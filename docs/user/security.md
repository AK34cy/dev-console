# Security

Dev Console is an internal administrative tool. It should run only in a trusted private environment.

## SSH

Managed Servers use key-based SSH authentication. Dev Console stores the SSH key path and fingerprint metadata, not sudo passwords.

The Dev Console Server Key is the shared key used for managed server access.

The configured SSH user is also the remote management and deployment user.

## Privileges

Dev Console v1 uses a simple privilege model:

- root SSH users run privileged commands directly
- non-root SSH users use `sudo -n`

The generated server setup command configures passwordless sudo for the deployment user.

Dev Console does not store sudo passwords.

## GitHub

GitHub operations use the Personal Access Token saved in Settings. GitHub CLI commands receive the token through the `GH_TOKEN` environment variable.

The token is not placed in command-line arguments.

## Network Assumption

Dev Console is intended for private access, such as a local tunnel, Tailscale, or another private network. Do not expose it as a public unauthenticated service.

For implementation-level details, read [Technical Security](?tab=documentation&doc=technical-security).
