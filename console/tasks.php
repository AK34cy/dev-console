<?php

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
        'in_progress' => $projectRoot . '/TASKS/IN PROGRESS',
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
                'in_progress' => $legacyRoot . '/TASKS/IN PROGRESS',
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
    $history = deploymentCommand(['git', '-C', $repoRoot, 'log', '--format=@@%H%x09%s', '--name-only', '--', 'TASKS/TODO', 'TASKS/IN PROGRESS', 'TASKS/DONE']);
    if ($history['exit_code'] === 0) {
        $currentCommit = '';
        foreach (preg_split('/\R/', $history['stdout']) ?: [] as $line) {
            if (str_starts_with($line, '@@')) {
                [$currentCommit] = explode("\t", substr($line, 2), 2);
            } elseif ($currentCommit !== '' && preg_match('#^TASKS/(?:TODO|IN PROGRESS|DONE)/(TASK-\d{3})\.md$#', $line, $matches)) {
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

    foreach (['TODO' => (string)$context['todo'], 'IN PROGRESS' => (string)$context['in_progress'], 'DONE' => (string)$context['done']] as $status => $directory) {
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
        if ($status === 'IN PROGRESS') {
            $groups['IN PROGRESS'][] = $task;
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
        foreach (['TODO' => (string)$context['todo'], 'IN PROGRESS' => (string)$context['in_progress'], 'DONE' => (string)$context['done']] as $status => $directory) {
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

    $paths = array_map(static fn(string $file): string => "TASKS/ATTACHMENTS/{$taskId}/{$file}", $files);

    return "The following attachments are available inside this Project repository:\n\n- " . implode("\n- ", $paths) . "\n\nUse them where appropriate.";
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

function runnableTaskForId(array $contexts, string $projectId, string $taskId, string $source = ''): ?array
{
    if (!isTaskId($taskId)) {
        return null;
    }

    $task = findTaskForView($contexts, $projectId, $taskId . '.md', $source);
    return $task !== null && in_array((string)$task['status'], ['TODO', 'IN PROGRESS'], true) ? $task : null;
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
    $task = runnableTaskForId($contexts, $projectId, $taskId, $source);
    if ($task === null) {
        throw new RuntimeException('Task file is not runnable.');
    }
    $body = (string)($task['body'] ?? file_get_contents((string)$task['path']));

    $prompt = "Project ID: {$projectId}

Project repository:
" . (string)$task['root'] . "

Task ID: {$taskId}

Task file:
" . (string)$task['relative_path'] . "

Task body:
```markdown
{$body}
```

Instructions:
- Work only in the current Project repository.
- Do not modify Dev Console itself.
- Do not modify other repositories.
- Do not deploy Preview or Production.
- Do not use sudo.
- Do not modify system services.
- Complete the requested development task.
- Run appropriate validation.
- Report what changed.";

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

    if (runnableTaskForId($contexts, $projectId, $taskId, $source) === null) {
        throw new RuntimeException('Task file is not runnable.');
    }

    if (codexProjectHasActiveRun($runsDir)) {
        throw new RuntimeException('Codex is already running for this Project.');
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

function codexProjectHasActiveRun(string $runsDir): bool
{
    if (!is_dir($runsDir)) {
        return false;
    }
    foreach (glob(rtrim($runsDir, '/') . '/*.status') ?: [] as $statusPath) {
        $status = trim((string)@file_get_contents($statusPath));
        if (in_array($status, ['queued', 'running'], true)) {
            return true;
        }
    }

    return false;
}

function codexRunResult(string $runsDir, string $taskId, string $source = 'project'): array
{
    $path = runFile($runsDir, $taskId, 'result.json', $source);
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;

    return is_array($decoded) ? $decoded : [];
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
        return ['ready' => false, 'reason' => 'Repository is not initialized. Initialize Repository in Projects before creating tasks.'];
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        return ['ready' => false, 'reason' => 'Repository is not initialized. Initialize Repository in Projects before creating tasks.'];
    }
    if ((string)($project['git']['bootstrap_status'] ?? '') !== 'ready' || empty($project['git']['connected'])) {
        return ['ready' => false, 'reason' => 'Repository initialization is incomplete. Use Retry Initialization in Projects before creating tasks.'];
    }
    if ($error = gitAssertConnectedRepository($project, $githubConfiguration)) {
        return ['ready' => false, 'reason' => $error];
    }
    $status = gitStatus($project, $githubConfiguration);
    if (in_array((string)$status['status'], ['INITIALIZATION INCOMPLETE', 'NOT INITIALIZED', 'INVALID REPOSITORY', 'REMOTE UNAVAILABLE'], true)) {
        return ['ready' => false, 'reason' => 'Repository initialization is incomplete. Use Retry Initialization in Projects before creating tasks.'];
    }
    if (in_array((string)$status['status'], ['AHEAD', 'AHEAD / BEHIND', 'CHANGES PRESENT'], true)) {
        return ['ready' => false, 'reason' => 'Repository synchronization is pending. Use Push in Projects before creating another task.'];
    }
    if ((string)$status['status'] !== 'CONNECTED') {
        return ['ready' => false, 'reason' => 'Repository is not ready for task creation. Review Git status in Projects.'];
    }

    return ['ready' => true, 'reason' => ''];
}

function codexCliInstalled(): bool
{
    $diagnostics = serverToolsDiagnostics(false);
    return !empty($diagnostics['tools']['codex']['available_to_service_user']);
}

function codexCliAuthenticated(): bool
{
    $cachePath = DEPLOY_STATE_DIR . '/codex-auth-status.json';
    if (is_file($cachePath) && time() - (filemtime($cachePath) ?: 0) < 60) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && array_key_exists('authenticated', $cached)) {
            return !empty($cached['authenticated']);
        }
    }

    $codex = serverToolsFindExecutable('codex', serverToolsDefaultPath());
    if ($codex === '') {
        return false;
    }
    $doctor = processRunCommand([$codex, 'doctor', '--json'], [
        'cwd' => devConsoleRepositoryRoot(),
        'inherit_env' => true,
        'timeout' => 8,
    ]);
    $decoded = json_decode((string)$doctor['stdout'], true);
    $authenticated = is_array($decoded) && (string)($decoded['checks']['auth.credentials']['status'] ?? '') === 'ok';
    if (!is_dir(DEPLOY_STATE_DIR)) @mkdir(DEPLOY_STATE_DIR, 0750, true);
    @file_put_contents($cachePath, json_encode(['authenticated' => $authenticated, 'checked_at' => date('c')], JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $authenticated;
}

function extractCommitHash(string $output): string
{
    if (preg_match('/\[[^\s]+\s+([0-9a-f]{7,40})\]/', $output, $matches)) {
        return $matches[1];
    }

    return '';
}
