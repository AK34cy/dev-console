<?php

const DEV_CONSOLE_SERVICE_NAME = 'iovon-dev-console.service';
const DEV_CONSOLE_SERVER_TOOL_RUNTIME_DIR = __DIR__ . '/runtime/server-tool-operations';
const DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE = __DIR__ . '/runtime/host-diagnostics.json';
const DEV_CONSOLE_CODEX_AUTH_TIMEOUT_SECONDS = 900;

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
            'source' => 'OpenAI standalone installer or administrator-managed CLI',
        ],
        'node' => [
            'display_name' => 'Node.js',
            'purpose' => 'Project-dependent',
            'command' => 'node',
            'version_arguments' => ['node', '--version'],
            'version_pattern' => '/^v?(.+)/',
            'required_group' => 'optional',
            'requirement' => 'Project-dependent',
            'dependency' => 'May be required by JavaScript Projects',
            'source' => 'System package, NodeSource, nvm, or administrator-managed binary',
        ],
        'npm' => [
            'display_name' => 'npm',
            'purpose' => 'Project-dependent',
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

function serverToolsUserHome(string $user): string
{
    if ($user === 'root') {
        return '/root';
    }
    if ($user !== '' && function_exists('posix_getpwnam')) {
        $info = @posix_getpwnam($user);
        if (is_array($info) && is_scalar($info['dir'] ?? null) && (string)$info['dir'] !== '') {
            return (string)$info['dir'];
        }
    }
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = @posix_getpwuid(posix_geteuid());
        if (is_array($info) && is_scalar($info['dir'] ?? null) && (string)$info['dir'] !== '') {
            return (string)$info['dir'];
        }
    }

    return '';
}

function serverToolsComposerEnvironment(?array $context = null, bool $createHome = true): array
{
    $context = $context ?? serverToolsServiceContext();
    $user = (string)($context['user'] ?? '');
    $home = serverToolsUserHome($user);
    if ($home === '') {
        throw new RuntimeException('Unable to resolve Composer home directory for the service user.');
    }
    $composerHome = rtrim($home, '/') . '/.composer';
    if ($createHome && !is_dir($composerHome) && !@mkdir($composerHome, 0700, true) && !is_dir($composerHome)) {
        throw new RuntimeException('Unable to create Composer home directory.');
    }

    $environment = [
        'PATH' => (string)($context['path'] ?? serverToolsDefaultPath()),
        'HOME' => $home,
        'COMPOSER_HOME' => $composerHome,
    ];
    if ($user === 'root') {
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
    }

    return $environment;
}

function serverToolsCodexStandalonePath(?array $context = null): string
{
    $context = $context ?? serverToolsServiceContext();
    $home = serverToolsUserHome((string)($context['user'] ?? ''));
    if ($home === '') {
        return '';
    }

    $candidate = rtrim($home, '/') . '/.codex/packages/standalone/current/codex';
    return is_file($candidate) && is_executable($candidate) ? $candidate : '';
}

function serverToolsCodexEnvironment(?array $context = null): array
{
    $context = $context ?? serverToolsServiceContext();
    $user = (string)($context['user'] ?? '');
    $home = serverToolsUserHome($user);
    if ($home === '') {
        throw new RuntimeException('Unable to resolve Codex home directory for the service user.');
    }

    $binDir = rtrim($home, '/') . '/.local/bin';
    $path = (string)($context['path'] ?? serverToolsDefaultPath());
    if (!str_contains(':' . $path . ':', ':' . $binDir . ':')) {
        $path = $binDir . ':' . $path;
    }

    return [
        'PATH' => $path,
        'HOME' => $home,
        'CODEX_HOME' => rtrim($home, '/') . '/.codex',
        'CODEX_NON_INTERACTIVE' => 'true',
    ];
}

function serverToolsResolveCodexCommand(?array $context = null): string
{
    $context = $context ?? serverToolsServiceContext();
    $standalone = serverToolsCodexStandalonePath($context);
    if ($standalone !== '') {
        return $standalone;
    }

    return serverToolsFindExecutable('codex', (string)($context['path'] ?? serverToolsDefaultPath()));
}

function serverToolsCodexAppArmorHelperPath(): string
{
    return dirname(__DIR__) . '/bin/configure-codex-apparmor';
}

function serverToolsCodexAppArmorCommand(array $context, bool $requireBwrap): array
{
    $helper = serverToolsCodexAppArmorHelperPath();
    if (!is_file($helper) || !is_executable($helper)) {
        throw new RuntimeException('Codex AppArmor helper is not installed.');
    }

    $user = (string)($context['user'] ?? '');
    if ($user === '' || $user === 'Diagnostic unavailable') {
        throw new RuntimeException('Unable to determine Dev Console service user for Codex AppArmor setup.');
    }

    $arguments = [$helper, '--user', $user];
    if ($requireBwrap) {
        $arguments[] = '--require-bwrap';
    }

    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        return $arguments;
    }

    $sudo = serverToolsFindExecutable('sudo', (string)($context['path'] ?? serverToolsDefaultPath()));
    if ($sudo === '') {
        throw new RuntimeException('sudo is required to configure the Codex AppArmor sandbox profile.');
    }

    return array_merge([$sudo, '-n'], $arguments);
}

function serverToolsConfigureCodexAppArmor(array $context, array &$log, ?string $operationId = null, bool $requireBwrap = true): void
{
    serverToolsSetOperationStage($operationId, 'Configuring Codex AppArmor sandbox support');
    $arguments = serverToolsCodexAppArmorCommand($context, $requireBwrap);
    try {
        serverToolsRunOperationCommand($arguments, $log, 30, [], $operationId, 'Configuring AppArmor');
    } catch (RuntimeException $exception) {
        throw new RuntimeException(
            'Codex CLI was installed, but Dev Console could not configure Ubuntu AppArmor support for the Codex sandbox. '
            . 'Run sudo ./install.sh from the Dev Console checkout after Codex is installed, or configure passwordless sudo for the service user. '
            . $exception->getMessage()
        );
    }
}

