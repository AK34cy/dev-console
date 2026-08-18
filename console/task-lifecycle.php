<?php

function workerRunFile(string $runsDir, string $taskId, string $extension, string $source = 'project'): string
{
    return runFile($runsDir, $taskId, $extension, $source);
}

function appendLog(string $logPath, string $message): void
{
    file_put_contents($logPath, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function appendActivity(string $logPath, string $message): void
{
    $message = trim($message);
    if ($message === '') {
        return;
    }

    $currentLog = is_file($logPath) ? (string)file_get_contents($logPath) : '';
    if (preg_match('/\] ' . preg_quote($message, '/') . '$/m', $currentLog) === 1) {
        return;
    }

    appendLog($logPath, $message);
}

function writeStatus(string $statusPath, string $status): void
{
    file_put_contents($statusPath, $status, LOCK_EX);
}

function writeResult(string $runsDir, string $taskId, string $source, array $result): void
{
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        file_put_contents(workerRunFile($runsDir, $taskId, 'result.json', $source), $json . "\n", LOCK_EX);
    }
}

function cleanActivityText(string $text): string
{
    $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
    $text = trim($text);

    return strlen($text) > 1000 ? substr($text, 0, 1000) . '...' : $text;
}

function runLoggedCommand(array $arguments, string $cwd, string $logPath, int $timeout = 120): array
{
    $result = processRunCommand($arguments, [
        'cwd' => $cwd,
        'env' => [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'IOVON Dev Console',
            'GIT_AUTHOR_EMAIL' => 'iovon@iovon.com',
            'GIT_COMMITTER_NAME' => 'IOVON Dev Console',
            'GIT_COMMITTER_EMAIL' => 'iovon@iovon.com',
        ],
        'inherit_env' => true,
        'timeout' => $timeout,
    ]);
    appendLog($logPath, '$ ' . (string)$result['command_display']);
    appendLog($logPath, 'Exit code: ' . (string)$result['exit_code']);
    if (trim((string)$result['output']) !== '') {
        appendLog($logPath, cleanActivityText((string)$result['output']));
    }

    return $result;
}

function taskPathForStatus(string $repoRoot, string $status, string $taskId): string
{
    $directory = match ($status) {
        'TODO' => 'TODO',
        'IN PROGRESS' => 'IN PROGRESS',
        'DONE' => 'DONE',
        default => throw new RuntimeException('Invalid task status.'),
    };

    return $repoRoot . '/TASKS/' . $directory . '/' . $taskId . '.md';
}

function moveTaskFile(string $repoRoot, string $taskId, string $from, string $to): void
{
    $source = taskPathForStatus($repoRoot, $from, $taskId);
    $target = taskPathForStatus($repoRoot, $to, $taskId);
    $targetDir = dirname($target);
    if (!is_file($source)) {
        throw new RuntimeException('Task file is not in ' . $from . '.');
    }
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Unable to create TASKS/' . $to . '.');
    }
    if (is_file($target)) {
        throw new RuntimeException('Task file already exists in ' . $to . '.');
    }
    if (!rename($source, $target)) {
        throw new RuntimeException('Unable to move task to ' . $to . '.');
    }
    $body = (string)file_get_contents($target);
    $metadata = taskSystemMetadata($body);
    $attachments = is_array($metadata['attachments'] ?? null) ? $metadata['attachments'] : [];
    $metadata['task_id'] = $taskId;
    $metadata['status'] = $to;
    $updated = taskBodyWithProjectMetadata(taskEditableBody($body), (string)($metadata['project_id'] ?? ''), $taskId, $to, $attachments, $metadata);
    file_put_contents($target, $updated . "\n", LOCK_EX);
}

function assertTaskMetadata(string $path, string $projectId): void
{
    $metadataProjectId = taskProjectId((string)file_get_contents($path));
    if ($metadataProjectId === '' || $metadataProjectId !== $projectId) {
        throw new RuntimeException('Task belongs to another Project.');
    }
}

function protectedTasksRoot(string $repoRoot): string
{
    return rtrim($repoRoot, '/') . '/TASKS';
}

