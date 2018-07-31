<?php

namespace App\Command;

use JiraRestApi\User\UserService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CyrusPurgeJiraUsersCommand extends Command
{
    protected static $defaultName = 'cyrus:purge:jira-users';

    protected function configure()
    {
        $this
            ->setDescription('Purge all non Admin JIRA Users.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $svc = new UserService();
        $users = $svc->findAssignableUsers(array('project' => 'CAD'));

        $responses = array();
        foreach($users as $user) {
            //don't delete the admin dummy
            if($user->name != 'admin') {
                $responses[] = $svc->deleteUser(['username' => $user->name]);
            }
        }

        foreach($responses as $response) {
            $output->writeln(sprintf('%s: %s', $response['response'], $response['message']));
        }

    }
}
