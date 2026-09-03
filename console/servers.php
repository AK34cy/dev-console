<?php

const DEV_CONSOLE_MANAGED_SERVER_RUNTIME_DIR = __DIR__ . '/runtime/managed-server-operations';

function managedServersConfigPath(): string
{
    return __DIR__ . '/config/servers.json';
}

function managedServersRuntimeDirectory(): string
{
    if (!is_dir(DEV_CONSOLE_MANAGED_SERVER_RUNTIME_DIR)) {
        @mkdir(DEV_CONSOLE_MANAGED_SERVER_RUNTIME_DIR, 0700, true);
    }

    return DEV_CONSOLE_MANAGED_SERVER_RUNTIME_DIR;
}

function managedServersEmptyServer(): array
{
    return [
        'id' => '',
        'name' => '',
        'host' => '',
        'port' => 22,
        'user' => '',
        'auth_method' => 'ssh_key',
        'key' => '',
        'key_fingerprint' => '',
        'description' => '',
        'status' => 'never_tested',
        'last_connection_test_at' => null,
        'response_time_ms' => null,
        'remote_hostname' => '',
        'remote_os' => '',
        'remote_kernel' => '',
        'remote_working_directory' => '',
        'remote_user' => '',
        'passwordless_sudo' => 'unknown',
        'php_installed' => false,
        'php_version' => '',
        'php_path' => '',
        'node_installed' => false,
        'node_version' => '',
        'node_path' => '',
        'npm_installed' => false,
        'npm_version' => '',
        'npm_path' => '',
        'composer_installed' => false,
        'composer_version' => '',
        'composer_path' => '',
        'apache' => [
            'installed' => false,
            'running' => null,
            'enabled' => null,
            'version' => '',
            'binary_path' => '',
            'diagnostic_error' => '',
        ],
        'apache_sites' => [],
        'last_error' => '',
    ];
}

function managedServersNormalizeId(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = preg_replace('/-+/', '-', $value) ?? '';

    return trim($value, '-');
}

