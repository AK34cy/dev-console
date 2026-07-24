<?php
require __DIR__ . '/deployment.php';

const DEV_CONSOLE_VERSION = '0.1';

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$devConsoleRoot = dirname(__DIR__);

function commandOutputOrNull(array $arguments, string $cwd): ?string
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $pipes = [];
    $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? trim($stdout) : null;
}

function processUptime(): string
{
    $stat = @file_get_contents('/proc/self/stat');
    if ($stat !== false && preg_match('/\)\s+\S+\s+(?:\S+\s+){19}(\d+)/', $stat, $matches) === 1) {
        $bootTime = null;
        foreach (file('/proc/stat', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'btime ')) {
                $bootTime = (int)substr($line, 6);
                break;
            }
        }

        $ticksPerSecond = (int)trim((string)shell_exec('getconf CLK_TCK 2>/dev/null'));
        if ($bootTime !== null && $ticksPerSecond > 0) {
            $startedAt = $bootTime + ((int)$matches[1] / $ticksPerSecond);
            $seconds = max(0, time() - (int)$startedAt);
            return formatDuration($seconds);
        }
    }

    return 'unknown';
}

function formatDuration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0 || !empty($parts)) $parts[] = $hours . 'h';
    if ($minutes > 0 || !empty($parts)) $parts[] = $minutes . 'm';
    $parts[] = $seconds . 's';

    return implode(' ', $parts);
}

function sendHealthResponse(string $projectRoot): void
{
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'version' => DEV_CONSOLE_VERSION,
        'php_version' => PHP_VERSION,
        'timestamp' => date('c'),
        'uptime' => processUptime(),
        'git_commit' => commandOutputOrNull(['git', '-C', $projectRoot, 'rev-parse', 'HEAD'], $projectRoot),
    ], JSON_UNESCAPED_SLASHES);
}

if ($requestMethod === 'GET' && $requestPath === '/health') {
    sendHealthResponse($devConsoleRoot);
    exit;
}

$sessionDirectory = DEPLOY_STATE_DIR . '/sessions';
if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0700, true);
session_save_path($sessionDirectory);
session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$consoleToken = (string)getenv('IOVON_DEV_CONSOLE_TOKEN');
if ($requestMethod === 'POST' && isset($_POST['console_token']) && $consoleToken !== '' && hash_equals($consoleToken, (string)$_POST['console_token'])) {
    session_regenerate_id(true);
    $_SESSION['dev_console_authenticated'] = true;
    header('Location: /');
    exit;
}
if (PHP_SAPI !== 'cli' && empty($_SESSION['dev_console_authenticated'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IOVON Dev Console</title></head><body>';
    echo '<main><h1>IOVON Dev Console</h1><p>Enter the persistent console token to continue.</p>';
    echo '<form method="post"><label for="console_token">Console token</label> <input id="console_token" name="console_token" type="password" required autofocus autocomplete="current-password"> <button type="submit">Authenticate</button></form></main></body></html>';
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['csrf_token'];

$repoRoot = dirname(__DIR__, 2);
$todoDir = $repoRoot . '/TASKS/TODO';
$doneDir = $repoRoot . '/TASKS/DONE';
$attachmentsRoot = $repoRoot . '/TASKS/ATTACHMENTS';
$runsDir = __DIR__ . '/runs';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function relativePath(string $repoRoot, string $path): string
{
    return ltrim(str_replace($repoRoot, '', $path), '/');
}

function taskNumber(int $number): string
{
    return sprintf('TASK-%03d', $number);
}

function taskExists(int $number, string $todoDir, string $doneDir): bool
{
    $taskFile = taskNumber($number) . '.md';

    return is_file($todoDir . '/' . $taskFile) || is_file($doneDir . '/' . $taskFile);
}

function shortSha(string $sha): string
{
    return preg_match('/^[0-9a-f]{7,40}$/i', $sha) ? substr($sha, 0, 7) : $sha;
}

function taskMarkdownMetadata(string $body): array
{
    $metadata = ['title' => '', 'milestone' => '', 'tag' => '', 'notes' => '', 'commit' => ''];
    if (preg_match('/^##\s+Title\s*$\R+\s*(.+)$/mi', $body, $matches)) {
        $metadata['title'] = trim($matches[1]);
    } elseif (preg_match('/^#\s+(?:TASK-\d{3}\s*[-:]\s*)?(.+)$/mi', $body, $matches)) {
        $metadata['title'] = trim($matches[1]);
    }

    $metadataBlock = preg_split('/^\s*---\s*$/m', $body, 2)[0] ?? '';
    foreach (['milestone', 'tag', 'notes', 'commit'] as $field) {
        if (preg_match('/^' . preg_quote(ucfirst($field), '/') . ':\s*(.+)$/mi', $metadataBlock, $matches)) {
            $metadata[$field] = trim($matches[1]);
        }
    }

    return $metadata;
}

function taskGitMetadata(string $repoRoot): array
{
    $cachePath = DEPLOY_STATE_DIR . '/task-git-metadata.json';
    if (is_file($cachePath) && time() - (filemtime($cachePath) ?: 0) < 60) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached)) return $cached;
    }

    $metadata = ['commits' => [], 'tags' => []];
    $history = deploymentCommand(['git', '-C', $repoRoot, 'log', '--format=@@%H%x09%s', '--name-only', '--', 'TASKS/TODO', 'TASKS/DONE']);
    if ($history['exit_code'] === 0) {
        $currentCommit = '';
        foreach (preg_split('/\R/', $history['stdout']) ?: [] as $line) {
            if (str_starts_with($line, '@@')) {
                [$currentCommit] = explode("\t", substr($line, 2), 2);
            } elseif ($currentCommit !== '' && preg_match('#^TASKS/(?:TODO|DONE)/(TASK-\d{3})\.md$#', $line, $matches)) {
                $metadata['commits'][$matches[1]] ??= $currentCommit;
            }
        }
    }

    $tags = deploymentCommand(['git', '-C', $repoRoot, 'for-each-ref', '--format=%(refname:short)%09%(objectname)', 'refs/tags']);
    if ($tags['exit_code'] === 0) {
        foreach (preg_split('/\R/', trim($tags['stdout'])) ?: [] as $line) {
            if ($line === '') continue;
            [$tag, $commit] = array_pad(explode("\t", $line, 2), 2, '');
            $tagHistory = deploymentCommand(['git', '-C', $repoRoot, 'log', '-30', '--format=%s', $commit]);
            if ($tagHistory['exit_code'] !== 0) continue;
            foreach (preg_split('/\R/', $tagHistory['stdout']) ?: [] as $subject) {
                if (preg_match('/\bComplete TASK-(\d{3})\b/i', $subject, $matches)) {
                    $metadata['tags']['TASK-' . $matches[1]][] = $tag;
                    break;
                }
            }
        }
    }

    if (!is_dir(DEPLOY_STATE_DIR)) @mkdir(DEPLOY_STATE_DIR, 0750, true);
    @file_put_contents($cachePath, json_encode($metadata, JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $metadata;
}

function taskFileEntries(string $repoRoot, array $directories): array
{
    $entries = [];
    $gitMetadata = taskGitMetadata($repoRoot);

    foreach ($directories as $status => $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if (!preg_match('/^TASK-(\d{3})\.md$/', $entry, $matches)) {
                continue;
            }

            $path = $directory . '/' . $entry;
            $relativePath = relativePath($repoRoot, $path);
            $body = (string)file_get_contents($path);
            $markdownMetadata = taskMarkdownMetadata($body);
            $taskId = 'TASK-' . $matches[1];
            $commit = preg_match('/^[0-9a-f]{7,40}$/i', $markdownMetadata['commit'])
                ? $markdownMetadata['commit']
                : (string)($gitMetadata['commits'][$taskId] ?? '');
            $tag = $markdownMetadata['tag'] !== '' ? $markdownMetadata['tag'] : (string)($gitMetadata['tags'][$taskId][0] ?? '');
            $entries[] = [
                'number' => (int)$matches[1],
                'filename' => $entry,
                'task_id' => $taskId,
                'status' => $status,
                'path' => $path,
                'relative_path' => $relativePath,
                'modified' => filemtime($path) ?: 0,
                'title' => $markdownMetadata['title'],
                'milestone' => $markdownMetadata['milestone'],
                'tag' => $tag,
                'notes' => $markdownMetadata['notes'],
                'commit' => $commit,
            ];
        }
    }

    usort($entries, function (array $left, array $right): int {
        return $right['number'] <=> $left['number'] ?: $right['modified'] <=> $left['modified'];
    });

    return $entries;
}

function existingTaskNumbers(array $directories): array
{
    $numbers = [];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if (preg_match('/^TASK-(\d{3})\.md$/', $entry, $matches)) {
                $numbers[] = (int)$matches[1];
            }
        }
    }

    return $numbers;
}

