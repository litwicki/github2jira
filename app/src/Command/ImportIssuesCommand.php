<?php

namespace App\Command;

use App\Common\Github2JiraHelpers;
use Github\Api\Issue as GithubIssue;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Issue as JiraIssue;
use Github\Client as GithubClient;
use JiraRestApi\Issue\Transition;
use JiraRestApi\Project\Project as JiraProject;
use JiraRestApi\Project\ProjectService;
use JiraRestApi\User\User;
use JiraRestApi\User\UserService;
use JiraRestApi\User\UserPropertiesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;
use App\Mail\Mailer;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\JiraException;

use App\Command\Github2JiraCommand;

/**
 * Class ImportIssuesCommand
 * @package App\Command
 */
class ImportIssuesCommand extends Github2JiraCommand
{
    protected static $defaultName = 'github2jira:import:issues';

    protected $params;

    protected $helpers;

    /**
     * @var App\Mail\Mailer
     */
    protected $mailer;

    public function __construct(Mailer $mailer, ParameterBagInterface $params, Github2JiraHelpers $helpers)
    {
        parent::__construct();
        $this->params = $params;
        $this->helpers = $helpers;
        $this->mailer = $mailer;
    }

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addOption('github-repo', null, InputOption::VALUE_REQUIRED, 'The Github Repository to import from.')
            ->addOption('jira-project-key', null, InputOption::VALUE_REQUIRED, 'The JIRA Project to import into.')
            ->addOption('no-update', null, InputOption::VALUE_NONE, 'If you only want to import new records and bypass updating existing issues.')
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send an email recapping everything.')
            ->addOption('allow-unassigned', null, InputOption::VALUE_NONE, 'If a User does not exist, set to Unassigned/Default.')
            ->addOption('skip-comments', null, InputOption::VALUE_NONE, 'Skip processing comments.')
            ->addOption('state', null, InputOption::VALUE_OPTIONAL, 'If you would like to import a specific state of issue, otherwise defaults to `all`')
            ->addOption('per-page', null, InputOption::VALUE_OPTIONAL, 'Number of records to process per search/request.')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Number of records to process total; this overrides `per-page` regardless of setting.')
        ;
    }

    public function console(OutputInterface $output, $string)
    {
        $message = "  " . $string;
        $output->writeln($message);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->console($output, '');
        $io = new SymfonyStyle($input, $output);
        $errors = $messages = $consoleComments = $missingUsers = array();
        $limit = $input->getOption('limit') ? $input->getOption('limit') : false;
        $noUpdate = $input->getOption('no-update') ? true : false;
        $skipComments = $input->getOption('skip-comments') ? true : false;
        $verbose = $input->getOption('verbose') ? true : false;
        $allowUnassigned = $input->getOption('allow-unassigned') ? true : false;
        $pageSize = $input->getOption('per-page') ? $input->getOption('per-page') : getEnv('PAGE_SIZE');
        $pageSize = $limit > 0 ? $limit : $pageSize;

        $created = $updated = 0;

        $githubRepo = $input->getOption('github-repo');
        $projectKey = $input->getOption('jira-project-key');

        if(!$githubRepo || !$projectKey) {
            $this->console($output, 'You must specify a Github Repository (--github-repo) & Jira Project Key (--jira-project-key) to import issues.');
            exit;
        }

        $issueService = new IssueService();

        $p = new ProjectService();

        try {
            $jiraProject = $p->get($projectKey);
        }
        catch(\Exception $e) {
            $this->console($output, sprintf('Could not find a Jira project with KEY %s.', $projectKey));
            exit;
        }

        //if `state` is not passed, default to `all`
        $state = $input->getOption('state') ? $input->getOption('state') : null;

        if(!in_array($state, ['open', 'closed']))

        if(!is_null($state)) {
            $q = sprintf('repo:%s/%s state:%s', getEnv('GITHUB_ORGANIZATION'), $githubRepo, $state);
        }
        else {
            $q = sprintf('repo:%s/%s', getEnv('GITHUB_ORGANIZATION'), $githubRepo);
        }

        /**
         * If we're skipping updates, then exclude Github issues that we have already processed, so they're not
         * processed in the initial queries we run.
         */
        if(false === $noUpdate) {
            $q = sprintf('%s -label:github2jira', $q);
        }

        if($verbose) {
            $this->console($output, sprintf('Github Query: %s', $q) );
        }

        $github = new GithubClient();
        $github->authenticate(getEnv('GITHUB_USERNAME'), null, GitHubClient::AUTH_HTTP_TOKEN);

        $results = $github->api('search')->issues($q);
        $github->api('search')->setPerPage($pageSize);
        $total = $results['total_count'];
        $this->console($output, sprintf('Found %s total Github Issues to process.', $total));
        $pages = $limit ? 1 : round($total / $pageSize);

        for($i=0;$i<$pages;$i++) {

            $github->api('search')->setPage($i+1);
            $result = $github->api('search')->issues($q);
            $issues = $result['items'];

            $this->console($output, sprintf('Processing page %s of %s.', $i+1, $pages));

            foreach($issues as $item) {

                try {

                    /**
                     * Do not treat pull requests as Issues for JIRA
                     */
                    if(isset($item['pull_request'])) {

                        if($verbose) {
                            $this->console($output, sprintf('Skipping Pull Request: %s', $item['number']));
                        }

                        //label this so we can skip it in the future..
                        $this->labelGithubIssue($github, $githubRepo, $item['number']);

                        //move on
                        continue;

                    }

                    /**
                     * Start by identifying if we have a milestone, and if we do, let's treat it
                     * like a JIRA epic, and either fetch it or create it if we don't have it in JIRA
                     * already.
                     */
                    $epic = false;
                    $milestone = isset($item['milestone']) ? $item['milestone'] : false;

                    if($milestone) {

                        $epic = $this->helpers->findEpic($milestone['title'], $projectKey);
                        $body = isset($milestone['body']) ? $milestone['body'] : $milestone['title'];

                        $issueField = new IssueField();
                        $issueField->setProjectKey($jiraProject->key)
                            ->setSummary($milestone['title'])
                            ->setPriorityName('Medium')
                            ->setIssueType('Epic')
                            ->setDescription($body)
                            ->addLabel('github')
                            ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ISSUE'), getEnv('JIRA_HOST'))
                            ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_EPIC_NAME'), $milestone['title'])
                        ;

                        if($epic instanceof JiraIssue) {
                            if(!$noUpdate) {
                                $issueService->update($epic->key, $issueField);
                                $message = sprintf('Updating JIRA EPIC %s..', $epic->key);
                                //refresh the issue
                                $epic = $issueService->get($epic->key);
                            }
                            else {
                                $message = sprintf('(Skipping) Updating JIRA EPIC %s..', $epic->key);
                            }

                        }
                        else {
                            $epic = $issueService->create($issueField);
                            $message = sprintf('JIRA EPIC %s created.', $epic->key);
                        }

                        $messages[] = $message;
                        if($verbose) {
                            $this->console($output, $message);
                        }

                    }

                    /**
                     * Now that we have an Epic, move on to processing the Issue explicitly.
                     */

                    //create the Issue
                    $issueField = new IssueField();
                    $issueField->setProjectKey($jiraProject->key)
                        ->setSummary($item['title'])
                        ->setPriorityName('Medium')
                        ->setIssueType('Story')
                        ->setDescription($item['body'])
                        ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ISSUE'), strval($item['html_url']))
                    ;

                    //the `user` is the creator of the issue
                    $user = $this->helpers->githubLoginToJiraUsername($item['user']['login']);

                    if(!$user) {
                        $message = sprintf('<error>User %s does not exist in Jira!</error>', $item['user']['login']);
                        $missingUsers[] = $item['user']['login'];
                        if(!$allowUnassigned) {
                            $this->console($output, $message);
                            continue;
                        }
                    }
                    else {
                        $issueField->setReporterName($user->name);
                    }

                    //the `assignee` is the... duh
                    $isAssigned = isset($item['assignee']['login']) ? $item['assignee']['login'] : false;
                    if($isAssigned) {
                        $assignee = $this->helpers->githubLoginToJiraUsername($item['assignee']['login']);
                        if(!$assignee) {
                            $message = sprintf('<error>User %s does not exist in Jira!</error>', $item['user']['login']);
                            $missingUsers[] = $item['user']['login'];
                            if(!$allowUnassigned) {
                                $this->console($output, $message);
                                continue;
                            }
                            else {
                                $issueField->setAssigneeToDefault();
                            }
                        }
                        else {
                            $issueField->setAssigneeName($assignee->name);
                        }
                    }

                    //if we have an Epic, link it!
                    if($epic instanceof JiraIssue) {
                        $issueField->addCustomField(getEnv('JIRA_CUSTOM_FIELD_EPIC_LINK'), $epic->key);
                    }

                    if (!empty($item['labels'])) {
                        foreach ($item['labels'] as $label) {
                            $label = str_replace(' ', '-', $label['name']);
                            $issueField->addLabel($label);
                        }
                    }

                    $issue = $this->helpers->findIssueInJira($item['html_url'], $jiraProject);

                    if($issue instanceof JiraIssue) {

                        if(true === $noUpdate) {
                            $message = sprintf('(Skipped) Updating JIRA Issue %s..', $issue->key);
                        }
                        else {
                            $issueService->update($issue->key, $issueField);
                            $message = sprintf('Updating JIRA Issue %s..', $issue->key);
                            //refresh the issue
                            $issue = $issueService->get($issue->key);
                            $updated++;
                        }

                    }
                    else {
                        $issue = $issueService->create($issueField);
                        $message = sprintf('JIRA Issue %s imported.', $issue->key);
                        $created++;
                    }

                    $messages[] = $message;
                    if($verbose) {
                        $this->console($output, $message);
                    }

                    /**
                     * Do we need to set this Issue as "Done"
                     */
                    if($item['state'] == 'closed') {
                        if(true === ('Done' == $issue->fields->status->name)) {
                            $message = sprintf('Issue already in status "Done"');
                        }
                        else {
                            $transition = new Transition();
                            $transition->setTransitionName('Done');
                            $resolution = sprintf('%s Resolved %s via Github2Jira.', getEnv('JIRA_USER'), $issue->key);
                            $transition->setCommentBody($resolution);
                            $issueService = new IssueService();
                            $issueService->transition($issue->key, $transition);
                            $message = sprintf('Closing Issue %s', $issue->key);
                        }

                        if($verbose) {
                            $this->console($output, $message);
                        }
                        $consoleComments[] = $message;
                    }

                    if(false === $noUpdate) {

                        /**
                         * Now that we have the Issue, let's import the associated Comments
                         * @TODO: update comments if we're updating issue, for now just purge comments and resubmit them..
                         */
                        $response = $issueService->getComments($issue->key);
                        if($response->total) {
                            foreach($response->comments as $c) {
                                $issueService->deleteComment($issue->key, $c->id);
                            }
                        }

                        if(false === $skipComments) {
                            $comments = $github->api('issue')->comments()->all(getEnv('GITHUB_ORGANIZATION'), $githubRepo, $item['number']);
                            foreach($comments as $comment) {
                                $c = new Comment();
                                $body = $comment['body'];
                                $jiraUser = $this->helpers->githubLoginToJiraUsername($comment['user']['login']);
                                $author = $jiraUser ? $jiraUser->emailAddress : $comment['user']['login'];
                                $body = $body . "\n\n---\n" . sprintf('Comment imported by %s on behalf of %s.', getenv('JIRA_USER'), $author);
                                $c->setBody($body);
                                $issueService = new IssueService();
                                $ret = $issueService->addComment($issue->key, $c);
                            }
                        }

                    }

                    //label this issue as processed so we can skip in the future
                    $this->labelGithubIssue($github, $githubRepo, $item['number']);

                }
                catch(\Exception $e) {
                    throw $e;
                    $message = sprintf('<error>%s: %s</error>', $e->getLine(), $e->getMessage());
                    $errors[] = $message;
                    if($verbose) {
                        $message = $message . $e->getTraceAsString();
                    }
                    $this->console($output, $message);
                    exit;
                }

            }

        }

        /**
         * Send an email recapping what was done.
         */
        if($input->getOption('send-email')) {
            $this->mailer->send([
                'recipients' => [getEnv('APP_USER_EMAIL')],
                'subject' => 'Github2Jira Import Issues',
                'params' => [
                    'messages' => $messages,
                    'errors' => $errors,
                    'comments' => $consoleComments,
                    'body' => sprintf('%s imported, %s updated, %s failed.', $created, $updated, count($errors))
                ],
            ], 'import-users');
        }

        $this->console($output, '');
        $this->console($output, '<info>Github2Jira Process Complete!</info>');
        $this->console($output, '');

    }

    /**
     * @param GithubClient $github
     * @param string $repo
     * @param int $number
     * @param string $label
     */
    public function labelGithubIssue(GithubClient $github, string $repo, int $number, string $label = 'github2jira')
    {
        $github->api('issue')->labels()->add(getEnv('GITHUB_ORGANIZATION'), $repo, $number, $label);
    }

}
