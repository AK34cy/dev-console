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
        foreach (['id', 'name', 'host', 'user', 'auth_method', 'key', 'key_fingerprint', 'description', 'status', 'remote_hostname', 'remote_os', 'remote_kernel', 'remote_working_directory', 'remote_user', 'passwordless_sudo', 'last_error'] as $field) {
            if (isset($serverInput[$field]) && is_scalar($serverInput[$field])) {
                $server[$field] = trim((string)$serverInput[$field]);
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
    if ($json === false || @file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write managed server operation state.');
    }
}

function managedServerOperationRead(string $operationId): array
{
    $path = managedServerOperationPath($operationId, 'json');
    $decoded = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;

    return is_array($decoded) ? $decoded : [];
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
    $filtered = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '__DEV_CONSOLE_SUDO__=')) {
            $value = substr($line, strlen('__DEV_CONSOLE_SUDO__='));
            $passwordlessSudo = in_array($value, ['ready', 'setup_required', 'root'], true) ? $value : 'unknown';
            continue;
        }
        $filtered[] = $line;
    }
    $lines = $filtered;

    return [
        'hostname' => (string)($lines[0] ?? ''),
        'uname' => (string)($lines[1] ?? ''),
        'kernel' => (string)($lines[1] ?? ''),
        'working_directory' => (string)($lines[2] ?? ''),
        'remote_user' => (string)($lines[3] ?? ''),
        'os' => (string)($lines[4] ?? ''),
        'passwordless_sudo' => $passwordlessSudo,
    ];
}

function managedServerStartConnectionTest(array $servers, string $serverId): array
{
    $server = managedServersFind($servers, $serverId);
    if ($server === null) {
        throw new RuntimeException('Managed server not found.');
    }
    $operationId = 'mso_' . bin2hex(random_bytes(16));
    $state = [
        'id' => $operationId,
        'server_id' => $serverId,
        'server_name' => (string)$server['name'],
        'status' => 'running',
        'stage' => 'Starting',
        'started_at' => date('c'),
        'updated_at' => date('c'),
        'finished_at' => '',
        'message' => 'Testing SSH connection.',
        'result' => null,
    ];
    managedServerOperationWrite($state);
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Starting SSH connection test for ' . (string)$server['name'] . '.');

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
    $state['pid'] = $pid;
    managedServerOperationWrite($state);

    return managedServerOperationRead($operationId);
}

function managedServerRunConnectionTestById(string $operationId): void
{
    $state = managedServerOperationRead($operationId);
    if (empty($state)) {
        throw new RuntimeException('Managed server operation not found.');
    }
    $serverId = (string)($state['server_id'] ?? '');
    $server = managedServersFind(managedServersLoad(), $serverId);
    if ($server === null) {
        throw new RuntimeException('Managed server not found.');
    }

    managedServerRunConnectionTest($operationId, $server);
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
        'sh',
        '-c',
        'printf "__DEV_CONSOLE_CONNECTED__\n"; hostname; uname -a; pwd; whoami; if [ -r /etc/os-release ]; then . /etc/os-release; printf "%s\n" "${PRETTY_NAME:-${NAME:-}}"; fi; if [ "$(id -u)" = 0 ]; then printf "__DEV_CONSOLE_SUDO__=root\n"; elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then printf "__DEV_CONSOLE_SUDO__=ready\n"; else printf "__DEV_CONSOLE_SUDO__=setup_required\n"; fi',
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

function managedServerOperationStatus(string $operationId): array
{
    $state = managedServerOperationRead($operationId);
    if (empty($state)) {
        throw new RuntimeException('Managed server operation not found.');
    }
    $state['log'] = managedServerOperationLog($operationId);
    $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
    $finishedAt = strtotime((string)($state['finished_at'] ?? '')) ?: time();
    $state['elapsed_seconds'] = max(0, $finishedAt - $startedAt);

    return $state;
}
