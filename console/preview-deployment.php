<?php

const DEV_CONSOLE_PREVIEW_DEPLOY_RUNTIME_DIR = __DIR__ . '/runtime/preview-deployments';
const DEV_CONSOLE_PREVIEW_DEPLOY_TMP_DIR = '/tmp/dev-console-preview-deployments';

function previewDeploymentRuntimeDirectory(): string
{
    if (!is_dir(DEV_CONSOLE_PREVIEW_DEPLOY_RUNTIME_DIR)) {
        @mkdir(DEV_CONSOLE_PREVIEW_DEPLOY_RUNTIME_DIR, 0700, true);
    }

    return DEV_CONSOLE_PREVIEW_DEPLOY_RUNTIME_DIR;
}

function previewDeploymentValidateOperationId(string $operationId): bool
{
    return preg_match('/^preview_deploy_[a-f0-9]{32}$/', $operationId) === 1;
}

function previewDeploymentOperationPath(string $operationId, string $extension): string
{
    if (!previewDeploymentValidateOperationId($operationId) || !in_array($extension, ['json', 'log'], true)) {
        throw new RuntimeException('Invalid Preview deployment operation ID.');
    }
    $directory = previewDeploymentRuntimeDirectory();
    $path = $directory . '/' . $operationId . '.' . $extension;
    $realDirectory = realpath($directory);
    $realParent = realpath(dirname($path)) ?: $directory;
    if ($realDirectory === false || $realParent !== $realDirectory) {
        throw new RuntimeException('Invalid Preview deployment operation path.');
    }

    return $path;
}

function previewDeploymentWriteOperation(array $state): void
{
    $path = previewDeploymentOperationPath((string)($state['id'] ?? ''), 'json');
    $state['updated_at'] = date('c');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to write Preview deployment operation state.');
    }
    $directory = dirname($path);
    $tmpPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
    $handle = @fopen($tmpPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write Preview deployment operation state.');
    }
    try {
        if (@fwrite($handle, $json . "\n") === false || !@fflush($handle)) {
            throw new RuntimeException('Unable to write Preview deployment operation state.');
        }
        if (!@fclose($handle)) {
            $handle = null;
            throw new RuntimeException('Unable to write Preview deployment operation state.');
        }
        $handle = null;
        @chmod($tmpPath, 0600);
        if (!@rename($tmpPath, $path)) {
            throw new RuntimeException('Unable to write Preview deployment operation state.');
        }
    } finally {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }
}

function previewDeploymentReadOperation(string $operationId): array
{
    $path = previewDeploymentOperationPath($operationId, 'json');
    if (!is_file($path)) {
        return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Preview deployment operation state file exists but could not be read.');
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Preview deployment operation state file exists but could not be decoded.');
    }

    return $decoded;
}

function previewDeploymentAppendLog(string $operationId, string $message): void
{
    @file_put_contents(previewDeploymentOperationPath($operationId, 'log'), rtrim($message) . "\n", FILE_APPEND | LOCK_EX);
}

function previewDeploymentLog(string $operationId): string
{
    $path = previewDeploymentOperationPath($operationId, 'log');

    return is_file($path) ? (string)@file_get_contents($path) : '';
}

function previewDeploymentRunCommand(string $operationId, array $arguments, array $options = []): array
{
    $result = processRunCommand($arguments, $options);
    previewDeploymentAppendLog($operationId, '$ ' . (string)$result['command_display']);
    previewDeploymentAppendLog($operationId, 'Exit code: ' . (string)$result['exit_code']);
    if (!empty($result['timed_out'])) {
        previewDeploymentAppendLog($operationId, 'Command timed out.');
    }
    if (trim((string)$result['output']) !== '') {
        previewDeploymentAppendLog($operationId, trim((string)$result['output']));
    }

    return $result;
}

function previewDeploymentExpectedPath(array $project): string
{
    return '/var/www/projects/' . (string)($project['id'] ?? '') . '/preview';
}

