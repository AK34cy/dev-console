<?php

const DEV_CONSOLE_MANAGED_MARKER = '# Managed by IOVON Dev Console';
const DEV_CONSOLE_PLACEHOLDER_FILE = 'index.html';
const DEV_CONSOLE_HTTP_VERIFY_TIMEOUT = 3;

function projectActionResult(bool $success, string $message, array $log = []): array
{
    return [
        'success' => $success,
        'message' => $message,
        'output' => implode("\n", $log),
    ];
}

function projectSafeId(string $projectId): bool
{
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $projectId) === 1;
}

function projectEnvironmentVhostName(array $project, string $environment): string
{
    $projectId = (string)($project['id'] ?? '');
    if (!projectSafeId($projectId) || !in_array($environment, ['production', 'preview'], true)) {
        throw new RuntimeException('Invalid project identifier.');
    }

    return 'dev-console-' . $projectId . '-' . $environment . '.conf';
}

function projectSafeLogName(array $project, string $environment): string
{
    return (string)$project['id'] . '-' . $environment;
}

function projectNormalizePath(string $path): string
{
    if ($path === '' || devConsoleHasControlCharacters($path) || !str_starts_with($path, '/')) {
        throw new RuntimeException('Path must be an absolute Unix path.');
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            throw new RuntimeException('Path traversal is not allowed.');
        }
        $parts[] = $part;
    }

    return '/' . implode('/', $parts);
}

function projectPathContains(string $parent, string $child): bool
{
    $parent = rtrim(projectNormalizePath($parent), '/');
    $child = rtrim(projectNormalizePath($child), '/');

    return $parent !== $child && str_starts_with($child . '/', $parent . '/');
}

function projectAssertManagedPathPolicy(array $project, string $allowedBase = '/var/www/projects'): array
{
    $base = rtrim(projectNormalizePath($allowedBase), '/');
    $repositoryPath = projectNormalizePath((string)($project['repository_path'] ?? ''));
    $productionPath = projectNormalizePath((string)($project['production']['path'] ?? ''));
    $previewPath = projectNormalizePath((string)($project['preview']['path'] ?? ''));
    $protected = ['/', '/var', '/var/www', $base];

    foreach (['Production' => $productionPath, 'Preview' => $previewPath] as $label => $path) {
        if (!str_starts_with($path . '/', $base . '/')) {
            throw new RuntimeException($label . ' path must be inside ' . $base . '.');
        }
        if (in_array($path, $protected, true)) {
            throw new RuntimeException($label . ' path is protected.');
        }
        if ($path === $repositoryPath || projectPathContains($path, $repositoryPath) || projectPathContains($repositoryPath, $path)) {
            throw new RuntimeException($label . ' path must not overlap the repository path.');
        }
        if (file_exists($path) && is_link($path)) {
            throw new RuntimeException($label . ' path must not be a symlink.');
        }
    }

    if ($productionPath === $previewPath) {
        throw new RuntimeException('Production and Preview paths must be different.');
    }
    if (projectPathContains($productionPath, $previewPath) || projectPathContains($previewPath, $productionPath)) {
        throw new RuntimeException('Production and Preview paths must not contain each other.');
    }

    return ['production' => $productionPath, 'preview' => $previewPath];
}

function projectDirectoryAcceptableForProvisioning(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }
    if (is_link($path)) {
        return false;
    }

    $entries = array_values(array_filter(scandir($path) ?: [], fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
    if (empty($entries)) {
        return true;
    }

    return count($entries) === 1 && $entries[0] === DEV_CONSOLE_PLACEHOLDER_FILE && projectIsPlaceholderFile($path . '/' . DEV_CONSOLE_PLACEHOLDER_FILE);
}

function projectIsPlaceholderFile(string $path): bool
{
    $contents = (string)@file_get_contents($path);
    return is_file($path) && (str_contains($contents, 'Temporary Dev Console placeholder') || str_contains($contents, 'Dev Console placeholder'));
}

function projectPlaceholderMarker(array $project, string $environment): string
{
    $projectId = htmlspecialchars((string)$project['id'], ENT_QUOTES, 'UTF-8');
    $environmentName = htmlspecialchars($environment, ENT_QUOTES, 'UTF-8');

    return '<meta name="iovon-dev-console-project" content="' . $projectId . '">' . "\n" .
        '<meta name="iovon-dev-console-environment" content="' . $environmentName . '">';
}

function projectPlaceholderMatches(string $contents, array $project, string $environment): bool
{
    return str_contains($contents, '<meta name="iovon-dev-console-project" content="' . htmlspecialchars((string)$project['id'], ENT_QUOTES, 'UTF-8') . '">')
        && str_contains($contents, '<meta name="iovon-dev-console-environment" content="' . htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . '">');
}

function projectAtomicWrite(string $path, string $contents): bool
{
    $directory = dirname($path);
    $temporaryPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
        return false;
    }
    @chmod($temporaryPath, 0644);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}

function projectUpgradeEnvironmentPlaceholder(array $project, string $environment, array &$log = []): bool
{
    if (!in_array($environment, ['production', 'preview'], true)) {
        throw new RuntimeException('Invalid environment.');
    }

    $path = rtrim(projectNormalizePath((string)($project[$environment]['path'] ?? '')), '/') . '/' . DEV_CONSOLE_PLACEHOLDER_FILE;
    if (!is_file($path)) {
        $log[] = ucfirst($environment) . ' placeholder not found: ' . $path;
        return false;
    }
    if (!projectIsPlaceholderFile($path)) {
        $log[] = ucfirst($environment) . ' index.html is not a Dev Console placeholder; it was not changed.';
        return false;
    }

    $contents = (string)@file_get_contents($path);
    if (projectPlaceholderMatches($contents, $project, $environment)) {
        return false;
    }
    if (!projectAtomicWrite($path, projectPlaceholderContent($project, $environment))) {
        throw new RuntimeException('Unable to upgrade ' . $environment . ' placeholder markers.');
    }

    $log[] = 'Upgraded ' . $environment . ' placeholder markers: ' . $path;
    return true;
}

function projectUpgradePlaceholders(array $project, array &$log = []): void
{
    foreach (['production', 'preview'] as $environment) {
        projectUpgradeEnvironmentPlaceholder($project, $environment, $log);
    }
}

function projectPlaceholderContent(array $project, string $environment): string
{
    $projectName = htmlspecialchars((string)$project['name'], ENT_QUOTES, 'UTF-8');
    $environmentName = htmlspecialchars(ucfirst($environment), ENT_QUOTES, 'UTF-8');

    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n" . projectPlaceholderMarker($project, $environment) . "\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<title>" . $projectName . ' ' . $environmentName . "</title>\n<style>body{margin:0;background:#f4f8fb;color:#1d2a32;font-family:Arial,sans-serif;display:grid;min-height:100vh;place-items:center}.wrap{background:#fff;border:1px solid #d8eef5;border-radius:10px;box-shadow:0 10px 28px rgba(0,83,133,.09);max-width:560px;padding:32px;width:calc(100% - 48px)}h1{color:#005385;margin:0 0 18px}dl{display:grid;gap:10px;margin:0}dt{color:#536b78;font-size:12px;font-weight:700;text-transform:uppercase}dd{margin:2px 0 0}.badge{background:#e3f5f9;border-radius:999px;color:#005385;display:inline-block;font-weight:700;padding:6px 10px}</style>\n</head>\n<body>\n<main class=\"wrap\">\n<h1>IOVON Dev Console</h1>\n<dl>\n<dt>Project</dt><dd>" . $projectName . "</dd>\n<dt>Environment</dt><dd>" . $environmentName . "</dd>\n<dt>Status</dt><dd><span class=\"badge\">Waiting for deployment</span></dd>\n</dl>\n<p>Dev Console placeholder page.</p>\n</main>\n</body>\n</html>\n";
}

function projectVhostPath(array $project, string $environment, string $availableDir = '/etc/apache2/sites-available'): string
{
    return rtrim($availableDir, '/') . '/' . projectEnvironmentVhostName($project, $environment);
}

function projectSafeVhostFilename(string $filename): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.conf$/', $filename) === 1
        && basename($filename) === $filename
        && !str_contains($filename, '..');
}

function projectStoredVhostName(array $project, string $environment, bool $remote = false): string
{
    $stored = (string)($project['provisioning'][$environment . '_vhost'] ?? '');
    if ($stored === '') {
        $setupField = $environment === 'production' ? 'production_site' : 'preview_site';
        $stored = (string)($project['setup'][$setupField] ?? '');
    }

    return $stored !== '' ? $stored : ($remote ? projectRemoteEnvironmentVhostName($project, $environment) : projectEnvironmentVhostName($project, $environment));
}

function projectVhostPathForName(string $filename, string $availableDir = '/etc/apache2/sites-available'): string
{
    return rtrim($availableDir, '/') . '/' . $filename;
}

function projectEnabledPathForName(string $filename, string $enabledDir = '/etc/apache2/sites-enabled'): string
{
    return rtrim($enabledDir, '/') . '/' . $filename;
}

function projectEnabledPath(array $project, string $environment, string $enabledDir = '/etc/apache2/sites-enabled'): string
{
    return rtrim($enabledDir, '/') . '/' . projectEnvironmentVhostName($project, $environment);
}

function projectVhostMarkerStatus(string $path, array $project, string $environment): array
{
    if (!is_file($path)) {
        return [
            'exists' => false,
            'managed_marker' => 'absent',
            'project_marker' => 'not checked',
            'environment_marker' => 'not checked',
            'matches' => true,
        ];
    }

    $contents = (string)@file_get_contents($path);
    $managed = str_contains($contents, DEV_CONSOLE_MANAGED_MARKER);
    $projectId = (string)$project['id'];
    $projectMatches = str_contains($contents, '# Project ID: ' . $projectId) || str_contains($contents, '# Project: ' . $projectId);
    $environmentMatches = str_contains($contents, '# Environment: ' . $environment);

    return [
        'exists' => true,
        'managed_marker' => $managed ? 'present' : 'missing',
        'project_marker' => $projectMatches ? 'present' : 'missing',
        'environment_marker' => $environmentMatches ? 'present' : 'missing',
        'matches' => $managed && $projectMatches && $environmentMatches,
    ];
}

