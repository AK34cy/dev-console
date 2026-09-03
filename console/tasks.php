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
    $frontMatter = taskSystemMetadata($body);
    foreach (['title', 'milestone', 'tag', 'notes', 'commit'] as $field) {
        if (isset($frontMatter[$field]) && is_scalar($frontMatter[$field]) && trim((string)$frontMatter[$field]) !== '') {
            $metadata[$field] = trim((string)$frontMatter[$field]);
        }
    }
    if ($metadata['title'] !== '') {
        return $metadata;
    }

    $metadata['title'] = taskTitleFromBody($body);

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
    return taskParseDocument($body)['metadata'];
}

function taskParseDocument(string $body): array
{
    $metadata = [];
    if (preg_match('/\A---\s*\R(.*?)\R---\s*(?:\R|$)/s', $body, $matches) !== 1) {
        return ['metadata' => [], 'body' => $body, 'has_front_matter' => false];
    }

    $currentList = '';
    $currentItem = null;
    foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
        if (preg_match('/^([a-z0-9_]+):\s*(.*?)\s*$/i', $line, $lineMatches) === 1) {
            if ($currentList !== '' && $currentItem !== null) {
                $metadata[$currentList][] = $currentItem;
                $currentItem = null;
            }
            $key = strtolower($lineMatches[1]);
            $value = trim($lineMatches[2]);
            if ($value === '') {
                $metadata[$key] = [];
                $currentList = $key;
            } elseif ($value === '[]') {
                $metadata[$key] = [];
                $currentList = '';
            } else {
                $metadata[$key] = taskYamlValue($value);
                $currentList = '';
            }
            continue;
        }
        if ($currentList !== '' && preg_match('/^\s*-\s+([a-z0-9_]+):\s*(.*?)\s*$/i', $line, $itemMatches) === 1) {
            if ($currentItem !== null) {
                $metadata[$currentList][] = $currentItem;
            }
            $currentItem = [strtolower($itemMatches[1]) => taskYamlValue(trim($itemMatches[2]))];
            continue;
        }
        if ($currentList !== '' && $currentItem !== null && preg_match('/^\s+([a-z0-9_]+):\s*(.*?)\s*$/i', $line, $fieldMatches) === 1) {
            $currentItem[strtolower($fieldMatches[1])] = taskYamlValue(trim($fieldMatches[2]));
        }
    }
    if ($currentList !== '' && $currentItem !== null) {
        $metadata[$currentList][] = $currentItem;
    }
    if (isset($metadata['attachments']) && is_array($metadata['attachments'])) {
        $metadata['attachments'] = array_values(array_filter(array_map('taskNormalizeAttachmentRecord', $metadata['attachments'])));
    }

    return [
        'metadata' => $metadata,
        'body' => preg_replace('/\A---\s*\R.*?\R---\s*(?:\R|$)/s', '', $body, 1) ?? $body,
        'has_front_matter' => true,
    ];
}

function taskYamlValue(string $value)
{
    $value = trim($value);
    if ($value === 'null') {
        return null;
    }
    if (preg_match('/^\d+$/', $value) === 1) {
        return (int)$value;
    }
    if (
        (str_starts_with($value, '"') && str_ends_with($value, '"'))
        || (str_starts_with($value, "'") && str_ends_with($value, "'"))
    ) {
        return stripcslashes(substr($value, 1, -1));
    }

    return $value;
}

function taskYamlScalar($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $text = (string)$value;
    if (preg_match('/^[A-Za-z0-9_.\/:@ -]+$/', $text) === 1 && !str_starts_with($text, ' ') && !str_ends_with($text, ' ')) {
        return $text;
    }

    return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $text) . '"';
}

function taskProjectId(string $body): string
{
    $metadata = taskSystemMetadata($body);
    if (isset($metadata['project_id']) && projectSafeId((string)$metadata['project_id'])) {
        return (string)$metadata['project_id'];
    }

    return '';
}

