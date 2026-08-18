<?php

const DEV_CONSOLE_PRODUCTION_DEPLOY_RUNTIME_DIR = __DIR__ . '/runtime/production-deployments';

function productionDeploymentRuntimeDirectory(): string
{
    if (!is_dir(DEV_CONSOLE_PRODUCTION_DEPLOY_RUNTIME_DIR)) {
        @mkdir(DEV_CONSOLE_PRODUCTION_DEPLOY_RUNTIME_DIR, 0700, true);
    }

    return DEV_CONSOLE_PRODUCTION_DEPLOY_RUNTIME_DIR;
}

function productionDeploymentValidateOperationId(string $operationId): bool
{
    return preg_match('/^production_deploy_[a-f0-9]{32}$/', $operationId) === 1;
}

function productionDeploymentOperationPath(string $operationId, string $extension): string
{
    if (!productionDeploymentValidateOperationId($operationId) || !in_array($extension, ['json', 'log'], true)) {
        throw new RuntimeException('Invalid Production deployment operation ID.');
    }
    $directory = productionDeploymentRuntimeDirectory();
    $path = $directory . '/' . $operationId . '.' . $extension;
    $realDirectory = realpath($directory);
    $realParent = realpath(dirname($path)) ?: $directory;
    if ($realDirectory === false || $realParent !== $realDirectory) {
        throw new RuntimeException('Invalid Production deployment operation path.');
    }

    return $path;
}