function removeDirectoryTree(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        if (!@unlink($path)) {
            throw new RuntimeException('Unable to restore protected TASKS state.');
        }
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!@rmdir($itemPath)) {
                throw new RuntimeException('Unable to restore protected TASKS state.');
            }
        } elseif (!@unlink($itemPath)) {
            throw new RuntimeException('Unable to restore protected TASKS state.');
        }
    }
    if (!@rmdir($path)) {
        throw new RuntimeException('Unable to restore protected TASKS state.');
    }
}

function copyDirectoryTree(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to restore protected TASKS state.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);
        $destination = rtrim($target, '/') . '/' . $relative;
        if ($item->isDir() && !$item->isLink()) {
            if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new RuntimeException('Unable to restore protected TASKS state.');
            }
        } elseif ($item->isLink()) {
            if (!@symlink((string)readlink($item->getPathname()), $destination)) {
                throw new RuntimeException('Unable to restore protected TASKS state.');
            }
        } else {
            $parent = dirname($destination);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                throw new RuntimeException('Unable to restore protected TASKS state.');
            }
            if (!@copy($item->getPathname(), $destination)) {
                throw new RuntimeException('Unable to restore protected TASKS state.');
            }
            @chmod($destination, $item->getPerms() & 0777);
        }
    }
}

function protectedTasksManifest(string $repoRoot): array
{
    $root = protectedTasksRoot($repoRoot);
    if (!is_dir($root)) {
        return [];
    }

    $manifest = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = 'TASKS/' . substr($item->getPathname(), strlen(rtrim($root, '/')) + 1);
        if ($item->isLink()) {
            $manifest[$relative] = ['type' => 'link', 'target' => (string)readlink($item->getPathname())];
        } elseif ($item->isDir()) {
            $manifest[$relative] = ['type' => 'dir'];
        } elseif ($item->isFile()) {
            $manifest[$relative] = [
                'type' => 'file',
                'hash' => hash_file('sha256', $item->getPathname()) ?: '',
                'size' => $item->getSize(),
            ];
        }
    }
    ksort($manifest);

    return $manifest;
}

function createProtectedTasksSnapshot(string $repoRoot): array
{
    $snapshotRoot = sys_get_temp_dir() . '/dev-console-tasks-snapshot-' . bin2hex(random_bytes(8));
    if (!@mkdir($snapshotRoot, 0700, true) && !is_dir($snapshotRoot)) {
        throw new RuntimeException('Unable to snapshot protected TASKS state.');
    }
    $tasksRoot = protectedTasksRoot($repoRoot);
    if (is_dir($tasksRoot)) {
        copyDirectoryTree($tasksRoot, $snapshotRoot . '/TASKS');
    }

    return [
        'root' => $snapshotRoot,
        'manifest' => protectedTasksManifest($repoRoot),
    ];
}

function cleanupProtectedTasksSnapshot(?array $snapshot): void
{
    $root = is_array($snapshot) ? (string)($snapshot['root'] ?? '') : '';
    if ($root !== '' && str_starts_with($root, sys_get_temp_dir() . '/dev-console-tasks-snapshot-')) {
        try {
            removeDirectoryTree($root);
        } catch (Throwable) {
            // Snapshot cleanup is best-effort; the run result should reflect the real failure.
        }
    }
}

function restoreProtectedTasksState(string $repoRoot, array $snapshot, string $logPath): void
{
    $beforeRestore = protectedTasksManifest($repoRoot);
    $changed = $beforeRestore !== ($snapshot['manifest'] ?? []);
    if ($changed) {
        appendLog($logPath, 'Warning: Codex attempted to modify Dev Console-managed task state.');
        removeDirectoryTree(protectedTasksRoot($repoRoot));
        copyDirectoryTree((string)$snapshot['root'] . '/TASKS', protectedTasksRoot($repoRoot));
        appendActivity($logPath, 'Protected task state restored');
    } else {
        appendActivity($logPath, 'Protected task state verified');
    }

    $reset = runLoggedCommand(['git', 'reset', '-q', '--', 'TASKS'], $repoRoot, $logPath, 30);
    if ($reset['exit_code'] !== 0) {
        throw new RuntimeException('Unable to restore protected TASKS index state.');
    }
}

function gitPathIsTracked(string $repoRoot, string $path): bool
{
    $result = processRunCommand(['git', 'ls-files', '--', $path], [
        'cwd' => $repoRoot,
        'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        'inherit_env' => true,
        'timeout' => 30,
    ]);

    return $result['exit_code'] === 0 && trim((string)$result['stdout']) !== '';
}

