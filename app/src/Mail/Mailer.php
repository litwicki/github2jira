<?php namespace App\Mail;

use Symfony\Component\Templating\PhpEngine;
use Symfony\Component\Templating\TemplateNameParser;
use Symfony\Component\Templating\Loader\FilesystemLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Mailer
{
    protected $mailer;

    protected $templatePath;

    public function __construct(\Swift_Mailer $mailer, ParameterBagInterface $params)
    {
        $this->params = $params;
        $dir = $this->params->get('kernel.project_dir').'/templates/%name%';
        $filesystemLoader = new FilesystemLoader($dir);
        $this->mailer = $mailer;
        $this->templating = $templating = new PhpEngine(new TemplateNameParser(), $filesystemLoader);
    }

    /**
     * Swiftmailer Wrapper to send an Email.
     *
     * @param string|null $template
     * @param array $params
     */
    public function send(array $params = array(), string $template = 'default')
    {
        $htmlTemplate = sprintf('emails/%s.html.twig', $template);
        $txtTemplate = sprintf('emails/%s.txt.twig', $template);

        $recipients = isset($params['recipients']) ? $params['recipients'] : false;
        $subject = isset($params['subject']) ? $params['subject'] : getEnv('MAILER_SUBJECT_DEFAULT');
        $emailFrom = getEnv('MAILER_FROM_ADDRESS');

        $message = (new \Swift_Message($subject))
            ->setFrom($emailFrom)
            ->setTo($recipients)
            ->setBody(
                $this->templating->render(
                    $htmlTemplate,
                    $params['params']
                ),
                'text/html'
            )
            ->addPart(
                $this->templating->render(
                    $txtTemplate,
                    $params['params']
                ),
                'text/plain'
            )
        ;

        $this->mailer->send($message);
    }
}