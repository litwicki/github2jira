<?php

namespace App\Command;

use App\Common\Github2JiraHelpers;
use Github\Client as GithubClient;
use JiraRestApi\User\UserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Mail\Mailer;

/**
 * Class CyrusImportUsersCommand
 * @package App\Command
 *
 * @TODO: convert static function calls to service declarations
 * through autowiring of separate services for JIRA and Github.
 *
 */
class CyrusImportUsersCommand extends Command
{
    protected static $defaultName = 'cyrus:import:users';

    /**
     * @var App\Mail\Mailer
     */
    protected $mailer;

    public function __construct(Mailer $mailer)
    {
        parent::__construct();
        $this->mailer = $mailer;
    }

    protected function configure()
    {
        $this
            ->setDescription('Import users from Github into Jira')
            ->addOption('send-email', null, InputOption::VALUE_NONE, 'Send an email recapping everything.')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Debug mode')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $debug = $input->getOption('debug');

        $github = new GithubClient();

        //@TODO: make the autowiring for this work with Symfony4
        $github->authenticate(getEnv('GITHUB_USERNAME'), null, GitHubClient::AUTH_HTTP_TOKEN);

        $users = $github->api('members')->all(getEnv('GITHUB_ORGANIZATION'));

        $total = count($users);

        if(!$total) {
            $output->writeln('No users to import.');
            exit;
        }

        $output->writeln(sprintf('<info>Processing %s user%s from Github Organization: %s.</info>', $total, $total == 1 ? '' : 's', getEnv('GITHUB_ORGANIZATION')));

        $oldUsers = $newUsers = [];

        foreach($users as $_user) {

            $userService = new UserService();

            $username = $_user['login'];

            $output->writeln(sprintf('Processing: %s', $username));

            /**
             * Only import (create) a User if they do not already exist,
             * so let's verify we cannot find them in Jira first.
             */
            if(false === (Github2JiraHelpers::findJiraUserByUsername($username))) {

                // create new user
                try {
                    $user = $userService->create([
                        'name'          => $username,
                        'password'      => $username,
                        'emailAddress'  => sprintf('%s@cyrusbio.com', $username),
                        'displayName'   => $username,
                        'notification'  => FALSE, # do we want to email the new user an invite or not?
                    ]);
                    $newUsers[] = sprintf('%s: %s', $user->name, $user->displayName);
                }
                catch(\Exception $e) {
                    $message = sprintf('<error>Username `%s` already exists!</error>', $username);
                    $output->writeln($message);
                }

            }
            else {
                $oldUsers[] = $username;
            }

        }

        $output->writeln(sprintf('<info>%s User%s imported to %s</info>', count($newUsers), count($newUsers) == 1 ? '' : 's', getEnv('JIRA_HOST')));

        if(0 != count($oldUsers)) {
            $output->writeln(sprintf('<info>%s User%s were not imported because they already exist.</info>', count($oldUsers), count($oldUsers) == 1 ? '' : 's'));
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
}
