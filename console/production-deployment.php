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
    $detail = productionDeploymentFailureDetail((string)($result['output'] ?? ''));
    if (str_contains($output, 'permission denied (publickey)') || str_contains($output, 'authentication failed') || str_contains($output, 'publickey,')) {
        return 'SSH authentication failed.';
    }
    if (str_contains($output, 'permission denied')) {
        $message = 'Remote filesystem permission failure.';
        if (str_contains(strtolower($fallback), 'synchronization')) {
            $message .= ' Production synchronization may have been partially applied; rerun preflight after fixing the permission issue.';
        }

        return $message . ($detail === '' ? '' : "\n\n" . $detail);
    }
    if (!empty($result['timed_out']) || str_contains($output, 'connection timed out') || str_contains($output, 'operation timed out')) {
        return 'Connection timeout.';
    }
    if (str_contains($output, 'no route to host') || str_contains($output, 'could not resolve hostname') || str_contains($output, 'connection refused')) {
        return 'Host unreachable.';
    }

    return $fallback;
}

function productionDeploymentFailureDetail(string $output): string
{
    $lines = [];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (count($lines) >= 6) {
            break;
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

function productionDeploymentExpectedPath(array $project): string
{
    return '/var/www/projects/' . (string)($project['id'] ?? '') . '/production';
}

function productionDeploymentPathIsAllowed(array $project): bool
{
    $path = (string)($project['production']['path'] ?? '');
    $previewPath = (string)($project['preview']['path'] ?? '');
    return devConsoleIsAbsoluteUnixPath($path)
        && ($previewPath === '' || $path !== $previewPath);
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

function productionDeploymentRemotePromoteCommand(string $previewPath, string $productionPath, array $preservePaths = []): string
{
    $source = productionDeploymentShellPath(rtrim($previewPath, '/') . '/');
    $target = productionDeploymentShellPath(rtrim($productionPath, '/') . '/');
    $targetRoot = productionDeploymentShellPath($productionPath);
    return 'rsync -a --delete --no-owner --no-group '
        . productionDeploymentPreserveArgumentsForShell($preservePaths)
        . '-- ' . $source . ' ' . $target
        . ' && ' . projectRemoteEnvironmentRootPermissionCommand($productionPath)
        . ' && test -d ' . $targetRoot;
}

function productionDeploymentApprovedDeletionPaths(array $project): array
{
    $preflight = is_array($project['production_deployment']['preflight'] ?? null) ? $project['production_deployment']['preflight'] : null;
    $approval = is_array($project['production_deployment']['deletion_approval'] ?? null) ? $project['production_deployment']['deletion_approval'] : [];
    if ($preflight === null || !productionDeploymentPreflightDeletionApproval($preflight, $approval)) {
        return [];
    }

    $paths = is_array($preflight['blocking_deletes'] ?? null) ? $preflight['blocking_deletes'] : [];
    $approved = [];
    foreach ($paths as $path) {
        $normalized = productionDeploymentNormalizePreservePath(is_scalar($path) ? (string)$path : '');
        if ($normalized !== null) {
            $approved[] = $normalized;
        }
    }

    return array_values(array_unique($approved));
}

function productionDeploymentRemoteApprovedDeletionCommand(string $productionPath, array $approvedDeletes, array $capabilities): string
{
    $commands = [];
    $productionRoot = rtrim($productionPath, '/');
    foreach ($approvedDeletes as $path) {
        $normalized = productionDeploymentNormalizePreservePath((string)$path);
        if ($normalized === null) {
            throw new RuntimeException('Production deletion approval contains an unsafe path.');
        }
        $target = $productionRoot . '/' . rtrim($normalized, '/');
        $commands[] = 'if [ -e ' . productionDeploymentShellPath($target) . ' ] || [ -L ' . productionDeploymentShellPath($target) . ' ]; then '
            . projectRemotePrivilegedCommand($capabilities, 'rm -rf -- ' . productionDeploymentShellPath($target))
            . '; fi';
    }

    return implode(' && ', $commands);
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

function productionDeploymentEffectiveStatus(array $deployment): string
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

function productionDeploymentPreservePaths(array $project): array
{
    $deployment = is_array($project['production_deployment'] ?? null) ? $project['production_deployment'] : [];
    $paths = is_array($deployment['preserve_paths'] ?? null) ? $deployment['preserve_paths'] : [];
    $valid = [];
    foreach ($paths as $path) {
        $normalized = productionDeploymentNormalizePreservePath(is_scalar($path) ? (string)$path : '');
        if ($normalized !== null) {
            $valid[] = $normalized;
        }
    }

    return array_values(array_unique($valid));
}

function productionDeploymentNormalizePreservePath(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = preg_replace('~/+~', '/', $path) ?? $path;
    $path = ltrim($path, '/');
    if (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }
    $directoryRule = str_ends_with($path, '/');
    $path = trim($path, '/');
    if ($path === '' || strlen($path) > 255 || devConsoleHasControlCharacters($path)) {
        return null;
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
    }

    return $path . ($directoryRule ? '/' : '');
}

function productionDeploymentPreserveArgumentsForShell(array $preservePaths): string
{
    $parts = [
        '--exclude=' . escapeshellarg('/.git/'),
        '--exclude=' . escapeshellarg('/TASKS/'),
    ];
    foreach ($preservePaths as $path) {
        $pattern = '/' . $path;
        if (str_ends_with($path, '/')) {
            $pattern .= '***';
        }
        $parts[] = '--exclude=' . escapeshellarg($pattern);
    }

    return empty($parts) ? '' : implode(' ', $parts) . ' ';
}

function productionDeploymentRemotePreflightCommand(string $previewPath, string $productionPath, array $preservePaths): string
{
    $source = productionDeploymentShellPath(rtrim($previewPath, '/') . '/');
    $target = productionDeploymentShellPath(rtrim($productionPath, '/') . '/');
    $targetRoot = rtrim($productionPath, '/');
    $command = 'test -d ' . productionDeploymentShellPath($previewPath)
        . ' && test -r ' . productionDeploymentShellPath($previewPath)
        . ' && test -d ' . productionDeploymentShellPath($productionPath)
        . ' && test -r ' . productionDeploymentShellPath($productionPath)
        . ' && rsync -ani --delete --no-owner --no-group --out-format=' . escapeshellarg('%i|%n%L') . ' '
        . productionDeploymentPreserveArgumentsForShell($preservePaths)
        . '-- ' . $source . ' ' . $target;

    foreach ($preservePaths as $path) {
        $remotePath = $targetRoot . '/' . rtrim($path, '/');
        $command .= ' && if [ -e ' . productionDeploymentShellPath($remotePath) . ' ]; then printf ' . escapeshellarg('__DEV_CONSOLE_PRESERVED__=%s\n') . ' ' . escapeshellarg($path) . '; fi';
    }

    return $command;
}

function productionDeploymentParsePreflightOutput(string $stdout): array
{
    $changes = [
        'add' => [],
        'update' => [],
        'delete' => [],
        'preserved' => [],
        'other' => [],
    ];
    foreach (preg_split('/\r\n|\r|\n/', trim($stdout)) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, 'cannot delete non-empty directory:')) {
            continue;
        }
        if (str_starts_with($line, '__DEV_CONSOLE_PRESERVED__=')) {
            $path = substr($line, strlen('__DEV_CONSOLE_PRESERVED__='));
            if ($path !== '') {
                $changes['preserved'][] = $path;
            }
            continue;
        }
        $parts = explode('|', $line, 2);
        $code = $parts[0] ?? '';
        $path = $parts[1] ?? $line;
        $path = trim($path);
        if ($path === '' || $path === './') {
            continue;
        }
        if (str_starts_with($code, '*deleting')) {
            $changes['delete'][] = $path;
        } elseif (str_contains($code, '+++++++++')) {
            $changes['add'][] = $path;
        } elseif (preg_match('/^[<>ch*]/', $code) === 1 || str_contains($code, 's') || str_contains($code, 't')) {
            $changes['update'][] = $path;
        } else {
            $changes['other'][] = $path;
        }
    }

    foreach ($changes as $key => $paths) {
        $changes[$key] = array_values(array_unique($paths));
        sort($changes[$key], SORT_NATURAL);
    }

    return $changes;
}

function productionDeploymentPathIsPreserveAncestor(string $path, array $preservePaths): bool
{
    $path = rtrim($path, '/');
    if ($path === '') {
        return false;
    }
    foreach ($preservePaths as $preservePath) {
        $preservePath = trim($preservePath, '/');
        if ($preservePath !== '' && str_starts_with($preservePath . '/', $path . '/')) {
            return true;
        }
    }

    return false;
}

function productionDeploymentPreflightHasBlockingDeletes(array $preflight): bool
{
    return !empty($preflight['blocking_deletes']);
}

function productionDeploymentDeletionApprovalFingerprint(array $preflight): string
{
    $payload = [
        'preview_commit' => (string)($preflight['preview_commit'] ?? ''),
        'preview_path' => (string)($preflight['preview_path'] ?? ''),
        'production_path' => (string)($preflight['production_path'] ?? ''),
        'preserve_paths' => array_values(array_map('strval', is_array($preflight['preserve_paths'] ?? null) ? $preflight['preserve_paths'] : [])),
        'blocking_deletes' => array_values(array_map('strval', is_array($preflight['blocking_deletes'] ?? null) ? $preflight['blocking_deletes'] : [])),
    ];
    sort($payload['preserve_paths'], SORT_NATURAL);
    sort($payload['blocking_deletes'], SORT_NATURAL);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

    return hash('sha256', $json === false ? serialize($payload) : $json);
}

function productionDeploymentPreflightDeletionApproval(array $preflight, array $approval): bool
{
    if (!productionDeploymentPreflightHasBlockingDeletes($preflight)) {
        return true;
    }

    return (string)($approval['fingerprint'] ?? '') !== ''
        && hash_equals(productionDeploymentDeletionApprovalFingerprint($preflight), (string)$approval['fingerprint']);
}

function productionDeploymentPreflightRequiresReview(array $preflight, array $approval = []): bool
{
    return productionDeploymentPreflightHasBlockingDeletes($preflight)
        && !productionDeploymentPreflightDeletionApproval($preflight, $approval);
}

function productionDeploymentPreflightForUi(?array $preflight, array $approval = []): ?array
{
    if ($preflight === null) {
        return null;
    }
    $deletionApproved = productionDeploymentPreflightDeletionApproval($preflight, $approval);
    $preflight['deletion_approved'] = $deletionApproved;
    $preflight['review_required'] = productionDeploymentPreflightRequiresReview($preflight, $approval);
    $preflight['deletion_approval'] = $deletionApproved ? $approval : null;

    return $preflight;
}

function productionDeploymentBuildPreflight(array $project, array $server): array
{
    $previewPath = (string)($project['preview']['path'] ?? '');
    $productionPath = (string)($project['production']['path'] ?? '');
    $preservePaths = productionDeploymentPreservePaths($project);
    $started = microtime(true);
    $result = processRunCommand(productionDeploymentSshArguments($server, productionDeploymentRemotePreflightCommand($previewPath, $productionPath, $preservePaths)), [
        'timeout' => 120,
        'env' => ['PATH' => serverToolsDefaultPath()],
        'inherit_env' => false,
    ]);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException(productionDeploymentFailureMessage($result, 'Production preflight failed.'));
    }
    $changes = productionDeploymentParsePreflightOutput((string)$result['stdout']);
    if (!empty($preservePaths)) {
        $changes['delete'] = array_values(array_filter(
            $changes['delete'],
            static fn(string $path): bool => !productionDeploymentPathIsPreserveAncestor($path, $preservePaths)
        ));
    }

    return [
        'checked_at' => date('c'),
        'preview_commit' => (string)($project['preview_deployment']['commit'] ?? ''),
        'preview_path' => $previewPath,
        'production_path' => $productionPath,
        'preserve_paths' => $preservePaths,
        'changes' => $changes,
        'blocking_deletes' => $changes['delete'],
        'summary' => [
            'add' => count($changes['add']),
            'update' => count($changes['update']),
            'delete' => count($changes['delete']),
            'preserved' => count($changes['preserved']),
            'other' => count($changes['other']),
        ],
        'duration_ms' => (int)round((microtime(true) - $started) * 1000),
    ];
}

function productionDeploymentPersistPreflight(array $configuration, string $projectId, array $preflight): bool
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return false;
    }
    $existing = is_array($project['production_deployment'] ?? null) ? $project['production_deployment'] : [];
    $project['production_deployment'] = array_merge(devConsoleEmptyProject()['production_deployment'], $existing, [
        'preflight' => $preflight,
        'preserve_paths' => productionDeploymentPreservePaths($project),
        'deletion_approval' => null,
    ]);

    return devConsoleSaveProjectConfiguration(devConsoleUpdateProjectInConfiguration($configuration, $project));
}