function managedServersNormalize(array $input): array
{
    $servers = [];
    foreach ($input as $serverInput) {
        if (!is_array($serverInput)) {
            continue;
        }
        $server = managedServersEmptyServer();
        foreach (['id', 'name', 'host', 'user', 'auth_method', 'key', 'key_fingerprint', 'description', 'status', 'remote_hostname', 'remote_os', 'remote_kernel', 'remote_working_directory', 'remote_user', 'passwordless_sudo', 'php_version', 'php_path', 'node_version', 'node_path', 'npm_version', 'npm_path', 'composer_version', 'composer_path', 'last_error'] as $field) {
            if (isset($serverInput[$field]) && is_scalar($serverInput[$field])) {
                $server[$field] = trim((string)$serverInput[$field]);
            }
        }
        foreach (['php_installed', 'node_installed', 'npm_installed', 'composer_installed'] as $field) {
            if (array_key_exists($field, $serverInput)) {
                $server[$field] = filter_var($serverInput[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (is_array($serverInput['apache'] ?? null)) {
            $apacheInput = $serverInput['apache'];
            $server['apache']['installed'] = filter_var($apacheInput['installed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            foreach (['running', 'enabled'] as $field) {
                $server['apache'][$field] = array_key_exists($field, $apacheInput)
                    ? (filter_var($apacheInput[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE))
                    : null;
            }
            foreach (['version', 'binary_path', 'diagnostic_error'] as $field) {
                if (isset($apacheInput[$field]) && is_scalar($apacheInput[$field])) {
                    $server['apache'][$field] = trim((string)$apacheInput[$field]);
                }
            }
        }
        if (is_array($serverInput['apache_sites'] ?? null)) {
            foreach ($serverInput['apache_sites'] as $siteInput) {
                if (!is_array($siteInput)) {
                    continue;
                }
                $site = [
                    'name' => '',
                    'path' => '',
                    'enabled' => null,
                    'server_name' => '',
                    'document_root' => '',
                    'managed_marker' => false,
                    'project_id' => '',
                    'environment' => '',
                ];
                foreach (['name', 'path', 'server_name', 'document_root', 'project_id', 'environment'] as $field) {
                    if (isset($siteInput[$field]) && is_scalar($siteInput[$field])) {
                        $site[$field] = trim((string)$siteInput[$field]);
                    }
                }
                if (array_key_exists('enabled', $siteInput)) {
                    $site['enabled'] = filter_var($siteInput['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                }
                if (array_key_exists('managed_marker', $siteInput)) {
                    $site['managed_marker'] = filter_var($siteInput['managed_marker'], FILTER_VALIDATE_BOOLEAN);
                }
                if ($site['name'] !== '') {
                    $server['apache_sites'][] = $site;
                }
            }
        }
        $server['id'] = managedServersNormalizeId($server['id']);
        $server['auth_method'] = $server['auth_method'] === 'ssh_key' ? 'ssh_key' : 'ssh_key';
        $server['status'] = in_array($server['status'], ['never_tested', 'reachable', 'unreachable'], true) ? $server['status'] : 'never_tested';
        $server['passwordless_sudo'] = in_array($server['passwordless_sudo'], ['unknown', 'ready', 'setup_required', 'root'], true) ? $server['passwordless_sudo'] : 'unknown';
        $server['port'] = isset($serverInput['port']) && is_numeric($serverInput['port']) ? (int)$serverInput['port'] : 22;
        if (array_key_exists('last_connection_test_at', $serverInput)) {
            $value = $serverInput['last_connection_test_at'];
            $server['last_connection_test_at'] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
        }
        if (array_key_exists('response_time_ms', $serverInput)) {
            $server['response_time_ms'] = is_numeric($serverInput['response_time_ms']) ? (int)$serverInput['response_time_ms'] : null;
        }
        if ($server['id'] !== '') {
            $servers[] = $server;
        }
    }

    return $servers;
}

function managedServersEnsureConfigFile(string $path): void
{
    if (is_file($path)) {
        return;
    }
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return;
    }
    if (@file_put_contents($path, "[]\n", LOCK_EX) !== false) {
        @chmod($path, 0640);
    }
}

function managedServersLoad(): array
{
    $path = managedServersConfigPath();
    if (!is_file($path) || !is_readable($path)) {
        managedServersEnsureConfigFile($path);
        return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return [];
    }

    return managedServersNormalize($decoded);
}

function managedServersSave(array $servers): bool
{
    $path = managedServersConfigPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return false;
    }
    $normalized = managedServersNormalize($servers);
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $temporaryPath = $directory . '/servers.json.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        return false;
    }
    @chmod($temporaryPath, 0640);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }
    @chmod($path, 0640);

    return true;
}

function managedServersFind(array $servers, string $serverId): ?array
{
    foreach ($servers as $server) {
        if ((string)($server['id'] ?? '') === $serverId) {
            return $server;
        }
    }

    return null;
}

function managedServersHostValid(string $host): bool
{
    if ($host === '' || strlen($host) > 253 || devConsoleHasControlCharacters($host) || preg_match('/\s/', $host) === 1) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }

    return devConsoleIsHostname($host);
}

function managedServersUsernameValid(string $user): bool
{
    return $user !== '' && strlen($user) <= 64 && preg_match('/^[a-z_][a-z0-9_-]*$/i', $user) === 1;
}

function managedServersSshExecutable(): string
{
    return serverToolsFindExecutable('ssh', serverToolsDefaultPath());
}

function managedServersKeyPermissionsValid(string $path): bool
{
    $permissions = @fileperms($path);
    if ($permissions === false) {
        return true;
    }

    return ($permissions & 0077) === 0;
}

function managedServersKeyFingerprint(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $sshKeygen = serverToolsFindExecutable('ssh-keygen', serverToolsDefaultPath());
    if ($sshKeygen === '') {
        return '';
    }
    $result = processRunCommand([$sshKeygen, '-lf', $path, '-E', 'sha256'], [
        'timeout' => 5,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    if (empty($result['success']) || preg_match('/\b(SHA256:[^\s]+)/', (string)$result['stdout'], $matches) !== 1) {
        return '';
    }

    return (string)$matches[1];
}

function managedServersShellQuote(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function managedServersCurrentHome(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && is_scalar($info['dir'] ?? null) && (string)$info['dir'] !== '') {
            return (string)$info['dir'];
        }
    }

    $home = getenv('HOME');
    return is_string($home) ? $home : '';
}

function managedServersSharedKeyPath(): string
{
    $home = managedServersCurrentHome();

    return $home === '' ? '' : rtrim($home, '/') . '/.ssh/dev_console_server';
}

function managedServersSharedPublicKeyPath(): string
{
    $keyPath = managedServersSharedKeyPath();

    return $keyPath === '' ? '' : $keyPath . '.pub';
}

function managedServersReadPublicKey(string $privateKeyPath): string
{
    $publicKeyPath = $privateKeyPath . '.pub';
    if (is_file($publicKeyPath) && is_readable($publicKeyPath)) {
        return trim((string)@file_get_contents($publicKeyPath));
    }

    if (!is_file($privateKeyPath) || !is_readable($privateKeyPath)) {
        return '';
    }
    $sshKeygen = serverToolsFindExecutable('ssh-keygen', serverToolsDefaultPath());
    if ($sshKeygen === '') {
        return '';
    }
    $result = processRunCommand([$sshKeygen, '-y', '-f', $privateKeyPath], [
        'timeout' => 5,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    $publicKey = trim((string)($result['stdout'] ?? ''));
    if (empty($result['success']) || $publicKey === '') {
        return '';
    }
    if (@file_put_contents($publicKeyPath, $publicKey . "\n", LOCK_EX) !== false) {
        @chmod($publicKeyPath, 0644);
    }

    return $publicKey;
}

function managedServersSetupCommand(string $publicKey, string $user = ''): string
{
    $user = trim($user);
    if ($publicKey === '' || $user === '' || !managedServersUsernameValid($user)) {
        return '';
    }
    $quotedKey = managedServersShellQuote($publicKey);
    $quotedUser = managedServersShellQuote($user);
    $sudoersPath = '/etc/sudoers.d/dev-console-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $user);

    $lines = [
        'set -eu',
        'DEV_CONSOLE_EXPECTED_USER=' . $quotedUser,
        'DEV_CONSOLE_USER="$(id -un)"',
        'DEV_CONSOLE_GROUP="$(id -gn)"',
        'if [ "$DEV_CONSOLE_USER" != "$DEV_CONSOLE_EXPECTED_USER" ]; then printf "%s\n" "Run this command as $DEV_CONSOLE_EXPECTED_USER, not $DEV_CONSOLE_USER." >&2; exit 1; fi',
        'case "$DEV_CONSOLE_USER" in ""|[0-9]*|*[!A-Za-z0-9_-]*) printf "%s\n" "Unsafe Linux username: $DEV_CONSOLE_USER" >&2; exit 1 ;; esac',
        'mkdir -p "$HOME/.ssh"',
        'chmod 700 "$HOME/.ssh"',
        'touch "$HOME/.ssh/authorized_keys"',
        'chmod 600 "$HOME/.ssh/authorized_keys"',
        'grep -qxF ' . $quotedKey . ' "$HOME/.ssh/authorized_keys" || printf ' . managedServersShellQuote('%s\n') . ' ' . $quotedKey . ' >> "$HOME/.ssh/authorized_keys"',
        'chmod 600 "$HOME/.ssh/authorized_keys"',
    ];
    if ($user === 'root') {
        $lines[] = 'install -d -m 0755 /var/www/projects';
    } else {
        $lines[] = 'sudoers_tmp="$(mktemp)"';
        $lines[] = 'trap ' . managedServersShellQuote('rm -f "$sudoers_tmp"') . ' EXIT';
        $lines[] = 'printf ' . managedServersShellQuote('%s ALL=(ALL) NOPASSWD: ALL\n') . ' "$DEV_CONSOLE_USER" > "$sudoers_tmp"';
        $lines[] = 'sudo visudo -cf "$sudoers_tmp"';
        $lines[] = 'sudo install -m 0440 -o root -g root "$sudoers_tmp" ' . managedServersShellQuote($sudoersPath);
        $lines[] = 'sudo visudo -cf ' . managedServersShellQuote($sudoersPath);
        $lines[] = 'sudo install -d -m 0755 -o "$DEV_CONSOLE_USER" -g "$DEV_CONSOLE_GROUP" /var/www/projects';
    }

    return "(\n  " . implode("\n  ", $lines) . "\n)";
}

function managedServersSharedKeyInfo(): array
{
    $privateKeyPath = managedServersSharedKeyPath();
    $publicKeyPath = managedServersSharedPublicKeyPath();
    $privateExists = $privateKeyPath !== '' && is_file($privateKeyPath);
    $publicKey = $privateExists ? managedServersReadPublicKey($privateKeyPath) : '';
    $fingerprint = $privateExists ? managedServersKeyFingerprint($privateKeyPath) : '';

    return [
        'path' => $privateKeyPath,
        'public_path' => $publicKeyPath,
        'generated' => $privateExists && $fingerprint !== '' && $publicKey !== '',
        'private_exists' => $privateExists,
        'public_exists' => $publicKeyPath !== '' && is_file($publicKeyPath),
        'fingerprint' => $fingerprint,
        'public_key' => $publicKey,
        'setup_command' => managedServersSetupCommand($publicKey),
    ];
}

function managedServersGenerateSharedKey(): array
{
    $keyPath = managedServersSharedKeyPath();
    if ($keyPath === '') {
        return ['success' => false, 'message' => 'Unable to resolve the Dev Console service user home directory.', 'output' => ''];
    }
    if (file_exists($keyPath)) {
        $info = managedServersSharedKeyInfo();
        return [
            'success' => !empty($info['generated']),
            'message' => !empty($info['generated']) ? 'Server SSH Key already exists.' : 'Server SSH Key exists but is not usable.',
            'output' => '',
            'key' => $info,
        ];
    }
    $sshKeygen = serverToolsFindExecutable('ssh-keygen', serverToolsDefaultPath());
    if ($sshKeygen === '') {
        return ['success' => false, 'message' => 'ssh-keygen is not installed.', 'output' => ''];
    }
    $sshDirectory = dirname($keyPath);
    if (!is_dir($sshDirectory) && !@mkdir($sshDirectory, 0700, true) && !is_dir($sshDirectory)) {
        return ['success' => false, 'message' => 'Unable to create the service user .ssh directory.', 'output' => ''];
    }
    @chmod($sshDirectory, 0700);
    $commentHost = gethostname();
    $comment = 'dev-console@' . (is_string($commentHost) && $commentHost !== '' ? $commentHost : 'server');
    $result = processRunCommand([$sshKeygen, '-t', 'ed25519', '-N', '', '-f', $keyPath, '-C', $comment], [
        'timeout' => 15,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    @chmod($keyPath, 0600);
    @chmod($keyPath . '.pub', 0644);
    $info = managedServersSharedKeyInfo();

    return [
        'success' => !empty($result['success']) && !empty($info['generated']),
        'message' => !empty($result['success']) && !empty($info['generated']) ? 'Server SSH Key generated.' : 'Server SSH Key generation failed.',
        'output' => trim((string)($result['output'] ?? '')),
        'key' => $info,
    ];
}

function managedServersBuildFromInput(array $input, array $existingServers, string $existingId = ''): array
{
    $server = managedServersEmptyServer();
    $existingServer = $existingId === '' ? null : managedServersFind($existingServers, $existingId);
    $useSharedKey = devConsoleScalarInput($input, 'use_shared_server_key') === '1';
    $server['id'] = managedServersNormalizeId(devConsoleScalarInput($input, 'server_id'));
    $server['name'] = devConsoleScalarInput($input, 'server_name');
    $server['host'] = devConsoleScalarInput($input, 'server_host');
    $server['user'] = devConsoleScalarInput($input, 'server_user');
    $server['key'] = $existingServer !== null && !$useSharedKey ? (string)($existingServer['key'] ?? '') : managedServersSharedKeyPath();
    $server['key_fingerprint'] = managedServersKeyFingerprint($server['key']);
    $server['description'] = array_key_exists('server_description', $input)
        ? devConsoleScalarInput($input, 'server_description')
        : (string)($existingServer['description'] ?? '');
    $server['auth_method'] = 'ssh_key';
    $portValue = devConsoleScalarInput($input, 'server_port');
    $server['port'] = ctype_digit($portValue) ? (int)$portValue : 0;

    $errors = [];
    if ($server['id'] === '' || strlen($server['id']) > 80) {
        $errors[] = 'Server ID is required and must contain letters, numbers, or hyphens.';
    }
    if ($server['name'] === '' || strlen($server['name']) > 120 || devConsoleHasControlCharacters($server['name'])) {
        $errors[] = 'Display name is required and must not contain control characters.';
    }
    if (!managedServersHostValid($server['host'])) {
        $errors[] = 'Hostname or IP address is invalid.';
    }
    if ($server['port'] < 1 || $server['port'] > 65535) {
        $errors[] = 'SSH port must be between 1 and 65535.';
    }
    if (!managedServersUsernameValid($server['user'])) {
        $errors[] = 'SSH username is required and must be a valid Linux username.';
    }
    if (!devConsoleIsAbsoluteUnixPath($server['key'])) {
        $errors[] = 'SSH private key path is required and must be an absolute Unix path.';
    } elseif (!is_file($server['key']) || !is_readable($server['key'])) {
        $errors[] = 'SSH private key file does not exist or is not readable.';
    } elseif (!managedServersKeyPermissionsValid($server['key'])) {
        $errors[] = 'SSH private key permissions are too open.';
    } elseif ($server['key_fingerprint'] === '') {
        $errors[] = 'SSH key fingerprint could not be calculated.';
    }
    if (strlen($server['description']) > 500 || devConsoleHasControlCharacters($server['description'])) {
        $errors[] = 'Description must not contain control characters and must be 500 characters or fewer.';
    }
    foreach ($existingServers as $existing) {
        $id = (string)($existing['id'] ?? '');
        if ($id === $server['id'] && $id !== $existingId) {
            $errors[] = 'A managed server with this ID already exists.';
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'server' => $server,
    ];
}

function managedServersUpsert(array $servers, array $server, string $existingId = ''): array
{
    $updated = [];
    $replaced = false;
    $diagnosticFields = [
        'status',
        'last_connection_test_at',
        'response_time_ms',
        'remote_hostname',
        'remote_os',
        'remote_kernel',
        'remote_working_directory',
        'remote_user',
        'passwordless_sudo',
        'php_installed',
        'php_version',
        'php_path',
        'node_installed',
        'node_version',
        'node_path',
        'npm_installed',
        'npm_version',
        'npm_path',
        'composer_installed',
        'composer_version',
        'composer_path',
        'apache',
        'apache_sites',
        'last_error',
    ];
    $connectionFields = ['host', 'port', 'user', 'key', 'key_fingerprint'];
    foreach ($servers as $existing) {
        if ((string)($existing['id'] ?? '') === $existingId || (string)($existing['id'] ?? '') === (string)$server['id']) {
            if (!$replaced) {
                $connectionChanged = false;
                foreach ($connectionFields as $field) {
                    if ((string)($existing[$field] ?? '') !== (string)($server[$field] ?? '')) {
                        $connectionChanged = true;
                        break;
                    }
                }
                if (!$connectionChanged) {
                    foreach ($diagnosticFields as $field) {
                        $server[$field] = $existing[$field] ?? $server[$field];
                    }
                } else {
                    $server['status'] = 'never_tested';
                    $server['last_connection_test_at'] = null;
                    $server['response_time_ms'] = null;
                    $server['remote_hostname'] = '';
                    $server['remote_os'] = '';
                    $server['remote_kernel'] = '';
                    $server['remote_working_directory'] = '';
                    $server['remote_user'] = '';
                    $server['passwordless_sudo'] = 'unknown';
                    $server['php_installed'] = false;
                    $server['php_version'] = '';
                    $server['php_path'] = '';
                    $server['node_installed'] = false;
                    $server['node_version'] = '';
                    $server['node_path'] = '';
                    $server['npm_installed'] = false;
                    $server['npm_version'] = '';
                    $server['npm_path'] = '';
                    $server['composer_installed'] = false;
                    $server['composer_version'] = '';
                    $server['composer_path'] = '';
                    $server['apache'] = managedServersEmptyServer()['apache'];
                    $server['apache_sites'] = [];
                    $server['last_error'] = '';
                }
                $replaced = true;
                $updated[] = $server;
            }
            continue;
        }
        $updated[] = $existing;
    }
    if (!$replaced) {
        $updated[] = $server;
    }

    return $updated;
}

function managedServersRemove(array $servers, string $serverId): array
{
    return array_values(array_filter($servers, static fn(array $server): bool => (string)($server['id'] ?? '') !== $serverId));
}

function managedServersStatusLabel(array $server): string
{
    return match ((string)($server['status'] ?? 'never_tested')) {
        'reachable' => 'Reachable',
        'unreachable' => 'Unreachable',
        default => 'Never Tested',
    };
}

function managedServersStatusClass(array $server): string
{
    return match ((string)($server['status'] ?? 'never_tested')) {
        'reachable' => 'healthy',
        'unreachable' => 'error',
        default => 'warning',
    };
}

function managedServersUpdateConnectionResult(string $serverId, array $result): void
{
    $servers = managedServersLoad();
    foreach ($servers as $index => $server) {
        if ((string)($server['id'] ?? '') !== $serverId) {
            continue;
        }
        $success = !empty($result['success']);
        $servers[$index]['status'] = $success ? 'reachable' : 'unreachable';
        $servers[$index]['last_connection_test_at'] = date('c');
        $servers[$index]['response_time_ms'] = isset($result['round_trip_ms']) ? (int)$result['round_trip_ms'] : null;
        $servers[$index]['remote_hostname'] = $success ? (string)($result['hostname'] ?? '') : '';
        $servers[$index]['remote_os'] = $success ? (string)($result['os'] ?? '') : '';
        $servers[$index]['remote_kernel'] = $success ? (string)($result['kernel'] ?? '') : '';
        $servers[$index]['remote_working_directory'] = $success ? (string)($result['working_directory'] ?? '') : '';
        $servers[$index]['remote_user'] = $success ? (string)($result['remote_user'] ?? '') : '';
        $servers[$index]['passwordless_sudo'] = $success ? (string)($result['passwordless_sudo'] ?? 'unknown') : 'unknown';
        $servers[$index]['php_installed'] = $success && !empty($result['php_installed']);
        $servers[$index]['php_version'] = $success ? (string)($result['php_version'] ?? '') : '';
        $servers[$index]['php_path'] = $success ? (string)($result['php_path'] ?? '') : '';
        $servers[$index]['node_installed'] = $success && !empty($result['node_installed']);
        $servers[$index]['node_version'] = $success ? (string)($result['node_version'] ?? '') : '';
        $servers[$index]['node_path'] = $success ? (string)($result['node_path'] ?? '') : '';
        $servers[$index]['npm_installed'] = $success && !empty($result['npm_installed']);
        $servers[$index]['npm_version'] = $success ? (string)($result['npm_version'] ?? '') : '';
        $servers[$index]['npm_path'] = $success ? (string)($result['npm_path'] ?? '') : '';
        $servers[$index]['composer_installed'] = $success && !empty($result['composer_installed']);
        $servers[$index]['composer_version'] = $success ? (string)($result['composer_version'] ?? '') : '';
        $servers[$index]['composer_path'] = $success ? (string)($result['composer_path'] ?? '') : '';
        $servers[$index]['apache'] = $success && is_array($result['apache'] ?? null)
            ? array_merge(managedServersEmptyServer()['apache'], $result['apache'])
            : managedServersEmptyServer()['apache'];
        $servers[$index]['apache_sites'] = $success && is_array($result['apache_sites'] ?? null)
            ? $result['apache_sites']
            : [];
        $servers[$index]['last_error'] = $success ? '' : (string)($result['message'] ?? 'SSH connection failed');
        $servers[$index]['key_fingerprint'] = managedServersKeyFingerprint((string)($server['key'] ?? ''));
        managedServersSave($servers);
        return;
    }
}

function managedServerOperationValidateId(string $operationId): bool
{
    return preg_match('/^mso_[a-f0-9]{32}$/', $operationId) === 1;
}

function managedServerOperationPath(string $operationId, string $extension): string
{
    if (!managedServerOperationValidateId($operationId) || !in_array($extension, ['json', 'log'], true)) {
        throw new RuntimeException('Invalid managed server operation ID.');
    }
    $directory = managedServersRuntimeDirectory();
    $path = $directory . '/' . $operationId . '.' . $extension;
    $realDirectory = realpath($directory);
    $realParent = realpath(dirname($path)) ?: $directory;
    if ($realDirectory === false || $realParent !== $realDirectory) {
        throw new RuntimeException('Invalid managed server operation path.');
    }

    return $path;
}

function managedServerOperationWrite(array $state): void
{
    $path = managedServerOperationPath((string)($state['id'] ?? ''), 'json');
    $state['updated_at'] = date('c');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to write managed server operation state.');
    }
    $directory = dirname($path);
    $temporaryPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
    $handle = @fopen($temporaryPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write managed server operation state.');
    }
    try {
        if (@fwrite($handle, $json . "\n") === false || !@fflush($handle)) {
            throw new RuntimeException('Unable to write managed server operation state.');
        }
        if (!@fclose($handle)) {
            $handle = null;
            throw new RuntimeException('Unable to write managed server operation state.');
        }
        $handle = null;
        @chmod($temporaryPath, 0600);
        if (!@rename($temporaryPath, $path)) {
            throw new RuntimeException('Unable to write managed server operation state.');
        }
    } finally {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        if (is_file($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}

function managedServerOperationRead(string $operationId): array
{
    $path = managedServerOperationPath($operationId, 'json');
    if (!is_file($path)) {
        throw new RuntimeException('Managed server operation state file is missing.');
    }
    if (!is_readable($path)) {
        throw new RuntimeException('Managed server operation state file is unreadable.');
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read managed server operation state.');
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Managed server operation state is invalid JSON.');
    }

    return $decoded;
}

function managedServerOperationAppendLog(string $operationId, string $message): void
{
    @file_put_contents(managedServerOperationPath($operationId, 'log'), rtrim($message) . "\n", FILE_APPEND | LOCK_EX);
}

function managedServerOperationLog(string $operationId): string
{
    $path = managedServerOperationPath($operationId, 'log');

    return is_file($path) ? (string)@file_get_contents($path) : '';
}

function managedServerConnectionResultMessage(string $output): string
{
    $lower = strtolower($output);
    if (str_contains($lower, 'permission denied') || str_contains($lower, 'publickey')) {
        return 'Authentication failed';
    }
    if (str_contains($lower, 'timed out') || str_contains($lower, 'operation timed out')) {
        return 'Connection timeout';
    }
    if (str_contains($lower, 'no route to host') || str_contains($lower, 'could not resolve') || str_contains($lower, 'connection refused')) {
        return 'Host unreachable';
    }
    if (str_contains($lower, 'identity file') || str_contains($lower, 'no such file')) {
        return 'Key file missing';
    }

    return 'SSH connection failed';
}

function managedServerParseConnectionOutput(string $stdout): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $stdout) ?: []), static fn(string $line): bool => $line !== ''));
    if (($lines[0] ?? '') === '__DEV_CONSOLE_CONNECTED__' || ($lines[0] ?? '') === 'connected') {
        array_shift($lines);
    }
    $passwordlessSudo = 'unknown';
    $runtime = [
        'php_installed' => false,
        'php_path' => '',
        'php_version' => '',
        'node_installed' => false,
        'node_path' => '',
        'node_version' => '',
        'npm_installed' => false,
        'npm_path' => '',
        'npm_version' => '',
        'composer_installed' => false,
        'composer_path' => '',
        'composer_version' => '',
    ];
    $apache = [
        'installed' => false,
        'running' => null,
        'enabled' => null,
        'version' => '',
        'binary_path' => '',
        'diagnostic_error' => '',
    ];
    $apacheSites = [];
    $filtered = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '__DEV_CONSOLE_SUDO__=')) {
            $value = substr($line, strlen('__DEV_CONSOLE_SUDO__='));
            $passwordlessSudo = in_array($value, ['ready', 'setup_required', 'root'], true) ? $value : 'unknown';
            continue;
        }
        foreach ([
            '__DEV_CONSOLE_PHP_PATH__=' => 'php_path',
            '__DEV_CONSOLE_PHP_VERSION__=' => 'php_version',
            '__DEV_CONSOLE_NODE_PATH__=' => 'node_path',
            '__DEV_CONSOLE_NODE_VERSION__=' => 'node_version',
            '__DEV_CONSOLE_NPM_PATH__=' => 'npm_path',
            '__DEV_CONSOLE_NPM_VERSION__=' => 'npm_version',
            '__DEV_CONSOLE_COMPOSER_PATH__=' => 'composer_path',
            '__DEV_CONSOLE_COMPOSER_VERSION__=' => 'composer_version',
        ] as $prefix => $field) {
            if (str_starts_with($line, $prefix)) {
                $runtime[$field] = substr($line, strlen($prefix));
                continue 2;
            }
        }
        foreach ([
            '__DEV_CONSOLE_APACHE_VERSION__=' => 'version',
            '__DEV_CONSOLE_APACHE_PATH__=' => 'binary_path',
            '__DEV_CONSOLE_APACHE_ERROR__=' => 'diagnostic_error',
        ] as $prefix => $field) {
            if (str_starts_with($line, $prefix)) {
                $apache[$field] = substr($line, strlen($prefix));
                continue 2;
            }
        }
        foreach ([
            '__DEV_CONSOLE_APACHE_INSTALLED__=' => 'installed',
            '__DEV_CONSOLE_APACHE_RUNNING__=' => 'running',
            '__DEV_CONSOLE_APACHE_ENABLED__=' => 'enabled',
        ] as $prefix => $field) {
            if (str_starts_with($line, $prefix)) {
                $value = substr($line, strlen($prefix));
                $apache[$field] = $value === 'unknown' ? null : $value === '1';
                continue 2;
            }
        }
        if (str_starts_with($line, '__DEV_CONSOLE_APACHE_SITE__=')) {
            $value = substr($line, strlen('__DEV_CONSOLE_APACHE_SITE__='));
            $parts = array_pad(explode("\t", $value), 7, '');
            $projectEnvironment = explode('|', (string)($parts[6] ?? ''), 2);
            $enabledValue = (string)($parts[2] ?? '');
            $site = [
                'name' => (string)($parts[0] ?? ''),
                'path' => (string)($parts[1] ?? ''),
                'enabled' => in_array($enabledValue, ['0', '1'], true) ? $enabledValue === '1' : null,
                'server_name' => (string)($parts[3] ?? ''),
                'document_root' => (string)($parts[4] ?? ''),
                'managed_marker' => (string)($parts[5] ?? '') === '1',
                'project_id' => (string)($projectEnvironment[0] ?? ''),
                'environment' => (string)($projectEnvironment[1] ?? ''),
            ];
            if ($site['name'] !== '') {
                $apacheSites[] = $site;
            }
            continue;
        }
        $filtered[] = $line;
    }
    $lines = $filtered;
    $runtime['php_installed'] = $runtime['php_path'] !== '';
    $runtime['node_installed'] = $runtime['node_path'] !== '';
    $runtime['npm_installed'] = $runtime['npm_path'] !== '';
    $runtime['composer_installed'] = $runtime['composer_path'] !== '';

    return array_merge([
        'hostname' => (string)($lines[0] ?? ''),
        'uname' => (string)($lines[1] ?? ''),
        'kernel' => (string)($lines[1] ?? ''),
        'working_directory' => (string)($lines[2] ?? ''),
        'remote_user' => (string)($lines[3] ?? ''),
        'os' => (string)($lines[4] ?? ''),
        'passwordless_sudo' => $passwordlessSudo,
        'apache' => $apache,
        'apache_sites' => $apacheSites,
    ], $runtime);
}

function managedServerStartConnectionTest(array $servers, string $serverId): array
{
    return managedServerStartOperation($servers, $serverId, 'connection_test');
}

function managedServerStartComposerInstall(array $servers, string $serverId): array
{
    return managedServerStartOperation($servers, $serverId, 'install_composer');
}

function managedServerStartApacheInstall(array $servers, string $serverId): array
{
    return managedServerStartOperation($servers, $serverId, 'install_apache');
}

function managedServerStartOperation(array $servers, string $serverId, string $operationAction): array
{
    $server = managedServersFind($servers, $serverId);
    if ($server === null) {
        throw new RuntimeException('Managed server not found.');
    }
    if (!in_array($operationAction, ['connection_test', 'install_composer', 'install_apache'], true)) {
        throw new RuntimeException('Unsupported managed server operation.');
    }
    if ($operationAction === 'install_composer' && (string)($server['status'] ?? '') !== 'reachable') {
        throw new RuntimeException('Test the managed server connection successfully before installing Composer.');
    }
    if ($operationAction === 'install_apache' && (string)($server['status'] ?? '') !== 'reachable') {
        throw new RuntimeException('Test the managed server connection successfully before installing Apache.');
    }
    $operationId = 'mso_' . bin2hex(random_bytes(16));
    $message = match ($operationAction) {
        'install_composer' => 'Installing Composer.',
        'install_apache' => 'Installing Apache.',
        default => 'Testing SSH connection.',
    };
    $state = [
        'id' => $operationId,
        'operation_action' => $operationAction,
        'server_id' => $serverId,
        'server_name' => (string)$server['name'],
        'status' => 'running',
        'stage' => 'Starting',
        'started_at' => date('c'),
        'updated_at' => date('c'),
        'finished_at' => '',
        'message' => $message,
        'result' => null,
    ];
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] ' . $message . ' Server: ' . (string)$server['name'] . '.');

    $worker = __DIR__ . '/run-managed-server.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($operationId) . ' >/dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($command));
    if ($pid <= 0) {
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['finished_at'] = date('c');
        $state['message'] = 'Unable to start managed server worker.';
        managedServerOperationWrite($state);
        throw new RuntimeException('Unable to start managed server worker.');
    }
    $latestState = managedServerOperationRead($operationId);
    $latestState['pid'] = $pid;
    managedServerOperationWrite($latestState);

    return managedServerOperationRead($operationId);
}

function managedServerRunConnectionTestById(string $operationId): void
{
    managedServerRunOperationById($operationId);
}

function managedServerDiagnosticCommand(): string
{
    return 'printf "__DEV_CONSOLE_CONNECTED__\n"; hostname; uname -a; pwd; whoami; '
        . 'if [ -r /etc/os-release ]; then . /etc/os-release; printf "%s\n" "${PRETTY_NAME:-${NAME:-}}"; fi; '
        . 'if [ "$(id -u)" = 0 ]; then printf "__DEV_CONSOLE_SUDO__=root\n"; elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then printf "__DEV_CONSOLE_SUDO__=ready\n"; else printf "__DEV_CONSOLE_SUDO__=setup_required\n"; fi; '
        . 'if php_path="$(command -v php 2>/dev/null)"; then printf "__DEV_CONSOLE_PHP_PATH__=%s\n" "$php_path"; php_version="$(php -r ' . managedServersShellQuote('echo PHP_VERSION;') . ' 2>/dev/null || php -v 2>/dev/null | head -n 1 || true)"; printf "__DEV_CONSOLE_PHP_VERSION__=%s\n" "$php_version"; fi; '
        . 'if composer_path="$(command -v composer 2>/dev/null)"; then printf "__DEV_CONSOLE_COMPOSER_PATH__=%s\n" "$composer_path"; composer_version="$(composer --version --no-interaction 2>/dev/null | head -n 1 || true)"; printf "__DEV_CONSOLE_COMPOSER_VERSION__=%s\n" "$composer_version"; fi; '
        . 'if node_path="$(command -v node 2>/dev/null)"; then printf "__DEV_CONSOLE_NODE_PATH__=%s\n" "$node_path"; node_version="$(node --version 2>/dev/null | head -n 1 || true)"; printf "__DEV_CONSOLE_NODE_VERSION__=%s\n" "$node_version"; fi; '
        . 'if npm_path="$(command -v npm 2>/dev/null)"; then printf "__DEV_CONSOLE_NPM_PATH__=%s\n" "$npm_path"; npm_version="$(npm --version 2>/dev/null | head -n 1 || true)"; printf "__DEV_CONSOLE_NPM_VERSION__=%s\n" "$npm_version"; fi; '
        . 'apache_path="$(command -v apache2 2>/dev/null || command -v httpd 2>/dev/null || true)"; '
        . 'if [ -n "$apache_path" ]; then printf "__DEV_CONSOLE_APACHE_INSTALLED__=1\n"; printf "__DEV_CONSOLE_APACHE_PATH__=%s\n" "$apache_path"; apache_version="$("$apache_path" -v 2>/dev/null | sed -n "s/^Server version:[[:space:]]*//p" | head -n 1 || true)"; printf "__DEV_CONSOLE_APACHE_VERSION__=%s\n" "$apache_version"; '
        . 'if command -v systemctl >/dev/null 2>&1; then if systemctl is-active --quiet apache2 2>/dev/null || systemctl is-active --quiet httpd 2>/dev/null; then printf "__DEV_CONSOLE_APACHE_RUNNING__=1\n"; else printf "__DEV_CONSOLE_APACHE_RUNNING__=0\n"; fi; if systemctl is-enabled --quiet apache2 2>/dev/null || systemctl is-enabled --quiet httpd 2>/dev/null; then printf "__DEV_CONSOLE_APACHE_ENABLED__=1\n"; else printf "__DEV_CONSOLE_APACHE_ENABLED__=0\n"; fi; else printf "__DEV_CONSOLE_APACHE_RUNNING__=unknown\n"; printf "__DEV_CONSOLE_APACHE_ENABLED__=unknown\n"; fi; '
        . 'else printf "__DEV_CONSOLE_APACHE_INSTALLED__=0\n"; fi; '
        . 'if [ -d /etc/apache2/sites-available ]; then for conf in /etc/apache2/sites-available/*.conf; do [ -f "$conf" ] || continue; name="$(basename "$conf")"; enabled=0; [ -e "/etc/apache2/sites-enabled/$name" ] && enabled=1; server_name="$(sed -n "s/^[[:space:]]*ServerName[[:space:]]\{1,\}//Ip" "$conf" | head -n 1 | tr "\t" " ")"; document_root="$(sed -n "s/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}//Ip" "$conf" | head -n 1 | tr "\t" " ")"; managed=0; grep -F ' . managedServersShellQuote(defined('DEV_CONSOLE_MANAGED_MARKER') ? DEV_CONSOLE_MANAGED_MARKER : '# Managed by IOVON Dev Console') . ' "$conf" >/dev/null 2>&1 && managed=1; project_id="$(sed -n "s/^#[[:space:]]*Project ID:[[:space:]]*//p;s/^#[[:space:]]*Project:[[:space:]]*//p" "$conf" | head -n 1 | tr "\t" " ")"; environment="$(sed -n "s/^#[[:space:]]*Environment:[[:space:]]*//p" "$conf" | head -n 1 | tr "\t" " ")"; printf "__DEV_CONSOLE_APACHE_SITE__=%s\t%s\t%s\t%s\t%s\t%s\t%s\n" "$name" "$conf" "$enabled" "$server_name" "$document_root" "$managed" "$project_id|$environment"; done; fi';
}

function managedServerRunOperationById(string $operationId): void
{
    $state = managedServerOperationRead($operationId);
    $serverId = (string)($state['server_id'] ?? '');
    $server = managedServersFind(managedServersLoad(), $serverId);
    if ($server === null) {
        throw new RuntimeException('Managed server not found.');
    }

    $operationAction = (string)($state['operation_action'] ?? 'connection_test');
    if ($operationAction === 'install_composer') {
        managedServerRunComposerInstall($operationId, $server);
        return;
    }
    if ($operationAction === 'install_apache') {
        managedServerRunApacheInstall($operationId, $server);
        return;
    }
    if ($operationAction === 'connection_test') {
        managedServerRunConnectionTest($operationId, $server);
        return;
    }

    throw new RuntimeException('Unsupported managed server operation.');
}

function managedServerRunConnectionTest(string $operationId, array $server): void
{
    $started = microtime(true);
    $state = managedServerOperationRead($operationId);
    $state['stage'] = 'Connecting';
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Connecting to ' . (string)$server['host'] . '.');
    $ssh = managedServersSshExecutable();
    if ($ssh === '') {
        $roundTripMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'success' => false,
            'message' => 'SSH executable missing',
            'round_trip_ms' => $roundTripMs,
            'output' => managedServerOperationLog($operationId),
        ];
        managedServersUpdateConnectionResult((string)$server['id'], $result);
        $state['finished_at'] = date('c');
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['message'] = 'SSH executable missing';
        $state['result'] = $result;
        managedServerOperationWrite($state);
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Error: SSH executable missing.');
        return;
    }
    if (!is_file((string)$server['key']) || !is_readable((string)$server['key'])) {
        $roundTripMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'success' => false,
            'message' => 'Key file missing',
            'round_trip_ms' => $roundTripMs,
            'output' => managedServerOperationLog($operationId),
        ];
        managedServersUpdateConnectionResult((string)$server['id'], $result);
        $state['finished_at'] = date('c');
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['message'] = 'Key file missing';
        $state['result'] = $result;
        managedServerOperationWrite($state);
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Error: Key file missing.');
        return;
    }
    if (!managedServersKeyPermissionsValid((string)$server['key'])) {
        $roundTripMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'success' => false,
            'message' => 'Invalid key permissions',
            'round_trip_ms' => $roundTripMs,
            'output' => managedServerOperationLog($operationId),
        ];
        managedServersUpdateConnectionResult((string)$server['id'], $result);
        $state['finished_at'] = date('c');
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['message'] = 'Invalid key permissions';
        $state['result'] = $result;
        managedServerOperationWrite($state);
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Error: Invalid key permissions.');
        return;
    }
    $target = (string)$server['user'] . '@' . (string)$server['host'];
    $arguments = [
        $ssh,
        '-i', (string)$server['key'],
        '-p', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=8',
        '-o', 'StrictHostKeyChecking=accept-new',
        $target,
        managedServerDiagnosticCommand(),
    ];
    managedServerOperationAppendLog($operationId, '$ ssh [managed-server-options] ' . $target . ' [diagnostic-command]');
    $result = processRunCommand($arguments, [
        'timeout' => 15,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    $roundTripMs = (int)round((microtime(true) - $started) * 1000);
    if ((string)$result['output'] !== '') {
        managedServerOperationAppendLog($operationId, (string)$result['output']);
    }
    managedServerOperationAppendLog($operationId, 'Exit code: ' . (string)$result['exit_code']);

    $connected = !empty($result['success']) && (int)$result['exit_code'] === 0;
    $parsed = managedServerParseConnectionOutput($connected ? (string)$result['stdout'] : '');
    if ($connected) {
        managedServerOperationAppendLog($operationId, 'SSH access: Ready');
        $sudoLabel = match ((string)$parsed['passwordless_sudo']) {
            'ready', 'root' => 'Ready',
            'setup_required' => 'Setup required',
            default => 'Unknown',
        };
        managedServerOperationAppendLog($operationId, 'Passwordless sudo: ' . $sudoLabel);
        if ((string)$parsed['passwordless_sudo'] === 'setup_required') {
            managedServerOperationAppendLog($operationId, 'Run the Managed Server setup command for this SSH user, then test the connection again.');
        }
    }
    $resultData = [
        'success' => $connected,
        'message' => $connected
            ? ((string)$parsed['passwordless_sudo'] === 'setup_required'
                ? 'Connected. Passwordless sudo is not configured for this deployment user. Run the Managed Server setup command for this SSH user, then test the connection again.'
                : 'Connected')
            : managedServerConnectionResultMessage((string)$result['output']),
        'hostname' => (string)$parsed['hostname'],
        'os' => (string)$parsed['os'],
        'kernel' => (string)$parsed['kernel'],
        'uname' => (string)$parsed['uname'],
        'working_directory' => (string)$parsed['working_directory'],
        'remote_user' => (string)$parsed['remote_user'],
        'passwordless_sudo' => (string)$parsed['passwordless_sudo'],
        'php_installed' => !empty($parsed['php_installed']),
        'php_version' => (string)($parsed['php_version'] ?? ''),
        'php_path' => (string)($parsed['php_path'] ?? ''),
        'node_installed' => !empty($parsed['node_installed']),
        'node_version' => (string)($parsed['node_version'] ?? ''),
        'node_path' => (string)($parsed['node_path'] ?? ''),
        'npm_installed' => !empty($parsed['npm_installed']),
        'npm_version' => (string)($parsed['npm_version'] ?? ''),
        'npm_path' => (string)($parsed['npm_path'] ?? ''),
        'composer_installed' => !empty($parsed['composer_installed']),
        'composer_version' => (string)($parsed['composer_version'] ?? ''),
        'composer_path' => (string)($parsed['composer_path'] ?? ''),
        'apache' => is_array($parsed['apache'] ?? null) ? $parsed['apache'] : managedServersEmptyServer()['apache'],
        'apache_sites' => is_array($parsed['apache_sites'] ?? null) ? $parsed['apache_sites'] : [],
        'round_trip_ms' => $roundTripMs,
        'output' => managedServerOperationLog($operationId),
    ];
    managedServersUpdateConnectionResult((string)$server['id'], $resultData);
    $state = managedServerOperationRead($operationId);
    $state['finished_at'] = date('c');
    $state['status'] = $connected ? 'completed' : 'failed';
    $state['stage'] = $connected ? 'Connected' : 'Failed';
    $state['message'] = (string)$resultData['message'];
    $state['result'] = $resultData;
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] ' . $state['stage'] . '.');
}

