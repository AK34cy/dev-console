# Security

This document describes the current security model implemented in source.

## Authentication

Dev Console uses a single shared token from the environment variable:

```text
IOVON_DEV_CONSOLE_TOKEN
```

Users authenticate by submitting the token through the login form. The authenticated state is stored in the PHP session.

The `/health` endpoint is unauthenticated by design and returns only status, version, PHP version, timestamp, uptime, and best-effort Git commit metadata.

## Session Cookies

Session cookies are configured with:

- `httponly: true`
- `samesite: Strict`
- `secure: true` for HTTPS or port 443
- `secure: false` for plain HTTP, including SSH tunnel access such as `http://127.0.0.1:8090`

Session files are stored under the legacy deployment state directory:

```text
/tmp/iovon-deployments/sessions
```

## CSRF Protection

State-changing POST actions require the session CSRF token. The token is generated in the session and rendered into forms or JavaScript requests.

Examples of CSRF-protected actions include project actions, GitHub settings, Apache actions, server tool actions, managed server actions, task creation, and deployment starts.

## Command Execution

`console/process.php` provides the shared command runner. It executes commands as argv arrays through `proc_open`, supports timeouts, and captures stdout, stderr, exit code, and command display output.

The implementation avoids accepting arbitrary browser-provided shell commands. Actions map to fixed backend operations such as `install_apache`, `start_apache`, `restart_apache`, server tool actions, Git operations, and deployment operations.

Known places that construct shell command strings are used for controlled contexts:

- remote SSH commands, where the remote shell receives a backend-generated command with escaped validated paths
- asynchronous worker launch commands, where generated operation IDs and escaped arguments are used
- setup command text rendered for the user to run manually on a managed server

## Output Redaction

Process output redacts known sensitive values and patterns, including:

- `GH_TOKEN`
- `GITHUB_TOKEN`
- `IOVON_GIT_TOKEN`
- Authorization headers
- credentials embedded in HTTPS URLs

GitHub tokens are not passed as command-line arguments.

## GitHub Authentication

GitHub configuration is stored in:

```text
config/github.json
```

Dev Console has one global GitHub configuration. Project repositories use that global account and authentication; there are no per-Project GitHub credentials in the current implementation.

The Personal Access Token is passed to GitHub CLI commands using:

```text
GH_TOKEN=<saved PAT>
```

The token is passed through the child-process environment and is not written to `~/.config/gh` by Dev Console. The implementation does not require `gh auth login`.

GitHub CLI is used for:

- connection testing
- account/organization verification
- Dev Console GitHub SSH public-key registration
- repository lookup
- private repository creation
- optional exact repository deletion

Normal Project Git repository remotes currently use the configured account-level SSH alias `github.com-dev-console-account`. Dev Console maintains one service-user SSH key for this alias and preserves unrelated SSH configuration. Dev Console also contains an askpass helper path for authenticated Git commands using token environment variables, but this does not create per-Project credentials.

## SSH Authentication

Managed servers use SSH key authentication. Server records store:

- key path
- SHA256 fingerprint

They do not store private key contents.

The current shared key path is derived from the service user's home directory:

```text
~/.ssh/dev_console_server
```

The public key is:

```text
~/.ssh/dev_console_server.pub
```

Private key permissions are checked where possible. Group/world writable private keys are rejected.

## SSH Key Handling

The Servers page can generate the shared Dev Console server key. The generated public key is shown for copying. The private key path is stored in `servers.json`; private key contents are not displayed or copied into project repositories.

Legacy/custom-key servers may still expose private key path information in advanced UI sections for diagnostics.

## Remote Execution

Managed server tests execute only a fixed diagnostic command. The browser cannot supply remote shell commands.

Preview and Production deployment remote commands are constructed by backend code from validated project and server configuration. New Projects default to generated paths such as:

```text
/var/www/projects/<project-id>/preview
/var/www/projects/<project-id>/production
```

Adopted Projects may use explicitly configured existing paths instead. Deployment code uses the stored Project paths rather than deriving paths from the Project ID.