function previewDeploymentPathIsAllowed(array $project): bool
{
    $path = (string)($project['preview']['path'] ?? '');
    $productionPath = (string)($project['production']['path'] ?? '');
    return devConsoleIsAbsoluteUnixPath($path)
        && ($productionPath === '' || $path !== $productionPath);
}

function previewDeploymentLocalRsync(): string
{
    return serverToolsFindExecutable('rsync', serverToolsDefaultPath());
}

function previewDeploymentOverview(array $project, ?array $server): array
{
    $deployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    $status = previewDeploymentEffectiveStatus($deployment);
    $lastAttemptStatus = (string)($deployment['last_attempt_status'] ?? '');
    if ($status === 'deployed' && $lastAttemptStatus === 'failed') {
        $lastAttemptStatus = '';
    }

    return [
        'managed_server' => $server,
        'remote_path' => (string)($project['preview']['path'] ?? ''),
        'url' => (string)($project['preview']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['preview']['domain'],
        'repository' => previewDeploymentConfiguredRemote($project),
        'branch' => (string)($deployment['branch'] ?? $project['branch'] ?? ''),
        'source_branch' => (string)($project['branch'] ?? ''),
        'source_commit' => '',
        'status' => $status,
        'commit' => (string)($deployment['commit'] ?? ''),
        'deployed_at' => (string)($deployment['deployed_at'] ?? ''),
        'duration_ms' => $deployment['duration_ms'] ?? null,
        'message' => (string)($deployment['message'] ?? ''),
        'operation_id' => (string)($deployment['operation_id'] ?? ''),
        'last_attempt_status' => $lastAttemptStatus,
        'last_attempt_at' => (string)($deployment['last_attempt_at'] ?? ''),
        'last_attempt_commit' => (string)($deployment['last_attempt_commit'] ?? ''),
        'last_attempt_message' => (string)($deployment['last_attempt_message'] ?? ''),
    ];
}

function previewDeploymentEffectiveStatus(array $deployment): string
{
    $status = (string)($deployment['status'] ?? 'never_deployed');
    $lastAttemptStatus = (string)($deployment['last_attempt_status'] ?? '');
    if ($lastAttemptStatus === 'running') {
        return 'running';
    }
    if ($lastAttemptStatus === 'failed') {
        $deployedAt = strtotime((string)($deployment['deployed_at'] ?? '')) ?: 0;
        $lastAttemptAt = strtotime((string)($deployment['last_attempt_at'] ?? '')) ?: 0;
        if ($status !== 'deployed' || $deployedAt === 0 || $lastAttemptAt > $deployedAt) {
            return 'failed';
        }
    }

    return $status;
}

function previewDeploymentConfiguredRemote(array $project): string
{
    $path = (string)($project['repository_path'] ?? '');
    if ($path !== '' && is_dir($path . '/.git')) {
        $remote = processRunCommand(['git', '-C', $path, 'config', '--get', 'remote.origin.url'], [
            'timeout' => 10,
            'env' => ['GIT_TERMINAL_PROMPT' => '0'],
            'inherit_env' => false,
        ]);
        if ($remote['exit_code'] === 0 && trim((string)$remote['stdout']) !== '') {
            return trim((string)$remote['stdout']);
        }
    }

    return (string)($project['git']['remote_url'] ?? '');
}

function previewDeploymentReadiness(?array $project, array $managedServers): array
{
    $reasons = [];
    $warnings = [];
    $server = null;
    if ($project === null) {
        $reasons[] = 'Select a Project before deploying Preview.';
    } else {
        $path = (string)($project['repository_path'] ?? '');
        $branch = (string)($project['branch'] ?? '');
        $managedServerId = (string)($project['managed_server_id'] ?? '');
        $server = devConsoleFindManagedServerById($managedServers, $managedServerId);
        if ((string)($project['git']['bootstrap_status'] ?? '') !== 'ready' || empty($project['git']['connected'])) {
            $reasons[] = 'Project repository is not initialized.';
        }
        if ((string)($project['git']['remote_url'] ?? '') === '') {
            $reasons[] = 'GitHub repository is not configured.';
        }
        if ($branch === '') {
            $reasons[] = 'Project branch is not configured.';
        }
        if ($managedServerId === '') {
            $reasons[] = 'Project has no Managed Server.';
        } elseif ($server === null) {
            $reasons[] = 'Assigned Managed Server does not exist.';
        } elseif ((string)($server['status'] ?? 'never_tested') !== 'reachable') {
            $reasons[] = 'Managed Server is not reachable.';
        }
        if ($server !== null && (!is_file((string)($server['key'] ?? '')) || !is_readable((string)($server['key'] ?? '')))) {
            $reasons[] = 'Managed Server SSH key is missing.';
        }
        if ($path === '' || !is_dir($path . '/.git')) {
            $reasons[] = 'Local Project repository is missing.';
        } elseif ((string)($project['git']['remote_url'] ?? '') !== '') {
            $remote = processRunCommand(['git', '-C', $path, 'config', '--get', 'remote.origin.url'], ['timeout' => 10, 'env' => ['GIT_TERMINAL_PROMPT' => '0'], 'inherit_env' => false]);
            if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) === '') {
                $reasons[] = 'GitHub remote is not configured in the local repository.';
            }
        }
        if (!previewDeploymentPathIsAllowed($project)) {
            $reasons[] = 'Preview path is not a valid configured Project path.';
        }
        if (previewDeploymentLocalRsync() === '') {
            $reasons[] = 'rsync is not installed on Dev Console.';
        }
        if (managedServersSshExecutable() === '') {
            $reasons[] = 'SSH executable is missing on Dev Console.';
        }
        if ($path !== '' && is_dir($path . '/.git')) {
            $status = processRunCommand(['git', '-C', $path, 'status', '--porcelain'], ['timeout' => 10, 'env' => ['GIT_TERMINAL_PROMPT' => '0'], 'inherit_env' => false]);
            if (!empty($status['success']) && trim((string)$status['stdout']) !== '') {
                $warnings[] = 'Local repository has uncommitted changes. Preview will use the GitHub version only.';
            }
        }
    }

    return [
        'ready' => empty($reasons),
        'reasons' => array_values(array_unique($reasons)),
        'warnings' => $warnings,
        'server' => $server,
    ];
}