function managedServerRemoteSshArguments(array $server, string $remoteCommand): array
{
    return [
        managedServersSshExecutable(),
        '-i', (string)$server['key'],
        '-p', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=10',
        '-o', 'StrictHostKeyChecking=accept-new',
        (string)$server['user'] . '@' . (string)$server['host'],
        $remoteCommand,
    ];
}

function managedServerOperationRunRemoteCommand(string $operationId, array $server, string $remoteCommand, int $timeout = 120): array
{
    managedServerOperationAppendLog($operationId, '$ ssh [managed-server-options] ' . (string)$server['user'] . '@' . (string)$server['host'] . ' ' . managedServersShellQuote($remoteCommand));
    $result = processRunCommand(managedServerRemoteSshArguments($server, $remoteCommand), [
        'timeout' => $timeout,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    managedServerOperationAppendLog($operationId, 'Exit code: ' . (string)$result['exit_code']);
    if (trim((string)$result['output']) !== '') {
        managedServerOperationAppendLog($operationId, trim((string)$result['output']));
    }

    return $result;
}

function managedServerDashboardDiagnostics(array $server, ?array $project = null): array
{
    $empty = [
        'available' => false,
        'message' => '',
        'server' => [
            'load' => [],
            'load_percentage' => 0,
            'memory' => ['total' => 0, 'used' => 0, 'free' => 0, 'percentage' => 0],
            'disk' => ['total' => 0, 'used' => 0, 'free' => 0, 'percentage' => 0],
        ],
        'storage' => [
            'preview' => ['status' => 'not_available', 'files' => 0, 'bytes' => 0],
            'production' => ['status' => 'not_available', 'files' => 0, 'bytes' => 0],
        ],
        'processes' => [],
    ];

    $ssh = managedServersSshExecutable();
    if ($ssh === '') {
        $empty['message'] = 'SSH executable missing.';
        return $empty;
    }
    $key = (string)($server['key'] ?? '');
    if ($key === '' || !is_file($key) || !is_readable($key)) {
        $empty['message'] = 'SSH key file missing.';
        return $empty;
    }

    $previewPath = is_array($project) ? (string)($project['preview']['path'] ?? '') : '';
    $productionPath = is_array($project) ? (string)($project['production']['path'] ?? '') : '';
    $storageCommand = '';
    foreach (['preview' => $previewPath, 'production' => $productionPath] as $environment => $path) {
        if ($path === '') {
            continue;
        }
        $quotedPath = managedServersShellQuote($path);
        $quotedEnvironment = managedServersShellQuote($environment);
        $storageCommand .= 'path=' . $quotedPath . '; env_name=' . $quotedEnvironment . '; '
            . 'if [ -d "$path" ] && [ -r "$path" ]; then '
            . 'stats="$(find "$path" -type f -printf "." 2>/dev/null | wc -c | tr -d " ") $(du -sb "$path" 2>/dev/null | awk \'{print $1}\' || printf "0")"; '
            . 'set -- $stats; printf "__STORAGE__=%s\tavailable\t%s\t%s\n" "$env_name" "${1:-0}" "${2:-0}"; '
            . 'elif [ -d "$path" ]; then printf "__STORAGE__=%s\tnot_readable\t0\t0\n" "$env_name"; '
            . 'else printf "__STORAGE__=%s\tnot_deployed\t0\t0\n" "$env_name"; fi; ';
    }

    $command = 'printf "__DEV_CONSOLE_DASHBOARD__\n"; '
        . 'cpu_count="$(command -v nproc >/dev/null 2>&1 && nproc || printf "1")"; printf "__CPU_COUNT__=%s\n" "$cpu_count"; '
        . 'if [ -r /proc/loadavg ]; then read l1 l5 l15 rest < /proc/loadavg; printf "__LOAD__=%s %s %s\n" "$l1" "$l5" "$l15"; fi; '
        . 'if [ -r /proc/meminfo ]; then awk \'/^(MemTotal|MemAvailable|MemFree):/ {print "__MEM__="$1" "$2}\' /proc/meminfo; fi; '
        . 'df -P / 2>/dev/null | awk \'NR==2 {printf "__DISK__=%s %s %s\n", $2 * 1024, $3 * 1024, $4 * 1024}\'; '
        . $storageCommand
        . 'ps -eo pid=,user=,pcpu=,pmem=,comm= --sort=-pcpu 2>/dev/null | head -n 5 | while read -r pid user cpu mem command_name; do printf "__PROC__=%s\t%s\t%s\t%s\t%s\n" "$pid" "$user" "$cpu" "$mem" "$command_name"; done';

    $result = processRunCommand(managedServerRemoteSshArguments($server, $command), [
        'timeout' => 8,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    if ((int)($result['exit_code'] ?? 1) !== 0) {
        $empty['message'] = managedServerConnectionResultMessage((string)($result['stderr'] ?? '') . "\n" . (string)($result['stdout'] ?? ''));
        return $empty;
    }

    $load = [];
    $cpuCount = 1;
    $memTotal = 0;
    $memAvailable = 0;
    $disk = $empty['server']['disk'];
    $storage = $empty['storage'];
    $processes = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)($result['stdout'] ?? '')) ?: [] as $line) {
        if (str_starts_with($line, '__CPU_COUNT__=')) {
            $cpuCount = max(1, (int)substr($line, 14));
            continue;
        }
        if (str_starts_with($line, '__LOAD__=')) {
            $load = array_map(static fn(string $value): float => round((float)$value, 2), preg_split('/\s+/', trim(substr($line, 9))) ?: []);
            continue;
        }
        if (str_starts_with($line, '__MEM__=')) {
            [$field, $value] = array_pad(preg_split('/\s+/', trim(substr($line, 8))) ?: [], 2, '');
            $bytes = (int)$value * 1024;
            if ($field === 'MemTotal:') $memTotal = $bytes;
            if ($field === 'MemAvailable:') $memAvailable = $bytes;
            if ($field === 'MemFree:' && $memAvailable === 0) $memAvailable = $bytes;
            continue;
        }
        if (str_starts_with($line, '__DISK__=')) {
            [$total, $used, $free] = array_pad(preg_split('/\s+/', trim(substr($line, 9))) ?: [], 3, 0);
            $disk = [
                'total' => (int)$total,
                'used' => (int)$used,
                'free' => (int)$free,
                'percentage' => (int)$total > 0 ? round(((int)$used) / ((int)$total) * 100, 1) : 0,
            ];
            continue;
        }
        if (str_starts_with($line, '__PROC__=')) {
            [$pid, $user, $cpu, $memory, $commandName] = array_pad(explode("\t", substr($line, 9), 5), 5, '');
            if ($pid === '') continue;
            $processes[] = [
                'pid' => (int)$pid,
                'user' => $user,
                'cpu' => (float)$cpu,
                'memory' => (float)$memory,
                'command' => $commandName,
            ];
            continue;
        }
        if (str_starts_with($line, '__STORAGE__=')) {
            [$environment, $status, $files, $bytes] = array_pad(explode("\t", substr($line, 12), 4), 4, '');
            if (isset($storage[$environment])) {
                $storage[$environment] = [
                    'status' => $status === '' ? 'not_available' : $status,
                    'files' => (int)$files,
                    'bytes' => (int)$bytes,
                ];
            }
        }
    }

    $usedMemory = max(0, $memTotal - $memAvailable);
    return [
        'available' => true,
        'message' => '',
        'server' => [
            'load' => $load,
            'load_percentage' => isset($load[0]) ? round(((float)$load[0]) / $cpuCount * 100, 1) : 0,
            'memory' => [
                'total' => $memTotal,
                'used' => $usedMemory,
                'free' => $memAvailable,
                'percentage' => $memTotal > 0 ? round($usedMemory / $memTotal * 100, 1) : 0,
            ],
            'disk' => $disk,
        ],
        'storage' => $storage,
        'processes' => $processes,
    ];
}

function managedServerComposerInstallCheckCommand(): string
{
    return 'printf "__DEV_CONSOLE_SERVER_CHECK__\n"; '
        . 'if [ -r /etc/os-release ]; then . /etc/os-release; printf "__DEV_CONSOLE_OS_ID__=%s\n" "${ID:-}"; printf "__DEV_CONSOLE_OS_LIKE__=%s\n" "${ID_LIKE:-}"; fi; '
        . 'if [ "$(id -u)" = 0 ]; then printf "__DEV_CONSOLE_SUDO__=root\n"; elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then printf "__DEV_CONSOLE_SUDO__=ready\n"; else printf "__DEV_CONSOLE_SUDO__=setup_required\n"; fi; '
        . 'if composer_path="$(command -v composer 2>/dev/null)"; then printf "__DEV_CONSOLE_COMPOSER_PATH__=%s\n" "$composer_path"; composer --version --no-interaction 2>/dev/null | sed "s/^/__DEV_CONSOLE_COMPOSER_VERSION__=/"; fi';
}

function managedServerParseComposerInstallCheck(string $stdout): array
{
    $result = [
        'os_id' => '',
        'os_like' => '',
        'passwordless_sudo' => 'unknown',
        'composer_path' => '',
        'composer_version' => '',
    ];
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        $line = trim($line);
        foreach ([
            '__DEV_CONSOLE_OS_ID__=' => 'os_id',
            '__DEV_CONSOLE_OS_LIKE__=' => 'os_like',
            '__DEV_CONSOLE_SUDO__=' => 'passwordless_sudo',
            '__DEV_CONSOLE_COMPOSER_PATH__=' => 'composer_path',
            '__DEV_CONSOLE_COMPOSER_VERSION__=' => 'composer_version',
        ] as $prefix => $field) {
            if (str_starts_with($line, $prefix)) {
                $result[$field] = substr($line, strlen($prefix));
                continue 2;
            }
        }
    }

    return $result;
}

