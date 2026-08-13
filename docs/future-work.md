# Future Work

This list contains observations discovered while reading the current source. It does not propose new product features beyond what the code already hints at or leaves unfinished.

## Legacy Deployment Module

`console/deployment.php` still contains hardcoded old IOVON paths and domains, including `/var/www/iovon-ai-dev`, `/var/www/iovon-ai`, `/var/www/io`, `labs.iovon.com`, `iovon.com`, and the old Tailscale hostname. The module is still loaded by `index.php` for dashboard/environment state and session storage.

## Mixed Local and Managed Project Setup Paths

`console/projects.php` supports both local project setup and managed-server setup. Current project creation requires a managed server, but local setup code still exists. Future maintenance should decide whether both paths are still supported.

## Routing Verification Scope

Local routing verification performs HTTP checks against `127.0.0.1:80` with configured Host headers. For managed-server projects, current implementation does not clearly define equivalent remote HTTP routing verification.

## Preview and Production Permission Drift

Project setup uses privileged commands to prepare environment directories. Preview and Production deployment modules assume the deployment user can write the target paths. If remote ownership or permissions drift after setup, deployment fails rather than repairing them.

## Git Authentication Model

Project origins use the account-level SSH alias, while authenticated Git helper code also passes token credentials through `GIT_ASKPASS`. Current implementation does not clearly define which credential path Git should use for SSH remotes.

## Retained GitHub Visibility Field

`default_visibility` remains in `config/github.json`, but repository creation is always private and the UI no longer exposes a visibility selector.

## Runtime Log Retention

Operation state and logs are written under `console/runtime` and `console/runs/projects`. No retention or cleanup policy was observed for old operations.

## Server Setup Command Duplication

The managed server setup command exists in PHP and is also represented in browser JavaScript for live rendering as SSH username changes. These paths need to stay synchronized.

## Project Status from Metadata

Some project status displays use saved setup/provisioning metadata rather than always reading remote filesystem or Apache state. This is fast, but stale metadata can misrepresent drift.

## Production Deployment Privilege Behavior

Production deployment promotes Preview to Production with remote `rsync`. It does not use the project setup sudo wrapper path; current implementation expects the remote Production directory to be writable by the deployment user.

## Preview Deployment Privilege Behavior

Preview deployment prepares and verifies the remote Preview path without sudo. This matches the expected post-setup ownership model, but it means Preview deployment cannot repair missing ownership by itself.

## Apache Site Naming Differences

Remote managed-server vhosts use `<project-id>-preview.conf` and `<project-id>-production.conf`. The older local setup path uses `dev-console-<project-id>-preview.conf` and `dev-console-<project-id>-production.conf`.

## Task Sorting Duplication

Task list code sorts task collections before grouping, while visible grouped output also sorts by task number. The final behavior is project-specific ascending groups, but sorting logic appears duplicated.

## Legacy Task Storage

The task system still supports legacy global task storage for the default project. The UI intentionally shows a single legacy warning when legacy tasks exist.

## Codex Worker Global State

`startCodexRun()` relies on the active project ID from current application state when launching the asynchronous worker. This works in the current flow, but it is a coupling to page state.

## GitHub CLI Installation Source

The GitHub CLI install path uses `apt-get install -y gh` from configured package repositories. Current implementation does not add GitHub's official apt repository.

## Server Tool Operations

Server tool installation operations log commands and results, but no UI or backend retention policy was observed.

## Documentation Drift Risk

Several workflows are split across `index.php` plus helper modules and asynchronous worker scripts. Future code changes should update this documentation alongside behavior changes to keep it authoritative.
