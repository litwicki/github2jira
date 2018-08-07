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

use App\Command\Github2JiraCommand;

/**
 * Class PurgeJiraIssuesCommand
 * @package App\Command
 */
class PurgeJiraIssuesCommand extends Github2JiraCommand
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

        $svc = new IssueService();

        $jql = '"Github Issue" IS NOT EMPTY';
        $jira = $svc->search($jql);

        $issueCount = count($jira->getIssues());

        foreach($jira->getIssues() as $issue) {

            try {
                $response = $svc->deleteIssue($issue->key);
                $message = sprintf('%s: %s', $issue->key, $issue->fields->summary);
            }
            catch(\Exception $e) {
                $message = sprintf('<error>%s</error>', $e->getMessage());
            }

            $output->writeln($message);
            $messages[] = $message;

        }

        $params = [
            'messages' => $messages,
            'body' => $issueCount ? sprintf('%s Jira Issues Purged.', $issueCount) : 'There were no issues to purge!',
        ];

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
