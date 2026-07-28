<?php
require __DIR__ . '/config.php';
require __DIR__ . '/git.php';

$taskId = (string)($argv[1] ?? '');
$projectId = (string)($argv[2] ?? '');
$taskSource = (string)($argv[3] ?? 'project');
$projectConfiguration = devConsoleLoadProjectConfiguration();
$project = $projectId === '' ? null : devConsoleFindProjectById($projectConfiguration, $projectId);
$githubConfiguration = devConsoleLoadGithubConfiguration();
$repoRoot = $taskSource === 'legacy' && $projectId === devConsoleFirstProjectId($projectConfiguration)
    ? dirname(devConsoleRepositoryRoot())
    : devConsoleProjectTaskRoot($projectConfiguration, $project);
$runsDir = devConsoleProjectRunsDir($project);

function workerIsTaskId(string $taskId): bool
{
    return preg_match('/^TASK-\d{3}$/', $taskId) === 1;
}

function workerTaskProjectId(string $body): string
{
    if (preg_match('/\A---\s*\R(.*?)\R---\s*(?:\R|$)/s', $body, $matches) !== 1) {
        return '';
    }
    foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
        if (preg_match('/^project_id:\s*([a-z0-9]+(?:-[a-z0-9]+)*)\s*$/i', trim($line), $lineMatches) === 1) {
            return strtolower($lineMatches[1]);
        }
    }

    return '';
}

function workerAssertTaskBelongsToProject(string $repoRoot, string $taskId, ?array $project): void
{
    if ($project === null) {
        throw new RuntimeException('Project not found.');
    }
    $path = $repoRoot . '/TASKS/TODO/' . $taskId . '.md';
    if (!is_file($path)) {
        throw new RuntimeException('Task file is not in TODO.');
    }
    $metadataProjectId = workerTaskProjectId((string)file_get_contents($path));
    if ($metadataProjectId !== '' && $metadataProjectId !== (string)($project['id'] ?? '')) {
        throw new RuntimeException('Task does not belong to the selected Project.');
    }
}

function workerRunFile(string $runsDir, string $taskId, string $extension, string $source = 'project'): string
{
    if (!workerIsTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }
    if (!in_array($source, ['project', 'legacy'], true)) {
        throw new RuntimeException('Invalid task source.');
    }

    $prefix = $source === 'project' ? '' : $source . '-';
    return $runsDir . '/' . $prefix . $taskId . '.' . $extension;
}

function appendLog(string $logPath, string $message): void
{
    file_put_contents($logPath, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
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
    file_put_contents($statusPath, $status);
}

function resolveCodexCommand(): string
{
    $resolved = trim((string)shell_exec('command -v codex 2>/dev/null'));

    return $resolved === '' ? 'codex' : $resolved;
}

function shellCommand(array $arguments): string
{
    return implode(' ', array_map('escapeshellarg', $arguments));
}

function commandForLog(array $arguments): string
{
    return implode(' ', $arguments);
}

function activityForCommand(array $arguments): string
{
    $command = implode(' ', $arguments);

    if (str_starts_with($command, 'git status')) {
        return 'Running git status';
    }
    if (str_starts_with($command, 'git add')) {
        return 'Running git add';
    }
    if (str_starts_with($command, 'git commit')) {
        return 'Running git commit';
    }
    if (str_starts_with($command, 'git push')) {
        return 'Running git push';
    }

    return 'Running command';
}

function cleanActivityText(string $text): string
{
    $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
    $text = trim($text);

    return strlen($text) > 1000 ? substr($text, 0, 1000) . '...' : $text;
}

function activityForCommandText(string $command): string
{
    $command = trim($command);

    if (preg_match('/\bgit\s+status\b/i', $command) === 1) {
        return 'Running git status';
    }
    if (preg_match('/\bgit\s+add\b/i', $command) === 1) {
        return 'Running git add';
    }
    if (preg_match('/\bgit\s+commit\b/i', $command) === 1) {
        return 'Running git commit';
    }
    if (preg_match('/\bgit\s+push\b/i', $command) === 1) {
        return 'Running git push';
    }
    if (preg_match('/\bphp\s+-l\b|\blint\b|\btest\b/i', $command) === 1) {
        return 'Running validation';
    }
    if (preg_match('/\bapply_patch\b|\bmv\b|\bcp\b|\brm\b/i', $command) === 1) {
        return 'Updating files';
    }
    if (preg_match('/\bTASKS\/TODO\/TASK-\d{3}\.md\b|\bTASK-\d{3}\.md\b/i', $command) === 1) {
        return 'Reading task file';
    }
    if (preg_match('/\bAGENTS\.md\b/i', $command) === 1) {
        return 'Reading AGENTS.md';
    }
    if (preg_match('/\bPROJECT\.md\b|\bDECISIONS\.md\b/i', $command) === 1) {
        return 'Reading project files';
    }
    if (preg_match('/\brg\b|\bfind\b|\bls\b|\bgit\s+show\b/i', $command) === 1) {
        return 'Inspecting repository';
    }
    if (preg_match('/\bsed\b|\bcat\b|\bnl\b/i', $command) === 1) {
        return 'Reading project files';
    }

    return 'Running command';
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
            appendActivity($logPath, $message);
        }
        return;
    }

    if ($type === 'command_execution' || $itemType === 'command_execution') {
        $isStartedEvent = str_ends_with($type, '.started') || $type === 'item_started';
        if ($isStartedEvent || ($type === 'command_execution' && $status === '') || in_array($status, ['started', 'running', 'in_progress'], true)) {
            appendActivity($logPath, activityForCommandText(commandTextFromEvent($itemType === 'command_execution' ? $item : $event)));
        } elseif (in_array($status, ['failed', 'error'], true)) {
            appendActivity($logPath, 'Failed');
        }
        return;
    }

    if (in_array($status, ['failed', 'error'], true) || in_array($type, ['error', 'turn_failed'], true)) {
        appendActivity($logPath, 'Failed');
    }
}