function taskBodyWithProjectMetadata(string $body, string $projectId, string $taskId = '', string $status = 'TODO', array $attachments = [], array $existingMetadata = []): string
{
    $editableBody = rtrim(taskEditableBody($body));
    $metadata = $existingMetadata;
    $metadata['task_id'] = $taskId;
    $metadata['project_id'] = $projectId;
    $metadata['title'] = taskTitleFromBody($editableBody);
    $metadata['status'] = $status;
    $metadata['created_at'] = (string)($metadata['created_at'] ?? date('c'));
    $metadata['updated_at'] = date('c');
    $metadata['attachments'] = array_values(array_filter(array_map('taskNormalizeAttachmentRecord', $attachments)));

    return taskMetadataBlockFromArray($metadata) . "\n\n" . $editableBody;
}

function taskMetadataBlock(string $projectId, string $taskId = '', string $body = '', string $status = 'TODO', array $attachments = []): string
{
    return taskMetadataBlockFromArray([
        'task_id' => $taskId,
        'project_id' => $projectId,
        'title' => taskTitleFromBody($body),
        'status' => $status,
        'created_at' => '',
        'updated_at' => '',
        'attachments' => $attachments,
    ]);
}

function taskMetadataBlockFromArray(array $metadata): string
{
    $orderedFields = ['task_id', 'project_id', 'title', 'status', 'created_at', 'updated_at'];
    $lines = ['---'];
    foreach ($orderedFields as $field) {
        $lines[] = $field . ': ' . taskYamlScalar($metadata[$field] ?? '');
    }
    $attachments = is_array($metadata['attachments'] ?? null) ? array_values(array_filter(array_map('taskNormalizeAttachmentRecord', $metadata['attachments']))) : [];
    if (empty($attachments)) {
        $lines[] = 'attachments: []';
    } else {
        $lines[] = 'attachments:';
        foreach ($attachments as $attachment) {
            $lines[] = '- name: ' . taskYamlScalar($attachment['name']);
            $lines[] = '  path: ' . taskYamlScalar($attachment['path']);
            $lines[] = '  mime: ' . taskYamlScalar($attachment['mime']);
            $lines[] = '  size: ' . taskYamlScalar($attachment['size']);
        }
    }
    foreach ($metadata as $field => $value) {
        if (in_array((string)$field, array_merge($orderedFields, ['attachments']), true) || is_array($value)) {
            continue;
        }
        $lines[] = (string)$field . ': ' . taskYamlScalar($value);
    }
    $lines[] = '---';

    return implode("\n", $lines);
}

function taskEditableBody(string $body): string
{
    return taskParseDocument($body)['body'];
}

function taskDefaultTemplate(string $taskId): string
{
    return "# {$taskId}\n\n## Title\n\n...\n";
}

function taskTitleFromBody(string $body): string
{
    if (preg_match('/^##\s+Title\s*$\R+\s*(.+)$/mi', $body, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/^Title:\s*\R+\s*(.+)$/mi', $body, $matches)) {
        return trim($matches[1]);
    }
    if (preg_match('/^#\s+(?:TASK-\d{3}\s*[-:]\s*)?(.+)$/mi', $body, $matches)) {
        return trim($matches[1]);
    }

    return '';
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
        'dropped' => $projectRoot . '/TASKS/DROPPED',
        'attachments' => taskAttachmentRoot($projectRoot),
        'legacy_attachments' => taskLegacyAttachmentRoot($projectRoot),
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
                'dropped' => $legacyRoot . '/TASKS/DROPPED',
                'attachments' => taskAttachmentRoot($legacyRoot),
                'legacy_attachments' => taskLegacyAttachmentRoot($legacyRoot),
                'allow_implicit_ownership' => true,
            ];
        }
    }

    return $contexts;
}

function taskAttachmentRoot(string $projectRoot): string
{
    return rtrim($projectRoot, '/') . '/TASKS/attachments';
}

