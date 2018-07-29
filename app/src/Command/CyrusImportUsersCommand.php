<?php

namespace App\Command;

use Github\Client as GithubClient;
use JiraRestApi\User\UserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        $output->writeln(sprintf('<info>Importing %s user%s from %s.</info>', $total, $total == 1 ? '' : 's', getEnv('GITHUB_ORGANIZATION')));

        foreach($users as $_user) {

            die(var_dump($_user));
            $us = new UserService();

            $username = $_user['login'];

            // create new user
            $user = $us->create([
                'name'          => $username,
                'password'      => $username,
                'emailAddress'  => sprintf('%s@cyrusbio.com'),
                'displayName'   => sprintf('Github: %s', $username)
            ]);

            var_dump($user);

        }

    }
}
