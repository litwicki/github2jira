<?php

namespace App\Command;

use App\Common\Github2JiraHelpers;
use Github\Api\Issue;
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

class CyrusImportIssuesCommand extends Command
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
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Debug mode')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $pageSize = $input->getOption('per-page') ? $input->getOption('per-page') : getEnv('PAGE_SIZE');

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
        $pages = round($total / $pageSize);

        for($i=0;$i<$pages;$i++) {

            $issues = $github->api('issue')->all(getEnv('GITHUB_ORGANIZATION'), $githubRepo, [
                'state' => $state,
                'page' => $i+1,
                'per_page' => $pageSize,
            ]);

            try {
                $this->importIssuesToJira($issues, $jiraProject, $output);
            }
            catch(\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                exit;
            }

        }

        /**
         * Send an email recapping what was done.
         */
        if($input->getOption('send-email')) {
            $this->mailer->send([
                'recipients' => [getEnv('APP_USER_EMAIL')],
                'subject' => 'Cyrus User Import (Jira)',
                'params' => [
                    'new' => $newUsers,
                    'old' => $oldUsers
                ]
            ], 'import-users');
        }

    }

    public function importIssuesToJira(array $items = array(), Project $jiraProject, OutputInterface $output)
    {
        foreach($items as $item) {

            $issue = $this->findIssueInJira($item['number'], $jiraProject);

            if($issue) {
                $output->writeln(sprintf('<warn>Skipping Issue #%s, already imported.', $item['number']));
                continue;
            }

            $labels = array();
            if (!empty($item['labels'])) {
                foreach ($item['labels'] as $label) {
                    $labels[] = $label['name'];
                }
            }

            $issueField = new IssueField();

            $issueField->setProjectKey($jiraProject->key)
                ->setSummary($item['title'])
                ->setAssigneeName($this->helpers->githubLoginToJiraUsername($item['user']['login']))
                ->setPriorityName('Medium')
                ->setIssueType('Story')
                ->setDescription($item['body'])
                ->addCustomField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ID'), strval($item['number']))
            ;

            //attach the labels to this issue.
            foreach($labels as $label) {
                $label = str_replace(' ', '-', $label);
                $issueField->addLabel($label);
            }

            $issueService = new IssueService();

            $issue = $issueService->create($issueField);

            $output->writeln(sprintf('JIRA Issue %s imported.', $issue->key));

        }

    }

    /**
     * Find an Issue in Jira by it's `github_number`
     *
     * @param int $githubNumber
     * @param Project $project
     * @return bool|\JiraRestApi\Issue\Issue
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    public function findIssueInJira(int $githubNumber, Project $project)
    {
        $issueService = new IssueService();
        $jql = sprintf('"Github Issue" ~ `%s`', $githubNumber);
        $result = $issueService->search($jql);
        return $result->total ? $result->getIssue(0) : false;
    }
}