function taskLegacyAttachmentRoot(string $projectRoot): string
{
    return rtrim($projectRoot, '/') . '/TASKS/ATTACHMENTS';
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
    $history = deploymentCommand(['git', '-C', $repoRoot, 'log', '--format=@@%H%x09%s', '--name-only', '--', 'TASKS/TODO', 'TASKS/IN PROGRESS', 'TASKS/DONE', 'TASKS/DROPPED']);
    if ($history['exit_code'] === 0) {
        $currentCommit = '';
        foreach (preg_split('/\R/', $history['stdout']) ?: [] as $line) {
            if (str_starts_with($line, '@@')) {
                [$currentCommit] = explode("\t", substr($line, 2), 2);
            } elseif ($currentCommit !== '' && preg_match('#^TASKS/(?:TODO|IN PROGRESS|DONE|DROPPED)/(TASK-\d{3})\.md$#', $line, $matches)) {
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

    foreach (['TODO' => (string)$context['todo'], 'IN PROGRESS' => (string)$context['in_progress'], 'DONE' => (string)$context['done'], 'DROPPED' => (string)($context['dropped'] ?? '')] as $status => $directory) {
        if ($directory === '') {
            continue;
        }
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
            $systemMetadata = taskSystemMetadata($body);
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
                'metadata' => $systemMetadata,
                'attachments' => is_array($systemMetadata['attachments'] ?? null) ? $systemMetadata['attachments'] : [],
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
        'DROPPED' => [],
    ];

    foreach ($tasks as $task) {
        $status = (string)($task['status'] ?? '');
        if ($status === 'DROPPED') {
            $groups['DROPPED'][] = $task;
            continue;
        }
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
            return ((int)$right['number']) <=> ((int)$left['number']);
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
        foreach (['TODO' => (string)$context['todo'], 'IN PROGRESS' => (string)$context['in_progress'], 'DONE' => (string)$context['done'], 'DROPPED' => (string)($context['dropped'] ?? '')] as $status => $directory) {
            if ($directory === '') {
                continue;
            }
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
                'metadata' => taskSystemMetadata($body),
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

function taskNormalizeAttachmentRecord($record): ?array
{
    if (!is_array($record)) {
        return null;
    }
    $name = sanitizeUploadName((string)($record['name'] ?? basename((string)($record['path'] ?? ''))));
    $path = str_replace('\\', '/', trim((string)($record['path'] ?? '')));
    $mime = trim((string)($record['mime'] ?? 'application/octet-stream'));
    $size = max(0, (int)($record['size'] ?? 0));
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
        return null;
    }

    return [
        'name' => $name,
        'path' => $path,
        'mime' => $mime === '' ? 'application/octet-stream' : $mime,
        'size' => $size,
    ];
}

function taskAttachmentMime(string $path): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    return 'application/octet-stream';
}

function taskAttachmentRecordFromFile(string $root, string $absolutePath): array
{
    return [
        'name' => basename($absolutePath),
        'path' => relativePath($root . '/TASKS', $absolutePath),
        'mime' => taskAttachmentMime($absolutePath),
        'size' => filesize($absolutePath) ?: 0,
    ];
}

function taskAttachmentAbsolutePath(array $context, array $record): string
{
    return rtrim((string)$context['root'], '/') . '/TASKS/' . ltrim((string)$record['path'], '/');
}

function taskAttachmentRecordsForTask(array $contexts, string $projectId, string $taskId, string $source): array
{
    $task = findTaskForView($contexts, $projectId, $taskId . '.md', $source);
    if ($task === null) {
        return [];
    }
    $context = taskContextForSource($contexts, $source);
    if ($context === null) {
        return [];
    }
    $metadata = taskSystemMetadata((string)$task['body']);
    $records = is_array($metadata['attachments'] ?? null) ? array_values(array_filter(array_map('taskNormalizeAttachmentRecord', $metadata['attachments']))) : [];
    $seen = [];
    $validRecords = [];
    foreach ($records as $record) {
        $absolutePath = taskAttachmentAbsolutePath($context, $record);
        if (!is_file($absolutePath)) {
            continue;
        }
        $record['size'] = filesize($absolutePath) ?: (int)$record['size'];
        $record['mime'] = taskAttachmentMime($absolutePath);
        $seen[(string)$record['path']] = true;
        $validRecords[] = $record;
    }

    foreach ([(string)$context['attachments'], (string)($context['legacy_attachments'] ?? '')] as $directoryRoot) {
        if ($directoryRoot === '') {
            continue;
        }
        $directory = rtrim($directoryRoot, '/') . '/' . $taskId;
        if (!is_dir($directory)) {
            continue;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_file($directory . '/' . $entry)) {
                continue;
            }
            $record = taskAttachmentRecordFromFile((string)$context['root'], $directory . '/' . $entry);
            if (!isset($seen[$record['path']])) {
                $validRecords[] = $record;
                $seen[$record['path']] = true;
            }
        }
    }

    usort($validRecords, static fn(array $left, array $right): int => strnatcasecmp((string)$left['name'], (string)$right['name']));
    return $validRecords;
}

function taskAttachmentRecordByName(array $contexts, string $projectId, string $taskId, string $source, string $name): ?array
{
    foreach (taskAttachmentRecordsForTask($contexts, $projectId, $taskId, $source) as $record) {
        if ((string)$record['name'] === $name) {
            return $record;
        }
    }

    return null;
}

function taskCanRemoveAttachments(array $task, string $runsDir): bool
{
    $status = (string)($task['status'] ?? '');
    if ($status !== 'TODO') {
        return false;
    }
    $runStatus = codexRunStatus($runsDir, (string)$task['task_id'], (string)($task['source'] ?? 'project'));

    return !in_array($runStatus, ['queued', 'running', 'completed'], true);
}

function formatTaskAttachmentSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return rtrim(rtrim(number_format($bytes / 1048576, 1), '0'), '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    }

    return $bytes . ' B';
}

function attachmentFilesForTask(array $contexts, string $projectId, string $taskId, string $source): array
{
    return array_map(static fn(array $record): string => (string)$record['name'], taskAttachmentRecordsForTask($contexts, $projectId, $taskId, $source));
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
    $records = taskAttachmentRecordsForTask($contexts, $projectId, $taskId, $source);
    if (empty($records)) {
        return '';
    }

    $paths = array_map(static fn(array $record): string => 'TASKS/' . (string)$record['path'], $records);

    return "The following read-only task attachments are available inside this Project repository:\n\n- " . implode("\n- ", $paths) . "\n\nUse them where appropriate, but do not modify files under TASKS/.";
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
- Task lifecycle is managed exclusively by IOVON Dev Console.
- Treat TASKS/ and its attachments as read-only input.
- Do not edit, move, rename, delete, stage, or update status/metadata of files under TASKS/.
- Do not mark the task done; Dev Console will move it to DONE after validation, commit, and push.
- Complete the requested development task.
- Modify only project/source files required by the task.
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
    $resultPath = runFile($runsDir, $taskId, 'result.json', $source);
    if ($status === 'failed') {
        $attemptSuffix = 'failed-' . gmdate('Ymd-His');
        if (is_file($logPath)) {
            @copy($logPath, runFile($runsDir, $taskId, $attemptSuffix . '.log', $source));
        }
        if (is_file($resultPath)) {
            @copy($resultPath, runFile($runsDir, $taskId, $attemptSuffix . '.result.json', $source));
        }
    }
    file_put_contents($promptPath, codexPromptForTask($contexts, $projectId, $taskId, $source));
    file_put_contents($statusPath, 'queued');
    file_put_contents($resultPath, json_encode([
        'task_id' => $taskId,
        'status' => 'Queued',
        'commit' => '',
        'files_changed' => 0,
        'validation' => 'Pending',
        'duration_seconds' => 0,
        'summary' => $status === 'failed' ? 'Codex retry queued.' : 'Codex run queued.',
        'started_at' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
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

    $readiness = gitRepositoryReadiness($project, $githubConfiguration);
    if (empty($readiness['ready'])) {
        return ['ready' => false, 'reason' => (string)($readiness['reason'] ?? 'Repository is not ready. Review Git status in Projects.')];
    }
    $status = is_array($readiness['git_status'] ?? null) ? $readiness['git_status'] : [];
    if (in_array((string)($status['status'] ?? ''), ['AHEAD', 'AHEAD / BEHIND', 'CHANGES PRESENT'], true)) {
        return ['ready' => false, 'reason' => 'Repository synchronization is pending. Use Push in Projects before creating another task.'];
    }
    if ((string)($status['status'] ?? '') !== 'CONNECTED') {
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

    $codex = function_exists('serverToolsResolveCodexCommand') ? serverToolsResolveCodexCommand() : serverToolsFindExecutable('codex', serverToolsDefaultPath());
    if ($codex === '' || !function_exists('serverToolsCodexAuthStatus')) {
        return false;
    }
    $status = serverToolsCodexAuthStatus(null, $codex);
    $authenticated = (string)($status['state'] ?? '') === 'authenticated';
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