function managedServerApacheInstallCheckCommand(): string
{
    return 'printf "__DEV_CONSOLE_SERVER_CHECK__\n"; '
        . 'if [ -r /etc/os-release ]; then . /etc/os-release; printf "__DEV_CONSOLE_OS_ID__=%s\n" "${ID:-}"; printf "__DEV_CONSOLE_OS_LIKE__=%s\n" "${ID_LIKE:-}"; fi; '
        . 'if [ "$(id -u)" = 0 ]; then printf "__DEV_CONSOLE_SUDO__=root\n"; elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then printf "__DEV_CONSOLE_SUDO__=ready\n"; else printf "__DEV_CONSOLE_SUDO__=setup_required\n"; fi; '
        . 'apache_path="$(command -v apache2 2>/dev/null || command -v httpd 2>/dev/null || true)"; '
        . 'if [ -n "$apache_path" ]; then printf "__DEV_CONSOLE_APACHE_PATH__=%s\n" "$apache_path"; apache_version="$("$apache_path" -v 2>/dev/null | sed -n "s/^Server version:[[:space:]]*//p" | head -n 1 || true)"; printf "__DEV_CONSOLE_APACHE_VERSION__=%s\n" "$apache_version"; fi';
}

function managedServerParseApacheInstallCheck(string $stdout): array
{
    $result = [
        'os_id' => '',
        'os_like' => '',
        'passwordless_sudo' => 'unknown',
        'apache_path' => '',
        'apache_version' => '',
    ];
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        $line = trim($line);
        foreach ([
            '__DEV_CONSOLE_OS_ID__=' => 'os_id',
            '__DEV_CONSOLE_OS_LIKE__=' => 'os_like',
            '__DEV_CONSOLE_SUDO__=' => 'passwordless_sudo',
            '__DEV_CONSOLE_APACHE_PATH__=' => 'apache_path',
            '__DEV_CONSOLE_APACHE_VERSION__=' => 'apache_version',
        ] as $prefix => $field) {
            if (str_starts_with($line, $prefix)) {
                $result[$field] = substr($line, strlen($prefix));
                continue 2;
            }
        }
    }

    return $result;
}

