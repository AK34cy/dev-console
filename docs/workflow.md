# Dev Console Workflows

This document describes the workflows implemented in the current source code.

## Create Managed Server

Purpose: register a Linux server that Dev Console can contact over SSH.

Prerequisites:

- Dev Console is authenticated.
- The shared Dev Console SSH key exists, or the user generates it from the Servers page.

User actions:

1. Open Servers.
2. Fill display name, host, SSH port, SSH username, and optional description.
3. Save the server.

Internal operations:

- `console/index.php` handles `save_managed_server`.
- `console/servers.php` validates ID, host, port, username, key presence, key permissions, and duplicate IDs.
- The shared key path is stored unless editing a legacy/custom-key server.

Files modified:

- `console/config/servers.json`

Remote operations:

- None.

Result:

- The server appears in Managed Servers with status Never Tested unless prior diagnostics were preserved.

Possible failures:

- Duplicate server ID.
- Invalid host, port, or username.
- Missing or unreadable key.
- Unsafe key permissions.

Recovery:

- Correct the form data or generate the shared SSH key, then save again.

## SSH Setup

Purpose: prepare the remote deployment user for SSH and passwordless sudo.

Prerequisites:

- Shared Dev Console public key exists.
- User can run the generated command on the managed server as the deployment user with sufficient privileges for sudoers setup.

User actions:

1. Open Servers.
2. Copy the generated setup command.
3. Run it on the managed server.
4. Test the connection.

Internal operations:

- Dev Console renders a subshell-wrapped setup command with the configured SSH username.
- The command installs the public key into `~/.ssh/authorized_keys`.
- For non-root users, it creates `/etc/sudoers.d/dev-console-<user>`.
- It validates sudoers syntax with `visudo`.
- It prepares `/var/www/projects`.

Files modified:

- On the remote server: `~/.ssh/authorized_keys`
- On the remote server for non-root users: `/etc/sudoers.d/dev-console-<user>`
- On the remote server: `/var/www/projects`

Remote operations:

- The user manually runs the setup command. Dev Console does not run it automatically.

Result:

- SSH key access and passwordless sudo are prepared for later project operations.

Possible failures:

- Wrong remote user.
- Missing sudo.
- Invalid sudoers validation.
- Insufficient privileges.

Recovery:

- Run the command as the correct deployment user and ensure the account can use sudo for setup.

## Test Managed Server Connection

Purpose: verify SSH connectivity and server readiness.

Prerequisites:

- Server is registered.
- Key file exists and has safe permissions.
- `ssh` exists on the Dev Console host.

User actions:

1. Press Test Connection on a server card.

Internal operations:

- `index.php` starts `managedServerStartConnectionTest`.
- A runtime operation is written under `console/runtime/managed-server-operations`.
- `console/run-managed-server.php` executes the SSH test asynchronously.
- The fixed remote diagnostic command collects marker, hostname, kernel, working directory, user, optional OS release data, and sudo readiness.

Files modified:

- `console/runtime/managed-server-operations/<operation>.json`
- `console/runtime/managed-server-operations/<operation>.log`
- `console/config/servers.json`

Remote operations:

- SSH command executes only read-only diagnostics plus `sudo -n true` for privilege checking.

Result:

- Server status becomes Reachable or Unreachable.
- Last checked, response time, hostname, OS, kernel, remote user, working directory, and passwordless sudo state are persisted when available.

Possible failures:

- Authentication failed.
- Host unreachable.
- Connection timeout.
- Missing SSH executable.
- Missing key file.
- Invalid key permissions.
- Passwordless sudo not configured.

Recovery:

- Correct SSH settings, key setup, or sudo configuration, then test again.

## Edit Managed Server

Purpose: update an existing server registration.

Prerequisites:

- Server exists in `servers.json`.

User actions:

1. Press Edit on the server card.
2. Change fields.
3. Save.

Internal operations:

- `managedServersBuildFromInput` validates the edited fields.
- The server ID is used to update the existing server rather than creating a duplicate.
- Existing diagnostics are preserved unless connection-relevant fields change.
- If host, port, user, or key changes, status resets to Never Tested.

Files modified:

- `console/config/servers.json`

Remote operations:

- None.

Result:

- Updated server appears in the list.

Possible failures:

- Invalid fields or duplicate ID.

Recovery:

- Correct the edit form and save again.

## Remove Managed Server

Purpose: remove only the server registration.