function serverToolsCodexAuthStatus(?array $context = null, string $codexPath = ''): array
{
    $context = $context ?? serverToolsServiceContext();
    $codexPath = $codexPath !== '' ? $codexPath : serverToolsResolveCodexCommand($context);
    if ($codexPath === '' || !is_file($codexPath) || !is_executable($codexPath)) {
        return [
            'state' => 'not_installed',
            'label' => 'CLI not installed',
            'message' => 'Install Codex CLI before signing in.',
        ];
    }

    try {
        $environment = serverToolsCodexEnvironment($context);
    } catch (Throwable $exception) {
        return [
            'state' => 'unknown',
            'label' => 'Status could not be determined',
            'message' => $exception->getMessage(),
        ];
    }

    $doctor = serverToolsRunDiagnosticCommand([$codexPath, 'doctor', '--json'], 8, $environment, false);
    $decoded = json_decode((string)$doctor['stdout'], true);
    $auth = is_array($decoded) && is_array($decoded['checks']['auth.credentials'] ?? null)
        ? $decoded['checks']['auth.credentials']
        : [];
    $authState = strtolower((string)($auth['status'] ?? ''));
    $summary = (string)($auth['summary'] ?? '');
    if ($authState === 'ok') {
        return [
            'state' => 'authenticated',
            'label' => 'Authenticated',
            'message' => $summary !== '' ? $summary : 'Signed in with ChatGPT.',
        ];
    }
    if (in_array($authState, ['failed', 'fail', 'error', 'warning'], true)) {
        return [
            'state' => 'not_authenticated',
            'label' => 'Not authenticated',
            'message' => $summary !== '' ? $summary : 'Sign in with ChatGPT before running Codex tasks.',
        ];
    }

    $status = serverToolsRunDiagnosticCommand([$codexPath, 'login', 'status'], 8, $environment, false);
    $statusOutput = trim((string)($status['output'] ?? ''));
    if (!empty($status['success']) && stripos($statusOutput, 'logged in') !== false) {
        return [
            'state' => 'authenticated',
            'label' => 'Authenticated',
            'message' => 'Logged in using ChatGPT.',
        ];
    }
    if (!empty($status['success']) && stripos($statusOutput, 'not logged') !== false) {
        return [
            'state' => 'not_authenticated',
            'label' => 'Not authenticated',
            'message' => 'Sign in with ChatGPT before running Codex tasks.',
        ];
    }

    return [
        'state' => 'unknown',
        'label' => 'Status could not be determined',
        'message' => $statusOutput !== '' ? $statusOutput : 'Codex authentication status could not be determined.',
    ];
}

function serverToolsClearCodexAuthCache(): void
{
    $stateDir = defined('DEPLOY_STATE_DIR') ? DEPLOY_STATE_DIR : '/tmp/iovon-deployments';
    @unlink(rtrim($stateDir, '/') . '/codex-auth-status.json');
}

function serverToolsRunDiagnosticCommand(array $arguments, int $timeoutSeconds = 5, array $environment = [], bool $inheritEnvironment = false): array
{
    return processRunCommand($arguments, [
        'timeout' => $timeoutSeconds,
        'env' => $environment + ['PATH' => getenv('PATH') ?: serverToolsDefaultPath()],
        'inherit_env' => $inheritEnvironment,
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
    $toolId = (string)($definition['id'] ?? '');
    $standaloneCodex = $toolId === 'codex' ? serverToolsCodexStandalonePath($context) : '';
    $executable = $standaloneCodex !== '' ? $standaloneCodex : serverToolsFindExecutable((string)$definition['command'], $path);
    $checkedAt = date('c');
    $packageSource = (string)$definition['source'];
    if ($toolId === 'codex' && $standaloneCodex !== '') {
        $packageSource = 'OpenAI standalone installer';
    } elseif ($toolId === 'codex' && $executable !== '') {
        $packageSource = 'Administrator-managed CLI';
    }
    $codexAuth = $toolId === 'codex'
        ? serverToolsCodexAuthStatus($context, $executable)
        : ['state' => 'not_applicable', 'label' => '', 'message' => ''];
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
            'package_source' => $packageSource,
            'dependency_relationship' => (string)$definition['dependency'],
            'requirement' => (string)$definition['requirement'],
            'required_group' => (string)$definition['required_group'],
            'auth_state' => (string)$codexAuth['state'],
            'auth_label' => (string)$codexAuth['label'],
            'auth_message' => (string)$codexAuth['message'],
            'log' => (string)$definition['display_name'] . ': executable not found in PATH.',
        ];
    }

    $arguments = $definition['version_arguments'];
    if ((string)$definition['command'] === PHP_BINARY) {
        $arguments = [$executable, '--version'];
    } elseif ($toolId === 'codex') {
        $arguments = [$executable, '--version'];
    }
    try {
        if ($toolId === 'composer') {
            $result = serverToolsRunDiagnosticCommand([$executable, '--version'], 5, serverToolsComposerEnvironment($context), false);
        } elseif ($toolId === 'codex') {
            $result = serverToolsRunDiagnosticCommand($arguments, 5, serverToolsCodexEnvironment($context), false);
        } else {
            $result = serverToolsRunDiagnosticCommand($arguments, 5);
        }
    } catch (Throwable $exception) {
        return [
            'display_name' => (string)$definition['display_name'],
            'purpose' => (string)$definition['purpose'],
            'installed' => true,
            'version' => '',
            'executable_path' => $executable,
            'available_to_service_user' => false,
            'diagnostic_status' => 'Version check failed',
            'last_checked_at' => $checkedAt,
            'latest_version' => '',
            'outdated' => false,
            'package_source' => $packageSource,
            'dependency_relationship' => (string)$definition['dependency'],
            'requirement' => (string)$definition['requirement'],
            'required_group' => (string)$definition['required_group'],
            'auth_state' => (string)$codexAuth['state'],
            'auth_label' => (string)$codexAuth['label'],
            'auth_message' => (string)$codexAuth['message'],
            'log' => (string)$definition['display_name'] . ': ' . $exception->getMessage(),
        ];
    }
    $available = !empty($result['success']);
    $version = $available ? serverToolsParseVersion((string)$result['stdout'], (string)$definition['version_pattern']) : '';
    if ($toolId === 'codex' && $version !== '') {
        $version = serverToolsNormalizeVersion($version);
    }
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
        'package_source' => $packageSource,
        'dependency_relationship' => (string)$definition['dependency'],
        'requirement' => (string)$definition['requirement'],
        'required_group' => (string)$definition['required_group'],
        'auth_state' => (string)$codexAuth['state'],
        'auth_label' => (string)$codexAuth['label'],
        'auth_message' => (string)$codexAuth['message'],
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

    $diagnostics = [
        'context' => $context,
        'tools' => $tools,
        'generated_at' => date('c'),
        'log' => implode("\n", $log),
    ];

    if ($includeLatest) {
        serverToolsPersistDiagnostics($diagnostics);
    }

    return $diagnostics;
}

