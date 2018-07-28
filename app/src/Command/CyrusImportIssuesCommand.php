<?php

namespace App\Command;

use Github\Client as GithubClient;
use JiraRestApi\Project\ProjectService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;

class CyrusImportIssuesCommand extends Command
{
    protected static $defaultName = 'cyrus:import:issues';

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addOption('github-repo', null, InputOption::VALUE_REQUIRED, 'The Github Repository to import from.')
            ->addOption('jira-project-key', null, InputOption::VALUE_REQUIRED, 'The JIRA Project to import into.')
            ->addOption('state', null, InputOption::VALUE_NONE, 'If you would like to import a specific state of issue, otherwise defaults to `all`')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

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
        $project = $p->get($projectKey);

        //if `state` is not passed, then default to `all`
        $state = $input->getOption('state') ? $input->getOption('state') : 'all';

        $github = new GithubClient();
        $issues = $github->api('issue')->all(getEnv('GITHUB_ORGANIZATION'), $githubRepo, array('state' => $state));

        die(var_dump($issues));

    }

    /**
     * Parse a Github Issue to import into JIRA
     *
     * @param array $data
     * @return array
     */
    public function parseIssue(array $data = array())
    {
        /**
         * Manually set vars here so we can parse and tweak 
         * as needed and simplify the downstream impact..
         */
        $url = $data['url'];
        $id = $data['id'];
        $node_id = $data['node_id'];
        $number = $data['number'];
        $summary = $data['title'];
        $description = $data['body'];

        $labels = array();
        if(!empty($data['labels'])) {
            foreach($data['labels'] as $label) {
                $labels[] = $label['name'];
            }
        }

        $state = $data['state'];

        /**
         * Build the JIRA issue.
         */
        $issue = [

            'update' => [],
            'fields' => [
                'project' => [
                    'id' => '',
                ],
                'summary' => $summary,
                'issuetype' => [
                    'id' => '',
                ],
                'labels' => $labels,
                'description' => $description,

            ],

        ];

        return $issue;
    }
}
