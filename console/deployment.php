<?php

const DEPLOY_SOURCE = '/var/www/iovon-ai-dev';
const PREVIEW_DOCUMENT_ROOT_FALLBACK = '/var/www/iovon-ai';
const PRODUCTION_DOCUMENT_ROOT_FALLBACK = '/var/www/io';
const PREVIEW_URL = 'https://labs.iovon.com';
const PRODUCTION_URL = 'https://iovon.com';
const DEV_CONSOLE_URL = 'https://iovon.blowfish-kitchen.ts.net';
const DEPLOY_STATE_DIR = '/tmp/iovon-deployments';
const DASHBOARD_CACHE_TTL = 60;
const DASHBOARD_COMMAND_TIMEOUT = 2.0;
const DEPLOY_REQUIRED_FILES = ['index.php', 'pages/articles/articles.json'];
const DEPLOY_REQUIRED_DIRS = ['assets', 'blocks', 'data', 'pages'];
const DEPLOY_EXCLUDES = [
    '/.git/', '/.gitignore', '/.env.php', '/.htaccess', '/TASKS/', '/tools/', '/bin/',
    '/docs/', '/logs/', '/tmp/', '/temp/', '*.tmp', '*.temp', '*.bak', '*~',
];

$DEPLOY_ACTIVE_PROJECT = null;

function deploymentSetProject(?array $project): void
{
    global $DEPLOY_ACTIVE_PROJECT;
    $DEPLOY_ACTIVE_PROJECT = $project;
}

function deploymentActiveProject(): ?array
{
    global $DEPLOY_ACTIVE_PROJECT;
    return is_array($DEPLOY_ACTIVE_PROJECT) ? $DEPLOY_ACTIVE_PROJECT : null;
}

function deploymentSourcePath(): string
{
    $project = deploymentActiveProject();
    return $project === null ? DEPLOY_SOURCE : (string)($project['repository_path'] ?? DEPLOY_SOURCE);
}

function deploymentProjectStateKey(): string
{
    $project = deploymentActiveProject();
    $projectId = is_array($project) ? (string)($project['id'] ?? '') : '';
    return $projectId === '' ? 'legacy' : $projectId;
}

function deploymentCommand(array $arguments, ?string $cwd = null): array
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start deployment command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit_code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function deploymentGitValue(array $arguments): string
{
    $result = deploymentCommand(array_merge(['git', '-C', deploymentSourcePath()], $arguments));
    return $result['exit_code'] === 0 ? trim($result['stdout']) : '';
}