function serverToolsNormalizeDiagnosticsForCache(array $diagnostics): array
{
    $context = is_array($diagnostics['context'] ?? null) ? $diagnostics['context'] : [];
    $diagnosticTools = is_array($diagnostics['tools'] ?? null) ? $diagnostics['tools'] : [];
    $tools = [];
    foreach (serverToolsDefinitions() as $id => $definition) {
        $tool = is_array($diagnosticTools[$id] ?? null) ? $diagnosticTools[$id] : [];
        $tools[(string)$id] = [
            'display_name' => (string)($tool['display_name'] ?? $definition['display_name']),
            'purpose' => (string)($tool['purpose'] ?? $definition['purpose']),
            'installed' => !empty($tool['installed']),
            'version' => (string)($tool['version'] ?? ''),
            'executable_path' => (string)($tool['executable_path'] ?? ''),
            'available_to_service_user' => !empty($tool['available_to_service_user']),
            'diagnostic_status' => (string)($tool['diagnostic_status'] ?? ''),
            'last_checked_at' => (string)($tool['last_checked_at'] ?? ''),
            'latest_version' => (string)($tool['latest_version'] ?? ''),
            'outdated' => !empty($tool['outdated']),
            'package_source' => (string)($tool['package_source'] ?? $definition['source']),
            'dependency_relationship' => (string)($tool['dependency_relationship'] ?? $definition['dependency']),
            'requirement' => (string)($tool['requirement'] ?? $definition['requirement']),
            'required_group' => (string)($tool['required_group'] ?? $definition['required_group']),
            'auth_state' => (string)($tool['auth_state'] ?? ''),
            'auth_label' => (string)($tool['auth_label'] ?? ''),
            'auth_message' => (string)($tool['auth_message'] ?? ''),
            'log' => (string)($tool['display_name'] ?? $definition['display_name']) . ': cached last-known diagnostic state.',
        ];
    }

    return [
        'context' => [
            'service_name' => (string)($context['service_name'] ?? DEV_CONSOLE_SERVICE_NAME),
            'user' => (string)($context['user'] ?? ''),
            'group' => (string)($context['group'] ?? ''),
            'path' => (string)($context['path'] ?? ''),
            'working_directory' => (string)($context['working_directory'] ?? ''),
            'php_executable' => (string)($context['php_executable'] ?? PHP_BINARY),
            'status' => (string)($context['status'] ?? ''),
        ],
        'tools' => $tools,
        'generated_at' => (string)($diagnostics['generated_at'] ?? date('c')),
        'cached' => true,
        'log' => 'Loaded last-known Dev Console host diagnostics.',
    ];
}

function serverToolsPersistDiagnostics(array $diagnostics): void
{
    $normalized = serverToolsNormalizeDiagnosticsForCache($diagnostics);
    $directory = dirname(DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return;
    }
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }
    $temporaryPath = $directory . '/.' . basename(DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE) . '.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        return;
    }
    @chmod($temporaryPath, 0600);
    @rename($temporaryPath, DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE);
    if (is_file($temporaryPath)) {
        @unlink($temporaryPath);
    }
}

function serverToolsLoadPersistedDiagnostics(): array
{
    if (!is_file(DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE) || !is_readable(DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE)) {
        return [];
    }
    $decoded = json_decode((string)@file_get_contents(DEV_CONSOLE_HOST_DIAGNOSTICS_CACHE), true);
    if (!is_array($decoded)) {
        return [];
    }

    return serverToolsNormalizeDiagnosticsForCache($decoded);
}

