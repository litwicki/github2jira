<?php

namespace App\Command;

use JiraRestApi\Issue\IssueService;
use JiraRestApi\User\UserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Common\Github2JiraHelpers;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Mail\Mailer;

class PurgeJiraIssuesCommand extends Command
{
    protected static $defaultName = 'github2jira:purge-jira-issues';

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
            ->setDescription('Purge all JIRA Issues that have been imported by Github2Jira.')
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send an email recapping everything.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $messages = array();

        $jira = new IssueService();

        $jql = '"Github Issue" IS NOT EMPTY';
        $issues = $jira->search($jql);

        foreach($issues->getIssues() as $issue) {

            try {
                $response = $jira->deleteIssue($issue->key);
                die(var_dump($response));
                $message = sprintf('%s: %s', $response['response'], $response['message']);
            }
            catch(\Exception $e) {
                $message = sprintf('<error>%s</error>', $e->getMessage());
            }

            $output->writeln($message);
            $messages[] = $message;

        }

        if(empty($messages)) {
            $params = [
                'message' => 'No Issues to Purge!'
            ];
        }
        else {
            $params = [
                'messages' => $messages,
            ];
        }

        /**
         * Send an email recapping what was done.
         */
        if($input->getOption('send-email')) {
            $this->mailer->send([
                'recipients' => [getEnv('APP_USER_EMAIL')],
                'subject' => 'Github2Jira Purge Issues',
                'params' => $params
            ]);
        }

    }
}
