<?php

const DEV_CONSOLE_SERVICE_NAME = 'iovon-dev-console.service';

function serverToolsDefinitions(): array
{
    return [
        'git' => [
            'display_name' => 'Git',
            'purpose' => 'Required',
            'command' => 'git',
            'version_arguments' => ['git', '--version'],
            'version_pattern' => '/git version\s+(.+)/i',
            'required_group' => 'required',
            'requirement' => 'Required',
            'dependency' => 'Required for repository workflows',
            'source' => 'System package or administrator-managed binary',
        ],
        'php' => [
            'display_name' => 'PHP',
            'purpose' => 'Required by Dev Console; System-managed',
            'command' => PHP_BINARY,
            'version_arguments' => [PHP_BINARY, '--version'],
            'version_pattern' => '/^PHP\s+([^\s]+)/i',
            'required_group' => 'required',
            'requirement' => 'Required',
            'dependency' => 'Runs Dev Console',
            'source' => 'System-managed',
        ],
        'codex' => [
            'display_name' => 'Codex CLI',
            'purpose' => 'Required for Run Codex',
            'command' => 'codex',
            'version_arguments' => ['codex', '--version'],
            'version_pattern' => '/codex(?:-cli)?\s+(.+)/i',
            'required_group' => 'required',
            'requirement' => 'Required for Run Codex',
            'dependency' => 'Run Codex action',
            'source' => 'Administrator-managed CLI',
        ],
        'node' => [
            'display_name' => 'Node.js',
            'purpose' => 'Project-dependent; Required to install and run Codex CLI through npm',
            'command' => 'node',
            'version_arguments' => ['node', '--version'],
            'version_pattern' => '/^v?(.+)/',
            'required_group' => 'optional',
            'requirement' => 'Project-dependent',
            'dependency' => 'May be required by JavaScript Projects and npm-installed Codex CLI',
            'source' => 'System package, NodeSource, nvm, or administrator-managed binary',
        ],
        'npm' => [
            'display_name' => 'npm',
            'purpose' => 'Project-dependent; Required to install and update Codex CLI',
            'command' => 'npm',
            'version_arguments' => ['npm', '--version'],
            'version_pattern' => '/^(.+)/',
            'required_group' => 'optional',
            'requirement' => 'Project-dependent',
            'dependency' => 'Node.js package manager',
            'source' => 'Usually installed with Node.js',
        ],
        'composer' => [
            'display_name' => 'Composer',
            'purpose' => 'Optional / Project-dependent',
            'command' => 'composer',
            'version_arguments' => ['composer', '--version'],
            'version_pattern' => '/Composer(?: version)?\s+([^\s]+)/i',
            'required_group' => 'optional',
            'requirement' => 'Optional / Project-dependent',
            'dependency' => 'PHP Project dependency manager',
            'source' => 'System package or administrator-managed binary',
        ],
    ];
}

function serverToolsDefaultPath(): string
{
    return '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
}

function serverToolsServiceContext(): array
{
    $context = [
        'service_name' => DEV_CONSOLE_SERVICE_NAME,
        'user' => get_current_user() ?: 'Diagnostic unavailable',
        'group' => 'Diagnostic unavailable',
        'path' => getenv('PATH') ?: serverToolsDefaultPath(),
        'working_directory' => getcwd() ?: __DIR__,
        'php_executable' => PHP_BINARY,
        'status' => 'Diagnostic unavailable',
    ];
    $groupInfo = function_exists('posix_getegid') && function_exists('posix_getgrgid') ? @posix_getgrgid(posix_getegid()) : false;
    if (is_array($groupInfo) && is_scalar($groupInfo['name'] ?? null)) {
        $context['group'] = (string)$groupInfo['name'];
    }
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $userInfo = @posix_getpwuid(posix_geteuid());
        if (is_array($userInfo) && is_scalar($userInfo['name'] ?? null)) {
            $context['user'] = (string)$userInfo['name'];
        }
    }

    $unit = serverToolsRunDiagnosticCommand(['systemctl', 'show', DEV_CONSOLE_SERVICE_NAME, '--property=User,Group,WorkingDirectory,Environment,ExecStart', '--no-pager'], 5);
    if (!empty($unit['success'])) {
        $context['status'] = 'Detected';
        foreach (preg_split('/\R/', (string)$unit['stdout']) ?: [] as $line) {
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            if ($name === 'User' && $value !== '') $context['user'] = $value;
            if ($name === 'Group' && $value !== '') $context['group'] = $value;
            if ($name === 'WorkingDirectory' && $value !== '') $context['working_directory'] = $value;
            if ($name === 'Environment' && preg_match('/(?:^|\s)PATH=([^\s]+)/', $value, $matches) === 1) {
                $context['path'] = $matches[1];
            }
            if ($name === 'ExecStart' && preg_match('#(/usr/\S*php\S*)#', $value, $matches) === 1) {
                $context['php_executable'] = $matches[1];
            }
        }
    }

    return $context;
}