function projectVhostMarkersMatch(string $path, array $project, string $environment): bool
{
    $status = projectVhostMarkerStatus($path, $project, $environment);

    return !empty($status['exists']) && !empty($status['matches']);
}

function projectVhostDeletionDiagnostic(array $target, string $reason): string
{
    return "Deletion stopped for " . ucfirst((string)$target['environment']) . " vhost.\n\n"
        . 'Project: ' . (string)$target['project_id'] . "\n\n"
        . 'Expected filename: ' . (string)$target['expected'] . "\n\n"
        . 'Stored filename: ' . (string)$target['stored'] . "\n\n"
        . 'Actual file: ' . (string)$target['path'] . "\n\n"
        . 'Managed marker: ' . (string)($target['managed_marker'] ?? 'not checked') . "\n\n"
        . 'Project ID marker: ' . (string)($target['project_marker'] ?? 'not checked') . "\n\n"
        . 'Environment marker: ' . (string)($target['environment_marker'] ?? 'not checked') . "\n\n"
        . 'Reason: ' . $reason;
}

function projectResolveDeletionVhostTarget(array $project, string $environment, string $availableDir, string $enabledDir, bool $remote = false): array
{
    $expected = $remote ? projectRemoteEnvironmentVhostName($project, $environment) : projectEnvironmentVhostName($project, $environment);
    $stored = projectStoredVhostName($project, $environment, $remote);
    $path = projectVhostPathForName($stored, $availableDir);
    $target = [
        'environment' => $environment,
        'project_id' => (string)($project['id'] ?? ''),
        'expected' => $expected,
        'stored' => $stored,
        'path' => $path,
        'enabled_path' => projectEnabledPathForName($stored, $enabledDir),
        'safe' => projectSafeVhostFilename($stored),
        'exists' => null,
        'matches' => false,
        'managed_marker' => 'not checked',
        'project_marker' => 'not checked',
        'environment_marker' => 'not checked',
    ];
    if (!$target['safe']) {
        $target['error'] = projectVhostDeletionDiagnostic($target, 'Stored vhost filename is not a safe Apache config basename.');
        return $target;
    }
    if (!$remote) {
        $status = projectVhostMarkerStatus($path, $project, $environment);
        $target = array_merge($target, $status);
        if (!empty($status['exists']) && empty($status['matches'])) {
            $target['error'] = projectVhostDeletionDiagnostic($target, 'Cannot safely prove this Apache vhost belongs to Project ' . (string)$project['id'] . '.');
        }
    }

    return $target;
}

function projectGenerateVhost(array $project, string $environment, string $documentRoot): string
{
    $domain = (string)$project[$environment]['domain'];
    $logName = projectSafeLogName($project, $environment);

    return DEV_CONSOLE_MANAGED_MARKER . "\n" .
        '# Project: ' . (string)$project['id'] . "\n" .
        '# Environment: ' . $environment . "\n" .
        "<VirtualHost *:80>\n" .
        '    ServerName ' . $domain . "\n" .
        '    DocumentRoot ' . $documentRoot . "\n\n" .
        '    <Directory ' . $documentRoot . ">\n" .
        "        Options FollowSymLinks\n" .
        "        AllowOverride All\n" .
        "        Require all granted\n" .
        "    </Directory>\n\n" .
        '    ErrorLog ${APACHE_LOG_DIR}/' . $logName . "-error.log\n" .
        '    CustomLog ${APACHE_LOG_DIR}/' . $logName . "-access.log combined\n" .
        "</VirtualHost>\n";
}

function projectRemoteEnvironmentVhostName(array $project, string $environment): string
{
    $projectId = (string)($project['id'] ?? '');
    if (!projectSafeId($projectId) || !in_array($environment, ['production', 'preview'], true)) {
        throw new RuntimeException('Invalid project identifier.');
    }

    return $projectId . '-' . $environment . '.conf';
}

function projectRemoteVhostPath(array $project, string $environment, string $availableDir = '/etc/apache2/sites-available'): string
{
    return rtrim($availableDir, '/') . '/' . projectRemoteEnvironmentVhostName($project, $environment);
}

function projectGenerateRemoteVhost(array $project, string $environment, string $documentRoot): string
{
    $domain = devConsoleNormalizeDomain((string)($project[$environment]['domain'] ?? ''));
    $logName = projectSafeLogName($project, $environment);

    return DEV_CONSOLE_MANAGED_MARKER . "\n" .
        '# Project ID: ' . (string)$project['id'] . "\n" .
        '# Environment: ' . $environment . "\n" .
        "<VirtualHost *:80>\n" .
        '    ServerName ' . $domain . "\n\n" .
        '    DocumentRoot ' . $documentRoot . "\n\n" .
        '    <Directory ' . $documentRoot . ">\n" .
        "        Options FollowSymLinks\n" .
        "        AllowOverride All\n" .
        "        Require all granted\n" .
        "    </Directory>\n\n" .
        '    ErrorLog ${APACHE_LOG_DIR}/' . $logName . "-error.log\n" .
        '    CustomLog ${APACHE_LOG_DIR}/' . $logName . "-access.log combined\n" .
        "</VirtualHost>\n";
}

function projectRemoteSshTarget(array $server): string
{
    return (string)$server['user'] . '@' . (string)$server['host'];
}

function projectRemoteSshBaseArguments(array $server): array
{
    $ssh = function_exists('managedServersSshExecutable') ? managedServersSshExecutable() : serverToolsFindExecutable('ssh', serverToolsDefaultPath());
    if ($ssh === '') {
        throw new RuntimeException('SSH executable missing.');
    }

    return [
        $ssh,
        '-i', (string)$server['key'],
        '-p', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=8',
        '-o', 'StrictHostKeyChecking=accept-new',
    ];
}

function projectRemoteSshArguments(array $server, string $command): array
{
    return array_merge(projectRemoteSshBaseArguments($server), [projectRemoteSshTarget($server), $command]);
}

function projectRemoteScpArguments(array $server, string $localPath, string $remotePath): array
{
    $scp = serverToolsFindExecutable('scp', serverToolsDefaultPath());
    if ($scp === '') {
        throw new RuntimeException('SCP executable missing.');
    }

    return [
        $scp,
        '-i', (string)$server['key'],
        '-P', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=8',
        '-o', 'StrictHostKeyChecking=accept-new',
        $localPath,
        projectRemoteSshTarget($server) . ':' . $remotePath,
    ];
}

function projectRemoteCommandDisplay(array $server, string $command): string
{
    return 'ssh [managed-server-options] ' . projectRemoteSshTarget($server) . ' ' . escapeshellarg($command);
}