function managedServerRemotePrivilegedPrefix(array $server): string
{
    return (string)($server['user'] ?? '') === 'root' ? '' : 'sudo -n ';
}

function managedServerComposerInstallCommand(array $server): string
{
    if ((string)($server['user'] ?? '') === 'root') {
        return 'apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y composer';
    }

    return 'sudo -n apt-get update && sudo -n env DEBIAN_FRONTEND=noninteractive apt-get install -y composer';
}

function managedServerComposerVerifyCommand(): string
{
    return 'command -v composer && composer --version --no-interaction';
}

function managedServerApacheInstallCommand(array $server): string
{
    if ((string)($server['user'] ?? '') === 'root') {
        return 'apt-get update && apt-get install -y apache2';
    }

    return 'sudo -n apt-get update && sudo -n apt-get install -y apache2';
}

function managedServerApacheVerifyCommand(): string
{
    return 'apache_path="$(command -v apache2 2>/dev/null || true)"; '
        . 'test -n "$apache_path" && printf "%s\n" "$apache_path" && command -v apache2ctl >/dev/null 2>&1 && apache2ctl configtest';
}

function managedServerApacheEnableStartCommand(array $server): string
{
    $prefix = managedServerRemotePrivilegedPrefix($server);
    return 'if command -v systemctl >/dev/null 2>&1; then '
        . $prefix . 'systemctl enable apache2 && ' . $prefix . 'systemctl start apache2; '
        . 'else printf "systemctl not available; skipping enable/start.\n"; fi';
}

