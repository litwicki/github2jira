<?php namespace App\Atlassian\Jira;

use App\Common\Github2JiraHelpers;
use Symfony\Component\Dotenv\Dotenv;
use GuzzleHttp\Client as Guzzle;

class JiraApiClient
{
    /**
     * @var string
     */
    private $url;

    /**
     * @var string
     */
    protected $domain;

    public function __construct()
    {
        $this->domain = getEnv('JIRA_DOMAIN');
        $this->url = sprintf('https://%s.atlassian.net/rest/api/2',
            $this->domain
        );

        /**
         * New Guzzle HTTP Client
         */
        $this->client = new Guzzle(
            ['auth' => [getEnv('JIRA_USERNAME'), getEnv('JIRA_API_TOKEN')]],
            ['headers' => [
                'Accept-Encoding' => 'application/json'
            ]]
        );

    }

    /**
     * Create a Custom JIRA Field.
     *
     * @param array $data
     * @return array
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function createField(array $data = array())
    {

        try {

            $url = sprintf('%s/field', $this->url);

            $response = $this->client->request('POST', $url, [
                'data' => json_encode($data)
            ]);

            $response = Github2JiraHelpers::responseToArray($response);

        }
        catch(\Exception $e) {
            throw $e;
        }

        return $response;

    }

    /**
     * Get a JIRA Project by its Key.
     *
     * @param string $key
     * @param bool $simplify
     * @return array
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function getProjectByKey(string $key, bool $simplify = true)
    {
        try {

            $url = sprintf('%s/project/%s', $this->url, $key);

            $response = $this->client->request('GET', $url);

            $response = Github2JiraHelpers::responseToArray($response);

            if($simplify) {
                //cleanup the response here so it's a simpler array
                $simplify = ['lead', 'issueTypes', 'projectKeys', 'components', 'versions', 'roles'];
                foreach($simplify as $key) {
                    unset($response[$key]);
                }
            }

        }
        catch(\Exception $e) {
            throw $e;
        }

        return $response;
    }
}