function projectRemoteRun(array $server, string $command, array &$log, int $timeout = 30): array
{
    $log[] = '$ ' . projectRemoteCommandDisplay($server, $command);
    $result = processRunCommand(projectRemoteSshArguments($server, $command), [
        'timeout' => $timeout,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (trim((string)$result['output']) !== '') {
        $log[] = trim((string)$result['output']);
    }

    return $result;
}

function projectRemoteScp(array $server, string $localPath, string $remotePath, array &$log): array
{
    $log[] = '$ scp [managed-server-options] ' . basename($localPath) . ' ' . projectRemoteSshTarget($server) . ':' . $remotePath;
    $result = processRunCommand(projectRemoteScpArguments($server, $localPath, $remotePath), [
        'timeout' => 30,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (trim((string)$result['output']) !== '') {
        $log[] = trim((string)$result['output']);
    }

    return $result;
}

function projectRemoteShellPath(string $path): string
{
    return escapeshellarg(projectNormalizePath($path));
}

function projectRemoteShellValue(string $value): string
{
    return escapeshellarg($value);
}

function projectRemoteValidateServer(array $server): void
{
    if ((string)($server['status'] ?? '') !== 'reachable') {
        throw new RuntimeException('Managed Server is not reachable.');
    }
    if (!is_file((string)($server['key'] ?? '')) || !is_readable((string)($server['key'] ?? ''))) {
        throw new RuntimeException('SSH key exists check failed.');
    }
}

function projectRemoteCapabilityCommand(string $managedRoot = '/var/www/projects'): string
{
    $root = projectRemoteShellPath($managedRoot);

    return 'printf "whoami=%s\n" "$(whoami)"; '
        . 'printf "uid=%s\n" "$(id -u)"; '
        . 'printf "gid=%s\n" "$(id -g)"; '
        . 'printf "gid_name=%s\n" "$(id -gn)"; '
        . 'for c in apache2 apache2ctl a2ensite a2dissite systemctl sudo install rm; do p=$(command -v "$c" 2>/dev/null || true); printf "cmd_%s=%s\n" "$c" "$p"; done; '
        . 'if command -v apache2 >/dev/null 2>&1; then apache2 -v 2>/dev/null | sed -n "s/^Server version:[[:space:]]*/apache_version=/p" | head -n 1; fi; '
        . 'if [ -d ' . $root . ' ] && [ -w ' . $root . ' ]; then printf "managed_root_writable=1\n"; else printf "managed_root_writable=0\n"; fi; '
        . 'if [ "$(id -u)" = 0 ]; then printf "apache_privilege=direct\n"; '
        . 'elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then printf "apache_privilege=sudo\n"; '
        . 'else printf "apache_privilege=missing\n"; fi';
}

function projectParseRemoteCapabilities(string $output): array
{
    $capabilities = [
        'whoami' => '',
        'uid' => '',
        'gid' => '',
        'gid_name' => '',
        'apache_version' => '',
        'managed_root_writable' => false,
        'apache_privilege' => 'missing',
        'commands' => [],
    ];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (str_starts_with($key, 'cmd_')) {
            $capabilities['commands'][substr($key, 4)] = $value;
        } elseif ($key === 'managed_root_writable') {
            $capabilities[$key] = $value === '1';
        } elseif (array_key_exists($key, $capabilities)) {
            $capabilities[$key] = $value;
        }
    }

    return $capabilities;
}

function projectRemoteCheckCapabilities(array $server, array &$log, string $managedRoot = '/var/www/projects'): array
{
    $result = projectRemoteRun($server, projectRemoteCapabilityCommand($managedRoot), $log);
    if ((int)$result['exit_code'] !== 0) {
        throw new RuntimeException('Unable to inspect Managed Server deployment capabilities.');
    }

    return projectParseRemoteCapabilities((string)$result['stdout']);
}

function projectRemoteCommandAvailable(array $capabilities, string $command): bool
{
    return (string)($capabilities['commands'][$command] ?? '') !== '';
}

function projectDiagnosticValue(string $value): string
{
    return trim($value) === '' ? 'Not detected' : $value;
}

function projectRemoteCapabilityReady(array $capabilities): bool
{
    foreach (['apache2', 'apache2ctl', 'a2ensite', 'a2dissite', 'systemctl', 'install', 'rm'] as $command) {
        if (!projectRemoteCommandAvailable($capabilities, $command)) {
            return false;
        }
    }
    if ((string)($capabilities['apache_privilege'] ?? '') === 'sudo' && !projectRemoteCommandAvailable($capabilities, 'sudo')) {
        return false;
    }

    return in_array((string)($capabilities['apache_privilege'] ?? ''), ['direct', 'sudo'], true);
}

function projectRemotePrivilegeDiagnostic(array $server, array $capabilities, string $managedRoot = '/var/www/projects'): string
{
    $commandStatus = static function (bool $ready): string {
        return $ready ? 'Available' : 'Missing';
    };
    $setupCommand = projectRemoteServerSetupCommand($server);
    $apacheDetected = projectRemoteCommandAvailable($capabilities, 'apache2') ? 'Detected' : 'Missing';
    $apachePrivilege = (string)($capabilities['apache_privilege'] ?? 'missing');
    $privilegeLabel = match ($apachePrivilege) {
        'direct' => 'Available',
        'sudo' => 'Available through sudo -n',
        default => 'Missing',
    };
    $sudoMessage = $apachePrivilege === 'missing'
        ? "\nPasswordless sudo is not configured for this deployment user.\nRun the Managed Server setup command for this SSH user, then test the connection again.\n"
        : '';

    return "Project setup cannot continue\n\n"
        . 'Connected as: ' . projectDiagnosticValue((string)($capabilities['whoami'] ?? '')) . ' (uid ' . projectDiagnosticValue((string)($capabilities['uid'] ?? '')) . ")\n\n"
        . 'Managed Project root: ' . $managedRoot . "\n"
        . 'Status: ' . (!empty($capabilities['managed_root_writable']) ? 'Writable' : 'Will be managed with sudo -n') . "\n\n"
        . 'Apache: ' . $apacheDetected . "\n"
        . 'Apache version: ' . projectDiagnosticValue((string)($capabilities['apache_version'] ?? '')) . "\n\n"
        . "System privileges:\n"
        . 'Apache config test       ' . $commandStatus(projectRemoteCommandAvailable($capabilities, 'apache2ctl') && $apachePrivilege !== 'missing') . "\n"
        . 'Enable/disable sites     ' . $commandStatus(projectRemoteCommandAvailable($capabilities, 'a2ensite') && projectRemoteCommandAvailable($capabilities, 'a2dissite') && $apachePrivilege !== 'missing') . "\n"
        . 'Apache reload            ' . $commandStatus(projectRemoteCommandAvailable($capabilities, 'systemctl') && $apachePrivilege !== 'missing') . "\n"
        . 'Vhost installation       ' . $privilegeLabel . "\n\n"
        . $sudoMessage
        . "Dev Console requires one-time server preparation.\n\n"
        . "Log in as the configured SSH user, run the setup command below, then return here and use Retry Setup.\n\n"
        . $setupCommand;
}

function projectRemoteServerSetupCommand(array $server): string
{
    if (!function_exists('managedServersReadPublicKey') || !function_exists('managedServersSetupCommand')) {
        return '';
    }

    return managedServersSetupCommand(managedServersReadPublicKey((string)($server['key'] ?? '')), (string)($server['user'] ?? ''));
}

function projectRemotePrivilegedCommand(array $capabilities, string $directCommand): string
{
    if ((string)($capabilities['apache_privilege'] ?? '') === 'direct') {
        return $directCommand;
    }

    return 'sudo -n ' . $directCommand;
}

function projectRemotePrivilegedCommandChain(array $capabilities, array $commands): string
{
    $prepared = [];
    foreach ($commands as $command) {
        $command = trim((string)$command);
        if ($command === '') {
            continue;
        }
        $prepared[] = projectRemotePrivilegedCommand($capabilities, $command);
    }

    return implode(' && ', $prepared);
}

function projectRemoteSetupMetadata(array $project): array
{
    $setup = is_array($project['setup'] ?? null) ? $project['setup'] : [];
    return [
        'status' => (string)($setup['status'] ?? 'Not configured'),
        'server_id' => (string)($setup['server_id'] ?? ''),
        'timestamp' => (string)($setup['timestamp'] ?? ''),
        'message' => (string)($setup['message'] ?? ''),
        'preview_site' => (string)($setup['preview_site'] ?? ''),
        'production_site' => (string)($setup['production_site'] ?? ''),
        'apache_version' => (string)($setup['apache_version'] ?? ''),
    ];
}

function projectRemoteApacheConfigIsManagedCommand(string $path, array $project, string $environment): string
{
    return 'test ! -e ' . projectRemoteShellPath($path)
        . ' || (grep -F ' . projectRemoteShellValue(DEV_CONSOLE_MANAGED_MARKER) . ' ' . projectRemoteShellPath($path) . ' >/dev/null'
        . ' && grep -F ' . projectRemoteShellValue('# Project ID: ' . (string)$project['id']) . ' ' . projectRemoteShellPath($path) . ' >/dev/null'
        . ' && grep -F ' . projectRemoteShellValue('# Environment: ' . $environment) . ' ' . projectRemoteShellPath($path) . ' >/dev/null)';
}

function projectRemoteApacheMarkerStatusCommand(string $path, array $project, string $environment): string
{
    $quotedPath = projectRemoteShellPath($path);
    $managed = projectRemoteShellValue(DEV_CONSOLE_MANAGED_MARKER);
    $projectMarker = projectRemoteShellValue('# Project ID: ' . (string)$project['id']);
    $legacyProjectMarker = projectRemoteShellValue('# Project: ' . (string)$project['id']);
    $environmentMarker = projectRemoteShellValue('# Environment: ' . $environment);

    return 'if [ ! -e ' . $quotedPath . ' ]; then printf "exists=0\n"; else '
        . 'printf "exists=1\n"; '
        . 'if grep -F ' . $managed . ' ' . $quotedPath . ' >/dev/null; then printf "managed_marker=present\n"; else printf "managed_marker=missing\n"; fi; '
        . 'if grep -F ' . $projectMarker . ' ' . $quotedPath . ' >/dev/null || grep -F ' . $legacyProjectMarker . ' ' . $quotedPath . ' >/dev/null; then printf "project_marker=present\n"; else printf "project_marker=missing\n"; fi; '
        . 'if grep -F ' . $environmentMarker . ' ' . $quotedPath . ' >/dev/null; then printf "environment_marker=present\n"; else printf "environment_marker=missing\n"; fi; '
        . 'fi';
}

function projectParseRemoteMarkerStatus(string $output): array
{
    $status = [
        'exists' => false,
        'managed_marker' => 'not checked',
        'project_marker' => 'not checked',
        'environment_marker' => 'not checked',
        'matches' => true,
    ];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if ($key === 'exists') {
            $status['exists'] = $value === '1';
        } elseif (array_key_exists($key, $status)) {
            $status[$key] = $value;
        }
    }
    if (!empty($status['exists'])) {
        $status['matches'] = $status['managed_marker'] === 'present'
            && $status['project_marker'] === 'present'
            && $status['environment_marker'] === 'present';
    }

    return $status;
}

function projectRemoteInstallSite(array $server, array $project, string $environment, string $content, string $availableDir, array $capabilities, array &$log, array &$installedSites): void
{
    $siteName = projectRemoteEnvironmentVhostName($project, $environment);
    $remotePath = rtrim($availableDir, '/') . '/' . $siteName;
    $check = projectRemoteRun($server, projectRemoteApacheConfigIsManagedCommand($remotePath, $project, $environment), $log);
    if ($check['exit_code'] !== 0) {
        throw new RuntimeException('Conflicting Apache site already exists: ' . $siteName);
    }

    $exists = projectRemoteRun($server, 'test -e ' . projectRemoteShellPath($remotePath), $log);
    $hadExisting = $exists['exit_code'] === 0;
    $tmpRemote = '/tmp/iovon-dev-console-' . (string)$project['id'] . '-' . $environment . '-' . bin2hex(random_bytes(6)) . '.conf';
    $backupRemote = '/tmp/iovon-dev-console-' . (string)$project['id'] . '-' . $environment . '-' . bin2hex(random_bytes(6)) . '.bak';
    $localTmp = tempnam(sys_get_temp_dir(), 'dev-console-vhost-');
    if ($localTmp === false || file_put_contents($localTmp, $content, LOCK_EX) === false) {
        throw new RuntimeException(ucfirst($environment) . ' VirtualHost could not be installed.');
    }
    @chmod($localTmp, 0600);

    try {
        $copy = projectRemoteScp($server, $localTmp, $tmpRemote, $log);
        if ($copy['exit_code'] !== 0) {
            throw new RuntimeException(ucfirst($environment) . ' VirtualHost could not be installed.');
        }
        $installCommands = [];
        if ($hadExisting) {
            $installCommands[] = 'cp -- ' . projectRemoteShellPath($remotePath) . ' ' . projectRemoteShellPath($backupRemote);
        }
        $installCommands[] = 'install -m 0644 -- ' . projectRemoteShellPath($tmpRemote) . ' ' . projectRemoteShellPath($remotePath);
        $installCommands[] = 'rm -f -- ' . projectRemoteShellPath($tmpRemote);
        $installCommand = projectRemotePrivilegedCommandChain($capabilities, $installCommands);
        $install = projectRemoteRun($server, $installCommand, $log);
        if ($install['exit_code'] !== 0) {
            throw new RuntimeException(ucfirst($environment) . ' VirtualHost could not be installed.');
        }
        $installedSites[] = [
            'path' => $remotePath,
            'backup' => $hadExisting ? $backupRemote : '',
            'created' => !$hadExisting,
            'project_id' => (string)$project['id'],
            'environment' => $environment,
        ];
        $log[] = ucfirst($environment) . ' site installed: ' . $siteName;
    } finally {
        if ($localTmp !== false && is_file($localTmp)) {
            @unlink($localTmp);
        }
    }
}

function projectRemoteRollbackInstalledSites(array $server, array $installedSites, array &$log, array $capabilities = ['apache_privilege' => 'direct']): void
{
    foreach (array_reverse($installedSites) as $site) {
        $path = (string)($site['path'] ?? '');
        $backup = (string)($site['backup'] ?? '');
        if ($path === '') {
            continue;
        }
        if ($backup !== '') {
            projectRemoteRun($server, 'if [ -e ' . projectRemoteShellPath($backup) . ' ]; then ' . projectRemotePrivilegedCommand($capabilities, 'mv -- ' . projectRemoteShellPath($backup) . ' ' . projectRemoteShellPath($path)) . '; fi', $log);
            $log[] = 'Restored previous remote Apache config: ' . basename($path);
        } elseif (!empty($site['created'])) {
            projectRemoteRun(
                $server,
                projectRemotePrivilegedCommand($capabilities, 'rm -f -- ' . projectRemoteShellPath($path)),
                $log
            );
            $log[] = 'Removed newly created remote Apache config: ' . basename($path);
        }
    }
}

function projectRemoteSetup(array $configuration, string $projectId, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return projectActionResult(false, 'Project not found.');
    }

    $log = [];
    $installedSites = [];
    $server = null;
    $availableDir = '/etc/apache2/sites-available';
    $enabledDir = '/etc/apache2/sites-enabled';
    $apacheVersion = '';
    $capabilities = ['apache_privilege' => 'direct'];

    try {
        $serverId = (string)($project['managed_server_id'] ?? '');
        if ($serverId === '') {
            throw new RuntimeException('Project does not have a Managed Server.');
        }
        $servers = is_array($options['managed_servers'] ?? null)
            ? $options['managed_servers']
            : (function_exists('managedServersLoad') ? managedServersLoad() : []);
        $server = function_exists('managedServersFind') ? managedServersFind($servers, $serverId) : null;
        if ($server === null) {
            throw new RuntimeException('Managed Server not found.');
        }
        projectRemoteValidateServer($server);
        if (!devConsoleProjectUsesGeneratedEnvironmentPaths($project)) {
            throw new RuntimeException('This project uses custom environment paths and cannot be set up automatically.');
        }
        projectValidateStoredConfiguration($configuration, $project);
        $paths = projectAssertManagedPathPolicy($project);

        $capabilities = projectRemoteCheckCapabilities($server, $log);
        $apacheVersion = (string)($capabilities['apache_version'] ?? '');
        if (!projectRemoteCapabilityReady($capabilities)) {
            $message = projectRemotePrivilegeDiagnostic($server, $capabilities);
            $result = projectActionResult(false, 'Project setup cannot continue. Server preparation is required.', $log);
            $result['setup_command'] = projectRemoteServerSetupCommand($server);
            $result['output'] .= ($result['output'] === '' ? '' : "\n\n") . $message;
            return $result;
        }
        $log[] = 'Deployment user: ' . projectDiagnosticValue((string)($capabilities['whoami'] ?? '')) . ' (uid ' . projectDiagnosticValue((string)($capabilities['uid'] ?? '')) . ').';
        $log[] = 'Apache control: ' . ((string)($capabilities['apache_privilege'] ?? '') === 'direct' ? 'direct root access' : 'sudo -n passwordless sudo') . '.';

        foreach (['preview', 'production'] as $environment) {
            $path = $paths[$environment];
            $owner = projectRemoteShellValue((string)($capabilities['whoami'] ?? ''));
            $group = projectRemoteShellValue((string)($capabilities['gid_name'] ?? $capabilities['whoami'] ?? ''));
            $prepare = projectRemotePrivilegedCommandChain($capabilities, [
                'mkdir -p -- ' . projectRemoteShellPath($path),
                'chown ' . $owner . ':' . $group . ' -- ' . projectRemoteShellPath($path),
                'chmod 755 -- ' . projectRemoteShellPath($path),
            ])
                . ' && test -d ' . projectRemoteShellPath($path)
                . ' && test -r ' . projectRemoteShellPath($path)
                . ' && test -w ' . projectRemoteShellPath($path);
            $result = projectRemoteRun($server, $prepare, $log);
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException('Project directory cannot be created: ' . $path);
            }
            $log[] = ucfirst($environment) . ' directory ready: ' . $path;
        }

        foreach (['preview', 'production'] as $environment) {
            projectRemoteInstallSite($server, $project, $environment, projectGenerateRemoteVhost($project, $environment, $paths[$environment]), $availableDir, $capabilities, $log, $installedSites);
        }

        foreach (['preview', 'production'] as $environment) {
            $siteName = projectRemoteEnvironmentVhostName($project, $environment);
            $enable = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'a2ensite ' . escapeshellarg($siteName)), $log);
            if ($enable['exit_code'] !== 0) {
                throw new RuntimeException('Unable to enable ' . $environment . ' site.');
            }
        }

        $configtest = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'apache2ctl configtest'), $log);
        if ($configtest['exit_code'] !== 0) {
            projectRemoteRollbackInstalledSites($server, $installedSites, $log, $capabilities);
            throw new RuntimeException('Apache configtest failed.');
        }
        $reload = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'systemctl reload apache2'), $log);
        if ($reload['exit_code'] !== 0) {
            throw new RuntimeException('Apache reload failed.');
        }

        $project['provisioning'] = [
            'managed' => true,
            'provisioned_at' => date('c'),
            'production_vhost' => projectRemoteEnvironmentVhostName($project, 'production'),
            'preview_vhost' => projectRemoteEnvironmentVhostName($project, 'preview'),
            'routing_verified_at' => null,
            'production_routing_verified' => null,
            'preview_routing_verified' => null,
        ];
        $project['setup'] = [
            'status' => 'Configured',
            'server_id' => $serverId,
            'timestamp' => $project['provisioning']['provisioned_at'],
            'message' => 'Remote Apache setup completed.',
            'preview_site' => projectRemoteEnvironmentVhostName($project, 'preview'),
            'production_site' => projectRemoteEnvironmentVhostName($project, 'production'),
            'apache_version' => $apacheVersion,
        ];
        if (empty($options['skip_save'])) {
            $updatedConfiguration = devConsoleTouchProject(devConsoleReplaceProject($configuration, $project), (string)$project['id']);
            if (!devConsoleSaveProjectConfiguration($updatedConfiguration)) {
                throw new RuntimeException('Unable to save setup metadata.');
            }
        }

        return projectActionResult(true, 'Remote Project setup completed.', $log);
    } catch (Throwable $exception) {
        if (is_array($server)) {
            projectRemoteRollbackInstalledSites($server, $installedSites, $log, $capabilities);
        }
        $project['setup'] = [
            'status' => 'Failed',
            'server_id' => (string)($project['managed_server_id'] ?? ''),
            'timestamp' => date('c'),
            'message' => $exception->getMessage(),
            'preview_site' => projectSafeId((string)($project['id'] ?? '')) ? projectRemoteEnvironmentVhostName($project, 'preview') : '',
            'production_site' => projectSafeId((string)($project['id'] ?? '')) ? projectRemoteEnvironmentVhostName($project, 'production') : '',
            'apache_version' => $apacheVersion,
        ];
        if (empty($options['skip_save'])) {
            @devConsoleSaveProjectConfiguration(devConsoleTouchProject(devConsoleReplaceProject($configuration, $project), (string)($project['id'] ?? '')));
        }

        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectVhostMatches(string $path, array $project, string $environment, string $documentRoot): bool
{
    if (!projectVhostMarkersMatch($path, $project, $environment)) {
        return false;
    }

    $parsed = devConsoleParseApacheSite($path);
    return devConsoleNormalizeDomain((string)$parsed['server_name']) === devConsoleNormalizeDomain((string)$project[$environment]['domain'])
        && projectNormalizePath((string)$parsed['document_root']) === $documentRoot;
}

function projectRunFixedCommand(array $arguments): array
{
    return apacheRunFixedCommand($arguments);
}

function projectApacheCommandPath(string $binary): string
{
    foreach (['/usr/sbin/' . $binary, '/usr/bin/' . $binary, '/bin/' . $binary] as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return $binary;
}

function projectAppendCommandLog(array &$log, array $result): void
{
    $log[] = '$ ' . (string)$result['command'];
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (trim((string)$result['output']) !== '') {
        $log[] = trim((string)$result['output']);
    }
}

function projectRoutingStatusLabel(array $project, string $environment): string
{
    $field = $environment . '_routing_verified';
    if (!array_key_exists($field, $project['provisioning'] ?? []) || ($project['provisioning'][$field] ?? null) === null) {
        return 'Not verified';
    }

    return !empty($project['provisioning'][$field]) ? 'Verified' : 'Failed';
}

function projectVerifyEnvironmentRouting(array $project, string $environment, array $options = []): array
{
    if (!in_array($environment, ['production', 'preview'], true)) {
        throw new RuntimeException('Invalid environment.');
    }

    $domain = devConsoleNormalizeDomain((string)($project[$environment]['domain'] ?? ''));
    if (!devConsoleIsHostname($domain)) {
        return [
            'request_completed' => false,
            'status_code' => null,
            'matched' => false,
            'message' => ucfirst($environment) . ' domain is invalid.',
        ];
    }

    $client = $options['http_client'] ?? null;
    if (is_callable($client)) {
        $response = $client($domain, $environment, $project);
    } else {
        $response = projectLocalHttpRequest($domain, (float)($options['timeout'] ?? DEV_CONSOLE_HTTP_VERIFY_TIMEOUT));
    }

    $completed = !empty($response['request_completed']);
    $statusCode = isset($response['status_code']) ? (int)$response['status_code'] : null;
    $body = (string)($response['body'] ?? '');
    $matched = $completed && projectPlaceholderMatches($body, $project, $environment);
    $message = (string)($response['message'] ?? '');
    if ($matched) {
        $message = ucfirst($environment) . ' routing verified for ' . $domain . '.';
    } elseif ($completed) {
        $message = ucfirst($environment) . ' routing did not return the expected Dev Console placeholder markers.';
    } elseif ($message === '') {
        $message = ucfirst($environment) . ' routing request failed.';
    }

    return [
        'request_completed' => $completed,
        'status_code' => $statusCode,
        'matched' => $matched,
        'message' => $message,
    ];
}

function projectLocalHttpRequest(string $host, float $timeout = DEV_CONSOLE_HTTP_VERIFY_TIMEOUT): array
{
    $errorCode = 0;
    $errorMessage = '';
    $socket = @fsockopen('127.0.0.1', 80, $errorCode, $errorMessage, $timeout);
    if (!is_resource($socket)) {
        return [
            'request_completed' => false,
            'status_code' => null,
            'body' => '',
            'message' => 'Unable to connect to 127.0.0.1:80: ' . ($errorMessage !== '' ? $errorMessage : 'connection failed'),
        ];
    }

    stream_set_timeout($socket, max(1, (int)ceil($timeout)));
    fwrite($socket, "GET / HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\nUser-Agent: IOVON Dev Console routing verifier\r\n\r\n");
    $response = '';
    while (!feof($socket) && strlen($response) < 1048576) {
        $chunk = fread($socket, 8192);
        if ($chunk === false) {
            break;
        }
        $response .= $chunk;
        $metadata = stream_get_meta_data($socket);
        if (!empty($metadata['timed_out'])) {
            fclose($socket);
            return [
                'request_completed' => false,
                'status_code' => null,
                'body' => '',
                'message' => 'Local HTTP routing request timed out.',
            ];
        }
    }
    fclose($socket);

    $statusCode = null;
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $response, $matches) === 1) {
        $statusCode = (int)$matches[1];
    }
    $bodyOffset = strpos($response, "\r\n\r\n");
    $body = $bodyOffset === false ? '' : substr($response, $bodyOffset + 4);

    return [
        'request_completed' => $response !== '',
        'status_code' => $statusCode,
        'body' => $body,
        'message' => $response === '' ? 'Local HTTP routing request returned an empty response.' : 'Local HTTP routing request completed.',
    ];
}