function previewDeploymentPersist(array $configuration, string $projectId, array $metadata): bool
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return false;
    }
    $project = previewDeploymentApplyMetadata($project, $metadata);
    $updated = devConsoleUpdateProjectInConfiguration($configuration, $project);

    return devConsoleSaveProjectConfiguration($updated);
}

function previewDeploymentApplyMetadata(array $project, array $metadata): array
{
    $existing = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    $status = (string)($metadata['status'] ?? '');
    if ($status === 'deployed') {
        $project['preview_deployment'] = array_merge(devConsoleEmptyProject()['preview_deployment'], $existing, $metadata, [
            'last_attempt_status' => 'deployed',
            'last_attempt_at' => $metadata['deployed_at'] ?? date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? 'Preview deployed.',
        ]);
    } elseif ($status === 'running') {
        $project['preview_deployment'] = array_merge(devConsoleEmptyProject()['preview_deployment'], $existing, [
            'operation_id' => $metadata['operation_id'] ?? ($existing['operation_id'] ?? null),
            'message' => $metadata['message'] ?? 'Preview deployment running.',
            'last_attempt_status' => 'running',
            'last_attempt_at' => date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? 'Preview deployment running.',
        ]);
    } else {
        $hasSuccessfulDeployment = (string)($existing['commit'] ?? '') !== '' && (string)($existing['deployed_at'] ?? '') !== '';
        $preservedStatus = $hasSuccessfulDeployment ? 'deployed' : 'never_deployed';
        $project['preview_deployment'] = array_merge(devConsoleEmptyProject()['preview_deployment'], $existing, [
            'status' => $preservedStatus,
            'operation_id' => $metadata['operation_id'] ?? ($existing['operation_id'] ?? null),
            'message' => $metadata['message'] ?? 'Preview deployment failed.',
            'last_attempt_status' => 'failed',
            'last_attempt_at' => $metadata['deployed_at'] ?? date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? 'Preview deployment failed.',
        ]);
    }

    return $project;
}

