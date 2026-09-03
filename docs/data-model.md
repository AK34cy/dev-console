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
- `repository_path`: local Git working copy path on the Dev Console host. New projects and adopted projects use `/var/www/git/<project-id>`.
- `branch`: stored Project branch metadata. It currently defaults to `main`; branch selection is not exposed in the UI, and several v1 operational workflows are `main`-specific.
- `production.domain`: Production domain.
- `production.path`: Production directory. New projects use a generated path under `/var/www/projects/<project-id>/production`; adopted projects may preserve an existing in-place Production path.
- `preview.domain`: generated Preview domain.
- `preview.path`: Preview directory. New projects use a generated path under `/var/www/projects/<project-id>/preview`; adopted projects may preserve an existing in-place Preview path.
- `preview_deployment`: Preview deployment metadata.
- `production_deployment`: Production deployment metadata.
- `git`: Git and GitHub metadata.
- `last_activity_at`: timestamp for recent project activity.
- `provisioning`: historical/internal setup metadata.
- `setup`: current user-facing setup metadata.

Adopted projects are registered only after source import succeeds. Their `setup.infrastructure.adopted_in_place` value may be true, and `setup.infrastructure.source_imported_from` records the read-only Managed Server source path that was copied into the Dev Console host repository.

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
- `repository_name`: GitHub repository name. It may differ from project ID after collision handling or existing-project adoption.
- `remote_url`: configured Git origin URL. Adopted repositories preserve the detected compatible GitHub remote, including SSH host aliases.
- `clone_url`: clone URL for the repository. It may match the preserved origin rather than a Dev Console-generated default URL.
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

Repository readiness is not supposed to depend only on these historical fields. Current Git facts such as `.git`, origin, expected branch, HEAD, and the matching origin branch are used by the readiness helper.

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
  "last_attempt_message": "",
  "preserve_paths": [],
  "preflight": null,
  "deletion_approval": null
}
```

Fields:

- `source`: deployment source, currently Preview for successful production promotions.
- `last_attempt_status`: status of the most recent attempt.
- `last_attempt_at`: timestamp of the most recent attempt.
- `last_attempt_commit`: commit involved in the most recent attempt.
- `last_attempt_message`: message from the most recent attempt.
- `preserve_paths`: relative Production paths that Dev Console must preserve during Preview-to-Production promotion.
- `preflight`: latest read-only Production preflight result, including the checked Preview commit, source/target paths, add/update/delete/preserved counts, and any blocking deletion candidates.
- `deletion_approval`: one-preflight approval for the current deletion candidate set. It stores a deterministic fingerprint, approval timestamp, Preview commit, and approved paths. It is cleared when preflight is refreshed or preserve rules change.

Production preserve paths are Project metadata, not global deployment exclusions. They are relative to the Production root and are applied only during Production promotion.
Deletion approval is not a preserve rule. It authorizes deletion only when the currently checked deletion set still matches the stored fingerprint.

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
  "authenticated_login": null,
  "ssh_transport_verified": false,
  "ssh_transport_verified_at": null,
  "ssh_alias": null,
  "ssh_public_key_fingerprint": null
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
- `ssh_transport_verified`: whether the Dev Console GitHub SSH alias/key transport was verified.
- `ssh_transport_verified_at`: timestamp for the last successful SSH transport verification.
- `ssh_alias`: managed SSH host alias used by Project Git remotes.
- `ssh_public_key_fingerprint`: fingerprint for the Dev Console GitHub SSH public key.

The token is passed to `gh` as `GH_TOKEN` and is not displayed in normal UI.

## `console/config/servers.json`

Top-level structure:

```json
[
  {
    "id": "server-id",
    "name": "Server Name",
    "host": "10.0.0.1",
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
    "php_installed": false,
    "php_version": "",
    "php_path": "",
    "node_installed": false,
    "node_version": "",
    "node_path": "",
    "npm_installed": false,
    "npm_version": "",
    "npm_path": "",
    "composer_installed": false,
    "composer_version": "",
    "composer_path": "",
    "apache": {
      "installed": false,
      "running": null,
      "enabled": null,
      "version": "",
      "binary_path": "",
      "diagnostic_error": ""
    },
    "apache_sites": [],
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
- `php_installed`: whether remote PHP is detected.
- `php_version`: remote PHP version when detected.
- `php_path`: remote PHP executable path when detected.
- `node_installed`: whether remote Node.js is detected.
- `node_version`: remote Node.js version when detected.
- `node_path`: remote Node.js executable path when detected.
- `npm_installed`: whether remote npm is detected.
- `npm_version`: remote npm version when detected.
- `npm_path`: remote npm executable path when detected.
- `composer_installed`: whether remote Composer is detected.
- `composer_version`: remote Composer version when detected.
- `composer_path`: remote Composer executable path when detected.
- `apache.installed`: whether Apache is detected on the managed server.
- `apache.running`: whether Apache is running, or `null` when unknown.
- `apache.enabled`: whether Apache is enabled at boot, or `null` when unknown.
- `apache.version`: Apache version string when detected.
- `apache.binary_path`: Apache executable path when detected.
- `apache.diagnostic_error`: Apache-specific diagnostic error when detection fails.
- `apache_sites`: remote Apache virtual host inventory collected from the managed server.
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

Project tasks are Markdown files with YAML Front Matter. The parser preserves room for additional fields.

```yaml
---
task_id: TASK-001
project_id: <project-id>
title: Example task
status: TODO
created_at: 2026-08-14T00:00:00+00:00
updated_at: 2026-08-14T00:00:00+00:00
attachments:
  - name: logo.png
    path: attachments/TASK-001/logo.png
    mime: image/png
    size: 11264
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
TASKS/attachments/<task-id>/
```

Older `TASKS/ATTACHMENTS/<task-id>/` directories remain readable for compatibility.

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

Managed Server Apache inventory uses a similar read-only model but is collected remotely over SSH and persisted on the server record as `apache_sites`. Remote site entries may also include Dev Console ownership marker data, project ID, environment, and parse status when detectable. Managed ownership is conservative; unrelated Apache configuration is not treated as Dev Console-managed.