function nextTaskNumber(string $todoDir, string $doneDir): int
{
    $numbers = existingTaskNumbers([$todoDir, $doneDir]);
    $next = empty($numbers) ? 1 : max($numbers) + 1;

    while (taskExists($next, $todoDir, $doneDir)) {
        $next++;
    }

    return $next;
}

function findTaskForView(string $repoRoot, string $todoDir, string $doneDir, string $filename): ?array
{
    if (!preg_match('/^TASK-\d{3}\.md$/', $filename)) {
        return null;
    }

    foreach (['TODO' => $todoDir, 'DONE' => $doneDir] as $status => $directory) {
        $path = $directory . '/' . $filename;
        if (is_file($path)) {
            return [
                'filename' => $filename,
                'status' => $status,
                'path' => $path,
                'relative_path' => relativePath($repoRoot, $path),
                'body' => file_get_contents($path) ?: '',
            ];
        }
    }

    return null;
}

function sanitizeUploadName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F]+/', '', $name) ?: '';
    $name = trim($name);

    return $name === '' || $name === '.' || $name === '..' ? 'attachment' : $name;
}

function uniqueUploadPath(string $directory, string $filename): string
{
    $path = $directory . '/' . $filename;

    if (!file_exists($path)) {
        return $path;
    }

    $extension = pathinfo($filename, PATHINFO_EXTENSION);
    $base = $extension === '' ? $filename : substr($filename, 0, -strlen($extension) - 1);

    for ($index = 2; $index < 1000; $index++) {
        $candidate = $directory . '/' . $base . '-' . $index . ($extension === '' ? '' : '.' . $extension);
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to create a unique attachment filename.');
}

function attachmentFilesForTask(string $repoRoot, string $taskId): array
{
    $directory = $repoRoot . '/TASKS/ATTACHMENTS/' . $taskId;

    if (!is_dir($directory)) {
        return [];
    }

    $files = array_values(array_filter(scandir($directory) ?: [], function (string $entry) use ($directory): bool {
        return $entry !== '.' && $entry !== '..' && is_file($directory . '/' . $entry);
    }));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files;
}

function uploadedAttachments(): array
{
    $fileGroup = $_FILES['attachments'] ?? $_FILES['attachment'] ?? null;
    if (!is_array($fileGroup) || !isset($fileGroup['error'])) {
        return [];
    }

    $names = is_array($fileGroup['name']) ? $fileGroup['name'] : [$fileGroup['name']];
    $tmpNames = is_array($fileGroup['tmp_name']) ? $fileGroup['tmp_name'] : [$fileGroup['tmp_name']];
    $errors = is_array($fileGroup['error']) ? $fileGroup['error'] : [$fileGroup['error']];
    $sizes = is_array($fileGroup['size']) ? $fileGroup['size'] : [$fileGroup['size']];
    $uploads = [];

    foreach ($errors as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $uploads[] = [
            'name' => (string)($names[$index] ?? ''),
            'tmp_name' => (string)($tmpNames[$index] ?? ''),
            'error' => (int)$error,
            'size' => (int)($sizes[$index] ?? 0),
        ];
    }

    return $uploads;
}

function attachmentPromptText(string $repoRoot, string $taskId): string
{
    $files = attachmentFilesForTask($repoRoot, $taskId);
    if (empty($files)) {
        return '';
    }

    return "The following attachments are available in TASKS/ATTACHMENTS/{$taskId}/:\n\n- " . implode("\n- ", $files) . "\n\nUse them where appropriate.";
}


function isTaskId(string $taskId): bool
{
    return preg_match('/^TASK-\d{3}$/', $taskId) === 1;
}

function taskFileForId(string $repoRoot, string $taskId): ?string
{
    if (!isTaskId($taskId)) {
        return null;
    }

    foreach (['TASKS/TODO', 'TASKS/DONE'] as $directory) {
        $path = $repoRoot . '/' . $directory . '/' . $taskId . '.md';
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function todoTaskFileForId(string $repoRoot, string $taskId): ?string
{
    if (!isTaskId($taskId)) {
        return null;
    }

    $path = $repoRoot . '/TASKS/TODO/' . $taskId . '.md';

    return is_file($path) ? $path : null;
}

function taskHasAttachment(string $repoRoot, string $taskId): bool
{
    return !empty(attachmentFilesForTask($repoRoot, $taskId));
}

function codexPromptForTask(string $repoRoot, string $taskId): string
{
    if (todoTaskFileForId($repoRoot, $taskId) === null) {
        throw new RuntimeException('Task file is not in TODO.');
    }

    $prompt = "Execute TASKS/TODO/{$taskId}.md.

Follow AGENTS.md.

Work in repository:
/var/www/iovon-ai-dev";

    $attachmentPrompt = attachmentPromptText($repoRoot, $taskId);
    if ($attachmentPrompt !== '') {
        $prompt .= "\n\n" . $attachmentPrompt;
    }

    return $prompt;
}

function runFile(string $runsDir, string $taskId, string $extension): string
{
    if (!isTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }

    return $runsDir . '/' . $taskId . '.' . $extension;
}

function ensureRunsDir(string $runsDir): void
{
    if (!is_dir($runsDir) && !mkdir($runsDir, 0755, true)) {
        throw new RuntimeException('Unable to create runs directory.');
    }
}

function statusLabel(string $status): string
{
    return match ($status) {
        'queued' => 'Queued',
        'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        default => 'Not started',
    };
}

function codexRunStatus(string $runsDir, string $taskId): string
{
    $statusPath = runFile($runsDir, $taskId, 'status');

    if (!is_file($statusPath)) {
        return 'not_started';
    }

    $status = trim((string)file_get_contents($statusPath));

    return in_array($status, ['queued', 'running', 'completed', 'failed'], true) ? $status : 'failed';
}

function startCodexRun(string $repoRoot, string $runsDir, string $taskId): void
{
    if (!isTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }

    if (todoTaskFileForId($repoRoot, $taskId) === null) {
        throw new RuntimeException('Task file is not in TODO.');
    }

    $status = codexRunStatus($runsDir, $taskId);
    if ($status === 'running' || $status === 'queued') {
        return;
    }

    ensureRunsDir($runsDir);
    $promptPath = runFile($runsDir, $taskId, 'prompt');
    $statusPath = runFile($runsDir, $taskId, 'status');
    $logPath = runFile($runsDir, $taskId, 'log');
    file_put_contents($promptPath, codexPromptForTask($repoRoot, $taskId));
    file_put_contents($statusPath, 'queued');
    file_put_contents($logPath, '[' . date('c') . "] Queued Codex run for {$taskId}.\n");

    $worker = __DIR__ . '/run-codex.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($taskId) . ' >/dev/null 2>&1 &';
    exec($command);
}

function sendJson(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function runCommand(array $arguments, string $cwd): array
{
    $command = implode(' ', array_map('escapeshellarg', $arguments));
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['GIT_AUTHOR_NAME'] = 'IOVON Dev Console';
    $environment['GIT_AUTHOR_EMAIL'] = 'iovon@iovon.com';
    $environment['GIT_COMMITTER_NAME'] = 'IOVON Dev Console';
    $environment['GIT_COMMITTER_EMAIL'] = 'iovon@iovon.com';
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd, $environment);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to run command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'command' => implode(' ', $arguments),
        'exit_code' => $exitCode,
        'output' => trim($stdout . ($stderr === '' ? '' : "\n" . $stderr)),
    ];
}

function extractCommitHash(string $output): string
{
    if (preg_match('/\[[^\s]+\s+([0-9a-f]{7,40})\]/', $output, $matches)) {
        return $matches[1];
    }

    return '';
}

function renderCommandResult(array $result): void
{
    echo '<section class="result-block">';
    echo '<h2>' . h($result['command']) . '</h2>';
    echo '<p>Exit code: ' . h((string)$result['exit_code']) . '</p>';
    echo '<pre>' . h($result['output'] === '' ? '(no output)' : $result['output']) . '</pre>';
    echo '</section>';
}

function renderGitOutput(array $results): void
{
    echo '<details class="result-block git-output">';
    echo '<summary>Git output</summary>';

    foreach ($results as $result) {
        echo '<div class="command-output">';
        echo '<h3>' . h($result['command']) . '</h3>';
        echo '<p>Exit code: ' . h((string)$result['exit_code']) . '</p>';
        echo '<pre>' . h($result['output'] === '' ? '(no output)' : $result['output']) . '</pre>';
        echo '</div>';
    }

    echo '</details>';
}

$nextNumber = nextTaskNumber($todoDir, $doneDir);
$latestTasks = taskFileEntries($repoRoot, ['TODO' => $todoDir, 'DONE' => $doneDir]);
$viewTask = findTaskForView($repoRoot, $todoDir, $doneDir, (string)($_GET['task'] ?? ''));
$createdTaskId = '';
$createdTaskPath = '';
$attachmentPaths = [];
$commitHash = '';
$prompt = '';
$error = '';
$results = [];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'codex-status') {
    $taskId = (string)($_GET['task'] ?? '');
    try {
        if (!isTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }
        $status = codexRunStatus($runsDir, $taskId);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'codex-log') {
    $taskId = (string)($_GET['task'] ?? '');
    if (!isTaskId($taskId)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid task id.';
        exit;
    }

    $logPath = runFile($runsDir, $taskId, 'log');
    header('Content-Type: text/plain; charset=UTF-8');
    echo is_file($logPath) ? (string)file_get_contents($logPath) : 'No log file yet.';
    exit;
}

if ($action === 'deployment-preview' || $action === 'deployment-start') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); sendJson(['ok' => false, 'error' => 'Invalid deployment request.']); exit;
    }
    try {
        $environment = (string)($_POST['environment'] ?? '');
        $configuration = deploymentConfiguration($environment);
        $errors = deploymentValidation($environment);
        if ($errors) {
            $state = newDeploymentState($environment, 'failed', ['added' => 0, 'updated' => 0, 'deleted' => 0, 'files' => []], 'local-console');
            $state['finish_time'] = date('c'); $state['error'] = implode(' ', $errors);
            appendDeploymentLog($state, 'Validation started.');
            appendDeploymentLog($state, 'Validation result: failed. ' . $state['error']);
            appendDeploymentLog($state, 'Final status: Failed.'); writeDeploymentState($state);
            throw new RuntimeException($state['error']);
        }
        $dryRun = deploymentCommand(deploymentRsyncArguments($environment, true));
        if ($dryRun['exit_code'] !== 0) throw new RuntimeException('rsync dry-run failed.');
        $summary = deploymentChangeSummary($dryRun['stdout']);
        if ($action === 'deployment-preview') {
            sendJson(['ok' => true, 'overview' => deploymentOverview($environment), 'summary' => $summary]); exit;
        }
        $latestDeployment = readDeploymentState($environment);
        if ($latestDeployment && in_array((string)($latestDeployment['status'] ?? ''), ['pending', 'running'], true)) {
            throw new RuntimeException('Another deployment is already pending or running.');
        }
        if ($environment === 'production' && (string)($_POST['confirmation'] ?? '') !== 'DEPLOY') throw new RuntimeException('Type DEPLOY exactly to confirm.');
        $confirmed = json_decode((string)($_POST['summary'] ?? ''), true);
        if (!is_array($confirmed) || $confirmed !== $summary) throw new RuntimeException('Deployment preview changed; review it again.');
        $state = newDeploymentState($environment, 'pending', $summary, 'local-console');
        writeDeploymentState($state); appendDeploymentLog($state, 'Deployment queued.');
        $worker = __DIR__ . '/run-deployment.php';
        exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($environment) . ' ' . escapeshellarg($state['id']) . ' >/dev/null 2>&1 &');
        sendJson(['ok' => true, 'deployment' => $state]); exit;
    } catch (Throwable $exception) {
        http_response_code(400); sendJson(['ok' => false, 'error' => $exception->getMessage()]); exit;
    }
}

