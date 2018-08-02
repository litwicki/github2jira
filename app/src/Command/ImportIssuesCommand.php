<?php

namespace App\Command;

use App\Common\Github2JiraHelpers;
use Github\Api\Issue as GithubIssue;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Issue as JiraIssue;
use Github\Client as GithubClient;
use JiraRestApi\Project\Project;
use JiraRestApi\Project\ProjectService;
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

class ImportIssuesCommand extends Command
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
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send an email recapping everything.')
            ->addOption('state', null, InputOption::VALUE_OPTIONAL, 'If you would like to import a specific state of issue, otherwise defaults to `all`')
            ->addOption('per-page', null, InputOption::VALUE_OPTIONAL, 'Number of records to process per search/request.')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Number of records to process total; this overrides `per-page` regardless of setting.')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Debug mode')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $errors = $messages = $consoleComments = array();
        $limit = $input->getOption('limit') ? $input->getOption('limit') : false;
        $pageSize = $input->getOption('per-page') ? $input->getOption('per-page') : getEnv('PAGE_SIZE');
        $pageSize = $limit ? $limit : $pageSize;

        $created = $updated = 0;

        $githubRepo = $input->getOption('github-repo');
        if(!$githubRepo) {
            $output->writeln('<error>You must specify a Github Repository!</error>');
            exit;
        }

        $projectKey = $input->getOption('jira-project-key');
        if(!$projectKey) {
            $output->writeln('<error>You must specify a JIRA Project!</error>');
            exit;
        }

        $issueService = new IssueService();

        $p = new ProjectService();
        $jiraProject = $p->get($projectKey);

        //if `state` is not passed, default to `all`
        $state = $input->getOption('state') ? $input->getOption('state') : 'all';

        $github = new GithubClient();
        //@TODO: make the autowiring for this work with Symfony4
        $github->authenticate(getEnv('GITHUB_USERNAME'), null, GitHubClient::AUTH_HTTP_TOKEN);

        $q = sprintf('repo:%s/%s', getEnv('GITHUB_ORGANIZATION'), $githubRepo);
        $search = $github->api('search')->issues($q);
        $total = $search['total_count'];
        $pages = $limit ? 1 : round($total / $pageSize);

        for($i=0;$i<$pages;$i++) {

            $issues = $github->api('issue')->all(getEnv('GITHUB_ORGANIZATION'), $githubRepo, [
                'state' => $state,
                'page' => $i+1,
                'per_page' => $pageSize,
            ]);

            try {

                foreach($issues as $item) {

                    /**
                     * Do not treat pull requests as Issues for JIRA
                     */
                    if(isset($item['pull_request'])) {
                        $output->writeln('Skipping pull request');
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

                        $issueField = new IssueField();
                        $issueField->setProjectKey($jiraProject->key)
                            ->setSummary($milestone['title'])
                            ->setAssigneeToUnassigned()
                            ->setPriorityName('Medium')
                            ->setIssueType('Epic')
                            ->setDescription($milestone['body'])
                            ->addLabel('github')
                            ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ID'), "0")
                        ;

                        if($epic instanceof JiraIssue) {
                            $issueService->update($epic->key, $issueField);
                            $message = sprintf('Updating JIRA EPIC %s..', $epic->key);
                            //refresh the issue
                            $epic = $issueService->get($epic->key);
                        }
                        else {
                            $epic = $issueService->create($issueField);
                            $message = sprintf('JIRA EPIC %s created.', $epic->key);
                        }

                        $messages[] = $message;
                        $output->writeln($message);

                    }

                    /**
                     * Now that we have an Epic, move on to processing the Issue explicitly.
                     */

                    $issue = $this->helpers->findIssueInJira($item['number'], $jiraProject);

                    //the `user` is the creator of the issue
                    $user = $this->helpers->githubLoginToJiraUsername($item['user']['login']);

                    //the `assignee` is the... duh
                    $isAssigned = isset($item['assignee']['login']) ? $item['assignee']['login'] : false;
                    if($isAssigned) {
                        $assignee = $this->helpers->githubLoginToJiraUsername($item['assignee']['login']);
                    }

                    if(!$user) {
                        $message = sprintf('<error>User %s does not exist in Jira!</error>', $item['user']['login']);
                        $consoleComments[] = $message;
                        $output->writeln($message);
                        exit;
                    }

                    //create the Issue
                    $issueField = new IssueField();
                    $issueField->setProjectKey($jiraProject->key)
                        ->setSummary($item['title'])
                        ->setPriorityName('Medium')
                        ->setIssueType('Story')
                        ->setDescription($item['body'])
                        ->setReporterName($user->name)
                        ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ISSUE'), strval($item['html_url']))
                    ;

                    //if we have an assignee, assign him/her!
                    if($isAssigned) {
                        $issueField->setAssigneeName($assignee->name);
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

                    if($issue instanceof JiraIssue) {
                        $issueService->update($issue->key, $issueField);
                        $message = sprintf('Updating JIRA Issue %s..', $issue->key);
                        //refresh the issue
                        $issue = $issueService->get($issue->key);
                        $updated++;
                    }
                    else {
                        $issue = $issueService->create($issueField);
                        $message = sprintf('JIRA Issue %s imported.', $issue->key);
                        $created++;
                    }

                    $messages[] = $message;
                    $output->writeln($message);

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

                    $comments = $github->api('issue')->comments()->all(getEnv('GITHUB_ORGANIZATION'), $githubRepo, $item['number']);
                    foreach($comments as $comment) {
                        $c = new Comment();
                        $c->setBody($comment['body']);
                        $issueService = new IssueService();
                        $ret = $issueService->addComment($issue->key, $c);
                    }

                }

            }
            catch(\Exception $e) {
                $message = sprintf('<error>%s: %s</error>', $e->getLine(), $e->getMessage());
                $errors[] = $message;
                $output->writeln($message);
                exit;
            }

        }

        $params = [
            'messages' => $messages,
            'errors' => $errors,
            'comments' => $consoleComments,
            'body' => sprintf('%s imported, %s updated, %s failed.', $created, $updated, count($errors))
        ];

        /**
         * Send an email recapping what was done.
         */
        if($input->getOption('send-email')) {
            $this->mailer->send([
                'recipients' => [getEnv('APP_USER_EMAIL')],
                'subject' => 'Github2Jira Import Issues',
                'params' => $params
            ], 'import-users');
        }

    }

}