function productionDeploymentWriteOperation(array $state): void
{
    $path = productionDeploymentOperationPath((string)($state['id'] ?? ''), 'json');
    $state['updated_at'] = date('c');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to write Production deployment operation state.');
    }
    $directory = dirname($path);
    $tmpPath = $directory . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));
    $handle = @fopen($tmpPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write Production deployment operation state.');
    }
    try {
        if (@fwrite($handle, $json . "\n") === false || !@fflush($handle)) {
            throw new RuntimeException('Unable to write Production deployment operation state.');
        }
        if (!@fclose($handle)) {
            $handle = null;
            throw new RuntimeException('Unable to write Production deployment operation state.');
        }
        $handle = null;
        @chmod($tmpPath, 0600);
        if (!@rename($tmpPath, $path)) {
            throw new RuntimeException('Unable to write Production deployment operation state.');
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

function productionDeploymentReadOperation(string $operationId): array
{
    $path = productionDeploymentOperationPath($operationId, 'json');
    if (!is_file($path)) {
        return [];
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Production deployment operation state file exists but could not be read.');
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Production deployment operation state file exists but could not be decoded.');
    }

    return $decoded;
}

function productionDeploymentAppendLog(string $operationId, string $message): void
{
    @file_put_contents(productionDeploymentOperationPath($operationId, 'log'), rtrim($message) . "\n", FILE_APPEND | LOCK_EX);
}

function productionDeploymentLog(string $operationId): string
{
    $path = productionDeploymentOperationPath($operationId, 'log');

    return is_file($path) ? (string)@file_get_contents($path) : '';
}

function productionDeploymentRunCommand(string $operationId, array $arguments, array $options = []): array
{
    $result = processRunCommand($arguments, $options);
    productionDeploymentAppendLog($operationId, '$ ' . (string)$result['command_display']);
    productionDeploymentAppendLog($operationId, 'Exit code: ' . (string)$result['exit_code']);
    if (!empty($result['timed_out'])) {
        productionDeploymentAppendLog($operationId, 'Command timed out.');
    }
    if (trim((string)$result['output']) !== '') {
        productionDeploymentAppendLog($operationId, trim((string)$result['output']));
    }

    return $result;
}

function productionDeploymentFailureMessage(array $result, string $fallback): string
{
    $output = strtolower((string)($result['output'] ?? ''));
    if (str_contains($output, 'permission denied') || str_contains($output, 'authentication failed')) {
        return 'SSH authentication failed.';
    }
    if (!empty($result['timed_out']) || str_contains($output, 'connection timed out') || str_contains($output, 'operation timed out')) {
        return 'Connection timeout.';
    }
    if (str_contains($output, 'no route to host') || str_contains($output, 'could not resolve hostname') || str_contains($output, 'connection refused')) {
        return 'Host unreachable.';
    }

    return $fallback;
}

function productionDeploymentExpectedPath(array $project): string
{
    return '/var/www/projects/' . (string)($project['id'] ?? '') . '/production';
}

function productionDeploymentPathIsAllowed(array $project): bool
{
    $path = (string)($project['production']['path'] ?? '');
    return $path !== '' && $path === productionDeploymentExpectedPath($project);
}

function productionDeploymentShellPath(string $path): string
{
    return escapeshellarg($path);
}

function productionDeploymentSshArguments(array $server, string $remoteCommand): array
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

function productionDeploymentRemotePreviewCheckCommand(string $previewPath): string
{
    $path = productionDeploymentShellPath($previewPath);
    return 'test -d ' . $path . ' && test -r ' . $path . ' && find ' . $path . ' -mindepth 1 -maxdepth 1 -print -quit';
}

function productionDeploymentRemotePrepareCommand(string $productionPath): string
{
    $path = productionDeploymentShellPath($productionPath);
    return 'mkdir -p -- ' . $path . ' && test -d ' . $path . ' && test -w ' . $path;
}

function productionDeploymentRemotePromoteCommand(string $previewPath, string $productionPath): string
{
    $source = productionDeploymentShellPath(rtrim($previewPath, '/') . '/');
    $target = productionDeploymentShellPath(rtrim($productionPath, '/') . '/');
    $targetRoot = productionDeploymentShellPath($productionPath);
    return 'rsync -a --delete --no-owner --no-group -- ' . $source . ' ' . $target
        . ' && ' . projectRemoteEnvironmentRootPermissionCommand($productionPath)
        . ' && test -d ' . $targetRoot;
}

function productionDeploymentRemoteVerifyCommand(string $productionPath): string
{
    $path = productionDeploymentShellPath($productionPath);
    return 'test -d ' . $path
        . ' && test -r ' . $path
        . ' && find ' . $path . ' -mindepth 1 -maxdepth 1 -print -quit';
}

function productionDeploymentRemoteApacheCapabilities(array $server): array
{
    return ['apache_privilege' => (string)($server['user'] ?? '') === 'root' ? 'direct' : 'sudo'];
}

function productionDeploymentStatusLabel(string $status): string
{
    return match ($status) {
        'deployed' => 'Deployed',
        'failed' => 'Failed',
        'running' => 'Running',
        default => 'Never deployed',
    };
}

function productionDeploymentVersionState(array $previewDeployment, array $productionDeployment): string
{
    $previewCommit = (string)($previewDeployment['commit'] ?? '');
    $productionCommit = (string)($productionDeployment['commit'] ?? '');
    if ($previewCommit === '') {
        return 'Preview has not been deployed';
    }
    if ($productionCommit !== '' && $productionCommit === $previewCommit) {
        return 'In sync with Preview';
    }

    return 'Preview ready for promotion';
}

function productionDeploymentOverview(array $project, ?array $server): array
{
    $previewDeployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    $productionDeployment = is_array($project['production_deployment'] ?? null) ? $project['production_deployment'] : devConsoleEmptyProject()['production_deployment'];
    $status = (string)($productionDeployment['status'] ?? 'never_deployed');
    $lastAttemptStatus = (string)($productionDeployment['last_attempt_status'] ?? '');
    if (in_array($lastAttemptStatus, ['running', 'failed'], true)) {
        $status = $lastAttemptStatus;
    }

    return [
        'managed_server' => $server,
        'production_path' => (string)($project['production']['path'] ?? ''),
        'production_url' => (string)($project['production']['domain'] ?? '') === '' ? '' : 'http://' . (string)$project['production']['domain'],
        'preview_path' => (string)($project['preview']['path'] ?? ''),
        'preview_commit' => (string)($previewDeployment['commit'] ?? ''),
        'preview_branch' => (string)($previewDeployment['branch'] ?? $project['branch'] ?? ''),
        'preview_deployed_at' => (string)($previewDeployment['deployed_at'] ?? ''),
        'status' => $status,
        'commit' => (string)($productionDeployment['commit'] ?? ''),
        'branch' => (string)($productionDeployment['branch'] ?? ''),
        'deployed_at' => (string)($productionDeployment['deployed_at'] ?? ''),
        'duration_ms' => $productionDeployment['duration_ms'] ?? null,
        'operation_id' => (string)($productionDeployment['operation_id'] ?? ''),
        'message' => (string)($productionDeployment['message'] ?? ''),
        'last_attempt_status' => $lastAttemptStatus,
        'last_attempt_at' => (string)($productionDeployment['last_attempt_at'] ?? ''),
        'last_attempt_commit' => (string)($productionDeployment['last_attempt_commit'] ?? ''),
        'last_attempt_message' => (string)($productionDeployment['last_attempt_message'] ?? ''),
        'version_state' => productionDeploymentVersionState($previewDeployment, $productionDeployment),
    ];
}

function productionDeploymentReadiness(?array $project, array $managedServers, bool $checkRemote = true): array
{
    $reasons = [];
    $server = null;
    if ($project === null) {
        $reasons[] = 'Select a Project before deploying Production.';
    } else {
        $managedServerId = (string)($project['managed_server_id'] ?? '');
        $server = devConsoleFindManagedServerById($managedServers, $managedServerId);
        $previewDeployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : [];
        $previewPath = (string)($project['preview']['path'] ?? '');
        $productionPath = (string)($project['production']['path'] ?? '');
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
        if ((string)($previewDeployment['status'] ?? 'never_deployed') !== 'deployed' || (string)($previewDeployment['commit'] ?? '') === '') {
            $reasons[] = 'Preview has never been deployed.';
        }
        if (!previewDeploymentPathIsAllowed($project) || $previewPath === '') {
            $reasons[] = 'Preview path is not a supported managed Project path.';
        }
        if (!productionDeploymentPathIsAllowed($project) || $productionPath === '') {
            $reasons[] = 'Production path is not a supported managed Project path.';
        }
        if (previewDeploymentLocalRsync() === '') {
            $reasons[] = 'rsync is not installed on Dev Console.';
        }
        if (managedServersSshExecutable() === '') {
            $reasons[] = 'SSH executable is missing on Dev Console.';
        }
        if ($checkRemote && $server !== null && empty($reasons)) {
            $previewCheck = processRunCommand(productionDeploymentSshArguments($server, productionDeploymentRemotePreviewCheckCommand($previewPath)), [
                'timeout' => 15,
                'env' => ['PATH' => serverToolsDefaultPath()],
                'inherit_env' => false,
            ]);
            if ($previewCheck['exit_code'] !== 0 || trim((string)$previewCheck['stdout']) === '') {
                $reasons[] = 'Preview directory does not exist, is not readable, or is empty.';
            }
        }
    }

    return [
        'ready' => empty($reasons),
        'reasons' => array_values(array_unique($reasons)),
        'server' => $server,
    ];
}

function productionDeploymentPersist(array $configuration, string $projectId, array $metadata, bool $successful): bool
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return false;
    }
    $project = productionDeploymentApplyMetadata($project, $metadata, $successful);
    $updated = devConsoleUpdateProjectInConfiguration($configuration, $project);

    return devConsoleSaveProjectConfiguration($updated);
}

