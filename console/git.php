<?php

require_once __DIR__ . '/process.php';

const DEV_CONSOLE_GIT_BASE = '/var/www/git';

function gitActionResult(bool $success, string $message, array $log = []): array
{
    return [
        'success' => $success,
        'message' => $message,
        'output' => implode("\n", $log),
    ];
}

function gitRunFixedCommand(array $arguments, int $timeoutSeconds = 10): array
{
    return processRunCommand($arguments, [
        'cwd' => devConsoleRepositoryRoot(),
        'env' => ['GIT_TERMINAL_PROMPT' => '0'],
        'timeout' => $timeoutSeconds,
    ]);
}

function gitAppendCommandLog(array &$log, array $result): void
{
    $log[] = '$ ' . (string)$result['command_display'];
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (!empty($result['timed_out'])) {
        $log[] = 'Command timed out.';
    }
    if (trim((string)$result['output']) !== '') {
        $log[] = trim((string)$result['output']);
    }
}

function gitValidateRemoteUrl(string $url): array
{
    $errors = [];
    if ($url === '' || strlen($url) > 512 || devConsoleHasControlCharacters($url) || preg_match('/\s/', $url) === 1) {
        $errors[] = 'Remote URL is empty, too long, or contains whitespace/control characters.';
    }
    if (str_starts_with($url, '-')) {
        $errors[] = 'Remote URL must not begin with a command-line option.';
    }
    if (str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
        $errors[] = 'Local filesystem paths are not supported.';
    }
    if (preg_match('/^git@[A-Za-z0-9.-]+:[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\.git$/', $url) === 1) {
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme'])) {
        $errors[] = 'Remote URL must use git@host:owner/repository.git, ssh://, or https:// syntax.';
        return ['valid' => false, 'errors' => array_values(array_unique($errors))];
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['ssh', 'https'], true)) {
        $errors[] = 'Remote URL scheme is not supported.';
    }
    if ($scheme === 'https' && (isset($parts['user']) || isset($parts['pass']))) {
        $errors[] = 'HTTPS URLs with embedded credentials are not supported.';
    }
    if (empty($parts['host']) || empty($parts['path']) || !str_ends_with((string)$parts['path'], '.git')) {
        $errors[] = 'Remote URL must include a host and .git repository path.';
    }

    return ['valid' => empty($errors), 'errors' => array_values(array_unique($errors))];
}

function gitProjectRepositoryPath(array $project): string
{
    return (string)($project['repository_path'] ?? '');
}

function gitExpectedRepositoryPath(array $project): string
{
    return devConsoleGeneratedRepositoryPath((string)($project['id'] ?? ''));
}

function gitValidateProjectRepositoryPath(array $project): ?string
{
    $path = gitProjectRepositoryPath($project);
    if ($path !== gitExpectedRepositoryPath($project)) {
        return 'Repository path must match ' . gitExpectedRepositoryPath($project) . '.';
    }
    if (!str_starts_with($path . '/', DEV_CONSOLE_GIT_BASE . '/')) {
        return 'Repository path must be inside ' . DEV_CONSOLE_GIT_BASE . '.';
    }
    if (is_link($path)) {
        return 'Repository path must not be a symlink.';
    }

    return null;
}

function gitDirectoryIsEmpty(string $path): bool
{
    return is_dir($path) && count(array_diff(scandir($path) ?: [], ['.', '..'])) === 0;
}

function gitProjectConnected(array $project): bool
{
    return !empty($project['git']['connected']) && (string)($project['git']['remote_url'] ?? '') !== '';
}

function gitDirectoryPath(string $repositoryPath): string
{
    $gitPath = rtrim($repositoryPath, '/') . '/.git';
    if (is_dir($gitPath)) {
        return $gitPath;
    }
    if (is_file($gitPath)) {
        $contents = (string)@file_get_contents($gitPath);
        if (preg_match('/^gitdir:\s*(.+)$/m', $contents, $matches) === 1) {
            $gitDir = trim($matches[1]);
            return str_starts_with($gitDir, '/') ? $gitDir : rtrim($repositoryPath, '/') . '/' . $gitDir;
        }
    }

    return $gitPath;
}

function gitCurrentBranch(string $repositoryPath): string
{
    $headPath = gitDirectoryPath($repositoryPath) . '/HEAD';
    if (!is_file($headPath)) {
        return '';
    }

    $head = trim((string)@file_get_contents($headPath));
    return preg_match('~^ref:\s+refs/heads/(.+)$~', $head, $matches) === 1 ? trim($matches[1]) : '';
}

