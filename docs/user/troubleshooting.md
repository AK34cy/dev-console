# Troubleshooting

## Server Unreachable

Symptoms: Test Connection reports Unreachable or times out.

Likely reason: host, port, network path, firewall, SSH daemon, or key access is wrong.

What to do next: verify host and port, confirm SSH works manually with the configured key, then test again.

## SSH Key Not Configured

Symptoms: Test Connection reports key missing or Add Server/Test Connection is disabled.

Likely reason: the Dev Console Server Key has not been generated or the configured key path is unreadable.

What to do next: generate the Dev Console Server Key and save the server with that key.

## Passwordless Sudo Missing

Symptoms: server is reachable but setup says server preparation is required.

Likely reason: the SSH user cannot run `sudo -n true`.

What to do next: run the Managed Server setup command for that SSH user, then test connection again.

## Repository Not Initialized

Symptoms: Create Task or Deploy Preview reports that the repository is not initialized.

Likely reason: Initialize Repository has not completed or repository metadata/local Git state is incomplete.

What to do next: open Projects and initialize or repair the repository.

## Git Working Tree Dirty

Symptoms: Run Codex rejects a fresh TODO task.

Likely reason: there are unrelated uncommitted changes in the Project repository.

What to do next: review the Project repository changes and resolve them before starting a fresh task.

## Setup Failed

Symptoms: Infrastructure shows Setup failed.

Likely reason: SSH, sudo, Apache tools, Apache configtest, or filesystem permissions failed.

What to do next: read the operation log, fix the reported server issue, then use Retry Setup.

## Infrastructure Update Required

Symptoms: Infrastructure shows Update required.

Likely reason: an infrastructure-affecting Project setting changed after setup.

What to do next: review the Project settings, then use Update Infrastructure.

## Codex Failed

Symptoms: Codex run status is Failed.

Likely reason: Codex authentication, validation, Git commit, or push failed.

What to do next: read the Codex activity and raw log. If work was preserved in IN PROGRESS, retry the same task after fixing the reported issue.

## Preview Deployment Failed

Symptoms: Preview deployment status is Failed.

Likely reason: Git fetch/archive, SSH, rsync, remote path permissions, or remote verification failed.

What to do next: read the Preview operation log, fix the reported Git or server issue, then deploy Preview again.

## Production Deployment Failed

Symptoms: Production deployment status is Failed.

Likely reason: Preview is missing, remote rsync is unavailable, SSH failed, or Production path verification failed.

What to do next: confirm Preview is deployed and readable, fix the reported remote issue, then deploy Production again.
