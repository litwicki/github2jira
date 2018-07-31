<?php

namespace App\Command;

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

use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\JiraException;

class CyrusImportIssuesCommand extends Command
{
    protected static $defaultName = 'cyrus:import:issues';

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addOption('github-repo', null, InputOption::VALUE_REQUIRED, 'The Github Repository to import from.')
            ->addOption('jira-project-key', null, InputOption::VALUE_REQUIRED, 'The JIRA Project to import into.')
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

        $svc = new UserService();

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
                $this->importIssuesToJira($issues, $jiraProject);
            }
            catch(\Exception $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                exit;
            }

        }

    }

    public function importIssuesToJira(array $items = array(), Project $jiraProject)
    {
        foreach($items as $item) {

            /**
             * Check if this Issue exists already in JIRA
             * and if it does, then we'll update instead of creating
             * a new duplicate. Alright alright alriiiight!
             */

            $issue = $this->findIssueInJira($item['number'], $jiraProject);
            if($issue) {
                die(var_dump($issue));
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
                ->setAssigneeName($item['user']['login'])
                ->setPriorityName('Medium')
                ->setIssueType('Story')
                ->setDescription($item['body'])
                ->addCustomField('customfield_10025', strval($item['number']));

            $issueService = new IssueService();

            $issue = $issueService->create($issueField);


            die(var_dump($issue));

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
        $jql = sprintf('github_number ~ `%s`', $githubNumber);
        $result = $issueService->search($jql);
        return $result->total ? $result->getIssue(0) : false;
    }
}