Prerequisites:

- Server exists.
- No registered project references the server.

User actions:

1. Press Remove.
2. Confirm removal.

Internal operations:

- `managedServersRemove` deletes the record from `servers.json`.
- Dev Console refuses removal if a project still references the server.

Files modified:

- `console/config/servers.json`

Remote operations:

- None.

Result:

- Server registration is removed.

Possible failures:

- Server is still assigned to a project.

Recovery:

- Reassign or remove dependent projects first.

## Create Project

Purpose: register a project in Dev Console.

Prerequisites:

- At least one managed server is available and selected.
- Project name and domains are valid.

User actions:

1. Open Projects.
2. Enter project name.
3. Enter Production domain.
4. Select managed server.
5. Create project.

Internal operations:

- Project ID is generated from the name.
- Preview domain is generated as `preview.<production-domain>`.
- Paths are generated server-side:
  - `/var/www/projects/<project-id>/production`
  - `/var/www/projects/<project-id>/preview`
  - `/var/www/git/<project-id>`
- Duplicate project IDs, domains, paths, and repository paths are rejected.

Files modified:

- `config/projects.json`

Remote operations:

- None.

Result:

- Project appears in Projects and can become the current project.

Possible failures:

- Duplicate ID.
- Duplicate path or domain.
- Invalid domain.
- Missing managed server.

Recovery:

- Choose a different name/domain or configure a managed server.

## Select Current Project

Purpose: choose which project Dashboard and task actions operate on.

Prerequisites:

- Project exists.

User actions:

1. Use the project selector or Open on Dashboard action.

Internal operations:

- `active_project_id` is saved in `config/projects.json`.
- The selected tab is preserved when possible.

Files modified:

- `config/projects.json`

Remote operations:

- None.

Result:

- Dashboard, workflow, task lists, deployments, and Codex actions use the selected project.

Possible failures:

- Unknown project ID.

Recovery:

- Select a registered project.

## Initialize Repository

Purpose: create the project GitHub repository and local Git working copy.

Prerequisites:

- GitHub settings are configured.
- GitHub CLI is installed.
- Saved PAT is valid.
- `/var/www/git` exists and is writable by the Dev Console service user.
- The local repository path does not contain an existing repository or non-empty directory.

User actions:

1. Open Projects.
2. Press Initialize Repository.
3. If a remote repository collision exists, choose an alternate available repository name or cancel.

Internal operations:

- GitHub is tested with `GH_TOKEN=<saved token> gh api user`.
- The preferred GitHub repository is checked.
- A private GitHub repository is created through `gh`.
- A local repository is created in `/var/www/git/<project-id>`.
- Initial files are written and committed.
- Origin is set to the account-level SSH alias.
- `main` is pushed and fetched.
- Git metadata is saved.

Files modified:

- `/var/www/git/<project-id>`
- `config/projects.json`

Remote operations:

- GitHub repository lookup.
- GitHub private repository creation.
- Git push to GitHub.

Result:

- Git status becomes Connected/Ready when current repository facts verify.

Possible failures:

- GitHub CLI missing.
- PAT missing or rejected.
- Repository name collision.
- `/var/www/git` missing or unwritable.
- Git push failure.

Recovery:

- Install/configure GitHub CLI and token, provision `/var/www/git`, choose an available repository name, or fix SSH access.

## Fetch Repository

Purpose: update remote tracking metadata.

Prerequisites:

- Project repository is initialized and connected.

User actions:

1. Press Fetch.

Internal operations:

- Ensures the expected origin.
- Runs `git fetch --prune origin`.
- Updates Git metadata including `last_fetch_at`.

Files modified:

- `config/projects.json`
- Git remote-tracking metadata inside the project repository.

Remote operations:

- Git fetch from GitHub.

Result:

- Last Fetch reflects the successful fetch time.

Possible failures:

- Remote unavailable.
- Authentication failure.
- Invalid local repository.

Recovery:

- Fix repository remote or GitHub access, then fetch again.

## Pull Repository

Purpose: fast-forward the local project repository.

Prerequisites:

- Project repository is connected.
- Working tree is clean.
- Current branch matches configured branch.

User actions:

1. Press Pull.

Internal operations:

- Fetches origin.
- Runs `git pull --ff-only origin <branch>`.
- Updates `last_fetch_at` and `last_pull_at`.

Files modified:

- Project repository files if new commits are pulled.
- `config/projects.json`