function taskLifecycleStagePaths(string $repoRoot, string $taskId): array
{
    $paths = [
        'TASKS/TODO/' . $taskId . '.md',
        'TASKS/IN PROGRESS/' . $taskId . '.md',
        'TASKS/DONE/' . $taskId . '.md',
    ];

    return array_values(array_filter($paths, static function (string $path) use ($repoRoot): bool {
        return file_exists($repoRoot . '/' . $path) || gitPathIsTracked($repoRoot, $path);
    }));
}

function taskLifecycleExpectedPaths(string $taskId): array
{
    return [
        'TASKS/TODO/' . $taskId . '.md',
        'TASKS/IN PROGRESS/' . $taskId . '.md',
        'TASKS/DONE/' . $taskId . '.md',
    ];
}

function assertTaskIdentity(string $path, string $projectId, string $taskId, string $expectedStatus = ''): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Task file is missing.');
    }
    $body = (string)file_get_contents($path);
    assertTaskMetadata($path, $projectId);
    $metadata = taskSystemMetadata($body);
    if ((string)($metadata['task_id'] ?? '') !== $taskId) {
        throw new RuntimeException('Task lifecycle file does not match the active task.');
    }
    if ($expectedStatus !== '' && (string)($metadata['status'] ?? '') !== $expectedStatus) {
        throw new RuntimeException('Task lifecycle file has unexpected status.');
    }
}

function gitNamesFromCommand(array $result): array
{
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }

    return array_values(array_filter(preg_split('/\R/', trim((string)$result['stdout'])) ?: []));
}

function gitHead(string $repoRoot, string $logPath, string $ref = 'HEAD'): string
{
    $result = runLoggedCommand(['git', 'rev-parse', $ref], $repoRoot, $logPath, 30);
    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }

    return trim((string)$result['stdout']);
}

function verifyLifecycleRemoteSynchronized(string $repoRoot, string $logPath): void
{
    $fetch = runLoggedCommand(['git', 'fetch', '--prune', 'origin'], $repoRoot, $logPath, 180);
    if ($fetch['exit_code'] !== 0) {
        throw new RuntimeException('Lifecycle remote verification failed.');
    }
    if (gitHead($repoRoot, $logPath, 'HEAD') !== gitHead($repoRoot, $logPath, 'origin/main')) {
        throw new RuntimeException('Lifecycle push verification failed.');
    }
}