function productionDeploymentRunPreflight(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    $managedServers = managedServersLoad();
    $readiness = productionDeploymentReadiness($project, $managedServers, true, false);
    $server = $readiness['server'] ?? null;
    if (empty($readiness['ready']) || $project === null || $server === null) {
        throw new RuntimeException(implode(' ', $readiness['reasons']));
    }
    $preflight = productionDeploymentBuildPreflight($project, $server);
    if (!productionDeploymentPersistPreflight($configuration, $projectId, $preflight)) {
        throw new RuntimeException('Production preflight completed, but metadata could not be saved.');
    }

    return $preflight;
}

function productionDeploymentApproveDeletions(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        throw new RuntimeException('Project not found.');
    }
    $preflight = is_array($project['production_deployment']['preflight'] ?? null) ? $project['production_deployment']['preflight'] : null;
    if ($preflight === null) {
        throw new RuntimeException('Run Production preflight before approving deletions.');
    }
    $previewCommit = (string)($project['preview_deployment']['commit'] ?? '');
    if ((string)($preflight['preview_commit'] ?? '') !== $previewCommit) {
        throw new RuntimeException('Production preflight is stale. Run it again for the current Preview version.');
    }
    if (!productionDeploymentPreflightHasBlockingDeletes($preflight)) {
        throw new RuntimeException('There are no Production deletion candidates to approve.');
    }

    $approval = [
        'fingerprint' => productionDeploymentDeletionApprovalFingerprint($preflight),
        'approved_at' => date('c'),
        'preview_commit' => (string)($preflight['preview_commit'] ?? ''),
        'paths' => array_values(array_map('strval', $preflight['blocking_deletes'] ?? [])),
    ];
    $project['production_deployment']['deletion_approval'] = $approval;
    if (!devConsoleSaveProjectConfiguration(devConsoleUpdateProjectInConfiguration($configuration, $project))) {
        throw new RuntimeException('Unable to save Production deletion approval.');
    }

    return $approval;
}