function productionDeploymentApplyMetadata(array $project, array $metadata, bool $successful): array
{
    $existing = is_array($project['production_deployment'] ?? null) ? $project['production_deployment'] : devConsoleEmptyProject()['production_deployment'];
    if ($successful) {
        $project['production_deployment'] = array_merge(devConsoleEmptyProject()['production_deployment'], $existing, $metadata, [
            'last_attempt_status' => 'deployed',
            'last_attempt_at' => $metadata['deployed_at'] ?? date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? null,
        ]);
    } elseif (($metadata['status'] ?? null) === 'running') {
        $project['production_deployment'] = array_merge(devConsoleEmptyProject()['production_deployment'], $existing, [
            'operation_id' => $metadata['operation_id'] ?? ($existing['operation_id'] ?? null),
            'message' => $metadata['message'] ?? 'Production deployment running.',
            'last_attempt_status' => 'running',
            'last_attempt_at' => date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? 'Production deployment running.',
        ]);
    } else {
        $hasSuccessfulDeployment = (string)($existing['commit'] ?? '') !== '' && (string)($existing['deployed_at'] ?? '') !== '';
        $preservedStatus = $hasSuccessfulDeployment ? 'deployed' : 'never_deployed';
        $project['production_deployment'] = array_merge(devConsoleEmptyProject()['production_deployment'], $existing, [
            'status' => $preservedStatus,
            'operation_id' => $metadata['operation_id'] ?? ($existing['operation_id'] ?? null),
            'message' => $metadata['message'] ?? 'Production deployment failed.',
            'last_attempt_status' => 'failed',
            'last_attempt_at' => $metadata['deployed_at'] ?? date('c'),
            'last_attempt_commit' => $metadata['commit'] ?? null,
            'last_attempt_message' => $metadata['message'] ?? 'Production deployment failed.',
        ]);
    }

    return $project;
}