Remote path values are shell-escaped before being embedded in remote commands.

## Privilege Model

Managed server privileged operations use a simple v1 model:

- if SSH user is `root`, commands run directly
- otherwise privileged commands run through `sudo -n`

Dev Console does not install or use a custom Apache helper binary in the current source.

The managed server setup command configures passwordless sudo for non-root deployment users:

```text
<user> ALL=(ALL) NOPASSWD: ALL
```

stored at:

```text
/etc/sudoers.d/dev-console-<user>
```

The setup command validates sudoers syntax with `visudo`.

## Passwordless Sudo

Connection tests check whether `sudo -n true` succeeds for non-root users. If it fails, Dev Console records the server as reachable but reports that passwordless sudo is not configured for the deployment user.

Project setup and deletion require privileged operations for Apache and `/var/www/projects`. Those operations fail unless the deployment user has passwordless sudo or the SSH user is root.

## Codex AppArmor Sandbox

Ubuntu 24.04 can keep `kernel.apparmor_restrict_unprivileged_userns=1`, which
blocks unconfined processes from creating unprivileged user namespaces. Codex's
normal workspace sandbox uses its bundled Bubblewrap binary, so Dev Console
installs a narrow AppArmor profile for that bundled executable instead of
disabling the global restriction.

The managed profile is:

```text
/etc/apparmor.d/iovon-dev-console-codex-bwrap
```

It matches only the Dev Console service user's standalone Codex release tree:

```text
<service-home>/.codex/packages/standalone/releases/*/codex-resources/bwrap
```

The profile uses Ubuntu's `flags=(unconfined)` plus `userns,` pattern for
applications that need user namespaces. Dev Console does not grant user
namespace creation globally, does not disable AppArmor, and does not persist
`kernel.apparmor_restrict_unprivileged_userns=0`.

Useful verification commands:

```sh
sysctl kernel.apparmor_restrict_unprivileged_userns
sudo apparmor_parser -Q -T /etc/apparmor.d/iovon-dev-console-codex-bwrap
```

## Apache Safety

Dev Console-generated Apache files contain managed markers. Setup and deletion paths check those markers before overwriting or removing managed configuration.

The local ServerName config refuses to overwrite unrelated existing content at:

```text
/etc/apache2/conf-available/iovon-dev-console-servername.conf
```

Apache config changes run `apache2ctl configtest` before reload where implemented.

## Project Deletion Safety

Delete Project requires typing the exact project display name. Remove from Console and Delete Project are separate actions.

Remove from Console:

- removes only the project registration
- preserves directories
- preserves Apache configuration
- preserves local Git repositories
- preserves GitHub repositories

Delete Project:

- removes managed Apache configuration
- removes managed Preview/Production directories
- removes project registration
- preserves local Git repositories
- preserves GitHub repositories unless exact verified deletion is explicitly selected

GitHub deletion is unavailable unless the repository identity is safely verified.

## Repository Collision Safety

When the preferred GitHub repository already exists during initialization, Dev Console does not delete it, attach it, overwrite it, or assume ownership.

The collision flow suggests an available repository name such as `<project-id>-2` while preserving the project ID.

## Generated Files and Permissions

Configuration writes use JSON encoding and atomic replacement where implemented.

Observed permissions:

- `config/github.json`: written with mode `0600`
- `config/projects.json`: written with mode `0640`
- `console/config/servers.json`: written with mode `0640`
- generated private SSH key: mode `0600`
- generated public SSH key: mode `0644`

Runtime logs and operation files are stored under `console/runtime` and `console/runs`.

## Security Assumptions

The current implementation assumes:

- Dev Console is an internal administrative tool.
- The service process may need significant local privileges for Apache/tool installation workflows.
- Managed server deployment users are trusted to have passwordless sudo.
- The GitHub PAT has sufficient repository permissions for configured workflows.
- Project paths follow Dev Console-generated path rules.
- Remote servers are Linux systems with SSH and standard shell utilities.
