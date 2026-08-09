<?php
require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/deployment.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/git.php';
require __DIR__ . '/tasks.php';

$taskId = (string)($argv[1] ?? '');
$projectId = (string)($argv[2] ?? '');
$taskSource = (string)($argv[3] ?? 'project');
$projectConfiguration = devConsoleLoadProjectConfiguration();
$project = $projectId === '' ? null : devConsoleFindProjectById($projectConfiguration, $projectId);
$githubConfiguration = devConsoleLoadGithubConfiguration();
$repoRoot = devConsoleProjectTaskRoot($projectConfiguration, $project);
$runsDir = devConsoleProjectRunsDir($project);
$startedAt = time();

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

function resolveCodexCommand(): string
{
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

function cleanActivityText(string $text): string
{
    $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
    $text = trim($text);

    return strlen($text) > 1000 ? substr($text, 0, 1000) . '...' : $text;
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
    $doctor = processRunCommand([$codex, 'doctor', '--json'], [
        'cwd' => $repoRoot,
        'inherit_env' => true,
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

function assertTaskMetadata(string $path, string $projectId): void
{
    $metadataProjectId = taskProjectId((string)file_get_contents($path));
    if ($metadataProjectId === '' || $metadataProjectId !== $projectId) {
        throw new RuntimeException('Task belongs to another Project.');
    }
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
    appendActivity($logPath, 'Push');
    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath, 180);
    if ($push['exit_code'] !== 0) {
        throw new RuntimeException('Push failed.');
    }
    $head = runLoggedCommand(['git', 'rev-parse', 'HEAD'], $repoRoot, $logPath, 30);
    if ($head['exit_code'] !== 0) {
        throw new RuntimeException('Git status failed.');
    }

    return trim((string)$head['stdout']);
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

function commitAndPushTaskDone(string $repoRoot, string $taskId, string $logPath): void
{
    appendActivity($logPath, 'Task moved to DONE');
    $paths = taskLifecycleStagePaths($repoRoot, $taskId);
    if (empty($paths)) {
        throw new RuntimeException('Commit failed.');
    }
    $add = runLoggedCommand(array_merge(['git', 'add', '--'], $paths), $repoRoot, $logPath, 120);
    if ($add['exit_code'] !== 0) {
        throw new RuntimeException('Commit failed.');
    }
    $commit = runLoggedCommand(['git', 'commit', '-m', $taskId . ': mark task done'], $repoRoot, $logPath, 120);
    if ($commit['exit_code'] !== 0) {
        throw new RuntimeException('Commit failed.');
    }
    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath, 180);
    if ($push['exit_code'] !== 0) {
        $done = taskPathForStatus($repoRoot, 'DONE', $taskId);
        $inProgress = taskPathForStatus($repoRoot, 'IN PROGRESS', $taskId);
        if (is_file($done) && !is_file($inProgress)) {
            @rename($done, $inProgress);
        }
        throw new RuntimeException('Push failed.');
    }
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

    $changedRecords = changedProjectRecords($repoRoot, $logPath);
    $changedFiles = implementationChangedFiles($changedRecords);
    if (empty($changedFiles)) {
        throw new RuntimeException('No task changes to commit.');
    }
    validateCodexChanges($repoRoot, $changedFiles, $logPath);
    $taskBody = (string)file_get_contents(taskPathForStatus($repoRoot, 'IN PROGRESS', $taskId));
    $title = taskMarkdownMetadata($taskBody)['title'] ?? '';
    $commitHash = commitAndPushProjectChanges($repoRoot, $taskId, $title, $changedFiles, $logPath);
    moveTaskFile($repoRoot, $taskId, 'IN PROGRESS', 'DONE');
    commitAndPushTaskDone($repoRoot, $taskId, $logPath);

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
} catch (Throwable $exception) {
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
