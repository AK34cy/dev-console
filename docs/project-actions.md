# Project Actions

This document describes Project action buttons implemented in the current UI and backend.

## Open on Dashboard

Purpose: make this project the current Dashboard project.

When to use: when working on a different registered project.

Safe to repeat: Yes.

Changes local filesystem: No.

Changes remote server: No.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: No.

Success conditions: `active_project_id` is saved in `config/projects.json`.

Failure conditions: unknown project ID or configuration write failure.

## Show Details / Hide Details

Purpose: expand or collapse project details.

When to use: when inspecting paths, domains, server assignment, Git metadata, setup metadata, or action details.

Safe to repeat: Yes.

Changes local filesystem: No.

Changes remote server: No.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: No.

Success conditions: client-side UI state changes.

Failure conditions: none expected; this is UI-only.

## Save Project

Purpose: update editable project fields.

When to use: when changing display name, Production domain, or managed server assignment.

Safe to repeat: Yes, if values remain valid.

Changes local filesystem: Yes, updates `config/projects.json`.

Changes remote server: No.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Result summary only.

Success conditions: project validates and configuration is saved.

Failure conditions: invalid project name/domain, duplicate domain/path, unknown managed server, or write failure.

## Initialize Repository

Purpose: create and connect the project Git repository.

When to use: after project registration and before task work.

Safe to repeat: No for a completed initialization; repeated use becomes verification/retry behavior only when current state permits it.

Changes local filesystem: Yes, creates `/var/www/git/<project-id>` on the Dev Console host and initial files.

Changes remote server: No.

Changes Git: Yes, initializes repository, commits, sets origin, pushes, and fetches.

Changes GitHub: Yes, creates a private GitHub repository when no collision exists.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: local repository exists, origin is configured, branch exists, HEAD resolves, remote branch resolves, push succeeds, and metadata is saved.

Failure conditions: GitHub config missing, `gh` missing, PAT invalid, repository collision, `/var/www/git` missing or unwritable, non-empty destination, Git failure, or push failure.

## Create Suggested Repository

Purpose: continue repository initialization using an available repository name when the preferred GitHub repository already exists.

When to use: after the collision panel suggests a name such as `<project-id>-2`.

Safe to repeat: No if creation succeeds; subsequent attempts would collide.

Changes local filesystem: Yes, same as Initialize Repository.

Changes remote server: No.

Changes Git: Yes.

Changes GitHub: Yes, creates the suggested private repository.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: suggested repository remains available and bootstrap completes.

Failure conditions: suggested repository is taken before creation, GitHub failure, local repository failure, or push failure.

## Choose Another Repository Name

Purpose: initialize the project repository with a manually entered GitHub repository name while keeping the project ID unchanged.

When to use: when the preferred repository name collides and the suggested name is not desired.

Safe to repeat: No if creation succeeds.

Changes local filesystem: Yes, during successful initialization.

Changes remote server: No.

Changes Git: Yes.

Changes GitHub: Yes.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: manual name is valid, available, and repository bootstrap completes.

Failure conditions: invalid repository name, existing repository, or bootstrap failure.

## Fetch

Purpose: refresh remote tracking information.

When to use: to update Git metadata from GitHub.

Safe to repeat: Yes.

Changes local filesystem: Yes, Git remote-tracking metadata and `config/projects.json`.

Changes remote server: No.

Changes Git: Yes, runs fetch.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: `git fetch --prune origin` succeeds and `last_fetch_at` is updated.

Failure conditions: repository not connected, authentication failure, or fetch failure.

## Pull

Purpose: fast-forward the local branch from GitHub.

When to use: when remote branch has changes that should be applied locally.

Safe to repeat: Yes when clean and already up to date.

Changes local filesystem: Yes, project repository and metadata.

Changes remote server: No.

Changes Git: Yes.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: working tree is clean and the fast-forward pull from origin succeeds. Current v1 behavior stores `branch` metadata but has no UI branch selector and remains `main`-oriented in several workflows.

Failure conditions: dirty working tree, branch mismatch, non-fast-forward state, authentication failure, or pull failure.

## Push

Purpose: push local repository commits to GitHub.

When to use: when local commits need to be synchronized.

Safe to repeat: Yes when up to date.

Changes local filesystem: Yes, metadata may be refreshed.

Changes remote server: No.

Changes Git: Yes.

Changes GitHub: Yes, updates remote branch.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: push succeeds and remote metadata verifies.

Failure conditions: repository not connected, authentication failure, remote rejection, or Git failure.

## Adopt Project

Purpose: register an existing website/project as a Dev Console Project while preserving its current source, Preview, Production, Apache, TASKS, and Git history.

When to use: after Add Existing Project discovery has produced a reviewed adoption plan.

Safe to repeat: No after success. A second adoption with the same Project ID or local target path is rejected.

Changes local filesystem: Yes, creates `/var/www/git/<project-id>` on the Dev Console host and updates `config/projects.json` only after source import succeeds.

