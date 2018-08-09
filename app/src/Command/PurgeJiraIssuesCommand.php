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


use App\Command\Github2JiraCommand;

/**
 * Class PurgeJiraIssuesCommand
 * @package App\Command
 */
class PurgeJiraIssuesCommand extends Github2JiraCommand
{
    protected static $defaultName = 'github2jira:purge-jira-issues';

    protected function configure()
    {
        $this
            ->setDescription('Purge all JIRA Issues that have been imported by Github2Jira.')
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send an email recapping everything.')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'All Jira issues with this label will be purged.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $messages = array();

        $svc = new IssueService();

        $label = $input->getOption('label') ? $input->getOption('label') : false;
        $verbose = $input->getOption('verbose') ? true : false;

        $customField = $this->helpers->getCustomJiraField(getEnv('JIRA_CUSTOM_FIELD_GITHUB_ISSUE'));

        $jql = $label ?
            sprintf('"%s" IS NOT EMPTY AND labels in(%s)', $customField->name, $label) :
            sprintf('"%s" IS NOT EMPTY', $customField->name);

        if($verbose) {
            $output->writeln(sprintf('JQL Query: %s', $jql));
        }

        $jira = $svc->search($jql);

        $issues = $jira->getIssues();
        $count = count($issues);

        $output->writeln(sprintf('Found %s issue%s to process.', $count, $count == 1 ? '' : 's'));

        foreach($issues as $issue) {

            try {
                $response = $svc->deleteIssue($issue->key);
                $message = sprintf('%s: %s', $issue->key, $issue->fields->summary);
            }
            catch(\Exception $e) {
                $message = sprintf('<error>%s</error>', $e->getMessage());
            }

            if($verbose) {
                $output->writeln($message);
            }
            $messages[] = $message;

        }

        /**
         * Send an email recapping what was done.
         */
        if($input->getOption('send-email')) {
            $this->mailer->send([
                'recipients' => [getEnv('APP_USER_EMAIL')],
                'subject' => 'Github2Jira Purge Issues',
                'params' => [
                    'messages' => $messages,
                    'body' => $count ? sprintf('%s Jira Issues Purged.', $count) : 'There were no issues to purge!',
                ]
            ]);
        }

    }
}