function serverToolsNormalizeVersion(string $version): string
{
    $version = trim($version);
    if (str_starts_with($version, 'rust-v')) {
        $version = substr($version, 7);
    } elseif (str_starts_with($version, 'v')) {
        $version = substr($version, 1);
    }
    if (str_starts_with($version, '.')) {
        $version = '0' . $version;
    }

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
    if ($toolId === 'npm') {
        $latest = serverToolsRunDiagnosticCommand(['npm', 'view', 'npm', 'version'], 15);
        return !empty($latest['success']) ? trim((string)$latest['stdout']) : '';
    }
    if ($toolId === 'codex') {
        $latest = serverToolsRunDiagnosticCommand([PHP_BINARY, '-r', <<<'PHP'
$context = stream_context_create(["http" => ["timeout" => 15, "ignore_errors" => true]]);
$json = @file_get_contents("https://releases.openai.com/codex/channels/latest", false, $context);
if ($json === false) {
    exit(1);
}
$decoded = json_decode($json, true);
if (!is_array($decoded)) {
    exit(1);
}
$tag = (string)($decoded["tag_name"] ?? "");
        if (str_starts_with($tag, "rust-v")) {
            $tag = substr($tag, 7);
        } elseif (str_starts_with($tag, "v")) {
            $tag = substr($tag, 1);
        }
echo $tag;
PHP], 20);
        return !empty($latest['success']) ? serverToolsNormalizeVersion((string)$latest['stdout']) : '';
    }
    if ($toolId === 'composer') {
        try {
            $latest = serverToolsRunDiagnosticCommand([PHP_BINARY, '-r', "echo json_decode(file_get_contents('https://getcomposer.org/versions'), true)['stable'][0]['version'] ?? '';"], 15, serverToolsComposerEnvironment(), false);
            return !empty($latest['success']) ? trim((string)$latest['stdout']) : '';
        } catch (Throwable) {
            return '';
        }
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
    if ($toolId === 'codex') {
        $actions = [];
        if ((string)($tool['auth_state'] ?? '') === 'not_authenticated') {
            $actions[] = 'sign_in_chatgpt';
        }
        if (!empty($tool['outdated'])) {
            $actions[] = 'update';
        }
        $actions[] = 'refresh';

        return array_values(array_unique($actions));
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
        'sign_in_chatgpt' => 'Sign in with ChatGPT',
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

function serverToolsRuntimeDirectory(): string
{
    if (!is_dir(DEV_CONSOLE_SERVER_TOOL_RUNTIME_DIR)) {
        @mkdir(DEV_CONSOLE_SERVER_TOOL_RUNTIME_DIR, 0700, true);
    }

    return DEV_CONSOLE_SERVER_TOOL_RUNTIME_DIR;
}

function serverToolsValidateOperationId(string $operationId): bool
{
    return preg_match('/^sto_[a-f0-9]{32}$/', $operationId) === 1;
}

function serverToolsOperationPath(string $operationId, string $extension): string
{
    if (!serverToolsValidateOperationId($operationId) || !in_array($extension, ['json', 'log', 'lock'], true)) {
        throw new RuntimeException('Invalid server tool operation ID.');
    }

    $directory = serverToolsRuntimeDirectory();
    $path = $directory . '/' . $operationId . '.' . $extension;
    $realDirectory = realpath($directory);
    $realParent = realpath(dirname($path)) ?: $directory;
    if ($realDirectory === false || $realParent !== $realDirectory) {
        throw new RuntimeException('Invalid server tool operation path.');
    }

    return $path;
}

function serverToolsReadOperation(string $operationId): array
{
    $path = serverToolsOperationPath($operationId, 'json');
    $data = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;

    return is_array($data) ? $data : [];
}

function serverToolsWriteOperation(array $state): void
{
    $operationId = (string)($state['id'] ?? '');
    $state['updated_at'] = date('c');
    $path = serverToolsOperationPath($operationId, 'json');
    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || @file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write server tool operation state.');
    }
}

function serverToolsAppendOperationLog(string $operationId, string $message): void
{
    if ($operationId === '') {
        return;
    }
    $path = serverToolsOperationPath($operationId, 'log');
    @file_put_contents($path, rtrim($message) . "\n", FILE_APPEND | LOCK_EX);
}

function serverToolsOperationLog(string $operationId): string
{
    $path = serverToolsOperationPath($operationId, 'log');

    return is_file($path) ? (string)@file_get_contents($path) : '';
}

function serverToolsSetOperationStage(?string $operationId, string $stage, string $status = 'running'): void
{
    if ($operationId === null || $operationId === '') {
        return;
    }
    $state = serverToolsReadOperation($operationId);
    if (empty($state)) {
        return;
    }
    $state['stage'] = $stage;
    $state['status'] = $status;
    serverToolsWriteOperation($state);
    serverToolsAppendOperationLog($operationId, '[' . date('c') . '] ' . $stage);
}

function serverToolsPidRunning(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
        return true;
    }

    return is_dir('/proc/' . $pid);
}

function serverToolsOperationStale(array $state, int $seconds = 600): bool
{
    $updatedAt = strtotime((string)($state['updated_at'] ?? '')) ?: strtotime((string)($state['started_at'] ?? '')) ?: time();

    return time() - $updatedAt > $seconds;
}

function serverToolsActiveOperationForTool(string $toolId): array
{
    foreach (glob(serverToolsRuntimeDirectory() . '/*.json') ?: [] as $path) {
        $data = json_decode((string)@file_get_contents($path), true);
        if (!is_array($data) || (string)($data['tool_id'] ?? '') !== $toolId) {
            continue;
        }
        if (!in_array((string)($data['status'] ?? ''), ['starting', 'running'], true)) {
            continue;
        }
        $pid = (int)($data['pid'] ?? 0);
        if ($pid === 0 || serverToolsPidRunning($pid) || !serverToolsOperationStale($data)) {
            return $data;
        }
        $data['status'] = 'failed';
        $data['stage'] = 'Failed';
        $data['finished_at'] = date('c');
        $data['message'] = 'Server tool worker stopped unexpectedly.';
        serverToolsWriteOperation($data);
    }

    return [];
}

function serverToolsOperationStatus(string $operationId): array
{
    $state = serverToolsReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Server tool operation not found.');
    }
    if (in_array((string)($state['status'] ?? ''), ['starting', 'running'], true)) {
        $pid = (int)($state['pid'] ?? 0);
        if ($pid > 0 && !serverToolsPidRunning($pid) && serverToolsOperationStale($state)) {
            $state['status'] = 'failed';
            $state['stage'] = 'Failed';
            $state['finished_at'] = date('c');
            $state['message'] = 'Server tool worker stopped unexpectedly.';
            $state['result'] = [
                'success' => false,
                'message' => $state['message'],
                'action' => 'server_tool_action',
                'output' => serverToolsOperationLog($operationId),
                'summary_steps' => [],
            ];
            serverToolsWriteOperation($state);
            serverToolsAppendOperationLog($operationId, '[' . date('c') . '] Error: ' . $state['message']);
        }
    }
    $state['log'] = serverToolsOperationLog($operationId);
    $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
    $finishedAt = strtotime((string)($state['finished_at'] ?? '')) ?: time();
    $state['elapsed_seconds'] = max(0, $finishedAt - $startedAt);

    return $state;
}

