# Import Issues

Migrate issues from [Github](https://developer.github.com/v3/) to [Jira](https://developer.atlassian.com/cloud/jira/platform/rest/).

## Known Issues

- Comment authors (`Reporter Name`) cannot be set when importing comments from a Github Issue into Jira.

## Usage

|Option|Required|Type|Description|
|---------|-----|-------|------------|
|github-repo|YES|string|The Github Repository to import from.|
|jira-project-key|YES|string|The JIRA Project to import into.|
|no-update|NA|null|If you only want to import new records and bypass updating existing issues.|
|send-email|NA|null|Send an email recapping everything.|
|allow-unassigned|NA|null|If a User does not exist, set to Unassigned/Default.|
|state|NO|string|If you would like to import a specific state of issue, otherwise defaults to `all`|
|per-page|NO|int|Number of records to process per search/request.|
|limit|NO|int|Number of records to process total; this overrides `per-page` regardless of setting.|

### Github Issue States  

| Tables        | Are           | Cool  |
| ------------- |:-------------:| :-----|
| state         | string | Indicates the state of the issues to return. Can be either `open`, `closed`, or `all`. Default: `open` |