function previewDeploymentWorkerRunning(array $state): ?bool
{
    $pid = (int)($state['pid'] ?? 0);
    if ($pid <= 0) {
        return null;
    }
    if (is_dir('/proc/' . $pid)) {
        return true;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return false;
}

function previewDeploymentFailOperation(string $operationId, string $message, ?int $durationMs = null): array
{
    $state = previewDeploymentReadOperation($operationId);
    if (empty($state)) {
        return [];
    }
    $finishedAt = date('c');
    if ($durationMs === null) {
        $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
        $durationMs = max(0, (time() - $startedAt) * 1000);
    }
    $stage = trim((string)($state['stage'] ?? ''));
    $state['status'] = 'failed';
    $state['stage'] = $stage === '' ? 'Failed' : $stage;
    $state['message'] = $message;
    $state['updated_at'] = $finishedAt;
    $state['finished_at'] = $finishedAt;
    $state['result'] = [
        'success' => false,
        'message' => $message,
        'commit' => (string)($state['commit'] ?? ''),
        'branch' => (string)($state['branch'] ?? ''),
        'duration_ms' => $durationMs,
    ];
    previewDeploymentWriteOperation($state);

    $projectId = (string)($state['project_id'] ?? '');
    if ($projectId !== '') {
        previewDeploymentPersist(devConsoleLoadProjectConfiguration(), $projectId, [
            'operation_id' => $operationId,
            'message' => $message,
            'deployed_at' => $finishedAt,
            'commit' => (string)($state['commit'] ?? ''),
        ]);
    }

    return $state;
}

function previewDeploymentStatus(string $operationId): array
{
    $state = previewDeploymentReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Preview deployment operation not found.');
    }
    if ((string)($state['status'] ?? '') === 'running' && previewDeploymentWorkerRunning($state) === false) {
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Failed: Preview deployment worker stopped before writing a terminal state.');
        $state = previewDeploymentFailOperation($operationId, 'Preview deployment worker stopped before writing a terminal state.');
    }
    $state['log'] = previewDeploymentLog($operationId);
    $result = is_array($state['result'] ?? null) ? $state['result'] : [];
    if (in_array((string)($state['status'] ?? ''), ['completed', 'failed'], true) && is_numeric($result['duration_ms'] ?? null)) {
        $state['elapsed_seconds'] = max(0, (int)floor(((int)$result['duration_ms']) / 1000));
    } else {
        $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
        $finishedAt = strtotime((string)($state['finished_at'] ?? '')) ?: time();
        $state['elapsed_seconds'] = max(0, $finishedAt - $startedAt);
    }

    return $state;
}

