<?php
require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/deployment.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/git.php';
require __DIR__ . '/tasks.php';
require __DIR__ . '/task-lifecycle.php';

$taskId = (string)($argv[1] ?? '');
$projectId = (string)($argv[2] ?? '');
$taskSource = (string)($argv[3] ?? 'project');
$projectConfiguration = devConsoleLoadProjectConfiguration();
$project = $projectId === '' ? null : devConsoleFindProjectById($projectConfiguration, $projectId);
$githubConfiguration = devConsoleLoadGithubConfiguration();
$repoRoot = devConsoleProjectTaskRoot($projectConfiguration, $project);
$runsDir = devConsoleProjectRunsDir($project);
$startedAt = time();
$protectedTasksSnapshot = null;

function resolveCodexCommand(): string
{
    if (function_exists('serverToolsResolveCodexCommand')) {
        $path = serverToolsResolveCodexCommand();
        if ($path !== '') {
            return $path;
        }
    }

    if (function_exists('serverToolsFindExecutable') && function_exists('serverToolsDefaultPath')) {
        $path = serverToolsFindExecutable('codex', serverToolsDefaultPath());
        if ($path !== '') {
            return $path;
        }
    }

    foreach (explode(':', getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $directory) {
        $path = rtrim($directory, '/') . '/codex';
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function eventValue(array $event, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $event)) {
            continue;
        }
        if (is_string($event[$key])) {
            return $event[$key];
        }
        if (is_array($event[$key])) {
            return eventValue($event[$key], $keys);
        }
    }
    foreach ($event as $value) {
        if (is_array($value)) {
            $nested = eventValue($value, $keys);
            if ($nested !== '') {
                return $nested;
            }
        }
    }

    return '';
}

function commandTextFromEvent(array $event): string
{
    foreach (['command', 'cmd'] as $key) {
        if (!array_key_exists($key, $event)) {
            continue;
        }
        if (is_array($event[$key])) {
            return implode(' ', array_map('strval', $event[$key]));
        }
        if (is_string($event[$key])) {
            return $event[$key];
        }
    }

    return eventValue($event, ['command', 'cmd']);
}

function activityForCommandText(string $command): string
{
    $command = trim($command);
    if (preg_match('/\bgit\s+status\b/i', $command) === 1) return 'Git status';
    if (preg_match('/\bgit\s+add\b/i', $command) === 1) return 'Git staging';
    if (preg_match('/\bgit\s+commit\b/i', $command) === 1) return 'Git commit';
    if (preg_match('/\bgit\s+push\b/i', $command) === 1) return 'GitHub push';
    if (preg_match('/\bphp\s+-l\b|\blint\b|\btest\b/i', $command) === 1) return 'Validation';
    if (preg_match('/\bapply_patch\b|\bmv\b|\bcp\b|\brm\b/i', $command) === 1) return 'Updating files';
    if (preg_match('/\brg\b|\bfind\b|\bls\b|\bgit\s+show\b/i', $command) === 1) return 'Inspecting repository';

    return 'Codex command';
}

function handleCodexJsonEvent(array $event, string $logPath): void
{
    $type = strtolower((string)($event['type'] ?? ''));
    $status = strtolower((string)($event['status'] ?? ''));
    $item = isset($event['item']) && is_array($event['item']) ? $event['item'] : [];
    $itemType = strtolower((string)($item['type'] ?? ''));

    if ($type === 'agent_message' || $itemType === 'agent_message') {
        $messageSource = $itemType === 'agent_message' ? $item : $event;
        $message = cleanActivityText(eventValue($messageSource, ['text', 'message', 'content']));
        if ($message !== '') {
            appendActivity($logPath, 'Codex output: ' . $message);
        }
        return;
    }

    if ($type === 'command_execution' || $itemType === 'command_execution') {
        $isStartedEvent = str_ends_with($type, '.started') || $type === 'item_started';
        if ($isStartedEvent || ($type === 'command_execution' && $status === '') || in_array($status, ['started', 'running', 'in_progress'], true)) {
            appendActivity($logPath, activityForCommandText(commandTextFromEvent($itemType === 'command_execution' ? $item : $event)));
        } elseif (in_array($status, ['failed', 'error'], true)) {
            appendActivity($logPath, 'Codex command failed');
        }
        return;
    }

    if (in_array($status, ['failed', 'error'], true) || in_array($type, ['error', 'turn_failed'], true)) {
        appendActivity($logPath, 'Codex process failed');
    }
}