function productionDeploymentStatus(string $operationId): array
{
    $state = productionDeploymentReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Production deployment operation not found.');
    }
    if ((string)($state['status'] ?? '') === 'running' && productionDeploymentWorkerRunning($state) === false) {
        productionDeploymentAppendLog($operationId, '[' . date('c') . '] Failed: Production deployment worker stopped before writing a terminal state.');
        $state = productionDeploymentFailOperation($operationId, 'Production deployment worker stopped before writing a terminal state.');
    }
    $state['log'] = productionDeploymentLog($operationId);
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

function productionDeploymentWorkerRunning(array $state): ?bool
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

function productionDeploymentFailOperation(string $operationId, string $message, ?int $durationMs = null): array
{
    $state = productionDeploymentReadOperation($operationId);
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
        'commit' => (string)($state['commit'] ?? $state['preview_commit'] ?? ''),
        'branch' => (string)($state['branch'] ?? ''),
        'duration_ms' => $durationMs,
    ];
    productionDeploymentWriteOperation($state);

    $projectId = (string)($state['project_id'] ?? '');
    if ($projectId !== '') {
        productionDeploymentPersist(devConsoleLoadProjectConfiguration(), $projectId, [
            'operation_id' => $operationId,
            'message' => $message,
            'deployed_at' => $finishedAt,
            'commit' => (string)($state['commit'] ?? $state['preview_commit'] ?? ''),
        ], false);
    }

    return $state;
}

