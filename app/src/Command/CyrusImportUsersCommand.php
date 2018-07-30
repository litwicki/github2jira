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

    protected function configure()
    {
        $this
            ->setDescription('Import users from Github into Jira')
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

        $users = $github->api('user')->all(getEnv('GITHUB_ORGANIZATION'));

        $total = count($users);

        if(!$total) {
            $output->writeln('No users to import.');
            exit;
        }

        $output->writeln(sprintf('<info>Processing %s user%s from Github Organization: %s.</info>', $total, $total == 1 ? '' : 's', getEnv('GITHUB_ORGANIZATION')));

        $old = $new = 0;

        foreach($users as $_user) {

            $userService = new UserService();

            $username = $_user['login'];

            /**
             * Only import (create) a User if they do not already exist,
             * so let's verify we cannot find them in Jira first.
             */
            if(false === (Github2JiraHelpers::findJiraUserByUsername($username))) {

                // create new user
//                $user = $userService->create([
//                    'name'          => $username,
//                    'password'      => $username,
//                    'emailAddress'  => sprintf('%s@cyrusbio.com'),
//                    'displayName'   => sprintf('Github: %s', $username)
//                ]);

                $new++;

            }
            else {
                $old++;
            }

        }

        $output->writeln(sprintf('<info>%s User%s imported to %s</info>', $new, $new == 1 ? '' : 's', getEnv('JIRA_HOST')));

        if($old) {
            $output->writeln(sprintf('<info>%s User%s were not imported because they already exist.</info>', $old, $old == 1 ? '' : 's'));
        }



    }
}
