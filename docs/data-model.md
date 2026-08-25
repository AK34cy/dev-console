# Data Model

This document describes JSON and metadata structures used by the current implementation.

## `config/projects.json`

Top-level structure:

```json
{
  "active_project_id": "example",
  "projects": []
}
```

Fields:

- `active_project_id`: ID of the project currently selected for Dashboard and workflow actions.
- `projects`: array of project objects.

## Project Object

Current default project shape:

```json
{
  "id": "example",
  "name": "Example",
  "managed_server_id": "server-id",
  "repository_path": "/var/www/git/example",
  "branch": "main",
  "production": {
    "domain": "example.com",
    "path": "/var/www/projects/example/production"
  },
  "preview": {
    "domain": "preview.example.com",
    "path": "/var/www/projects/example/preview"
  },
  "preview_deployment": {},
  "production_deployment": {},
  "git": {},
  "last_activity_at": null,
  "provisioning": {},
  "setup": {}
}
```

Fields:

- `id`: generated project slug.
- `name`: display name.
- `managed_server_id`: ID of the assigned managed server.
- `repository_path`: local Git working copy path. New projects use `/var/www/git/<project-id>`.
- `branch`: configured branch, currently defaulting to `main`.
- `production.domain`: Production domain.
- `production.path`: generated Production directory.
- `preview.domain`: generated Preview domain.
- `preview.path`: generated Preview directory.
- `preview_deployment`: Preview deployment metadata.
- `production_deployment`: Production deployment metadata.
- `git`: Git and GitHub metadata.
- `last_activity_at`: timestamp for recent project activity.
- `provisioning`: historical/internal setup metadata.
- `setup`: current user-facing setup metadata.

## Git Metadata

Default fields:

```json
{
  "provider": "github",
  "repository_owner": "",
  "repository_name": "",
  "remote_url": "",
  "clone_url": "",
  "bootstrap_status": "not_started",
  "remote_created_at": null,
  "last_error_at": null,
  "connected": false,
  "connected_at": null,
  "created_at": null,
  "local_head": "",
  "remote_head": "",
  "remote_verified": false,
  "remote_verified_at": null,
  "last_fetch_at": null,
  "last_pull_at": null
}
```

Fields:

- `provider`: currently `github`.
- `repository_owner`: GitHub user or organization.
- `repository_name`: GitHub repository name. It may differ from project ID after collision handling.
- `remote_url`: configured Git origin URL.
- `clone_url`: expected clone URL for the repository.
- `bootstrap_status`: initialization lifecycle state. Values include `not_started`, `local_created`, `remote_created`, `ready`, and `failed`.
- `remote_created_at`: timestamp when GitHub repository creation succeeded.
- `last_error_at`: timestamp of last bootstrap error.
- `connected`: saved connection flag.
- `connected_at`: timestamp when connection was established.
- `created_at`: creation timestamp for local metadata.
- `local_head`: last saved local commit.
- `remote_head`: last saved remote commit.
- `remote_verified`: saved remote verification flag.
- `remote_verified_at`: timestamp of remote verification.
- `last_fetch_at`: last successful fetch timestamp.
- `last_pull_at`: last successful pull timestamp.

Repository readiness is not supposed to depend only on these historical fields. Current Git facts such as `.git`, origin, branch, HEAD, and `origin/<branch>` are used by the readiness helper.

## Preview Deployment Metadata

Default fields:

```json
{
  "status": "never_deployed",
  "commit": "",
  "branch": "",
  "deployed_at": null,
  "managed_server_id": "",
  "duration_ms": null,
  "operation_id": "",
  "message": ""
}
```

Fields:

- `status`: `never_deployed`, `running`, `deployed`, or `failed`.
- `commit`: deployed commit hash.
- `branch`: deployed branch.
- `deployed_at`: completion timestamp.
- `managed_server_id`: server used for deployment.
- `duration_ms`: operation duration.
- `operation_id`: runtime operation ID.
- `message`: result or failure message.

## Production Deployment Metadata

Default fields include the Preview deployment fields plus:

```json
{
  "source": "",
  "last_attempt_status": "",
  "last_attempt_at": null,
  "last_attempt_commit": "",
  "last_attempt_message": ""
}
```

Fields:

- `source`: deployment source, currently Preview for successful production promotions.
- `last_attempt_status`: status of the most recent attempt.
- `last_attempt_at`: timestamp of the most recent attempt.
- `last_attempt_commit`: commit involved in the most recent attempt.
- `last_attempt_message`: message from the most recent attempt.

## Provisioning Metadata

Default fields:

```json
{
  "managed": false,
  "provisioned_at": null,
  "production_vhost": "",
  "preview_vhost": "",
  "routing_verified_at": null,
  "production_routing_verified": false,
  "preview_routing_verified": false
}
```

Fields:

- `managed`: internal flag indicating Dev Console-managed setup.
- `provisioned_at`: setup timestamp.
- `production_vhost`: Production Apache config name.
- `preview_vhost`: Preview Apache config name.
- `routing_verified_at`: last routing verification timestamp.
- `production_routing_verified`: Production routing result.
- `preview_routing_verified`: Preview routing result.