function productionDeploymentStart(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    $managedServers = managedServersLoad();
    $readiness = productionDeploymentReadiness($project, $managedServers);
    if (empty($readiness['ready'])) {
        throw new RuntimeException(implode(' ', $readiness['reasons']));
    }
    $previewDeployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : [];
    $operationId = 'production_deploy_' . bin2hex(random_bytes(16));
    $state = [
        'id' => $operationId,
        'project_id' => $projectId,
        'project_name' => (string)($project['name'] ?? $projectId),
        'status' => 'running',
        'stage' => 'Preparing',
        'started_at' => date('c'),
        'updated_at' => date('c'),
        'finished_at' => '',
        'message' => 'Preparing Production deployment.',
        'result' => null,
        'preview_commit' => (string)($previewDeployment['commit'] ?? ''),
        'branch' => (string)($previewDeployment['branch'] ?? $project['branch'] ?? ''),
    ];
    productionDeploymentWriteOperation($state);
    productionDeploymentAppendLog($operationId, '[' . date('c') . '] Production deployment queued for ' . (string)($project['name'] ?? $projectId) . '.');
    productionDeploymentPersist($configuration, $projectId, [
        'status' => 'running',
        'operation_id' => $operationId,
        'message' => 'Production deployment running.',
        'commit' => (string)($previewDeployment['commit'] ?? ''),
    ], false);

    $worker = __DIR__ . '/run-production-deployment.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($operationId) . ' >/dev/null 2>&1 & echo $!';
    $pid = (int)trim((string)shell_exec($command));
    if ($pid <= 0) {
        productionDeploymentFailOperation($operationId, 'Unable to start Production deployment worker.');
        throw new RuntimeException('Unable to start Production deployment worker.');
    }
    $latestState = productionDeploymentReadOperation($operationId);
    if (empty($latestState)) {
        productionDeploymentFailOperation($operationId, 'Production deployment operation state disappeared after worker start.');
        throw new RuntimeException('Production deployment operation state disappeared after worker start.');
    }
    $latestState['pid'] = $pid;
    productionDeploymentWriteOperation($latestState);

    return productionDeploymentReadOperation($operationId);
}

function productionDeploymentSetStage(string $operationId, string $stage, string $message): array
{
    $state = productionDeploymentReadOperation($operationId);
    $state['stage'] = $stage;
    $state['message'] = $message;
    $state['updated_at'] = date('c');
    productionDeploymentWriteOperation($state);
    productionDeploymentAppendLog($operationId, '[' . date('c') . '] ' . $stage . ': ' . $message);

    return $state;
}

function productionDeploymentRunById(string $operationId): void
{
    $state = productionDeploymentReadOperation($operationId);
    if (empty($state)) {
        throw new RuntimeException('Production deployment operation not found.');
    }
    productionDeploymentRun($operationId, (string)($state['project_id'] ?? ''));
}