function serverToolsRunDiagnosticCommand(array $arguments, int $timeoutSeconds = 5): array
{
    return processRunCommand($arguments, [
        'timeout' => $timeoutSeconds,
        'env' => ['PATH' => getenv('PATH') ?: serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
}

function serverToolsFindExecutable(string $command, string $path): string
{
    if (str_contains($command, '/')) {
        return is_file($command) && is_executable($command) ? $command : '';
    }

    foreach (explode(':', $path) as $directory) {
        $candidate = rtrim($directory, '/') . '/' . $command;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function serverToolsParseVersion(string $output, string $pattern): string
{
    $firstLine = trim(strtok($output, "\n") ?: $output);
    if ($pattern !== '' && preg_match($pattern, $output, $matches) === 1) {
        return trim((string)$matches[1]);
    }

    return $firstLine;
}

function serverToolsDetectTool(array $definition, array $context, bool $includeLatest = true): array
{
    $path = (string)($context['path'] ?? serverToolsDefaultPath());
    $executable = serverToolsFindExecutable((string)$definition['command'], $path);
    $checkedAt = date('c');
    $toolId = (string)($definition['id'] ?? '');
    if ($executable === '') {
        return [
            'display_name' => (string)$definition['display_name'],
            'purpose' => (string)$definition['purpose'],
            'installed' => false,
            'version' => '',
            'executable_path' => '',
            'available_to_service_user' => false,
            'diagnostic_status' => 'Not installed',
            'last_checked_at' => $checkedAt,
            'latest_version' => $includeLatest ? serverToolsLatestVersion($toolId, '', false) : '',
            'outdated' => false,
            'package_source' => (string)$definition['source'],
            'dependency_relationship' => (string)$definition['dependency'],
            'requirement' => (string)$definition['requirement'],
            'required_group' => (string)$definition['required_group'],
            'log' => (string)$definition['display_name'] . ': executable not found in PATH.',
        ];
    }

    $arguments = $definition['version_arguments'];
    if ((string)$definition['command'] === PHP_BINARY) {
        $arguments = [$executable, '--version'];
    }
    $result = serverToolsRunDiagnosticCommand($arguments, 5);
    $available = !empty($result['success']);
    $version = $available ? serverToolsParseVersion((string)$result['stdout'], (string)$definition['version_pattern']) : '';
    $status = $available ? 'Installed' : ((int)($result['exit_code'] ?? 127) === 126 ? 'Installed but unavailable to service user' : 'Version check failed');

    $latestVersion = $includeLatest ? serverToolsLatestVersion($toolId, $version, $available) : '';
    $outdated = $version !== '' && $latestVersion !== '' && version_compare(serverToolsNormalizeVersion($version), serverToolsNormalizeVersion($latestVersion), '<');

    return [
        'display_name' => (string)$definition['display_name'],
        'purpose' => (string)$definition['purpose'],
        'installed' => true,
        'version' => $version,
        'executable_path' => $executable,
        'available_to_service_user' => $available,
        'diagnostic_status' => $status,
        'last_checked_at' => $checkedAt,
        'latest_version' => $latestVersion,
        'outdated' => $outdated,
        'package_source' => (string)$definition['source'],
        'dependency_relationship' => (string)$definition['dependency'],
        'requirement' => (string)$definition['requirement'],
        'required_group' => (string)$definition['required_group'],
        'log' => trim((string)$definition['display_name'] . ': ' . $result['command'] . ' -> ' . $status . ($version !== '' ? ' (' . $version . ')' : '')),
    ];
}

function serverToolsDiagnostics(bool $includeLatest = true): array
{
    $context = serverToolsServiceContext();
    $tools = [];
    $log = ['Server context: ' . (string)$context['status']];
    foreach (serverToolsDefinitions() as $id => $definition) {
        $definition['id'] = $id;
        try {
            $tools[$id] = serverToolsDetectTool($definition, $context, $includeLatest);
        } catch (Throwable $exception) {
            $tools[$id] = [
                'display_name' => (string)$definition['display_name'],
                'purpose' => (string)$definition['purpose'],
                'installed' => false,
                'version' => '',
                'executable_path' => '',
                'available_to_service_user' => false,
                'diagnostic_status' => 'Diagnostic unavailable',
                'last_checked_at' => date('c'),
                'package_source' => (string)$definition['source'],
                'dependency_relationship' => (string)$definition['dependency'],
                'requirement' => (string)$definition['requirement'],
                'required_group' => (string)$definition['required_group'],
                'log' => (string)$definition['display_name'] . ': Diagnostic unavailable.',
            ];
        }
        $log[] = (string)$tools[$id]['log'];
    }

    return [
        'context' => $context,
        'tools' => $tools,
        'generated_at' => date('c'),
        'log' => implode("\n", $log),
    ];
}

function serverToolsNormalizeVersion(string $version): string
{
    return preg_match('/(\d+(?:\.\d+){0,3})/', $version, $matches) === 1 ? $matches[1] : $version;
}

function serverToolsLatestVersion(string $toolId, string $installedVersion, bool $available): string
{
    if ($toolId === 'node') {
        $candidate = serverToolsRunDiagnosticCommand(['apt-cache', 'policy', 'nodejs'], 5);
        if (!empty($candidate['success']) && preg_match('/^\s*Candidate:\s*(.+)$/m', (string)$candidate['stdout'], $matches) === 1) {
            $value = trim($matches[1]);
            return $value === '(none)' ? '' : $value;
        }
    }
    if ($toolId === 'npm' || $toolId === 'codex') {
        $package = $toolId === 'npm' ? 'npm' : '@openai/codex';
        $latest = serverToolsRunDiagnosticCommand(['npm', 'view', $package, 'version'], 15);
        return !empty($latest['success']) ? trim((string)$latest['stdout']) : '';
    }
    if ($toolId === 'composer') {
        $latest = serverToolsRunDiagnosticCommand([PHP_BINARY, '-r', "echo json_decode(file_get_contents('https://getcomposer.org/versions'), true)['stable'][0]['version'] ?? '';"], 15);
        return !empty($latest['success']) ? trim((string)$latest['stdout']) : '';
    }

    return '';
}

function serverToolsAllowedActionsForTool(string $toolId, array $tool): array
{
    if (in_array($toolId, ['git', 'php'], true)) {
        return ['refresh'];
    }
    if ($toolId === 'npm') {
        return ['refresh'];
    }
    $binaryPresent = !empty($tool['installed']);
    $available = $binaryPresent && !empty($tool['available_to_service_user']);
    if (!$binaryPresent) {
        return ['install', 'refresh'];
    }
    if (!$available) {
        return ['reinstall', 'refresh'];
    }
    if ((string)($tool['diagnostic_status'] ?? '') !== 'Installed') {
        return ['reinstall', 'refresh'];
    }
    if (!empty($tool['outdated'])) {
        return ['update', 'refresh'];
    }

    return ['refresh'];
}

function serverToolsActionLabel(string $action): string
{
    return match ($action) {
        'install' => 'Install',
        'update' => 'Update',
        'reinstall' => 'Reinstall',
        default => 'Refresh',
    };
}

function serverToolsActionPermitted(string $toolId, string $toolAction, array $diagnostics): bool
{
    $tool = is_array($diagnostics['tools'][$toolId] ?? null) ? $diagnostics['tools'][$toolId] : [];
    return in_array($toolAction, serverToolsAllowedActionsForTool($toolId, $tool), true);
}

function serverToolsOperationLogPath(): string
{
    return sys_get_temp_dir() . '/iovon-dev-console-server-tools.log';
}

function serverToolsAppendActivityLog(array $entry): void
{
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
        @file_put_contents(serverToolsOperationLogPath(), $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

function serverToolsCommandsFromLog(array $log): array
{
    $commands = [];
    foreach ($log as $line) {
        if (str_starts_with((string)$line, '$ ')) {
            $commands[] = substr((string)$line, 2);
        }
    }

    return $commands;
}

function serverToolsRunOperationCommand(array $arguments, array &$log, int $timeoutSeconds = 120): array
{
    $result = serverToolsRunDiagnosticCommand($arguments, $timeoutSeconds);
    $log[] = '$ ' . $result['command'];
    if ((string)$result['output'] !== '') {
        $log[] = (string)$result['output'];
    }
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (empty($result['success'])) {
        throw new RuntimeException(serverToolsReadableCommandError($result));
    }

    return $result;
}

function serverToolsReadableCommandError(array $result): string
{
    $output = strtolower((string)($result['output'] ?? ''));
    if (str_contains($output, 'could not resolve') || str_contains($output, 'failed to fetch') || str_contains($output, 'network')) {
        return 'Network unavailable or repository unreachable.';
    }
    if (str_contains($output, 'permission denied') || str_contains($output, 'are you root') || str_contains($output, 'could not open lock')) {
        return 'Permission problem while changing server tools.';
    }
    if (str_contains($output, 'checksum')) {
        return 'Checksum verification failed.';
    }
    if (str_contains($output, 'unsupported')) {
        return 'Unsupported operating system.';
    }

    return 'Command failed.';
}

function serverToolsAssertSupportedOs(): void
{
    $release = (string)@file_get_contents('/etc/os-release');
    if (preg_match('/^ID=(?:\"?)([a-z0-9_-]+)/m', $release, $matches) !== 1 || !in_array($matches[1], ['ubuntu', 'debian'], true)) {
        throw new RuntimeException('Unsupported operating system.');
    }
}

function serverToolsInstallNode(string $toolAction, array &$log): void
{
    serverToolsAssertSupportedOs();
    $setupPath = sys_get_temp_dir() . '/nodesource_setup_lts.sh';
    try {
        serverToolsRunOperationCommand(['apt-get', 'update'], $log, 180);
        serverToolsRunOperationCommand(['apt-get', 'install', '-y', 'ca-certificates', 'curl', 'gnupg'], $log, 180);
        serverToolsRunOperationCommand(['curl', '-fsSL', 'https://deb.nodesource.com/setup_lts.x', '-o', $setupPath], $log, 60);
        serverToolsRunOperationCommand(['bash', $setupPath], $log, 240);
        $installArguments = ['apt-get', 'install', '-y', 'nodejs'];
        if ($toolAction === 'reinstall') {
            $installArguments = ['apt-get', 'install', '--reinstall', '-y', 'nodejs'];
        }
        if ($toolAction === 'update') {
            $installArguments = ['apt-get', 'install', '--only-upgrade', '-y', 'nodejs'];
        }
        serverToolsRunOperationCommand($installArguments, $log, 240);
    } finally {
        @unlink($setupPath);
    }
}

function serverToolsInstallComposer(array &$log): void
{
    $setupPath = sys_get_temp_dir() . '/composer-setup.php';
    try {
        $signatureResult = serverToolsRunOperationCommand([PHP_BINARY, '-r', "echo trim(file_get_contents('https://composer.github.io/installer.sig'));"], $log, 60);
        serverToolsRunOperationCommand([PHP_BINARY, '-r', "copy('https://getcomposer.org/installer', '" . $setupPath . "');"], $log, 60);
        $expected = trim((string)$signatureResult['stdout']);
        $actual = is_file($setupPath) ? hash_file('sha384', $setupPath) : '';
        $log[] = 'Composer installer checksum: ' . ($expected !== '' && hash_equals($expected, $actual) ? 'verified' : 'failed');
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Checksum verification failed.');
        }
        serverToolsRunOperationCommand([PHP_BINARY, $setupPath, '--install-dir=/usr/local/bin', '--filename=composer'], $log, 120);
    } finally {
        @unlink($setupPath);
    }
}

function serverToolsUpdateComposer(array &$log): void
{
    serverToolsRunOperationCommand(['composer', 'self-update', '--stable'], $log, 180);
}

function serverToolsInstallCodex(array &$log): void
{
    $diagnostics = serverToolsDiagnostics();
    if (empty($diagnostics['tools']['node']['available_to_service_user']) || empty($diagnostics['tools']['npm']['available_to_service_user'])) {
        throw new RuntimeException('Install Node.js and npm before installing Codex CLI.');
    }
    serverToolsRunOperationCommand(['npm', 'install', '-g', '@openai/codex'], $log, 240);
}

function serverToolsRunManagedAction(string $toolId, string $toolAction): array
{
    $diagnosticsBefore = serverToolsDiagnostics();
    if (!isset(serverToolsDefinitions()[$toolId])) {
        return ['success' => false, 'message' => 'Unsupported server tool.', 'action' => 'server_tool_action', 'output' => ''];
    }
    if (!serverToolsActionPermitted($toolId, $toolAction, $diagnosticsBefore)) {
        return ['success' => false, 'message' => 'Action is not available for this tool state.', 'action' => 'server_tool_action', 'output' => ''];
    }
    $toolName = (string)$diagnosticsBefore['tools'][$toolId]['display_name'];
    if ($toolAction === 'refresh') {
        $result = serverToolsRefreshResult();
        $result['action'] = 'server_tool_action';
        $result['message'] = 'Diagnostics refreshed for ' . $toolName . '.';
        $result['summary_steps'] = [$toolName . ' diagnostics refreshed'];
        serverToolsAppendActivityLog([
            'timestamp' => date('c'),
            'tool' => $toolName,
            'requested_action' => $toolAction,
            'executed_commands' => [],
            'result' => 'success',
            'installed_version' => (string)($result['diagnostics']['tools'][$toolId]['version'] ?? ''),
        ]);

        return $result;
    }

    $log = ['[' . date('c') . '] ' . serverToolsActionLabel($toolAction) . ' requested for ' . $toolName . '.'];
    $success = false;
    $message = $toolName . ' action failed.';
    try {
        if ($toolId === 'node') {
            serverToolsInstallNode($toolAction, $log);
        } elseif ($toolId === 'composer') {
            $toolAction === 'update' ? serverToolsUpdateComposer($log) : serverToolsInstallComposer($log);
        } elseif ($toolId === 'codex') {
            serverToolsInstallCodex($log);
        } else {
            throw new RuntimeException('Action is diagnostics-only for this tool.');
        }
        $success = true;
        $message = $toolName . ' ' . serverToolsActionLabel($toolAction) . ' completed.';
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $log[] = 'Error: ' . $message;
    }

    $diagnosticsAfter = serverToolsDiagnostics();
    $installedVersion = (string)($diagnosticsAfter['tools'][$toolId]['version'] ?? '');
    $log[] = 'Installed version: ' . ($installedVersion === '' ? 'Not detected' : $installedVersion);
    $entry = [
        'timestamp' => date('c'),
        'tool' => $toolName,
        'requested_action' => $toolAction,
        'executed_commands' => serverToolsCommandsFromLog($log),
        'result' => $success ? 'success' : 'failure',
        'installed_version' => $installedVersion,
    ];
    serverToolsAppendActivityLog($entry + ['log' => implode("\n", $log)]);

    return [
        'success' => $success,
        'message' => $message,
        'action' => 'server_tool_action',
        'output' => implode("\n", $log),
        'summary_steps' => $success ? [$toolName . ' ' . strtolower(serverToolsActionLabel($toolAction)) . ' completed', 'Diagnostics refreshed'] : [],
        'diagnostics' => $diagnosticsAfter,
    ];
}

function serverToolsDashboardSoftware(): array
{
    $diagnostics = serverToolsDiagnostics(false);
    $software = [];
    foreach ($diagnostics['tools'] as $tool) {
        $software[(string)$tool['display_name']] = !empty($tool['installed']) && (string)$tool['version'] !== ''
            ? (string)$tool['version']
            : (string)$tool['diagnostic_status'];
    }

    return $software;
}

function serverToolsRefreshResult(): array
{
    $diagnostics = serverToolsDiagnostics();
    return [
        'success' => true,
        'message' => 'Server diagnostics refreshed.',
        'action' => 'refresh_server_diagnostics',
        'output' => (string)$diagnostics['log'],
        'summary_steps' => ['Server context refreshed', count($diagnostics['tools']) . ' tools checked'],
        'diagnostics' => $diagnostics,
    ];
}
