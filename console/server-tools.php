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

function serverToolsDetectTool(array $definition, array $context): array
{
    $path = (string)($context['path'] ?? serverToolsDefaultPath());
    $executable = serverToolsFindExecutable((string)$definition['command'], $path);
    $checkedAt = date('c');
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

    return [
        'display_name' => (string)$definition['display_name'],
        'purpose' => (string)$definition['purpose'],
        'installed' => true,
        'version' => $version,
        'executable_path' => $executable,
        'available_to_service_user' => $available,
        'diagnostic_status' => $status,
        'last_checked_at' => $checkedAt,
        'package_source' => (string)$definition['source'],
        'dependency_relationship' => (string)$definition['dependency'],
        'requirement' => (string)$definition['requirement'],
        'required_group' => (string)$definition['required_group'],
        'log' => trim((string)$definition['display_name'] . ': ' . $result['command'] . ' -> ' . $status . ($version !== '' ? ' (' . $version . ')' : '')),
    ];
}

function serverToolsDiagnostics(): array
{
    $context = serverToolsServiceContext();
    $tools = [];
    $log = ['Server context: ' . (string)$context['status']];
    foreach (serverToolsDefinitions() as $id => $definition) {
        try {
            $tools[$id] = serverToolsDetectTool($definition, $context);
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

function serverToolsDashboardSoftware(): array
{
    $diagnostics = serverToolsDiagnostics();
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