Remote operations:

- Git fetch/pull from GitHub.

Result:

- Local repository is fast-forwarded.

Possible failures:

- Dirty working tree.
- Non-fast-forward state.
- Remote authentication failure.

Recovery:

- Resolve local changes or remote divergence manually, then retry.

## Push Repository

Purpose: push local branch state to GitHub.

Prerequisites:

- Project repository is connected.

User actions:

1. Press Push.

Internal operations:

- Ensures origin.
- Pushes configured branch to origin.
- Fetches/verifies remote state.
- Updates Git metadata.

Files modified:

- `config/projects.json`

Remote operations:

- Git push to GitHub.

Result:

- Remote branch is synchronized.

Possible failures:

- Authentication failure.
- Remote rejected push.
- Invalid repository state.

Recovery:

- Fix Git/SSH state and retry.

## Set Up

Purpose: prepare Preview and Production directories and Apache virtual hosts.

Prerequisites:

- Project exists.
- Managed server exists and is reachable for managed projects.
- Generated environment paths are valid.
- Apache command capabilities are available remotely or locally.
- Non-root managed server users have passwordless sudo.

User actions:

1. Press Set up.

Internal operations:

- For managed-server projects, Dev Console checks SSH capabilities.
- It creates Preview and Production directories.
- It writes managed Apache virtual host configuration.
- It enables sites, runs Apache configtest, and reloads Apache.
- It stores setup and provisioning metadata.

Files modified:

- `config/projects.json`
- Remote or local `/var/www/projects/<project-id>/...`
- Remote or local Apache `sites-available` and `sites-enabled`

Remote operations:

- SSH commands for directory creation, ownership, Apache config installation, `a2ensite`, `apache2ctl configtest`, and Apache reload.

Result:

- Project infrastructure state becomes Ready.

Possible failures:

- SSH unavailable.
- Passwordless sudo missing.
- Apache missing.
- Existing unrelated Apache config at target path.
- Apache configtest failure.

Recovery:

- Run the managed server setup command, install Apache, resolve conflicting config, or fix Apache errors, then retry setup.

## Retry Setup

Purpose: run the same setup operation again after setup failed.

Prerequisites:

- Project exists.
- Previous setup attempt failed.
- The original failure condition has been corrected.

User actions:

1. Press Retry Setup.

Internal operations:

- Executes the same backend operation as Set Up.
- Rechecks managed server capabilities.
- Re-prepares directories and Apache configuration.
- Saves setup metadata on success.

Files modified:

- `config/projects.json`
- Remote or local project infrastructure, depending on project type.
- Apache configuration when setup proceeds.

Remote operations:

- Same as Set Up for managed-server projects.

Result:

- Project infrastructure state becomes Ready if setup succeeds.

Possible failures:

- Same as Set Up.

Recovery:

- Correct the reported setup failure and retry again.

## Update Infrastructure

Purpose: re-apply project infrastructure after infrastructure-affecting settings change.

Prerequisites:

- Project was previously set up.
- Production domain, generated Preview domain, Managed Server assignment, or generated paths changed.
- Managed server and Apache prerequisites are satisfied.

User actions:

1. Press Update Infrastructure.

Internal operations:

- Executes the same backend operation as Set Up.
- Revalidates stored project configuration.
- Re-prepares directories and Apache virtual hosts for the current settings.
- Saves fresh setup/provisioning metadata.

Files modified:

- `config/projects.json`
- Remote or local project infrastructure, depending on project type.
- Apache configuration when setup proceeds.

Remote operations:

- Same as Set Up for managed-server projects.

Result:

- Infrastructure state becomes Ready when setup succeeds.

Possible failures:

- Same as Set Up, including conflicting Apache configuration or missing server privileges.

Recovery:

- Fix the reported infrastructure issue and run Update Infrastructure again.

## Create Task

Purpose: create a project-scoped Markdown task.

Prerequisites:

- Current project is selected.
- Project repository is usable.
- Task body is non-empty.

User actions:

1. Enter task body.
2. Add optional attachments.
3. Press Create Task.

Internal operations:

- Generates the next project-specific task ID.
- Prepends YAML metadata.
- Writes `TASKS/TODO/<task-id>.md`.
- Ensures `TASKS/README.md` exists without overwriting it.
- Stores attachments under `TASKS/attachments/<task-id>`.
- Commits and pushes the task addition.
- Selects the task for current workflow.

Files modified:

- Project repository `TASKS/`
- Project Git history
- `config/projects.json`

Remote operations:

- Git push to GitHub.

Result:

- Task appears in the TODO group and can be used in workflow.

Possible failures:

- Repository not ready.
- Empty task body.
- Unknown project.
- File write failure.
- Git commit or push failure.

Recovery:

- Fix repository readiness or task content, then create again.

## Run Codex

Purpose: execute a selected task inside the current project repository.

Prerequisites:

- Current task is selected.
- Task belongs to current project.
- Codex CLI is installed and authenticated.
- For a fresh TODO task, the project working tree must not contain unrelated dirty changes.

User actions:

1. Select Use in Workflow for a task.
2. Press Run Codex.

Internal operations:

- Starts `console/run-codex.php` asynchronously.
- Validates project repository and task metadata.
- Moves TODO task to IN PROGRESS, unless retrying an existing IN PROGRESS task.
- Runs Codex in the project repository with a generated prompt.
- Treats `TASKS/` as Dev Console-owned protected state.
- Restores the pre-Codex `TASKS/` state if Codex edits task metadata, moves task files, deletes attachments, or changes unrelated task files.
- Parses Git porcelain output without trimming status columns.
- Commits implementation changes outside `TASKS/`.
- Moves the task to DONE.
- Commits and pushes task lifecycle state.

Files modified:

- Project repository implementation files changed by Codex.
- Project `TASKS/` lifecycle files.
- Runtime files under `console/runs/projects/<project-id>`.

Remote operations:

- Git push to GitHub.

Result:

- Task is completed and moved to DONE when implementation and lifecycle commits succeed.

Possible failures:

- Codex CLI missing or unauthenticated.
- Dirty working tree on fresh TODO run.
- Validation failure.
- Git commit or push failure.
- No implementation changes when work is not already satisfied.

Recovery:

- Fix the reported issue. If the task is left IN PROGRESS with preserved work, retry the same task.

## Deploy Preview

Purpose: deploy the selected GitHub branch to the managed server Preview path.

Prerequisites:

- Project repository is connected.
- Managed server is reachable.
- Preview path matches `/var/www/projects/<project-id>/preview`.
- `rsync` and `ssh` are available locally.

User actions:

1. Press Deploy Preview.
2. Confirm the deployment.

Internal operations:

- Starts an asynchronous Preview deployment.
- Fetches the configured branch.
- Resolves `origin/<branch>`.
- Creates a Git archive of that remote commit.
- Extracts the archive into a temporary source directory.
- Runs `rsync --delete` to the remote Preview path, excluding `.git/` and `TASKS/`.
- Verifies remote Preview directory exists, is readable, and contains at least one file.
- Stores Preview deployment metadata.

Files modified:

- `config/projects.json`
- Runtime files under `console/runtime/preview-deployments`
- Temporary files under `/tmp/dev-console-preview-deployments`

Remote operations:

- SSH directory checks.
- Remote file synchronization with rsync.

Result:

- Preview deployment status becomes Deployed with commit, branch, server, timestamp, and duration.

Possible failures:

- Git fetch/archive failure.
- Missing local rsync.
- SSH failure.
- Remote path missing or not writable.
- Remote verification failure.

Recovery:

- Fix Git, SSH, or remote permissions and deploy again.

## Deploy Production

Purpose: promote current remote Preview contents to Production.

Prerequisites:

- Preview has been deployed successfully.
- Managed server is reachable.
- Preview and Production paths match generated project paths.
- Remote Preview directory is readable and non-empty.
- Remote `rsync` is available.

User actions:

1. Press Deploy Production.
2. Confirm the modal.

Internal operations:

- Starts an asynchronous Production deployment.
- Checks remote Preview.
- Ensures remote Production directory exists and is writable.
- Runs remote `rsync --delete --no-owner --no-group` from Preview to Production.
- Verifies remote Production directory exists, is readable, and contains files.
- Stores Production deployment metadata.

Files modified:

- `config/projects.json`
- Runtime files under `console/runtime/production-deployments`

Remote operations:

- SSH checks and remote rsync.

Result:

- Production deployment status becomes Deployed with source Preview and commit metadata.

Possible failures:

- Preview not deployed.
- Remote `rsync` missing.
- SSH failure.
- Remote path permission failure.
- Verification failure.

Recovery:

- Deploy Preview first, install rsync remotely, or fix permissions.

## Delete Project