function managedServerRefreshDiagnosticsAfterOperation(string $operationId, array $server, float $started): array
{
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Refreshing managed server diagnostics.');
    $diagnostic = managedServerOperationRunRemoteCommand($operationId, $server, managedServerDiagnosticCommand(), 30);
    if ((int)$diagnostic['exit_code'] !== 0) {
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Warning: Diagnostics refresh failed after the operation.');

        return [
            'success' => true,
            'message' => 'Operation completed, but diagnostics refresh failed.',
            'output' => managedServerOperationLog($operationId),
        ];
    }

    $parsed = managedServerParseConnectionOutput((string)$diagnostic['stdout']);
    $resultData = [
        'success' => true,
        'message' => 'Diagnostics refreshed.',
        'hostname' => (string)$parsed['hostname'],
        'os' => (string)$parsed['os'],
        'kernel' => (string)$parsed['kernel'],
        'uname' => (string)$parsed['uname'],
        'working_directory' => (string)$parsed['working_directory'],
        'remote_user' => (string)$parsed['remote_user'],
        'passwordless_sudo' => (string)$parsed['passwordless_sudo'],
        'php_installed' => !empty($parsed['php_installed']),
        'php_version' => (string)($parsed['php_version'] ?? ''),
        'php_path' => (string)($parsed['php_path'] ?? ''),
        'node_installed' => !empty($parsed['node_installed']),
        'node_version' => (string)($parsed['node_version'] ?? ''),
        'node_path' => (string)($parsed['node_path'] ?? ''),
        'npm_installed' => !empty($parsed['npm_installed']),
        'npm_version' => (string)($parsed['npm_version'] ?? ''),
        'npm_path' => (string)($parsed['npm_path'] ?? ''),
        'composer_installed' => !empty($parsed['composer_installed']),
        'composer_version' => (string)($parsed['composer_version'] ?? ''),
        'composer_path' => (string)($parsed['composer_path'] ?? ''),
        'apache' => is_array($parsed['apache'] ?? null) ? $parsed['apache'] : managedServersEmptyServer()['apache'],
        'apache_sites' => is_array($parsed['apache_sites'] ?? null) ? $parsed['apache_sites'] : [],
        'round_trip_ms' => (int)round((microtime(true) - $started) * 1000),
        'output' => managedServerOperationLog($operationId),
    ];
    managedServersUpdateConnectionResult((string)$server['id'], $resultData);

    return $resultData;
}