function gitStatus(array $project): array
{
    $path = gitProjectRepositoryPath($project);
    $status = [
        'status' => 'Not connected',
        'repository_path' => $path,
        'remote_url' => (string)($project['git']['remote_url'] ?? ''),
        'branch' => (string)($project['branch'] ?? ''),
        'commit' => '',
        'subject' => '',
        'working_tree' => 'Unknown',
        'ahead' => null,
        'behind' => null,
        'last_fetch_at' => (string)($project['git']['last_fetch_at'] ?? ''),
        'last_pull_at' => (string)($project['git']['last_pull_at'] ?? ''),
    ];

    if (!gitProjectConnected($project)) {
        return $status;
    }
    if (!file_exists($path)) {
        $status['status'] = 'Repository missing';
        return $status;
    }
    if (is_link($path) || !is_dir($path)) {
        $status['status'] = 'Invalid repository';
        return $status;
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        $status['status'] = 'Invalid repository';
        return $status;
    }

    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== (string)$project['git']['remote_url']) {
        $status['status'] = 'Remote unavailable';
        return $status;
    }

    $commit = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--short', 'HEAD'], 5);
    if ($commit['exit_code'] === 0) $status['commit'] = trim((string)$commit['stdout']);
    $subject = gitRunFixedCommand(['git', '-C', $path, 'log', '-1', '--pretty=%s'], 5);
    if ($subject['exit_code'] === 0) $status['subject'] = trim((string)$subject['stdout']);
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5);
    $dirty = $porcelain['exit_code'] === 0 && trim((string)$porcelain['stdout']) !== '';
    $status['working_tree'] = $dirty ? 'Dirty' : 'Clean';
    $status['status'] = $dirty ? 'Changes present' : 'Ready';

    $counts = gitRunFixedCommand(['git', '-C', $path, 'rev-list', '--left-right', '--count', 'HEAD...origin/' . (string)$project['branch']], 5);
    if ($counts['exit_code'] === 0 && preg_match('/^(\d+)\s+(\d+)$/', trim((string)$counts['stdout']), $matches) === 1) {
        $status['ahead'] = (int)$matches[1];
        $status['behind'] = (int)$matches[2];
    }

    return $status;
}

function gitSetMetadata(array $project, array $metadata): array
{
    $project['git'] = array_merge(devConsoleEmptyProject()['git'], $project['git'] ?? [], $metadata);
    return $project;
}

function gitSaveProject(array $configuration, array $project): bool
{
    return devConsoleSaveProjectConfiguration(devConsoleReplaceProject($configuration, $project));
}

function gitConnectRepository(array $configuration, string $projectId, string $remoteUrl): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $log = [];
    $validation = gitValidateRemoteUrl($remoteUrl);
    if (!$validation['valid']) return gitActionResult(false, implode(' ', $validation['errors']));
    if ($error = gitValidateProjectRepositoryPath($project)) return gitActionResult(false, $error);

    $gitVersion = gitRunFixedCommand(['git', '--version'], 5);
    gitAppendCommandLog($log, $gitVersion);
    if (!empty($gitVersion['timed_out'])) return gitActionResult(false, 'Unable to determine whether Git is installed.', $log);
    if ($gitVersion['exit_code'] !== 0) return gitActionResult(false, trim((string)$gitVersion['stdout']) !== '' ? 'Unable to determine whether Git is installed.' : 'Git is not installed.', $log);

    $path = gitProjectRepositoryPath($project);
    $createdBase = false;
    $createdRepositoryPath = false;
    if (!is_dir(DEV_CONSOLE_GIT_BASE)) {
        if (!@mkdir(DEV_CONSOLE_GIT_BASE, 0755, true) && !is_dir(DEV_CONSOLE_GIT_BASE)) {
            return gitActionResult(false, 'Unable to create Git repository base directory.', $log);
        }
        $createdBase = true;
    } elseif (is_link(DEV_CONSOLE_GIT_BASE)) {
        return gitActionResult(false, 'Git repository base directory must not be a symlink.', $log);
    }
    if (file_exists($path)) {
        if (!is_dir($path) || is_link($path) || !gitDirectoryIsEmpty($path)) {
            return gitActionResult(false, 'Repository directory already contains data and cannot be connected automatically.', $log);
        }
    } else {
        $createdRepositoryPath = true;
    }

    $clone = gitRunFixedCommand(['git', 'clone', '--branch', (string)$project['branch'], '--single-branch', $remoteUrl, $path], 120);
    gitAppendCommandLog($log, $clone);
    if ($clone['exit_code'] !== 0) {
        if ($createdRepositoryPath && is_dir($path) && !is_link($path)) {
            gitRemoveDirectoryCreatedDuringAction($path);
        }
        if ($createdBase && is_dir(DEV_CONSOLE_GIT_BASE) && gitDirectoryIsEmpty(DEV_CONSOLE_GIT_BASE)) {
            @rmdir(DEV_CONSOLE_GIT_BASE);
        }
        return gitActionResult(false, 'Git clone failed.', $log);
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5);
    gitAppendCommandLog($log, $inside);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        if ($createdRepositoryPath) gitRemoveDirectoryCreatedDuringAction($path);
        return gitActionResult(false, 'Cloned repository verification failed.', $log);
    }
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5);
    gitAppendCommandLog($log, $remote);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== $remoteUrl) {
        if ($createdRepositoryPath) gitRemoveDirectoryCreatedDuringAction($path);
        return gitActionResult(false, 'Cloned repository origin does not match submitted remote.', $log);
    }
    if (gitCurrentBranch($path) !== (string)$project['branch']) {
        if ($createdRepositoryPath) gitRemoveDirectoryCreatedDuringAction($path);
        return gitActionResult(false, 'Cloned repository branch does not match project branch.', $log);
    }

    $project = gitSetMetadata($project, [
        'remote_url' => $remoteUrl,
        'connected' => true,
        'connected_at' => date('c'),
    ]);
    if (!gitSaveProject($configuration, $project)) {
        if ($createdRepositoryPath) gitRemoveDirectoryCreatedDuringAction($path);
        return gitActionResult(false, 'Unable to save Git metadata.', $log);
    }

    return gitActionResult(true, 'Git repository connected.', $log);
}