function deploymentConfiguration(string $environment): array
{
    $project = deploymentActiveProject();
    if ($project !== null) {
        $configurations = [
            'preview' => [
                'target' => (string)($project['preview']['path'] ?? ''),
                'url' => (string)($project['preview']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['preview']['domain'],
                'host' => (string)($project['preview']['domain'] ?? ''),
            ],
            'production' => [
                'target' => (string)($project['production']['path'] ?? ''),
                'url' => (string)($project['production']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['production']['domain'],
                'host' => (string)($project['production']['domain'] ?? ''),
            ],
        ];
        if (!isset($configurations[$environment])) throw new RuntimeException('Invalid deployment environment.');
        return $configurations[$environment];
    }

    $configurations = [
        'preview' => ['target' => PREVIEW_DOCUMENT_ROOT_FALLBACK, 'url' => PREVIEW_URL, 'host' => 'labs.iovon.com'],
        'production' => ['target' => PRODUCTION_DOCUMENT_ROOT_FALLBACK, 'url' => PRODUCTION_URL, 'host' => 'iovon.com'],
    ];
    if (!isset($configurations[$environment])) throw new RuntimeException('Invalid deployment environment.');
    return $configurations[$environment];
}

function deploymentRsyncArguments(string $environment, bool $dryRun): array
{
    $configuration = deploymentConfiguration($environment);
    $arguments = ['rsync', '-a', '--delete', '--itemize-changes', '--out-format=%i|%n%L'];
    if ($dryRun) {
        $arguments[] = '--dry-run';
    }
    foreach (DEPLOY_EXCLUDES as $exclude) {
        $arguments[] = '--exclude=' . $exclude;
    }
    $arguments[] = rtrim(deploymentSourcePath(), '/') . '/';
    $arguments[] = rtrim($configuration['target'], '/') . '/';
    return $arguments;
}

function enabledApacheDocumentRoot(string $serverName, string $fallback, array $preferredFiles = []): string
{
    $configurationFiles = glob('/etc/apache2/sites-enabled/*.conf') ?: [];
    usort($configurationFiles, static function (string $left, string $right) use ($preferredFiles): int {
        $leftRank = array_search(basename($left), $preferredFiles, true);
        $rightRank = array_search(basename($right), $preferredFiles, true);
        return ($leftRank === false ? PHP_INT_MAX : $leftRank) <=> ($rightRank === false ? PHP_INT_MAX : $rightRank);
    });

    foreach ($configurationFiles as $configurationFile) {
        $configuration = file_get_contents($configurationFile);
        if ($configuration === false) continue;
        $isPreferred = in_array(basename($configurationFile), $preferredFiles, true);
        $hasServerName = preg_match('/^\s*ServerName\s+' . preg_quote($serverName, '/') . '\s*$/mi', $configuration) === 1;
        if (!$isPreferred && !$hasServerName) continue;
        if (preg_match('/^\s*DocumentRoot\s+["\']?([^\s"\']+)/mi', $configuration, $matches) === 1) {
            return rtrim($matches[1], '/');
        }
    }

    return $fallback;
}

function deploymentEnvironment(): array
{
    $project = deploymentActiveProject();
    if ($project !== null) {
        return [
            'development_path' => deploymentSourcePath(),
            'active_project_id' => (string)($project['id'] ?? ''),
            'active_project_name' => (string)($project['name'] ?? ''),
            'preview_document_root' => (string)($project['preview']['path'] ?? ''),
            'preview_url' => (string)($project['preview']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['preview']['domain'],
            'production_document_root' => (string)($project['production']['path'] ?? ''),
            'production_url' => (string)($project['production']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['production']['domain'],
            'console_url' => DEV_CONSOLE_URL,
        ];
    }

    return [
        'development_path' => DEPLOY_SOURCE,
        'active_project_id' => '',
        'active_project_name' => '',
        'preview_document_root' => enabledApacheDocumentRoot('labs.iovon.com', PREVIEW_DOCUMENT_ROOT_FALLBACK, ['labs-le-ssl.conf', 'labs.conf']),
        'preview_url' => PREVIEW_URL,
        'production_document_root' => enabledApacheDocumentRoot('iovon.com', PRODUCTION_DOCUMENT_ROOT_FALLBACK, ['iovon-le-ssl.conf', 'iovon.conf']),
        'production_url' => PRODUCTION_URL,
        'console_url' => DEV_CONSOLE_URL,
    ];
}

function deploymentTargetIsExpected(string $environment): bool
{
    $target = deploymentConfiguration($environment)['target'];
    return realpath($target) === $target;
}

function deploymentValidation(string $environment): array
{
    $configuration = deploymentConfiguration($environment);
    $target = $configuration['target'];
    $label = ucfirst($environment);
    $errors = [];
    $source = deploymentSourcePath();
    if (!is_dir($source)) $errors[] = 'Source directory does not exist.';
    if (!is_dir($target)) $errors[] = 'Target directory does not exist.';
    if (!deploymentTargetIsExpected($environment)) $errors[] = $label . ' target must resolve to ' . $target . '.';
    $sourceReal = realpath($source);
    $targetReal = realpath($target);
    if ($sourceReal !== false && $sourceReal === $targetReal) $errors[] = 'Source and target directories must be different.';
    if (deploymentGitValue(['rev-parse', '--is-inside-work-tree']) !== 'true') $errors[] = 'Source is not a valid Git repository.';
    if (deploymentGitValue(['status', '--porcelain']) !== '') $errors[] = 'Source Git working tree is not clean.';
    if (deploymentGitValue(['branch', '--show-current']) !== 'main') $errors[] = 'Source Git branch is not main.';
    if (PHP_BINARY === '' || !is_executable(PHP_BINARY)) $errors[] = 'PHP is not available.';
    if (!is_writable($target)) $errors[] = $label . ' target is not writable by the deployment process.';
    if (deploymentActiveProject() === null) {
        foreach (DEPLOY_REQUIRED_FILES as $file) {
            if (!is_file(DEPLOY_SOURCE . '/' . $file)) $errors[] = 'Required source file is missing: ' . $file;
        }
    }
    if (trim((string)shell_exec('command -v rsync 2>/dev/null')) === '') $errors[] = 'rsync is not available.';
    return $errors;
}

function deploymentChangeSummary(string $output): array
{
    $summary = ['added' => 0, 'updated' => 0, 'deleted' => 0, 'files' => []];
    foreach (preg_split('/\r\n|\r|\n/', trim($output)) as $line) {
        if ($line === '' || !str_contains($line, '|')) continue;
        [$code, $path] = explode('|', $line, 2);
        if (str_starts_with($code, '*deleting')) {
            $summary['deleted']++;
            $summary['files'][] = 'Delete: ' . $path;
        } elseif (strlen($code) > 1 && $code[1] === 'f') {
            $key = str_contains($code, '+++++++++') ? 'added' : 'updated';
            $summary[$key]++;
            $summary['files'][] = ucfirst($key === 'added' ? 'add' : 'update') . ': ' . $path;
        }
    }
    return $summary;
}

function deploymentStateDir(string $environment): string
{
    deploymentConfiguration($environment);
    $projectKey = deploymentProjectStateKey();
    return $projectKey === 'legacy'
        ? DEPLOY_STATE_DIR . '/' . $environment
        : DEPLOY_STATE_DIR . '/deployments/' . $projectKey . '/' . $environment;
}

function ensureDeploymentStateDir(string $environment): void
{
    $stateDirectory = deploymentStateDir($environment);
    if (!is_dir($stateDirectory) && !mkdir($stateDirectory, 0750, true)) {
        throw new RuntimeException('Unable to create deployment state directory.');
    }
}

function deploymentStatePath(string $environment, string $id): string
{
    if (!preg_match('/^deploy-[0-9]{8}T[0-9]{6}-[a-f0-9]{8}$/', $id)) throw new RuntimeException('Invalid deployment ID.');
    return deploymentStateDir($environment) . '/' . $id . '.json';
}

function writeDeploymentState(array $state): void
{
    $environment = (string)$state['environment'];
    ensureDeploymentStateDir($environment);
    $path = deploymentStatePath($environment, (string)$state['id']);
    $temporary = $path . '.tmp';
    file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($temporary, $path);
    file_put_contents(deploymentStateDir($environment) . '/latest', $state['id'], LOCK_EX);
}

function readDeploymentState(string $environment, ?string $id = null): ?array
{
    if ($id === null) {
        $latest = deploymentStateDir($environment) . '/latest';
        if (!is_file($latest)) return null;
        $id = trim((string)file_get_contents($latest));
    }
    try { $path = deploymentStatePath($environment, $id); } catch (Throwable) { return null; }
    if (!is_file($path)) return null;
    $state = json_decode((string)file_get_contents($path), true);
    return is_array($state) ? $state : null;
}

function appendDeploymentLog(array &$state, string $message): void
{
    file_put_contents($state['log_path'], '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function newDeploymentState(string $environment, string $status, array $summary, string $initiator): array
{
    $configuration = deploymentConfiguration($environment);
    ensureDeploymentStateDir($environment);
    $id = 'deploy-' . gmdate('Ymd\THis') . '-' . bin2hex(random_bytes(4));
    return [
        'id' => $id, 'environment' => $environment, 'start_time' => date('c'), 'finish_time' => null,
        'project_id' => deploymentProjectStateKey() === 'legacy' ? null : deploymentProjectStateKey(),
        'source_commit' => deploymentGitValue(['rev-parse', 'HEAD']),
        'source_message' => deploymentGitValue(['log', '-1', '--pretty=%s']),
        'branch' => deploymentGitValue(['branch', '--show-current']), 'status' => $status,
        'initiator' => $initiator, 'summary' => $summary,
        'target' => $configuration['target'],
        'log_path' => deploymentStateDir($environment) . '/' . $id . '.log', 'error' => null,
    ];
}

function deploymentOverview(string $environment): array
{
    $configuration = deploymentConfiguration($environment);
    $latest = readDeploymentState($environment);
    $deployedCommit = $latest && ($latest['status'] ?? '') === 'success' ? (string)$latest['source_commit'] : '';
    if ($deployedCommit === '' && is_dir($configuration['target'] . '/.git')) {
        $result = deploymentCommand(['git', '-C', $configuration['target'], 'rev-parse', 'HEAD']);
        if ($result['exit_code'] === 0) $deployedCommit = trim($result['stdout']);
    }
    return [
        'source' => deploymentSourcePath(), 'target' => $configuration['target'], 'url' => $configuration['url'],
        'branch' => deploymentGitValue(['branch', '--show-current']),
        'commit' => deploymentGitValue(['rev-parse', 'HEAD']),
        'message' => deploymentGitValue(['log', '-1', '--pretty=%s']),
        'deployed_commit' => $deployedCommit, 'latest' => $latest,
    ];
}

function statusCommand(array $arguments): string
{
    try {
        $pipes = [];
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return '';
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $startedAt = microtime(true);
        $exitCode = -1;
        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                break;
            }
            if (microtime(true) - $startedAt >= DASHBOARD_COMMAND_TIMEOUT) {
                proc_terminate($process, 9);
                break;
            }
            usleep(10000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closedExitCode = proc_close($process);
        if ($exitCode < 0) $exitCode = $closedExitCode;
        return $exitCode === 0 ? trim($stdout) : '';
    } catch (Throwable) {
        return '';
    }
}

function installedVersion(array $arguments, ?string $pattern = null): string
{
    $output = statusCommand($arguments);
    if ($output === '') return 'Not installed';
    $line = trim((string)strtok($output, "\n"));
    if ($pattern !== null && preg_match($pattern, $line, $matches) === 1) {
        return $matches[1];
    }
    return $line;
}

function directoryStatistics(string $path, bool $includeCounts = false): array
{
    $statistics = ['files' => 0, 'directories' => 0, 'bytes' => 0];
    if (!is_dir($path) || !is_readable($path)) return $statistics;

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink()) continue;
            if ($entry->isDir()) {
                if ($includeCounts) $statistics['directories']++;
                continue;
            }
            if ($entry->isFile()) {
                if ($includeCounts) $statistics['files']++;
                $statistics['bytes'] += $entry->getSize();
            }
        }
    } catch (Throwable) {
        // Return the readable portion of the directory statistics.
    }
    return $statistics;
}

function memoryStatus(): array
{
    $values = [];
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches) === 1) {
            $values[$matches[1]] = (int)$matches[2] * 1024;
        }
    }
    $total = $values['MemTotal'] ?? 0;
    $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
    $used = max(0, $total - $available);
    return [
        'total' => $total, 'used' => $used, 'free' => $available,
        'percentage' => $total > 0 ? round($used / $total * 100, 1) : 0,
    ];
}

