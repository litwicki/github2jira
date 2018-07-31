<?php namespace App\Common;

use JiraRestApi\Project\Project;
use JiraRestApi\User\UserService;
use JiraRestApi\Issue\IssueService;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Github2JiraHelpers
{
    protected $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * Find a User in JIRA.
     *
     * @param string|null $username
     * @return bool
     * @throws \JiraRestApi\JiraException
     * @throws \JsonMapper_Exception
     */
    public function findJiraUserByUsername(string $username = null)
    {
        $us = new UserService();

        // get the user info.
        $users = $us->findUsers(['username' => $username]);

        if(!empty($users)) {
            $first = reset($users);
            return ($first->name == $username);
        }

        return false;
    }

    /**
     * @param Project $project
     * @return array
     * @throws \JiraRestApi\JiraException
     * @throws \JsonMapper_Exception
     */
    public function getGithubIssuesImportedToJira(Project $project)
    {
        $jql = sprintf('project = %s and "Github Issue" is NOT EMPTY ORDER BY created DESC', $project->key);
        $svc = new IssueService();
        $response = $svc->search($jql);
        $items = array();
        foreach($response->getIssues() as $issue) {
            $fields = $issue->fields->getCustomFields();
            $items[] = $fields[getEnv('JIRA_CUSTOM_FIELD_GITHUB_ID')];
        }
        return $items;
    }

    public function githubLoginToJiraUsername(string $githubLogin)
    {

        //@TODO: We'll want to cache or preload this in a much more elegant way
        //because anything that isn't a simple dozen or so records here could
        //be extremely problematic, plus it's just ugly.

        $userMap = json_decode(file_get_contents($this->params->get('kernel.project_dir') . '/var/data/users.json'), true);

    }

}