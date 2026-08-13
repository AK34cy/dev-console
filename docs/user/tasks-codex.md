# Tasks & Codex

Tasks live inside the current Project repository under `TASKS/`.

## Task Folders

- `TASKS/TODO`: tasks waiting to run.
- `TASKS/IN PROGRESS`: tasks currently being worked on or retryable after failure.
- `TASKS/DONE`: completed tasks.
- `TASKS/ATTACHMENTS`: task-scoped attachments.

Each task contains generated YAML metadata with the Project ID. Users edit the task body, not the metadata.

## Create Task

Create Task writes the next Project-specific task file, saves attachments, commits the task, pushes to GitHub, and selects it for workflow.

## Run Codex

Run Codex executes the selected task inside the Project repository.

The worker:

1. validates the Project repository and task metadata
2. moves a TODO task to IN PROGRESS
3. runs Codex
4. validates changed files
5. commits implementation changes outside `TASKS/`
6. pushes the implementation commit
7. moves the task to DONE
8. commits and pushes the task lifecycle update

## Retry After Failure

If a Codex run fails after preserving work, the same IN PROGRESS task can be retried. Dev Console should preserve implementation changes for the retry rather than requiring manual reset.

## Practical Loop

Create Task -> Run Codex -> Review result -> Deploy Preview.