function previewDeploymentStart(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    $managedServers = managedServersLoad();
    $readiness = previewDeploymentReadiness($project, $managedServers);
    if (empty($readiness['ready'])) {
        throw new RuntimeException(implode(' ', $readiness['reasons']));
    }
    $operationId = 'preview_deploy_' . bin2hex(random_bytes(16));
    $state = [
        'id' => $operationId,
        'project_id' => $projectId,
        'project_name' => (string)($project['name'] ?? $projectId),
        'status' => 'running',
        'stage' => 'Preparing',
        'started_at' => date('c'),
        'updated_at' => date('c'),
        'finished_at' => '',
        'message' => 'Preparing Preview deployment.',
        'result' => null,
    ];
    previewDeploymentWriteOperation($state);
    previewDeploymentAppendLog($operationId, '[' . date('c') . '] Preview deployment queued for ' . (string)($project['name'] ?? $projectId) . '.');
    foreach ($readiness['warnings'] as $warning) {
        previewDeploymentAppendLog($operationId, 'Warning: ' . $warning);
    }
    previewDeploymentPersist($configuration, $projectId, [
        'status' => 'running',
        'operation_id' => $operationId,
        'message' => 'Preview deployment running.',
    ]);

    $worker = __DIR__ . '/run-preview-deployment.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($operationId) . ' >/dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($command));
    if ($pid <= 0) {
        previewDeploymentFailOperation($operationId, 'Unable to start Preview deployment worker.');
        throw new RuntimeException('Unable to start Preview deployment worker.');
    }
    $latestState = previewDeploymentReadOperation($operationId);
    if (empty($latestState)) {
        previewDeploymentFailOperation($operationId, 'Preview deployment operation state disappeared after worker start.');
        throw new RuntimeException('Preview deployment operation state disappeared after worker start.');
    }
    $latestState['pid'] = $pid;
    previewDeploymentWriteOperation($latestState);

    return previewDeploymentReadOperation($operationId);
}

function previewDeploymentSetStage(string $operationId, string $stage, string $message): array
{
    $state = previewDeploymentReadOperation($operationId);
    $state['stage'] = $stage;
    $state['message'] = $message;
    $state['updated_at'] = date('c');
    previewDeploymentWriteOperation($state);
    previewDeploymentAppendLog($operationId, '[' . date('c') . '] ' . $stage . ': ' . $message);

    return $state;
}

function previewDeploymentShellPath(string $path): string
{
    return escapeshellarg($path);
}

function previewDeploymentRemotePrepareCommand(string $remotePath): string
{
    $path = previewDeploymentShellPath($remotePath);
    return 'mkdir -p -- ' . $path . ' && test -d ' . $path . ' && test -w ' . $path;
}

function previewDeploymentRemoteVerifyCommand(string $remotePath): string
{
    $path = previewDeploymentShellPath($remotePath);
    return 'test -d ' . $path
        . ' && test -r ' . $path
        . ' && find ' . $path . ' -mindepth 1 -maxdepth 1 -print -quit';
}

function previewDeploymentComposerRequirement(string $sourceDirectory): array
{
    $composerJson = rtrim($sourceDirectory, '/') . '/composer.json';
    if (!is_file($composerJson)) {
        return ['required' => false, 'lock_present' => false];
    }

    return [
        'required' => true,
        'lock_present' => is_file(rtrim($sourceDirectory, '/') . '/composer.lock'),
    ];
}

function previewDeploymentRemoteComposerPrerequisiteCommand(): string
{
    return 'if ! command -v php >/dev/null 2>&1; then printf "%s\n" "__DEV_CONSOLE_PHP_MISSING__"; exit 20; fi; '
        . 'command -v php; php -v; '
        . 'if ! command -v composer >/dev/null 2>&1; then printf "%s\n" "__DEV_CONSOLE_COMPOSER_MISSING__"; exit 21; fi; '
        . 'command -v composer; composer --version --no-interaction';
}

function previewDeploymentRemoteComposerInstallCommand(string $remotePath): string
{
    $path = previewDeploymentShellPath($remotePath);
    return 'cd ' . $path . ' && composer install --no-dev --prefer-dist --no-interaction --no-progress';
}

function previewDeploymentRemoteComposerAutoloadVerifyCommand(string $remotePath): string
{
    return 'test -r ' . previewDeploymentShellPath(rtrim($remotePath, '/') . '/vendor/autoload.php');
}

function previewDeploymentRemoteNormalizeRootCommand(string $remotePath): string
{
    return projectRemoteEnvironmentRootPermissionCommand($remotePath);
}

function previewDeploymentRemoteApacheCapabilities(array $server): array
{
    return ['apache_privilege' => (string)($server['user'] ?? '') === 'root' ? 'direct' : 'sudo'];
}

