# IOVON Dev Console

IOVON Dev Console is a small, private PHP console for managing local development
workflows on an Ubuntu server. It provides a browser UI for creating task files,
running Codex against those tasks, viewing run activity, and triggering preview
or production deployments.

The service is designed to bind only to `127.0.0.1:8090`. Remote access should be
provided by a private tunnel such as Tailscale Serve rather than by exposing the
PHP server directly.

For lower-level service details, see [docs/DEV_CONSOLE.md](docs/DEV_CONSOLE.md).

## Features

- Token-protected web console for internal use.
- Managed Server registration, SSH onboarding, and connection testing.
- Task creation with optional attachments.
- Codex run queueing and activity display.
- Project repository initialization with GitHub.
- Preview and production deployment controls.
- Remote Server Management diagnostics for runtime tools, Apache, and assigned projects.
- Project, workflow, and deployment status dashboard.
- Built-in Documentation section.
- Unauthenticated JSON health endpoint at `/health`.
- Portable Git-checkout installer for systemd installation.

## Requirements

- Ubuntu server with systemd.
- Existing non-root Linux user to run the service.
- `sudo` access for installation.
- Optional: Tailscale Serve or another private tunnel for private HTTPS access.

The installer installs the base packages required for Dev Console itself,
including PHP CLI and required PHP extensions, curl, Git, OpenSSH client, rsync,
sudo, and certificate support. GitHub CLI, Node.js, npm, Composer, and Codex CLI
are managed as optional host or server tools after installation.

## Installation

Clone this repository onto the target server, then run the installer from the
repository root:

```sh
sudo git clone <repo> /var/www/dev-console
cd /var/www/dev-console
sudo ./install.sh
```

To explicitly choose the Linux user that should run the service:

```sh
sudo ./install.sh --user deploy
```

If `--user` is omitted, the installer uses `SUDO_USER` when available. It does
not create Linux users in v1. The selected user must already exist, must not be
`root`, and must be able to read the checkout.

The installer:

- Verifies Ubuntu, systemd, required packages, PHP extensions, and checkout
  access.
- Installs missing base packages with `apt`.
- Creates `/etc/iovon-dev-console.env` with a strong token if it is missing.
- Preserves existing local configuration, secrets, project repositories, managed
  server definitions, SSH keys, and runtime state.
- Ensures `/var/www/git` and ignored Dev Console runtime directories exist with
  service-user ownership.
- On Ubuntu hosts that restrict unprivileged user namespaces through AppArmor,
  configures a narrow Codex sandbox profile if Codex standalone is already
  installed.
- Generates `/etc/systemd/system/iovon-dev-console.service` from the tracked
  systemd template.
- Reloads systemd, enables the service, starts or restarts it, and verifies it is
  active.
- Checks the local `/health` endpoint.

## Configuration

The installer creates `/etc/iovon-dev-console.env` if it does not exist and
stores `IOVON_DEV_CONSOLE_TOKEN` with `root:root` ownership and `0600`
permissions. Existing tokens are preserved. The token is required for the web UI
and is not required for `/health`.

On first install, the newly generated token is printed once in the installer
completion summary. On reruns, the existing token is preserved and is not
printed.

Retrieve the configured token when needed:

```sh
sudo grep '^IOVON_DEV_CONSOLE_TOKEN=' /etc/iovon-dev-console.env
```

Dev Console runtime settings, including task attachment upload limits, are
managed in Settings -> Dev Console Runtime. The defaults are 25 MB per
attachment and 50 MB per request. Saving new values requires restarting
`iovon-dev-console.service` before PHP reports them as effective. If Settings
reports that the runtime unit needs an update, run `sudo ./install.sh` once to
install the service unit that starts `bin/run-dev-console`.

Project repositories are stored on the Dev Console host under `/var/www/git`.
Fresh installs start with no configured projects, no GitHub token, and no
managed servers.

On Ubuntu 24.04, AppArmor may restrict unprivileged user namespaces while Codex
needs its bundled Bubblewrap sandbox. Dev Console keeps the global kernel
restriction enabled and, after Codex standalone is installed, manages only this
profile:

```text
/etc/apparmor.d/iovon-dev-console-codex-bwrap
```

If Codex is installed later from Settings, Dev Console attempts the same profile
setup with non-interactive `sudo -n`. If that is unavailable, run the installer
again after installing Codex:

```sh
sudo ./install.sh
```

## Starting the Service

Start, restart, or inspect the systemd service with:

```sh
sudo systemctl start iovon-dev-console.service
sudo systemctl restart iovon-dev-console.service
systemctl status iovon-dev-console.service --no-pager
```

The service listens on:

```text
http://127.0.0.1:8090
```

If Tailscale Serve is configured separately, use `bin/start-dev-console` to
display the tailnet URL and service status:

```sh
bin/start-dev-console
```

## Health Endpoint

The health endpoint does not require authentication:

```sh
curl -fsS http://127.0.0.1:8090/health
```

Example response:

```json
{
  "status": "ok",
  "version": "0.1",
  "php_version": "8.3.6",
  "timestamp": "2026-07-24T15:59:58+00:00",
  "uptime": "2m 14s",
  "git_commit": "2e0a4e6eb9fc5e58add25624f7a0166aa55343ba"
}
```

If Git metadata is unavailable, `git_commit` is returned as `null`.

## Server Management

Settings shows Dev Console host prerequisites such as Git, PHP, Codex CLI, and
GitHub configuration. Server Management is scoped to one selected Managed
Server and shows remote PHP, Composer, Node.js, npm, Apache status, Apache site
inventory, and projects assigned to that server. Composer can be installed from
Server Management through a predefined POST action with CSRF protection.

## Updating

Update the repository checkout, then rerun the installer so the installed
systemd unit is regenerated from the current files:

```sh
git pull
sudo ./install.sh
```

If the service user should change during an update, pass it explicitly:

```sh
sudo ./install.sh --user deploy
```

The installer is idempotent and will restart the service when it is already
active.

## Troubleshooting

Check service state and logs:

```sh
systemctl status iovon-dev-console.service --no-pager
journalctl -u iovon-dev-console.service -f
```

Confirm the local listener:

```sh
ss -ltnp 'sport = :8090'
```

Common issues:

- `Linux user does not exist`: rerun with `--user <existing-linux-user>` or
  create the account intentionally outside the installer.
- `cannot read or traverse project path`: fix repository ownership or
  permissions so the selected service user can read the checkout.
- `/health` fails remotely but works locally: check the private tunnel or
  Tailscale Serve configuration.

More operational detail lives in [docs/DEV_CONSOLE.md](docs/DEV_CONSOLE.md).
