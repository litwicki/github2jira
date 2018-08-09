<?php namespace App\Common;

use JiraRestApi\User\User as JiraUser;
use Github\Api\User as GithubUser;
use JiraRestApi\Project\Project;
use JiraRestApi\User\UserService;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\JiraException;
use JiraRestApi\Field\FieldService;
use JiraRestApi\Field\Field;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Github2JiraHelpers
{
    protected $params;

    protected $issueService;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        $this->issueService = new IssueService();
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
        $response = $this->issueService->search($jql);
        $items = array();
        foreach($response->getIssues() as $issue) {
            $fields = $issue->fields->getCustomFields();
            $items[] = $fields[getEnv('JIRA_CUSTOM_FIELD_GITHUB_ID')];
        }
        return $items;
    }

    /**
     * Find a JIRA User from a Github Login based on a custom userMap.
     *
     * @param string $githubLogin
     * @return bool|User|\JiraRestApi\User\User[]
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    public function githubLoginToJiraUsername(string $githubLogin)
    {
        //@TODO: We'll want to cache or preload this in a much more elegant way
        //because anything that isn't a simple dozen or so records here could
        //be extremely problematic, plus it's just ugly.

        $userMap = json_decode(file_get_contents($this->params->get('kernel.project_dir') . '/var/data/users.json'), true);

        $email = isset($userMap[$githubLogin]) ? $userMap[$githubLogin] : false;

        if(!$email) {
            return false;
        }

        /**
         * Connect to JIRA, and find an Atlassian User Account that matches
         * based on the githubLogin we are looking for.
         */
        $jira = new UserService();
        $user = $jira->findUsers(['username' => $email]);
        $user = reset($user);

        return ($user instanceof JiraUser) ? $user : false;

    }

    /**
     * Find an Issue in Jira by it's `Github Issue` field
     *
     * @param string $githubUrl
     * @param Project $project
     * @return bool|\JiraRestApi\Issue\Issue
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    public function findIssueInJira(string $githubUrl, Project $project)
    {
        $jql = sprintf('"Github Issue" ~ "%s"', $githubUrl);
        $result = $this->issueService->search($jql);
        return $result->total ? $result->getIssue(0) : false;
    }

    /**
     * @param string $summary
     * @param string $projectKey
     * @return bool|\JiraRestApi\Issue\Issue
     * @throws JiraException
     * @throws \JsonMapper_Exception
     */
    public function findEpic(string $summary, string $projectKey)
    {
        $jql = sprintf('project = "%s" and issuetype = Epic and summary ~ "%s"', $projectKey, $summary);
        $result = $this->issueService->search($jql);
        return $result->total ? $result->getIssue(0) : false;
    }

    /**
     * @param string $fieldId
     * @return bool
     * @throws JiraException
     */
    public function getCustomJiraField(string $fieldId)
    {
        $fieldSvc = new FieldService();
        $fields = $fieldSvc->getAllFields();
        foreach($fields as $field) {
            if(true === ($field->key == $fieldId)) {
                return $field;
            }
        }
        return false;
    }

}