function previewDeploymentSshArguments(array $server, string $remoteCommand): array
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

function previewDeploymentRsyncArguments(array $server, string $sourceDirectory, string $remotePath): array
{
    $ssh = implode(' ', array_map('escapeshellarg', [
        managedServersSshExecutable(),
        '-i', (string)$server['key'],
        '-p', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=10',
        '-o', 'StrictHostKeyChecking=accept-new',
    ]));
    $target = (string)$server['user'] . '@' . (string)$server['host'] . ':' . rtrim($remotePath, '/') . '/';
    $arguments = [
        previewDeploymentLocalRsync(),
        '-a',
        '--delete',
        '--itemize-changes',
        '--no-owner',
        '--no-group',
    ];
    if (!is_file(rtrim($sourceDirectory, '/') . '/.env')) {
        $arguments[] = '--filter=- /.env';
    }
    return array_merge($arguments, [
        '--exclude=.git/',
        '--exclude=TASKS/',
        '-e',
        $ssh,
        rtrim($sourceDirectory, '/') . '/',
        $target,
    ]);
}

function previewDeploymentRemoveDirectory(string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function previewDeploymentRunById(string $operationId): void
{
    $state = previewDeploymentReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Preview deployment operation not found.');
    }
    previewDeploymentRun($operationId, (string)($state['project_id'] ?? ''));
}

