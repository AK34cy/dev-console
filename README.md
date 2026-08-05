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
- Task creation with optional attachments.
- Codex run queueing and activity display.
- Preview and production deployment controls.
- Environment and deployment status dashboard.
- Unauthenticated JSON health endpoint at `/health`.
- Portable bootstrap script for systemd installation.

## Requirements

- Ubuntu server with systemd.
- Existing Linux user to run the service.
- PHP CLI. The bootstrap script installs `php-cli` with `apt` if it is missing.
- Git, for version metadata and task/deployment workflows.
- Existing `/etc/iovon-dev-console.env` containing `IOVON_DEV_CONSOLE_TOKEN`.
- Optional: Tailscale Serve for private HTTPS access.

## Installation

Clone or copy this repository onto the target server, then run the bootstrap from
the repository root:

```sh
sudo ./bootstrap.sh
```

To explicitly choose the Linux user that should run the service:

```sh
sudo ./bootstrap.sh --user iovon
```

If `--user` is omitted, the bootstrap uses `SUDO_USER` when available. Otherwise,
it uses the current user. The selected user must already exist and must be able
to read the project files.

The bootstrap script:

- Verifies Ubuntu, systemd, PHP CLI, and project file access.
- Installs only missing required packages.
- Generates `/etc/systemd/system/iovon-dev-console.service` from the tracked
  systemd template.
- Reloads systemd, enables the service, starts or restarts it, and verifies it is
  active.

## Configuration

Create `/etc/iovon-dev-console.env` before running the bootstrap:

```sh
sudo install -o root -g root -m 600 /dev/null /etc/iovon-dev-console.env
sudo sh -c 'printf "IOVON_DEV_CONSOLE_TOKEN=%s\n" "$(openssl rand -hex 32)" > /etc/iovon-dev-console.env'
```

The token is required for the web UI. It is not required for `/health`.

Retrieve the configured token when needed:

```sh
sudo sed -n 's/^IOVON_DEV_CONSOLE_TOKEN=//p' /etc/iovon-dev-console.env
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

If Tailscale Serve is configured, use `bin/start-dev-console` to display the
tailnet URL and service status:

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

Server Management uses a fixed allowlist for tool diagnostics: Codex CLI,
Node.js, npm, Composer, Git, and PHP. Results reflect whether each tool is
available to the Dev Console service user. Git and PHP remain read-only
diagnostics. Node.js, Composer, and Codex CLI can be installed or updated from
the Server Management tab through predefined POST actions with CSRF protection;
npm is installed together with Node.js.

## Updating

Update the repository checkout, then rerun the bootstrap so the installed
systemd unit is regenerated from the current files:

```sh
git pull
sudo ./bootstrap.sh
```

If the service user should change during an update, pass it explicitly:

```sh
sudo ./bootstrap.sh --user deploy
```

The bootstrap is idempotent and will restart the service when it is already
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

- `Missing environment file`: create `/etc/iovon-dev-console.env` with
  `IOVON_DEV_CONSOLE_TOKEN`.
- `Linux user does not exist`: rerun with `--user <existing-linux-user>` or
  create the account intentionally outside the bootstrap.
- `cannot read or traverse project path`: fix repository ownership or
  permissions so the selected service user can read the checkout.
- `/health` fails remotely but works locally: check the private tunnel or
  Tailscale Serve configuration.

More operational detail lives in [docs/DEV_CONSOLE.md](docs/DEV_CONSOLE.md).