function gitRemoveDirectoryCreatedDuringAction(string $path): void
{
    if (!is_dir($path) || is_link($path)) return;
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        if (is_link($child) || is_file($child)) {
            @unlink($child);
        } elseif (is_dir($child)) {
            gitRemoveDirectoryCreatedDuringAction($child);
        }
    }
    @rmdir($path);
}

function gitAssertConnectedRepository(array $project): ?string
{
    if (!gitProjectConnected($project)) return 'Project is not connected to a Git repository.';
    if ($error = gitValidateProjectRepositoryPath($project)) return $error;
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) return 'Repository is missing or invalid.';
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') return 'Repository is not a valid Git working tree.';
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== (string)$project['git']['remote_url']) return 'Repository origin no longer matches the stored remote URL.';
    return null;
}

function gitFetchRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    if ($error = gitAssertConnectedRepository($project)) return gitActionResult(false, $error);
    $log = [];
    $fetch = gitRunFixedCommand(['git', '-C', gitProjectRepositoryPath($project), 'fetch', '--prune', 'origin'], 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) return gitActionResult(false, 'Git fetch failed.', $log);
    $project = gitSetMetadata($project, ['last_fetch_at' => date('c')]);
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to save Git fetch metadata.', $log);
    return gitActionResult(true, 'Git fetch completed.', $log);
}

function gitPullRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    if ($error = gitAssertConnectedRepository($project)) return gitActionResult(false, $error);
    $path = gitProjectRepositoryPath($project);
    if (gitCurrentBranch($path) !== (string)$project['branch']) return gitActionResult(false, 'Current branch does not match project branch.');
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5);
    if ($porcelain['exit_code'] !== 0 || trim((string)$porcelain['stdout']) !== '') return gitActionResult(false, 'Working tree must be clean before pulling.');

    $log = [];
    $fetch = gitRunFixedCommand(['git', '-C', $path, 'fetch', '--prune', 'origin'], 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) return gitActionResult(false, 'Git fetch failed.', $log);
    $pull = gitRunFixedCommand(['git', '-C', $path, 'pull', '--ff-only', 'origin', (string)$project['branch']], 120);
    gitAppendCommandLog($log, $pull);
    if ($pull['exit_code'] !== 0) return gitActionResult(false, 'Git pull failed.', $log);
    $project = gitSetMetadata($project, ['last_fetch_at' => date('c'), 'last_pull_at' => date('c')]);
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to save Git pull metadata.', $log);
    return gitActionResult(true, 'Git pull completed.', $log);
}

function gitRemoveConnection(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $project['git'] = devConsoleEmptyProject()['git'];
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to remove Git metadata.');
    return gitActionResult(true, 'Git connection removed. Repository files remain on the server.', ['Git repository preserved: ' . gitProjectRepositoryPath($project)]);
}
