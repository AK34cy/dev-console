# Tasks & Codex

Tasks live inside the current Project repository under `TASKS/`.

## Task Folders

- `TASKS/TODO`: tasks waiting to run.
- `TASKS/IN PROGRESS`: tasks currently being worked on or retryable after failure.
- `TASKS/DONE`: completed tasks.
- `TASKS/attachments`: task-scoped attachments. Older `TASKS/ATTACHMENTS` directories remain readable.

Each task contains generated YAML Front Matter with task metadata. Users edit the task body, not the metadata.

## Create Task

Create Task writes the next Project-specific task file, saves attachments, commits the task, pushes to GitHub, and selects it for workflow.

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

## Attachments

Task attachments use the Dev Console PHP runtime limits on the Dev Console host. They do not use Managed Server settings.

The default configured limits are:

- Maximum attachment size: 25 MB
- Maximum total request size: 50 MB

Change these in Settings -> Dev Console Runtime. Saved values require a Dev Console restart before PHP reports them as effective. The task form shows the currently effective limits and indicates when a restart is pending.

Attachments are committed with the task. Preview and Production deployments exclude the entire `TASKS/` directory, so task files and attachments are never deployed.

## Run Codex

Run Codex executes the selected task inside the Project repository.

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

## Practical Loop

Create Task -> Run Codex -> Review result -> Deploy Preview.
