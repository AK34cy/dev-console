# Managed Server Actions

Managed Servers are registered in `console/config/servers.json` and operated through `console/servers.php`.

Page responsibilities:

- Settings: Dev Console host/runtime/configuration and local host tool management.
- Servers: managed server registry, SSH onboarding, Add/Edit/Remove, Test Connection, and compact runtime diagnostics.
- Server Management: selected Managed Server summary and future remote operations.

## Add Server

Purpose: create a managed server registration.

User inputs:

- Display name
- Hostname or IP
- SSH port
- SSH username
- Optional description

Current implementation uses the shared Dev Console server SSH key for new servers. It stores the private key path and SHA256 fingerprint, not private key contents.

Validation:

- Duplicate IDs are rejected.
- Host must match the accepted hostname/IP character pattern.
- Port must be 1 through 65535.
- Username must match the accepted Linux username pattern.
- Key must exist and be readable.
- Key permissions must not be group/world writable when permissions are detectable.

Result:

- Server is saved with status Never Tested.

## Edit Server

Purpose: update server registration metadata or connection settings.

The edit form reuses the server form. If host, port, SSH username, or key changes, diagnostic state resets to Never Tested. If connection-relevant values are unchanged, existing diagnostic state is preserved.

Server ID handling is update-oriented; editing an existing server must not accidentally create a duplicate server record.

## Remove Server

Purpose: remove only the server registration.

The action requires explicit confirmation. It does not delete SSH keys, remote users, remote files, Apache configuration, or remote data.

Removal is refused if any project currently references the server.

## Test Connection

Purpose: verify SSH access and remote capability state.

Execution model:

- The browser starts a managed server operation.
- Dev Console writes operation JSON/log files under `console/runtime/managed-server-operations`.
- `console/run-managed-server.php` executes the SSH test asynchronously.
- The browser polls operation status.

The fixed SSH diagnostic command collects:

- connection marker
- hostname
- `uname -a`
- current working directory
- remote user
- optional Linux distribution from `/etc/os-release`
- passwordless sudo status
- PHP path/version when available
- Composer path/version when available

The command set is fixed by the backend. The browser does not provide shell commands.

Success result:

- Status becomes Reachable.
- Last checked timestamp is saved.
- Response time is saved.
- Hostname, OS, kernel, remote user, and remote working directory are saved when available.
- Passwordless sudo state is saved.
- PHP and Composer diagnostic values are saved when available.

Failure result:

- Status becomes Unreachable.
- Last checked timestamp and response time are saved when available.
- A human-readable error is shown.

Known failure messages include:

- Authentication failed.
- Host unreachable.
- Connection timeout.
- SSH executable missing.
- Key file missing.
- Invalid key permissions.
- Passwordless sudo is not configured for this deployment user.

## Install Composer

Purpose: install Composer explicitly on a managed server for projects that need Composer during Preview deployment.

UI location: Server Management for the currently selected Managed Server. The Servers page remains focused on registry, onboarding, editing, removal, and SSH connection testing.

Execution model:

- The browser starts a managed server operation.
- `console/run-managed-server.php` dispatches the operation to the Composer installer.
- Dev Console uses SSH with fixed backend-generated commands.

Prerequisites:

- SSH executable exists locally.
- The configured SSH key exists and has acceptable permissions.
- The server is Ubuntu/Debian-family.
- The SSH user is root or `sudo -n true` succeeds.

Remote operations:

- Checks `/etc/os-release`.
- Checks whether Composer already exists.
- If Composer exists, the action succeeds without reinstalling.
- Otherwise runs fixed apt commands:
  - `apt-get update`
  - `apt-get install -y composer`
- Non-root users run the apt commands through `sudo -n`.
- Verifies `composer --version`.

Result:

- Composer path/version diagnostics are saved.
- The operation log keeps command output.

Failure conditions:

- Unsupported OS.
- Missing SSH executable or key.
- Passwordless sudo is not configured.
- Package manager failure.
- Composer verification failure.

## Generate SSH Key

Purpose: create the shared Dev Console server SSH key used for managed servers.

Internal operations:

- Runs `ssh-keygen -t ed25519`.
- Writes the private key to the service user's `.ssh/dev_console_server`.
- Writes the public key beside it.
- Applies safe permissions where possible.

Files modified:

- `~/.ssh/dev_console_server`
- `~/.ssh/dev_console_server.pub`

Result:

- The Servers page can show the public key, fingerprint, copy controls, and setup command.

## SSH Setup

Purpose: prepare the remote deployment user.

The generated setup command is intended to be run on the managed server. It is rendered with the configured SSH username and wrapped in a subshell so validation failures do not terminate the parent interactive shell.

The command performs:

- user validation
- creation of `~/.ssh`
- creation/update of `~/.ssh/authorized_keys`
- permission fixes for SSH files
- sudoers creation for non-root users
- `visudo` validation
- preparation of `/var/www/projects`

For non-root users, the sudoers file is:

```text
/etc/sudoers.d/dev-console-<user>
```

with:

```text
<user> ALL=(ALL) NOPASSWD: ALL
```

For `root`, Dev Console preserves root compatibility and does not need a sudoers rule for the deployment user.

## Server Validation

Validation happens when saving server records and before connection tests. The current implementation validates:

- duplicate IDs
- invalid host
- invalid port
- missing username
- invalid username
- missing key
- unreadable key
- invalid key permissions where detectable
- missing SSH executable during test

## Privilege Checks

Privilege readiness is checked during SSH testing and project setup.

For root SSH users:

- privileged project commands run directly.
- passwordless sudo state is treated as root/direct.

For non-root SSH users:

- Dev Console expects `sudo -n true` to succeed.
- privileged project commands use `sudo -n`.
- if sudo is not configured, the UI instructs the user to run the Managed Server setup command again as root or with sufficient setup privileges.

## Server Status

Visible statuses are:

- Never Tested
- Reachable
- Unreachable

The internal status values are:

- `never_tested`
- `reachable`
- `unreachable`

Additional diagnostic fields include last connection timestamp, response time, hostname, OS, kernel, remote user, remote working directory, passwordless sudo state, and last error.

## Details

Show details expands compact server information. The current implementation exposes:

- Server ID
- Display name
- Description
- Host
- SSH port
- SSH username
- SSH private key path for legacy/custom-key cases or advanced details
- SHA256 fingerprint
- Status
- Last connection test
- Response time
- Hostname
- OS
- Kernel
- Remote user
- Remote working directory

The default card view is intentionally compact and focuses on name, status, hostname, last check, response time, and action buttons.
