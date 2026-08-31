# Getting Started

Dev Console is an internal tool for managing Projects, task execution with Codex, and Preview/Production deployments on managed Linux servers.

For a fresh Dev Console host, install from a Git checkout:

```sh
git clone <repo> /var/www/dev-console
cd /var/www/dev-console
sudo ./install.sh
```

The installer uses the invoking `SUDO_USER` as the service user unless
`--user <existing-linux-user>` is provided. It creates the local token, systemd
unit, `/var/www/git`, and Dev Console runtime directories without creating any
projects or credentials.

The normal v1 workflow is:

1. Prepare a Linux server.
2. Configure SSH access.
3. Add a Managed Server.
4. Test Connection.
5. Create Project.
6. Initialize Repository.
7. Set up infrastructure.
8. Create Task.
9. Run Codex.
10. Deploy Preview.
11. Review the website.
12. Deploy Production.

## What You Need First

- A Linux server reachable over SSH.
- A deployment SSH user on that server.
- The Dev Console Server Key generated in the Servers page.
- GitHub configured in Settings.
- GitHub CLI installed on the Dev Console host.
- Codex CLI installed and authenticated before running Codex tasks.

Managed Servers do not need Git for Dev Console operation. Dev Console keeps Project repositories on the Dev Console host and sends deployment files to Managed Servers over SSH/rsync.

Root SSH is not required. Dev Console v1 expects the configured SSH user to have passwordless sudo after running the generated server setup command.

## Practical First Run

1. Open Servers.
2. Generate the Dev Console Server Key if it does not exist.
3. Add a server record with host, port, and SSH username.
4. Use Select/Edit on that server to show the setup command.
5. Log in to the remote server as that SSH user and run the setup command.
6. Save the server and press Test Connection.
7. Open Projects and create a Project assigned to the reachable server.
8. Initialize Repository.
9. Press Set up.
10. Create a task and run Codex.
11. Deploy Preview.
12. After review, deploy Production.

Dev Console does not deploy to Production automatically.