function previewDeploymentRun(string $operationId, string $projectId): void
{
    $started = microtime(true);
    $configuration = devConsoleLoadProjectConfiguration();
    $project = devConsoleFindProjectById($configuration, $projectId);
    $managedServers = managedServersLoad();
    $readiness = previewDeploymentReadiness($project, $managedServers);
    $server = $readiness['server'] ?? null;
    $commit = '';
    $branch = is_array($project) ? (string)($project['branch'] ?? '') : '';
    $tmpRoot = DEV_CONSOLE_PREVIEW_DEPLOY_TMP_DIR . '/' . $operationId;
    $sourceDirectory = $tmpRoot . '/source';
    $archivePath = $tmpRoot . '/source.tar';

    try {
        previewDeploymentSetStage($operationId, 'Validating Project', 'Checking Project, GitHub, Managed Server, SSH and rsync prerequisites.');
        if (empty($readiness['ready']) || $project === null || $server === null) {
            throw new RuntimeException(implode(' ', $readiness['reasons']));
        }
        $repoPath = (string)$project['repository_path'];
        $remotePath = (string)$project['preview']['path'];

        previewDeploymentSetStage($operationId, 'Checking GitHub', 'Fetching the configured GitHub remote.');
        $fetch = previewDeploymentRunCommand($operationId, ['git', '-C', $repoPath, 'fetch', '--prune', 'origin', $branch], [
            'timeout' => 120,
            'env' => ['GIT_TERMINAL_PROMPT' => '0'],
            'inherit_env' => false,
        ]);
        if ($fetch['exit_code'] !== 0) {
            throw new RuntimeException('GitHub branch could not be fetched.');
        }
        $rev = previewDeploymentRunCommand($operationId, ['git', '-C', $repoPath, 'rev-parse', 'origin/' . $branch . '^{commit}'], [
            'timeout' => 20,
            'env' => ['GIT_TERMINAL_PROMPT' => '0'],
            'inherit_env' => false,
        ]);
        if ($rev['exit_code'] !== 0 || trim((string)$rev['stdout']) === '') {
            throw new RuntimeException('GitHub branch not found.');
        }
        $commit = trim((string)$rev['stdout']);

        previewDeploymentSetStage($operationId, 'Preparing Preview', 'Creating a temporary deployment source from the GitHub commit.');
        if (!is_dir($sourceDirectory) && !@mkdir($sourceDirectory, 0700, true) && !is_dir($sourceDirectory)) {
            throw new RuntimeException('Unable to create temporary deployment source.');
        }
        $archive = previewDeploymentRunCommand($operationId, ['git', '-C', $repoPath, 'archive', '--format=tar', '-o', $archivePath, $commit], [
            'timeout' => 120,
            'env' => ['GIT_TERMINAL_PROMPT' => '0'],
            'inherit_env' => false,
        ]);
        if ($archive['exit_code'] !== 0) {
            throw new RuntimeException('Unable to export GitHub commit.');
        }
        $tar = serverToolsFindExecutable('tar', serverToolsDefaultPath());
        if ($tar === '') {
            throw new RuntimeException('tar is not installed on Dev Console.');
        }
        $extract = previewDeploymentRunCommand($operationId, [$tar, '-xf', $archivePath, '-C', $sourceDirectory], ['timeout' => 120, 'inherit_env' => false]);
        if ($extract['exit_code'] !== 0) {
            throw new RuntimeException('Unable to prepare deployment source.');
        }
        $documentRoot = projectEnvironmentDocumentRoot($project, 'preview', $sourceDirectory);
        $usesPublicWebRoot = projectSourceUsesPublicWebRoot($sourceDirectory);
        previewDeploymentSetStage($operationId, 'Detecting Dependencies', 'Checking whether the deployment source requires Composer dependencies.');
        $composerRequirement = previewDeploymentComposerRequirement($sourceDirectory);
        $composerRequired = !empty($composerRequirement['required']);
        if ($composerRequired && empty($composerRequirement['lock_present'])) {
            throw new RuntimeException("composer.json exists but composer.lock is missing.\nGenerate and commit composer.lock before deployment.");
        }
        if ($composerRequired) {
            previewDeploymentSetStage($operationId, 'Checking Runtime', 'Checking PHP and Composer on the Managed Server before modifying Preview.');
            $composerPrerequisites = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteComposerPrerequisiteCommand()), [
                'timeout' => 30,
                'env' => ['PATH' => serverToolsDefaultPath()],
                'inherit_env' => false,
            ]);
            if ($composerPrerequisites['exit_code'] === 20 || str_contains((string)$composerPrerequisites['stdout'], '__DEV_CONSOLE_PHP_MISSING__')) {
                throw new RuntimeException('PHP is required by this project but is not installed on Managed Server "' . (string)($server['name'] ?? $server['id']) . '".');
            }
            if ($composerPrerequisites['exit_code'] === 21 || str_contains((string)$composerPrerequisites['stdout'], '__DEV_CONSOLE_COMPOSER_MISSING__')) {
                throw new RuntimeException('Composer is required by this project but is not installed on Managed Server "' . (string)($server['name'] ?? $server['id']) . "\".\nInstall the Composer prerequisite and retry Preview deployment.");
            }
            if ($composerPrerequisites['exit_code'] !== 0) {
                throw new RuntimeException('Runtime prerequisite check failed on Managed Server "' . (string)($server['name'] ?? $server['id']) . '".');
            }
        }

        previewDeploymentSetStage($operationId, 'Checking Managed Server', 'Preparing the remote Preview directory.');
        $remotePrep = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemotePrepareCommand($remotePath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($remotePrep['exit_code'] !== 0) {
            throw new RuntimeException('Remote Preview directory cannot be created or used by the SSH user.');
        }

        $vhostCommand = devConsoleProjectAdoptedInPlace($project)
            ? projectRemoteAdoptedVhostDocumentRootMatchesCommand($project, 'preview', $documentRoot)
            : projectRemoteVhostDocumentRootMatchesCommand($project, 'preview', $documentRoot);
        $vhostCheck = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, $vhostCommand), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($vhostCheck['exit_code'] !== 0) {
            $message = devConsoleProjectAdoptedInPlace($project)
                ? 'Preview Apache configuration does not map ' . (string)($project['preview']['domain'] ?? '') . ' to ' . $documentRoot . '.'
                : 'Preview Apache DocumentRoot does not match the deployed web root. Run Update Infrastructure before deploying Preview.';
            throw new RuntimeException($message);
        }

        previewDeploymentSetStage($operationId, 'Transferring Files', 'Synchronizing the GitHub commit to remote Preview with delete semantics.');
        $rsync = previewDeploymentRunCommand($operationId, previewDeploymentRsyncArguments($server, $sourceDirectory, $remotePath), [
            'timeout' => 300,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($rsync['exit_code'] !== 0) {
            throw new RuntimeException('File transfer failed.');
        }
        $normalizeRoot = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteNormalizeRootCommand($remotePath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($normalizeRoot['exit_code'] !== 0) {
            throw new RuntimeException('Preview files were transferred, but the Preview root permissions could not be normalized.');
        }
        if ($composerRequired) {
            previewDeploymentSetStage($operationId, 'Installing Dependencies', 'Running Composer install in remote Preview.');
            $composerInstall = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteComposerInstallCommand($remotePath)), [
                'timeout' => 600,
                'env' => ['PATH' => serverToolsDefaultPath()],
                'inherit_env' => false,
            ]);
            if ($composerInstall['exit_code'] !== 0) {
                throw new RuntimeException('Composer install failed.');
            }
            $composerAutoload = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteComposerAutoloadVerifyCommand($remotePath)), [
                'timeout' => 30,
                'env' => ['PATH' => serverToolsDefaultPath()],
                'inherit_env' => false,
            ]);
            if ($composerAutoload['exit_code'] !== 0) {
                throw new RuntimeException('Composer completed but vendor/autoload.php was not created or is not readable.');
            }
        }

        previewDeploymentSetStage($operationId, 'Verifying Preview', 'Verifying remote Preview directory and deployed files.');
        $verify = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteVerifyCommand($remotePath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($verify['exit_code'] !== 0 || trim((string)$verify['stdout']) === '') {
            throw new RuntimeException('Preview verification failed.');
        }
        $apacheReadable = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, projectRemoteApacheReadabilityCommand(previewDeploymentRemoteApacheCapabilities($server), $documentRoot, $usesPublicWebRoot)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($apacheReadable['exit_code'] !== 0) {
            throw new RuntimeException("Preview files were transferred, but Apache cannot read the Preview web root:\n\n" . $documentRoot . "\n\nCheck directory permissions.");
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'success' => true,
            'message' => 'Preview deployed.',
            'commit' => $commit,
            'branch' => $branch,
            'managed_server_id' => (string)$server['id'],
            'remote_path' => $remotePath,
            'document_root' => $documentRoot,
            'composer_dependencies' => $composerRequired,
            'duration_ms' => $durationMs,
        ];
        previewDeploymentPersist(devConsoleLoadProjectConfiguration(), $projectId, [
            'status' => 'deployed',
            'commit' => $commit,
            'branch' => $branch,
            'deployed_at' => date('c'),
            'managed_server_id' => (string)$server['id'],
            'duration_ms' => $durationMs,
            'operation_id' => $operationId,
            'message' => 'Preview deployed.',
        ]);
        $state = previewDeploymentReadOperation($operationId);
        $state['commit'] = $commit;
        $state['branch'] = $branch;
        $state['status'] = 'completed';
        $state['stage'] = 'Completed';
        $state['message'] = 'Preview deployed.';
        $state['finished_at'] = date('c');
        $state['result'] = $result;
        previewDeploymentWriteOperation($state);
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Completed: Preview deployed at ' . substr($commit, 0, 12) . '.');
    } catch (Throwable $exception) {
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $state = previewDeploymentReadOperation($operationId);
        $state['commit'] = $commit;
        $state['branch'] = $branch;
        previewDeploymentWriteOperation($state);
        previewDeploymentFailOperation($operationId, $exception->getMessage(), $durationMs);
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Failed: ' . $exception->getMessage());
    } finally {
        previewDeploymentRemoveDirectory($tmpRoot);
    }
}