function topCpuProcesses(): array
{
    $output = statusCommand(['ps', '-eo', 'pid=,user=,pcpu=,pmem=,comm=', '--sort=-pcpu']);
    $processes = [];
    foreach (array_slice(preg_split('/\r\n|\r|\n/', $output) ?: [], 0, 5) as $line) {
        if (preg_match('/^\s*(\d+)\s+(\S+)\s+([\d.]+)\s+([\d.]+)\s+(.+)$/', $line, $matches) !== 1) continue;
        $processes[] = [
            'pid' => (int)$matches[1], 'user' => $matches[2], 'cpu' => (float)$matches[3],
            'memory' => (float)$matches[4], 'command' => trim($matches[5]),
        ];
    }
    return $processes;
}

function dashboardCachePath(string $name): string
{
    return DEPLOY_STATE_DIR . '/dashboard-' . preg_replace('/[^a-z0-9-]/', '', strtolower(deploymentProjectStateKey() . '-' . $name)) . '.json';
}

function readDashboardCache(string $name, ?int $maximumAge = null): ?array
{
    $path = dashboardCachePath($name);
    if (!is_file($path) || ($maximumAge !== null && time() - (filemtime($path) ?: 0) >= $maximumAge)) return null;
    $value = json_decode((string)@file_get_contents($path), true);
    return is_array($value) ? $value : null;
}

