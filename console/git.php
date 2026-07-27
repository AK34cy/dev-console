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

function gitRunFixedCommand(array $arguments, int $timeoutSeconds = 10, array $environment = [], bool $inheritEnvironment = true): array
{
    return processRunCommand($arguments, [
        'cwd' => devConsoleRepositoryRoot(),
        'env' => ['GIT_TERMINAL_PROMPT' => '0'] + $environment,
        'inherit_env' => $inheritEnvironment,
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

function gitGhInstalled(): bool
{
    $result = gitRunFixedCommand(['gh', '--version'], 5, [], false);
    return !empty($result['success']);
}

function gitGithubRunCommand(array $arguments, array $githubConfiguration, int $timeoutSeconds = 20): array
{
    return processRunCommand($arguments, [
        'cwd' => devConsoleRepositoryRoot(),
        'env' => [
            'GH_TOKEN' => (string)($githubConfiguration['token'] ?? ''),
            'GIT_TERMINAL_PROMPT' => '0',
        ],
        'inherit_env' => false,
        'timeout' => $timeoutSeconds,
    ]);
}

function gitGithubInstallCli(): array
{
    $log = [];
    if (gitGhInstalled()) {
        return gitActionResult(true, 'GitHub CLI is already installed.');
    }

    foreach ([['/usr/bin/apt-get', 'update'], ['/usr/bin/apt-get', 'install', '-y', 'gh']] as $arguments) {
        $result = processRunCommand($arguments, [
            'cwd' => '/',
            'env' => ['DEBIAN_FRONTEND' => 'noninteractive'],
            'timeout' => 120,
        ]);
        gitAppendCommandLog($log, $result);
        if ($result['exit_code'] !== 0) {
            return gitActionResult(false, 'Unable to install GitHub CLI using the system package repositories.', $log);
        }
    }

    return gitGhInstalled()
        ? gitActionResult(true, 'GitHub CLI installed.', $log)
        : gitActionResult(false, 'GitHub CLI installation completed but gh is still unavailable.', $log);
}

function gitGithubSaveConfiguration(array $input): array
{
    $existing = devConsoleLoadGithubConfiguration();
    $validation = devConsoleBuildGithubConfiguration($input, $existing);
    if (!$validation['valid']) {
        return gitActionResult(false, implode(' ', $validation['errors']));
    }
    if (!devConsoleSaveGithubConfiguration($validation['configuration'])) {
        return gitActionResult(false, 'Unable to save GitHub configuration.');
    }
    if (!gitGhInstalled()) {
        return gitActionResult(true, 'GitHub configuration saved. Install GitHub CLI to test the connection.');
    }

    $test = gitGithubTestConnection();
    if (!empty($test['success'])) {
        $test['message'] = 'GitHub configuration saved and connection verified.';
        return $test;
    }

    return gitActionResult(false, 'GitHub configuration saved, but connection test failed: ' . (string)$test['message'], [(string)($test['output'] ?? '')]);
}

function gitGithubRemoveConfiguration(): array
{
    if (!devConsoleRemoveGithubConfiguration()) {
        return gitActionResult(false, 'Unable to remove GitHub configuration.');
    }

    return gitActionResult(true, 'GitHub configuration removed. Local repositories and remote GitHub repositories were not changed.');
}

function gitGithubTestConnection(): array
{
    $github = devConsoleLoadGithubConfiguration();
    $log = [];
    if (!devConsoleGithubConfigured($github)) {
        return gitActionResult(false, 'GitHub is not configured.');
    }
    if (!gitGhInstalled()) {
        return gitActionResult(false, 'GitHub CLI is not installed.');
    }

    $user = gitGithubRunCommand(['gh', 'api', 'user'], $github, 20);
    gitAppendCommandLog($log, $user);
    if ($user['exit_code'] !== 0) {
        return gitActionResult(false, 'GitHub authentication failed.', $log);
    }
    $decodedUser = json_decode((string)$user['stdout'], true);
    $authenticatedLogin = is_array($decodedUser) && is_scalar($decodedUser['login'] ?? null) ? (string)$decodedUser['login'] : '';
    if ($authenticatedLogin === '') {
        return gitActionResult(false, 'GitHub authentication response did not include a login.', $log);
    }

    $account = (string)$github['account'];
    if (strcasecmp($account, $authenticatedLogin) !== 0) {
        $organization = gitGithubRunCommand(['gh', 'api', 'orgs/' . $account], $github, 20);
        gitAppendCommandLog($log, $organization);
        if ($organization['exit_code'] !== 0) {
            return gitActionResult(false, 'The configured account is not the authenticated user or an accessible organization.', $log);
        }
    }

    $github['verified'] = true;
    $github['last_verified_at'] = date('c');
    $github['authenticated_login'] = $authenticatedLogin;
    if (!devConsoleSaveGithubConfiguration($github)) {
        return gitActionResult(false, 'GitHub connection verified, but the verification metadata could not be saved.', $log);
    }

    return gitActionResult(true, 'GitHub connection verified for ' . $account . ' as ' . $authenticatedLogin . '.', $log);
}

function gitProjectRepositoryPath(array $project): string
{
    return (string)($project['repository_path'] ?? '');
}

function gitExpectedRepositoryPath(array $project): string
{
    return devConsoleGeneratedRepositoryPath((string)($project['id'] ?? ''));
}

function gitExpectedRemoteUrl(array $project, ?array $githubConfiguration = null): string
{
    $owner = (string)($project['git']['repository_owner'] ?? '');
    if ($owner === '' && $githubConfiguration !== null) {
        $owner = (string)($githubConfiguration['account'] ?? '');
    }
    $name = (string)($project['git']['repository_name'] ?? '');
    if ($name === '') {
        $name = (string)($project['id'] ?? '');
    }
    if ($owner === '' || $name === '') {
        return '';
    }

    return 'https://github.com/' . $owner . '/' . $name . '.git';
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

function gitStatus(array $project, ?array $githubConfiguration = null): array
{
    $path = gitProjectRepositoryPath($project);
    $expectedRemote = gitExpectedRemoteUrl($project, $githubConfiguration);
    $githubConfigured = $githubConfiguration !== null && devConsoleGithubConfigured($githubConfiguration);
    $status = [
        'status' => $githubConfigured ? 'Repository not created' : 'GitHub not configured',
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

    if (!file_exists($path)) {
        return $status;
    }
    if (is_link($path) || !is_dir($path)) {
        $status['status'] = 'Invalid repository';
        return $status;
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        $status['status'] = gitDirectoryIsEmpty($path) ? $status['status'] : 'Invalid repository';
        return $status;
    }

    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5);
    if ($remote['exit_code'] !== 0) {
        $status['status'] = 'Remote unavailable';
        return $status;
    }
    $actualRemote = trim((string)$remote['stdout']);
    $status['remote_url'] = $actualRemote;
    if ($expectedRemote === '' || $actualRemote !== $expectedRemote || (string)($project['git']['remote_url'] ?? '') !== $expectedRemote) {
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

function gitEnsureRepositoryBase(array &$log): bool
{
    if (!is_dir(DEV_CONSOLE_GIT_BASE)) {
        return @mkdir(DEV_CONSOLE_GIT_BASE, 0755, true) || is_dir(DEV_CONSOLE_GIT_BASE);
    }
    if (is_link(DEV_CONSOLE_GIT_BASE)) {
        $log[] = 'Git repository base directory must not be a symlink.';
        return false;
    }

    return true;
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

function gitCreateAskpassHelper(string $token): array
{
    $directory = sys_get_temp_dir() . '/dev-console-git-' . bin2hex(random_bytes(8));
    if (!@mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create temporary Git credential helper.');
    }
    $script = $directory . '/askpass.sh';
    $contents = "#!/bin/sh\ncase \"$1\" in\n  *Username*) printf '%s\\n' 'x-access-token' ;;\n  *) printf '%s\\n' \"$GH_TOKEN\" ;;\nesac\n";
    if (@file_put_contents($script, $contents, LOCK_EX) === false) {
        @rmdir($directory);
        throw new RuntimeException('Unable to write temporary Git credential helper.');
    }
    @chmod($script, 0700);

    return ['directory' => $directory, 'script' => $script];
}

function gitRemoveAskpassHelper(array $helper): void
{
    $script = (string)($helper['script'] ?? '');
    $directory = (string)($helper['directory'] ?? '');
    if ($script !== '') @unlink($script);
    if ($directory !== '') @rmdir($directory);
}

function gitRunAuthenticatedCommand(array $arguments, array $githubConfiguration, int $timeoutSeconds = 120): array
{
    // Git reads the token from this action's environment through a temporary askpass helper; the helper file contains no secret and is removed immediately.
    $helper = gitCreateAskpassHelper((string)$githubConfiguration['token']);
    try {
        return gitRunFixedCommand($arguments, $timeoutSeconds, [
            'GH_TOKEN' => (string)$githubConfiguration['token'],
            'GIT_ASKPASS' => (string)$helper['script'],
        ], false);
    } finally {
        gitRemoveAskpassHelper($helper);
    }
}

function gitRepositoryFullName(array $project, array $githubConfiguration): string
{
    return (string)$githubConfiguration['account'] . '/' . (string)$project['id'];
}

function gitCreateInitialRepositoryCommit(array $project, array $githubConfiguration, string $remoteUrl, array &$log): void
{
    $temporary = sys_get_temp_dir() . '/dev-console-repo-bootstrap-' . bin2hex(random_bytes(8));
    if (!@mkdir($temporary, 0700)) {
        throw new RuntimeException('Unable to create temporary repository bootstrap directory.');
    }
    try {
        $readme = '# ' . (string)$project['name'] . "\n\nCreated by IOVON Dev Console.\n";
        if (@file_put_contents($temporary . '/README.md', $readme, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write bootstrap README.');
        }
        foreach ([
            ['git', '-C', $temporary, 'init', '--initial-branch=main'],
            ['git', '-C', $temporary, 'config', 'user.name', 'IOVON Dev Console'],
            ['git', '-C', $temporary, 'config', 'user.email', 'iovon@iovon.com'],
            ['git', '-C', $temporary, 'add', 'README.md'],
            ['git', '-C', $temporary, 'commit', '-m', 'Initial commit'],
            ['git', '-C', $temporary, 'remote', 'add', 'origin', $remoteUrl],
        ] as $arguments) {
            $result = gitRunFixedCommand($arguments, 20, [], false);
            gitAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException('Unable to initialize GitHub repository.');
            }
        }
        $push = gitRunAuthenticatedCommand(['git', '-C', $temporary, 'push', '-u', 'origin', 'main'], $githubConfiguration, 120);
        gitAppendCommandLog($log, $push);
        if ($push['exit_code'] !== 0) {
            throw new RuntimeException('Unable to push initial repository contents.');
        }
    } finally {
        gitRemoveDirectoryCreatedDuringAction($temporary);
    }
}

function gitCreateProjectRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $github = devConsoleLoadGithubConfiguration();
    $log = [];
    if (!devConsoleGithubConfigured($github) || empty($github['verified'])) {
        return gitActionResult(false, 'GitHub must be configured and verified before creating repositories.');
    }
    if (!gitGhInstalled()) {
        return gitActionResult(false, 'GitHub CLI is not installed.');
    }
    if ($error = gitValidateProjectRepositoryPath($project)) return gitActionResult(false, $error);

    $path = gitProjectRepositoryPath($project);
    if (file_exists($path)) {
        return gitActionResult(false, 'Local repository path already exists and cannot be bootstrapped automatically.');
    }
    if (!gitEnsureRepositoryBase($log)) {
        return gitActionResult(false, 'Unable to prepare Git repository base directory.', $log);
    }

    $fullName = gitRepositoryFullName($project, $github);
    $remoteUrl = gitExpectedRemoteUrl($project, $github);
    $view = gitGithubRunCommand(['gh', 'repo', 'view', $fullName, '--json', 'nameWithOwner,url,sshUrl,isPrivate'], $github, 20);
    gitAppendCommandLog($log, $view);
    if ($view['exit_code'] === 0) {
        return gitActionResult(false, 'A repository with this name already exists in the configured GitHub account.', $log);
    }

    $visibilityFlag = (string)$github['default_visibility'] === 'public' ? '--public' : '--private';
    $create = gitGithubRunCommand(['gh', 'repo', 'create', $fullName, $visibilityFlag, '--confirm'], $github, 60);
    gitAppendCommandLog($log, $create);
    if ($create['exit_code'] !== 0) {
        return gitActionResult(false, 'GitHub repository creation failed.', $log);
    }

    $createdRemote = true;
    $createdLocal = false;
    try {
        gitCreateInitialRepositoryCommit($project, $github, $remoteUrl, $log);
        $createdLocal = true;
        $clone = gitRunAuthenticatedCommand(['git', 'clone', '--branch', 'main', '--single-branch', $remoteUrl, $path], $github, 120);
        gitAppendCommandLog($log, $clone);
        if ($clone['exit_code'] !== 0) {
            throw new RuntimeException('Git clone failed.');
        }
        $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
        gitAppendCommandLog($log, $inside);
        if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
            throw new RuntimeException('Cloned repository verification failed.');
        }
        $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
        gitAppendCommandLog($log, $remote);
        if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== $remoteUrl) {
            throw new RuntimeException('Cloned repository origin does not match the GitHub repository.');
        }
        if (gitCurrentBranch($path) !== 'main') {
            throw new RuntimeException('Cloned repository branch is not main.');
        }

        $project = gitSetMetadata($project, [
            'provider' => 'github',
            'repository_owner' => (string)$github['account'],
            'repository_name' => (string)$project['id'],
            'remote_url' => $remoteUrl,
            'connected' => true,
            'connected_at' => date('c'),
            'created_at' => date('c'),
        ]);
        if (!gitSaveProject($configuration, $project)) {
            throw new RuntimeException('Unable to save Git metadata.');
        }
    } catch (Throwable $exception) {
        if ($createdLocal && is_dir($path) && !is_link($path)) {
            gitRemoveDirectoryCreatedDuringAction($path);
            $log[] = 'Removed local repository created during this action.';
        }
        if ($createdRemote) {
            $delete = gitGithubRunCommand(['gh', 'repo', 'delete', $fullName, '--yes'], $github, 60);
            gitAppendCommandLog($log, $delete);
            if ($delete['exit_code'] !== 0) {
                return gitActionResult(false, $exception->getMessage() . ' GitHub repository rollback failed; manual cleanup may be required.', $log);
            }
            $log[] = 'Deleted GitHub repository created during this action.';
        }
        return gitActionResult(false, $exception->getMessage(), $log);
    }

    return gitActionResult(true, 'GitHub repository created and cloned.', $log);
}

function gitAssertConnectedRepository(array $project, array $githubConfiguration): ?string
{
    if (!devConsoleGithubConfigured($githubConfiguration)) return 'GitHub is not configured.';
    if (!gitProjectConnected($project)) return 'Repository has not been created.';
    if ($error = gitValidateProjectRepositoryPath($project)) return $error;
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) return 'Repository is missing or invalid.';
    $expectedRemote = gitExpectedRemoteUrl($project, $githubConfiguration);
    if ((string)($project['git']['remote_url'] ?? '') !== $expectedRemote) return 'Repository metadata does not match the configured GitHub account.';
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') return 'Repository is not a valid Git working tree.';
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== $expectedRemote) return 'Repository origin no longer matches the GitHub repository.';
    return null;
}

function gitFetchRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $github = devConsoleLoadGithubConfiguration();
    if ($error = gitAssertConnectedRepository($project, $github)) return gitActionResult(false, $error);
    $log = [];
    $fetch = gitRunAuthenticatedCommand(['git', '-C', gitProjectRepositoryPath($project), 'fetch', '--prune', 'origin'], $github, 120);
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
    $github = devConsoleLoadGithubConfiguration();
    if ($error = gitAssertConnectedRepository($project, $github)) return gitActionResult(false, $error);
    $path = gitProjectRepositoryPath($project);
    if (gitCurrentBranch($path) !== (string)$project['branch']) return gitActionResult(false, 'Current branch does not match project branch.');
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5, [], false);
    if ($porcelain['exit_code'] !== 0 || trim((string)$porcelain['stdout']) !== '') return gitActionResult(false, 'Working tree must be clean before pulling.');

    $log = [];
    $fetch = gitRunAuthenticatedCommand(['git', '-C', $path, 'fetch', '--prune', 'origin'], $github, 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) return gitActionResult(false, 'Git fetch failed.', $log);
    $pull = gitRunAuthenticatedCommand(['git', '-C', $path, 'pull', '--ff-only', 'origin', (string)$project['branch']], $github, 120);
    gitAppendCommandLog($log, $pull);
    if ($pull['exit_code'] !== 0) return gitActionResult(false, 'Git pull failed.', $log);
    $project = gitSetMetadata($project, ['last_fetch_at' => date('c'), 'last_pull_at' => date('c')]);
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to save Git pull metadata.', $log);
    return gitActionResult(true, 'Git pull completed.', $log);
}
