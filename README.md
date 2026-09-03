# IOVON Dev Console

IOVON Dev Console turns AI coding into a controlled website development and
deployment workflow.

You describe a change as a task. Codex CLI works on the Project source in a
local Git repository. Dev Console commits and synchronizes the result with
GitHub, deploys it to Preview for review, and promotes it to Production only
after an explicit user action.

```text
AI task -> Git -> GitHub -> Preview -> human approval -> Production
```

Dev Console is for website owners, developers, consultants, and small teams who
want AI-assisted implementation without giving the AI direct authority over live
Production releases.

## Why It Exists

AI can make useful code changes, but the surrounding workflow still matters:

- Which source tree should be changed?
- What changed, and was it committed?
- Was the repository synchronized?
- Can the result be tested before it goes live?
- Who approves Production?
- How are servers, SSH access, and privileged operations controlled?

Dev Console packages those control points into one internal console. The core
principle is:

> AI writes the change. Git records it. Preview proves it. You decide whether it
> goes live.

## What It Includes

- Token-protected internal web console.
- Managed Server registration, SSH onboarding, diagnostics, and tool actions.
- Project creation and existing-project adoption.
- Local Project repositories on the Dev Console host under `/var/www/git`.
- Private GitHub repository initialization and synchronization.
- Task files with persistent attachments.
- Codex CLI task execution.
- Preview deployment over SSH/rsync.
- Production preflight, deletion review, and explicit Production promotion.
- Documentation for users and implementation maintainers.
- Portable Git-checkout installer for Ubuntu/systemd hosts.

Dev Console itself runs as a normal Linux service user, not as root. Operations
that genuinely need host or Managed Server privileges use explicit,
non-interactive privilege paths such as `sudo -n`.

## Documentation Path

Start here:

1. [Product & User Guide](docs/product-guide.md) explains what Dev Console is,
   who it is for, and the product/security model.
2. [Getting Started](docs/user/getting-started.md) covers the practical install
   and first-run workflow.
3. User documentation:
   [Workflow](docs/user/workflow.md),
   [Projects](docs/user/projects.md),
   [Servers](docs/user/servers.md),
   [Git & GitHub](docs/user/git.md),
   [Tasks & Codex](docs/user/tasks-codex.md),
   [Preview](docs/user/preview.md),
   [Production](docs/user/production.md),
   [Security](docs/user/security.md), and
   [Troubleshooting](docs/user/troubleshooting.md).
4. Technical reference:
   [Architecture](docs/architecture.md),
   [Workflow Internals](docs/workflow.md),
   [Project Actions](docs/project-actions.md),
   [Server Actions](docs/server-actions.md),
   [Data Model](docs/data-model.md),
   [Security Internals](docs/security.md), and
   [Service Notes](docs/DEV_CONSOLE.md).

## Requirements

- Ubuntu server with systemd.
- Existing non-root Linux user to run the service.
- `sudo` access for installation.
- Optional: Tailscale Serve, SSH port forwarding, VPN, or another private access
  path for browser access.

The installer installs the base packages required for Dev Console itself,
including PHP CLI and required PHP extensions, curl, Git, OpenSSH client, rsync,
sudo, and certificate support.

GitHub CLI, Codex CLI, and Managed Server tools such as Composer, Node.js, npm,
and Apache are detected automatically and changed only through explicit user
actions in the console.

## Installation

Clone this repository onto the target server, then run the installer from the
repository root:

```sh
sudo git clone <repo> /var/www/dev-console
cd /var/www/dev-console
sudo ./install.sh
```

To explicitly choose the existing Linux user that should run the service:

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
- Preserves existing local configuration, secrets, Project repositories, Managed
  Server definitions, SSH keys, and runtime state.
- Ensures `/var/www/git` and ignored Dev Console runtime directories exist with
  service-user ownership.
- On Ubuntu hosts that restrict unprivileged user namespaces through AppArmor,
  configures a narrow Codex sandbox profile if Codex standalone is already
  installed.
- Installs and restarts `iovon-dev-console.service`.
- Checks the local `/health` endpoint with a bounded retry loop.

## First Login

The service listens on:

```text
http://127.0.0.1:8090
```

The installer creates `/etc/iovon-dev-console.env` if it does not exist and
stores `IOVON_DEV_CONSOLE_TOKEN` with `root:root` ownership and `0600`
permissions. Existing tokens are preserved.

On first install, the newly generated token is printed once in the installer
completion summary. On reruns, the existing token is preserved and is not
printed.

Retrieve the configured token when needed:

```sh
sudo grep '^IOVON_DEV_CONSOLE_TOKEN=' /etc/iovon-dev-console.env
```

Remote browser access should be provided through a private path such as
Tailscale Serve, SSH port forwarding, or VPN. Do not expose the PHP listener as a
public unauthenticated service.

## Normal Workflow

1. Install Dev Console.
2. Install/sign in to Codex CLI from Settings.
3. Configure GitHub in Settings.
4. Add and test a Managed Server.
5. Create a new Project or adopt an existing website.
6. Initialize the Project repository when needed.
7. Set up infrastructure when needed.
8. Create a task and run Codex.
9. Deploy Preview and review the site.
10. Promote Preview to Production explicitly.

Production deployment is never automatic just because Codex completed a task.

## Operations

Start, restart, or inspect the service:

```sh
sudo systemctl start iovon-dev-console.service
sudo systemctl restart iovon-dev-console.service
systemctl status iovon-dev-console.service --no-pager
```

Check the unauthenticated health endpoint:

```sh
curl -fsS http://127.0.0.1:8090/health
```

Update the repository checkout, then rerun the installer so the installed
systemd unit and host setup are refreshed:

```sh
git pull
sudo ./install.sh
```

More service-level detail lives in [docs/DEV_CONSOLE.md](docs/DEV_CONSOLE.md).
