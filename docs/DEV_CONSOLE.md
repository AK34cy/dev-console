# IOVON Dev Console service

The Dev Console runs as `iovon-dev-console.service` and listens only on
`127.0.0.1:8090`. Tailscale Serve provides its permanent, tailnet-only HTTPS URL.
Run `bin/start-dev-console` on the server to display that URL and the service
status. Tailscale Funnel and Apache are not used.

Install the service once, on the server, from the repository root:

```sh
sudo bin/install-dev-console
```

The installer verifies that Tailscale is connected and shows the existing Serve
configuration before making changes. It refuses to overwrite an existing Serve
configuration. If an existing route is reported, add a non-conflicting HTTPS
route for `http://127.0.0.1:8090` using the installed Tailscale CLI instead of
resetting the existing configuration.

Connect the Mac or iPad to the same tailnet, open the HTTPS URL displayed by
`bin/start-dev-console`, and enter the persistent token in the authentication
form. The token is submitted in the request body so it does not appear in the
URL or journald access logs. The console then stores authentication in its secure
HTTP session.

The token is generated only during the first installation and stored at
`/etc/iovon-dev-console.env`. It is not printed or committed. A root user can
retrieve it when needed with:

```sh
sudo sed -n 's/^IOVON_DEV_CONSOLE_TOKEN=//p' /etc/iovon-dev-console.env
```

Service operations:

```sh
systemctl status iovon-dev-console.service
sudo systemctl restart iovon-dev-console.service
journalctl -u iovon-dev-console.service -f
```

Confirm the local bind and private HTTPS route with:

```sh
ss -ltnp 'sport = :8090'
tailscale serve status
```
