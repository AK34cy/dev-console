# Preview

Preview is the remote environment used to review the current Project version before Production.

## Prerequisites

- Project repository is initialized.
- GitHub repository is configured.
- Managed Server is reachable.
- Preview path matches the generated Project path.
- SSH and rsync are available from Dev Console.
- For PHP projects with `composer.json`, `composer.lock` must be committed and PHP plus Composer must be installed on the Managed Server.

## Deploy Preview

Deploy Preview uses the GitHub version of the configured branch.

The current implementation:

1. fetches the configured branch
2. resolves `origin/<branch>`
3. creates a Git archive for that commit
4. extracts it to a temporary source directory
5. detects Composer dependencies when `composer.json` exists
6. requires `composer.lock` for Composer projects
7. checks remote PHP and Composer before changing Preview
8. rsyncs it to the remote Preview path with `--delete`, handling root `.env` according to the deployment source
9. runs `composer install --no-dev --prefer-dist --no-interaction --no-progress` remotely for Composer projects
10. verifies `vendor/autoload.php` for Composer projects
11. verifies the remote Preview directory exists, is readable, and contains files
12. saves Preview deployment metadata

Dev Console excludes only:

- `.git/`
- `TASKS/`

Dev Console supports both root `.env` models:

- Git-managed `.env`: if the deployment source contains a root `.env`, Dev Console deploys it like any other project file.
- Server-local `.env`: if the deployment source does not contain a root `.env`, Dev Console preserves an existing `.env` in the Preview deployment root and does not create one.

Dev Console does not create, edit, inspect, or manage `.env` contents. The server-local preservation rule is anchored to the Preview deployment root. A committed `.env.example` continues to deploy normally.

Preview can become outdated when new commits are pushed after the last Preview deployment.

Composer is not installed automatically during deployment. Use the Managed Server Composer install action first, then retry Preview deployment.

## Practical Scenario

After Codex completes a task, deploy the current Project version to Preview and review it in the browser.