The UI does not expose the raw `managed` boolean as a status.

## Setup Metadata

Default fields:

```json
{
  "status": "Not configured",
  "server_id": "",
  "timestamp": null,
  "message": "",
  "preview_site": "",
  "production_site": "",
  "apache_version": ""
}
```

Fields:

- `status`: user-facing setup state such as Not configured, Configured, or Failed.
- `server_id`: managed server used for setup.
- `timestamp`: setup completion timestamp.
- `message`: setup result message.
- `preview_site`: Preview Apache site file.
- `production_site`: Production Apache site file.
- `apache_version`: detected Apache version from setup capability checks.

## `config/github.json`

Current default shape:

```json
{
  "account": "",
  "token": "",
  "default_visibility": "private",
  "configured_at": null,
  "verified": false,
  "last_verified_at": null,
  "authenticated_login": null
}
```

Fields:

- `account`: GitHub user or organization used for project repositories.
- `token`: Personal Access Token.
- `default_visibility`: retained in the model but repository visibility is forced private by current UI/workflow.
- `configured_at`: save timestamp.
- `verified`: whether the saved connection has been verified.
- `last_verified_at`: last successful verification timestamp.
- `authenticated_login`: login returned by GitHub for the token.

The token is passed to `gh` as `GH_TOKEN` and is not displayed in normal UI.

## `console/config/servers.json`

Top-level structure:

```json
[
  {
    "id": "server-id",
    "name": "Server Name",
    "host": "203.0.113.10",
    "port": 22,
    "user": "deploy",
    "auth_method": "ssh_key",
    "key": "/home/user/.ssh/dev_console_server",
    "key_fingerprint": "SHA256:...",
    "description": "",
    "status": "never_tested",
    "last_connection_test_at": null,
    "response_time_ms": null,
    "remote_hostname": "",
    "remote_os": "",
    "remote_kernel": "",
    "remote_working_directory": "",
    "remote_user": "",
    "passwordless_sudo": "unknown",
    "last_error": ""
  }
]
```

Fields:

- `id`: server slug.
- `name`: display name.
- `host`: hostname or IP.
- `port`: SSH port.
- `user`: SSH username.
- `auth_method`: currently `ssh_key`.
- `key`: private key path.
- `key_fingerprint`: SHA256 fingerprint.
- `description`: optional description.
- `status`: `never_tested`, `reachable`, or `unreachable`.
- `last_connection_test_at`: timestamp of last SSH test.
- `response_time_ms`: measured response time.
- `remote_hostname`: hostname returned by the server.
- `remote_os`: Linux distribution when parsed.
- `remote_kernel`: `uname -a` output.
- `remote_working_directory`: remote `pwd`.
- `remote_user`: remote `whoami`.
- `passwordless_sudo`: `unknown`, `ready`, `setup_required`, or `root`.
- `last_error`: last human-readable diagnostic error.

## Server Tool Operation State

Server tool operations are stored under `console/runtime/server-tool-operations`. Operation records include timestamp, tool, requested action, executed command summaries, result, installed version, status, stage, and output log.

Current implementation details are defined in `console/server-tools.php` and `console/run-server-tool.php`.

## Managed Server Operation State

Managed server SSH tests are stored under `console/runtime/managed-server-operations`.

Operation IDs use the form:

```text
mso_<32 lowercase hex characters>
```

Operation JSON contains status, stage, message, timestamps, server ID, result, and log reference. The `.log` file contains detailed diagnostic output.

## Preview Deployment Operation State

Preview operation IDs use:

```text
preview_deploy_<32 lowercase hex characters>
```

Runtime JSON/log files are stored under:

```text
console/runtime/preview-deployments
```

The operation result includes commit, branch, deployed timestamp, managed server ID, duration, message, and log output.

## Production Deployment Operation State

Production operation IDs use:

```text
production_deploy_<32 lowercase hex characters>
```

Runtime JSON/log files are stored under:

```text
console/runtime/production-deployments
```

The operation result includes promoted commit, source Preview metadata, deployed timestamp, managed server ID, duration, message, and log output.

## Task Files

Project tasks are Markdown files with YAML metadata:

```yaml
---
project_id: <project-id>
---

# TASK-001

## Title

...
```

Task file location defines task state:

- `TASKS/TODO/<task-id>.md`
- `TASKS/IN PROGRESS/<task-id>.md`
- `TASKS/DONE/<task-id>.md`
- `TASKS/DROPPED/<task-id>.md`

Attachments are stored under:

```text
TASKS/ATTACHMENTS/<task-id>/
```

`TASKS/README.md` is generated for project repositories and is not overwritten if it already exists.

## Apache Site Discovery Model

Apache discovery returns objects with:

- `name`: config filename.
- `path`: full config path.
- `enabled`: whether a matching file/link exists in `sites-enabled`.
- `server_name`: parsed `ServerName`.
- `server_aliases`: parsed `ServerAlias` values.
- `document_root`: parsed `DocumentRoot`.

Discovery reads:

- `/etc/apache2/sites-available`
- `/etc/apache2/sites-enabled`

It does not execute shell commands and returns an empty list if directories are unavailable.