function processEnvironment(): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['GIT_TERMINAL_PROMPT'] = '0';
    $environment['GIT_AUTHOR_NAME'] = 'IOVON Dev Console';
    $environment['GIT_AUTHOR_EMAIL'] = 'iovon@iovon.com';
    $environment['GIT_COMMITTER_NAME'] = 'IOVON Dev Console';
    $environment['GIT_COMMITTER_EMAIL'] = 'iovon@iovon.com';

    return $environment;
}

function gitStatusPorcelain(string $repoRoot, string $logPath): string
{
    $status = runLoggedCommand(['git', 'status', '--porcelain=v1'], $repoRoot, $logPath, 30);
    if ($status['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }

    return trim((string)$status['stdout']);
}

function assertProjectRepository(?array $project, string $repoRoot, string $projectId): void
{
    if ($project === null) {
        throw new RuntimeException('Project not found.');
    }
    if ($projectId === '' || (string)($project['id'] ?? '') !== $projectId) {
        throw new RuntimeException('Task belongs to another Project.');
    }
    if (gitValidateProjectRepositoryPath($project) !== null || !is_dir($repoRoot . '/.git')) {
        throw new RuntimeException('Project repository missing.');
    }
    $realRepo = realpath($repoRoot);
    if ($realRepo === false || $realRepo !== realpath((string)$project['repository_path']) || $realRepo === realpath(devConsoleRepositoryRoot())) {
        throw new RuntimeException('Project path containment check failed.');
    }
}

function assertCodexAuthenticated(string $codex, string $repoRoot): void
{
    if (function_exists('serverToolsCodexAuthStatus')) {
        $status = serverToolsCodexAuthStatus(null, $codex);
        if ((string)($status['state'] ?? '') !== 'authenticated') {
            throw new RuntimeException('Codex CLI is not authenticated for the Dev Console service user.');
        }

        return;
    }

    $doctor = processRunCommand([$codex, 'doctor', '--json'], [
        'cwd' => $repoRoot,
        'env' => function_exists('serverToolsCodexEnvironment') ? serverToolsCodexEnvironment() : [],
        'inherit_env' => !function_exists('serverToolsCodexEnvironment'),
        'timeout' => 20,
    ]);
    if ($doctor['exit_code'] !== 0 && trim((string)$doctor['stdout']) === '') {
        throw new RuntimeException('Codex CLI is not authenticated for the Dev Console service user.');
    }
    $decoded = json_decode((string)$doctor['stdout'], true);
    $authStatus = is_array($decoded) ? (string)($decoded['checks']['auth.credentials']['status'] ?? '') : '';
    if ($authStatus !== 'ok') {
        throw new RuntimeException('Codex CLI is not authenticated for the Dev Console service user.');
    }
}

function currentTaskPath(string $repoRoot, string $taskId): string
{
    foreach (['IN PROGRESS', 'TODO'] as $status) {
        $path = taskPathForStatus($repoRoot, $status, $taskId);
        if (is_file($path)) {
            return $path;
        }
    }

    throw new RuntimeException('Task file is not runnable.');
}

function changedProjectRecords(string $repoRoot, string $logPath): array
{
    appendActivity($logPath, 'Git status');
    $result = processRunCommand(['git', 'status', '--porcelain=v1', '-z'], [
        'cwd' => $repoRoot,
        'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        'inherit_env' => true,
        'timeout' => 30,
    ]);
    appendLog($logPath, '$ ' . (string)$result['command_display']);
    appendLog($logPath, 'Exit code: ' . (string)$result['exit_code']);
    if ($result['exit_code'] !== 0) {
        if (trim((string)$result['output']) !== '') {
            appendLog($logPath, cleanActivityText((string)$result['output']));
        }
        throw new RuntimeException('Git status failed.');
    }

    return gitParsePorcelainV1Z((string)($result['stdout_raw'] ?? $result['stdout']));
}

function implementationChangedFiles(array $records): array
{
    $files = [];
    foreach ($records as $record) {
        if (gitPorcelainRecordIsUnderPath($record, 'TASKS/')) {
            continue;
        }
        foreach (gitPorcelainRecordPaths($record) as $path) {
            $files[] = $path;
        }
    }

    return array_values(array_unique($files));
}

function validateCodexChanges(string $repoRoot, array $changedFiles, string $logPath): void
{
    appendActivity($logPath, 'Validation');
    $check = runLoggedCommand(array_merge(['git', 'diff', '--check', '--'], $changedFiles), $repoRoot, $logPath, 120);
    if ($check['exit_code'] !== 0) {
        throw new RuntimeException('Validation failed.');
    }
    $cachedCheck = runLoggedCommand(array_merge(['git', 'diff', '--cached', '--check', '--'], $changedFiles), $repoRoot, $logPath, 120);
    if ($cachedCheck['exit_code'] !== 0) {
        throw new RuntimeException('Validation failed.');
    }
    foreach ($changedFiles as $path) {
        if (!str_ends_with($path, '.php') || !is_file($repoRoot . '/' . $path)) {
            continue;
        }
        $lint = runLoggedCommand(['php', '-l', $path], $repoRoot, $logPath, 30);
        if ($lint['exit_code'] !== 0) {
            throw new RuntimeException('Validation failed.');
        }
    }
}

function commitAndPushProjectChanges(string $repoRoot, string $taskId, string $title, array $files, string $logPath): string
{
    appendActivity($logPath, 'Commit');
    $add = runLoggedCommand(array_merge(['git', 'add', '--'], $files), $repoRoot, $logPath, 120);
    if ($add['exit_code'] !== 0) {
        throw new RuntimeException('Commit failed.');
    }
    $message = $taskId . ': ' . ($title === '' || $title === '...' ? 'Complete task' : $title);
    $commit = runLoggedCommand(['git', 'commit', '-m', $message], $repoRoot, $logPath, 120);
    if ($commit['exit_code'] !== 0) {
        throw new RuntimeException('Commit failed.');
    }
    appendActivity($logPath, 'Source commit created');
    appendActivity($logPath, 'Push');
    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath, 180);
    if ($push['exit_code'] !== 0) {
        throw new RuntimeException('Push failed.');
    }
    appendActivity($logPath, 'Source pushed');
    $head = runLoggedCommand(['git', 'rev-parse', 'HEAD'], $repoRoot, $logPath, 30);
    if ($head['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }

    return trim((string)$head['stdout']);
}

function streamCodexActivity($process, array $pipes, string $prompt, string $logPath): array
{
    fwrite($pipes[0], $prompt);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buffers = [1 => '', 2 => ''];
    $stderr = '';

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        foreach ([1, 2] as $pipeIndex) {
            $chunk = stream_get_contents($pipes[$pipeIndex]);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $buffers[$pipeIndex] .= $chunk;
            if ($pipeIndex === 2) {
                $stderr .= $chunk;
                if (strlen($stderr) > 4000) {
                    $stderr = substr($stderr, -4000);
                }
            }
            $lines = preg_split('/\r\n|\r|\n/', $buffers[$pipeIndex]);
            $buffers[$pipeIndex] = (string)array_pop($lines);
            if ($pipeIndex === 1) {
                foreach ($lines as $line) {
                    $event = json_decode($line, true);
                    if (is_array($event)) {
                        handleCodexJsonEvent($event, $logPath);
                    }
                }
            }
        }
        usleep(200000);
    }

    foreach ([1, 2] as $pipeIndex) {
        $remaining = $buffers[$pipeIndex] . (stream_get_contents($pipes[$pipeIndex]) ?: '');
        if ($pipeIndex === 2) {
            $stderr .= $remaining;
        }
        if ($pipeIndex === 1) {
            foreach (preg_split('/\r\n|\r|\n/', $remaining) ?: [] as $line) {
                $event = json_decode($line, true);
                if (is_array($event)) {
                    handleCodexJsonEvent($event, $logPath);
                }
            }
        }
        fclose($pipes[$pipeIndex]);
    }

    return [
        'exit_code' => proc_close($process),
        'stderr' => cleanActivityText($stderr),
    ];
}

if (realpath((string)($argv[0] ?? '')) === __FILE__) {
try {
    if (!isTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }
    if ($taskSource !== 'project') {
        throw new RuntimeException('Codex can run only Project repository tasks.');
    }
    assertProjectRepository($project, $repoRoot, $projectId);

    $promptPath = workerRunFile($runsDir, $taskId, 'prompt', $taskSource);
    $statusPath = workerRunFile($runsDir, $taskId, 'status', $taskSource);
    $logPath = workerRunFile($runsDir, $taskId, 'log', $taskSource);
    if (!is_file($promptPath)) {
        throw new RuntimeException('Prompt file not found.');
    }

    writeStatus($statusPath, 'running');
    appendActivity($logPath, 'Task selected');

    $taskPath = currentTaskPath($repoRoot, $taskId);
    assertTaskMetadata($taskPath, $projectId);
    $taskWasTodo = str_contains($taskPath, '/TASKS/TODO/');
    if ($taskWasTodo && gitStatusPorcelain($repoRoot, $logPath) !== '') {
        throw new RuntimeException('Project working tree contains uncommitted changes. Commit, discard, or resolve them before running Codex.');
    }
    if ($taskWasTodo) {
        moveTaskFile($repoRoot, $taskId, 'TODO', 'IN PROGRESS');
        appendActivity($logPath, 'Task moved to IN PROGRESS');
    }
    $protectedTasksSnapshot = createProtectedTasksSnapshot($repoRoot);

    $codex = resolveCodexCommand();
    if ($codex === '') {
        throw new RuntimeException('Codex CLI not installed.');
    }
    assertCodexAuthenticated($codex, $repoRoot);

    $summaryPath = workerRunFile($runsDir, $taskId, 'summary.txt', $taskSource);
    @unlink($summaryPath);
    $arguments = [
        $codex,
        'exec',
        '-C',
        $repoRoot,
        '--sandbox',
        'workspace-write',
        '--json',
        '-o',
        $summaryPath,
        '-',
    ];
    appendActivity($logPath, 'Codex started');
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot, processEnvironment());
    if (!is_resource($process)) {
        throw new RuntimeException('Codex process failed.');
    }
    $codexResult = streamCodexActivity($process, $pipes, (string)file_get_contents($promptPath), $logPath);
    if ($codexResult['exit_code'] !== 0) {
        if ($codexResult['stderr'] !== '') {
            appendLog($logPath, 'Codex error: ' . $codexResult['stderr']);
        }
        throw new RuntimeException('Codex process failed.');
    }
    appendActivity($logPath, 'Codex completed');
    restoreProtectedTasksState($repoRoot, $protectedTasksSnapshot, $logPath);

    $changedRecords = changedProjectRecords($repoRoot, $logPath);
    $changedFiles = implementationChangedFiles($changedRecords);
    if (empty($changedFiles)) {
        throw new RuntimeException('No task changes to commit.');
    }
    validateCodexChanges($repoRoot, $changedFiles, $logPath);
    appendActivity($logPath, 'Source validation passed');
    $taskBody = (string)file_get_contents(taskPathForStatus($repoRoot, 'IN PROGRESS', $taskId));
    $title = taskMarkdownMetadata($taskBody)['title'] ?? '';
    $commitHash = commitAndPushProjectChanges($repoRoot, $taskId, $title, $changedFiles, $logPath);
    completeTaskLifecycleTransaction($repoRoot, $taskId, $projectId, $logPath);
    appendActivity($logPath, 'Task lifecycle synchronized');

    writeStatus($statusPath, 'completed');
    $duration = max(0, time() - $startedAt);
    $summary = is_file($summaryPath) ? trim((string)file_get_contents($summaryPath)) : '';
    writeResult($runsDir, $taskId, $taskSource, [
        'task_id' => $taskId,
        'status' => 'Completed',
        'commit' => $commitHash,
        'files_changed' => count($changedFiles),
        'validation' => 'Passed',
        'duration_seconds' => $duration,
        'summary' => $summary,
        'finished_at' => date('c'),
    ]);
    appendActivity($logPath, 'Completed');
    cleanupProtectedTasksSnapshot($protectedTasksSnapshot);
} catch (Throwable $exception) {
    cleanupProtectedTasksSnapshot($protectedTasksSnapshot);
    if (isTaskId($taskId) && in_array($taskSource, ['project', 'legacy'], true)) {
        $statusPath = workerRunFile($runsDir, $taskId, 'status', $taskSource);
        $logPath = workerRunFile($runsDir, $taskId, 'log', $taskSource);
        writeStatus($statusPath, 'failed');
        writeResult($runsDir, $taskId, $taskSource, [
            'task_id' => $taskId,
            'status' => 'Failed',
            'commit' => '',
            'files_changed' => 0,
            'validation' => str_contains($exception->getMessage(), 'Validation failed') ? 'Failed' : 'Not completed',
            'duration_seconds' => max(0, time() - $startedAt),
            'summary' => $exception->getMessage(),
            'finished_at' => date('c'),
        ]);
        appendLog($logPath, 'Error: ' . $exception->getMessage());
        appendActivity($logPath, 'Failed');
    }
    exit(1);
}
}