function serverToolsStartOperation(string $toolId, string $toolAction): array
{
    $diagnostics = serverToolsDiagnostics();
    if (!isset(serverToolsDefinitions()[$toolId])) {
        throw new RuntimeException('Unsupported server tool.');
    }
    if (!serverToolsActionPermitted($toolId, $toolAction, $diagnostics)) {
        throw new RuntimeException('Action is not available for this tool state.');
    }
    $active = serverToolsActiveOperationForTool($toolId);
    if (!empty($active)) {
        throw new RuntimeException('An operation is already running for this tool.');
    }

    $operationId = 'sto_' . bin2hex(random_bytes(16));
    $toolName = (string)$diagnostics['tools'][$toolId]['display_name'];
    $state = [
        'id' => $operationId,
        'tool_id' => $toolId,
        'tool_name' => $toolName,
        'tool_action' => $toolAction,
        'action_label' => serverToolsActionLabel($toolAction),
        'status' => 'starting',
        'stage' => 'Starting',
        'started_at' => date('c'),
        'updated_at' => date('c'),
        'finished_at' => '',
        'pid' => 0,
        'message' => serverToolsActionLabel($toolAction) . ' requested for ' . $toolName . '.',
        'summary_steps' => [],
        'result' => null,
    ];
    serverToolsWriteOperation($state);
    @file_put_contents(serverToolsOperationPath($operationId, 'log'), '[' . date('c') . '] Starting ' . serverToolsActionLabel($toolAction) . ' for ' . $toolName . ".\n", LOCK_EX);

    $worker = __DIR__ . '/run-server-tool.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($operationId) . ' >/dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($command));
    if ($pid <= 0) {
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['finished_at'] = date('c');
        $state['message'] = 'Unable to start server tool worker.';
        serverToolsWriteOperation($state);
        throw new RuntimeException('Unable to start server tool worker.');
    }
    $state['pid'] = $pid;
    serverToolsWriteOperation($state);

    return $state;
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

function serverToolsRunOperationCommand(array $arguments, array &$log, int $timeoutSeconds = 120, array $environment = [], ?string $operationId = null, string $stage = ''): array
{
    if ($stage !== '') {
        serverToolsSetOperationStage($operationId, $stage);
    }
    serverToolsAppendOperationLog($operationId ?? '', '$ ' . processCommandDisplay($arguments));
    $result = serverToolsRunDiagnosticCommand($arguments, $timeoutSeconds, $environment, false);
    $log[] = '$ ' . $result['command'];
    if ((string)$result['output'] !== '') {
        $log[] = (string)$result['output'];
        serverToolsAppendOperationLog($operationId ?? '', (string)$result['output']);
    }
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    serverToolsAppendOperationLog($operationId ?? '', 'Exit code: ' . (string)$result['exit_code']);
    if (empty($result['success'])) {
        throw new RuntimeException(serverToolsReadableCommandError($result));
    }

    return $result;
}

function serverToolsSanitizeCodexAuthOutput(string $output): string
{
    $output = preg_replace('/\x1B(?:[@-Z\\\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $output) ?? $output;
    $output = preg_replace('#\b(Bearer)\s+[A-Za-z0-9._~+/=-]+#i', '$1 [redacted]', $output) ?? $output;
    $output = preg_replace('~\b(access[_-]?token|refresh[_-]?token|id[_-]?token|session[_-]?token|api[_-]?key)(\s*[:=]\s*)[^\s,;}]+~i', '$1$2[redacted]', $output) ?? $output;
    $output = preg_replace('~(Authorization:\s*(?:Bearer|token|Basic)\s+)[^\r\n]+~i', '$1[redacted]', $output) ?? $output;

    return $output;
}

function serverToolsParseCodexDeviceAuthOutput(string $output): array
{
    $clean = serverToolsSanitizeCodexAuthOutput($output);
    $result = [
        'url' => '',
        'code' => '',
        'state' => 'waiting',
        'message' => 'Waiting for authorization...',
    ];

    if (preg_match_all('~https?://[^\s<>"\'\]\)]+~i', $clean, $matches) > 0) {
        foreach ($matches[0] as $candidate) {
            $candidate = rtrim($candidate, ".,;");
            $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
            if ($host === '' || !preg_match('/(^|\.)((openai\.com)|(chatgpt\.com))$/', $host)) {
                continue;
            }
            $result['url'] = $candidate;
            break;
        }
    }

    $expectCode = false;
    foreach (preg_split('/\R/', $clean) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_contains($line, '://')) {
            continue;
        }
        if (preg_match('/\b(?:one-time\s+code|device\s+code|user\s+code|enter\s+(?:this\s+)?code|verification\s+code)\b/i', $line) === 1) {
            $expectCode = true;
        }
        if (preg_match('/(?:code|enter|verification)[^A-Z0-9]*([A-Z0-9][A-Z0-9-]{5,24})\b/i', $line, $matches) === 1 && serverToolsLooksLikeCodexDeviceCode($matches[1])) {
            $result['code'] = strtoupper($matches[1]);
            break;
        }
        if ($expectCode && preg_match('/^([A-Z0-9][A-Z0-9-]{5,24})$/i', $line, $matches) === 1 && serverToolsLooksLikeCodexDeviceCode($matches[1])) {
            $result['code'] = strtoupper($matches[1]);
            break;
        }
    }

    $lower = strtolower($clean);
    if (str_contains($lower, 'authenticated') || str_contains($lower, 'logged in') || str_contains($lower, 'success')) {
        $result['state'] = 'completed';
        $result['message'] = 'Authentication completed.';
    } elseif (str_contains($lower, 'timed out') || str_contains($lower, 'expired')) {
        $result['state'] = 'failed';
        $result['message'] = 'ChatGPT sign-in timed out.';
    } elseif (str_contains($lower, 'error') || str_contains($lower, 'failed')) {
        $result['state'] = 'failed';
        $result['message'] = 'ChatGPT sign-in failed.';
    }

    return $result;
}