function verifyRepositoryClean(string $repoRoot, string $logPath): void
{
    $status = processRunCommand(['git', 'status', '--porcelain=v1', '-z'], [
        'cwd' => $repoRoot,
        'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        'inherit_env' => true,
        'timeout' => 30,
    ]);
    appendLog($logPath, '$ ' . (string)$status['command_display']);
    appendLog($logPath, 'Exit code: ' . (string)$status['exit_code']);
    if ($status['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }
    $records = gitParsePorcelainV1Z((string)($status['stdout_raw'] ?? $status['stdout']));
    if (!empty($records)) {
        $paths = [];
        foreach ($records as $record) {
            foreach (gitPorcelainRecordPaths($record) as $path) {
                $paths[] = $path;
            }
        }
        throw new RuntimeException('Repository is not clean after lifecycle completion: ' . implode(', ', array_values(array_unique($paths))) . '.');
    }
    appendActivity($logPath, 'Repository clean');
}

function completeTaskLifecycleTransaction(string $repoRoot, string $taskId, string $projectId, string $logPath): void
{
    $inProgress = taskPathForStatus($repoRoot, 'IN PROGRESS', $taskId);
    $done = taskPathForStatus($repoRoot, 'DONE', $taskId);
    if (is_file($inProgress)) {
        assertTaskIdentity($inProgress, $projectId, $taskId, 'IN PROGRESS');
        moveTaskFile($repoRoot, $taskId, 'IN PROGRESS', 'DONE');
        appendActivity($logPath, 'Task moved to DONE');
    } elseif (is_file($done)) {
        assertTaskIdentity($done, $projectId, $taskId, 'DONE');
        appendActivity($logPath, 'Existing unsynchronized DONE transition detected');
    } else {
        throw new RuntimeException('Task lifecycle state is ambiguous: expected IN PROGRESS or DONE for ' . $taskId . '.');
    }

    $paths = taskLifecycleStagePaths($repoRoot, $taskId);
    if (empty($paths)) {
        throw new RuntimeException('Lifecycle changes could not be staged: no task lifecycle paths found.');
    }
    $add = runLoggedCommand(array_merge(['git', 'add', '--'], $paths), $repoRoot, $logPath, 120);
    if ($add['exit_code'] !== 0) {
        throw new RuntimeException('Lifecycle changes could not be staged.');
    }
    appendActivity($logPath, 'Lifecycle changes staged');

    $expected = array_flip(taskLifecycleExpectedPaths($taskId));
    $stagedPaths = gitNamesFromCommand(runLoggedCommand(array_merge(['git', 'diff', '--cached', '--name-only', '--'], $paths), $repoRoot, $logPath, 30));
    foreach ($stagedPaths as $path) {
        if (!isset($expected[$path])) {
            throw new RuntimeException('Unexpected task lifecycle path staged: ' . $path . '.');
        }
    }

    $diff = runLoggedCommand(array_merge(['git', 'diff', '--cached', '--quiet', '--'], $paths), $repoRoot, $logPath, 30);
    if ($diff['exit_code'] === 0) {
        appendActivity($logPath, 'Task lifecycle already synchronized');
        $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath, 180);
        if ($push['exit_code'] !== 0) {
            throw new RuntimeException('Lifecycle push failed.');
        }
        appendActivity($logPath, 'Lifecycle pushed');
        verifyLifecycleRemoteSynchronized($repoRoot, $logPath);
        verifyRepositoryClean($repoRoot, $logPath);
        return;
    }
    if ($diff['exit_code'] !== 1) {
        throw new RuntimeException('Lifecycle diff verification failed.');
    }

    $commit = runLoggedCommand(['git', 'commit', '-m', $taskId . ': mark task done'], $repoRoot, $logPath, 120);
    if ($commit['exit_code'] !== 0) {
        throw new RuntimeException('Lifecycle commit failed.');
    }
    appendActivity($logPath, 'Lifecycle commit created');

    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath, 180);
    if ($push['exit_code'] !== 0) {
        throw new RuntimeException('Lifecycle push failed.');
    }
    appendActivity($logPath, 'Lifecycle pushed');

    verifyLifecycleRemoteSynchronized($repoRoot, $logPath);
    verifyRepositoryClean($repoRoot, $logPath);
}

function codexLifecycleRecoverySourceSynchronized(string $repoRoot, string $commit, string $logPath = ''): bool
{
    if ($commit === '' || preg_match('/^[0-9a-f]{7,40}$/i', $commit) !== 1) {
        return false;
    }
    $fetch = processRunCommand(['git', 'fetch', '--prune', 'origin'], [
        'cwd' => $repoRoot,
        'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        'inherit_env' => true,
        'timeout' => 180,
    ]);
    if ($logPath !== '') {
        appendLog($logPath, '$ ' . (string)$fetch['command_display']);
        appendLog($logPath, 'Exit code: ' . (string)$fetch['exit_code']);
    }
    if ($fetch['exit_code'] !== 0) {
        return false;
    }
    $sourceInHead = processRunCommand(['git', 'merge-base', '--is-ancestor', $commit, 'HEAD'], ['cwd' => $repoRoot, 'timeout' => 30]);
    $sourceInOrigin = processRunCommand(['git', 'merge-base', '--is-ancestor', $commit, 'origin/main'], ['cwd' => $repoRoot, 'timeout' => 30]);
    if ($sourceInHead['exit_code'] !== 0 || $sourceInOrigin['exit_code'] !== 0) {
        return false;
    }

    return true;
}

function codexLifecycleRecoveryFailureLooksRecoverable(array $result): bool
{
    $summary = strtolower((string)($result['summary'] ?? ''));
    if ($summary === '') {
        return false;
    }
    foreach ([
        'task file is not in in progress',
        'task lifecycle',
        'lifecycle',
        'repository is not clean after lifecycle completion',
        'lifecycle push failed',
        'lifecycle commit failed',
        'lifecycle changes could not be staged',
    ] as $needle) {
        if (str_contains($summary, $needle)) {
            return true;
        }
    }

    return false;
}

