# Preview

Preview is the remote environment used to review the current Project version before Production.

## Prerequisites

- Project repository is initialized.
- GitHub repository is configured.
- Managed Server is reachable.
- Preview path matches the generated Project path.
- SSH and rsync are available from Dev Console.

## Deploy Preview

Deploy Preview uses the GitHub version of the configured branch.

The current implementation:

1. fetches the configured branch
2. resolves `origin/<branch>`
3. creates a Git archive for that commit
4. extracts it to a temporary source directory
5. rsyncs it to the remote Preview path with `--delete`
6. verifies the remote Preview directory exists, is readable, and contains files
7. saves Preview deployment metadata

Dev Console excludes only:

- `.git/`
- `TASKS/`

Preview can become outdated when new commits are pushed after the last Preview deployment.

## Practical Scenario

After Codex completes a task, deploy the current Project version to Preview and review it in the browser.
