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
    if ($json === false || @file_put_contents($path, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Unable to write Preview deployment operation state.');
    }
}

function previewDeploymentReadOperation(string $operationId): array
{
    $path = previewDeploymentOperationPath($operationId, 'json');
    $decoded = is_file($path) ? json_decode((string)@file_get_contents($path), true) : null;

    return is_array($decoded) ? $decoded : [];
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
    return $path !== '' && $path === previewDeploymentExpectedPath($project);
}

function previewDeploymentLocalRsync(): string
{
    return serverToolsFindExecutable('rsync', serverToolsDefaultPath());
}

function previewDeploymentOverview(array $project, ?array $server): array
{
    $deployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    return [
        'managed_server' => $server,
        'remote_path' => (string)($project['preview']['path'] ?? ''),
        'url' => (string)($project['preview']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['preview']['domain'],
        'repository' => previewDeploymentConfiguredRemote($project),
        'branch' => (string)($deployment['branch'] ?? $project['branch'] ?? ''),
        'source_branch' => (string)($project['branch'] ?? ''),
        'source_commit' => '',
        'status' => (string)($deployment['status'] ?? 'never_deployed'),
        'commit' => (string)($deployment['commit'] ?? ''),
        'deployed_at' => (string)($deployment['deployed_at'] ?? ''),
        'duration_ms' => $deployment['duration_ms'] ?? null,
        'message' => (string)($deployment['message'] ?? ''),
        'operation_id' => (string)($deployment['operation_id'] ?? ''),
    ];
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
            $reasons[] = 'Preview path is not a supported managed Project path.';
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
    $existing = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    $project['preview_deployment'] = array_merge(devConsoleEmptyProject()['preview_deployment'], $existing, $metadata);
    $updated = devConsoleUpdateProjectInConfiguration($configuration, $project);

    return devConsoleSaveProjectConfiguration($updated);
}

function previewDeploymentStatus(string $operationId): array
{
    $state = previewDeploymentReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Preview deployment operation not found.');
    }
    $state['log'] = previewDeploymentLog($operationId);
    $startedAt = strtotime((string)($state['started_at'] ?? '')) ?: time();
    $finishedAt = strtotime((string)($state['finished_at'] ?? '')) ?: time();
    $state['elapsed_seconds'] = max(0, $finishedAt - $startedAt);

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
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['finished_at'] = date('c');
        $state['message'] = 'Unable to start Preview deployment worker.';
        previewDeploymentWriteOperation($state);
        previewDeploymentPersist($configuration, $projectId, [
            'status' => 'failed',
            'operation_id' => $operationId,
            'message' => 'Unable to start Preview deployment worker.',
            'deployed_at' => date('c'),
        ]);
        throw new RuntimeException('Unable to start Preview deployment worker.');
    }
    $state['pid'] = $pid;
    previewDeploymentWriteOperation($state);

    return previewDeploymentReadOperation($operationId);
}

function previewDeploymentSetStage(string $operationId, string $stage, string $message): array
{
    $state = previewDeploymentReadOperation($operationId);
    $state['stage'] = $stage;
    $state['message'] = $message;
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
    return [
        previewDeploymentLocalRsync(),
        '-a',
        '--delete',
        '--itemize-changes',
        '--exclude=.git/',
        '--exclude=TASKS/',
        '-e',
        $ssh,
        rtrim($sourceDirectory, '/') . '/',
        $target,
    ];
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
        previewDeploymentSetStage($operationId, 'Checking Managed Server', 'Preparing the remote Preview directory.');
        $remotePrep = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemotePrepareCommand($remotePath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($remotePrep['exit_code'] !== 0) {
            throw new RuntimeException('Remote Preview directory cannot be created or used by the SSH user.');
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

        previewDeploymentSetStage($operationId, 'Verifying Preview', 'Verifying remote Preview directory and deployed files.');
        $verify = previewDeploymentRunCommand($operationId, previewDeploymentSshArguments($server, previewDeploymentRemoteVerifyCommand($remotePath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($verify['exit_code'] !== 0 || trim((string)$verify['stdout']) === '') {
            throw new RuntimeException('Preview verification failed.');
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $result = [
            'success' => true,
            'message' => 'Preview deployed.',
            'commit' => $commit,
            'branch' => $branch,
            'managed_server_id' => (string)$server['id'],
            'remote_path' => $remotePath,
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
        $state['status'] = 'completed';
        $state['stage'] = 'Completed';
        $state['message'] = 'Preview deployed.';
        $state['finished_at'] = date('c');
        $state['result'] = $result;
        previewDeploymentWriteOperation($state);
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Completed: Preview deployed at ' . substr($commit, 0, 12) . '.');
    } catch (Throwable $exception) {
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        previewDeploymentPersist(devConsoleLoadProjectConfiguration(), $projectId, [
            'status' => 'failed',
            'commit' => $commit === '' ? null : $commit,
            'branch' => $branch === '' ? null : $branch,
            'deployed_at' => date('c'),
            'managed_server_id' => is_array($server) ? (string)($server['id'] ?? '') : null,
            'duration_ms' => $durationMs,
            'operation_id' => $operationId,
            'message' => $exception->getMessage(),
        ]);
        $state = previewDeploymentReadOperation($operationId);
        $state['status'] = 'failed';
        $state['stage'] = (string)($state['stage'] ?? 'Failed');
        $state['message'] = $exception->getMessage();
        $state['finished_at'] = date('c');
        $state['result'] = [
            'success' => false,
            'message' => $exception->getMessage(),
            'commit' => $commit,
            'branch' => $branch,
            'duration_ms' => $durationMs,
        ];
        previewDeploymentWriteOperation($state);
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Failed: ' . $exception->getMessage());
    } finally {
        previewDeploymentRemoveDirectory($tmpRoot);
    }
}