function serverToolsLooksLikeCodexDeviceCode(string $value): bool
{
    $value = strtoupper(trim($value));
    if (preg_match('/^[A-Z0-9][A-Z0-9-]{5,24}$/', $value) !== 1) {
        return false;
    }

    return str_contains($value, '-') || preg_match('/\d/', $value) === 1;
}

function serverToolsUpdateCodexDeviceAuthState(?string $operationId, string $output): void
{
    if ($operationId === null || $operationId === '') {
        return;
    }
    $state = serverToolsReadOperation($operationId);
    if (empty($state) || (string)($state['tool_action'] ?? '') !== 'sign_in_chatgpt') {
        return;
    }
    $state['device_auth'] = serverToolsParseCodexDeviceAuthOutput($output);
    serverToolsWriteOperation($state);
}

function serverToolsMarkCodexDeviceAuthFailed(?string $operationId, string $message): void
{
    if ($operationId === null || $operationId === '') {
        return;
    }
    $state = serverToolsReadOperation($operationId);
    if (empty($state) || (string)($state['tool_action'] ?? '') !== 'sign_in_chatgpt') {
        return;
    }
    $deviceAuth = is_array($state['device_auth'] ?? null) ? $state['device_auth'] : [];
    $deviceAuth['state'] = 'failed';
    $deviceAuth['message'] = $message;
    $state['device_auth'] = $deviceAuth;
    serverToolsWriteOperation($state);
}