function codexLifecycleRecoveryState(?array $project, string $repoRoot, string $runsDir, string $taskId, string $source): array
{
    if ($project === null || $source !== 'project' || !isTaskId($taskId)) {
        return ['recoverable' => false, 'reason' => 'Recovery is available only for Project task runs.'];
    }
    $realRepo = realpath($repoRoot);
    $realProjectPath = realpath((string)($project['repository_path'] ?? ''));
    $realDevConsole = realpath(devConsoleRepositoryRoot());
    if ($realRepo === false || $realProjectPath === false || $realRepo !== $realProjectPath || $realRepo === $realDevConsole || !is_dir($repoRoot . '/.git')) {
        return ['recoverable' => false, 'reason' => 'Project repository is not available.'];
    }
    $status = codexRunStatus($runsDir, $taskId, $source);
    if ($status !== 'failed') {
        return ['recoverable' => false, 'reason' => 'Recovery is available only for failed runs.'];
    }
    $result = codexRunResult($runsDir, $taskId, $source);
    $commit = (string)($result['commit'] ?? '');
    if ($commit === '') {
        return ['recoverable' => false, 'reason' => 'No source commit was recorded for this run.'];
    }
    if ((string)($result['validation'] ?? '') === 'Failed') {
        return ['recoverable' => false, 'reason' => 'Validation failed before lifecycle recovery was possible.'];
    }
    if (!codexLifecycleRecoveryFailureLooksRecoverable($result)) {
        return ['recoverable' => false, 'reason' => 'The failure does not appear to be a lifecycle synchronization failure.'];
    }
    if (!codexLifecycleRecoverySourceSynchronized($repoRoot, $commit)) {
        return ['recoverable' => false, 'reason' => 'The source commit is not synchronized with origin/main.'];
    }

    $inProgress = taskPathForStatus($repoRoot, 'IN PROGRESS', $taskId);
    $done = taskPathForStatus($repoRoot, 'DONE', $taskId);
    try {
        if (is_file($inProgress)) {
            assertTaskIdentity($inProgress, (string)$project['id'], $taskId, 'IN PROGRESS');
        } elseif (is_file($done)) {
            assertTaskIdentity($done, (string)$project['id'], $taskId, 'DONE');
        } else {
            return ['recoverable' => false, 'reason' => 'Expected task lifecycle file is not present.'];
        }
    } catch (Throwable $exception) {
        return ['recoverable' => false, 'reason' => $exception->getMessage()];
    }

    return ['recoverable' => true, 'reason' => '', 'commit' => $commit];
}

function recoverCodexLifecycle(array $project, string $repoRoot, string $runsDir, string $taskId, string $source): array
{
    $state = codexLifecycleRecoveryState($project, $repoRoot, $runsDir, $taskId, $source);
    if (empty($state['recoverable'])) {
        throw new RuntimeException((string)($state['reason'] ?? 'Task lifecycle recovery is not available.'));
    }

    $statusPath = workerRunFile($runsDir, $taskId, 'status', $source);
    $logPath = workerRunFile($runsDir, $taskId, 'log', $source);
    $result = codexRunResult($runsDir, $taskId, $source);
    $startedAt = time();
    appendActivity($logPath, 'Lifecycle recovery started');
    if (!codexLifecycleRecoverySourceSynchronized($repoRoot, (string)$state['commit'], $logPath)) {
        throw new RuntimeException('The source commit is not synchronized with origin/main.');
    }
    appendActivity($logPath, 'Source commit already synchronized');
    completeTaskLifecycleTransaction($repoRoot, $taskId, (string)$project['id'], $logPath);
    appendActivity($logPath, 'Lifecycle recovery completed');
    writeStatus($statusPath, 'completed');

    $updated = array_merge($result, [
        'task_id' => $taskId,
        'status' => 'Completed (recovered)',
        'validation' => (string)($result['validation'] ?? '') === '' || (string)($result['validation'] ?? '') === 'Not completed' ? 'Passed' : (string)$result['validation'],
        'duration_seconds' => max((int)($result['duration_seconds'] ?? 0), max(0, time() - $startedAt)),
        'recovered_at' => date('c'),
        'recovery' => [
            'status' => 'completed',
            'message' => 'Task lifecycle synchronized without rerunning Codex.',
        ],
        'summary' => trim((string)($result['summary'] ?? '') . "\n\nLifecycle recovery completed."),
        'finished_at' => date('c'),
    ]);
    writeResult($runsDir, $taskId, $source, $updated);

    return $updated;
}