if ($action === 'deployment-status') {
    try { $environment = (string)($_GET['environment'] ?? ''); deploymentConfiguration($environment); }
    catch (Throwable $exception) { http_response_code(400); sendJson(['ok' => false, 'error' => $exception->getMessage()]); exit; }
    $state = readDeploymentState($environment, isset($_GET['id']) ? (string)$_GET['id'] : null);
    $log = $state && is_file((string)$state['log_path']) ? (string)file_get_contents((string)$state['log_path']) : '';
    sendJson(['ok' => true, 'deployment' => $state, 'log' => $log]); exit;
}

if ($action === 'environment-status') {
    sendJson(['ok' => true, 'dashboard' => operationalDashboard()]);
    exit;
}

if ($action === 'run-codex' && $requestMethod === 'POST') {
    $taskId = (string)($_POST['task'] ?? '');
    try {
        startCodexRun($repoRoot, $runsDir, $taskId);
        $status = codexRunStatus($runsDir, $taskId);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($requestMethod === 'POST') {
    try {
        $body = trim((string)($_POST['task_body'] ?? ''));

        if ($body === '') {
            throw new RuntimeException('Task markdown body is required.');
        }

        if (!is_dir($todoDir) && !mkdir($todoDir, 0755, true)) {
            throw new RuntimeException('Unable to create TASKS/TODO.');
        }

        $uploads = uploadedAttachments();
        if (count($uploads) > 5) {
            throw new RuntimeException('A maximum of five attachments can be uploaded.');
        }

        foreach ($uploads as $upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Attachment upload failed with code ' . (string)$upload['error'] . '.');
            }
        }

        $number = nextTaskNumber($todoDir, $doneDir);
        $taskId = taskNumber($number);
        $createdTaskId = $taskId;
        $taskPath = $todoDir . '/' . $taskId . '.md';

        if (taskExists($number, $todoDir, $doneDir)) {
            throw new RuntimeException($taskId . ' already exists.');
        }

        $handle = @fopen($taskPath, 'x');
        if (!$handle) {
            throw new RuntimeException('Unable to create task file without overwriting.');
        }
        fwrite($handle, rtrim($body) . "\n");
        fclose($handle);

        $pathsToAdd = [relativePath($repoRoot, $taskPath)];
        $createdTaskPath = $pathsToAdd[0];

        if (!empty($uploads)) {
            $taskAttachmentDir = $attachmentsRoot . '/' . $taskId;
            if (!is_dir($taskAttachmentDir) && !mkdir($taskAttachmentDir, 0755, true)) {
                throw new RuntimeException('Unable to create task attachment directory.');
            }

            foreach ($uploads as $upload) {
                $safeFilename = sanitizeUploadName($upload['name']);
                $uploadPath = uniqueUploadPath($taskAttachmentDir, $safeFilename);

                if (!move_uploaded_file($upload['tmp_name'], $uploadPath)) {
                    throw new RuntimeException('Unable to save uploaded attachment.');
                }

                $attachmentPaths[] = relativePath($repoRoot, $uploadPath);
            }

            $pathsToAdd[] = relativePath($repoRoot, $taskAttachmentDir);
        }

        $results[] = runCommand(array_merge(['git', 'add'], $pathsToAdd), $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }

        $results[] = runCommand(['git', 'commit', '-m', 'Add ' . $taskId], $repoRoot);
        $commitHash = extractCommitHash(end($results)['output']);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git commit failed.');
        }

        $results[] = runCommand(['git', 'push', 'origin', 'main'], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git push failed.');
        }

        $prompt = codexPromptForTask($repoRoot, $taskId);

        $nextNumber = nextTaskNumber($todoDir, $doneDir);
        $latestTasks = array_slice(taskFileEntries($repoRoot, ['TODO' => $todoDir, 'DONE' => $doneDir]), 0, 12);
        $taskGroups = ['TODO' => [], 'DONE' => []];
        foreach ($latestTasks as $task) {
            $taskGroups[$task['status']][] = $task;
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$activeTaskId = $createdTaskId;
if ($activeTaskId === '' && $viewTask) {
    $activeTaskId = pathinfo($viewTask['filename'], PATHINFO_FILENAME);
}
$activeTaskStatus = '';
$activeTaskPath = '';
if ($createdTaskId !== '' && $error === '') {
    $activeTaskStatus = 'TODO';
    $activeTaskPath = $createdTaskPath;
} elseif ($viewTask) {
    $activeTaskStatus = $viewTask['status'];
    $activeTaskPath = $viewTask['relative_path'];
}
$activeRunStatus = $activeTaskId === '' ? 'not_started' : codexRunStatus($runsDir, $activeTaskId);
$taskGitCompleted = $activeTaskId !== '';
$editorTaskId = $viewTask ? pathinfo($viewTask['filename'], PATHINFO_FILENAME) : '';
$editorBody = ($createdTaskId === '' && $viewTask) ? $viewTask['body'] : '';
$editorHeading = $editorTaskId === '' ? 'Create New Task' : 'View Task: ' . $editorTaskId;
$previewDeploymentOverview = deploymentOverview('preview');
$productionDeploymentOverview = deploymentOverview('production');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IOVON Dev Console</title>
  <style>
    :root { --blue: #005385; --green: #147544; --light: #e3f5f9; --line: #d8eef5; --ink: #1d2a32; --muted: #536b78; }
    body { background: #f4f8fb; color: var(--ink); font-family: Arial, sans-serif; margin: 0; }
    main { margin: 24px auto; max-width: 1280px; padding: 0 20px; }
    h1 { color: var(--blue); margin: 0 0 8px; }
    h2 { color: var(--blue); margin: 0 0 12px; }
    code { background: #edf7fb; border-radius: 4px; padding: 2px 5px; }
    .dashboard-columns { align-items: start; display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr) 440px; }
    .dashboard-column { min-width: 0; }
    .panel, .result-block { background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 6px 18px rgba(0, 83, 133, 0.07); margin-top: 14px; padding: 18px; }
    .result-block h2, .command-output h3 { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 15px; }
    label { display: block; font-weight: 700; margin: 18px 0 8px; }
    textarea { background: #fcfeff; border: 1px solid #bddfeb; border-radius: 8px; box-sizing: border-box; color: #10242f; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 14px; line-height: 1.5; min-height: 390px; padding: 14px; resize: vertical; tab-size: 2; width: 100%; }
    textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    button, .button-link { align-items: center; background: var(--blue); border: 0; border-radius: 5px; color: #fff; cursor: pointer; display: inline-flex; font-size: 15px; font-weight: 700; gap: 8px; margin-top: 16px; padding: 11px 18px; text-decoration: none; }
    [hidden] { display: none !important; }
    button.secondary, .button-link.secondary { background: #e8f4f8; color: var(--blue); }
    pre { background: #f2f7fa; border-radius: 6px; overflow: auto; padding: 16px; white-space: pre-wrap; }
    .error { background: #fff4f4; border-color: #f0b8b8; color: #8a1f1f; }
    .meta, .hint { color: var(--muted); }
    .form-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; }
    .upload-zone { background: #f7fcfe; border: 2px dashed #9fcfe0; border-radius: 10px; cursor: pointer; padding: 16px; text-align: center; transition: border-color 0.2s ease, background 0.2s ease; }
    .upload-zone.dragover { background: var(--light); border-color: var(--blue); }
    .upload-zone strong { color: var(--blue); display: block; font-size: 17px; margin-bottom: 6px; }
    .upload-zone input { height: 1px; opacity: 0; position: absolute; width: 1px; }
    .selected-files { color: var(--muted); margin: 10px 0 0; }
    .selected-files ul { display: grid; gap: 6px; list-style: none; margin: 8px auto 0; max-width: 460px; padding: 0; text-align: left; }
    .selected-files li { align-items: center; background: #edf7fb; border-radius: 5px; display: flex; gap: 8px; justify-content: space-between; padding: 6px 8px; }
    .selected-files button { background: transparent; color: #8a1f1f; font-size: 12px; margin: 0; padding: 3px 6px; }
    .attachment-list { color: var(--muted); margin: 12px 0 0; }
    .attachment-list strong { color: var(--blue); display: block; margin-bottom: 6px; }
    .attachment-list ul { list-style: none; margin: 0; padding: 0; }
    .attachment-list li { margin-top: 4px; }
    .task-list-scroll { max-height: 620px; overflow-y: auto; padding-right: 6px; }
    .task-list { list-style: none; margin: 0; padding: 0; }
    .task-list > li { border-top: 1px solid var(--line); padding: 8px 0; }
    .task-list li:first-child { border-top: 0; }
    .task-row-header { align-items: center; display: flex; gap: 8px; justify-content: space-between; }
    .task-summary-label { color: var(--blue); font-weight: 700; }
    .task-title { color: var(--ink); display: block; font-size: 12px; margin-top: 3px; }
    .task-metadata { color: var(--muted); display: flex; flex-wrap: wrap; font-size: 12px; gap: 5px 12px; margin-top: 6px; }
    .task-list .button-link { font-size: 12px; margin-top: 7px; padding: 6px 9px; }
    .milestone { color: #7a5b00; }
    .badge { background: #e3f5f9; border-radius: 999px; color: var(--blue); display: inline-block; flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 4px 8px; }
    .badge.done { background: #e9f7ef; color: var(--green); }
    .workflow-steps { display: grid; gap: 10px; list-style: none; margin: 16px 0 0; padding: 0; }
    .workflow-steps li { align-items: center; border-top: 1px solid var(--line); display: flex; gap: 10px; padding-top: 10px; }
    .workflow-steps li:first-child { border-top: 0; padding-top: 0; }
    .step-state { background: #edf4f7; border-radius: 999px; color: var(--muted); flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 4px 8px; text-transform: uppercase; }
    .step-state.done { background: #e9f7ef; color: var(--green); }
    .step-state.pending { background: #f4f1e8; color: #76622d; }
    .git-output summary { color: var(--blue); cursor: pointer; font-weight: 700; }
    .command-output { border-top: 1px solid var(--line); margin-top: 16px; padding-top: 16px; }
    .command-output:first-of-type { border-top: 0; }
    .prompt-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; }
    .codex-run-panel { background: #f7fcfe; border: 1px solid var(--line); border-radius: 8px; padding: 18px; }
    .codex-run-panel strong { color: var(--blue); }
    .codex-status { font-weight: 700; }
    .codex-console { background: #101820; border-radius: 6px; color: #dce7ec; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; line-height: 1.5; max-height: 360px; min-height: 180px; overflow: auto; padding: 14px; white-space: pre-wrap; }
    .deployment-panel.production { border: 2px solid #8a1f1f; }
    .deployment-details { display: grid; gap: 10px 24px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 16px 0; }
    .deployment-details dt { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .deployment-details dd { margin: 3px 0 0; overflow-wrap: anywhere; }
    .deployment-status { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 5px 10px; text-transform: uppercase; }
    .deployment-status.pending { background: #edf0f2; color: #56636a; }
    .deployment-status.running { background: #fff2b8; color: #705900; }
    .deployment-status.success { background: #e4f6ea; color: #147544; }
    .deployment-status.failed { background: #fde8e8; color: #8a1f1f; }
    .deploy-production { background: #a51d1d; border-color: #a51d1d; font-size: 16px; }
    .deploy-production:hover { background: #801515; }
    dialog { border: 0; border-radius: 10px; box-shadow: 0 20px 70px #0006; max-width: 720px; padding: 0; width: calc(100% - 32px); }
    dialog::backdrop { background: #101820aa; }
    .modal-content { padding: 24px; }
    .modal-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; margin-top: 20px; }
    .change-list { background: #f3f6f7; max-height: 220px; overflow: auto; padding: 12px 12px 12px 30px; }
    .deployment-error { color: #8a1f1f; font-weight: 700; }
    .environment-block { padding: 14px; }
    .environment-block a { color: var(--blue); }
    .dashboard-header { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; }
    .dashboard-header h2 { font-size: 18px; }
    .dashboard-header .meta { font-size: 11px; white-space: nowrap; }
    .dashboard-grid { display: grid; gap: 8px; margin-top: 10px; }
    .summary-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-card { background: #f8fbfc; border: 1px solid var(--line); border-radius: 7px; min-width: 0; padding: 9px 10px; }
    .dashboard-card h3 { color: var(--blue); font-size: 13px; margin: 0 0 6px; }
    .dashboard-list { display: grid; gap: 4px; margin: 0; }
    .dashboard-list div { display: grid; gap: 5px; grid-template-columns: minmax(68px, .75fr) minmax(0, 1.25fr); }
    .dashboard-list dt { color: var(--muted); font-size: 11px; font-weight: 700; }
    .dashboard-list dd { font-size: 12px; margin: 0; overflow-wrap: anywhere; }
    .dashboard-list code { padding: 1px 3px; }
    .status-pill { background: #edf0f2; border-radius: 999px; color: #56636a; display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 8px; text-transform: uppercase; }
    .status-pill.healthy { background: #e4f6ea; color: var(--green); }
    .status-pill.warning { background: #fff2b8; color: #705900; }
    .status-pill.error { background: #fde8e8; color: #8a1f1f; }
    .health-row { display: flex; flex-wrap: wrap; gap: 6px 12px; }
    .health-item { align-items: center; display: inline-flex; font-size: 11px; font-weight: 700; gap: 5px; }
    .health-dot { background: #8a969c; border-radius: 50%; height: 8px; width: 8px; }
    .health-item.healthy .health-dot { background: #1b9a59; }
    .health-item.warning .health-dot { background: #d59b00; }
    .health-item.error .health-dot { background: #c83232; }
    .resource-grid { display: grid; gap: 9px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .resource-head { display: flex; font-size: 11px; font-weight: 700; justify-content: space-between; margin-bottom: 4px; }
    .progress-track { background: #dce8ed; border-radius: 999px; height: 7px; overflow: hidden; }
    .progress-bar { background: #238654; border-radius: inherit; height: 100%; }
    .progress-bar.warning { background: #d59b00; }
    .progress-bar.error { background: #c83232; }
    .resource-value { color: var(--muted); font-size: 10px; margin: 4px 0 0; }
    .compact-details summary { color: var(--blue); cursor: pointer; font-size: 13px; font-weight: 700; }
    .compact-table { border-collapse: collapse; font-size: 11px; width: 100%; }
    .compact-table th, .compact-table td { border-top: 1px solid var(--line); padding: 4px 2px; text-align: left; }
    .compact-table tr:first-child th, .compact-table tr:first-child td { border-top: 0; }
    .compact-table th { color: var(--muted); width: 45%; }
    .process-table { border-collapse: collapse; font-size: 12px; width: 100%; }
    .process-table th, .process-table td { border-top: 1px solid var(--line); padding: 6px; text-align: left; }
    .process-table th { border-top: 0; color: var(--muted); }
    .process-table td:last-child { overflow-wrap: anywhere; }
    @media (max-width: 900px) {
      .dashboard-columns { display: block; }
      main { margin-top: 18px; }
    }
    @media (max-width: 520px) { .summary-grid, .resource-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<main>
  <h1>IOVON Dev Console</h1>
  <p class="meta">Internal task creator. Run only on <code>127.0.0.1:8090</code>.</p>

  <?php if ($error !== ''): ?>
    <section class="panel error">
      <h2>Task creation failed</h2>
      <p><?= h($error) ?></p>
      <?php foreach ($results as $result): ?>
        <?php renderCommandResult($result); ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <div class="dashboard-columns">
  <div class="dashboard-column dashboard-column-left">
  <section class="panel" id="create-task">
    <h2 id="editorHeading"><?= h($editorHeading) ?></h2>
    <?php if ($editorTaskId !== ''): ?>
      <p class="meta" id="viewingTaskNote">Viewing existing task. Editing here will not update the saved task.</p>
    <?php endif; ?>
    <p id="nextTaskNumber"><strong>Next task number:</strong> <?= h(taskNumber($nextNumber)) ?></p>
    <form method="post" enctype="multipart/form-data" id="taskForm" data-created="<?= h($createdTaskPath !== '' && $error === '' ? '1' : '0') ?>">
      <label for="task_body">Task markdown body</label>
      <textarea id="task_body" name="task_body" required spellcheck="false" placeholder="# TASK-<?= h(sprintf('%03d', $nextNumber)) ?>&#10;&#10;## Title&#10;&#10;..."><?= h($editorBody) ?></textarea>
      <div class="form-actions">
        <button type="button" class="secondary" id="clearDraft">Clear draft</button>
        <span class="hint" id="draftStatus">Draft autosaves in this browser.</span>
      </div>

      <label for="attachment">Optional attachments</label>
      <label class="upload-zone" id="uploadZone" for="attachment">
        <input id="attachment" name="attachments[]" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.svg,.md,.txt,.docx,application/pdf,image/png,image/jpeg,image/svg+xml,text/markdown,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        <strong>Drop files here</strong>
        <span>or click to select up to five attachments.</span>
        <div class="selected-files" id="selectedFiles">No files selected.</div>
      </label>

      <button type="submit">Create Task</button>
    </form>
  </section>

      <section class="panel">
        <h2>Current Task Workflow</h2>
        <?php if ($activeTaskId !== '' && $error === ''): ?>
          <p class="meta">Current task: <strong><?= h($activeTaskId) ?></strong> · <?= h($activeTaskStatus) ?></p>
          <ul class="workflow-steps">
            <li><span class="step-state done">Done</span><span>Task file created<?php if ($activeTaskPath !== ''): ?>: <code><?= h($activeTaskPath) ?></code><?php endif; ?></span></li>
            <li><span class="step-state <?= h($taskGitCompleted ? 'done' : 'pending') ?>"><?= h($taskGitCompleted ? 'Done' : 'Ready') ?></span><span>Task committed and pushed<?php if ($commitHash !== ''): ?>: <code title="<?= h($commitHash) ?>"><?= h(shortSha($commitHash)) ?></code><?php endif; ?></span></li>
            <li><span class="step-state <?= h(in_array($activeRunStatus, ['completed', 'failed'], true) ? 'done' : 'pending') ?>"><?= h(statusLabel($activeRunStatus)) ?></span><span>Codex run status</span></li>
          </ul>
          <div class="prompt-actions">
            <?php if ($activeTaskStatus === 'TODO'): ?>
              <button type="button" id="runCodex" data-task="<?= h($activeTaskId) ?>">Run Codex</button>
            <?php else: ?>
              <button type="button" disabled>Run Codex</button>
            <?php endif; ?>
            <a class="button-link" href="?task=<?= h(rawurlencode($activeTaskId . '.md')) ?>" target="_blank" rel="noopener">Open TASK</a>
          </div>
          <?php if (!empty($attachmentPaths)): ?>
            <div class="attachment-list">
              <strong>Attachments</strong>
              <ul>
                <?php foreach ($attachmentPaths as $path): ?>
                  <li>✓ <code><?= h($path) ?></code></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="meta">No active task. Create a task or open a task from the list.</p>
        <?php endif; ?>
      </section>

      <section class="panel">
        <h2>Activity</h2>
        <?php if ($activeTaskId !== '' && $error === ''): ?>
          <div class="codex-run-panel" id="codexRunPanel" data-task="<?= h($activeTaskId) ?>">
            <p><strong>Run status:</strong> <span class="codex-status" id="codexStatus"><?= h(statusLabel($activeRunStatus)) ?></span></p>
            <pre class="codex-console" id="codexConsole">Loading activity...</pre>
            <div class="prompt-actions">
              <button type="button" class="secondary" id="refreshCodexLog">Refresh</button>
              <button type="button" class="secondary" id="copyCodexLog">Copy to Clipboard</button>
              <span class="hint" id="copyCodexMessage" aria-live="polite"></span>
            </div>
          </div>
        <?php else: ?>
          <div class="codex-run-panel">
            <p><strong>Run status:</strong> <span class="codex-status">Not started</span></p>
            <pre class="codex-console">Activity will appear here after a task is created and run.</pre>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel deployment-panel" id="previewDeployment">
    <h2>Preview Deployment</h2>
    <dl class="deployment-details">
      <div><dt>Source path</dt><dd><code><?= h($previewDeploymentOverview['source']) ?></code></dd></div>
      <div><dt>Target path</dt><dd><code><?= h($previewDeploymentOverview['target']) ?></code></dd></div>
      <div><dt>Preview URL</dt><dd><a href="<?= h($previewDeploymentOverview['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($previewDeploymentOverview['url']) ?></a></dd></div>
      <div><dt>Current Git branch</dt><dd><?= h($previewDeploymentOverview['branch']) ?></dd></div>
      <div><dt>Development commit</dt><dd><code title="<?= h($previewDeploymentOverview['commit']) ?>"><?= h(shortSha($previewDeploymentOverview['commit'])) ?></code></dd></div>
      <div><dt>Commit message</dt><dd><?= h($previewDeploymentOverview['message']) ?></dd></div>
      <div><dt>Preview version</dt><dd><code title="<?= h($previewDeploymentOverview['deployed_commit']) ?>"><?= h($previewDeploymentOverview['deployed_commit'] === '' ? 'Not detected' : shortSha($previewDeploymentOverview['deployed_commit'])) ?></code></dd></div>
      <div><dt>Last Preview deployment</dt><dd id="previewLastDeploymentTime"><?= h((string)($previewDeploymentOverview['latest']['finish_time'] ?? $previewDeploymentOverview['latest']['start_time'] ?? 'Never')) ?></dd></div>
      <div><dt>Preview status</dt><dd><span id="previewDeploymentStatus" class="deployment-status <?= h((string)($previewDeploymentOverview['latest']['status'] ?? 'pending')) ?>"><?= h(isset($previewDeploymentOverview['latest']['status']) ? ucfirst((string)$previewDeploymentOverview['latest']['status']) : 'Not started') ?></span></dd></div>
    </dl>
    <button type="button" id="deployPreview">Deploy to Preview</button>
    <p class="deployment-error" id="previewDeploymentError" aria-live="assertive"></p>
    <pre class="codex-console" id="previewDeploymentLog"><?= h(isset($previewDeploymentOverview['latest']['log_path']) && is_file($previewDeploymentOverview['latest']['log_path']) ? (string)file_get_contents($previewDeploymentOverview['latest']['log_path']) : 'No deployment log yet.') ?></pre>
  </section>
  </div>

  <div class="dashboard-column dashboard-column-right">
    <section class="panel environment-block" id="environment">
      <div class="dashboard-header">
        <h2>Environment</h2>
        <span class="meta" id="dashboardUpdated">Loading…</span>
      </div>
      <div class="dashboard-grid" id="environmentDashboard" aria-live="polite"></div>
    </section>

    <section class="panel">
      <h2>Tasks</h2>
      <?php if (empty($latestTasks)): ?>
        <p class="meta">No task files found yet.</p>
      <?php else: ?>
        <div class="task-list-scroll">
          <ul class="task-list">
            <?php foreach ($latestTasks as $task): ?>
              <li>
                <div class="task-row-header">
                  <span class="task-summary-label"><?= h($task['task_id']) ?></span>
                  <span class="badge <?= h(strtolower($task['status'])) ?>"><?= h($task['status']) ?></span>
                </div>
                <?php if ($task['title'] !== ''): ?><span class="task-title"><?= h($task['title']) ?></span><?php endif; ?>
                <div class="task-metadata">
                  <?php if ($task['commit'] !== ''): ?><span>Commit: <code title="<?= h($task['commit']) ?>"><?= h(shortSha($task['commit'])) ?></code></span><?php endif; ?>
                  <?php if ($task['milestone'] !== ''): ?><span class="milestone">⭐ <?= h($task['milestone']) ?></span><?php endif; ?>
                  <?php if ($task['tag'] !== ''): ?><span>Tag: <?= h($task['tag']) ?></span><?php endif; ?>
                </div>
                <a class="button-link secondary" href="?task=<?= h(rawurlencode($task['filename'])) ?>">Use in Workflow</a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>

  <section class="panel deployment-panel production" id="productionDeployment">
    <h2>Production Deployment</h2>
    <dl class="deployment-details">
      <div><dt>Production target</dt><dd><code><?= h($productionDeploymentOverview['target']) ?></code></dd></div>
      <div><dt>Production URL</dt><dd><a href="<?= h($productionDeploymentOverview['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($productionDeploymentOverview['url']) ?></a></dd></div>
      <div><dt>Production version</dt><dd><code id="productionCommit" title="<?= h($productionDeploymentOverview['deployed_commit']) ?>"><?= h($productionDeploymentOverview['deployed_commit'] === '' ? 'Not detected' : shortSha($productionDeploymentOverview['deployed_commit'])) ?></code></dd></div>
      <div><dt>Last Production deployment</dt><dd id="productionLastDeploymentTime"><?= h((string)($productionDeploymentOverview['latest']['finish_time'] ?? $productionDeploymentOverview['latest']['start_time'] ?? 'Never')) ?></dd></div>
      <div><dt>Production status</dt><dd><span id="productionDeploymentStatus" class="deployment-status <?= h((string)($productionDeploymentOverview['latest']['status'] ?? 'pending')) ?>"><?= h(isset($productionDeploymentOverview['latest']['status']) ? ucfirst((string)$productionDeploymentOverview['latest']['status']) : 'Not started') ?></span></dd></div>
    </dl>
    <button type="button" class="deploy-production" id="deployProduction">Deploy to Production</button>
    <p class="deployment-error" id="productionDeploymentError" aria-live="assertive"></p>
    <pre class="codex-console" id="productionDeploymentLog"><?= h(isset($productionDeploymentOverview['latest']['log_path']) && is_file($productionDeploymentOverview['latest']['log_path']) ? (string)file_get_contents($productionDeploymentOverview['latest']['log_path']) : 'No deployment log yet.') ?></pre>
  </section>
  </div>
  </div>

  <dialog id="previewDeploymentDialog">
    <div class="modal-content">
      <h2>Confirm Preview Deployment</h2>
      <div id="previewDeploymentSummary"></div>
      <p class="deployment-error" id="previewModalDeploymentError" aria-live="assertive"></p>
      <div class="modal-actions">
        <button type="button" class="secondary" id="cancelPreviewDeployment">Cancel</button>
        <button type="button" id="confirmPreviewDeployment">Deploy to Preview</button>
      </div>
    </div>
  </dialog>

  <dialog id="deploymentDialog">
    <div class="modal-content">
      <h2>Confirm Production Deployment</h2>
      <div id="deploymentPreview"></div>
      <div id="deploymentFinalStep" hidden>
        <label for="deploymentConfirmation">Type <code>DEPLOY</code> exactly to continue</label>
        <input id="deploymentConfirmation" type="text" autocomplete="off" spellcheck="false">
      </div>
      <p class="deployment-error" id="modalDeploymentError" aria-live="assertive"></p>
      <div class="modal-actions">
        <button type="button" class="secondary" id="cancelDeployment">Cancel</button>
        <button type="button" id="continueDeployment">Continue</button>
        <button type="button" class="deploy-production" id="confirmDeployment" disabled hidden>Confirm Deployment</button>
      </div>
    </div>
  </dialog>

  <?php if (!$viewTask && isset($_GET['task']) && (string)$_GET['task'] !== ''): ?>
    <section class="panel error">
      <h2>Task not found</h2>
      <p>The requested task file could not be opened.</p>
    </section>
  <?php endif; ?>

</main>
<script>
(() => {
  const textarea = document.getElementById('task_body');
  const clearDraft = document.getElementById('clearDraft');
  const draftStatus = document.getElementById('draftStatus');
  const editorHeading = document.getElementById('editorHeading');
  const viewingTaskNote = document.getElementById('viewingTaskNote');
  const form = document.getElementById('taskForm');
  const uploadZone = document.getElementById('uploadZone');
  const fileInput = document.getElementById('attachment');
  const selectedFiles = document.getElementById('selectedFiles');
  const runCodex = document.getElementById('runCodex');
  const codexRunPanel = document.getElementById('codexRunPanel');
  const codexStatus = document.getElementById('codexStatus');
  const codexConsole = document.getElementById('codexConsole');
  const refreshCodexLog = document.getElementById('refreshCodexLog');
  const copyCodexLog = document.getElementById('copyCodexLog');
  const copyCodexMessage = document.getElementById('copyCodexMessage');
  const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const draftKey = 'iovon.devConsole.taskDraft';
  const environmentDashboard = document.getElementById('environmentDashboard');
  const dashboardUpdated = document.getElementById('dashboardUpdated');
  const activeTask = <?= json_encode($activeTaskId === '' ? 'None' : $activeTaskId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const scrollKey = 'iovon.devConsole.scrollPosition';
  let scrollSaveFrame = null;
  const saveScrollPosition = () => {
    sessionStorage.setItem(scrollKey, String(window.scrollY));
  };
  const restoreScrollPosition = () => {
    const savedPosition = Number(sessionStorage.getItem(scrollKey));
    if (Number.isFinite(savedPosition) && savedPosition >= 0) {
      requestAnimationFrame(() => window.scrollTo(0, savedPosition));
    }
  };
  restoreScrollPosition();
  window.addEventListener('scroll', () => {
    if (scrollSaveFrame !== null) cancelAnimationFrame(scrollSaveFrame);
    scrollSaveFrame = requestAnimationFrame(() => {
      saveScrollPosition();
      scrollSaveFrame = null;
    });
  }, { passive: true });
  window.addEventListener('pagehide', saveScrollPosition);
  const dashboardEscape = (value) => String(value).replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const formatBytes = (bytes) => {
    let value = Number(bytes) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
  };
  const statusClass = (status) => {
    const value = String(status).toLowerCase();
    if (['running', 'success', 'healthy'].includes(value)) return 'healthy';
    if (['pending', 'not_started'].includes(value)) return 'warning';
    return 'error';
  };
  const statusLabel = (status) => String(status).replaceAll('_', ' ');
  const dashboardCard = (title, rows, extraClass = '') => `<section class="dashboard-card ${extraClass}"><h3>${dashboardEscape(title)}</h3><dl class="dashboard-list">${rows.map(([label, value]) => `<div><dt>${dashboardEscape(label)}</dt><dd>${value}</dd></div>`).join('')}</dl></section>`;
  const dashboardLink = (url) => `<a href="${dashboardEscape(url)}" target="_blank" rel="noopener noreferrer">${dashboardEscape(url)}</a>`;
  const dashboardStatus = (status) => `<span class="status-pill ${statusClass(status)}">${dashboardEscape(statusLabel(status))}</span>`;
  const healthItem = (label, state) => `<span class="health-item ${state}"><span class="health-dot" aria-hidden="true"></span>${dashboardEscape(label)}</span>`;
  const resourceBar = (label, percentage, numericValue) => {
    const value = Math.max(0, Math.min(100, Number(percentage) || 0));
    const state = value >= 90 ? 'error' : (value >= 80 ? 'warning' : 'healthy');
    return `<div><div class="resource-head"><span>${dashboardEscape(label)}</span><span>${dashboardEscape(`${value}%`)}</span></div><div class="progress-track" role="progressbar" aria-label="${dashboardEscape(label)} usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${value}"><div class="progress-bar ${state}" style="width:${value}%"></div></div><p class="resource-value">${dashboardEscape(numericValue)}</p></div>`;
  };
  let environmentRefreshInProgress = false;
  const refreshEnvironmentDashboard = async () => {
    if (!environmentDashboard || environmentRefreshInProgress) return;
    environmentRefreshInProgress = true;
    try {
      const topProcessesWasOpen = environmentDashboard.querySelector('#topProcesses')?.open ?? false;
      const response = await fetch('?action=environment-status', { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to load environment status.');
      const data = payload.dashboard;
    const development = data.environment.development;
    const preview = data.environment.preview;
    const production = data.environment.production;
    const memory = data.server.memory;
    const disk = data.server.disk;
    const softwareNames = ['PHP', 'Composer', 'Node.js', 'npm', 'Git', 'Codex CLI'];
    const softwareRows = softwareNames.map((name) => `<tr><th>${dashboardEscape(name.replace('Node.js', 'Node').replace('Codex CLI', 'Codex'))}</th><td>${dashboardEscape(data.software[name])}</td></tr>`).join('');
    const processes = data.processes.length ? data.processes.map((process) => `<tr><td>${process.pid}</td><td>${dashboardEscape(process.user)}</td><td>${process.cpu.toFixed(1)}</td><td>${process.memory.toFixed(1)}</td><td>${dashboardEscape(process.command)}</td></tr>`).join('') : '<tr><td colspan="5">No process data available.</td></tr>';
    const previewHealth = statusClass(preview.status);
    const productionHealth = statusClass(production.status);
    const consoleHealth = statusClass(data.environment.console.status);
    const gitHealth = data.software.Git === 'Not installed' ? 'error' : 'healthy';
    const webHealth = previewHealth === 'error' && productionHealth === 'error' ? 'error' : (previewHealth === 'warning' && productionHealth === 'warning' ? 'warning' : 'healthy');
      environmentDashboard.innerHTML =
      `<section class="dashboard-card"><div class="health-row">${healthItem('Preview', previewHealth)}${healthItem('Production', productionHealth)}${healthItem('Dev Console', consoleHealth)}${healthItem('Git', gitHealth)}${healthItem('Apache', webHealth)}${healthItem('Tailscale', consoleHealth)}</div></section>` +
      `<div class="summary-grid">` +
      dashboardCard('Development', [['Branch', dashboardEscape(development.branch)], ['Commit', `<code>${dashboardEscape(development.commit)}</code>`], ['Current task', dashboardEscape(activeTask)]]) +
      dashboardCard('Preview', [['Status', dashboardStatus(preview.status)], ['URL', dashboardLink(preview.url)]]) +
      dashboardCard('Production', [['Status', dashboardStatus(production.status)], ['URL', dashboardLink(production.url)]]) +
      dashboardCard('Dev Console', [['Status', dashboardStatus(data.environment.console.status)], ['URL', dashboardLink(data.environment.console.url)]]) +
      `</div>` +
      `<section class="dashboard-card"><h3>Resources</h3><div class="resource-grid">${resourceBar('CPU', data.server.load_percentage, `Load ${data.server.load.join(' / ') || 'not detected'}`)}${resourceBar('Memory', memory.percentage, `${formatBytes(memory.used)} / ${formatBytes(memory.total)}`)}${resourceBar('Disk', disk.percentage, `${formatBytes(disk.used)} / ${formatBytes(disk.total)}`)}</div></section>` +
      `<section class="dashboard-card"><h3>Software Versions</h3><table class="compact-table"><tbody>${softwareRows}</tbody></table></section>` +
      dashboardCard('Repository', [['Size', formatBytes(data.statistics.development.bytes)], ['File count', data.statistics.development.files.toLocaleString()]]) +
      `<details class="dashboard-card compact-details" id="topProcesses"${topProcessesWasOpen ? ' open' : ''}><summary>Top Processes</summary><table class="process-table"><thead><tr><th>PID</th><th>User</th><th>CPU %</th><th>Memory %</th><th>Command</th></tr></thead><tbody>${processes}</tbody></table></details>`;
      dashboardUpdated.textContent = `Updated ${new Date(data.generated_at).toLocaleTimeString()}`;
    } finally {
      environmentRefreshInProgress = false;
    }
  };

  if (form && form.dataset.created === '1') {
    localStorage.removeItem(draftKey);
  }

  if (textarea && !textarea.value) {
    textarea.value = localStorage.getItem(draftKey) || '';
  }

  textarea?.addEventListener('input', () => {
    localStorage.setItem(draftKey, textarea.value);
    if (draftStatus) {
      draftStatus.textContent = 'Draft saved locally.';
    }
  });

  clearDraft?.addEventListener('click', () => {
    localStorage.removeItem(draftKey);
    if (textarea) {
      textarea.value = '';
      textarea.focus();
    }
    if (draftStatus) {
      draftStatus.textContent = 'Draft cleared.';
    }
    if (editorHeading) {
      editorHeading.textContent = 'Create New Task';
    }
    viewingTaskNote?.remove();
    window.history.replaceState(null, '', window.location.pathname);
  });

  let pendingFiles = [];

  const syncFileInput = () => {
    if (!fileInput) {
      return;
    }

    const transfer = new DataTransfer();
    pendingFiles.slice(0, 5).forEach((file) => transfer.items.add(file));
    pendingFiles = Array.from(transfer.files);
    fileInput.files = transfer.files;
  };

  const renderSelectedFiles = () => {
    if (!selectedFiles) {
      return;
    }

    if (pendingFiles.length === 0) {
      selectedFiles.textContent = 'No files selected.';
      return;
    }

    const list = document.createElement('ul');
    pendingFiles.forEach((file, index) => {
      const item = document.createElement('li');
      const name = document.createElement('span');
      const remove = document.createElement('button');

      name.textContent = `✓ ${file.name}`;
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.addEventListener('click', () => {
        pendingFiles.splice(index, 1);
        syncFileInput();
        renderSelectedFiles();
      });

      item.append(name, remove);
      list.append(item);
    });

    selectedFiles.replaceChildren(list);
  };

  const setSelectedFiles = (files) => {
    pendingFiles = Array.from(files).slice(0, 5);
    syncFileInput();
    renderSelectedFiles();
  };

  fileInput?.addEventListener('change', () => {
    setSelectedFiles(fileInput.files);
  });

  ['dragenter', 'dragover'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.add('dragover');
    });
  });

  ['dragleave', 'drop'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.remove('dragover');
    });
  });

  uploadZone?.addEventListener('drop', (event) => {
    if (!fileInput || !event.dataTransfer || !event.dataTransfer.files.length) {
      return;
    }
    setSelectedFiles(event.dataTransfer.files);
  });

  const taskForCodexPanel = () => codexRunPanel?.dataset.task || '';

  const updateCodexLog = async (scrollToBottom = false) => {
    if (!codexConsole || !codexRunPanel) {
      return;
    }
    const response = await fetch(`?action=codex-log&task=${encodeURIComponent(taskForCodexPanel())}`, { cache: 'no-store' });
    const logText = await response.text();
    codexConsole.textContent = logText;
    if (scrollToBottom) {
      codexConsole.scrollTop = codexConsole.scrollHeight;
    }
  };

  const updateCodexStatus = async () => {
    if (!codexRunPanel || !codexStatus) {
      return 'not_started';
    }
    const response = await fetch(`?action=codex-status&task=${encodeURIComponent(taskForCodexPanel())}`, { cache: 'no-store' });
    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Unable to read Codex status.');
    }
    codexStatus.textContent = payload.label;
    codexStatus.dataset.status = payload.status;
    return payload.status;
  };

  let codexPoll = null;
  const startCodexPolling = () => {
    if (codexPoll) {
      clearInterval(codexPoll);
    }
    codexPoll = setInterval(async () => {
      try {
        const status = await updateCodexStatus();
        await updateCodexLog(status === 'queued' || status === 'running');
        if (status === 'completed' || status === 'failed') {
          clearInterval(codexPoll);
          if (status === 'completed') {
            window.setTimeout(() => {
              saveScrollPosition();
              window.location.href = `?task=${encodeURIComponent(`${taskForCodexPanel()}.md`)}`;
            }, 1000);
          }
        }
      } catch (error) {
        if (codexStatus) {
          codexStatus.textContent = 'Failed';
        }
        clearInterval(codexPoll);
      }
    }, 3000);
  };

  runCodex?.addEventListener('click', async () => {
    if (!runCodex || !codexStatus) {
      return;
    }
    runCodex.disabled = true;
    codexStatus.textContent = 'Queued';
    const formData = new FormData();
    formData.set('action', 'run-codex');
    formData.set('task', runCodex.dataset.task || '');

    try {
      const response = await fetch('', { method: 'POST', body: formData });
      const payload = await response.json();
      if (!payload.ok) {
        throw new Error(payload.error || 'Unable to start Codex.');
      }
      codexStatus.textContent = payload.label;
      await updateCodexLog(true);
      startCodexPolling();
    } catch (error) {
      codexStatus.textContent = 'Failed';
      runCodex.disabled = false;
    }
  });

  refreshCodexLog?.addEventListener('click', () => {
    updateCodexLog(false).catch(() => {
      if (codexConsole) {
        codexConsole.textContent = 'Unable to load activity.';
      }
    });
  });

  copyCodexLog?.addEventListener('click', async () => {
    if (!codexConsole || !copyCodexLog) return;
    try {
      await navigator.clipboard.writeText(codexConsole.textContent || '');
      copyCodexLog.textContent = 'Copied';
      if (copyCodexMessage) copyCodexMessage.textContent = '';
      window.setTimeout(() => { copyCodexLog.textContent = 'Copy to Clipboard'; }, 1500);
    } catch (error) {
      if (copyCodexMessage) copyCodexMessage.textContent = 'Unable to copy activity.';
    }
  });

  const deployButton = document.getElementById('deployProduction');
  const previewDeployButton = document.getElementById('deployPreview');
  const previewDeployDialog = document.getElementById('previewDeploymentDialog');
  const previewSummaryBox = document.getElementById('previewDeploymentSummary');
  const previewConfirmButton = document.getElementById('confirmPreviewDeployment');
  const previewCancelButton = document.getElementById('cancelPreviewDeployment');
  const previewModalError = document.getElementById('previewModalDeploymentError');
  const deployDialog = document.getElementById('deploymentDialog');
  const previewBox = document.getElementById('deploymentPreview');
  const finalStep = document.getElementById('deploymentFinalStep');
  const confirmationInput = document.getElementById('deploymentConfirmation');
  const continueButton = document.getElementById('continueDeployment');
  const confirmButton = document.getElementById('confirmDeployment');
  const cancelButton = document.getElementById('cancelDeployment');
  const deploymentError = document.getElementById('productionDeploymentError');
  const modalError = document.getElementById('modalDeploymentError');
  const deploymentLog = document.getElementById('productionDeploymentLog');
  const deploymentStatus = document.getElementById('productionDeploymentStatus');
  const previewDeploymentError = document.getElementById('previewDeploymentError');
  const previewDeploymentLog = document.getElementById('previewDeploymentLog');
  const previewDeploymentStatus = document.getElementById('previewDeploymentStatus');
  let confirmedSummary = null;
  let previewConfirmedSummary = null;
  let deploymentStep = 1;
  const deploymentPolls = { preview: null, production: null };
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));

  const postDeployment = async (action, values = {}) => {
    const body = new FormData();
    body.set('action', action); body.set('csrf_token', csrfToken);
    Object.entries(values).forEach(([key, value]) => body.set(key, value));
    const response = await fetch(`?action=${encodeURIComponent(action)}`, { method: 'POST', body });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || 'Deployment request failed.');
    return payload;
  };
  const setDeploymentStatus = (environment, status) => {
    const element = environment === 'preview' ? previewDeploymentStatus : deploymentStatus;
    element.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    element.className = `deployment-status ${status}`;
  };
  const pollDeployment = (environment, id) => {
    clearInterval(deploymentPolls[environment]);
    const isPreview = environment === 'preview';
    const button = isPreview ? previewDeployButton : deployButton;
    const log = isPreview ? previewDeploymentLog : deploymentLog;
    const errorBox = isPreview ? previewDeploymentError : deploymentError;
    const update = async () => {
      const response = await fetch(`?action=deployment-status&environment=${encodeURIComponent(environment)}&id=${encodeURIComponent(id)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.deployment) return;
      setDeploymentStatus(environment, payload.deployment.status);
      log.textContent = payload.log || 'Waiting for deployment log...';
      log.scrollTop = log.scrollHeight;
      if (['success', 'failed'].includes(payload.deployment.status)) {
        clearInterval(deploymentPolls[environment]); button.disabled = false;
        document.getElementById(`${environment}LastDeploymentTime`).textContent = payload.deployment.finish_time || payload.deployment.start_time;
        if (!isPreview && payload.deployment.status === 'success') {
          const productionCommit = document.getElementById('productionCommit');
          productionCommit.textContent = payload.deployment.source_commit.slice(0, 7);
          productionCommit.title = payload.deployment.source_commit;
        }
        if (payload.deployment.error) errorBox.textContent = payload.deployment.error;
      }
    };
    update().catch(() => {}); deploymentPolls[environment] = setInterval(() => update().catch(() => {}), 2000);
  };
  const deploymentSummaryHtml = (payload) => {
    const items = payload.summary.files.length ? payload.summary.files.map((file) => `<li>${escapeHtml(file)}</li>`).join('') : '<li>No file changes.</li>';
    return `<dl class="deployment-details"><div><dt>Source</dt><dd><code>${escapeHtml(payload.overview.source)}</code></dd></div><div><dt>Target</dt><dd><code>${escapeHtml(payload.overview.target)}</code></dd></div><div><dt>Branch</dt><dd>${escapeHtml(payload.overview.branch)}</dd></div><div><dt>Commit</dt><dd><code>${escapeHtml(payload.overview.commit)}</code></dd></div><div><dt>Message</dt><dd>${escapeHtml(payload.overview.message)}</dd></div><div><dt>Changes</dt><dd>${Number(payload.summary.added)} added · ${Number(payload.summary.updated)} updated · ${Number(payload.summary.deleted)} deleted</dd></div></dl><ul class="change-list">${items}</ul>`;
  };
  previewDeployButton?.addEventListener('click', async () => {
    previewDeploymentError.textContent = ''; previewModalError.textContent = ''; previewDeployButton.disabled = true;
    previewConfirmButton.disabled = false;
    try {
      const payload = await postDeployment('deployment-preview', { environment: 'preview' });
      previewConfirmedSummary = payload.summary;
      previewSummaryBox.innerHTML = deploymentSummaryHtml(payload);
      previewDeployDialog.showModal();
    } catch (error) { previewDeploymentError.textContent = error.message; previewDeployButton.disabled = false; setDeploymentStatus('preview', 'failed'); }
  });
  previewCancelButton?.addEventListener('click', () => { previewDeployDialog.close(); previewDeployButton.disabled = false; });
  previewConfirmButton?.addEventListener('click', async () => {
    previewConfirmButton.disabled = true; previewModalError.textContent = '';
    try {
      const payload = await postDeployment('deployment-start', { environment: 'preview', summary: JSON.stringify(previewConfirmedSummary) });
      previewDeployDialog.close(); setDeploymentStatus('preview', 'pending'); pollDeployment('preview', payload.deployment.id);
    } catch (error) { previewModalError.textContent = error.message; previewConfirmButton.disabled = false; }
  });
  deployButton?.addEventListener('click', async () => {
    deploymentError.textContent = ''; modalError.textContent = ''; deployButton.disabled = true;
    try {
      const payload = await postDeployment('deployment-preview', { environment: 'production' });
      confirmedSummary = payload.summary;
      previewBox.innerHTML = deploymentSummaryHtml(payload);
      deploymentStep = 1; finalStep.hidden = true; continueButton.hidden = false; confirmButton.hidden = true; confirmationInput.value = '';
      deployDialog.showModal();
    } catch (error) { deploymentError.textContent = error.message; deployButton.disabled = false; setDeploymentStatus('production', 'failed'); }
  });
  cancelButton?.addEventListener('click', () => { deploymentStep = 1; deployDialog.close(); deployButton.disabled = false; });
  continueButton?.addEventListener('click', () => { deploymentStep = 2; finalStep.hidden = false; continueButton.hidden = true; confirmButton.hidden = false; confirmationInput.focus(); });
  confirmationInput?.addEventListener('input', () => { confirmButton.disabled = confirmationInput.value !== 'DEPLOY'; });
  confirmButton?.addEventListener('click', async () => {
    if (deploymentStep !== 2 || confirmationInput.value !== 'DEPLOY') return;
    confirmButton.disabled = true; modalError.textContent = '';
    try {
      const payload = await postDeployment('deployment-start', { environment: 'production', confirmation: confirmationInput.value, summary: JSON.stringify(confirmedSummary) });
      deployDialog.close(); setDeploymentStatus('production', 'pending'); pollDeployment('production', payload.deployment.id);
    } catch (error) { modalError.textContent = error.message; confirmButton.disabled = confirmationInput.value !== 'DEPLOY'; }
  });

  if (codexRunPanel) {
    updateCodexStatus().then((status) => {
      updateCodexLog(status === 'queued' || status === 'running').catch(() => {});
      if (status === 'queued' || status === 'running') {
        startCodexPolling();
      }
    }).catch(() => {});
  }

  refreshEnvironmentDashboard().catch(() => {
    if (dashboardUpdated) dashboardUpdated.textContent = 'Status unavailable';
  });
  window.setInterval(() => refreshEnvironmentDashboard().catch(() => {
    if (dashboardUpdated) dashboardUpdated.textContent = 'Refresh failed';
  }), 5000);
})();
</script>
</body>
</html>