function serverToolsRunStreamingOperationCommand(array $arguments, array &$log, int $timeoutSeconds, array $environment, ?string $operationId, string $stage): array
{
    if ($stage !== '') {
        serverToolsSetOperationStage($operationId, $stage);
    }
    $startedAt = microtime(true);
    $commandDisplay = processCommandDisplay($arguments);
    $log[] = '$ ' . $commandDisplay;
    serverToolsAppendOperationLog($operationId ?? '', '$ ' . $commandDisplay);

    $pipes = [];
    $normalizedEnvironment = processNormalizeEnvironment($environment, false);
    $process = @proc_open(array_values(array_map('strval', $arguments)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $normalizedEnvironment);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, $timeoutSeconds);
    $timedOut = false;
    $observedExitCode = null;

    while (true) {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $target) {
            $chunk = stream_get_contents($pipes[$index]) ?: '';
            if ($chunk === '') {
                continue;
            }
            if ($target === 'stdout') {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
            $safeChunk = serverToolsSanitizeCodexAuthOutput(processRedactSensitiveOutput($chunk, $normalizedEnvironment ?? []));
            serverToolsAppendOperationLog($operationId ?? '', $safeChunk);
            serverToolsUpdateCodexDeviceAuthState($operationId, $stdout . "\n" . $stderr);
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            if (isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $observedExitCode = (int)$status['exitcode'];
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            usleep(200000);
            $status = proc_get_status($process);
            if (!empty($status['running'])) {
                proc_terminate($process, 9);
            } elseif (isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $observedExitCode = (int)$status['exitcode'];
            }
            break;
        }
        usleep(100000);
    }

    $finalStdout = stream_get_contents($pipes[1]) ?: '';
    $finalStderr = stream_get_contents($pipes[2]) ?: '';
    if ($finalStdout !== '') {
        $stdout .= $finalStdout;
        serverToolsAppendOperationLog($operationId ?? '', serverToolsSanitizeCodexAuthOutput(processRedactSensitiveOutput($finalStdout, $normalizedEnvironment ?? [])));
    }
    if ($finalStderr !== '') {
        $stderr .= $finalStderr;
        serverToolsAppendOperationLog($operationId ?? '', serverToolsSanitizeCodexAuthOutput(processRedactSensitiveOutput($finalStderr, $normalizedEnvironment ?? [])));
    }
    serverToolsUpdateCodexDeviceAuthState($operationId, $stdout . "\n" . $stderr);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExitCode = proc_close($process);
    $exitCode = $closedExitCode >= 0 ? $closedExitCode : $observedExitCode;
    if ($timedOut) {
        $exitCode = 124;
        $stderr = trim($stderr . "\nCommand timed out.");
    } elseif ($exitCode === null) {
        $exitCode = 127;
        $stderr = trim($stderr . "\nUnable to determine command exit status.");
    }

    $stdout = serverToolsSanitizeCodexAuthOutput(processRedactSensitiveOutput($stdout, $normalizedEnvironment ?? []));
    $stderr = serverToolsSanitizeCodexAuthOutput(processRedactSensitiveOutput($stderr, $normalizedEnvironment ?? []));
    $result = processResult($commandDisplay, $stdout, $stderr, (int)$exitCode, $timedOut, $startedAt);
    if ((string)$result['output'] !== '') {
        $log[] = (string)$result['output'];
    }
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    serverToolsAppendOperationLog($operationId ?? '', 'Exit code: ' . (string)$result['exit_code']);
    if (empty($result['success'])) {
        $message = $timedOut ? 'Codex ChatGPT sign-in timed out before completion.' : serverToolsReadableCommandError($result);
        serverToolsMarkCodexDeviceAuthFailed($operationId, $message);
        throw new RuntimeException($message);
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

function serverToolsInstallNode(string $toolAction, array &$log, ?string $operationId = null): void
{
    serverToolsSetOperationStage($operationId, 'Checking prerequisites');
    serverToolsAssertSupportedOs();
    $setupPath = sys_get_temp_dir() . '/nodesource_setup_lts.sh';
    try {
        serverToolsRunOperationCommand(['apt-get', 'update'], $log, 180, [], $operationId, 'Preparing');
        serverToolsRunOperationCommand(['apt-get', 'install', '-y', 'ca-certificates', 'curl', 'gnupg'], $log, 180, [], $operationId, 'Checking prerequisites');
        serverToolsRunOperationCommand(['curl', '-fsSL', 'https://deb.nodesource.com/setup_lts.x', '-o', $setupPath], $log, 60, [], $operationId, 'Downloading');
        serverToolsRunOperationCommand(['bash', $setupPath], $log, 240, [], $operationId, 'Preparing');
        $installArguments = ['apt-get', 'install', '-y', 'nodejs'];
        if ($toolAction === 'reinstall') {
            $installArguments = ['apt-get', 'install', '--reinstall', '-y', 'nodejs'];
        }
        if ($toolAction === 'update') {
            $installArguments = ['apt-get', 'install', '--only-upgrade', '-y', 'nodejs'];
        }
        serverToolsRunOperationCommand($installArguments, $log, 240, [], $operationId, 'Installing');
    } finally {
        @unlink($setupPath);
    }
}

function serverToolsInstallComposer(array &$log, ?string $operationId = null): void
{
    serverToolsSetOperationStage($operationId, 'Checking prerequisites');
    $environment = serverToolsComposerEnvironment();
    $suffix = $operationId !== null && serverToolsValidateOperationId($operationId) ? $operationId : bin2hex(random_bytes(8));
    $setupPath = sys_get_temp_dir() . '/composer-setup-' . $suffix . '.php';
    $signaturePath = sys_get_temp_dir() . '/composer-setup-' . $suffix . '.sig';
    try {
        serverToolsRunOperationCommand([PHP_BINARY, '-r', "copy('https://composer.github.io/installer.sig', '" . $signaturePath . "');"], $log, 60, $environment, $operationId, 'Downloading');
        serverToolsRunOperationCommand([PHP_BINARY, '-r', "copy('https://getcomposer.org/installer', '" . $setupPath . "');"], $log, 60, $environment, $operationId, 'Downloading');
        $expected = is_file($signaturePath) ? trim((string)@file_get_contents($signaturePath)) : '';
        $actual = is_file($setupPath) ? hash_file('sha384', $setupPath) : '';
        $log[] = 'Composer installer checksum: ' . ($expected !== '' && hash_equals($expected, $actual) ? 'verified' : 'failed');
        serverToolsAppendOperationLog($operationId ?? '', 'Composer installer checksum: ' . ($expected !== '' && hash_equals($expected, $actual) ? 'verified' : 'failed'));
        if ($expected === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Checksum verification failed.');
        }
        serverToolsRunOperationCommand([PHP_BINARY, $setupPath, '--install-dir=/usr/local/bin', '--filename=composer'], $log, 120, $environment, $operationId, 'Installing');
        if (!is_file('/usr/local/bin/composer') || !is_executable('/usr/local/bin/composer')) {
            throw new RuntimeException('Composer installer did not create an executable /usr/local/bin/composer.');
        }
        serverToolsRunOperationCommand(['/usr/local/bin/composer', '--version'], $log, 60, $environment, $operationId, 'Verifying');
    } finally {
        @unlink($setupPath);
        @unlink($signaturePath);
    }
}

function serverToolsUpdateComposer(array &$log, ?string $operationId = null): void
{
    $environment = serverToolsComposerEnvironment();
    serverToolsRunOperationCommand(['composer', 'self-update', '--stable'], $log, 180, $environment, $operationId, 'Installing');
    serverToolsRunOperationCommand(['composer', '--version'], $log, 60, $environment, $operationId, 'Verifying');
}

function serverToolsInstallCodex(string $toolAction, array &$log, ?string $operationId = null): void
{
    serverToolsSetOperationStage($operationId, 'Checking prerequisites');
    $diagnostics = serverToolsDiagnostics();
    $context = is_array($diagnostics['context'] ?? null) ? $diagnostics['context'] : serverToolsServiceContext();
    $environment = serverToolsCodexEnvironment($context);
    $curl = serverToolsFindExecutable('curl', (string)$environment['PATH']);
    $wget = serverToolsFindExecutable('wget', (string)$environment['PATH']);
    if ($curl === '' && $wget === '') {
        throw new RuntimeException('Codex CLI standalone installer requires curl or wget on the Dev Console host.');
    }
    $expectedVersion = (string)($diagnostics['tools']['codex']['latest_version'] ?? '');
    $installerPath = sys_get_temp_dir() . '/codex-install-' . ($operationId !== null && serverToolsValidateOperationId($operationId) ? $operationId : bin2hex(random_bytes(8))) . '.sh';
    try {
        $downloadCommand = $curl !== ''
            ? [$curl, '-fsSL', 'https://chatgpt.com/codex/install.sh', '-o', $installerPath]
            : [$wget, '-q', '-O', $installerPath, 'https://chatgpt.com/codex/install.sh'];
        serverToolsRunOperationCommand($downloadCommand, $log, 60, $environment, $operationId, 'Downloading');
        @chmod($installerPath, 0700);
        serverToolsRunOperationCommand(['sh', $installerPath], $log, 600, $environment, $operationId, 'Installing');
    } finally {
        @unlink($installerPath);
    }
    serverToolsConfigureCodexAppArmor($context, $log, $operationId, true);
    $after = serverToolsDiagnostics(false);
    $installedVersion = (string)($after['tools']['codex']['version'] ?? '');
    if ($installedVersion === '') {
        throw new RuntimeException('Codex CLI operation completed, but the installed version could not be verified.');
    }
    if ($expectedVersion !== '' && version_compare(serverToolsNormalizeVersion($installedVersion), serverToolsNormalizeVersion($expectedVersion), '<')) {
        throw new RuntimeException('Codex CLI operation completed, but the installed version is still older than expected.');
    }
    $log[] = 'Codex CLI version verified: ' . $installedVersion;
    serverToolsAppendOperationLog($operationId ?? '', 'Codex CLI version verified: ' . $installedVersion);
}

function serverToolsAuthenticateCodexChatGpt(array &$log, ?string $operationId = null): void
{
    serverToolsSetOperationStage($operationId, 'Checking Codex CLI');
    $context = serverToolsServiceContext();
    $codex = serverToolsResolveCodexCommand($context);
    if ($codex === '') {
        throw new RuntimeException('Codex CLI is not installed on the Dev Console host.');
    }
    $environment = serverToolsCodexEnvironment($context);
    $status = serverToolsCodexAuthStatus($context, $codex);
    if ((string)($status['state'] ?? '') === 'authenticated') {
        $log[] = 'Codex CLI is already authenticated with ChatGPT.';
        serverToolsAppendOperationLog($operationId ?? '', 'Codex CLI is already authenticated with ChatGPT.');
        return;
    }

    $log[] = 'Starting official Codex ChatGPT device authentication.';
    $log[] = 'Open the URL/code shown by Codex CLI to complete sign-in.';
    serverToolsAppendOperationLog($operationId ?? '', 'Starting official Codex ChatGPT device authentication.');
    serverToolsAppendOperationLog($operationId ?? '', 'Open the URL/code shown by Codex CLI to complete sign-in.');
    serverToolsRunStreamingOperationCommand([$codex, 'login', '--device-auth'], $log, DEV_CONSOLE_CODEX_AUTH_TIMEOUT_SECONDS, $environment, $operationId, 'Waiting for ChatGPT sign-in');

    serverToolsSetOperationStage($operationId, 'Verifying authentication');
    $after = serverToolsCodexAuthStatus($context, $codex);
    if ((string)($after['state'] ?? '') !== 'authenticated') {
        throw new RuntimeException('Codex ChatGPT sign-in completed, but authentication could not be verified.');
    }
    serverToolsClearCodexAuthCache();
    if ($operationId !== null && $operationId !== '') {
        $state = serverToolsReadOperation($operationId);
        if (!empty($state)) {
            $deviceAuth = is_array($state['device_auth'] ?? null) ? $state['device_auth'] : [];
            $deviceAuth['state'] = 'completed';
            $deviceAuth['message'] = 'Authentication completed.';
            $state['device_auth'] = $deviceAuth;
            serverToolsWriteOperation($state);
        }
    }
    $log[] = 'Codex ChatGPT authentication verified.';
    serverToolsAppendOperationLog($operationId ?? '', 'Codex ChatGPT authentication verified.');
}

function serverToolsRunManagedAction(string $toolId, string $toolAction, ?string $operationId = null): array
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
        serverToolsSetOperationStage($operationId, 'Refreshing diagnostics');
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
    serverToolsAppendOperationLog($operationId ?? '', '[' . date('c') . '] ' . serverToolsActionLabel($toolAction) . ' requested for ' . $toolName . '.');
    $success = false;
    $message = $toolName . ' action failed.';
    try {
        if ($toolId === 'node') {
            serverToolsInstallNode($toolAction, $log, $operationId);
        } elseif ($toolId === 'composer') {
            $toolAction === 'update' ? serverToolsUpdateComposer($log, $operationId) : serverToolsInstallComposer($log, $operationId);
        } elseif ($toolId === 'codex') {
            $toolAction === 'sign_in_chatgpt'
                ? serverToolsAuthenticateCodexChatGpt($log, $operationId)
                : serverToolsInstallCodex($toolAction, $log, $operationId);
        } else {
            throw new RuntimeException('Action is diagnostics-only for this tool.');
        }
        $success = true;
        $message = $toolName . ' ' . serverToolsActionLabel($toolAction) . ' completed.';
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $log[] = 'Error: ' . $message;
        serverToolsAppendOperationLog($operationId ?? '', 'Error: ' . $message);
    }

    serverToolsSetOperationStage($operationId, 'Refreshing diagnostics');
    $diagnosticsAfter = serverToolsDiagnostics();
    $installedVersion = (string)($diagnosticsAfter['tools'][$toolId]['version'] ?? '');
    $installedPath = (string)($diagnosticsAfter['tools'][$toolId]['executable_path'] ?? '');
    $log[] = 'Installed version: ' . ($installedVersion === '' ? 'Not detected' : $installedVersion);
    $log[] = 'Executable path: ' . ($installedPath === '' ? 'Not detected' : $installedPath);
    serverToolsAppendOperationLog($operationId ?? '', 'Installed version: ' . ($installedVersion === '' ? 'Not detected' : $installedVersion));
    serverToolsAppendOperationLog($operationId ?? '', 'Executable path: ' . ($installedPath === '' ? 'Not detected' : $installedPath));
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

function serverToolsExecuteOperation(string $operationId): void
{
    $state = serverToolsReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Server tool operation not found.');
    }
    $toolId = (string)($state['tool_id'] ?? '');
    $toolAction = (string)($state['tool_action'] ?? '');
    $state['status'] = 'running';
    $state['stage'] = 'Preparing';
    $state['pid'] = function_exists('getmypid') ? (int)getmypid() : (int)($state['pid'] ?? 0);
    serverToolsWriteOperation($state);
    serverToolsAppendOperationLog($operationId, '[' . date('c') . '] Worker started.');

    $result = serverToolsRunManagedAction($toolId, $toolAction, $operationId);
    $state = serverToolsReadOperation($operationId);
    $state['status'] = !empty($result['success']) ? 'completed' : 'failed';
    $state['stage'] = !empty($result['success']) ? 'Completed' : 'Failed';
    $state['finished_at'] = date('c');
    $state['message'] = (string)($result['message'] ?? '');
    $state['summary_steps'] = is_array($result['summary_steps'] ?? null) ? $result['summary_steps'] : [];
    $state['result'] = [
        'success' => !empty($result['success']),
        'message' => (string)($result['message'] ?? ''),
        'action' => 'server_tool_action',
        'output' => serverToolsOperationLog($operationId),
        'summary_steps' => $state['summary_steps'],
    ];
    $state['diagnostics'] = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : serverToolsDiagnostics();
    serverToolsWriteOperation($state);
    serverToolsAppendOperationLog($operationId, '[' . date('c') . '] ' . $state['stage'] . '.');
}

function serverToolsDashboardSoftware(): array
{
    $diagnostics = serverToolsLoadPersistedDiagnostics();
    if (empty($diagnostics)) {
        $diagnostics = serverToolsDiagnostics(false);
    }
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