function streamCodexActivity($process, array $pipes, string $logPath): array
{
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
            foreach (preg_split('/\r\n|\r|\n/', $remaining) as $line) {
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

function processEnvironment(): array
{
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }

    $environment['GIT_AUTHOR_NAME'] = 'IOVON Dev Console';
    $environment['GIT_AUTHOR_EMAIL'] = 'iovon@iovon.com';
    $environment['GIT_COMMITTER_NAME'] = 'IOVON Dev Console';
    $environment['GIT_COMMITTER_EMAIL'] = 'iovon@iovon.com';

    return $environment;
}

function runLoggedCommand(array $arguments, string $cwd, string $logPath): array
{
    appendActivity($logPath, activityForCommand($arguments));
    $isGitPush = ($arguments[0] ?? '') === 'git' && ($arguments[1] ?? '') === 'push';
    if ($isGitPush && is_array($GLOBALS['project'] ?? null) && devConsoleGithubConfigured($GLOBALS['githubConfiguration'] ?? [])) {
        $result = gitRunAuthenticatedCommand(array_merge(['git', '-C', $cwd], array_slice($arguments, 1)), $GLOBALS['githubConfiguration'], 120);
    } else {
        $result = processRunCommand($arguments, [
            'cwd' => $cwd,
            'env' => [
                'GIT_TERMINAL_PROMPT' => '0',
                'GIT_AUTHOR_NAME' => 'IOVON Dev Console',
                'GIT_AUTHOR_EMAIL' => 'iovon@iovon.com',
                'GIT_COMMITTER_NAME' => 'IOVON Dev Console',
                'GIT_COMMITTER_EMAIL' => 'iovon@iovon.com',
            ],
            'inherit_env' => false,
            'timeout' => 120,
        ]);
    }
    $exitCode = (int)$result['exit_code'];
    $output = trim((string)$result['output']);

    if ($exitCode !== 0) {
        appendLog($logPath, commandForLog($arguments) . ' failed: ' . ($output === '' ? 'no output' : substr($output, 0, 1000)));
    } elseif (str_starts_with(commandForLog($arguments), 'git commit') && $output !== '') {
        appendLog($logPath, 'Git commit summary: ' . strtok($output, "\n"));
    }

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function completeGitWorkflow(string $repoRoot, string $taskId, string $logPath): void
{
    $status = runLoggedCommand(['git', 'status', '--short'], $repoRoot, $logPath);
    if ($status['exit_code'] !== 0) {
        throw new RuntimeException('git status failed.');
    }

    if ($status['output'] === '') {
        throw new RuntimeException('No task changes to commit.');
    }

    $add = runLoggedCommand(['git', 'add', '-A'], $repoRoot, $logPath);
    if ($add['exit_code'] !== 0) {
        throw new RuntimeException('git add failed.');
    }

    $commit = runLoggedCommand(['git', 'commit', '-m', 'Complete ' . $taskId], $repoRoot, $logPath);
    if ($commit['exit_code'] !== 0) {
        throw new RuntimeException('git commit failed.');
    }

    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath);
    if ($push['exit_code'] !== 0) {
        throw new RuntimeException('git push failed.');
    }
}

function moveTaskToDone(string $repoRoot, string $taskId, string $logPath): void
{
    $source = $repoRoot . '/TASKS/TODO/' . $taskId . '.md';
    $targetDir = $repoRoot . '/TASKS/DONE';
    $target = $targetDir . '/' . $taskId . '.md';

    if (is_file($target)) {
        appendActivity($logPath, 'Task already moved to DONE by Codex.');
        return;
    }
    if (!is_file($source)) {
        throw new RuntimeException('Task file is not in TODO.');
    }
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        throw new RuntimeException('Unable to create TASKS/DONE.');
    }

    appendActivity($logPath, 'Moving task to DONE');
    if (!rename($source, $target)) {
        throw new RuntimeException('Unable to move task to DONE.');
    }

    $add = runLoggedCommand(['git', 'add', 'TASKS/TODO/' . $taskId . '.md', 'TASKS/DONE/' . $taskId . '.md'], $repoRoot, $logPath);
    if ($add['exit_code'] !== 0) {
        rename($target, $source);
        throw new RuntimeException('git add failed for task move.');
    }

    $commit = runLoggedCommand(['git', 'commit', '-m', 'Mark ' . $taskId . ' done'], $repoRoot, $logPath);
    if ($commit['exit_code'] !== 0) {
        rename($target, $source);
        throw new RuntimeException('git commit failed for task move.');
    }

    $push = runLoggedCommand(['git', 'push', 'origin', 'main'], $repoRoot, $logPath);
    if ($push['exit_code'] !== 0) {
        rename($target, $source);
        throw new RuntimeException('git push failed for task move.');
    }
}

