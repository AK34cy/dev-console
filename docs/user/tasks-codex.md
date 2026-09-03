# Tasks & Codex

Tasks live inside the current Project repository under `TASKS/`.

## Task Folders

- `TASKS/TODO`: tasks waiting to run.
- `TASKS/IN PROGRESS`: tasks currently being worked on or retryable after failure.
- `TASKS/DONE`: completed tasks.
- `TASKS/DROPPED`: explicitly abandoned TODO tasks or failed IN PROGRESS tasks.
- `TASKS/attachments`: task-scoped attachments. Older `TASKS/ATTACHMENTS` directories remain readable.

Each task contains generated YAML Front Matter with task metadata. Users edit the task body, not the metadata.

## Create Task

New Task explicitly starts creation of another task. Save Task writes the next Project-specific task file, saves attachments, commits the task, pushes to GitHub, and keeps the saved TODO task selected in the editor and Current Workflow.

The front matter contains at least:

```yaml
---
task_id: TASK-001
project_id: example
title: Example task
status: TODO
created_at: 2026-08-14T00:00:00+00:00
updated_at: 2026-08-14T00:00:00+00:00
attachments: []
---
```

Uploaded attachments are stored under `TASKS/attachments/<TASK-ID>/` and listed in the task YAML with name, path, MIME type, and size. Selecting **Use in Workflow** restores the task body and attachment list from the repository.

Dev Console owns task lifecycle state. Codex may read task Markdown, YAML metadata, and attachments as context, but it must not edit, move, delete, rename, stage, or update status/metadata for files under `TASKS/`.

TODO tasks remain editable before execution. Saving an existing TODO task updates that same task and keeps it open.

## Attachments

Task attachments use the Dev Console PHP runtime limits on the Dev Console host. They do not use Managed Server settings.

The default configured limits are:

- Maximum attachment size: 25 MB
- Maximum total request size: 50 MB

Change these in Settings -> Dev Console Runtime. Saved values require a Dev Console restart before PHP reports them as effective. The task form shows the currently effective limits and indicates when a restart is pending.

Attachments are committed with the task. Preview and Production deployments exclude the entire `TASKS/` directory, so task files and attachments are never deployed.

## Run Codex

Run Codex executes the selected task inside the Project repository.

Codex CLI is installed from Settings -> Dev Console Host Tools. If the CLI is
installed but not authenticated, use **Sign in with ChatGPT**. Dev Console runs
the official `codex login --device-auth` flow as the Dev Console service user
and shows the URL/code returned by the CLI. Dev Console does not implement its
own OpenAI OAuth flow and does not store or display Codex credential files.

The worker:

1. validates the Project repository and task metadata
2. moves a TODO task to IN PROGRESS
3. runs Codex
4. restores protected `TASKS/` state if Codex attempted to modify it
5. validates changed source files
6. commits implementation changes outside `TASKS/`
7. pushes the implementation commit
8. moves the task to DONE
9. commits and pushes the task lifecycle update

Implementation commits never include `TASKS/` changes. Dev Console commits task lifecycle updates separately after source validation, commit, and push succeed.

## Retry After Failure

If a Codex run fails after preserving work, the same IN PROGRESS task can be retried. Dev Console should preserve implementation changes for the retry rather than requiring manual reset.

## Drop Task

If a TODO task should not be executed, use Drop Task. If an IN PROGRESS task has a failed Codex run and should not continue, use Drop Task. Dev Console moves the task file to `TASKS/DROPPED`, updates the YAML status to `DROPPED`, commits and pushes that lifecycle change, and leaves any failed Codex run status/log intact.

DROPPED tasks are terminal. They are listed separately and are not editable or runnable.

## Practical Loop

New Task -> Save Task -> Run Codex -> Review result -> Deploy Preview.