function managedServerRunComposerInstall(string $operationId, array $server): void
{
    $started = microtime(true);
    $state = managedServerOperationRead($operationId);
    $state['stage'] = 'Checking Server';
    $state['message'] = 'Checking SSH, sudo and Composer state.';
    managedServerOperationWrite($state);

    $ssh = managedServersSshExecutable();
    if ($ssh === '') {
        throw new RuntimeException('SSH executable missing.');
    }
    if (!is_file((string)$server['key']) || !is_readable((string)$server['key'])) {
        throw new RuntimeException('Key file missing.');
    }
    if (!managedServersKeyPermissionsValid((string)$server['key'])) {
        throw new RuntimeException('Invalid key permissions.');
    }

    $check = managedServerOperationRunRemoteCommand($operationId, $server, managedServerComposerInstallCheckCommand(), 30);
    if ($check['exit_code'] !== 0) {
        throw new RuntimeException(managedServerConnectionResultMessage((string)$check['output']));
    }
    $parsed = managedServerParseComposerInstallCheck((string)$check['stdout']);
    $sudoState = (string)$parsed['passwordless_sudo'];
    if (!in_array($sudoState, ['root', 'ready'], true)) {
        throw new RuntimeException('Passwordless sudo is not configured for this deployment user. Run the Managed Server setup command again as root.');
    }
    $osId = strtolower((string)$parsed['os_id']);
    $osLike = strtolower((string)$parsed['os_like']);
    if ($osId !== 'ubuntu' && $osId !== 'debian' && !str_contains($osLike, 'debian')) {
        throw new RuntimeException('Composer installation is supported only for Ubuntu/Debian managed servers.');
    }
    if ((string)$parsed['composer_path'] !== '') {
        $resultData = [
            'success' => true,
            'message' => 'Composer is already installed.',
            'composer_installed' => true,
            'composer_path' => (string)$parsed['composer_path'],
            'composer_version' => (string)$parsed['composer_version'],
            'output' => managedServerOperationLog($operationId),
        ];
        managedServersUpdateRuntimeDiagnostics((string)$server['id'], $resultData);
        $diagnosticData = managedServerRefreshDiagnosticsAfterOperation($operationId, $server, $started);
        $resultData = array_merge($resultData, $diagnosticData, [
            'success' => true,
            'message' => 'Composer is already installed.',
            'output' => managedServerOperationLog($operationId),
        ]);
        $state['finished_at'] = date('c');
        $state['status'] = 'completed';
        $state['stage'] = 'Completed';
        $state['message'] = 'Composer is already installed.';
        $state['result'] = $resultData;
        $state['elapsed_seconds'] = max(0, (int)round(microtime(true) - $started));
        managedServerOperationWrite($state);
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Composer is already installed.');
        return;
    }

    $state['stage'] = 'Installing Composer';
    $state['message'] = 'Installing Composer with apt-get.';
    managedServerOperationWrite($state);
    $install = managedServerOperationRunRemoteCommand($operationId, $server, managedServerComposerInstallCommand($server), 600);
    if ($install['exit_code'] !== 0) {
        throw new RuntimeException('Composer installation failed.');
    }

    $verify = managedServerOperationRunRemoteCommand($operationId, $server, managedServerComposerVerifyCommand(), 30);
    if ($verify['exit_code'] !== 0 || trim((string)$verify['stdout']) === '') {
        throw new RuntimeException('Composer installation completed but composer --version failed.');
    }
    $verifyLines = explode("\n", trim((string)$verify['stdout']));
    $composerPath = trim((string)($verifyLines[0] ?? ''));
    $composerVersion = trim((string)($verifyLines[1] ?? ''));
    $resultData = [
        'success' => true,
        'message' => 'Composer installed.',
        'composer_installed' => true,
        'composer_path' => $composerPath,
        'composer_version' => $composerVersion,
        'output' => managedServerOperationLog($operationId),
    ];
    managedServersUpdateRuntimeDiagnostics((string)$server['id'], $resultData);
    $diagnosticData = managedServerRefreshDiagnosticsAfterOperation($operationId, $server, $started);
    $resultData = array_merge($resultData, $diagnosticData, [
        'success' => true,
        'message' => 'Composer installed.',
        'composer_installed' => true,
        'composer_path' => $composerPath,
        'composer_version' => $composerVersion,
        'output' => managedServerOperationLog($operationId),
    ]);
    $state = managedServerOperationRead($operationId);
    $state['finished_at'] = date('c');
    $state['status'] = 'completed';
    $state['stage'] = 'Completed';
    $state['message'] = 'Composer installed.';
    $state['result'] = $resultData;
    $state['elapsed_seconds'] = max(0, (int)round(microtime(true) - $started));
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Composer installed.');
}

