<?php namespace App\Mail;

class Mailer
{
    private $mailer;

    public function __construct(\Swift_Mailer $mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * Swiftmailer Wrapper to send an Email.
     *
     * @param string|null $template
     * @param array $params
     */
    public function send(array $params = array(), string $template = 'message')
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
                $this->renderView(
                    $htmlTemplate,
                    $params['params']
                ),
                'text/html'
            )
            ->addPart(
                $this->renderView(
                    $txtTemplate,
                    $params['params']
                ),
                'text/plain'
            )
        ;

        $this->mailer->send($message);
    }
}