Changes remote server: No. The remote server is inspected and read as the source of the import, but Preview, Production, Apache, and permissions are not modified.

Changes Git: Yes on the Dev Console host. Existing Git history is preserved when present. For non-Git sources, Dev Console initializes a local baseline repository and commits `Adopt existing project baseline`.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: confirmation values validate, the selected remote paths are still readable, stale discovery checks pass, the local repository target does not already exist, the source imports successfully, compatible TASKS are preserved or initialized, and Project configuration saves successfully.

Failure conditions: duplicate Project ID/domain/path, existing local target path, unreachable Managed Server, unreadable source or environment path, stale source HEAD/remote/TASKS data, incompatible GitHub remote owner, rsync failure, baseline Git failure, or configuration write failure.

## Set Up

Purpose: create managed environment directories and Apache virtual hosts.

When to use: after repository initialization and before deployments.

Safe to repeat: Mostly yes for managed Dev Console infrastructure; it refuses unrelated config/content.

Changes local filesystem: Yes, updates `config/projects.json`; local setup path also writes local Apache/project files.

Changes remote server: Yes for managed-server projects.

Changes Git: No.

Changes GitHub: No.

Changes Apache: Yes.

Requires sudo: Yes for non-root managed-server privileged operations.

Operation log: Yes.

Success conditions: environment directories exist, managed Apache vhosts are installed/enabled, configtest passes, Apache reload succeeds, setup metadata is saved.

Failure conditions: unreachable server, missing passwordless sudo, missing Apache tools, unrelated existing vhost, configtest failure, or filesystem permission failure.

## Retry Setup

Purpose: execute the existing setup operation again after setup failed.

When to use: after correcting the cause of a failed setup attempt.

Safe to repeat: Yes for Dev Console-managed infrastructure; it is still a mutating setup operation.

Changes local filesystem: Yes, updates `config/projects.json`; local setup path also writes local Apache/project files.

Changes remote server: Yes for managed-server projects.

Changes Git: No.

Changes GitHub: No.

Changes Apache: Yes.

Requires sudo: Yes for non-root managed-server privileged operations.

Operation log: Yes.

Success conditions: same as Set Up.

Failure conditions: same as Set Up.

## Update Infrastructure

Purpose: re-apply setup after infrastructure-affecting Project settings changed.

When to use: after changing Production domain, Preview domain, Managed Server assignment, or configured environment paths.

Safe to repeat: Yes for Dev Console-managed infrastructure; it is still a mutating setup operation. Adopted-in-place infrastructure marked configured is preserved rather than rewritten.

Changes local filesystem: Yes, updates `config/projects.json`; local setup path also writes local Apache/project files.

Changes remote server: Yes for managed-server projects.

Changes Git: No.

Changes GitHub: No.

Changes Apache: Yes.

Requires sudo: Yes for non-root managed-server privileged operations.

Operation log: Yes.

Success conditions: current environment directories and Apache vhosts match Project settings, Apache validates/reloads, and setup metadata is saved.

Failure conditions: unreachable server, missing passwordless sudo, missing Apache tools, conflicting Apache config, configtest failure, or filesystem permission failure.

## Deploy Preview

Purpose: deploy the selected Project Git state from the Dev Console host to the managed server Preview directory.

When to use: after project setup and successful repository synchronization.

Safe to repeat: Yes; it uses `rsync --delete`.

Changes local filesystem: Yes, runtime logs/state and temporary archive/source files.

Changes remote server: Yes, replaces Preview directory contents. For Composer projects it also creates/updates `vendor/` in Preview by running Composer remotely.

Changes Git: Local fetch/read-only archive operations on the Dev Console host.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No in the deployment module; remote Preview path must be writable by the deployment user. Composer must already be installed separately when required.

Operation log: Yes.

Success conditions: fetch, archive, dependency checks, rsync, Composer install when required, and remote verification all succeed; Preview metadata is saved.

Failure conditions: Git failure, missing rsync, SSH failure, missing `composer.lock`, missing remote PHP/Composer for Composer projects, Composer install failure, unwritable Preview path, or empty/unreadable remote result.

## Deploy Production

Purpose: promote remote Preview contents to remote Production.

When to use: after Preview has the version that should become Production and Production preflight has been reviewed.

Safe to repeat: Yes after a clean/current preflight; it uses remote `rsync --delete` with `.git/` and `TASKS/` excluded, explicit preserve rules applied, and any remaining deletion set explicitly approved.

Changes local filesystem: Yes, runtime logs/state and project metadata.

Changes remote server: Yes, replaces Production directory contents from Preview, including `vendor/` when Preview was prepared for a Composer project. Relative preserve rules prevent selected Production-local paths from being deleted or overwritten.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: Sometimes. The normal Preview-to-Production rsync runs as the configured deployment user. Exact deletion candidates that were explicitly approved in the current preflight may be removed with managed privileges (`sudo -n` for non-root deployment users, direct execution for root) when required. This privilege use does not bypass deletion approval or preserve rules. Production does not run Composer.

