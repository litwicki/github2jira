# GitHub to JIRA

[View the full documentation](https://litwicki.github.io/github2jira/)

## Requirements

* [PHP 7.1](https://coolestguidesontheplanet.com/upgrade-php-on-osx/)
* [Composer](https://getcomposer.org/doc/00-intro.md)

## Setup

Again, make sure to reference the [documentation](https://litwicki.github.io/github2jira/), but you'll need to do this regardless of anything.

    cd app
    cp .env.dist .env
    cp var/users.json.dist var/users.json
    
Now you have `.env` and `var/data/users.json` that need to be configured for your needs. When you've completed that, install all dependencies with Composer, assuming you have installed it (see above).
    
    composer install --prefer-source