function projectVerifyRouting(array $project, array $options = []): array
{
    $results = [];
    $log = [];
    foreach (['production', 'preview'] as $environment) {
        $result = projectVerifyEnvironmentRouting($project, $environment, $options);
        $results[$environment] = $result;
        $log[] = ucfirst($environment) . ': ' . $result['message'] . ' HTTP ' . ($result['status_code'] === null ? '-' : (string)$result['status_code']);
    }

    return [
        'success' => !empty($results['production']['matched']) && !empty($results['preview']['matched']),
        'results' => $results,
        'log' => $log,
    ];
}

function projectApplyRoutingVerificationMetadata(array $project, array $verification): array
{
    $success = !empty($verification['success']);
    $project['provisioning']['routing_verified_at'] = date('c');
    $project['provisioning']['production_routing_verified'] = $success && !empty($verification['results']['production']['matched']);
    $project['provisioning']['preview_routing_verified'] = $success && !empty($verification['results']['preview']['matched']);

    return $project;
}

function projectVerifyRoutingAction(array $configuration, string $projectId, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return projectActionResult(false, 'Project not found.');
    }
    if (!empty($options['require_apache_running'])) {
        $apacheState = apacheState();
        if (empty($apacheState['installed']) || empty($apacheState['running'])) {
            return projectActionResult(false, 'Apache must be installed and running before routing verification.');
        }
    }

    $log = [];
    $serverNameResult = apacheEnsureServerNameConfig($options['apache_options'] ?? []);
    $log[] = $serverNameResult['message'];
    if (trim((string)($serverNameResult['output'] ?? '')) !== '') {
        $log[] = trim((string)$serverNameResult['output']);
    }
    if (empty($serverNameResult['success'])) {
        return projectActionResult(false, 'Apache ServerName configuration failed.', $log);
    }

    try {
        projectUpgradePlaceholders($project, $log);
    } catch (Throwable $exception) {
        return projectActionResult(false, $exception->getMessage(), $log);
    }

    $verification = projectVerifyRouting($project, $options);
    $log = array_merge($log, $verification['log']);
    $updatedProject = projectApplyRoutingVerificationMetadata($project, $verification);
    if (empty($options['skip_save']) && !devConsoleSaveProjectConfiguration(devConsoleTouchProject(devConsoleReplaceProject($configuration, $updatedProject), (string)($project['id'] ?? '')))) {
        return projectActionResult(false, 'Unable to save routing verification metadata.', $log);
    }

    return projectActionResult($verification['success'], $verification['success'] ? 'Project routing verified.' : 'Project routing verification failed.', $log);
}

