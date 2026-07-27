<?php

const DEV_CONSOLE_APACHE_SERVERNAME_CONF = 'iovon-dev-console-servername.conf';
const DEV_CONSOLE_APACHE_SERVERNAME_CONTENT = "# Managed by IOVON Dev Console\nServerName localhost\n";

function apacheBinaryPath(): string
{
    foreach (['/usr/sbin/apache2', '/usr/sbin/httpd'] as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function apacheSystemctlPath(): string
{
    return is_file('/bin/systemctl') && is_executable('/bin/systemctl')
        ? '/bin/systemctl'
        : (is_file('/usr/bin/systemctl') && is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl' : '');
}

function apacheRunFixedCommand(array $arguments, array $environment = []): array
{
    $pipes = [];
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment ?: null);
    if (!is_resource($process)) {
        return [
            'command' => implode(' ', $arguments),
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'Unable to start command.',
            'output' => 'Unable to start command.',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'command' => implode(' ', $arguments),
        'exit_code' => $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'output' => trim($stdout . ($stderr === '' ? '' : "\n" . $stderr)),
    ];
}

function apacheSystemctlCommand(string $operation): array
{
    $systemctl = apacheSystemctlPath();
    if ($systemctl === '') {
        return [
            'command' => 'systemctl ' . $operation . ' apache2',
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'systemctl is not available.',
            'output' => 'systemctl is not available.',
        ];
    }

    return apacheRunFixedCommand([$systemctl, $operation, 'apache2']);
}

function apacheState(): array
{
    $binaryPath = apacheBinaryPath();
    $installed = $binaryPath !== '';
    $running = false;
    $enabled = null;
    $version = '';

    if ($installed) {
        $versionResult = apacheRunFixedCommand([$binaryPath, '-v']);
        if ($versionResult['exit_code'] === 0 && preg_match('/Server version:\s*(.+)/i', $versionResult['output'], $matches) === 1) {
            $version = trim($matches[1]);
        }

        $runningResult = apacheSystemctlCommand('is-active');
        $running = $runningResult['exit_code'] === 0 && trim($runningResult['stdout']) === 'active';

        $enabledResult = apacheSystemctlCommand('is-enabled');
        if ($enabledResult['exit_code'] === 0) {
            $enabled = true;
        } elseif (in_array(trim($enabledResult['stdout']), ['disabled', 'static', 'masked'], true)) {
            $enabled = false;
        }
    }

    return [
        'installed' => $installed,
        'running' => $running,
        'enabled' => $enabled,
        'version' => $version,
        'binary_path' => $binaryPath,
    ];
}

function apacheAllowedActions(): array
{
    return ['install_apache', 'start_apache', 'restart_apache'];
}

function apacheCommandPath(string $binary): string
{
    foreach (['/usr/sbin/' . $binary, '/usr/bin/' . $binary, '/bin/' . $binary] as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return $binary;
}

function apacheServerNameAvailablePath(string $confAvailableDir = '/etc/apache2/conf-available'): string
{
    return rtrim($confAvailableDir, '/') . '/' . DEV_CONSOLE_APACHE_SERVERNAME_CONF;
}

function apacheServerNameEnabledPath(string $confEnabledDir = '/etc/apache2/conf-enabled'): string
{
    return rtrim($confEnabledDir, '/') . '/' . DEV_CONSOLE_APACHE_SERVERNAME_CONF;
}

function apacheServerNameConfigMatches(string $path): bool
{
    return is_file($path) && trim((string)@file_get_contents($path)) === trim(DEV_CONSOLE_APACHE_SERVERNAME_CONTENT);
}

function apacheEnsureServerNameConfig(array $options = []): array
{
    if (empty($options['assume_installed']) && !apacheState()['installed']) {
        return apacheFormatResult(false, 'Apache is not installed.', []);
    }

    $confAvailableDir = (string)($options['conf_available_dir'] ?? '/etc/apache2/conf-available');
    $confEnabledDir = (string)($options['conf_enabled_dir'] ?? '/etc/apache2/conf-enabled');
    $runCommands = $options['run_commands'] ?? true;
    $commandRunner = $options['command_runner'] ?? null;
    $commands = [];
    $changed = false;
    $path = apacheServerNameAvailablePath($confAvailableDir);

    if (is_file($path) && !apacheServerNameConfigMatches($path)) {
        return apacheFormatResult(false, 'Refusing to overwrite unrelated Apache ServerName config.', []);
    }

    if (!is_file($path)) {
        if (!is_dir($confAvailableDir) && !@mkdir($confAvailableDir, 0755, true) && !is_dir($confAvailableDir)) {
            return apacheFormatResult(false, 'Unable to access Apache conf-available directory.', []);
        }
        if (@file_put_contents($path, DEV_CONSOLE_APACHE_SERVERNAME_CONTENT, LOCK_EX) === false) {
            return apacheFormatResult(false, 'Unable to write Apache ServerName config.', []);
        }
        @chmod($path, 0644);
        $changed = true;
    }

    if ($runCommands) {
        if (!file_exists(apacheServerNameEnabledPath($confEnabledDir))) {
            $commands[] = is_callable($commandRunner)
                ? $commandRunner([apacheCommandPath('a2enconf'), 'iovon-dev-console-servername'])
                : apacheRunFixedCommand([apacheCommandPath('a2enconf'), 'iovon-dev-console-servername']);
            if (end($commands)['exit_code'] !== 0) {
                return apacheFormatResult(false, 'Unable to enable Apache ServerName config.', $commands);
            }
            $changed = true;
        }

        $commands[] = is_callable($commandRunner)
            ? $commandRunner([apacheCommandPath('apache2ctl'), 'configtest'])
            : apacheRunFixedCommand([apacheCommandPath('apache2ctl'), 'configtest']);
        if (end($commands)['exit_code'] !== 0) {
            return apacheFormatResult(false, 'Apache configtest failed.', $commands);
        }

        if ($changed) {
            $commands[] = is_callable($commandRunner)
                ? $commandRunner([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2'])
                : apacheSystemctlCommand('reload');
            if (end($commands)['exit_code'] !== 0) {
                return apacheFormatResult(false, 'Apache reload failed.', $commands);
            }
        }
    }

    return apacheFormatResult(true, $changed ? 'Apache ServerName config enabled.' : 'Apache ServerName config already enabled.', $commands);
}

function apacheAppendFormattedResultCommands(array &$commands, array $formattedResult): void
{
    $output = trim((string)($formattedResult['output'] ?? ''));
    if ($output === '') {
        return;
    }

    $commands[] = [
        'command' => 'ensure Apache ServerName config',
        'exit_code' => !empty($formattedResult['success']) ? 0 : 1,
        'stdout' => '',
        'stderr' => '',
        'output' => $output,
    ];
}

function apacheFormatResult(bool $success, string $message, array $commands): array
{
    $output = [];
    foreach ($commands as $result) {
        $output[] = '$ ' . (string)$result['command'];
        $output[] = 'Exit code: ' . (string)$result['exit_code'];
        $commandOutput = trim((string)$result['output']);
        if ($commandOutput !== '') {
            $output[] = $commandOutput;
        }
    }

    return [
        'success' => $success,
        'message' => $message,
        'output' => implode("\n", $output),
    ];
}

function apacheInstall(): array
{
    if (apacheState()['installed']) {
        $serverNameResult = apacheEnsureServerNameConfig();
        return [
            'success' => !empty($serverNameResult['success']),
            'message' => !empty($serverNameResult['success']) ? 'Apache is already installed.' : 'Apache ServerName config failed.',
            'output' => (string)($serverNameResult['output'] ?? ''),
        ];
    }

    $commands = [];
    $commands[] = apacheRunFixedCommand(['/usr/bin/apt-get', 'update']);
    if (end($commands)['exit_code'] === 0) {
        $commands[] = apacheRunFixedCommand(['/usr/bin/apt-get', 'install', '-y', 'apache2'], ['DEBIAN_FRONTEND' => 'noninteractive']);
    }
    if (end($commands)['exit_code'] === 0) {
        $commands[] = apacheSystemctlCommand('enable');
    }
    if (end($commands)['exit_code'] === 0) {
        $commands[] = apacheSystemctlCommand('start');
    }
    if (end($commands)['exit_code'] === 0) {
        $serverNameResult = apacheEnsureServerNameConfig();
        apacheAppendFormattedResultCommands($commands, $serverNameResult);
    }

    $success = end($commands)['exit_code'] === 0;
    return apacheFormatResult($success, $success ? 'Apache installed and started.' : 'Apache installation failed.', $commands);
}

function apacheStart(): array
{
    if (!apacheState()['installed']) {
        return apacheFormatResult(false, 'Apache is not installed.', []);
    }

    $result = apacheSystemctlCommand('start');
    $commands = [$result];
    if ($result['exit_code'] === 0) {
        $serverNameResult = apacheEnsureServerNameConfig();
        apacheAppendFormattedResultCommands($commands, $serverNameResult);
    }

    $success = end($commands)['exit_code'] === 0;
    return apacheFormatResult($success, $success ? 'Apache started.' : 'Apache start failed.', $commands);
}

function apacheRestart(): array
{
    if (!apacheState()['installed']) {
        return apacheFormatResult(false, 'Apache is not installed.', []);
    }

    $commands = [];
    $serverNameResult = apacheEnsureServerNameConfig();
    apacheAppendFormattedResultCommands($commands, $serverNameResult);
    if (!empty($serverNameResult['success'])) {
        $commands[] = apacheSystemctlCommand('restart');
    }

    $success = !empty($commands) && end($commands)['exit_code'] === 0;
    return apacheFormatResult($success, $success ? 'Apache restarted.' : 'Apache restart failed.', $commands);
}

function apacheRunAction(string $action): array
{
    return match ($action) {
        'install_apache' => apacheInstall(),
        'start_apache' => apacheStart(),
        'restart_apache' => apacheRestart(),
        default => [
            'success' => false,
            'message' => 'Unsupported Apache action.',
            'output' => '',
        ],
    };
}

function apacheStatusLabel(array $state): string
{
    if (empty($state['installed'])) {
        return 'Not installed';
    }

    return !empty($state['running']) ? 'Running' : 'Installed, stopped';
}

function apacheEnabledLabel(array $state): string
{
    if (($state['enabled'] ?? null) === true) {
        return 'Yes';
    }

    if (($state['enabled'] ?? null) === false) {
        return 'No';
    }

    return 'Unknown';
}