Purpose: remove Dev Console-managed project infrastructure and project registration.

Prerequisites:

- User confirms by typing the exact project display name.
- Project has managed setup metadata.

User actions:

1. Press Delete Project.
2. Type the project display name.
3. Optionally check Delete GitHub repository when an exact verified repository identity is available.
4. Confirm Delete Project.

Internal operations:

- Verifies managed Apache config markers.
- Disables managed Apache sites.
- Runs Apache configtest and reloads Apache.
- Removes managed Apache config files.
- Removes Preview and Production directories.
- Removes project root if empty.
- Removes project registration.
- Optionally deletes the exact configured GitHub repository through `gh`.

Files modified:

- `config/projects.json`
- Remote or local Apache configuration.
- Remote or local project directories.

Remote operations:

- SSH privileged operations for managed-server projects.
- Optional GitHub repository deletion.

Result:

- Project registration and managed local infrastructure are removed.
- Local Git repository is preserved.
- GitHub repository is preserved unless explicitly selected and verified.

Possible failures:

- Confirmation mismatch.
- Unverified GitHub repository identity.
- Apache config marker mismatch.
- Configtest failure.
- SSH or sudo failure.

Recovery:

- Correct confirmation, fix server access, or clean conflicting infrastructure manually.

## Remove from Console

Purpose: remove only the project registration.

Prerequisites:

- Project exists.

User actions:

1. Press Remove from Console.
2. Confirm.

Internal operations:

- Removes the project record from `config/projects.json`.

Files modified:

- `config/projects.json`

Remote operations:

- None.

Result:

- Dev Console no longer lists the project.
- Directories, Apache configuration, local Git repository, and GitHub repository remain.

Possible failures:

- Unknown project ID.

Recovery:

- Re-add the project manually if needed.

## Repository Deletion

Purpose: optionally delete the exact GitHub repository associated with a project during Project deletion.

Prerequisites:

- Project has verified GitHub repository metadata.
- User explicitly checks the Delete GitHub repository checkbox in the Delete Project modal.
- Saved GitHub PAT has permission to delete repositories.

User actions:

1. Open Delete Project modal.
2. Check Delete GitHub repository.
3. Type project display name.
4. Confirm.

Internal operations:

- Verifies configured repository identity.
- Calls `gh api -X DELETE repos/<owner>/<repo>` with `GH_TOKEN`.

Files modified:

- `config/projects.json`

Remote operations:

- GitHub repository deletion.

Result:

- Exact configured GitHub repository is deleted.

Possible failures:

- Repository identity unavailable or unsafe.
- Token lacks permission.
- GitHub API failure.

Recovery:

- Leave repository preserved or delete manually in GitHub.

## Cleanup Orphaned Project

Purpose: remove Dev Console-managed Apache/project infrastructure for orphaned projects.

Prerequisites:

- Orphaned managed Apache configuration exists.
- Config markers identify it as Dev Console managed.

User actions:

1. Use the orphan cleanup action when shown.

Internal operations:

- Disables/removes managed Apache vhosts.
- Removes managed generated environment directories.
- Preserves `/var/www/git/<project-id>` and GitHub repositories.

Files modified:

- Apache site configuration.
- `/var/www/projects/<project-id>` generated environment directories.

Remote operations:

- Current implementation handles the local cleanup path.

Result:

- Orphaned managed infrastructure is removed.

Possible failures:

- Marker mismatch.
- Apache configtest failure.
- Filesystem permission failure.

Recovery:

- Inspect and clean manually.

## Server Management Tool Installation

Purpose: install or update local Dev Console host tools.

Prerequisites:

- Dev Console service has permission to run package manager commands.
- Ubuntu/Debian-compatible package environment for supported installs.

User actions:

1. Open Server Management.
2. Press Install, Update, Reinstall, or Refresh for supported tools.

Internal operations:

- Starts asynchronous server tool operation.
- Runs predefined commands only.
- Refreshes diagnostics after success.
- Writes operation history and logs.

Files modified:

- Runtime operation files under `console/runtime/server-tool-operations`.
- System package/tool locations depending on action.

Remote operations:

- Network package downloads may occur for installs or updates.

Result:

- Diagnostics and Dashboard software versions reflect the new state.

Possible failures:

- Network unavailable.
- Unsupported OS.
- Permission failure.
- Package manager failure.
- Composer checksum failure.

Recovery:

- Fix server/package environment and rerun.
