<?php

namespace App\Command;

use App\Atlassian\Jira\JiraApiClient;
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

        $jira = new JiraApiClient();
        $project = $jira->getProjectByKey($project);

        die(var_dump($project));

        //if `state` is not passed, then default to `all`
        $state = $input->getOption('state') ? $input->getOption('state') : 'all';
        $url = $this->buildGithubUrl($repo, $state);

        $client = new Guzzle();

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
            //force an array structure
            $items = !is_array($items) ? array($items) : $items;

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

    /**
     * Build the Github API URL.
     *
     * @param string $repo
     * @param string $state
     * @param int $page
     * @return string
     */
    public function buildGithubUrl(string $repo, string $state, int $page = 1)
    {
        $url = '/repos/%s/%s/issues?state=%s&access_token=%s&per_page=%s&page=%s';

        return sprintf($url,
            getEnv('GITHUB_BASE_URL'),
            getEnv('GITHUB_ORGANIZATION'),
            $repo,
            $state, 
            getenv('GITHUB_OAUTH_TOKEN'),
            getEnv('PAGE_SIZE'),
            $page
        );
    }

    /**
     * Parse array of comments from Github into a comment we can add to a JIRA issue.
     *
     * @param array $comments
     */
    public function parseComments(array $comments = array())
    {
        foreach($comments as $comment) {
            //@TODO: this :)
        }
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
