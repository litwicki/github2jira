<?php namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

class Github2JiraCommand extends Command
{

    protected static $defaultName = 'github2jira';

    public function console(OutputInterface $output, $string)
    {
        $message = "  " . $string;
        $output->writeln($message);
    }
}