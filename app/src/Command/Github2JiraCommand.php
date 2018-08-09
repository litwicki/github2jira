<?php namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use App\Mail\Mailer;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Common\Github2JiraHelpers;

class Github2JiraCommand extends Command
{

    protected static $defaultName = 'github2jira';protected $helpers;

    protected $params;

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

    public function console(OutputInterface $output, $string)
    {
        $message = "  " . $string;
        $output->writeln($message);
    }
}