try {
    if (!workerIsTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }
    workerAssertTaskBelongsToProject($repoRoot, $taskId, $project);

    $promptPath = workerRunFile($runsDir, $taskId, 'prompt', $taskSource);
    $statusPath = workerRunFile($runsDir, $taskId, 'status', $taskSource);
    $logPath = workerRunFile($runsDir, $taskId, 'log', $taskSource);

    if (!is_file($promptPath)) {
        throw new RuntimeException('Prompt file not found.');
    }

    $prompt = (string)file_get_contents($promptPath);
    writeStatus($statusPath, 'running');
    appendActivity($logPath, 'Inspecting repository');

    $arguments = [
        resolveCodexCommand(),
        'exec',
        '--cd',
        $repoRoot,
        '--sandbox',
        'workspace-write',
        '--json',
        $prompt,
    ];

    appendActivity($logPath, 'Running Codex');

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(shellCommand($arguments), $descriptors, $pipes, $repoRoot, processEnvironment());
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Codex process.');
    }

    $codexResult = streamCodexActivity($process, $pipes, $logPath);
    if ($codexResult['exit_code'] !== 0) {
        writeStatus($statusPath, 'failed');
        if ($codexResult['stderr'] !== '') {
            appendLog($logPath, 'Codex error: ' . $codexResult['stderr']);
        }
        appendActivity($logPath, 'Failed');
        exit(1);
    }

    completeGitWorkflow($repoRoot, $taskId, $logPath);
    moveTaskToDone($repoRoot, $taskId, $logPath);
    writeStatus($statusPath, 'completed');
    appendActivity($logPath, 'Completed successfully');
} catch (Throwable $exception) {
    if (workerIsTaskId($taskId)) {
        $statusPath = workerRunFile($runsDir, $taskId, 'status', $taskSource);
        $logPath = workerRunFile($runsDir, $taskId, 'log', $taskSource);
        writeStatus($statusPath, 'failed');
        appendLog($logPath, 'Error: ' . $exception->getMessage());
        appendActivity($logPath, 'Failed');
    }
    exit(1);
}