function productionDeploymentRun(string $operationId, string $projectId): void
{
    $started = microtime(true);
    $configuration = devConsoleLoadProjectConfiguration();
    $project = devConsoleFindProjectById($configuration, $projectId);
    $managedServers = managedServersLoad();
    $readiness = productionDeploymentReadiness($project, $managedServers);
    $server = $readiness['server'] ?? null;
    $previewDeployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : [];
    $commit = (string)($previewDeployment['commit'] ?? '');
    $branch = (string)($previewDeployment['branch'] ?? $project['branch'] ?? '');

    try {
        productionDeploymentSetStage($operationId, 'Preparing', 'Preparing Production promotion.');
        if (empty($readiness['ready']) || $project === null || $server === null) {
            throw new RuntimeException(implode(' ', $readiness['reasons']));
        }
        $previewPath = (string)$project['preview']['path'];
        $productionPath = (string)$project['production']['path'];
        $documentRoot = projectEnvironmentDocumentRoot($project, 'production');
        $usesPublicWebRoot = projectSourceUsesPublicWebRoot((string)($project['repository_path'] ?? ''));

        productionDeploymentSetStage($operationId, 'Validating Preview', 'Verifying the remote Preview directory and selected Preview version.');
        $previewCheck = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, productionDeploymentRemotePreviewCheckCommand($previewPath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($previewCheck['exit_code'] !== 0 || trim((string)$previewCheck['stdout']) === '') {
            throw new RuntimeException(productionDeploymentFailureMessage($previewCheck, 'Preview directory does not exist, is not readable, or is empty.'));
        }

        productionDeploymentSetStage($operationId, 'Checking Managed Server', 'Checking remote rsync availability.');
        $rsyncCheck = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, 'command -v rsync >/dev/null 2>&1'), [
            'timeout' => 20,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($rsyncCheck['exit_code'] !== 0) {
            throw new RuntimeException(productionDeploymentFailureMessage($rsyncCheck, 'rsync failed: rsync is not available on the Managed Server.'));
        }

        productionDeploymentSetStage($operationId, 'Preparing Production', 'Creating and checking the remote Production directory.');
        $remotePrep = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, productionDeploymentRemotePrepareCommand($productionPath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($remotePrep['exit_code'] !== 0) {
            throw new RuntimeException(productionDeploymentFailureMessage($remotePrep, 'Production directory cannot be created or is not writable.'));
        }
        $vhostCheck = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, projectRemoteVhostDocumentRootMatchesCommand($project, 'production', $documentRoot)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($vhostCheck['exit_code'] !== 0) {
            throw new RuntimeException('Production Apache DocumentRoot does not match the deployed web root. Run Update Infrastructure before deploying Production.');
        }

        productionDeploymentSetStage($operationId, 'Promoting Preview', 'Synchronizing remote Preview to remote Production with delete semantics.');
        $promote = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, productionDeploymentRemotePromoteCommand($previewPath, $productionPath)), [
            'timeout' => 300,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($promote['exit_code'] !== 0) {
            throw new RuntimeException(productionDeploymentFailureMessage($promote, 'rsync failed.'));
        }

        productionDeploymentSetStage($operationId, 'Verifying Production', 'Verifying remote Production directory and promoted files.');
        $verify = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, productionDeploymentRemoteVerifyCommand($productionPath)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($verify['exit_code'] !== 0 || trim((string)$verify['stdout']) === '') {
            throw new RuntimeException(productionDeploymentFailureMessage($verify, 'Production verification failed.'));
        }
        $apacheReadable = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, projectRemoteApacheReadabilityCommand(productionDeploymentRemoteApacheCapabilities($server), $documentRoot, $usesPublicWebRoot)), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($apacheReadable['exit_code'] !== 0) {
            throw new RuntimeException("Production files were promoted, but Apache cannot read the Production web root:\n\n" . $documentRoot . "\n\nCheck directory permissions.");
        }

        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $deployedAt = date('c');
        $result = [
            'success' => true,
            'message' => 'Production deployed.',
            'commit' => $commit,
            'branch' => $branch,
            'source' => 'Preview',
            'managed_server_id' => (string)$server['id'],
            'preview_path' => $previewPath,
            'production_path' => $productionPath,
            'document_root' => $documentRoot,
            'duration_ms' => $durationMs,
        ];
        productionDeploymentPersist(devConsoleLoadProjectConfiguration(), $projectId, [
            'status' => 'deployed',
            'commit' => $commit,
            'branch' => $branch,
            'deployed_at' => $deployedAt,
            'managed_server_id' => (string)$server['id'],
            'duration_ms' => $durationMs,
            'operation_id' => $operationId,
            'message' => 'Production deployed.',
            'source' => 'Preview',
        ], true);
        $state = productionDeploymentReadOperation($operationId);
        $state['commit'] = $commit;
        $state['branch'] = $branch;
        $state['status'] = 'completed';
        $state['stage'] = 'Completed';
        $state['message'] = 'Production deployed.';
        $state['finished_at'] = $deployedAt;
        $state['result'] = $result;
        productionDeploymentWriteOperation($state);
        productionDeploymentAppendLog($operationId, '[' . date('c') . '] Completed: Production promoted from Preview at ' . substr($commit, 0, 12) . '.');
    } catch (Throwable $exception) {
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $state = productionDeploymentReadOperation($operationId);
        $state['commit'] = $commit;
        $state['branch'] = $branch;
        productionDeploymentWriteOperation($state);
        productionDeploymentFailOperation($operationId, $exception->getMessage(), $durationMs);
        productionDeploymentAppendLog($operationId, '[' . date('c') . '] Failed: ' . $exception->getMessage());
    }
}
