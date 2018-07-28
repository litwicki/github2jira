<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Dotenv\Dotenv;

use GuzzleHttp\Client as Guzzle;

class CyrusImportIssuesCommand extends Command
{
    protected static $defaultName = 'cyrus:import:issues';

    protected function configure()
    {
        $this
            ->setDescription('Add a short description for your command')
            ->addOption('github-repo', null, InputOption::VALUE_REQUIRED, 'The Github Repository to import from.')
            ->addOption('jira-project', null, InputOption::VALUE_REQUIRED, 'The JIRA Project to import into.')
            ->addOption('state', null, InputOption::VALUE_NONE, 'If you would like to import a specific state of issue, otherwise defaults to `all`')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $repo = $input->getOption('github-repo');
        if(!$repo) {
            $output->writeln('<error>You must specify a Github Repository!</error>');
            exit;
        }

        $project = $input->getOption('jira-project');
        if(!$project) {
            $output->writeln('<error>You must specify a JIRA Project!</error>');
            exit;
        }

        /**
         * Get the JIRA Project from the `name` here..
         */
        $jira = new Guzzle();
        $jiraUrl = sprintf('https://%s.atlassian.net/reset/api/2/%s', 
            getenv('JIRA_DOMAIN'),
            $project
        );

        //if `state` is not passed, then default to `all`
        $state = $input->getOption('state') ? $input->getOption('state') : 'all';

        $client = new Guzzle();
        $page = 1;
        $pageSize = getenv('PAGE_SIZE');

        $url = 'https://api.github.com/repos/%s/%s/issues?state=%s&access_token=%s&per_page=%s&page=%s';

        $url = sprintf($url, 
            getEnv('GITHUB_ORGANIZATION'),
            $repo,
            $state, 
            getenv('OAUTH_TOKEN'), 
            $pageSize,
            $page
        );

        /**
         * This is so incredibly hacky and stupid, how is there not a better way for this?
         * Please someone help me to understand and/or clean this up so it's prettier, I am ashamed.
         * 
         * @TODO: validate against there not being any pagination..
         */
        $response = $client->head($url);
        $csv = $response->getHeader('Link');
        $csv = reset($csv);
        $links = str_getcsv($csv, ',');
        $last = end($links);
        $last = preg_replace("/.*page=(\d+)>.*/", "$1", $last);

        for($i=1;$i<=$last;$i++) {
            
            $url = $this->buildGithubUrl();

            $response = $client->request('GET', $url);
            $json = $response->getBody();
            $items = json_decode($json, TRUE);

            $data = !is_array($items) ? array($items) : $items;

            foreach($items as $data) {

                $issue = $this->parseIssue($data);

                /**
                 * When the Issue is created, add any Github comments..
                 * 
                 *  POST /rest/api/2/issue/{issueIdOrKey}/comment
                 * 
                 */
                if($data['comments']) {
                    $comment = $this->parseComments($data['comments']);
                }

            }
            
        }
        
        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');
    }

    public function buildGithubUrl(string $url, string $repo, string $state, string $pageSize)
    {
        return sprintf($url, 
            getEnv('GITHUB_ORGANIZATION'),
            $repo,
            $state, 
            getenv('OAUTH_TOKEN'), 
            $pageSize, 
            $i
        );
    }

    public function parseComments(array $comments = array())
    {
        foreach($comments as $comment) {
            //@TODO: this :)
        }
    }

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
    }
}
