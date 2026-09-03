# IOVON Dev Console service

For the main project overview and common setup path, start with
[../README.md](../README.md). This document keeps the lower-level service notes.

The Dev Console runs as `iovon-dev-console.service` and listens only on
`127.0.0.1:8090`. Remote browser access should be provided by a private tunnel,
VPN, SSH port forwarding, or another private network path. Tailscale Serve is a
supported optional access method, but it is not required by the Dev Console
runtime. Apache is not used to serve Dev Console itself.

Install the service once, on the server, from a Git checkout:

```sh
sudo git clone <repo> /var/www/dev-console
cd /var/www/dev-console
sudo ./install.sh
```

To choose the existing non-root Linux user that should run the service:

```sh
sudo ./install.sh --user deploy
```

If `--user` is omitted, the installer uses `SUDO_USER` when available. The
installer does not create Linux users in v1 and refuses to run the service as
`root`.

Connect through a private access path, open the local or tunneled Console URL,
and enter the persistent token in the authentication form. The token is
submitted in the request body so it does not appear in the URL or journald
access logs. The console then stores authentication in its secure HTTP session.

The token is generated only during the first installation and stored at
`/etc/iovon-dev-console.env` with `0600` permissions. The installer prints a
newly generated token once in the completion summary. Existing tokens are
preserved on rerun and are not printed. A root user can retrieve the current
token when needed with:

```sh
sudo grep '^IOVON_DEV_CONSOLE_TOKEN=' /etc/iovon-dev-console.env
```

Service operations:

```sh
systemctl status iovon-dev-console.service
sudo systemctl restart iovon-dev-console.service
journalctl -u iovon-dev-console.service -f
```

The systemd unit starts `bin/run-dev-console`, which applies Dev Console-specific
PHP runtime options before launching the built-in PHP server. Attachment upload
limits are stored locally in `console/config/runtime.json` and are applied as:

```sh
php -d upload_max_filesize=<MB>M -d post_max_size=<MB>M ...
```

Changing these values in Settings saves the local runtime configuration, but the
effective PHP values do not change until `iovon-dev-console.service` is
restarted. This does not modify global PHP configuration and does not affect
Managed Servers.

The installer also ensures the local runtime/configuration directories and
`/var/www/git` exist with service-user ownership. Fresh installs do not populate
project configuration, GitHub credentials, managed servers, SSH keys, task
history, or deployment history.

Older installed units may still start `/usr/bin/php -S` directly. Run
`sudo ./install.sh` from the repository root once to install the updated unit
that uses `bin/run-dev-console`; the installer reloads and restarts the service.

Confirm the local bind with:

```sh
ss -ltnp 'sport = :8090'
```

If Tailscale Serve is configured separately, `bin/start-dev-console` displays
the tailnet URL and systemd status.

Health checks can be performed without authentication:

```sh
curl -fsS http://127.0.0.1:8090/health
```

The endpoint returns HTTP 200 with JSON containing the console status, version,
PHP version, current timestamp, process uptime, and current Dev Console Git
commit. If Git metadata is unavailable, `git_commit` is returned as `null`.

## Documentation Updates

Any task that changes user-visible workflow or major architecture should update
the relevant documentation in the same change. User-facing help lives under
`docs/user/`; technical reference documents live directly under `docs/`.