function writeDashboardCache(string $name, array $value): void
{
    if (!is_dir(DEPLOY_STATE_DIR)) @mkdir(DEPLOY_STATE_DIR, 0750, true);
    $path = dashboardCachePath($name);
    $temporary = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temporary, json_encode($value, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @rename($temporary, $path);
    }
}

function slowDashboardValues(): array
{
    $cached = readDashboardCache('slow-values', DASHBOARD_CACHE_TTL);
    if ($cached !== null) return $cached;
    $lastSuccessful = readDashboardCache('slow-values') ?? [];

    $environment = deploymentEnvironment();
    $os = trim((string)@file_get_contents('/etc/os-release'));
    $osName = '';
    if (preg_match('/^PRETTY_NAME=(?:"([^"]+)"|([^\r\n]+))$/m', $os, $matches) === 1) {
        $osName = $matches[1] !== '' ? $matches[1] : $matches[2];
    }
    $values = [
        'environment' => $environment,
        'software' => array_merge(
            function_exists('serverToolsDashboardSoftware') ? serverToolsDashboardSoftware() : [
                'PHP' => PHP_VERSION,
                'Composer' => installedVersion(['composer', '--version'], '/Composer(?: version)?\s+([^\s]+)/i'),
                'Node.js' => installedVersion(['node', '--version']),
                'npm' => installedVersion(['npm', '--version']),
                'Git' => installedVersion(['git', '--version'], '/git version\s+(.+)/i'),
                'Codex CLI' => installedVersion(['codex', '--version'], '/codex(?:-cli)?\s+(.+)/i'),
            ],
            [
                'Operating System' => $osName !== '' ? $osName : 'Not detected',
                'Kernel' => php_uname('r'),
            ]
        ),
        'statistics' => [
            'development' => directoryStatistics(deploymentSourcePath(), true),
            'preview' => directoryStatistics($environment['preview_document_root']),
            'production' => directoryStatistics($environment['production_document_root']),
        ],
    ];
    foreach ($values['statistics'] as $name => $statistics) {
        $previousStatistics = $lastSuccessful['statistics'][$name] ?? null;
        if (($statistics['bytes'] ?? 0) === 0 && is_array($previousStatistics) && ($previousStatistics['bytes'] ?? 0) > 0) {
            $values['statistics'][$name] = $previousStatistics;
        }
    }
    writeDashboardCache('slow-values', $values);
    return $values;
}

