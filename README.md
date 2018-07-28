# GitHub to JIRA

Migrate Github to JIRA for all the things..

## Requirements

* [PHP 7.1](https://coolestguidesontheplanet.com/upgrade-php-on-osx/)
* [Composer](https://getcomposer.org/doc/00-intro.md)

## Usage

    php bin/console cyrus:import:issues --github-repo={repository} --jira-project={jira-project-key] --state={github-issue-state}
  
### Github Issue States  
    
| Tables        | Are           | Cool  |
| ------------- |:-------------:| :-----|
| state         | string | Indicates the state of the issues to return. Can be either `open`, `closed`, or `all`. Default: `open` |

