<?php

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
        return apacheFormatResult(true, 'Apache is already installed.', []);
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

    $success = end($commands)['exit_code'] === 0;
    return apacheFormatResult($success, $success ? 'Apache installed and started.' : 'Apache installation failed.', $commands);
}

function apacheStart(): array
{
    if (!apacheState()['installed']) {
        return apacheFormatResult(false, 'Apache is not installed.', []);
    }

    $result = apacheSystemctlCommand('start');
    return apacheFormatResult($result['exit_code'] === 0, $result['exit_code'] === 0 ? 'Apache started.' : 'Apache start failed.', [$result]);
}

function apacheRestart(): array
{
    if (!apacheState()['installed']) {
        return apacheFormatResult(false, 'Apache is not installed.', []);
    }

    $result = apacheSystemctlCommand('restart');
    return apacheFormatResult($result['exit_code'] === 0, $result['exit_code'] === 0 ? 'Apache restarted.' : 'Apache restart failed.', [$result]);
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