function operationalDashboard(): array
{
    $startedAt = microtime(true);
    $timings = [];
    $previous = readDashboardCache('last-success') ?? [];
    $metric = static function (string $name, callable $collector, mixed $fallback) use (&$timings): mixed {
        $metricStartedAt = microtime(true);
        try {
            $value = $collector();
            return $value;
        } catch (Throwable) {
            return $fallback;
        } finally {
            $timings[$name] = microtime(true) - $metricStartedAt;
        }
    };

    $slow = $metric('cached slow values', static fn (): array => slowDashboardValues(), $previous['_slow'] ?? []);
    $environment = $slow['environment'] ?? deploymentEnvironment();
    $preview = $metric('preview status', static fn (): array => deploymentOverview('preview'), $previous['_preview'] ?? []);
    $production = $metric('production status', static fn (): array => deploymentOverview('production'), $previous['_production'] ?? []);
    $memory = $metric('memory', static fn (): array => memoryStatus(), $previous['server']['memory'] ?? ['total' => 0, 'used' => 0, 'free' => 0, 'percentage' => 0]);
    $disk = $metric('disk', static function (): array {
        $total = @disk_total_space(deploymentSourcePath()) ?: 0;
        $free = @disk_free_space(deploymentSourcePath()) ?: 0;
        $used = max(0, $total - $free);
        return ['total' => $total, 'used' => $used, 'free' => $free, 'percentage' => $total > 0 ? round($used / $total * 100, 1) : 0];
    }, $previous['server']['disk'] ?? ['total' => 0, 'used' => 0, 'free' => 0, 'percentage' => 0]);
    $load = $metric('cpu', static fn (): array => sys_getloadavg() ?: [], $previous['server']['load'] ?? []);
    $processes = $metric('top processes', static fn (): array => topCpuProcesses(), $previous['processes'] ?? []);
    $cpuCount = max(1, (int)(statusCommand(['nproc']) ?: 1));
    $managedServer = null;
    $activeProject = deploymentActiveProject();
    if ($activeProject !== null && function_exists('managedServersLoad') && function_exists('managedServersFind')) {
        $managedServerId = (string)($activeProject['managed_server_id'] ?? '');
        $managedServer = $managedServerId === '' ? null : managedServersFind(managedServersLoad(), $managedServerId);
    }
    $managedServerDashboard = $managedServer === null || !function_exists('managedServerDashboardDiagnostics')
        ? ['available' => false, 'message' => 'Managed Server is not configured.', 'server' => null, 'processes' => []]
        : $metric('managed server dashboard', static fn (): array => managedServerDashboardDiagnostics($managedServer, $activeProject), $previous['managed_server'] ?? []);

    $dashboard = [
        'generated_at' => date('c'),
        'environment' => [
            'development' => [
                'path' => $environment['development_path'], 'branch' => $preview['branch'] ?? 'Not detected',
                'commit' => substr((string)($preview['commit'] ?? ''), 0, 7), 'message' => $preview['message'] ?? '',
            ],
            'preview' => [
                'url' => $environment['preview_url'], 'document_root' => $environment['preview_document_root'],
                'commit' => ($preview['deployed_commit'] ?? '') === '' ? 'Not detected' : substr($preview['deployed_commit'], 0, 7),
                'status' => (string)($preview['latest']['status'] ?? 'not_started'),
            ],
            'production' => [
                'url' => $environment['production_url'], 'document_root' => $environment['production_document_root'],
                'commit' => ($production['deployed_commit'] ?? '') === '' ? 'Not detected' : substr($production['deployed_commit'], 0, 7),
                'status' => (string)($production['latest']['status'] ?? 'not_started'),
            ],
            'console' => ['url' => $environment['console_url'], 'status' => 'running'],
        ],
        'software' => $slow['software'] ?? ($previous['software'] ?? []),
        'server' => [
            'load' => array_map(static fn ($value): float => round((float)$value, 2), array_slice($load, 0, 3)),
            'load_percentage' => isset($load[0]) ? round((float)$load[0] / $cpuCount * 100, 1) : 0,
            'memory' => $memory,
            'disk' => $disk,
        ],
        'managed_server' => array_merge([
            'available' => false,
            'message' => '',
            'name' => $managedServer === null ? '' : (string)($managedServer['name'] ?? ''),
            'status' => $managedServer === null ? 'unknown' : (string)($managedServer['status'] ?? 'never_tested'),
            'apache' => $managedServer === null ? [] : (is_array($managedServer['apache'] ?? null) ? $managedServer['apache'] : []),
            'apache_sites' => $managedServer === null ? [] : (is_array($managedServer['apache_sites'] ?? null) ? $managedServer['apache_sites'] : []),
        ], is_array($managedServerDashboard) ? $managedServerDashboard : []),
        'statistics' => $slow['statistics'] ?? ($previous['statistics'] ?? []),
        'processes' => $processes,
        '_slow' => $slow, '_preview' => $preview, '_production' => $production,
    ];
    writeDashboardCache('last-success', $dashboard);

    $duration = microtime(true) - $startedAt;
    if ($duration > 2.0) {
        arsort($timings);
        $slowMetrics = array_keys(array_filter($timings, static fn (float $value): bool => $value >= 0.1));
        error_log(sprintf('Dev Console Environment API took %.3fs; slow metrics: %s', $duration, $slowMetrics ? implode(', ', $slowMetrics) : 'none identified'));
    }
    unset($dashboard['_slow'], $dashboard['_preview'], $dashboard['_production']);
    return $dashboard;
}