Operation log: Yes. The preflight result is stored in Project metadata and displayed before deployment. Deletion approval is stored as a fingerprint of the current preflight deletion set, not as a permanent rule.

Success conditions: Preview is deployed, preflight has run for that Preview commit, no unreviewed deletion candidates remain, remote Preview is readable/non-empty, remote rsync succeeds, Production verifies, and metadata is saved.

Failure conditions: no Preview deployment, missing or stale preflight, unreviewed deletion candidates, changed deletion set after approval, remote rsync missing, SSH failure, permission failure, or verification failure.

## Use in Workflow

Purpose: select a task as the current workflow task.

When to use: before running Codex or inspecting task workflow state.

Safe to repeat: Yes.

Changes local filesystem: Yes, selected task state is persisted by Dev Console.

Changes remote server: No.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Activity display may update.

Success conditions: selected task belongs to the current project.

Failure conditions: unknown task or project mismatch.

## Run Codex

Purpose: run Codex on the selected project task.

When to use: when a TODO or retryable IN PROGRESS task should be implemented.

Safe to repeat: Conditional. Retrying an IN PROGRESS task with preserved implementation changes is supported; rerunning completed tasks is not the normal flow.

Changes local filesystem: Yes, project files, task lifecycle files, and runtime logs.

Changes remote server: No.

Changes Git: Yes, implementation and task lifecycle commits.

Changes GitHub: Yes, pushes commits.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes.

Success conditions: Codex exits successfully, validation passes, implementation changes are committed when present, task moves to DONE, and pushes succeed.

Failure conditions: Codex unavailable/unauthenticated, dirty working tree on fresh task, validation failure, Git parsing/staging failure, commit failure, or push failure.

## Drop Task

Purpose: explicitly abandon a TODO task before execution, or abandon a failed IN PROGRESS task while preserving the task file and failed Codex run history.

When to use: when a TODO task was created by mistake or should not run, or when an IN PROGRESS task has a failed Codex run and should not be retried.

Safe to repeat: No after success because the task is terminal in DROPPED.

Changes local filesystem: Yes, moves the task from `TASKS/TODO/` or `TASKS/IN PROGRESS/` to `TASKS/DROPPED/` and updates the task YAML status.

Changes remote server: No.

Changes Git: Yes, commits and pushes the task lifecycle change.

Changes GitHub: Yes, pushes the lifecycle commit.

Changes Apache: No.

Requires sudo: No.

Operation log: Yes, lifecycle activity is appended to the task Codex log.

Success conditions: task is TODO, or task is IN PROGRESS with Codex run status Failed; lifecycle paths are committed, and push succeeds.

Failure conditions: task is not TODO or IN PROGRESS, IN PROGRESS run status is not Failed, task belongs to another project, or Git commit/push fails.

## Remove from Console

Purpose: remove only Dev Console's project registration.

When to use: when the project should no longer appear in Dev Console but infrastructure should remain.

Safe to repeat: No after removal because the project record no longer exists.

Changes local filesystem: Yes, updates `config/projects.json`.

Changes remote server: No.

Changes Git: No.

Changes GitHub: No.

Changes Apache: No.

Requires sudo: No.

Operation log: Summary only when raw command output is empty.

Success conditions: project record is removed.

Failure conditions: unknown project or config write failure.

## Delete Project

Purpose: remove managed project infrastructure and registration.

When to use: when Dev Console-created local/remote infrastructure should be removed.

Safe to repeat: No.

Changes local filesystem: Yes, updates `config/projects.json`; preserves local Git repository.

Changes remote server: Yes for managed-server projects.

Changes Git: No.

Changes GitHub: Optional, only if exact verified repository deletion is selected.

Changes Apache: Yes.

Requires sudo: Yes for non-root managed-server project operations.

Operation log: Yes.

Success conditions: confirmation matches project display name, managed Apache config is safely removed, generated directories are removed, project registration is removed, and optional GitHub deletion succeeds if selected.

Failure conditions: confirmation mismatch, marker mismatch, configtest failure, SSH/sudo failure, unsafe GitHub identity, or GitHub API failure.

## Cleanup Orphan

Purpose: remove detected Dev Console-managed orphan infrastructure.

When to use: when a project record is gone but managed local Apache/project files remain.

Safe to repeat: Conditional; only while orphaned managed artifacts exist.

Changes local filesystem: Yes.

Changes remote server: Current implementation documents local orphan cleanup behavior.

Changes Git: No.

Changes GitHub: No.

Changes Apache: Yes.

Requires sudo: Yes if local Apache/filesystem permissions require it.

Operation log: Yes.

Success conditions: managed markers match and cleanup completes.

Failure conditions: marker mismatch, configtest failure, or permission failure.
