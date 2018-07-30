<?php namespace App\Common;

use JiraRestApi\User\UserService;

class Github2JiraHelpers
{
    /**
     * Find a User in JIRA.
     *
     * @param string|null $username
     * @return bool
     * @throws \JiraRestApi\JiraException
     * @throws \JsonMapper_Exception
     */
    public static function findJiraUserByUsername(string $username = null)
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
}