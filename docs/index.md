# Setup

1. [Create a new JIRA Project](https://confluence.atlassian.com/jira064/create-a-project-720412889.html)
2. [Create a Custom Field in Jira](https://confluence.atlassian.com/adminjiraserver/adding-a-custom-field-938847222.html) that is a text input, and available on all Screens for your Jira Project Issues.
3. Get the SMTP credentials for your email service provider, you'll need to build a `MAILER_URL` (see below) for any operations to send email.
4. [Create a Github Oauth Token](https://help.github.com/articles/creating-a-personal-access-token-for-the-command-line/)
5. [Create a Jira Oauth Token](https://confluence.atlassian.com/cloud/api-tokens-938839638.html)

## Configuration

To get started simply copy `.env.dist` to `.env` and customize with your options.

    cp app/.env.dist app/.env

|Config Name|Description|Value|
|-----------|------------|-----------|
|`APP_USER_EMAIL`|Who should receive emails from the processors?|jake.litwicki@example.com|
|`PAGE_SIZE`| |100|
|`JIRA_HOST`| |"https://example.atlassian.net"|
|`JIRA_USER`| |"jirabot@example.com"|
|`JIRA_PASS`| |"p4ssw0rd!"|
|`JIRA_CUSTOM_FIELD_GITHUB_ISSUE`| |customfield_#####|Get the field id from the field you create|
|`JIRA_CUSTOM_FIELD_EPIC_LINK`| |customfield_10013|This is the default|
|`JIRA_CUSTOM_FIELD_EPIC_NAME`| |customfield_10010|This is the default|
|`GITHUB_ORGANIZATION`||Acme Corp|
|`GITHUB_AUTH_METHOD`|[see client docs](https://github.com/KnpLabs/php-github-api/blob/master/doc/security.md)|http_token|
|`GITHUB_USERNAME`|[see client docs](https://github.com/KnpLabs/php-github-api/blob/master/doc/security.md)|Oauth Token Value|
|`GITHUB_SECRET`|[see client docs](https://github.com/KnpLabs/php-github-api/blob/master/doc/security.md)|Oauth Token Value|
|`MAILER_URL`| |smtp://smtp.mailgun.org:25?encryptiontls&auth_mode=login&username=noreply@mg.example.com&password=p4ssw0rd!|
|`MAILER_FROM_ADDRESS`| |noreply@example.com|
|`MAILER_DEFAULT_SUBJECT`| |"Github2Jira"|
|`EMAIL_SIGNATURE_TXT`| |'text formatted string with no line breaks; can include `\n` for line breaks'|
|`EMAIL_SIGNATURE_HTML`| |"html formatted string with no line breaks"|

# Available Commands

A list of all the available commands.

### Import

* Users
* Issues

### Purge
* Purge all imported issues from Jira
* Purge all imported users from Jira