function managedServerRunApacheInstall(string $operationId, array $server): void
{
    $started = microtime(true);
    $state = managedServerOperationRead($operationId);
    $state['stage'] = 'Checking Server';
    $state['message'] = 'Checking SSH, sudo, Ubuntu and Apache state.';
    managedServerOperationWrite($state);

    $ssh = managedServersSshExecutable();
    if ($ssh === '') {
        throw new RuntimeException('SSH executable missing.');
    }
    if (!is_file((string)$server['key']) || !is_readable((string)$server['key'])) {
        throw new RuntimeException('Key file missing.');
    }
    if (!managedServersKeyPermissionsValid((string)$server['key'])) {
        throw new RuntimeException('Invalid key permissions.');
    }

    $check = managedServerOperationRunRemoteCommand($operationId, $server, managedServerApacheInstallCheckCommand(), 30);
    if ($check['exit_code'] !== 0) {
        throw new RuntimeException(managedServerConnectionResultMessage((string)$check['output']));
    }
    $parsed = managedServerParseApacheInstallCheck((string)$check['stdout']);
    $sudoState = (string)$parsed['passwordless_sudo'];
    if (!in_array($sudoState, ['root', 'ready'], true)) {
        throw new RuntimeException('Passwordless sudo is not configured for this deployment user. Run the Managed Server setup command again as root.');
    }
    $osId = strtolower((string)$parsed['os_id']);
    if ($osId !== 'ubuntu') {
        throw new RuntimeException('Apache installation is supported only for Ubuntu managed servers. Install Apache manually for this server, then refresh diagnostics.');
    }
    if ((string)$parsed['apache_path'] !== '') {
        $resultData = [
            'success' => true,
            'message' => 'Apache is already installed.',
            'apache' => array_merge(managedServersEmptyServer()['apache'], [
                'installed' => true,
                'version' => (string)$parsed['apache_version'],
                'binary_path' => (string)$parsed['apache_path'],
            ]),
            'output' => managedServerOperationLog($operationId),
        ];
        managedServersUpdateRuntimeDiagnostics((string)$server['id'], $resultData);
        $diagnosticData = managedServerRefreshDiagnosticsAfterOperation($operationId, $server, $started);
        $resultData = array_merge($resultData, $diagnosticData, [
            'success' => true,
            'message' => 'Apache is already installed.',
            'output' => managedServerOperationLog($operationId),
        ]);
        $state['finished_at'] = date('c');
        $state['status'] = 'completed';
        $state['stage'] = 'Completed';
        $state['message'] = 'Apache is already installed.';
        $state['result'] = $resultData;
        $state['elapsed_seconds'] = max(0, (int)round(microtime(true) - $started));
        managedServerOperationWrite($state);
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Apache is already installed.');
        return;
    }

    $state['stage'] = 'Installing Apache';
    $state['message'] = 'Installing Apache with apt-get.';
    managedServerOperationWrite($state);
    $install = managedServerOperationRunRemoteCommand($operationId, $server, managedServerApacheInstallCommand($server), 600);
    if ($install['exit_code'] !== 0) {
        throw new RuntimeException('Apache installation failed.');
    }

    $state = managedServerOperationRead($operationId);
    $state['stage'] = 'Verifying Apache';
    $state['message'] = 'Verifying Apache and Apache configuration.';
    managedServerOperationWrite($state);
    $verify = managedServerOperationRunRemoteCommand($operationId, $server, managedServerApacheVerifyCommand(), 30);
    if ($verify['exit_code'] !== 0 || trim((string)$verify['stdout']) === '') {
        throw new RuntimeException('Apache installation completed but apache2ctl configtest failed.');
    }
    $verifyLines = explode("\n", trim((string)$verify['stdout']));
    $apachePath = trim((string)($verifyLines[0] ?? ''));

    $state = managedServerOperationRead($operationId);
    $state['stage'] = 'Starting Apache';
    $state['message'] = 'Enabling and starting Apache.';
    managedServerOperationWrite($state);
    $start = managedServerOperationRunRemoteCommand($operationId, $server, managedServerApacheEnableStartCommand($server), 60);
    if ($start['exit_code'] !== 0) {
        throw new RuntimeException('Apache was installed, but enable/start failed.');
    }

    $resultData = [
        'success' => true,
        'message' => 'Apache installed.',
        'apache' => array_merge(managedServersEmptyServer()['apache'], [
            'installed' => true,
            'binary_path' => $apachePath,
        ]),
        'output' => managedServerOperationLog($operationId),
    ];
    managedServersUpdateRuntimeDiagnostics((string)$server['id'], $resultData);
    $diagnosticData = managedServerRefreshDiagnosticsAfterOperation($operationId, $server, $started);
    $resultData = array_merge($resultData, $diagnosticData, [
        'success' => true,
        'message' => 'Apache installed.',
        'output' => managedServerOperationLog($operationId),
    ]);
    $state = managedServerOperationRead($operationId);
    $state['finished_at'] = date('c');
    $state['status'] = 'completed';
    $state['stage'] = 'Completed';
    $state['message'] = 'Apache installed.';
    $state['result'] = $resultData;
    $state['elapsed_seconds'] = max(0, (int)round(microtime(true) - $started));
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Apache installed.');
}

function managedServersUpdateRuntimeDiagnostics(string $serverId, array $result): void
{
    $servers = managedServersLoad();
    foreach ($servers as $index => $server) {
        if ((string)($server['id'] ?? '') !== $serverId) {
            continue;
        }
        if (array_key_exists('composer_installed', $result)) {
            $servers[$index]['composer_installed'] = !empty($result['composer_installed']);
            $servers[$index]['composer_version'] = (string)($result['composer_version'] ?? '');
            $servers[$index]['composer_path'] = (string)($result['composer_path'] ?? '');
        }
        if (array_key_exists('php_installed', $result)) {
            $servers[$index]['php_installed'] = !empty($result['php_installed']);
            $servers[$index]['php_version'] = (string)($result['php_version'] ?? '');
            $servers[$index]['php_path'] = (string)($result['php_path'] ?? '');
        }
        foreach (['node', 'npm'] as $tool) {
            $installedField = $tool . '_installed';
            $versionField = $tool . '_version';
            $pathField = $tool . '_path';
            if (array_key_exists($installedField, $result)) {
                $servers[$index][$installedField] = !empty($result[$installedField]);
                $servers[$index][$versionField] = (string)($result[$versionField] ?? '');
                $servers[$index][$pathField] = (string)($result[$pathField] ?? '');
            }
        }
        if (is_array($result['apache'] ?? null)) {
            $servers[$index]['apache'] = array_merge(managedServersEmptyServer()['apache'], $result['apache']);
        }
        if (is_array($result['apache_sites'] ?? null)) {
            $servers[$index]['apache_sites'] = $result['apache_sites'];
        }
        managedServersSave($servers);
        return;
    }
}

function managedServerOperationStatus(string $operationId): array
{
    $state = managedServerOperationRead($operationId);
    $state['log'] = managedServerOperationLog($operationId);
    $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
    $finishedAt = strtotime((string)($state['finished_at'] ?? '')) ?: time();
    $state['elapsed_seconds'] = max(0, $finishedAt - $startedAt);

    return $state;
}