function productionDeploymentAddPreservePath(array $configuration, string $projectId, string $path): array
{
    $normalized = productionDeploymentNormalizePreservePath($path);
    if ($normalized === null) {
        throw new RuntimeException('Preserve path must be a safe relative Production path.');
    }
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        throw new RuntimeException('Project not found.');
    }
    $preservePaths = productionDeploymentPreservePaths($project);
    $preservePaths[] = $normalized;
    $project['production_deployment']['preserve_paths'] = array_values(array_unique($preservePaths));
    $project['production_deployment']['preflight'] = null;
    $project['production_deployment']['deletion_approval'] = null;
    if (!devConsoleSaveProjectConfiguration(devConsoleUpdateProjectInConfiguration($configuration, $project))) {
        throw new RuntimeException('Unable to save Production preserve rule.');
    }

    return $project['production_deployment']['preserve_paths'];
}

function productionDeploymentOverview(array $project, ?array $server): array
{
    $previewDeployment = is_array($project['preview_deployment'] ?? null) ? $project['preview_deployment'] : devConsoleEmptyProject()['preview_deployment'];
    $productionDeployment = is_array($project['production_deployment'] ?? null) ? $project['production_deployment'] : devConsoleEmptyProject()['production_deployment'];
    $status = productionDeploymentEffectiveStatus($productionDeployment);
    $lastAttemptStatus = (string)($productionDeployment['last_attempt_status'] ?? '');
    if ($status === 'deployed' && $lastAttemptStatus === 'failed') {
        $lastAttemptStatus = '';
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
        'preserve_paths' => productionDeploymentPreservePaths($project),
        'preflight' => productionDeploymentPreflightForUi(
            is_array($productionDeployment['preflight'] ?? null) ? $productionDeployment['preflight'] : null,
            is_array($productionDeployment['deletion_approval'] ?? null) ? $productionDeployment['deletion_approval'] : []
        ),
        'deletion_approval' => is_array($productionDeployment['deletion_approval'] ?? null) ? $productionDeployment['deletion_approval'] : null,
    ];
}