function projectEnvironmentStatus(array $project, string $environment, string $availableDir = '/etc/apache2/sites-available', string $enabledDir = '/etc/apache2/sites-enabled'): array
{
    $path = projectNormalizePath((string)$project[$environment]['path']);
    $vhostPath = projectVhostPath($project, $environment, $availableDir);
    $enabledPath = projectEnabledPath($project, $environment, $enabledDir);
    $serverNameMatches = false;
    $documentRootMatches = false;

    if (is_file($vhostPath)) {
        $parsed = devConsoleParseApacheSite($vhostPath);
        $serverNameMatches = devConsoleNormalizeDomain((string)$parsed['server_name']) === devConsoleNormalizeDomain((string)$project[$environment]['domain']);
        $documentRootMatches = $parsed['document_root'] !== '' && projectNormalizePath((string)$parsed['document_root']) === $path;
    }

    return [
        'directory_exists' => is_dir($path) && !is_link($path),
        'vhost_exists' => is_file($vhostPath),
        'site_enabled' => file_exists($enabledPath),
        'server_name_matches' => $serverNameMatches,
        'document_root_matches' => $documentRootMatches,
        'vhost_name' => basename($vhostPath),
        'routing_status' => projectRoutingStatusLabel($project, $environment),
        'routing_verified_at' => (string)($project['provisioning']['routing_verified_at'] ?? ''),
    ];
}

function projectStatus(array $project, string $availableDir = '/etc/apache2/sites-available', string $enabledDir = '/etc/apache2/sites-enabled'): array
{
    if ((string)($project['managed_server_id'] ?? '') !== '') {
        $setup = projectRemoteSetupMetadata($project);
        $configured = $setup['status'] === 'Configured';
        $updateRequired = $setup['status'] === 'Update required';
        $failed = $setup['status'] === 'Failed';
        $environmentStatus = static function (string $environment) use ($project, $setup, $configured): array {
            $siteField = $environment === 'production' ? 'production_site' : 'preview_site';
            return [
                'directory_exists' => $configured,
                'vhost_exists' => $configured,
                'site_enabled' => $configured,
                'server_name_matches' => $configured,
                'document_root_matches' => $configured,
                'vhost_name' => (string)$setup[$siteField],
                'routing_status' => $configured ? 'Prepared' : 'Not verified',
                'routing_verified_at' => (string)$setup['timestamp'],
                'remote_path' => (string)($project[$environment]['path'] ?? ''),
                'remote_domain' => (string)($project[$environment]['domain'] ?? ''),
            ];
        };

        return [
            'label' => $configured ? 'Ready' : ($updateRequired ? 'Update required' : ($failed ? 'Setup failed' : 'Not set up')),
            'production' => $environmentStatus('production'),
            'preview' => $environmentStatus('preview'),
        ];
    }

    $production = projectEnvironmentStatus($project, 'production', $availableDir, $enabledDir);
    $preview = projectEnvironmentStatus($project, 'preview', $availableDir, $enabledDir);
    $all = [$production, $preview];
    $resourceFlags = [];
    foreach ($all as $environmentStatus) {
        $resourceFlags[] = $environmentStatus['directory_exists'];
        $resourceFlags[] = $environmentStatus['vhost_exists'];
        $resourceFlags[] = $environmentStatus['site_enabled'];
    }

    $anyResources = in_array(true, $resourceFlags, true);
    $allProvisioned = true;
    foreach ($all as $environmentStatus) {
        foreach (['directory_exists', 'vhost_exists', 'site_enabled', 'server_name_matches', 'document_root_matches'] as $field) {
            $allProvisioned = $allProvisioned && !empty($environmentStatus[$field]);
        }
    }

    $drift = false;
    foreach ($all as $environmentStatus) {
        if (!empty($environmentStatus['vhost_exists']) && (empty($environmentStatus['server_name_matches']) || empty($environmentStatus['document_root_matches']))) {
            $drift = true;
        }
    }

    $label = 'Not set up';
    if ($drift) {
        $label = 'Configuration drift';
    } elseif ($allProvisioned) {
        $label = 'Ready';
    } elseif ($anyResources) {
        $label = 'Incomplete';
    }

    return ['label' => $label, 'production' => $production, 'preview' => $preview];
}

function projectValidateStoredConfiguration(array $configuration, array $project): void
{
    if (!projectSafeId((string)($project['id'] ?? ''))
        || trim((string)($project['name'] ?? '')) === ''
        || trim((string)($project['branch'] ?? '')) === ''
        || !devConsoleIsAbsoluteUnixPath((string)($project['repository_path'] ?? ''))
        || !devConsoleIsHostname((string)($project['production']['domain'] ?? ''))
        || !devConsoleIsHostname((string)($project['preview']['domain'] ?? ''))
        || !devConsoleIsAbsoluteUnixPath((string)($project['production']['path'] ?? ''))
        || !devConsoleIsAbsoluteUnixPath((string)($project['preview']['path'] ?? ''))
        || devConsoleNormalizeDomain((string)$project['production']['domain']) === devConsoleNormalizeDomain((string)$project['preview']['domain'])
        || (string)$project['production']['path'] === (string)$project['preview']['path']) {
        throw new RuntimeException('Stored project configuration is invalid.');
    }

    foreach (devConsoleProjects(devConsoleRemoveProjectFromConfiguration($configuration, (string)$project['id'])) as $existingProject) {
        foreach (['production', 'preview'] as $environment) {
            $existingDomain = devConsoleNormalizeDomain((string)($existingProject[$environment]['domain'] ?? ''));
            $existingPath = (string)($existingProject[$environment]['path'] ?? '');
            if ($existingDomain !== '' && ($existingDomain === devConsoleNormalizeDomain((string)$project['production']['domain']) || $existingDomain === devConsoleNormalizeDomain((string)$project['preview']['domain']))) {
                throw new RuntimeException('Stored project domain conflicts with another project.');
            }
            if ($existingPath !== '' && ($existingPath === (string)$project['production']['path'] || $existingPath === (string)$project['preview']['path'])) {
                throw new RuntimeException('Stored project path conflicts with another project.');
            }
        }
    }
}

