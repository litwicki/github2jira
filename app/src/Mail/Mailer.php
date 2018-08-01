<?php namespace App\Mail;

use Symfony\Component\Templating\PhpEngine;
use Symfony\Component\Templating\TemplateNameParser;
use Symfony\Component\Templating\Loader\FilesystemLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use Twig\Environment as Twig;

class Mailer
{
    /**
     * @var \Swift_Mailer
     */
    protected $mailer;

    /**
     * @var \Symfony\Bridge\Twig\
     */
    protected $twig;

    /**
     * @var ParameterBagInterface
     */
    protected $params;

    public function __construct(\Swift_Mailer $mailer, ParameterBagInterface $params, Twig $twig)
    {
        $this->params = $params;
        $this->mailer = $mailer;
        $this->twig = $twig;
    }

    /**
     * Swiftmailer Wrapper to send an Email.
     *
     * @param array $params
     * @param string|null $template
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
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
                $this->twig->render(
                    $htmlTemplate,
                    $params['params']
                ),
                'text/html'
            )
            ->addPart(
                $this->twig->render(
                    $txtTemplate,
                    $params['params']
                ),
                'text/plain'
            )
        ;

        $this->mailer->send($message);
    }
}