function productionDeploymentReadiness(?array $project, array $managedServers, bool $checkRemote = true, bool $requirePreflight = true): array
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
            $reasons[] = 'Preview path is not a valid configured Project path.';
        }
        if (!productionDeploymentPathIsAllowed($project) || $productionPath === '') {
            $reasons[] = 'Production path is not a valid configured Project path.';
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
        if ($requirePreflight && empty($reasons)) {
            $preflight = is_array($project['production_deployment']['preflight'] ?? null) ? $project['production_deployment']['preflight'] : null;
            $previewCommit = (string)($previewDeployment['commit'] ?? '');
            if ($preflight === null) {
                $reasons[] = 'Run Production preflight before deploying.';
            } elseif ((string)($preflight['preview_commit'] ?? '') !== $previewCommit) {
                $reasons[] = 'Production preflight is stale. Run it again for the current Preview version.';
            } elseif (productionDeploymentPreflightRequiresReview($preflight, is_array($project['production_deployment']['deletion_approval'] ?? null) ? $project['production_deployment']['deletion_approval'] : [])) {
                $reasons[] = 'Production preflight requires review before deployment.';
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
    $readiness = productionDeploymentReadiness($project, $managedServers, true, false);
    if (empty($readiness['ready'])) {
        throw new RuntimeException(implode(' ', $readiness['reasons']));
    }
    $existingPreflight = is_array($project['production_deployment']['preflight'] ?? null) ? $project['production_deployment']['preflight'] : null;
    $existingApproval = is_array($project['production_deployment']['deletion_approval'] ?? null) ? $project['production_deployment']['deletion_approval'] : [];
    $existingApprovalValid = $existingPreflight !== null && productionDeploymentPreflightDeletionApproval($existingPreflight, $existingApproval);
    $preflight = productionDeploymentBuildPreflight($project, $readiness['server']);
    if ($existingApprovalValid && hash_equals(productionDeploymentDeletionApprovalFingerprint($existingPreflight), productionDeploymentDeletionApprovalFingerprint($preflight))) {
        $project['production_deployment']['preflight'] = $preflight;
        $project['production_deployment']['deletion_approval'] = $existingApproval;
        devConsoleSaveProjectConfiguration(devConsoleUpdateProjectInConfiguration($configuration, $project));
    } else {
        productionDeploymentPersistPreflight($configuration, $projectId, $preflight);
    }
    if (productionDeploymentPreflightRequiresReview($preflight, $existingApprovalValid ? $existingApproval : [])) {
        throw new RuntimeException('Production preflight requires review before deployment.');
    }
    $configuration = devConsoleLoadProjectConfiguration();
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        throw new RuntimeException('Project not found after Production preflight.');
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
        $vhostCommand = devConsoleProjectAdoptedInPlace($project)
            ? projectRemoteAdoptedVhostDocumentRootMatchesCommand($project, 'production', $documentRoot)
            : projectRemoteVhostDocumentRootMatchesCommand($project, 'production', $documentRoot);
        $vhostCheck = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, $vhostCommand), [
            'timeout' => 30,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($vhostCheck['exit_code'] !== 0) {
            $message = devConsoleProjectAdoptedInPlace($project)
                ? 'Production Apache configuration does not map ' . (string)($project['production']['domain'] ?? '') . ' to ' . $documentRoot . '.'
                : 'Production Apache DocumentRoot does not match the deployed web root. Run Update Infrastructure before deploying Production.';
            throw new RuntimeException($message);
        }

        $approvedDeletes = productionDeploymentApprovedDeletionPaths($project);
        if (!empty($approvedDeletes)) {
            productionDeploymentSetStage($operationId, 'Preparing Deletes', 'Removing explicitly approved Production deletion candidates with managed privileges.');
            productionDeploymentAppendLog($operationId, 'Approved Production deletion candidates: ' . implode(', ', $approvedDeletes));
            $deleteCommand = productionDeploymentRemoteApprovedDeletionCommand($productionPath, $approvedDeletes, productionDeploymentRemoteApacheCapabilities($server));
            $deleteResult = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, $deleteCommand), [
                'timeout' => 120,
                'env' => ['PATH' => serverToolsDefaultPath()],
                'inherit_env' => false,
            ]);
            if ($deleteResult['exit_code'] !== 0) {
                throw new RuntimeException(productionDeploymentFailureMessage($deleteResult, 'Unable to remove approved Production deletion candidates.'));
            }
        }

        productionDeploymentSetStage($operationId, 'Promoting Preview', 'Synchronizing remote Preview to remote Production with delete semantics.');
        $promote = productionDeploymentRunCommand($operationId, productionDeploymentSshArguments($server, productionDeploymentRemotePromoteCommand($previewPath, $productionPath, productionDeploymentPreservePaths($project))), [
            'timeout' => 300,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        if ($promote['exit_code'] !== 0) {
            throw new RuntimeException(productionDeploymentFailureMessage($promote, 'Production synchronization failed after changes may have been partially applied. Review Production state, rerun preflight, and retry when safe.'));
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