function projectProvision(array $configuration, string $projectId, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return projectActionResult(false, 'Project not found.');
    }
    if ((string)($project['managed_server_id'] ?? '') !== '') {
        return projectRemoteSetup($configuration, $projectId, $options);
    }

    $availableDir = $options['available_dir'] ?? '/etc/apache2/sites-available';
    $enabledDir = $options['enabled_dir'] ?? '/etc/apache2/sites-enabled';
    $allowedBase = $options['allowed_base'] ?? '/var/www/projects';
    $runCommands = $options['run_commands'] ?? true;
    $log = [];
    $createdDirectories = [];
    $createdPlaceholders = [];
    $updatedPlaceholders = [];
    $createdVhosts = [];
    $enabledSites = [];
    $siteStateChanged = false;

    try {
        if (!devConsoleProjectUsesGeneratedEnvironmentPaths($project)) {
            throw new RuntimeException('This project uses custom environment paths and cannot be set up automatically.');
        }
        projectValidateStoredConfiguration($configuration, $project);
        if ($runCommands) {
            $apacheState = apacheState();
            if (empty($apacheState['installed']) || empty($apacheState['running'])) {
                throw new RuntimeException('Apache must be installed and running before provisioning.');
            }
        }

        $paths = projectAssertManagedPathPolicy($project, $allowedBase);
        foreach (['production', 'preview'] as $environment) {
            if (!projectDirectoryAcceptableForProvisioning($paths[$environment])) {
                throw new RuntimeException(ucfirst($environment) . ' directory is not empty.');
            }
        }

        foreach (['production', 'preview'] as $environment) {
            $path = $paths[$environment];
            if (!is_dir($path)) {
                if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                    throw new RuntimeException('Unable to create ' . $environment . ' directory.');
                }
                $createdDirectories[] = $path;
                $log[] = 'Created directory: ' . $path;
            }
            $entries = array_values(array_filter(scandir($path) ?: [], fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
            if (empty($entries)) {
                $placeholder = $path . '/' . DEV_CONSOLE_PLACEHOLDER_FILE;
                if (!projectAtomicWrite($placeholder, projectPlaceholderContent($project, $environment))) {
                    throw new RuntimeException('Unable to write placeholder for ' . $environment . '.');
                }
                $createdPlaceholders[] = $placeholder;
                $log[] = 'Created placeholder: ' . $placeholder;
            } elseif (count($entries) === 1 && $entries[0] === DEV_CONSOLE_PLACEHOLDER_FILE) {
                $placeholder = $path . '/' . DEV_CONSOLE_PLACEHOLDER_FILE;
                $existingPlaceholder = (string)@file_get_contents($placeholder);
                if (projectIsPlaceholderFile($placeholder) && !projectPlaceholderMatches($existingPlaceholder, $project, $environment)) {
                    if (!projectAtomicWrite($placeholder, projectPlaceholderContent($project, $environment))) {
                        throw new RuntimeException('Unable to update placeholder for ' . $environment . '.');
                    }
                    $updatedPlaceholders[$placeholder] = $existingPlaceholder;
                    $log[] = 'Updated placeholder markers: ' . $placeholder;
                }
            }
        }

        if (!is_dir($availableDir) && !@mkdir($availableDir, 0755, true) && !is_dir($availableDir)) {
            throw new RuntimeException('Unable to access Apache sites-available directory.');
        }

        foreach (['production', 'preview'] as $environment) {
            $vhostPath = projectVhostPath($project, $environment, $availableDir);
            $content = projectGenerateVhost($project, $environment, $paths[$environment]);
            if (is_file($vhostPath)) {
                if (!projectVhostMatches($vhostPath, $project, $environment, $paths[$environment])) {
                    throw new RuntimeException('Refusing to overwrite unrelated Apache config: ' . basename($vhostPath));
                }
            } else {
                if (@file_put_contents($vhostPath, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write Apache config: ' . basename($vhostPath));
                }
                $createdVhosts[] = $vhostPath;
                $log[] = 'Created Apache config: ' . basename($vhostPath);
            }
        }

        if ($runCommands) {
            $serverNameResult = apacheEnsureServerNameConfig($options['apache_options'] ?? []);
            $log[] = $serverNameResult['message'];
            if (trim((string)$serverNameResult['output']) !== '') {
                $log[] = trim((string)$serverNameResult['output']);
            }
            if (empty($serverNameResult['success'])) {
                throw new RuntimeException('Apache ServerName config failed.');
            }

            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed.');
            foreach (['production', 'preview'] as $environment) {
                if (!file_exists(projectEnabledPath($project, $environment, $enabledDir))) {
                    $result = projectRunFixedCommand([projectApacheCommandPath('a2ensite'), projectEnvironmentVhostName($project, $environment)]);
                    projectAppendCommandLog($log, $result);
                    if ($result['exit_code'] !== 0) throw new RuntimeException('Unable to enable ' . $environment . ' site.');
                    $enabledSites[] = $environment;
                    $siteStateChanged = true;
                }
            }
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed after enabling sites.');
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache reload failed.');

            $verification = projectVerifyRouting($project, $options);
            foreach ($verification['log'] as $verificationLine) {
                $log[] = $verificationLine;
            }
            if (empty($verification['success'])) {
                throw new RuntimeException('Project routing verification failed.');
            }
        } else {
            $verification = ['success' => false, 'results' => []];
        }

        $project['provisioning'] = [
            'managed' => true,
            'provisioned_at' => date('c'),
            'production_vhost' => projectEnvironmentVhostName($project, 'production'),
            'preview_vhost' => projectEnvironmentVhostName($project, 'preview'),
            'routing_verified_at' => !empty($verification['success']) ? date('c') : null,
            'production_routing_verified' => !empty($verification['results']['production']['matched']),
            'preview_routing_verified' => !empty($verification['results']['preview']['matched']),
        ];
        if (empty($options['skip_save'])) {
            $updatedConfiguration = devConsoleTouchProject(devConsoleReplaceProject($configuration, $project), (string)$project['id']);
            if (!devConsoleSaveProjectConfiguration($updatedConfiguration)) {
                throw new RuntimeException('Unable to save provisioning metadata.');
            }
        }

        return projectActionResult(true, 'Project set up.', $log);
    } catch (Throwable $exception) {
        foreach (array_reverse($enabledSites) as $environment) {
            if ($runCommands) {
                $result = projectRunFixedCommand([projectApacheCommandPath('a2dissite'), projectEnvironmentVhostName($project, $environment)]);
                projectAppendCommandLog($log, $result);
                $siteStateChanged = true;
            }
        }
        foreach (array_reverse($createdVhosts) as $path) {
            if (is_file($path)) {
                @unlink($path);
                $log[] = 'Rolled back Apache config: ' . basename($path);
            }
        }
        foreach (array_reverse($createdPlaceholders) as $path) {
            if (is_file($path)) {
                @unlink($path);
                $log[] = 'Rolled back placeholder: ' . $path;
            }
        }
        foreach ($updatedPlaceholders as $path => $contents) {
            if (is_file($path)) {
                @file_put_contents($path, $contents, LOCK_EX);
                $log[] = 'Restored previous placeholder: ' . $path;
            }
        }
        foreach (array_reverse($createdDirectories) as $path) {
            @rmdir($path);
            $log[] = 'Rolled back directory if empty: ' . $path;
        }
        if ($siteStateChanged && $runCommands) {
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
        }

        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectRemoveFromConsole(array $configuration, string $projectId): array
{
    if (devConsoleFindProjectById($configuration, $projectId) === null) {
        return projectActionResult(false, 'Project not found.');
    }
    if (!devConsoleSaveProjectConfiguration(devConsoleRemoveProjectFromConfiguration($configuration, $projectId))) {
        return projectActionResult(false, 'Unable to save project configuration.');
    }

    return projectActionResult(true, 'Project removed from Dev Console.');
}

function projectRemoteDelete(array $configuration, array $project, string $projectId, string $availableDir, string $enabledDir, string $allowedBase, array $options = []): array
{
    $servers = is_array($options['managed_servers'] ?? null)
        ? $options['managed_servers']
        : (function_exists('managedServersLoad') ? managedServersLoad() : []);
    $serverId = (string)($project['managed_server_id'] ?? '');
    $server = function_exists('managedServersFind') ? managedServersFind($servers, $serverId) : null;
    $runCommands = $options['run_commands'] ?? true;
    $log = [];
    $capabilities = ['apache_privilege' => 'direct'];

    try {
        if ($server === null) {
            throw new RuntimeException('Managed Server not found.');
        }
        projectRemoteValidateServer($server);
        $paths = projectAssertManagedPathPolicy($project, $allowedBase);
        if ($runCommands) {
            $capabilities = projectRemoteCheckCapabilities($server, $log);
            if (!projectRemoteCapabilityReady($capabilities)) {
                $message = projectRemotePrivilegeDiagnostic($server, $capabilities);
                $result = projectActionResult(false, 'Project deletion cannot continue. Server preparation is required.', $log);
                $result['setup_command'] = projectRemoteServerSetupCommand($server);
                $result['output'] .= ($result['output'] === '' ? '' : "\n\n") . $message;
                return $result;
            }
        }
        $targets = [];
        foreach (['production', 'preview'] as $environment) {
            $target = projectResolveDeletionVhostTarget($project, $environment, $availableDir, $enabledDir, true);
            if (empty($target['safe'])) {
                throw new RuntimeException((string)$target['error']);
            }
            $statusResult = $runCommands
                ? projectRemoteRun($server, projectRemoteApacheMarkerStatusCommand((string)$target['path'], $project, $environment), $log)
                : ['exit_code' => 0, 'stdout' => "exists=0\n", 'success' => true, 'output' => ''];
            if ((int)($statusResult['exit_code'] ?? 1) !== 0) {
                throw new RuntimeException(projectVhostDeletionDiagnostic($target, 'Unable to inspect remote Apache vhost ownership markers.'));
            }
            $status = projectParseRemoteMarkerStatus((string)($statusResult['stdout'] ?? ''));
            $target = array_merge($target, $status);
            if (!empty($target['exists']) && empty($target['matches'])) {
                throw new RuntimeException(projectVhostDeletionDiagnostic($target, 'Cannot safely prove this Apache vhost belongs to Project ' . (string)$project['id'] . '.'));
            }
            if ((string)$target['stored'] !== (string)$target['expected']) {
                $log[] = ucfirst($environment) . ' uses legacy stored vhost filename "' . (string)$target['stored'] . '" instead of current convention "' . (string)$target['expected'] . '".';
            }
            if (empty($target['exists'])) {
                $log[] = ucfirst($environment) . ' Apache config already absent: ' . (string)$target['stored'];
            }
            $targets[$environment] = $target;
        }
        projectHandleGithubRepositoryDeletion($project, $log, $options);

        if ($runCommands) {
            foreach ($targets as $environment => $target) {
                $enabledCheck = projectRemoteRun($server, 'test -e ' . projectRemoteShellPath((string)$target['enabled_path']) . ' || test -L ' . projectRemoteShellPath((string)$target['enabled_path']), $log);
                if ((int)$enabledCheck['exit_code'] === 0) {
                    $result = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'a2dissite ' . escapeshellarg((string)$target['stored'])), $log);
                    if ((int)$result['exit_code'] !== 0) {
                        throw new RuntimeException('Unable to disable ' . $environment . ' site.');
                    }
                }
            }
            $result = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'apache2ctl configtest'), $log);
            if ((int)$result['exit_code'] !== 0) {
                throw new RuntimeException('Apache configtest failed.');
            }
            $result = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'systemctl reload apache2'), $log);
            if ((int)$result['exit_code'] !== 0) {
                throw new RuntimeException('Apache reload failed.');
            }
            foreach ($targets as $environment => $target) {
                if (!empty($target['exists'])) {
                    $result = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'rm -f -- ' . projectRemoteShellPath((string)$target['path'])), $log);
                    if ((int)$result['exit_code'] !== 0) {
                        throw new RuntimeException('Unable to delete ' . $environment . ' Apache config.');
                    }
                    $log[] = 'Deleted remote Apache config: ' . (string)$target['stored'];
                }
            }
            foreach (['production', 'preview'] as $environment) {
                $result = projectRemoteRun($server, projectRemotePrivilegedCommand($capabilities, 'rm -rf -- ' . projectRemoteShellPath($paths[$environment])), $log);
                if ((int)$result['exit_code'] !== 0) {
                    throw new RuntimeException('Unable to delete remote ' . $environment . ' directory.');
                }
                $log[] = 'Deleted remote directory if present: ' . $paths[$environment];
            }
            $projectRoot = dirname($paths['production']);
            $quotedRoot = projectRemoteShellPath($projectRoot);
            $rootResult = projectRemoteRun($server, 'if [ ! -e ' . $quotedRoot . ' ]; then printf %s absent; elif ' . projectRemotePrivilegedCommand($capabilities, 'rmdir -- ' . $quotedRoot) . ' 2>/dev/null; then printf %s removed; else printf %s preserved; fi', $log);
            $rootState = trim((string)($rootResult['stdout'] ?? ''));
            if ($rootState === 'removed') {
                $log[] = 'Deleted empty remote project root: ' . $projectRoot;
            } elseif ($rootState === 'preserved') {
                $log[] = 'Remote project root preserved because it is not empty: ' . $projectRoot;
            }
        }
        if (!empty($project['git']['connected'])) {
            $log[] = 'Git repository preserved: ' . (string)$project['repository_path'];
        }
        if (empty($options['skip_save'])) {
            if (!devConsoleSaveProjectConfiguration(devConsoleRemoveProjectFromConfiguration($configuration, $projectId))) {
                throw new RuntimeException('Unable to save project configuration.');
            }
        }

        $result = projectActionResult(true, 'Project deleted.', $log);
        $result['summary_steps'] = projectDeleteSummarySteps($project, $log, $options);
        return $result;
    } catch (Throwable $exception) {
        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectSafeRecursiveDelete(string $path, string $expectedPath, string $repositoryPath, array &$log, string $allowedBase = '/var/www/projects'): void
{
    $target = projectNormalizePath($path);
    if ($target !== projectNormalizePath($expectedPath)) {
        throw new RuntimeException('Deletion target does not match stored project path.');
    }
    $paths = projectAssertManagedPathPolicy([
        'repository_path' => $repositoryPath,
        'production' => ['path' => $target],
        'preview' => ['path' => $target . '-delete-check'],
    ], $allowedBase);

    if (is_link($target)) {
        @unlink($target);
        $log[] = 'Deleted symlink: ' . $target;
        return;
    }
    if (!file_exists($target)) {
        return;
    }
    if (!is_dir($target)) {
        throw new RuntimeException('Deletion target is not a directory.');
    }

    foreach (scandir($target) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $target . '/' . $entry;
        if (is_link($child) || is_file($child)) {
            if (!@unlink($child)) throw new RuntimeException('Unable to delete file: ' . $child);
            $log[] = 'Deleted file: ' . $child;
        } elseif (is_dir($child)) {
            projectSafeRecursiveDelete($child, $child, $repositoryPath, $log, $allowedBase);
        }
    }
    if (!@rmdir($target)) {
        throw new RuntimeException('Unable to remove directory: ' . $target);
    }
    $log[] = 'Deleted directory: ' . $target;
}

function projectRemoveEmptyGeneratedRoot(array $project, array &$log, string $allowedBase = '/var/www/projects'): void
{
    $projectId = (string)($project['id'] ?? '');
    if (!projectSafeId($projectId)) {
        throw new RuntimeException('Invalid project identifier.');
    }
    $base = rtrim(projectNormalizePath($allowedBase), '/');
    $productionPath = projectNormalizePath((string)($project['production']['path'] ?? ''));
    $previewPath = projectNormalizePath((string)($project['preview']['path'] ?? ''));
    $root = projectNormalizePath(dirname($productionPath));
    if (projectNormalizePath(dirname($previewPath)) !== $root || basename($productionPath) !== 'production' || basename($previewPath) !== 'preview') {
        $log[] = 'Project root preserved because environment paths do not match the generated layout.';
        return;
    }
    if (!str_starts_with($root . '/', $base . '/')) {
        throw new RuntimeException('Project root is outside the managed Project base.');
    }
    if (!file_exists($root)) {
        return;
    }
    if (is_link($root) || !is_dir($root)) {
        $log[] = 'Project root preserved because it is not a normal directory: ' . $root;
        return;
    }
    $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
    if (!empty($entries)) {
        $log[] = 'Project root preserved because it is not empty: ' . $root;
        return;
    }
    if (!@rmdir($root)) {
        $log[] = 'Project root preserved because it could not be removed: ' . $root;
        return;
    }

    $log[] = 'Deleted empty project root: ' . $root;
}

function projectHandleGithubRepositoryDeletion(array $project, array &$log, array $options = []): void
{
    $policy = (string)($options['github_repository_policy'] ?? 'keep');
    if ($policy !== 'delete') {
        $log[] = 'GitHub repository preserved.';
        return;
    }
    if (!function_exists('gitDeleteConfiguredGithubRepository')) {
        throw new RuntimeException('GitHub repository deletion is unavailable.');
    }
    $github = is_array($options['github_configuration'] ?? null) ? $options['github_configuration'] : devConsoleLoadGithubConfiguration();
    $delete = gitDeleteConfiguredGithubRepository($project, $github, $log, $options);
    if (empty($delete['success'])) {
        throw new RuntimeException((string)($delete['message'] ?? 'GitHub repository deletion failed.'));
    }
}

function projectGithubRepositorySummaryLabel(array $project, array $options = []): string
{
    if (function_exists('gitConfiguredRepositoryIdentity')) {
        $github = is_array($options['github_configuration'] ?? null) ? $options['github_configuration'] : null;
        [$owner, $name, $error] = gitConfiguredRepositoryIdentity($project, $github);
        if ($error === '' && $owner !== '' && $name !== '') {
            return $owner . '/' . $name;
        }
    }

    return 'GitHub repository';
}

function projectDeleteSummarySteps(array $project, array $log, array $options = []): array
{
    $rootRemoved = false;
    foreach ($log as $line) {
        $text = (string)$line;
        if (str_contains($text, 'Deleted empty project root') || str_contains($text, 'Deleted empty remote project root')) {
            $rootRemoved = true;
            break;
        }
    }
    $repositoryLabel = projectGithubRepositorySummaryLabel($project, $options);
    $deleteGithub = (string)($options['github_repository_policy'] ?? 'keep') === 'delete';

    $githubSummary = $repositoryLabel === 'GitHub repository'
        ? 'GitHub repository'
        : 'GitHub repository ' . $repositoryLabel;

    return [
        'Done: Managed Apache configuration removed',
        'Done: Managed Project directories removed',
        $rootRemoved ? 'Done: Project root removed' : 'Kept: Project root preserved',
        'Done: Project registration removed',
        $deleteGithub ? 'Done: ' . $githubSummary . ' deleted' : 'Kept: ' . $githubSummary,
    ];
}

function projectDelete(array $configuration, string $projectId, string $confirmation, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return projectActionResult(false, 'Project not found.');
    if ($confirmation !== projectMessageName($project, $projectId)) return projectActionResult(false, 'Project confirmation did not match.');
    if (empty($project['provisioning']['managed'])) return projectActionResult(false, 'Delete Project is available only for Dev Console-managed projects.');

    $availableDir = $options['available_dir'] ?? '/etc/apache2/sites-available';
    $enabledDir = $options['enabled_dir'] ?? '/etc/apache2/sites-enabled';
    $allowedBase = $options['allowed_base'] ?? '/var/www/projects';
    $runCommands = $options['run_commands'] ?? true;
    $log = [];

    try {
        if ((string)($project['managed_server_id'] ?? '') !== '') {
            return projectRemoteDelete($configuration, $project, $projectId, $availableDir, $enabledDir, $allowedBase, $options);
        }
        $paths = projectAssertManagedPathPolicy($project, $allowedBase);
        $targets = [];
        foreach (['production', 'preview'] as $environment) {
            $target = projectResolveDeletionVhostTarget($project, $environment, $availableDir, $enabledDir);
            if (empty($target['safe']) || !empty($target['error'])) {
                throw new RuntimeException((string)$target['error']);
            }
            if ((string)$target['stored'] !== (string)$target['expected']) {
                $log[] = ucfirst($environment) . ' uses legacy stored vhost filename "' . (string)$target['stored'] . '" instead of current convention "' . (string)$target['expected'] . '".';
            }
            if (empty($target['exists'])) {
                $log[] = ucfirst($environment) . ' Apache config already absent: ' . (string)$target['stored'];
            }
            $targets[$environment] = $target;
        }
        projectHandleGithubRepositoryDeletion($project, $log, $options);

        if ($runCommands) {
            foreach ($targets as $environment => $target) {
                if (file_exists((string)$target['enabled_path']) || is_link((string)$target['enabled_path'])) {
                    $result = projectRunFixedCommand([projectApacheCommandPath('a2dissite'), (string)$target['stored']]);
                    projectAppendCommandLog($log, $result);
                    if ($result['exit_code'] !== 0) throw new RuntimeException('Unable to disable ' . $environment . ' site.');
                }
            }
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed.');
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache reload failed.');
        }

        foreach ($targets as $target) {
            $vhostPath = (string)$target['path'];
            if (is_file($vhostPath)) {
                if (!@unlink($vhostPath)) throw new RuntimeException('Unable to delete Apache config: ' . basename($vhostPath));
                $log[] = 'Deleted Apache config: ' . basename($vhostPath);
            }
        }
        foreach (['production', 'preview'] as $environment) {
            projectSafeRecursiveDelete($paths[$environment], $paths[$environment], (string)$project['repository_path'], $log, $allowedBase);
        }
        projectRemoveEmptyGeneratedRoot($project, $log, $allowedBase);
        if (!empty($project['git']['connected'])) {
            $log[] = 'Git repository preserved: ' . (string)$project['repository_path'];
        }
        if (empty($options['skip_save'])) {
            if (!devConsoleSaveProjectConfiguration(devConsoleRemoveProjectFromConfiguration($configuration, $projectId))) {
                throw new RuntimeException('Unable to save project configuration.');
            }
        }

        $result = projectActionResult(true, 'Project deleted.', $log);
        $result['summary_steps'] = projectDeleteSummarySteps($project, $log, $options);
        return $result;
    } catch (Throwable $exception) {
        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectOrphanedApacheInfrastructure(array $configuration, array $sites): array
{
    $registeredIds = array_flip(array_map(static fn(array $project): string => (string)($project['id'] ?? ''), devConsoleProjects($configuration)));
    $orphans = [];

    foreach ($sites as $site) {
        $name = (string)($site['name'] ?? '');
        if (preg_match('/^dev-console-([a-z0-9]+(?:-[a-z0-9]+)*)-(production|preview)\.conf$/', $name, $matches) !== 1) {
            continue;
        }
        $projectId = $matches[1];
        $environment = $matches[2];
        if (isset($registeredIds[$projectId]) || !projectSafeId($projectId)) {
            continue;
        }
        $path = (string)($site['path'] ?? '');
        if ($path !== '' && is_file($path) && !projectVhostMarkersMatch($path, ['id' => $projectId], $environment)) {
            continue;
        }

        $paths = devConsoleGeneratedEnvironmentPaths($projectId);
        $orphans[$projectId] ??= [
            'project_id' => $projectId,
            'production' => null,
            'preview' => null,
            'production_path' => $paths['production'],
            'preview_path' => $paths['preview'],
            'git_repository_path' => devConsoleGeneratedRepositoryPath($projectId),
        ];
        $orphans[$projectId][$environment] = $site;
    }

    ksort($orphans, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($orphans);
}

function projectCleanupOrphanedInfrastructure(array $configuration, string $projectId, array $options = []): array
{
    if (!projectSafeId($projectId)) {
        return projectActionResult(false, 'Invalid project identifier.');
    }
    if (devConsoleFindProjectById($configuration, $projectId) !== null || devConsoleActiveProjectId($configuration) === $projectId) {
        return projectActionResult(false, 'Clean Up is not available for a registered Project.');
    }

    $availableDir = $options['available_dir'] ?? '/etc/apache2/sites-available';
    $enabledDir = $options['enabled_dir'] ?? '/etc/apache2/sites-enabled';
    $allowedBase = $options['allowed_base'] ?? '/var/www/projects';
    $runCommands = $options['run_commands'] ?? true;
    $log = [];
    $paths = devConsoleGeneratedEnvironmentPaths($projectId);
    $repositoryPath = devConsoleGeneratedRepositoryPath($projectId);
    $pseudoProject = [
        'id' => $projectId,
        'repository_path' => $repositoryPath,
        'production' => ['path' => $paths['production']],
        'preview' => ['path' => $paths['preview']],
    ];

    try {
        projectAssertManagedPathPolicy($pseudoProject, $allowedBase);
        $foundManagedConfig = false;
        foreach (['production', 'preview'] as $environment) {
            $vhostPath = projectVhostPath($pseudoProject, $environment, $availableDir);
            $enabledPath = projectEnabledPath($pseudoProject, $environment, $enabledDir);
            if (is_file($vhostPath) || is_file($enabledPath)) {
                $foundManagedConfig = true;
                $pathToCheck = is_file($vhostPath) ? $vhostPath : $enabledPath;
                if (!projectVhostMarkersMatch($pathToCheck, $pseudoProject, $environment)) {
                    throw new RuntimeException('Refusing to clean up unverified Apache config: ' . basename($vhostPath));
                }
            }
        }
        if (!$foundManagedConfig) {
            return projectActionResult(false, 'No orphaned Dev Console Apache configuration found.');
        }

        if ($runCommands) {
            foreach (['production', 'preview'] as $environment) {
                if (file_exists(projectEnabledPath($pseudoProject, $environment, $enabledDir))) {
                    $result = projectRunFixedCommand([projectApacheCommandPath('a2dissite'), projectEnvironmentVhostName($pseudoProject, $environment)]);
                    projectAppendCommandLog($log, $result);
                    if ($result['exit_code'] !== 0 && is_file(projectVhostPath($pseudoProject, $environment, $availableDir))) throw new RuntimeException('Unable to disable orphaned ' . $environment . ' site.');
                }
            }
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed.');
        }

        foreach (['production', 'preview'] as $environment) {
            $enabledPath = projectEnabledPath($pseudoProject, $environment, $enabledDir);
            if (is_link($enabledPath)) {
                if (!@unlink($enabledPath)) throw new RuntimeException('Unable to delete enabled Apache link: ' . basename($enabledPath));
                $log[] = 'Deleted enabled Apache link: ' . basename($enabledPath);
            }
            $vhostPath = projectVhostPath($pseudoProject, $environment, $availableDir);
            if (is_file($vhostPath)) {
                if (!@unlink($vhostPath)) throw new RuntimeException('Unable to delete Apache config: ' . basename($vhostPath));
                $log[] = 'Deleted orphaned Apache config: ' . basename($vhostPath);
            }
        }
        foreach (['production', 'preview'] as $environment) {
            projectSafeRecursiveDelete($paths[$environment], $paths[$environment], $repositoryPath, $log, $allowedBase);
        }
        projectRemoveEmptyGeneratedRoot($pseudoProject, $log, $allowedBase);
        $log[] = 'Git repository preserved: ' . $repositoryPath;
        $log[] = 'GitHub repository preserved.';

        if ($runCommands) {
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed after cleanup.');
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache reload failed.');
        }

        return projectActionResult(true, 'Orphaned Dev Console infrastructure cleaned up.', $log);
    } catch (Throwable $exception) {
        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectMessageName(?array $project, string $fallback = ''): string
{
    $name = trim((string)($project['name'] ?? ''));
    if ($name === '') {
        $name = trim($fallback);
    }

    return $name === '' ? 'Project' : $name;
}

function projectLifecycleLabel(array $project, array $projectStatus): string
{
    $status = (string)($projectStatus['label'] ?? 'Not set up');
    $managed = !empty($project['provisioning']['managed']);
    if ($status === 'Ready') {
        return $managed ? 'Ready' : 'Imported';
    }
    if ($status === 'Configuration drift' || $status === 'Incomplete' || $status === 'Update required' || $status === 'Setup failed') {
        return $status;
    }

    return $managed ? 'Not set up' : 'New';
}

function operationSummarySteps(string $action, array $result): array
{
    if (!empty($result['summary_steps']) && is_array($result['summary_steps'])) {
        return array_values(array_filter(array_map('strval', $result['summary_steps'])));
    }
    if (empty($result['success'])) {
        return [];
    }

    if ($action === 'initialize_repository') {
        return [
            'Local repository prepared',
            'GitHub repository verified',
            'First push completed',
            'Remote branch verified',
        ];
    }
    if ($action === 'fetch_git_repository') {
        return ['Remote changes fetched', 'Git status refreshed'];
    }
    if ($action === 'pull_git_repository') {
        return ['Remote changes fetched', 'Fast-forward pull completed', 'Git status refreshed'];
    }
    if ($action === 'push_git_repository') {
        return ['Local commits pushed', 'Remote branch verified', 'Git status refreshed'];
    }
    if ($action === 'provision_project') {
        return ['Project directories prepared', 'Apache configuration ready', 'Apache validated and reloaded'];
    }
    if ($action === 'delete_project') {
        return ['Managed Apache configuration removed', 'Managed project directories removed', 'Git repository preserved'];
    }
    if ($action === 'cleanup_orphaned_project') {
        return ['Orphaned Apache configuration removed', 'Orphaned project directories removed', 'Git repositories preserved'];
    }
    if ($action === 'remove_project') {
        return ['Project registration removed', 'Server files preserved'];
    }

    return [];
}
