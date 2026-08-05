<?php
require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/deployment.php';
require __DIR__ . '/apache.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/git.php';

const DEV_CONSOLE_VERSION = '0.1';

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$devConsoleRoot = dirname(__DIR__);

function commandOutputOrNull(array $arguments, string $cwd): ?string
{
    $result = processRunCommand($arguments, ['cwd' => $cwd, 'timeout' => 5]);

    return !empty($result['success']) ? trim((string)$result['stdout']) : null;
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

        $ticksResult = processRunCommand(['getconf', 'CLK_TCK'], ['timeout' => 2]);
        $ticksPerSecond = !empty($ticksResult['success']) ? (int)trim((string)$ticksResult['stdout']) : 0;
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
$isHttps = (
    isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== ''
) || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
session_set_cookie_params(['secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
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

$projectConfiguration = devConsoleLoadProjectConfiguration();
$projects = devConsoleProjects($projectConfiguration);
$activeProject = devConsoleActiveProject($projectConfiguration);
$activeProjectId = $activeProject === null ? '' : (string)($activeProject['id'] ?? '');
deploymentSetProject($activeProject);
$githubConfiguration = devConsoleLoadGithubConfiguration();
$githubConfigured = devConsoleGithubConfigured($githubConfiguration);
$githubCliInstalled = gitGhInstalled();
$legacyRepoRoot = dirname(__DIR__, 2);
$repoRoot = devConsoleProjectTaskRoot($projectConfiguration, $activeProject);
$todoDir = $repoRoot . '/TASKS/TODO';
$doneDir = $repoRoot . '/TASKS/DONE';
$attachmentsRoot = $repoRoot . '/TASKS/ATTACHMENTS';
$runsDir = devConsoleProjectRunsDir($activeProject);
$legacyTaskRoot = dirname(devConsoleRepositoryRoot());

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function configuredDisplayValue($value): string
{
    $text = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    return trim($text) === '' ? 'Not configured' : $text;
}

function projectMessageName(?array $project, string $fallback = ''): string
{
    $name = trim((string)($project['name'] ?? ''));
    if ($name === '') {
        $name = trim($fallback);
    }

    return $name === '' ? 'Project' : $name;
}

function relativePath(string $repoRoot, string $path): string
{
    return ltrim(str_replace($repoRoot, '', $path), '/');
}

function taskNumber(int $number): string
{
    return sprintf('TASK-%03d', $number);
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

function taskSystemMetadata(string $body): array
{
    if (preg_match('/\A---\s*\R(.*?)\R---\s*(?:\R|$)/s', $body, $matches) !== 1) {
        return [];
    }

    $metadata = [];
    foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
        if (preg_match('/^([a-z0-9_]+):\s*(.*?)\s*$/i', $line, $lineMatches) === 1) {
            $metadata[strtolower($lineMatches[1])] = trim($lineMatches[2], " \t\"'");
        }
    }

    return $metadata;
}

function taskProjectId(string $body): string
{
    $metadata = taskSystemMetadata($body);
    if (isset($metadata['project_id']) && projectSafeId((string)$metadata['project_id'])) {
        return (string)$metadata['project_id'];
    }

    return '';
}

function taskBodyWithProjectMetadata(string $body, string $projectId): string
{
    return taskMetadataBlock($projectId) . "\n\n" . rtrim(taskEditableBody($body));
}

function taskMetadataBlock(string $projectId): string
{
    return "---\nproject_id: " . $projectId . "\n---";
}

function taskEditableBody(string $body): string
{
    return preg_replace('/\A---\s*\R.*?\R---\s*(?:\R|$)/s', '', $body, 1) ?? $body;
}

function taskDefaultTemplate(string $taskId): string
{
    return "# {$taskId}\n\n## Title\n\n...\n";
}

function taskBelongsToProject(string $body, string $projectId, bool $allowImplicitOwnership): bool
{
    $metadataProjectId = taskProjectId($body);
    if ($metadataProjectId !== '') {
        return $metadataProjectId === $projectId;
    }

    return $allowImplicitOwnership;
}

function taskStorageContexts(array $configuration, ?array $project): array
{
    if ($project === null) {
        return [];
    }

    $projectId = (string)($project['id'] ?? '');
    $projectRoot = devConsoleProjectTaskRoot($configuration, $project);
    $contexts = [[
        'source' => 'project',
        'root' => $projectRoot,
        'todo' => $projectRoot . '/TASKS/TODO',
        'done' => $projectRoot . '/TASKS/DONE',
        'attachments' => $projectRoot . '/TASKS/ATTACHMENTS',
        'allow_implicit_ownership' => true,
    ]];

    // Legacy compatibility: before Project-specific repositories, the first/default
    // Project used /var/www/TASKS. Those legacy files are associated only with that
    // first Project and are never copied or shown for other Projects.
    if ($projectId !== '' && $projectId === devConsoleFirstProjectId($configuration)) {
        $legacyRoot = dirname(devConsoleRepositoryRoot());
        if ($legacyRoot !== $projectRoot) {
            $contexts[] = [
                'source' => 'legacy',
                'root' => $legacyRoot,
                'todo' => $legacyRoot . '/TASKS/TODO',
                'done' => $legacyRoot . '/TASKS/DONE',
                'attachments' => $legacyRoot . '/TASKS/ATTACHMENTS',
                'allow_implicit_ownership' => true,
            ];
        }
    }

    return $contexts;
}

function taskContextForSource(array $contexts, string $source): ?array
{
    foreach ($contexts as $context) {
        if ((string)$context['source'] === $source) {
            return $context;
        }
    }

    return null;
}

function taskGitMetadata(string $repoRoot): array
{
    $cachePath = DEPLOY_STATE_DIR . '/task-git-metadata-' . substr(hash('sha256', $repoRoot), 0, 16) . '.json';
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

function taskFileEntriesForContext(array $context, string $projectId): array
{
    $entries = [];
    $gitMetadata = taskGitMetadata((string)$context['root']);

    foreach (['TODO' => (string)$context['todo'], 'DONE' => (string)$context['done']] as $status => $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if (!preg_match('/^TASK-(\d{3})\.md$/', $entry, $matches)) {
                continue;
            }

            $path = $directory . '/' . $entry;
            $body = (string)file_get_contents($path);
            if (!taskBelongsToProject($body, $projectId, !empty($context['allow_implicit_ownership']))) {
                continue;
            }
            $relativePath = relativePath((string)$context['root'], $path);
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
                'source' => (string)$context['source'],
                'root' => (string)$context['root'],
                'project_id' => $projectId,
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

function taskFileEntries(array $contexts, string $projectId): array
{
    $entries = [];
    $seen = [];
    foreach ($contexts as $context) {
        foreach (taskFileEntriesForContext($context, $projectId) as $entry) {
            $key = (string)$entry['task_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entries[] = $entry;
        }
    }

    usort($entries, function (array $left, array $right): int {
        return $right['number'] <=> $left['number'] ?: $right['modified'] <=> $left['modified'];
    });

    return $entries;
}

function legacyTasksDetected(array $tasks): bool
{
    foreach ($tasks as $task) {
        if ((string)($task['source'] ?? '') === 'legacy') {
            return true;
        }
    }

    return false;
}

function groupedTaskEntries(array $tasks, string $runsDir): array
{
    $groups = [
        'TODO' => [],
        'IN PROGRESS' => [],
        'DONE' => [],
    ];

    foreach ($tasks as $task) {
        $status = (string)($task['status'] ?? '');
        if ($status === 'DONE') {
            $groups['DONE'][] = $task;
            continue;
        }

        $runStatus = codexRunStatus($runsDir, (string)$task['task_id'], (string)($task['source'] ?? 'project'));
        if (in_array($runStatus, ['queued', 'running'], true)) {
            $groups['IN PROGRESS'][] = $task;
        } else {
            $groups['TODO'][] = $task;
        }
    }

    foreach ($groups as &$groupTasks) {
        usort($groupTasks, static function (array $left, array $right): int {
            return ((int)$left['number']) <=> ((int)$right['number']);
        });
    }
    unset($groupTasks);

    return $groups;
}

function existingTaskNumbers(array $contexts, string $projectId): array
{
    $numbers = [];

    foreach (taskFileEntries($contexts, $projectId) as $entry) {
        if (isset($entry['number'])) {
            $numbers[] = (int)$entry['number'];
        }
    }

    return $numbers;
}

function taskExists(int $number, array $contexts, string $projectId): bool
{
    $taskId = taskNumber($number);
    foreach (taskFileEntries($contexts, $projectId) as $entry) {
        if ((string)$entry['task_id'] === $taskId) {
            return true;
        }
    }

    return false;
}

function nextTaskNumber(array $contexts, string $projectId): int
{
    $numbers = existingTaskNumbers($contexts, $projectId);
    $next = empty($numbers) ? 1 : max($numbers) + 1;

    while (taskExists($next, $contexts, $projectId)) {
        $next++;
    }

    return $next;
}

function findTaskForView(array $contexts, string $projectId, string $filename, string $source = ''): ?array
{
    if (!preg_match('/^TASK-\d{3}\.md$/', $filename)) {
        return null;
    }

    $contextsToInspect = $source === '' ? $contexts : array_values(array_filter([$context = taskContextForSource($contexts, $source)]));
    $matches = [];
    foreach ($contextsToInspect as $context) {
        foreach (['TODO' => (string)$context['todo'], 'DONE' => (string)$context['done']] as $status => $directory) {
            $path = $directory . '/' . $filename;
            if (!is_file($path)) {
                continue;
            }
            $body = (string)file_get_contents($path);
            if (!taskBelongsToProject($body, $projectId, !empty($context['allow_implicit_ownership']))) {
                continue;
            }
            $matches[] = [
                'filename' => $filename,
                'task_id' => pathinfo($filename, PATHINFO_FILENAME),
                'status' => $status,
                'path' => $path,
                'relative_path' => relativePath((string)$context['root'], $path),
                'source' => (string)$context['source'],
                'root' => (string)$context['root'],
                'project_id' => $projectId,
                'body' => $body,
            ];
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
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

function attachmentFilesForTask(array $contexts, string $projectId, string $taskId, string $source): array
{
    if (!isTaskId($taskId)) {
        return [];
    }
    $context = taskContextForSource($contexts, $source);
    if ($context === null) {
        return [];
    }
    if (findTaskForView($contexts, $projectId, $taskId . '.md', $source) === null) {
        return [];
    }

    $directory = (string)$context['attachments'] . '/' . $taskId;

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

function attachmentPromptText(array $contexts, string $projectId, string $taskId, string $source): string
{
    $files = attachmentFilesForTask($contexts, $projectId, $taskId, $source);
    if (empty($files)) {
        return '';
    }

    return "The following attachments are available in TASKS/ATTACHMENTS/{$taskId}/:\n\n- " . implode("\n- ", $files) . "\n\nUse them where appropriate.";
}


function isTaskId(string $taskId): bool
{
    return preg_match('/^TASK-\d{3}$/', $taskId) === 1;
}

function taskFileForId(array $contexts, string $projectId, string $taskId, string $source = ''): ?string
{
    if (!isTaskId($taskId)) {
        return null;
    }

    $task = findTaskForView($contexts, $projectId, $taskId . '.md', $source);
    return $task === null ? null : (string)$task['path'];
}

function todoTaskForId(array $contexts, string $projectId, string $taskId, string $source = ''): ?array
{
    if (!isTaskId($taskId)) {
        return null;
    }

    $task = findTaskForView($contexts, $projectId, $taskId . '.md', $source);
    return $task !== null && (string)$task['status'] === 'TODO' ? $task : null;
}

function todoTaskFileForId(array $contexts, string $projectId, string $taskId, string $source = ''): ?string
{
    $task = todoTaskForId($contexts, $projectId, $taskId, $source);
    return $task === null ? null : (string)$task['path'];
}

function taskHasAttachment(array $contexts, string $projectId, string $taskId, string $source): bool
{
    return !empty(attachmentFilesForTask($contexts, $projectId, $taskId, $source));
}

function codexPromptForTask(array $contexts, string $projectId, string $taskId, string $source): string
{
    $task = todoTaskForId($contexts, $projectId, $taskId, $source);
    if ($task === null) {
        throw new RuntimeException('Task file is not in TODO.');
    }

    $prompt = "Execute TASKS/TODO/{$taskId}.md.

Follow AGENTS.md.

Work in repository:
" . (string)$task['root'];

    $attachmentPrompt = attachmentPromptText($contexts, $projectId, $taskId, $source);
    if ($attachmentPrompt !== '') {
        $prompt .= "\n\n" . $attachmentPrompt;
    }

    return $prompt;
}

function runFile(string $runsDir, string $taskId, string $extension, string $source = 'project'): string
{
    if (!isTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }
    if (!in_array($source, ['project', 'legacy'], true)) {
        throw new RuntimeException('Invalid task source.');
    }

    $prefix = $source === 'project' ? '' : $source . '-';
    return $runsDir . '/' . $prefix . $taskId . '.' . $extension;
}

function currentTaskSessionKey(string $projectId): string
{
    return 'current_task_' . $projectId;
}

function currentTaskSourceSessionKey(string $projectId): string
{
    return 'current_task_source_' . $projectId;
}

function saveCurrentTaskSelection(string $projectId, string $taskId, string $source): void
{
    if ($projectId === '' || !isTaskId($taskId) || !in_array($source, ['project', 'legacy'], true)) {
        return;
    }

    $_SESSION[currentTaskSessionKey($projectId)] = $taskId;
    $_SESSION[currentTaskSourceSessionKey($projectId)] = $source;
}

function clearCurrentTaskSelection(string $projectId): void
{
    unset($_SESSION[currentTaskSessionKey($projectId)], $_SESSION[currentTaskSourceSessionKey($projectId)]);
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

function codexRunStatus(string $runsDir, string $taskId, string $source = 'project'): string
{
    $statusPath = runFile($runsDir, $taskId, 'status', $source);

    if (!is_file($statusPath)) {
        return 'not_started';
    }

    $status = trim((string)file_get_contents($statusPath));

    return in_array($status, ['queued', 'running', 'completed', 'failed'], true) ? $status : 'failed';
}

function startCodexRun(array $contexts, string $projectId, string $runsDir, string $taskId, string $source): void
{
    if (!isTaskId($taskId)) {
        throw new RuntimeException('Invalid task id.');
    }

    if (todoTaskForId($contexts, $projectId, $taskId, $source) === null) {
        throw new RuntimeException('Task file is not in TODO.');
    }

    $status = codexRunStatus($runsDir, $taskId, $source);
    if ($status === 'running' || $status === 'queued') {
        return;
    }

    ensureRunsDir($runsDir);
    $promptPath = runFile($runsDir, $taskId, 'prompt', $source);
    $statusPath = runFile($runsDir, $taskId, 'status', $source);
    $logPath = runFile($runsDir, $taskId, 'log', $source);
    file_put_contents($promptPath, codexPromptForTask($contexts, $projectId, $taskId, $source));
    file_put_contents($statusPath, 'queued');
    file_put_contents($logPath, '[' . date('c') . "] Queued Codex run for {$taskId}.\n");

    $worker = __DIR__ . '/run-codex.php';
    $command = 'nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($taskId) . ' ' . escapeshellarg((string)($GLOBALS['activeProjectId'] ?? '')) . ' ' . escapeshellarg($source) . ' >/dev/null 2>&1 &';
    exec($command);
}

function sendJson(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function taskGitCommand(array $arguments, string $repoRoot, int $timeoutSeconds = 120): array
{
    return processRunCommand(array_merge(['git', '-C', $repoRoot], $arguments), [
        'cwd' => $repoRoot,
        'env' => [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_AUTHOR_NAME' => 'IOVON Dev Console',
            'GIT_AUTHOR_EMAIL' => 'iovon@iovon.com',
            'GIT_COMMITTER_NAME' => 'IOVON Dev Console',
            'GIT_COMMITTER_EMAIL' => 'iovon@iovon.com',
        ],
        'inherit_env' => false,
        'timeout' => $timeoutSeconds,
    ]);
}

function taskGitAuthenticatedCommand(array $arguments, string $repoRoot, array $githubConfiguration, int $timeoutSeconds = 120): array
{
    return gitRunAuthenticatedCommand(array_merge(['git', '-C', $repoRoot], $arguments), $githubConfiguration, $timeoutSeconds);
}

function taskRepositoryReadiness(?array $project, array $githubConfiguration): array
{
    if ($project === null) {
        return ['ready' => false, 'reason' => 'Select or create a Project before creating tasks.'];
    }
    $path = gitProjectRepositoryPath($project);
    if ($path === '' || gitValidateProjectRepositoryPath($project) !== null) {
        return ['ready' => false, 'reason' => 'Repository path is not valid for this Project.'];
    }
    if (!is_dir($path) || is_link($path)) {
        return ['ready' => false, 'reason' => 'Repository is not initialized. Initialize Repository in Settings before creating tasks.'];
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        return ['ready' => false, 'reason' => 'Repository is not initialized. Initialize Repository in Settings before creating tasks.'];
    }
    if ((string)($project['git']['bootstrap_status'] ?? '') !== 'ready' || empty($project['git']['connected'])) {
        return ['ready' => false, 'reason' => 'Repository initialization is incomplete. Use Retry Initialization in Settings before creating tasks.'];
    }
    if ($error = gitAssertConnectedRepository($project, $githubConfiguration)) {
        return ['ready' => false, 'reason' => $error];
    }
    $status = gitStatus($project, $githubConfiguration);
    if (in_array((string)$status['status'], ['INITIALIZATION INCOMPLETE', 'NOT INITIALIZED', 'INVALID REPOSITORY', 'REMOTE UNAVAILABLE'], true)) {
        return ['ready' => false, 'reason' => 'Repository initialization is incomplete. Use Retry Initialization in Settings before creating tasks.'];
    }
    if (in_array((string)$status['status'], ['AHEAD', 'AHEAD / BEHIND', 'CHANGES PRESENT'], true)) {
        return ['ready' => false, 'reason' => 'Repository synchronization is pending. Use Push in Settings before creating another task.'];
    }
    if ((string)$status['status'] !== 'CONNECTED') {
        return ['ready' => false, 'reason' => 'Repository is not ready for task creation. Review Git status in Settings.'];
    }

    return ['ready' => true, 'reason' => ''];
}

function codexCliInstalled(): bool
{
    $diagnostics = serverToolsDiagnostics(false);
    return !empty($diagnostics['tools']['codex']['available_to_service_user']);
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

function projectActionTitle(string $projectAction, bool $success): string
{
    if ($projectAction === 'provision_project') return $success ? 'Project set up' : 'Project setup failed';
    if ($projectAction === 'remove_project') return $success ? 'Project removed' : 'Project removal failed';
    if ($projectAction === 'delete_project') return $success ? 'Project deleted' : 'Project deletion failed';
    if ($projectAction === 'verify_project_routing') return $success ? 'Websites checked' : 'Website check failed';
    if ($projectAction === 'initialize_repository') return $success ? 'Repository initialized' : 'Repository initialization failed';
    if ($projectAction === 'fetch_git_repository') return $success ? 'Git fetch completed' : 'Git fetch failed';
    if ($projectAction === 'pull_git_repository') return $success ? 'Git pull completed' : 'Git pull failed';
    if ($projectAction === 'push_git_repository') return $success ? 'Git push completed' : 'Git push failed';
    if ($projectAction === 'cleanup_orphaned_project') return $success ? 'Orphaned infrastructure cleaned up' : 'Orphaned cleanup failed';
    if ($projectAction === 'refresh_server_diagnostics') return $success ? 'Server diagnostics refreshed' : 'Server diagnostics failed';
    if ($projectAction === 'server_tool_action') return $success ? 'Server tool action completed' : 'Server tool action failed';

    return $success ? 'Project action completed' : 'Project action failed';
}

function renderOperationResult(array $result, string $operationLogId, string $downloadName): void
{
    $projectAction = (string)($result['action'] ?? '');
    $success = !empty($result['success']);
    $operationSteps = operationSummarySteps($projectAction, $result);
    $operationLog = (string)($result['output'] ?? '');
    $hasOperationLog = trim($operationLog) !== '';
    ?>
    <section class="result-block <?= $success ? '' : 'error' ?>">
      <h2><?= h(projectActionTitle($projectAction, $success)) ?></h2>
      <p><?= h((string)$result['message']) ?></p>
      <?php if (!empty($operationSteps)): ?>
        <ul class="operation-summary">
          <?php foreach ($operationSteps as $step): ?>
            <li>Done: <?= h($step) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($hasOperationLog): ?>
        <details<?= $success ? '' : ' open' ?>>
          <summary>Show operation log</summary>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="<?= h($operationLogId) ?>">Copy Log</button>
            <button type="button" class="secondary" data-download-log="<?= h($operationLogId) ?>" data-download-name="<?= h($downloadName) ?>">Download Log</button>
            <span class="hint" data-log-message="<?= h($operationLogId) ?>" aria-live="polite"></span>
          </div>
          <pre id="<?= h($operationLogId) ?>"><?= h($operationLog) ?></pre>
        </details>
      <?php endif; ?>
    </section>
    <?php
}

function projectLifecycleLabel(array $project, array $projectStatus): string
{
    $status = (string)($projectStatus['label'] ?? 'Not set up');
    $managed = !empty($project['provisioning']['managed']);
    if ($status === 'Ready') {
        return $managed ? 'Ready' : 'Imported';
    }
    if ($status === 'Configuration drift' || $status === 'Incomplete') {
        return $status;
    }

    return $managed ? 'Not set up' : 'New';
}

function operationSummarySteps(string $action, array $result): array
{
    if (!empty($result['summary_steps']) && is_array($result['summary_steps'])) {
        return array_values(array_filter(array_map('strval', $result['summary_steps'])));
    }
    if (empty($result['success'])) {
        return [];
    }

    if ($action === 'initialize_repository') {
        return [
            'Local repository prepared',
            'GitHub repository verified',
            'First push completed',
            'Remote branch verified',
        ];
    }
    if ($action === 'fetch_git_repository') {
        return ['Remote changes fetched', 'Git status refreshed'];
    }
    if ($action === 'pull_git_repository') {
        return ['Remote changes fetched', 'Fast-forward pull completed', 'Git status refreshed'];
    }
    if ($action === 'push_git_repository') {
        return ['Local commits pushed', 'Remote branch verified', 'Git status refreshed'];
    }
    if ($action === 'provision_project') {
        return ['Project directories prepared', 'Apache configuration ready', 'Routing verified'];
    }
    if ($action === 'verify_project_routing') {
        return ['Apache ServerName checked', 'Websites checked'];
    }
    if ($action === 'delete_project') {
        return ['Managed Apache configuration removed', 'Managed project directories removed', 'Git repository preserved'];
    }
    if ($action === 'cleanup_orphaned_project') {
        return ['Orphaned Apache configuration removed', 'Orphaned project directories removed', 'Git repositories preserved'];
    }
    if ($action === 'remove_project') {
        return ['Project registration removed', 'Server files preserved'];
    }

    return [];
}

$taskContexts = taskStorageContexts($projectConfiguration, $activeProject);
$nextNumber = $activeProjectId === '' ? 1 : nextTaskNumber($taskContexts, $activeProjectId);
$latestTasks = $activeProjectId === '' ? [] : taskFileEntries($taskContexts, $activeProjectId);
$legacyTasksDetected = legacyTasksDetected($latestTasks);
$taskGroups = groupedTaskEntries($latestTasks, $runsDir);
$requestedTaskFile = (string)($_GET['task'] ?? '');
$requestedTaskSource = (string)($_GET['task_source'] ?? '');
$viewTask = $activeProjectId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $requestedTaskFile, $requestedTaskSource);
if ($viewTask !== null) {
    saveCurrentTaskSelection($activeProjectId, (string)$viewTask['task_id'], (string)$viewTask['source']);
} elseif ($requestedTaskFile === '' && $activeProjectId !== '') {
    $storedTaskId = is_scalar($_SESSION[currentTaskSessionKey($activeProjectId)] ?? null) ? (string)$_SESSION[currentTaskSessionKey($activeProjectId)] : '';
    $storedTaskSource = is_scalar($_SESSION[currentTaskSourceSessionKey($activeProjectId)] ?? null) ? (string)$_SESSION[currentTaskSourceSessionKey($activeProjectId)] : '';
    $viewTask = $storedTaskId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $storedTaskId . '.md', $storedTaskSource);
    if ($viewTask === null) {
        clearCurrentTaskSelection($activeProjectId);
    }
}
$createdTaskId = '';
$createdTaskPath = '';
$attachmentPaths = [];
$commitHash = '';
$prompt = '';
$error = '';
$apacheActionResult = null;
$projectActionResult = null;
$githubActionResult = null;
$serverDiagnosticsResult = null;
$projectFormErrors = [];
$projectFormValues = [
    'project_name' => '',
    'production_domain' => '',
];
$projectFlash = '';
$results = [];
$taskPushWarning = '';
$taskRepositoryReadiness = taskRepositoryReadiness($activeProject, $githubConfiguration);
$taskCreationReady = !empty($taskRepositoryReadiness['ready']);
$taskCreationUnavailableReason = (string)($taskRepositoryReadiness['reason'] ?? '');
$codexCliReady = codexCliInstalled();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'codex-status') {
    $taskId = (string)($_GET['task'] ?? '');
    $taskSource = (string)($_GET['task_source'] ?? 'project');
    try {
        if (!isTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }
        if ($activeProjectId === '' || todoTaskForId($taskContexts, $activeProjectId, $taskId, $taskSource) === null) {
            throw new RuntimeException('Task does not belong to the active Project.');
        }
        $status = codexRunStatus($runsDir, $taskId, $taskSource);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'codex-log') {
    $taskId = (string)($_GET['task'] ?? '');
    $taskSource = (string)($_GET['task_source'] ?? 'project');
    if (!isTaskId($taskId) || $activeProjectId === '' || todoTaskForId($taskContexts, $activeProjectId, $taskId, $taskSource) === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Task does not belong to the active Project.';
        exit;
    }

    $logPath = runFile($runsDir, $taskId, 'log', $taskSource);
    header('Content-Type: text/plain; charset=UTF-8');
    echo is_file($logPath) ? (string)file_get_contents($logPath) : 'No log file yet.';
    exit;
}

if ($action === 'deployment-preview' || $action === 'deployment-start') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); sendJson(['ok' => false, 'error' => 'Invalid deployment request.']); exit;
    }
    try {
        if ($activeProject === null) {
            throw new RuntimeException('Select or create a Project before deploying.');
        }
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
        exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($environment) . ' ' . escapeshellarg($state['id']) . ' ' . escapeshellarg($activeProjectId) . ' >/dev/null 2>&1 &');
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

if ($action === 'server-tool-operation-status') {
    $operationId = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : '';
    try {
        if (!serverToolsValidateOperationId($operationId)) {
            throw new RuntimeException('Invalid server tool operation ID.');
        }
        sendJson(['ok' => true, 'operation' => serverToolsOperationStatus($operationId)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'run-codex' && $requestMethod === 'POST') {
    $taskId = (string)($_POST['task'] ?? '');
    $taskSource = (string)($_POST['task_source'] ?? 'project');
    try {
        if (!$codexCliReady) {
            throw new RuntimeException('Codex CLI is not installed on this server.');
        }
        startCodexRun($taskContexts, $activeProjectId, $runsDir, $taskId, $taskSource);
        $status = codexRunStatus($runsDir, $taskId, $taskSource);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'refresh_server_diagnostics') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $serverDiagnosticsResult = [
            'success' => false,
            'message' => 'Invalid diagnostics refresh request.',
            'action' => 'refresh_server_diagnostics',
            'output' => '',
            'summary_steps' => [],
        ];
    } else {
        $serverDiagnosticsResult = serverToolsRefreshResult();
    }
    $_SESSION['server_diagnostics_result'] = $serverDiagnosticsResult;
    header('Location: /?tab=server-management#server-tools');
    exit;
}

if ($action === 'server_tool_action') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid server tool request.']);
    } else {
        try {
            $toolId = is_scalar($_POST['tool_id'] ?? null) ? (string)$_POST['tool_id'] : '';
            $toolAction = is_scalar($_POST['tool_action'] ?? null) ? (string)$_POST['tool_action'] : '';
            $operation = serverToolsStartOperation($toolId, $toolAction);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if (in_array($action, apacheAllowedActions(), true)) {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $apacheActionResult = [
            'success' => false,
            'message' => 'Invalid Apache management request.',
            'output' => '',
        ];
    } else {
        $apacheActionResult = apacheRunAction($action);
    }
    $_SESSION['apache_action_result'] = $apacheActionResult;
    header('Location: /?tab=settings#apache');
    exit;
}

if ($action === 'select_active_project') {
    $projectId = is_scalar($_POST['project_id'] ?? null) ? (string)$_POST['project_id'] : '';
    $projectForSelection = devConsoleFindProjectById(devConsoleLoadProjectConfiguration(), $projectId);
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_flash'] = 'Invalid project selection request.';
    } elseif (!devConsoleSaveActiveProject($projectId)) {
        $_SESSION['project_flash'] = 'Unable to select Project.';
    } else {
        $_SESSION['project_flash'] = 'Project "' . projectMessageName($projectForSelection, $projectId) . '" selected for Dashboard.';
    }
    $targetTab = (string)($_POST['target_tab'] ?? 'settings') === 'dashboard' ? 'dashboard' : 'settings';
    header('Location: /?tab=' . $targetTab);
    exit;
}

if (in_array($action, ['save_github_configuration', 'test_github_connection', 'remove_github_configuration', 'install_github_cli'], true)) {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $githubActionResult = gitActionResult(false, 'Invalid GitHub management request.');
    } elseif ($action === 'save_github_configuration') {
        $githubActionResult = gitGithubSaveConfiguration($_POST);
    } elseif ($action === 'test_github_connection') {
        $githubActionResult = gitGithubTestConnection();
    } elseif ($action === 'remove_github_configuration') {
        $githubActionResult = gitGithubRemoveConfiguration();
    } else {
        $githubActionResult = gitGithubInstallCli();
    }
    $githubActionResult['action'] = $action;
    $_SESSION['github_action_result'] = $githubActionResult;
    header('Location: /?tab=settings#github');
    exit;
}

if ($action === 'create_project') {
    foreach ($projectFormValues as $field => $fallback) {
        $value = $_POST[$field] ?? $fallback;
        $projectFormValues[$field] = is_scalar($value) ? trim((string)$value) : '';
    }

    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $projectFormErrors[] = 'Invalid project request.';
    } else {
        $projectResult = devConsoleAppendProject($projectFormValues);
        if (!empty($projectResult['valid']) && !empty($projectResult['saved'])) {
            $_SESSION['project_flash'] = 'Project "' . projectMessageName($projectResult['project'] ?? null, $projectFormValues['project_name']) . '" created.';
            header('Location: /?tab=settings#projects');
            exit;
        }

        $projectFormErrors = $projectResult['errors'] ?? ['Unable to create project.'];
    }
}

if (in_array($action, ['provision_project', 'remove_project', 'delete_project', 'verify_project_routing', 'initialize_repository', 'fetch_git_repository', 'pull_git_repository', 'push_git_repository', 'cleanup_orphaned_project'], true)) {
    $projectConfigurationForAction = devConsoleLoadProjectConfiguration();
    $projectId = is_scalar($_POST['project_id'] ?? null) ? (string)$_POST['project_id'] : '';
    $projectForAction = devConsoleFindProjectById($projectConfigurationForAction, $projectId);
    $projectNameForAction = projectMessageName($projectForAction, $projectId);
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $projectActionResult = projectActionResult(false, 'Invalid project action request.');
    } elseif ($action === 'provision_project') {
        $projectActionResult = projectProvision($projectConfigurationForAction, $projectId);
    } elseif ($action === 'verify_project_routing') {
        $projectActionResult = projectVerifyRoutingAction($projectConfigurationForAction, $projectId, ['require_apache_running' => true]);
    } elseif ($action === 'initialize_repository') {
        $projectActionResult = gitInitializeRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'fetch_git_repository') {
        $projectActionResult = gitFetchRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'pull_git_repository') {
        $projectActionResult = gitPullRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'push_git_repository') {
        $projectActionResult = gitPushRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'remove_project') {
        $projectActionResult = projectRemoveFromConsole($projectConfigurationForAction, $projectId);
    } elseif ($action === 'cleanup_orphaned_project') {
        $projectActionResult = projectCleanupOrphanedInfrastructure($projectConfigurationForAction, $projectId);
    } else {
        $confirmation = is_scalar($_POST['confirm_project_id'] ?? null) ? (string)$_POST['confirm_project_id'] : '';
        $projectActionResult = projectDelete($projectConfigurationForAction, $projectId, $confirmation);
    }
    $projectActionResult['action'] = $action;
    $projectActionResult['project_id'] = $projectId;
    $projectActionResult['project_name'] = $projectNameForAction;
    if (!empty($projectActionResult['success'])) {
        if ($action === 'provision_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" set up.';
        } elseif ($action === 'verify_project_routing') {
            $projectActionResult['message'] = 'Websites for "' . $projectNameForAction . '" checked.';
        } elseif ($action === 'initialize_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" initialized.';
        } elseif ($action === 'fetch_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" fetched.';
        } elseif ($action === 'pull_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" pulled.';
        } elseif ($action === 'push_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" pushed.';
        } elseif ($action === 'remove_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" removed from Dev Console.';
        } elseif ($action === 'delete_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" deleted.';
        } elseif ($action === 'cleanup_orphaned_project') {
            $projectActionResult['message'] = 'Infrastructure for "' . $projectNameForAction . '" cleaned up.';
        }
    } else {
        $prefix = $action === 'cleanup_orphaned_project'
            ? 'Infrastructure for "' . $projectNameForAction . '": '
            : 'Project "' . $projectNameForAction . '": ';
        $projectActionResult['message'] = $prefix . (string)($projectActionResult['message'] ?? 'Action failed.');
    }
    if (empty($projectActionResult['success']) && $projectForAction !== null && !in_array($action, ['remove_project', 'delete_project'], true)) {
        devConsoleSaveProjectConfiguration(devConsoleTouchProject($projectConfigurationForAction, $projectId));
    }
    $_SESSION['project_action_result'] = $projectActionResult;
    header('Location: /?tab=settings#' . ($action === 'cleanup_orphaned_project' ? 'apache' : 'projects'));
    exit;
}

if ($requestMethod === 'POST' && !in_array($action, array_merge(apacheAllowedActions(), ['refresh_server_diagnostics', 'server_tool_action', 'create_project', 'select_active_project', 'provision_project', 'remove_project', 'delete_project', 'verify_project_routing', 'initialize_repository', 'fetch_git_repository', 'pull_git_repository', 'push_git_repository', 'cleanup_orphaned_project', 'save_github_configuration', 'test_github_connection', 'remove_github_configuration', 'install_github_cli']), true)) {
    try {
        $body = trim((string)($_POST['task_body'] ?? ''));

        if ($body === '') {
            throw new RuntimeException('Task markdown body is required.');
        }
        if ($activeProject === null) {
            throw new RuntimeException('Select or create a Project before creating tasks.');
        }
        if (!$taskCreationReady) {
            throw new RuntimeException($taskCreationUnavailableReason);
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

        $number = nextTaskNumber($taskContexts, $activeProjectId);
        $taskId = taskNumber($number);
        $createdTaskId = $taskId;
        $taskPath = $todoDir . '/' . $taskId . '.md';

        if (taskExists($number, $taskContexts, $activeProjectId)) {
            throw new RuntimeException($taskId . ' already exists.');
        }

        $handle = @fopen($taskPath, 'x');
        if (!$handle) {
            throw new RuntimeException('Unable to create task file without overwriting.');
        }
        $taskBody = taskBodyWithProjectMetadata($body, $activeProjectId);
        fwrite($handle, $taskBody . "\n");
        fclose($handle);

        $pathsToAdd = [relativePath($repoRoot, $taskPath)];
        $createdTaskPath = $pathsToAdd[0];
        if ($activeProject !== null) {
            $documentationPath = gitEnsureTaskDocumentation($activeProject, $repoRoot);
            if ($documentationPath !== null) {
                $documentationRelativePath = relativePath($repoRoot, $documentationPath);
                if (!in_array($documentationRelativePath, $pathsToAdd, true)) {
                    $pathsToAdd[] = $documentationRelativePath;
                }
            }
        }

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

        $taskCommitted = false;
        $results[] = taskGitCommand(array_merge(['add'], $pathsToAdd), $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }

        $results[] = taskGitCommand(['commit', '-m', 'Add ' . $taskId], $repoRoot);
        $commitHash = extractCommitHash(end($results)['output']);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git commit failed.');
        }
        $taskCommitted = true;

        $results[] = taskGitAuthenticatedCommand(['push', 'origin', 'main'], $repoRoot, $githubConfiguration);
        if (end($results)['exit_code'] !== 0) {
            $refreshedConfiguration = devConsoleLoadProjectConfiguration();
            $refreshedProject = devConsoleFindProjectById($refreshedConfiguration, $activeProjectId);
            if ($refreshedProject !== null) {
                gitSaveProject($refreshedConfiguration, gitSetMetadata($refreshedProject, [
                    'bootstrap_status' => 'ready',
                    'connected' => true,
                    'remote_verified' => true,
                    'last_error_at' => date('c'),
                ]));
            }
            $taskPushWarning = 'Task "' . $taskId . '" created and committed locally for Project "' . projectMessageName($activeProject, $activeProjectId) . '", but synchronization with GitHub failed. Use Push in Settings to retry.';
        } else {
            $results[] = taskGitAuthenticatedCommand(['fetch', '--prune', 'origin'], $repoRoot, $githubConfiguration);
            if (end($results)['exit_code'] === 0) {
                $refreshedConfiguration = devConsoleLoadProjectConfiguration();
                $refreshedProject = devConsoleFindProjectById($refreshedConfiguration, $activeProjectId);
                $verified = gitReadVerifiedRepositoryMetadata($repoRoot);
                if ($refreshedProject !== null && $verified !== null) {
                    gitSaveProject($refreshedConfiguration, gitSetMetadata($refreshedProject, $verified + [
                        'bootstrap_status' => 'ready',
                        'connected' => true,
                        'remote_verified' => true,
                        'last_error_at' => null,
                        'last_fetch_at' => date('c'),
                    ]));
                }
            }
        }

        saveCurrentTaskSelection($activeProjectId, $taskId, 'project');
        $prompt = codexPromptForTask($taskContexts, $activeProjectId, $taskId, 'project');

        $nextNumber = nextTaskNumber($taskContexts, $activeProjectId);
        $latestTasks = taskFileEntries($taskContexts, $activeProjectId);
        $legacyTasksDetected = legacyTasksDetected($latestTasks);
        $taskGroups = groupedTaskEntries($latestTasks, $runsDir);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        if (empty($taskCommitted ?? false) && isset($taskPath) && is_file($taskPath)) {
            @unlink($taskPath);
        }
        if (empty($taskCommitted ?? false) && isset($taskAttachmentDir) && is_dir($taskAttachmentDir)) {
            foreach (array_diff(scandir($taskAttachmentDir) ?: [], ['.', '..']) as $entry) {
                @unlink($taskAttachmentDir . '/' . $entry);
            }
            @rmdir($taskAttachmentDir);
        }
        if (empty($taskCommitted ?? false)) {
            $createdTaskId = '';
            $createdTaskPath = '';
            $attachmentPaths = [];
            $commitHash = '';
        }
    }
}

$activeTaskId = $createdTaskId;
if ($activeTaskId === '' && $viewTask) {
    $activeTaskId = pathinfo($viewTask['filename'], PATHINFO_FILENAME);
}
$activeTaskSource = $createdTaskId !== '' ? 'project' : ($viewTask ? (string)$viewTask['source'] : '');
$activeTaskStatus = '';
$activeTaskPath = '';
if ($createdTaskId !== '' && $error === '') {
    $activeTaskStatus = 'TODO';
    $activeTaskPath = $createdTaskPath;
} elseif ($viewTask) {
    $activeTaskStatus = $viewTask['status'];
    $activeTaskPath = $viewTask['relative_path'];
}
$activeRunStatus = $activeTaskId === '' ? 'not_started' : codexRunStatus($runsDir, $activeTaskId, $activeTaskSource === '' ? 'project' : $activeTaskSource);
$taskGitCompleted = $activeTaskId !== '';
$taskGitPushed = $activeTaskId !== '' && $taskPushWarning === '';
$editorTaskId = $viewTask ? pathinfo($viewTask['filename'], PATHINFO_FILENAME) : '';
$editorBody = ($createdTaskId === '' && $viewTask) ? taskEditableBody((string)$viewTask['body']) : '';
$editorHeading = $editorTaskId === '' ? 'Create New Task' : 'View Task: ' . $editorTaskId;
$taskMetadataPreview = $activeProjectId === '' ? '' : taskMetadataBlock($activeProjectId);
$taskDefaultTemplate = taskDefaultTemplate(taskNumber($nextNumber));
$previewDeploymentOverview = deploymentOverview('preview');
$productionDeploymentOverview = deploymentOverview('production');
$projectConfiguration = devConsoleLoadProjectConfiguration();
$projects = devConsoleProjects($projectConfiguration);
$apacheSites = devConsoleApacheSites();
$registeredProjectIds = array_flip(array_map(static fn(array $project): string => (string)($project['id'] ?? ''), $projects));
$orphanedApacheInfrastructure = projectOrphanedApacheInfrastructure($projectConfiguration, $apacheSites);
$managedApacheSites = array_values(array_filter($apacheSites, static function (array $site) use ($registeredProjectIds): bool {
    $name = (string)($site['name'] ?? '');
    return preg_match('/^dev-console-([a-z0-9]+(?:-[a-z0-9]+)*)-(production|preview)\.conf$/', $name, $matches) === 1
        && isset($registeredProjectIds[$matches[1]]);
}));
$otherApacheSites = array_values(array_filter($apacheSites, fn(array $site): bool => !str_starts_with((string)($site['name'] ?? ''), 'dev-console-')));
$apacheState = apacheState();
$projectFlash = (string)($_SESSION['project_flash'] ?? '');
unset($_SESSION['project_flash']);
$projectActionResult = is_array($_SESSION['project_action_result'] ?? null) ? $_SESSION['project_action_result'] : $projectActionResult;
unset($_SESSION['project_action_result']);
$apacheActionResult = is_array($_SESSION['apache_action_result'] ?? null) ? $_SESSION['apache_action_result'] : $apacheActionResult;
unset($_SESSION['apache_action_result']);
$githubActionResult = is_array($_SESSION['github_action_result'] ?? null) ? $_SESSION['github_action_result'] : $githubActionResult;
unset($_SESSION['github_action_result']);
$serverDiagnosticsResult = is_array($_SESSION['server_diagnostics_result'] ?? null) ? $_SESSION['server_diagnostics_result'] : $serverDiagnosticsResult;
unset($_SESSION['server_diagnostics_result']);
$serverToolOperationId = is_scalar($_GET['server_tool_operation'] ?? null) ? (string)$_GET['server_tool_operation'] : '';
if (serverToolsValidateOperationId($serverToolOperationId)) {
    try {
        $serverToolOperation = serverToolsOperationStatus($serverToolOperationId);
        if (in_array((string)($serverToolOperation['status'] ?? ''), ['completed', 'failed'], true) && is_array($serverToolOperation['result'] ?? null)) {
            $serverDiagnosticsResult = $serverToolOperation['result'];
            $serverDiagnosticsResult['output'] = (string)($serverToolOperation['log'] ?? '');
            if (is_array($serverToolOperation['diagnostics'] ?? null)) {
                $serverDiagnosticsResult['diagnostics'] = $serverToolOperation['diagnostics'];
            }
        }
    } catch (Throwable) {
        // Ignore stale or invalid operation state during page rendering.
    }
}
$serverDiagnostics = is_array($serverDiagnosticsResult['diagnostics'] ?? null) ? $serverDiagnosticsResult['diagnostics'] : serverToolsDiagnostics();
$serverContext = is_array($serverDiagnostics['context'] ?? null) ? $serverDiagnostics['context'] : [];
$serverTools = is_array($serverDiagnostics['tools'] ?? null) ? $serverDiagnostics['tools'] : [];
$projectStatuses = [];
$gitStatuses = [];
foreach ($projects as $project) {
    try {
        $projectStatuses[(string)($project['id'] ?? '')] = projectStatus($project);
    } catch (Throwable) {
        $projectStatuses[(string)($project['id'] ?? '')] = [
            'label' => 'Configuration drift',
            'production' => [],
            'preview' => [],
        ];
    }
    $gitStatuses[(string)($project['id'] ?? '')] = gitStatus($project, $githubConfiguration);
}
$activeGitStatus = $activeProjectId === '' ? [] : ($gitStatuses[$activeProjectId] ?? []);
$projectsForDisplay = devConsoleProjectsForDisplay($projectConfiguration);
$serverAddress = (string)($_SERVER['SERVER_ADDR'] ?? '');
if ($serverAddress === '' || in_array($serverAddress, ['127.0.0.1', '::1'], true)) {
    $serverAddress = 'SERVER_IP';
}
$requestedTab = (string)($_GET['tab'] ?? '');
if ($requestPath === '/' && in_array($requestedTab, ['dashboard', 'settings', 'server-management'], true)) {
    $initialTab = $requestedTab;
} else {
    $initialTab = $requestPath === '/' && (!empty($projectFormErrors) || $projectFlash !== '' || $projectActionResult !== null || $apacheActionResult !== null || $githubActionResult !== null) ? 'settings' : 'dashboard';
}
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
    .project-selector { align-items: end; display: grid; gap: 12px; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); }
    .project-selector form { margin: 0; }
    .project-selector label { margin-top: 0; }
    .project-identity { display: flex; flex-wrap: wrap; gap: 8px 14px; }
    .project-identity span { color: var(--muted); font-size: 12px; }
    .panel, .result-block { background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 6px 18px rgba(0, 83, 133, 0.07); margin-top: 14px; padding: 18px; }
    .result-block h2, .command-output h3 { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 15px; }
    .result-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
    .result-actions button { font-size: 12px; margin-top: 0; padding: 7px 10px; }
    .operation-summary { background: #f7fcfe; border: 1px solid var(--line); border-radius: 7px; display: grid; gap: 6px; list-style: none; margin: 12px 0; padding: 10px; }
    .operation-summary li { color: var(--green); font-size: 13px; font-weight: 700; }
    .tool-operation-panel { border-left: 4px solid var(--blue); }
    .tool-operation-panel.failed { border-left-color: #8a1f1f; }
    .tool-operation-grid { display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 12px 0; }
    .tool-operation-grid div { background: #f7fcfe; border: 1px solid var(--line); border-radius: 7px; padding: 9px; }
    .tool-operation-grid dt { color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .tool-operation-grid dd { font-size: 13px; font-weight: 700; margin: 4px 0 0; overflow-wrap: anywhere; }
    .tool-operation-log { max-height: 360px; min-height: 120px; }
    label { display: block; font-weight: 700; margin: 18px 0 8px; }
    textarea { background: #fcfeff; border: 1px solid #bddfeb; border-radius: 8px; box-sizing: border-box; color: #10242f; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 14px; line-height: 1.5; min-height: 390px; padding: 14px; resize: vertical; tab-size: 2; width: 100%; }
    textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    .metadata-preview { background: #f7fcfe; border: 1px solid var(--line); color: var(--muted); margin: 8px 0 14px; min-height: 78px; padding: 10px 12px; resize: none; }
    input[type="text"], input[type="password"], select { background: #fcfeff; border: 1px solid #bddfeb; border-radius: 6px; box-sizing: border-box; color: #10242f; font-size: 14px; padding: 9px 10px; width: 100%; }
    input[type="text"]:focus, input[type="password"]:focus, select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    button, .button-link { align-items: center; background: var(--blue); border: 0; border-radius: 5px; color: #fff; cursor: pointer; display: inline-flex; font-size: 15px; font-weight: 700; gap: 8px; margin-top: 16px; padding: 11px 18px; text-decoration: none; }
    button:disabled { cursor: not-allowed; opacity: 0.55; }
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
    .task-group { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px; }
    .task-group:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
    .task-group h3 { color: var(--blue); font-size: 13px; letter-spacing: 0; margin: 0 0 6px; }
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
    .page-header { align-items: flex-start; display: flex; gap: 18px; justify-content: space-between; margin-bottom: 20px; }
    .page-header h1 { margin-bottom: 8px; }
    .page-context { color: var(--muted); min-width: 220px; text-align: right; }
    .page-context strong { color: var(--ink); display: block; font-size: 16px; }
    .page-context span { font-size: 13px; }
    .tab-nav { display: flex; gap: 8px; margin-top: 18px; }
    .tab-button { background: #e8f4f8; color: var(--blue); margin: 0; padding: 9px 14px; }
    .tab-button.active { background: var(--blue); color: #fff; }
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
    .dashboard-header button { margin-top: 0; }
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
    .settings-layout { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 1.7fr) minmax(300px, 1fr); }
    .server-layout { display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr); }
    .settings-service-row { align-items: start; display: grid; gap: 14px; grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .settings-service-row > .panel { margin-top: 0; }
    .settings-table { border-collapse: collapse; font-size: 12px; width: 100%; }
    .settings-table th, .settings-table td { border-top: 1px solid var(--line); padding: 7px 6px; text-align: left; vertical-align: top; }
    .settings-table th { color: var(--muted); font-size: 11px; text-transform: uppercase; }
    .settings-table td { overflow-wrap: normal; }
    .table-scroll { overflow-x: auto; width: 100%; }
    .table-scroll .settings-table { min-width: 680px; }
    .table-scroll .settings-table.compact-sites { min-width: 560px; }
    .tool-status { white-space: nowrap; }
    .site-path, .path-value { overflow-wrap: anywhere; word-break: normal; }
    #projects, #github, #apache { scroll-margin-top: 18px; }
    #createProject { scroll-margin-top: 18px; }
    #createProject button[type="submit"] { width: 100%; }
    .subsection { border-top: 1px solid var(--line); margin-top: 18px; padding-top: 18px; }
    .project-form { display: grid; gap: 14px; }
    .project-form fieldset { border: 1px solid var(--line); border-radius: 8px; margin: 0; padding: 14px; }
    .project-form legend { color: var(--blue); font-weight: 700; padding: 0 4px; }
    .project-form label { margin-top: 12px; }
    .project-form label:first-of-type { margin-top: 4px; }
    .field-help { color: var(--muted); font-size: 12px; margin: 5px 0 0; }
    .project-list { display: grid; gap: 12px; }
    .project-item { background: #f8fbfc; border: 1px solid var(--line); border-radius: 8px; padding: 14px; }
    .project-summary { align-items: center; display: flex; flex-wrap: wrap; gap: 8px 12px; justify-content: space-between; }
    .project-summary > span:first-child { display: grid; gap: 2px; margin-right: auto; }
    .project-summary .button-link { font-size: 12px; margin-top: 0; padding: 6px 9px; }
    .project-card-toggle { font-size: 12px; margin-top: 0; padding: 6px 9px; }
    .project-details { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px; }
    .project-item-header { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; }
    .project-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .project-actions form { margin: 0; }
    .project-actions button { font-size: 13px; margin-top: 0; padding: 8px 11px; }
    .project-actions .danger { background: #a51d1d; }
    .project-actions .danger:disabled { background: #a51d1d; }
    .action-note { color: var(--muted); flex-basis: 100%; font-size: 12px; margin: 0; }
    .lifecycle-note { color: var(--muted); font-size: 12px; margin: 8px 0 0; }
    .environment-grid { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .environment-block, .generated-summary { background: #fff; border: 1px solid var(--line); border-radius: 7px; padding: 10px; }
    .environment-block h4, .generated-summary h3 { color: var(--blue); font-size: 12px; margin: 0 0 8px; }
    .generated-preview { display: grid; gap: 5px; margin: 0; }
    .generated-preview div, .apache-summary-grid div { display: grid; gap: 8px; grid-template-columns: minmax(112px, .8fr) minmax(0, 1.2fr); }
    .generated-preview dt, .apache-summary-grid dt { color: var(--muted); font-size: 11px; font-weight: 700; }
    .generated-preview dd, .apache-summary-grid dd { margin: 0; overflow-wrap: anywhere; }
    .apache-summary { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr) auto; }
    .apache-summary-grid { display: grid; gap: 6px 18px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
    .apache-summary .form-actions { align-self: start; margin: 0; }
    .settings-service-row .apache-summary, .settings-service-row .apache-summary-grid { grid-template-columns: 1fr; }
    .apache-sites { display: grid; gap: 12px; margin-top: 16px; }
    .local-hosts { background: #edf7fb; border-radius: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; overflow-wrap: anywhere; padding: 8px; }
    .success-message { background: #e9f7ef; border: 1px solid #b8dfc7; border-radius: 6px; color: var(--green); padding: 10px 12px; }
    .process-table { border-collapse: collapse; font-size: 12px; width: 100%; }
    .process-table th, .process-table td { border-top: 1px solid var(--line); padding: 6px; text-align: left; }
    .process-table th { border-top: 0; color: var(--muted); }
    .process-table td:last-child { overflow-wrap: anywhere; }
    @media (max-width: 900px) {
      .dashboard-columns { display: block; }
      .settings-layout, .settings-service-row { grid-template-columns: 1fr; }
      .apache-summary { grid-template-columns: 1fr; }
      .page-header { display: block; }
      .page-context { margin-top: 12px; text-align: left; }
      main { margin-top: 18px; }
    }
    @media (max-width: 520px) {
      .summary-grid, .resource-grid, .environment-grid, .apache-summary-grid { grid-template-columns: 1fr; }
      .generated-preview div, .apache-summary-grid div { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<main>
  <header class="page-header">
    <div>
      <h1>IOVON Dev Console</h1>
      <p class="meta">Internal task creator. Run only on <code>127.0.0.1:8090</code>.</p>
      <nav class="tab-nav" aria-label="Primary">
        <button type="button" class="tab-button <?= $initialTab === 'dashboard' ? 'active' : '' ?>" data-tab-target="dashboard">Dashboard</button>
        <button type="button" class="tab-button <?= $initialTab === 'settings' ? 'active' : '' ?>" data-tab-target="settings">Settings</button>
        <button type="button" class="tab-button <?= $initialTab === 'server-management' ? 'active' : '' ?>" data-tab-target="server-management">Server Management</button>
      </nav>
    </div>
    <div class="page-context" aria-live="polite">
      <div data-page-context="dashboard"<?= $initialTab === 'dashboard' ? '' : ' hidden' ?>>
        <strong>Dashboard</strong>
        <span>Tasks and deployments</span>
      </div>
      <div data-page-context="settings"<?= $initialTab === 'settings' ? '' : ' hidden' ?>>
        <strong>Settings</strong>
        <span>Projects and server configuration</span>
      </div>
      <div data-page-context="server-management"<?= $initialTab === 'server-management' ? '' : ' hidden' ?>>
        <strong>Server Management</strong>
        <span>Read-only server diagnostics</span>
      </div>
    </div>
  </header>

  <section id="dashboardTab" data-tab-panel="dashboard"<?= $initialTab === 'dashboard' ? '' : ' hidden' ?>>
  <?php if ($error !== ''): ?>
    <section class="panel error">
      <h2>Task creation failed</h2>
      <p><?= h($error) ?></p>
      <?php foreach ($results as $result): ?>
        <?php renderCommandResult($result); ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="panel project-selector">
    <?php if ($activeProject === null): ?>
      <div>
        <h2>Project</h2>
        <p class="meta">No Projects are registered yet. Create a Project in Settings to use the Dashboard.</p>
      </div>
    <?php else: ?>
      <form method="post" action="/?tab=dashboard" data-project-selection-form>
        <input type="hidden" name="action" value="select_active_project">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="target_tab" value="dashboard">
        <label for="activeProjectSelect">Project</label>
        <select id="activeProjectSelect" name="project_id" onchange="this.form.submit()">
          <?php foreach ($projects as $project): ?>
            <option value="<?= h((string)$project['id']) ?>"<?= (string)$project['id'] === $activeProjectId ? ' selected' : '' ?>><?= h((string)$project['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="project-identity">
        <span><strong><?= h((string)($activeProject['name'] ?? '')) ?></strong></span>
        <span>Production: <?= h(configuredDisplayValue($activeProject['production']['domain'] ?? '')) ?></span>
        <span>Preview: <?= h(configuredDisplayValue($activeProject['preview']['domain'] ?? '')) ?></span>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($activeProject !== null): ?>
  <div class="dashboard-columns">
  <div class="dashboard-column dashboard-column-left">
  <section class="panel" id="create-task">
    <h2 id="editorHeading"><?= h($editorHeading) ?></h2>
    <?php if ($editorTaskId !== ''): ?>
      <p class="meta" id="viewingTaskNote">Viewing existing task. Editing here will not update the saved task.</p>
    <?php endif; ?>
    <?php if (!$taskCreationReady): ?>
      <p class="error"><?= h($taskCreationUnavailableReason === '' ? 'Repository is not ready for task creation. Review Git status in Settings.' : $taskCreationUnavailableReason) ?></p>
    <?php endif; ?>
    <?php if ($taskPushWarning !== ''): ?>
      <p class="success-message"><?= h($taskPushWarning) ?></p>
    <?php elseif ($createdTaskId !== '' && $error === ''): ?>
      <p class="success-message">Task "<?= h($createdTaskId) ?>" created, committed locally, and synchronized with GitHub for Project "<?= h(projectMessageName($activeProject, $activeProjectId)) ?>".</p>
    <?php endif; ?>
    <p id="nextTaskNumber"><strong>Next task number:</strong> <?= h(taskNumber($nextNumber)) ?></p>
    <form method="post" enctype="multipart/form-data" id="taskForm" data-created="<?= h($createdTaskPath !== '' && $error === '' ? '1' : '0') ?>">
      <label for="task_metadata">Task metadata</label>
      <textarea id="task_metadata" class="metadata-preview" readonly rows="3" aria-readonly="true" tabindex="-1"><?= h($taskMetadataPreview) ?></textarea>
      <label for="task_body">Task markdown body</label>
      <textarea id="task_body" name="task_body" required spellcheck="false" data-default-template="<?= h($taskDefaultTemplate) ?>" placeholder="# TASK-<?= h(sprintf('%03d', $nextNumber)) ?>&#10;&#10;## Title&#10;&#10;..."><?= h($editorBody) ?></textarea>
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

      <button type="submit"<?= $taskCreationReady ? '' : ' disabled title="' . h($taskCreationUnavailableReason === '' ? 'Review Git status in Settings before creating tasks.' : $taskCreationUnavailableReason) . '"' ?>>Create Task</button>
    </form>
  </section>

      <section class="panel">
        <h2>Current Workflow</h2>
        <?php if ($activeTaskId !== '' && $error === ''): ?>
          <dl class="dashboard-list">
            <div><dt>Current Task</dt><dd><strong><?= h($activeTaskId) ?></strong></dd></div>
            <div><dt>Status</dt><dd><?= h($activeTaskStatus) ?></dd></div>
            <div><dt>Current commit</dt><dd><?= h(configuredDisplayValue($activeGitStatus['subject'] ?? '')) ?><?= ($activeGitStatus['local_commit'] ?? '') !== '' ? '<br><code title="' . h((string)$activeGitStatus['local_commit']) . '">' . h(shortSha((string)$activeGitStatus['local_commit'])) . '</code>' : '' ?></dd></div>
            <div><dt>Current branch</dt><dd><?= h(configuredDisplayValue($activeGitStatus['branch'] ?? '')) ?></dd></div>
            <div><dt>Task location</dt><dd><code><?= h($activeTaskPath) ?></code></dd></div>
          </dl>
          <ul class="workflow-steps">
            <li><span class="step-state done">Done</span><span>Task file created<?php if ($activeTaskPath !== ''): ?>: <code><?= h($activeTaskPath) ?></code><?php endif; ?></span></li>
            <li><span class="step-state <?= h($taskGitCompleted ? 'done' : 'pending') ?>"><?= h($taskGitCompleted ? 'Done' : 'Ready') ?></span><span>Task committed locally<?php if ($commitHash !== ''): ?>: <code title="<?= h($commitHash) ?>"><?= h(shortSha($commitHash)) ?></code><?php endif; ?></span></li>
            <li><span class="step-state <?= h($taskGitPushed ? 'done' : 'pending') ?>"><?= h($taskGitPushed ? 'Done' : 'Pending') ?></span><span><?= h($taskGitPushed ? 'Task synchronized with GitHub' : 'GitHub synchronization needs retry from Settings') ?></span></li>
            <li><span class="step-state <?= h(in_array($activeRunStatus, ['completed', 'failed'], true) ? 'done' : 'pending') ?>"><?= h(statusLabel($activeRunStatus)) ?></span><span>Codex run status</span></li>
          </ul>
          <div class="prompt-actions">
            <?php if ($activeTaskStatus === 'TODO' && $codexCliReady): ?>
              <button type="button" id="runCodex" data-task="<?= h($activeTaskId) ?>" data-task-source="<?= h($activeTaskSource) ?>">Run Codex</button>
            <?php else: ?>
              <button type="button" disabled title="<?= h($activeTaskStatus === 'TODO' ? 'Codex CLI is not installed on this server.' : 'Only TODO tasks can be run with Codex.') ?>">Run Codex</button>
            <?php endif; ?>
            <a class="button-link" href="?tab=dashboard&task=<?= h(rawurlencode($activeTaskId . '.md')) ?>&task_source=<?= h(rawurlencode($activeTaskSource)) ?>" target="_blank" rel="noopener">Open TASK</a>
            <?php if ($activeTaskStatus === 'TODO' && !$codexCliReady): ?>
              <span class="hint">Codex CLI is not installed on this server.</span>
            <?php endif; ?>
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
          <ul class="operation-summary">
            <li>Done: Task selected</li>
            <li><?= $taskGitCompleted ? 'Done' : 'Ready' ?>: Local commit</li>
            <li><?= $taskGitPushed ? 'Done' : 'Pending' ?>: GitHub synchronized</li>
            <li><?= h($activeRunStatus === 'not_started' ? 'Ready: Codex not started' : statusLabel($activeRunStatus) . ': Codex run') ?></li>
          </ul>
          <div class="codex-run-panel" id="codexRunPanel" data-task="<?= h($activeTaskId) ?>" data-task-source="<?= h($activeTaskSource) ?>">
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
        <span class="meta" id="dashboardUpdated">Loading...</span>
      </div>
      <div class="dashboard-grid" id="environmentDashboard" aria-live="polite"></div>
    </section>

    <section class="panel">
      <h2>Tasks</h2>
      <?php if ($legacyTasksDetected): ?>
        <p class="field-help">Legacy tasks detected. They belong to the previous global task storage and are associated with the default Project.</p>
      <?php endif; ?>
      <div class="task-list-scroll">
        <?php foreach ($taskGroups as $groupName => $groupTasks): ?>
          <section class="task-group">
            <h3><?= h($groupName) ?></h3>
            <?php if (empty($groupTasks)): ?>
              <p class="meta">No <?= h(strtolower($groupName)) ?> tasks.</p>
            <?php else: ?>
              <ul class="task-list">
                <?php foreach ($groupTasks as $task): ?>
                  <li>
                    <div class="task-row-header">
                      <span class="task-summary-label"><?= h($task['task_id']) ?></span>
                      <span class="badge <?= h(strtolower((string)$task['status'])) ?>"><?= h($task['status']) ?></span>
                    </div>
                    <?php if ($task['title'] !== ''): ?><span class="task-title"><?= h($task['title']) ?></span><?php endif; ?>
                    <div class="task-metadata">
                      <?php if ((string)($task['source'] ?? '') === 'legacy'): ?><span>Legacy storage</span><?php endif; ?>
                      <?php if ($task['commit'] !== ''): ?><span>Commit: <code title="<?= h($task['commit']) ?>"><?= h(shortSha($task['commit'])) ?></code></span><?php endif; ?>
                      <?php if ($task['milestone'] !== ''): ?><span class="milestone">Milestone: <?= h($task['milestone']) ?></span><?php endif; ?>
                      <?php if ($task['tag'] !== ''): ?><span>Tag: <?= h($task['tag']) ?></span><?php endif; ?>
                    </div>
                    <a class="button-link secondary" href="?tab=dashboard&task=<?= h(rawurlencode($task['filename'])) ?>&task_source=<?= h(rawurlencode((string)$task['source'])) ?>">Use in Workflow</a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
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
  <?php endif; ?>

  <?php if (!$viewTask && $requestedTaskFile !== ''): ?>
    <section class="panel error">
      <h2>Task not found</h2>
      <p>The requested task file does not belong to the active Project or could not be opened.</p>
    </section>
  <?php endif; ?>

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
  </section>

  <section id="settingsTab" data-tab-panel="settings"<?= $initialTab === 'settings' ? '' : ' hidden' ?>>
    <div class="settings-layout">
      <section class="panel" id="projects">
        <h2>Projects</h2>
        <?php if ($projectFlash !== ''): ?>
          <p class="success-message"><?= h($projectFlash) ?></p>
        <?php endif; ?>
        <?php if ($projectActionResult !== null && in_array((string)($projectActionResult['action'] ?? ''), ['remove_project', 'delete_project'], true)): ?>
          <?php renderOperationResult($projectActionResult, 'projectOperationLog', ((string)($projectActionResult['action'] ?? 'project-operation')) . '.log'); ?>
        <?php endif; ?>
        <?php if (empty($projects)): ?>
          <p class="meta">No projects configured yet.</p>
        <?php else: ?>
          <div class="project-list">
            <?php foreach ($projectsForDisplay as $project): ?>
              <?php
                $projectIdForCard = (string)($project['id'] ?? '');
                $projectStatus = $projectStatuses[(string)($project['id'] ?? '')] ?? projectStatus($project);
                $statusLabel = (string)$projectStatus['label'];
                $statusClass = $statusLabel === 'Ready' ? 'healthy' : ($statusLabel === 'Configuration drift' ? 'error' : 'warning');
                $lifecycleLabel = projectLifecycleLabel($project, $projectStatus);
                $isManaged = !empty($project['provisioning']['managed']);
                $isActiveProject = $projectIdForCard === $activeProjectId;
                $cardActionResult = $projectActionResult !== null
                    && (string)($projectActionResult['project_id'] ?? '') === $projectIdForCard
                    && !in_array((string)($projectActionResult['action'] ?? ''), ['remove_project', 'delete_project', 'cleanup_orphaned_project'], true)
                        ? $projectActionResult
                        : null;
                $cardOpen = $cardActionResult !== null || $projectIdForCard === (string)($projectsForDisplay[0]['id'] ?? '');
                $usesGeneratedPaths = devConsoleProjectUsesGeneratedEnvironmentPaths($project);
                $canSetUp = $statusLabel !== 'Ready' && $usesGeneratedPaths;
                $gitStatus = $gitStatuses[(string)($project['id'] ?? '')] ?? gitStatus($project, $githubConfiguration);
                $gitStatusClass = gitStatusClassName((string)$gitStatus['status']);
                $gitCanInitialize = in_array($gitStatus['status'], ['NOT INITIALIZED', 'INITIALIZATION INCOMPLETE', 'REMOTE UNAVAILABLE'], true);
                $gitCanFetch = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['INITIALIZATION INCOMPLETE', 'CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
                $gitCanPull = !empty($gitStatus['can_pull']) && in_array($gitStatus['status'], ['CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND'], true);
                $gitCanPush = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['AHEAD', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
              ?>
              <section class="project-item" data-project-card data-expanded="<?= $cardOpen ? '1' : '0' ?>">
                <div class="project-summary">
                  <span>
                    <strong><?= h(configuredDisplayValue($project['name'] ?? '')) ?></strong>
                    <span class="meta"><?= h(configuredDisplayValue($project['id'] ?? '')) ?></span>
                  </span>
                  <span class="status-pill <?= h($statusClass) ?>"><?= h($lifecycleLabel) ?></span>
                  <?php if ($isActiveProject): ?><span class="status-pill healthy">CURRENT</span><?php endif; ?>
                  <span>Production: <?= h(configuredDisplayValue($project['production']['domain'] ?? '')) ?></span>
                  <span>Preview: <?= h(configuredDisplayValue($project['preview']['domain'] ?? '')) ?></span>
                  <button type="button" class="secondary project-card-toggle" data-project-toggle aria-expanded="<?= $cardOpen ? 'true' : 'false' ?>"><?= $cardOpen ? 'Hide details' : 'Show details' ?></button>
                </div>
                <div class="project-details"<?= $cardOpen ? '' : ' hidden' ?>>
                <?php if ($cardActionResult !== null): ?>
                  <?php renderOperationResult($cardActionResult, 'projectOperationLog-' . $projectIdForCard, ((string)($cardActionResult['action'] ?? 'project-operation')) . '-' . $projectIdForCard . '.log'); ?>
                <?php endif; ?>
                <div class="project-item-header">
                  <div>
                    <h3><?= h(configuredDisplayValue($project['name'] ?? '')) ?></h3>
                    <p class="meta"><?= h(configuredDisplayValue($project['id'] ?? '')) ?></p>
                  </div>
                  <div class="project-actions">
                    <span class="status-pill <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                    <?php if ($isActiveProject): ?>
                      <span class="status-pill healthy">CURRENT</span>
                    <?php else: ?>
                      <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1" data-project-selection-form>
                        <input type="hidden" name="action" value="select_active_project">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="target_tab" value="settings">
                        <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                        <button type="submit" class="secondary">Open on Dashboard</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
                <table class="compact-table">
                  <tbody>
                    <tr><th>Lifecycle</th><td><?= h($lifecycleLabel) ?></td></tr>
                    <tr><th>Repository</th><td><?= h(configuredDisplayValue($project['repository_path'] ?? '')) ?></td></tr>
                    <tr><th>Branch</th><td><?= h(configuredDisplayValue($project['branch'] ?? '')) ?></td></tr>
                    <tr><th>Set up</th><td><?= h(configuredDisplayValue($project['provisioning']['provisioned_at'] ?? '')) ?></td></tr>
                  </tbody>
                </table>
                <div class="environment-grid">
                  <?php foreach (['production' => 'Production', 'preview' => 'Preview'] as $environmentKey => $environmentLabel): ?>
                    <?php
                      $environmentStatus = $projectStatus[$environmentKey] ?? [];
                      $environmentReady = !empty($environmentStatus['directory_exists']) && !empty($environmentStatus['vhost_exists']) && !empty($environmentStatus['site_enabled']) && !empty($environmentStatus['server_name_matches']) && !empty($environmentStatus['document_root_matches']);
                      $environmentHasAny = !empty($environmentStatus['directory_exists']) || !empty($environmentStatus['vhost_exists']) || !empty($environmentStatus['site_enabled']);
                      $environmentLabelStatus = $statusLabel === 'Configuration drift' ? 'Configuration drift' : ($environmentReady ? 'Ready' : ($environmentHasAny ? 'Incomplete' : 'Not set up'));
                    ?>
                    <section class="environment-block">
                      <h4><?= h($environmentLabel) ?></h4>
                      <dl class="dashboard-list">
                        <div><dt>Domain</dt><dd><?= h(configuredDisplayValue($project[$environmentKey]['domain'] ?? '')) ?></dd></div>
                        <div><dt>Directory</dt><dd><?= h(configuredDisplayValue($project[$environmentKey]['path'] ?? '')) ?></dd></div>
                        <div><dt>Status</dt><dd><?= h($environmentLabelStatus) ?></dd></div>
                      </dl>
                      <details class="compact-details">
                        <summary>Details</summary>
                        <table class="compact-table">
                          <tbody>
                            <tr><th>Directory</th><td><?= !empty($environmentStatus['directory_exists']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Vhost</th><td><?= !empty($environmentStatus['vhost_exists']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Enabled</th><td><?= !empty($environmentStatus['site_enabled']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>ServerName</th><td><?= !empty($environmentStatus['server_name_matches']) ? 'OK' : 'Mismatch' ?></td></tr>
                            <tr><th>DocumentRoot</th><td><?= !empty($environmentStatus['document_root_matches']) ? 'OK' : 'Mismatch' ?></td></tr>
                            <tr><th>Routing</th><td><?= h(configuredDisplayValue($environmentStatus['routing_status'] ?? '')) ?></td></tr>
                            <tr><th>Last verified</th><td><?= h(configuredDisplayValue($environmentStatus['routing_verified_at'] ?? '')) ?></td></tr>
                          </tbody>
                        </table>
                      </details>
                    </section>
                  <?php endforeach; ?>
                </div>
                <p class="field-help">Add this line to your local hosts file when DNS is not configured.</p>
                <p class="local-hosts"><?= h($serverAddress . ' ' . (string)($project['production']['domain'] ?? '') . ' ' . (string)($project['preview']['domain'] ?? '')) ?></p>
                <?php if (!$usesGeneratedPaths): ?>
                  <p class="error">This project uses custom environment paths and cannot be set up automatically. Remove it from Console and create it again.</p>
                <?php endif; ?>
                <section class="environment-block">
                  <h4>Git</h4>
                  <table class="compact-table">
                    <tbody>
                      <tr><th>Status</th><td><span class="status-pill <?= h($gitStatusClass) ?>"><?= h($gitStatus['status']) ?></span></td></tr>
                      <tr><th>Repository path</th><td><?= h(configuredDisplayValue($gitStatus['repository_path'] ?? '')) ?></td></tr>
                      <tr><th>Remote</th><td><?= h(configuredDisplayValue($gitStatus['remote_url'] ?? '')) ?></td></tr>
                      <tr><th>Branch</th><td><?= h(configuredDisplayValue($gitStatus['branch'] ?? '')) ?></td></tr>
                      <tr><th>Local commit</th><td><?= h(configuredDisplayValue($gitStatus['subject'] ?? '')) ?><?= ($gitStatus['local_commit'] ?? '') !== '' ? '<br><span class="meta">' . h(substr((string)$gitStatus['local_commit'], 0, 12)) . '</span>' : '' ?></td></tr>
                      <tr><th>Remote commit</th><td><?= h(($gitStatus['remote_commit'] ?? '') !== '' ? substr((string)$gitStatus['remote_commit'], 0, 12) : 'Not verified') ?></td></tr>
                      <tr><th>Working tree</th><td><?= h(configuredDisplayValue($gitStatus['working_tree'] ?? '')) ?></td></tr>
                      <tr><th>Ahead / Behind</th><td><?= h(($gitStatus['ahead'] ?? null) === null || ($gitStatus['behind'] ?? null) === null ? 'Not available' : ((string)$gitStatus['ahead'] . ' / ' . (string)$gitStatus['behind'])) ?></td></tr>
                      <tr><th>Last remote verification</th><td><?= h(configuredDisplayValue($gitStatus['remote_verified_at'] ?? '')) ?></td></tr>
                      <tr><th>Last fetch</th><td><?= h(configuredDisplayValue($gitStatus['last_fetch_at'] ?? '')) ?></td></tr>
                      <tr><th>Last pull</th><td><?= h(configuredDisplayValue($gitStatus['last_pull_at'] ?? '')) ?></td></tr>
                    </tbody>
                  </table>
                  <?php if (($gitStatus['diagnostic'] ?? '') !== ''): ?>
                    <p class="field-help"><?= h((string)$gitStatus['diagnostic']) ?></p>
                  <?php endif; ?>
                  <?php if ($gitStatus['status'] === 'GitHub not configured'): ?>
                    <p class="field-help">Configure GitHub in Settings to create this repository.</p>
                  <?php elseif ($gitStatus['status'] !== 'INVALID REPOSITORY'): ?>
                    <div class="project-actions">
                      <?php if ($gitCanInitialize): ?>
                        <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                          <input type="hidden" name="action" value="initialize_repository">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                          <button type="submit"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub in Settings before initializing repositories."' ?>><?= $gitStatus['status'] === 'NOT INITIALIZED' ? 'Initialize Repository' : ($gitStatus['status'] === 'REMOTE UNAVAILABLE' ? 'Retry remote verification' : 'Retry Initialization') ?></button>
                        </form>
                      <?php endif; ?>
                      <?php if ($gitCanFetch): ?>
                        <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                          <input type="hidden" name="action" value="fetch_git_repository">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                          <button type="submit" class="secondary"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub before network Git actions."' ?>>Fetch</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($gitCanPull): ?>
                        <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                          <input type="hidden" name="action" value="pull_git_repository">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                          <button type="submit" class="secondary"<?= $githubConfigured && ($gitStatus['pull_disabled_reason'] ?? '') === '' ? '' : ' disabled title="' . h((string)($gitStatus['pull_disabled_reason'] ?: 'Configure GitHub before network Git actions.')) . '"' ?>>Pull</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($gitCanPush): ?>
                        <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                          <input type="hidden" name="action" value="push_git_repository">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                          <button type="submit" class="secondary"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub before network Git actions."' ?>>Push</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </section>
                <div class="project-actions">
                  <?php if ($statusLabel !== 'Ready'): ?>
                    <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                      <input type="hidden" name="action" value="provision_project">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                      <button type="submit"<?= $canSetUp ? '' : ' disabled title="This project cannot be set up automatically with its current environment paths."' ?>>Set up</button>
                    </form>
                  <?php else: ?>
                    <p class="lifecycle-note">Configured: <?= h(configuredDisplayValue($project['provisioning']['provisioned_at'] ?? '')) ?></p>
                  <?php endif; ?>
                  <?php if ($statusLabel === 'Ready'): ?>
                    <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
                      <input type="hidden" name="action" value="verify_project_routing">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                      <button type="submit" class="secondary" title="Validate Apache configuration and check Production and Preview routing.">Reverify Routing</button>
                    </form>
                  <?php endif; ?>
                  <p class="action-note">Alternative actions: choose Remove from Console to unregister the project, or Delete Project to remove Dev Console-managed local infrastructure.</p>
                  <p class="action-note">Remove from Console removes only the project registration from Dev Console. Directories, Apache configuration, and Git repositories remain.</p>
                  <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1" onsubmit="return confirm('Remove this project from Dev Console?\nServer files will not be deleted.');">
                    <input type="hidden" name="action" value="remove_project">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                    <button type="submit" class="secondary" title="Removes only this project record.">Remove from Console</button>
                  </form>
                  <p class="action-note">Delete Project removes the project registration and local project infrastructure. The local Git repository and GitHub repository are preserved.</p>
                  <form method="post" action="/?tab=settings#projects" data-preserve-settings-scroll="1" data-delete-project-form="1" data-project-id="<?= h((string)$project['id']) ?>">
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                    <input type="hidden" name="confirm_project_id" value="">
                    <button type="submit" class="danger" title="Removes Dev Console-managed directories and Apache configuration. Preserves Git repositories."<?= $isManaged ? '' : ' disabled' ?>>Delete Project</button>
                  </form>
                </div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" id="createProject">
          <h2>Create Project</h2>
          <?php if (!empty($projectFormErrors)): ?>
            <section class="result-block error">
              <h2>Project not created</h2>
              <ul>
                <?php foreach ($projectFormErrors as $formError): ?>
                  <li><?= h((string)$formError) ?></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>
          <form method="post" class="project-form" action="/?tab=settings#projects" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="create_project">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <fieldset>
              <legend>Project</legend>
              <label for="project_name">Project name</label>
              <input id="project_name" name="project_name" type="text" required maxlength="120" placeholder="Client Website" value="<?= h($projectFormValues['project_name']) ?>">
              <p class="field-help">Used to generate the project ID and server directories.</p>
            </fieldset>

            <fieldset>
              <legend>Production</legend>
              <label for="production_domain">Domain</label>
              <input id="production_domain" name="production_domain" type="text" required maxlength="253" placeholder="example.com" value="<?= h($projectFormValues['production_domain']) ?>">
              <p class="field-help">Main hostname, without https:// or a path.</p>
            </fieldset>

            <section class="generated-summary">
              <h3>Generated configuration</h3>
              <dl class="generated-preview">
                <div><dt>Project ID</dt><dd id="projectIdPreview">-</dd></div>
                <div><dt>Repository</dt><dd id="repositoryPreview">-</dd></div>
                <div><dt>Production</dt><dd id="productionDomainPreview">-</dd></div>
                <div><dt>Preview</dt><dd id="previewDomainPreview">-</dd></div>
                <div><dt>Production directory</dt><dd id="productionDirectoryPreview">-</dd></div>
                <div><dt>Preview directory</dt><dd id="previewDirectoryPreview">-</dd></div>
              </dl>
            </section>

            <button type="submit">Create Project</button>
          </form>
      </section>

      <div class="settings-service-row">
      <section class="panel" id="github">
        <h2>GitHub</h2>
        <?php if ($githubActionResult !== null): ?>
          <?php
            $githubAction = (string)($githubActionResult['action'] ?? '');
            $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub action completed' : 'GitHub action failed';
            if ($githubAction === 'save_github_configuration') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub configuration saved' : 'GitHub configuration needs attention';
            } elseif ($githubAction === 'test_github_connection') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub connection verified' : 'GitHub connection failed';
            } elseif ($githubAction === 'remove_github_configuration') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub configuration removed' : 'GitHub configuration removal failed';
            } elseif ($githubAction === 'install_github_cli') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub CLI installed' : 'GitHub CLI installation failed';
            }
          ?>
          <section class="result-block <?= !empty($githubActionResult['success']) ? '' : 'error' ?>">
            <h2><?= h($githubActionTitle) ?></h2>
            <p><?= h((string)$githubActionResult['message']) ?></p>
            <details<?= !empty($githubActionResult['success']) ? '' : ' open' ?>>
              <summary>Show operation log</summary>
              <pre><?= h((string)($githubActionResult['output'] ?? '')) ?></pre>
            </details>
          </section>
        <?php endif; ?>

        <dl class="apache-summary-grid">
          <div><dt>Account / organization</dt><dd><?= h(configuredDisplayValue($githubConfigured ? (string)$githubConfiguration['account'] : '')) ?></dd></div>
          <div><dt>Authentication</dt><dd><?= $githubConfigured ? 'Configured' : 'Not configured' ?></dd></div>
          <div><dt>GitHub CLI</dt><dd><?= $githubCliInstalled ? 'Installed' : 'Not installed' ?></dd></div>
          <div><dt>Connection</dt><dd><?= !empty($githubConfiguration['verified']) ? 'Verified' : ($githubConfigured ? 'Not verified' : 'Not configured') ?></dd></div>
          <div><dt>Last verified</dt><dd><?= h(configuredDisplayValue($githubConfiguration['last_verified_at'] ?? '')) ?></dd></div>
          <?php if ($githubConfigured): ?>
            <div><dt>Authenticated login</dt><dd><?= h(configuredDisplayValue($githubConfiguration['authenticated_login'] ?? '')) ?></dd></div>
          <?php endif; ?>
        </dl>

        <?php if (!$githubCliInstalled): ?>
          <p class="field-help">GitHub CLI is not installed.</p>
          <form method="post" class="form-actions" action="/?tab=settings#github" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="install_github_cli">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <button type="submit" class="secondary">Install GitHub CLI</button>
          </form>
        <?php endif; ?>

        <form method="post" class="project-form subsection" action="/?tab=settings#github" data-preserve-settings-scroll="1">
          <input type="hidden" name="action" value="save_github_configuration">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <fieldset>
            <legend><?= $githubConfigured ? 'Update configuration' : 'Configure GitHub' ?></legend>
            <label for="github_account">Account or organization</label>
            <input id="github_account" name="github_account" type="text" required maxlength="39" placeholder="account-or-organization" value="<?= h($githubConfigured ? (string)$githubConfiguration['account'] : '') ?>">
            <p class="field-help">GitHub owner where Dev Console will create repositories.</p>

            <label for="github_token">Personal Access Token</label>
            <input id="github_token" name="github_token" type="password" maxlength="4096" placeholder="github_pat_..." autocomplete="new-password" spellcheck="false" autocorrect="off" autocapitalize="off"<?= $githubConfigured ? '' : ' required' ?>>
            <p class="field-help">Stored only in the server's local configuration. Leave empty to keep the current token.</p>
            <p class="field-help">Recommended token: Classic Personal Access Token with repo scope.</p>
            <p class="field-help"><a href="https://github.com/settings/tokens/new" target="_blank" rel="noopener noreferrer">Create GitHub Personal Access Token</a></p>
          </fieldset>
          <button type="submit"><?= $githubConfigured ? 'Update configuration' : 'Save and test' ?></button>
        </form>

        <?php if ($githubConfigured): ?>
          <div class="project-actions">
            <form method="post" action="/?tab=settings#github" data-preserve-settings-scroll="1">
              <input type="hidden" name="action" value="test_github_connection">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <button type="submit" class="secondary">Test connection</button>
            </form>
            <form method="post" action="/?tab=settings#github" data-preserve-settings-scroll="1" onsubmit="return confirm('Remove GitHub configuration?\nLocal and remote repositories will not be deleted.');">
              <input type="hidden" name="action" value="remove_github_configuration">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <button type="submit" class="secondary">Remove configuration</button>
            </form>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel" id="apache">
        <h2>Apache</h2>
        <?php if ($projectActionResult !== null && (string)($projectActionResult['action'] ?? '') === 'cleanup_orphaned_project'): ?>
          <?php
            $projectAction = (string)($projectActionResult['action'] ?? '');
            $projectActionTitle = !empty($projectActionResult['success']) ? 'Orphaned infrastructure cleaned up' : 'Orphaned cleanup failed';
            $operationSteps = operationSummarySteps($projectAction, $projectActionResult);
            $operationLogId = 'orphanCleanupOperationLog';
            $operationLog = (string)($projectActionResult['output'] ?? '');
            $hasOperationLog = trim($operationLog) !== '';
          ?>
          <section class="result-block <?= !empty($projectActionResult['success']) ? '' : 'error' ?>">
            <h2><?= h($projectActionTitle) ?></h2>
            <p><?= h((string)$projectActionResult['message']) ?></p>
            <?php if (!empty($operationSteps)): ?>
              <ul class="operation-summary">
                <?php foreach ($operationSteps as $step): ?>
                  <li>Done: <?= h($step) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if ($hasOperationLog): ?>
              <details<?= !empty($projectActionResult['success']) ? '' : ' open' ?>>
                <summary>Show operation log</summary>
                <div class="result-actions">
                  <button type="button" class="secondary" data-copy-log="<?= h($operationLogId) ?>">Copy Log</button>
                  <button type="button" class="secondary" data-download-log="<?= h($operationLogId) ?>" data-download-name="orphan-cleanup.log">Download Log</button>
                  <span class="hint" data-log-message="<?= h($operationLogId) ?>" aria-live="polite"></span>
                </div>
                <pre id="<?= h($operationLogId) ?>"><?= h($operationLog) ?></pre>
              </details>
            <?php endif; ?>
          </section>
        <?php endif; ?>
        <div class="apache-summary">
          <dl class="apache-summary-grid">
            <div><dt>Status</dt><dd><?= h(apacheStatusLabel($apacheState)) ?></dd></div>
            <div><dt>Version</dt><dd><?= h(configuredDisplayValue($apacheState['version'] ?? '')) ?></dd></div>
            <div><dt>Service enabled</dt><dd><?= h(apacheEnabledLabel($apacheState)) ?></dd></div>
            <div><dt>Binary path</dt><dd><?= h(configuredDisplayValue($apacheState['binary_path'] ?? '')) ?></dd></div>
          </dl>
          <form method="post" class="form-actions" action="/?tab=settings#apache" data-preserve-settings-scroll="1">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <?php if (empty($apacheState['installed'])): ?>
              <input type="hidden" name="action" value="install_apache">
              <button type="submit">Install Apache</button>
            <?php elseif (empty($apacheState['running'])): ?>
              <input type="hidden" name="action" value="start_apache">
              <button type="submit">Start Apache</button>
            <?php else: ?>
              <input type="hidden" name="action" value="restart_apache">
              <button type="submit">Restart Apache</button>
            <?php endif; ?>
          </form>
        </div>
        <?php if ($apacheActionResult !== null): ?>
          <section class="result-block <?= !empty($apacheActionResult['success']) ? '' : 'error' ?>">
            <h2><?= !empty($apacheActionResult['success']) ? 'Apache action completed' : 'Apache action failed' ?></h2>
            <p><?= h((string)$apacheActionResult['message']) ?></p>
            <details<?= !empty($apacheActionResult['success']) ? '' : ' open' ?>>
              <summary>Show command output</summary>
              <pre><?= h((string)($apacheActionResult['output'] ?? '')) ?></pre>
            </details>
          </section>
        <?php endif; ?>

        <details class="apache-sites compact-details" open>
        <summary>Managed Sites (<?= h((string)count($managedApacheSites)) ?>)</summary>
        <?php if (empty($apacheSites)): ?>
          <p class="meta">No Apache site configurations detected.</p>
        <?php elseif (empty($managedApacheSites)): ?>
          <p class="meta">No managed Apache sites found.</p>
        <?php else: ?>
          <div class="table-scroll">
          <table class="settings-table compact-sites">
            <thead>
              <tr>
                <th>Site</th>
                <th>Status</th>
                <th>ServerName</th>
                <th>DocumentRoot</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($managedApacheSites as $site): ?>
                <tr>
                  <td>
                    <strong><?= h(configuredDisplayValue($site['name'] ?? '')) ?></strong><br>
                    <span class="meta site-path"><?= h(configuredDisplayValue($site['path'] ?? '')) ?></span>
                  </td>
                  <td><span class="status-pill <?= !empty($site['enabled']) ? 'healthy' : 'warning' ?>"><?= !empty($site['enabled']) ? 'Enabled' : 'Disabled' ?></span></td>
                  <td><?= h(configuredDisplayValue($site['server_name'] ?? '')) ?></td>
                  <td class="path-value"><?= h(configuredDisplayValue($site['document_root'] ?? '')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        <?php endif; ?>
        </details>
        <details class="apache-sites compact-details"<?= !empty($orphanedApacheInfrastructure) ? ' open' : '' ?>>
          <summary>Orphaned Dev Console Infrastructure (<?= h((string)count($orphanedApacheInfrastructure)) ?>)</summary>
          <?php if (empty($orphanedApacheInfrastructure)): ?>
            <p class="meta">No orphaned Dev Console infrastructure detected.</p>
          <?php else: ?>
            <p class="field-help">These Dev Console Apache configurations no longer belong to a registered Project. Clean Up preserves local Git repositories and GitHub repositories.</p>
            <?php foreach ($orphanedApacheInfrastructure as $orphan): ?>
              <details class="project-item"<?= count($orphanedApacheInfrastructure) === 1 ? ' open' : '' ?>>
                <summary class="project-summary">
                  <span>
                    <strong><?= h((string)$orphan['project_id']) ?></strong>
                    <span class="meta">Former Project infrastructure</span>
                  </span>
                  <span>Production config: <?= empty($orphan['production']) ? 'No' : 'Yes' ?></span>
                  <span>Preview config: <?= empty($orphan['preview']) ? 'No' : 'Yes' ?></span>
                  <span>Dirs: <?= is_dir((string)$orphan['production_path']) || is_dir((string)$orphan['preview_path']) ? 'Present' : 'Not present' ?></span>
                  <span>Git: <?= is_dir((string)$orphan['git_repository_path']) ? 'Present' : 'Not present' ?></span>
                </summary>
                <table class="compact-table">
                  <tbody>
                    <tr><th>Production config</th><td><?= h(configuredDisplayValue($orphan['production']['name'] ?? '')) ?></td></tr>
                    <tr><th>Preview config</th><td><?= h(configuredDisplayValue($orphan['preview']['name'] ?? '')) ?></td></tr>
                    <tr><th>Production directory</th><td><?= is_dir((string)$orphan['production_path']) ? h((string)$orphan['production_path']) : 'Not present' ?></td></tr>
                    <tr><th>Preview directory</th><td><?= is_dir((string)$orphan['preview_path']) ? h((string)$orphan['preview_path']) : 'Not present' ?></td></tr>
                    <tr><th>Local Git repository</th><td><?= is_dir((string)$orphan['git_repository_path']) ? h((string)$orphan['git_repository_path']) . ' (preserved)' : 'Not present' ?></td></tr>
                  </tbody>
                </table>
                <p class="action-note">Clean Up removes orphaned Dev Console Apache configuration and matching Production/Preview directories only. Local Git and GitHub repositories are preserved.</p>
                <form method="post" class="project-actions" action="/?tab=settings#apache" data-preserve-settings-scroll="1" onsubmit="return confirm('Clean up orphaned Dev Console infrastructure for <?= h((string)$orphan['project_id']) ?>? Local Git and GitHub repositories will be preserved.');">
                  <input type="hidden" name="action" value="cleanup_orphaned_project">
                  <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                  <input type="hidden" name="project_id" value="<?= h((string)$orphan['project_id']) ?>">
                  <button type="submit" class="danger">Clean Up</button>
                </form>
              </details>
            <?php endforeach; ?>
          <?php endif; ?>
        </details>
        <?php if (!empty($otherApacheSites)): ?>
          <details class="apache-sites compact-details">
            <summary>Other Apache Sites (<?= h((string)count($otherApacheSites)) ?>)</summary>
            <div class="table-scroll">
            <table class="settings-table compact-sites">
              <thead>
                <tr>
                  <th>Site</th>
                  <th>Status</th>
                  <th>ServerName</th>
                  <th>DocumentRoot</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($otherApacheSites as $site): ?>
                  <tr>
                    <td>
                      <strong><?= h(configuredDisplayValue($site['name'] ?? '')) ?></strong><br>
                      <span class="meta site-path"><?= h(configuredDisplayValue($site['path'] ?? '')) ?></span>
                    </td>
                    <td><span class="status-pill <?= !empty($site['enabled']) ? 'healthy' : 'warning' ?>"><?= !empty($site['enabled']) ? 'Enabled' : 'Disabled' ?></span></td>
                    <td><?= h(configuredDisplayValue($site['server_name'] ?? '')) ?></td>
                    <td class="path-value"><?= h(configuredDisplayValue($site['document_root'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          </details>
        <?php endif; ?>
      </section>
      </div>
    </div>
  </section>

  <section id="serverManagementTab" data-tab-panel="server-management"<?= $initialTab === 'server-management' ? '' : ' hidden' ?>>
    <div class="server-layout">
      <section class="panel">
        <div class="dashboard-header">
          <h2>Server Management</h2>
          <form method="post" action="/?tab=server-management#server-tools">
            <input type="hidden" name="action" value="refresh_server_diagnostics">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <button type="submit" class="secondary">Refresh Diagnostics</button>
          </form>
        </div>
        <?php if ($serverDiagnosticsResult !== null): ?>
          <?php renderOperationResult($serverDiagnosticsResult, 'serverDiagnosticsOperationLog', 'server-diagnostics.log'); ?>
        <?php endif; ?>
        <section class="result-block tool-operation-panel" id="serverToolOperationPanel" hidden>
          <h2>Server tool operation</h2>
          <p id="serverToolOperationMessage">Starting...</p>
          <dl class="tool-operation-grid">
            <div><dt>Tool</dt><dd id="serverToolOperationTool">-</dd></div>
            <div><dt>Action</dt><dd id="serverToolOperationAction">-</dd></div>
            <div><dt>Status</dt><dd id="serverToolOperationStatus">Starting</dd></div>
            <div><dt>Elapsed</dt><dd id="serverToolOperationElapsed">0s</dd></div>
          </dl>
          <p><strong>Stage:</strong> <span id="serverToolOperationStage">Starting</span></p>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="serverToolLiveLog">Copy Log</button>
            <button type="button" class="secondary" data-download-log="serverToolLiveLog" data-download-name="server-tool-operation.log">Download Log</button>
            <span class="hint" data-log-message="serverToolLiveLog" aria-live="polite"></span>
          </div>
          <pre id="serverToolLiveLog" class="tool-operation-log">Waiting for operation log...</pre>
        </section>
      </section>

      <section class="panel" id="server-context">
        <h2>Server Context</h2>
        <dl class="apache-summary-grid">
          <div><dt>Service</dt><dd><?= h(configuredDisplayValue($serverContext['service_name'] ?? '')) ?></dd></div>
          <div><dt>User</dt><dd><?= h(configuredDisplayValue($serverContext['user'] ?? '')) ?></dd></div>
          <div><dt>Group</dt><dd><?= h(configuredDisplayValue($serverContext['group'] ?? '')) ?></dd></div>
          <div><dt>Working directory</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['working_directory'] ?? '')) ?></dd></div>
          <div><dt>PATH</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['path'] ?? '')) ?></dd></div>
          <div><dt>PHP executable</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['php_executable'] ?? '')) ?></dd></div>
        </dl>
      </section>

      <section class="panel" id="server-tools">
        <h2>Server Tools</h2>
        <?php foreach (['required' => ['Required Tools', true], 'optional' => ['Optional / Project-dependent Tools', false]] as $toolGroup => [$toolGroupLabel, $toolGroupOpen]): ?>
          <details class="compact-details"<?= $toolGroupOpen ? ' open' : '' ?>>
            <summary><?= h($toolGroupLabel) ?></summary>
            <div class="table-scroll">
              <table class="settings-table compact-sites">
                <thead>
                  <tr>
                    <th>Tool</th>
                    <th>Status</th>
                    <th>Installed version</th>
                    <th>Latest available</th>
                    <th>Executable</th>
                    <th>Installation source</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($serverTools as $toolId => $tool): ?>
                    <?php if ((string)($tool['required_group'] ?? '') !== $toolGroup) continue; ?>
                    <?php
                      $diagnosticStatus = (string)($tool['diagnostic_status'] ?? 'Diagnostic unavailable');
                      $statusClass = $diagnosticStatus === 'Installed' ? 'healthy' : ($toolGroup === 'required' ? 'error' : 'warning');
                      $actions = serverToolsAllowedActionsForTool((string)$toolId, $tool);
                    ?>
                    <tr>
                      <td>
                        <strong><?= h(configuredDisplayValue($tool['display_name'] ?? '')) ?></strong><br>
                        <span class="meta"><?= h(configuredDisplayValue($tool['requirement'] ?? '')) ?></span>
                      </td>
                      <td class="tool-status"><span class="status-pill <?= h($statusClass) ?>"><?= h($diagnosticStatus) ?></span></td>
                      <td><?= h(configuredDisplayValue($tool['version'] ?? '')) ?></td>
                      <td><?= h(configuredDisplayValue($tool['latest_version'] ?? '')) ?></td>
                      <td class="path-value"><?= h(configuredDisplayValue($tool['executable_path'] ?? '')) ?></td>
                      <td>
                        <?= h(configuredDisplayValue($tool['package_source'] ?? '')) ?><br>
                        <span class="meta"><?= h(configuredDisplayValue($tool['purpose'] ?? '')) ?></span><br>
                        <span class="meta">Service user: <?= !empty($tool['available_to_service_user']) ? 'Executable' : 'Unavailable' ?></span><br>
                        <span class="meta">Last checked: <?= h(configuredDisplayValue($tool['last_checked_at'] ?? '')) ?></span>
                      </td>
                      <td>
                        <div class="project-actions">
                          <?php foreach ($actions as $toolAction): ?>
                            <form method="post" action="/?tab=server-management#server-tools" data-server-tool-form="1" data-tool-id="<?= h((string)$toolId) ?>" data-tool-name="<?= h(configuredDisplayValue($tool['display_name'] ?? '')) ?>" data-tool-action="<?= h((string)$toolAction) ?>" data-action-label="<?= h(serverToolsActionLabel((string)$toolAction)) ?>">
                              <input type="hidden" name="action" value="server_tool_action">
                              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                              <input type="hidden" name="tool_id" value="<?= h((string)$toolId) ?>">
                              <input type="hidden" name="tool_action" value="<?= h((string)$toolAction) ?>">
                              <button type="submit" class="<?= $toolAction === 'refresh' ? 'secondary' : '' ?>"><?= h(serverToolsActionLabel((string)$toolAction)) ?></button>
                            </form>
                          <?php endforeach; ?>
                        </div>
                        <?php if ((string)$toolId === 'npm'): ?>
                          <p class="field-help">npm is installed and updated with Node.js.</p>
                        <?php elseif (in_array((string)$toolId, ['git', 'php'], true)): ?>
                          <p class="field-help">Diagnostics only.</p>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endforeach; ?>
      </section>
    </div>
  </section>

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
  const activeProjectId = <?= json_encode($activeProjectId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const draftKey = `dev-console-task-draft-${activeProjectId || 'none'}`;
  const environmentDashboard = document.getElementById('environmentDashboard');
  const dashboardUpdated = document.getElementById('dashboardUpdated');
  const activeTask = <?= json_encode($activeTaskId === '' ? 'None' : $activeTaskId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tabButtons = Array.from(document.querySelectorAll('[data-tab-target]'));
  const tabPanels = Array.from(document.querySelectorAll('[data-tab-panel]'));
  const pageContexts = Array.from(document.querySelectorAll('[data-page-context]'));
  const scrollKey = 'iovon.devConsole.scrollPosition';
  const settingsScrollKey = 'iovon.devConsole.settingsScrollPosition';
  let scrollSaveFrame = null;
  const selectedTab = () => tabButtons.find((button) => button.classList.contains('active'))?.dataset.tabTarget || 'dashboard';
  const activateTab = (target) => {
    tabButtons.forEach((button) => {
      button.classList.toggle('active', button.dataset.tabTarget === target);
    });
    tabPanels.forEach((panel) => {
      panel.hidden = panel.dataset.tabPanel !== target;
    });
    pageContexts.forEach((context) => {
      context.hidden = context.dataset.pageContext !== target;
    });
    const url = new URL(window.location.href);
    url.searchParams.set('tab', target);
    window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
  };
  tabButtons.forEach((button) => {
    button.addEventListener('click', () => activateTab(button.dataset.tabTarget || 'dashboard'));
  });
  const saveScrollPosition = () => {
    sessionStorage.setItem(scrollKey, String(window.scrollY));
  };
  const restoreScrollPosition = () => {
    const savedPosition = Number(sessionStorage.getItem(scrollKey));
    if (Number.isFinite(savedPosition) && savedPosition >= 0) {
      requestAnimationFrame(() => window.scrollTo(0, savedPosition));
    }
  };
  const restoreSettingsScrollPosition = () => {
    const settingsPanel = document.getElementById('settingsTab');
    if (!settingsPanel || settingsPanel.hidden) return false;
    const savedPosition = Number(sessionStorage.getItem(settingsScrollKey));
    sessionStorage.removeItem(settingsScrollKey);
    if (Number.isFinite(savedPosition) && savedPosition >= 0) {
      requestAnimationFrame(() => window.scrollTo(0, savedPosition));
      return true;
    }
    return false;
  };
  if (!restoreSettingsScrollPosition()) {
    restoreScrollPosition();
  }
  document.querySelectorAll('#settingsTab form[data-preserve-settings-scroll="1"]').forEach((settingsForm) => {
    settingsForm.addEventListener('submit', () => {
      sessionStorage.setItem(settingsScrollKey, String(window.scrollY));
    });
  });
  document.querySelectorAll('form[data-project-selection-form]').forEach((projectForm) => {
    projectForm.addEventListener('submit', () => {
      const target = selectedTab();
      const targetInput = projectForm.querySelector('input[name="target_tab"]');
      if (targetInput) targetInput.value = target;
      projectForm.action = target === 'settings' ? '/?tab=settings#projects' : '/?tab=dashboard';
    });
  });
  document.querySelectorAll('[data-delete-project-form="1"]').forEach((deleteForm) => {
    deleteForm.addEventListener('submit', (event) => {
      const projectId = deleteForm.dataset.projectId || '';
      const confirmation = window.prompt(`Type ${projectId} to delete Dev Console-managed directories and Apache configuration. The local Git repository and GitHub repository will be preserved.`);
      if (confirmation !== projectId) {
        event.preventDefault();
        return;
      }
      const confirmationInput = deleteForm.querySelector('input[name="confirm_project_id"]');
      if (confirmationInput) confirmationInput.value = confirmation;
    });
  });
  document.querySelectorAll('[data-copy-log]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copyLog || '');
      const message = document.querySelector(`[data-log-message="${button.dataset.copyLog || ''}"]`);
      if (!target) return;
      try {
        await navigator.clipboard.writeText(target.textContent || '');
        const original = button.textContent;
        button.textContent = 'Copied';
        if (message) message.textContent = '';
        window.setTimeout(() => { button.textContent = original; }, 1500);
      } catch (error) {
        if (message) message.textContent = 'Unable to copy log.';
      }
    });
  });
  document.querySelectorAll('[data-download-log]').forEach((button) => {
    button.addEventListener('click', () => {
      const target = document.getElementById(button.dataset.downloadLog || '');
      if (!target) return;
      const blob = new Blob([target.textContent || ''], { type: 'text/plain;charset=utf-8' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = button.dataset.downloadName || 'operation.log';
      document.body.appendChild(link);
      link.click();
      URL.revokeObjectURL(link.href);
      link.remove();
    });
  });

  const serverToolPanel = document.getElementById('serverToolOperationPanel');
  const serverToolMessage = document.getElementById('serverToolOperationMessage');
  const serverToolTool = document.getElementById('serverToolOperationTool');
  const serverToolAction = document.getElementById('serverToolOperationAction');
  const serverToolStatus = document.getElementById('serverToolOperationStatus');
  const serverToolElapsed = document.getElementById('serverToolOperationElapsed');
  const serverToolStage = document.getElementById('serverToolOperationStage');
  const serverToolLog = document.getElementById('serverToolLiveLog');
  const serverToolForms = Array.from(document.querySelectorAll('[data-server-tool-form="1"]'));
  const formatElapsed = (seconds) => {
    const value = Math.max(0, Number(seconds) || 0);
    const minutes = Math.floor(value / 60);
    const remainder = value % 60;
    return minutes > 0 ? `${minutes}m ${remainder}s` : `${remainder}s`;
  };
  const setServerToolButtons = (toolId, disabled) => {
    serverToolForms.forEach((form) => {
      if (form.dataset.toolId !== toolId) return;
      form.querySelectorAll('button').forEach((button) => { button.disabled = disabled; });
    });
  };
  const showServerToolOperation = (operation) => {
    if (!serverToolPanel) return;
    serverToolPanel.hidden = false;
    serverToolPanel.classList.toggle('failed', operation.status === 'failed');
    if (serverToolTool) serverToolTool.textContent = operation.tool_name || '-';
    if (serverToolAction) serverToolAction.textContent = operation.action_label || operation.tool_action || '-';
    if (serverToolStatus) serverToolStatus.textContent = String(operation.status || 'starting').replace(/^\w/, (letter) => letter.toUpperCase());
    if (serverToolStage) serverToolStage.textContent = operation.stage || 'Starting';
    if (serverToolElapsed) serverToolElapsed.textContent = formatElapsed(operation.elapsed_seconds || 0);
    if (serverToolMessage) serverToolMessage.textContent = operation.message || 'Operation running.';
    if (serverToolLog) {
      const nextLog = operation.log && operation.log.trim() !== '' ? operation.log : 'Waiting for operation log...';
      serverToolLog.textContent = nextLog;
      serverToolLog.scrollTop = serverToolLog.scrollHeight;
    }
  };
  const pollServerToolOperation = (operationId, toolId) => {
    let poll = null;
    const update = async () => {
      const response = await fetch(`?action=server-tool-operation-status&id=${encodeURIComponent(operationId)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to read server tool operation.');
      showServerToolOperation(payload.operation);
      if (payload.operation.status === 'completed' || payload.operation.status === 'failed') {
        clearInterval(poll);
        setServerToolButtons(toolId, false);
        window.setTimeout(() => {
          window.location.href = `/?tab=server-management&server_tool_operation=${encodeURIComponent(operationId)}#server-tools`;
        }, 900);
      }
    };
    update().catch((error) => {
      clearInterval(poll);
      setServerToolButtons(toolId, false);
      if (serverToolPanel) serverToolPanel.hidden = false;
      if (serverToolPanel) serverToolPanel.classList.add('failed');
      if (serverToolMessage) serverToolMessage.textContent = error.message;
      if (serverToolStatus) serverToolStatus.textContent = 'Failed';
      if (serverToolStage) serverToolStage.textContent = 'Failed';
    });
    poll = window.setInterval(() => update().catch((error) => {
      clearInterval(poll);
      setServerToolButtons(toolId, false);
      if (serverToolMessage) serverToolMessage.textContent = error.message;
      if (serverToolStatus) serverToolStatus.textContent = 'Failed';
    }), 1000);
  };
  serverToolForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const toolId = form.dataset.toolId || '';
      setServerToolButtons(toolId, true);
      showServerToolOperation({
        tool_name: form.dataset.toolName || toolId,
        tool_action: form.dataset.toolAction || '',
        action_label: form.dataset.actionLabel || '',
        status: 'starting',
        stage: 'Starting',
        elapsed_seconds: 0,
        message: 'Starting server tool operation.',
        log: '',
      });
      try {
        const response = await fetch('/?tab=server-management#server-tools', { method: 'POST', body: new FormData(form) });
        const payload = await response.json();
        if (!payload.ok) throw new Error(payload.error || 'Unable to start server tool operation.');
        showServerToolOperation(payload.operation);
        pollServerToolOperation(payload.operation.id, toolId);
      } catch (error) {
        setServerToolButtons(toolId, false);
        if (serverToolPanel) serverToolPanel.classList.add('failed');
        if (serverToolMessage) serverToolMessage.textContent = error.message;
        if (serverToolStatus) serverToolStatus.textContent = 'Failed';
        if (serverToolStage) serverToolStage.textContent = 'Failed';
      }
    });
  });

  const projectNameInput = document.getElementById('project_name');
  const productionDomainInput = document.getElementById('production_domain');
  const projectIdPreview = document.getElementById('projectIdPreview');
  const repositoryPreview = document.getElementById('repositoryPreview');
  const productionDomainPreview = document.getElementById('productionDomainPreview');
  const previewDomainPreview = document.getElementById('previewDomainPreview');
  const productionDirectoryPreview = document.getElementById('productionDirectoryPreview');
  const previewDirectoryPreview = document.getElementById('previewDirectoryPreview');
  const slugFromProjectName = (value) => String(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
  const normalizeDomainPreview = (value) => String(value)
    .trim()
    .replace(/^https?:\/\//i, '')
    .split(/[/:?#]/)[0]
    .replace(/\.$/, '')
    .toLowerCase();
  const setPreviewText = (element, value) => {
    if (element) element.textContent = value || '-';
  };
  const updateGeneratedPreview = () => {
    const slug = slugFromProjectName(projectNameInput?.value || '');
    const domain = normalizeDomainPreview(productionDomainInput?.value || '');
    setPreviewText(projectIdPreview, slug);
    setPreviewText(repositoryPreview, slug ? `/var/www/git/${slug}` : '');
    setPreviewText(productionDomainPreview, domain);
    setPreviewText(previewDomainPreview, domain ? `preview.${domain}` : '');
    setPreviewText(productionDirectoryPreview, slug ? `/var/www/projects/${slug}/production` : '');
    setPreviewText(previewDirectoryPreview, slug ? `/var/www/projects/${slug}/preview` : '');
  };
  projectNameInput?.addEventListener('input', updateGeneratedPreview);
  productionDomainInput?.addEventListener('input', updateGeneratedPreview);
  updateGeneratedPreview();
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
      const readiness = {
        PHP: 'Required',
        Composer: 'Optional / project-dependent',
        'Node.js': 'Optional / project-dependent',
        npm: 'Optional / project-dependent',
        Git: 'Required',
        'Codex CLI': 'Required for Run Codex',
      };
      const softwareRows = softwareNames.map((name) => `<tr><th>${dashboardEscape(name)}</th><td>${dashboardEscape(data.software[name])}<br><span class="meta">${dashboardEscape(readiness[name] || '')}</span></td></tr>`).join('');
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
    textarea.value = localStorage.getItem(draftKey) || textarea.dataset.defaultTemplate || '';
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
      textarea.value = textarea.dataset.defaultTemplate || '';
      textarea.focus();
    }
    if (draftStatus) {
      draftStatus.textContent = 'Draft cleared.';
    }
    if (editorHeading) {
      editorHeading.textContent = 'Create New Task';
    }
    viewingTaskNote?.remove();
    window.history.replaceState(null, '', `${window.location.pathname}?tab=dashboard`);
  });

  document.querySelectorAll('[data-project-card]').forEach((card) => {
    const toggle = card.querySelector('[data-project-toggle]');
    const details = card.querySelector('.project-details');
    const setExpanded = (expanded) => {
      card.dataset.expanded = expanded ? '1' : '0';
      if (details) details.hidden = !expanded;
      if (toggle) {
        toggle.textContent = expanded ? 'Hide details' : 'Show details';
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
    };
    setExpanded(card.dataset.expanded === '1');
    toggle?.addEventListener('click', () => {
      setExpanded(card.dataset.expanded !== '1');
    });
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
  const taskSourceForCodexPanel = () => codexRunPanel?.dataset.taskSource || 'project';

  const updateCodexLog = async (scrollToBottom = false) => {
    if (!codexConsole || !codexRunPanel) {
      return;
    }
    const response = await fetch(`?action=codex-log&task=${encodeURIComponent(taskForCodexPanel())}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`, { cache: 'no-store' });
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
    const response = await fetch(`?action=codex-status&task=${encodeURIComponent(taskForCodexPanel())}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`, { cache: 'no-store' });
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
              window.location.href = `?tab=dashboard&task=${encodeURIComponent(`${taskForCodexPanel()}.md`)}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`;
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
    formData.set('task_source', runCodex.dataset.taskSource || 'project');

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
