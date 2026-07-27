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

function gitAppendCommandSummary(array &$log, array $result): void
{
    $log[] = '$ ' . (string)$result['command_display'];
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (!empty($result['timed_out'])) {
        $log[] = 'Command timed out.';
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

function gitGithubVerifyConnection(bool $persist): array
{
    $github = devConsoleLoadGithubConfiguration();
    $log = [];
    if (!devConsoleGithubConfigured($github)) {
        return gitActionResult(false, 'GitHub is not configured.');
    }
    if (!gitGhInstalled()) {
        return gitActionResult(false, 'GitHub CLI is not installed.');
    }

    $log[] = 'GitHub CLI detected';
    $user = gitGithubRunCommand(['gh', 'api', 'user'], $github, 20);
    gitAppendCommandSummary($log, $user);
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
        gitAppendCommandSummary($log, $organization);
        if ($organization['exit_code'] !== 0) {
            return gitActionResult(false, 'The configured account is not the authenticated user or an accessible organization.', $log);
        }
    }

    $log[] = 'Authentication successful';
    $log[] = 'Login: ' . $authenticatedLogin;
    $log[] = 'Account: ' . $account;

    if ($persist) {
        $github['verified'] = true;
        $github['last_verified_at'] = date('c');
        $github['authenticated_login'] = $authenticatedLogin;
        if (!devConsoleSaveGithubConfiguration($github)) {
            return gitActionResult(false, 'GitHub connection verified, but the verification metadata could not be saved.', $log);
        }
    }

    return gitActionResult(true, 'GitHub connection verified for ' . $account . ' as ' . $authenticatedLogin . '.', $log);
}

function gitGithubTestConnection(): array
{
    return gitGithubVerifyConnection(true);
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

    return 'git@github.com:' . $owner . '/' . $name . '.git';
}

function gitExpectedCloneUrl(array $project, ?array $githubConfiguration = null): string
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
    $expectedClone = gitExpectedCloneUrl($project, $githubConfiguration);
    $githubConfigured = $githubConfiguration !== null && devConsoleGithubConfigured($githubConfiguration);
    $bootstrapStatus = (string)($project['git']['bootstrap_status'] ?? 'not_started');
    $status = [
        'status' => $githubConfigured && in_array($bootstrapStatus, ['local_created', 'remote_created', 'failed'], true) ? 'INITIALIZATION INCOMPLETE' : ($githubConfigured ? 'NOT INITIALIZED' : 'GitHub not configured'),
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
    if ($expectedClone === '' || $actualRemote !== $expectedClone || (string)($project['git']['clone_url'] ?? '') !== $expectedClone || (string)($project['git']['remote_url'] ?? '') !== $expectedRemote) {
        $status['status'] = 'Remote unavailable';
        return $status;
    }
    $status['remote_url'] = $expectedRemote;

    $commit = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--short', 'HEAD'], 5);
    if ($commit['exit_code'] === 0) $status['commit'] = trim((string)$commit['stdout']);
    $subject = gitRunFixedCommand(['git', '-C', $path, 'log', '-1', '--pretty=%s'], 5);
    if ($subject['exit_code'] === 0) $status['subject'] = trim((string)$subject['stdout']);
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5);
    $dirty = $porcelain['exit_code'] === 0 && trim((string)$porcelain['stdout']) !== '';
    $status['working_tree'] = $dirty ? 'Dirty' : 'Clean';
    $status['status'] = $dirty ? 'Changes present' : 'CONNECTED';

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

function gitSetBootstrapState(array $configuration, array $project, string $status, array $metadata = []): bool
{
    $metadata['bootstrap_status'] = $status;
    if ($status === 'failed') {
        $metadata['last_error_at'] = date('c');
    }
    if ($status === 'ready') {
        $metadata['last_error_at'] = null;
    }

    return gitSaveProject($configuration, gitSetMetadata($project, $metadata));
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

function gitRunAuthenticatedCommand(array $arguments, array $githubConfiguration, int $timeoutSeconds = 120): array
{
    // gh installs its Git credential helper for the service user; Git receives GH_TOKEN only in this process environment and the token is not written to the remote URL.
    $setup = gitGithubRunCommand(['gh', 'auth', 'setup-git', '--hostname', 'github.com'], $githubConfiguration, 20);
    if ($setup['exit_code'] !== 0) {
        return $setup;
    }

    return gitRunFixedCommand($arguments, $timeoutSeconds, [
        'GH_TOKEN' => (string)$githubConfiguration['token'],
    ], false);
}

function gitRepositoryFullName(array $project, array $githubConfiguration): string
{
    return (string)$githubConfiguration['account'] . '/' . (string)$project['id'];
}

function gitWriteInitialProjectFiles(array $project, string $path): void
{
    $projectName = trim((string)($project['name'] ?? ''));
    if ($projectName === '' || strlen($projectName) > 255 || devConsoleHasControlCharacters($projectName)) {
        throw new RuntimeException('Local repository initialization failed');
    }

    $readme = '# ' . $projectName . "\n\nCreated by IOVON Dev Console.\n\nRepository initialized automatically.\n";
    $gitignore = ".env\n.env.*\nvendor/\nnode_modules/\n.DS_Store\n";
    if (@file_put_contents(rtrim($path, '/') . '/README.md', $readme, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write README.md.');
    }
    if (@file_put_contents(rtrim($path, '/') . '/.gitignore', $gitignore, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write .gitignore.');
    }
}

function gitBootstrapMetadataMatches(array $project, array $githubConfiguration): bool
{
    $git = is_array($project['git'] ?? null) ? $project['git'] : [];
    $status = (string)($git['bootstrap_status'] ?? 'not_started');
    if (!in_array($status, ['local_created', 'remote_created', 'failed'], true)) {
        return false;
    }

    return (string)($git['provider'] ?? '') === 'github'
        && (string)($git['repository_owner'] ?? '') === (string)$githubConfiguration['account']
        && (string)($git['repository_name'] ?? '') === (string)$project['id']
        && (string)($git['remote_url'] ?? '') === gitExpectedRemoteUrl($project, $githubConfiguration)
        && (string)($git['clone_url'] ?? '') === gitExpectedCloneUrl($project, $githubConfiguration);
}

function gitExpectedLocalRepositoryValid(array $project, array $githubConfiguration, array &$log = []): bool
{
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) {
        return false;
    }
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    gitAppendCommandLog($log, $inside);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        return false;
    }
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    gitAppendCommandLog($log, $remote);
    if ($remote['exit_code'] !== 0) {
        return false;
    }
    $actualRemote = trim((string)$remote['stdout']);
    return in_array($actualRemote, [gitExpectedCloneUrl($project, $githubConfiguration), gitExpectedRemoteUrl($project, $githubConfiguration)], true);
}

function gitRemoteExists(string $fullName, array $githubConfiguration, array &$log): bool
{
    $view = gitGithubRunCommand(['gh', 'repo', 'view', $fullName, '--json', 'nameWithOwner,url,sshUrl,isPrivate'], $githubConfiguration, 20);
    gitAppendCommandLog($log, $view);
    return $view['exit_code'] === 0;
}

function gitLocalBootstrapRepositoryValid(array $project, array &$log = []): bool
{
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) {
        return false;
    }
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    gitAppendCommandLog($log, $inside);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        return false;
    }
    if (gitCurrentBranch($path) !== 'main') {
        return false;
    }
    $head = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--verify', 'HEAD'], 5, [], false);
    gitAppendCommandLog($log, $head);
    if ($head['exit_code'] !== 0) {
        return false;
    }

    return is_file($path . '/README.md') && is_file($path . '/.gitignore');
}

function gitVerifyInitializedRepository(array $project, array $githubConfiguration, array &$log): ?string
{
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) {
        return 'Repository verification failed';
    }
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    gitAppendCommandLog($log, $inside);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        return 'Repository verification failed';
    }
    if (gitCurrentBranch($path) !== 'main') {
        return 'Repository verification failed';
    }
    $head = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--verify', 'HEAD'], 5, [], false);
    gitAppendCommandLog($log, $head);
    if ($head['exit_code'] !== 0) {
        return 'Repository verification failed';
    }
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    gitAppendCommandLog($log, $remote);
    if ($remote['exit_code'] !== 0 || !in_array(trim((string)$remote['stdout']), [gitExpectedCloneUrl($project, $githubConfiguration), gitExpectedRemoteUrl($project, $githubConfiguration)], true)) {
        return 'Repository verification failed';
    }
    $clean = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5, [], false);
    gitAppendCommandLog($log, $clean);
    if ($clean['exit_code'] !== 0 || trim((string)$clean['stdout']) !== '') {
        return 'Repository verification failed';
    }
    if (!gitRemoteExists(gitRepositoryFullName($project, $githubConfiguration), $githubConfiguration, $log)) {
        return 'Repository verification failed';
    }

    return null;
}

function gitWorkingTreeSafeForBootstrap(string $path): bool
{
    $status = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5, [], false);
    if ($status['exit_code'] !== 0) {
        return false;
    }
    foreach (explode("\n", trim((string)$status['stdout'])) as $line) {
        if ($line === '') {
            continue;
        }
        $file = trim(substr($line, 3));
        if (!in_array($file, ['README.md', '.gitignore'], true)) {
            return false;
        }
    }

    return true;
}

function gitSaveBootstrapFailure(array $configuration, array $project, string $message): void
{
    gitSetBootstrapState($configuration, $project, 'failed', ['last_error_at' => date('c')]);
}

function gitInitializeLocalRepository(array $configuration, array $project, array &$log): array
{
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Local repository initialization failed');
    }
    if (is_link($path)) {
        throw new RuntimeException('Local repository initialization failed');
    }

    $init = gitRunFixedCommand(['git', '-C', $path, 'init', '-b', 'main'], 20, [], false);
    gitAppendCommandLog($log, $init);
    if ($init['exit_code'] !== 0) {
        $init = gitRunFixedCommand(['git', '-C', $path, 'init'], 20, [], false);
        gitAppendCommandLog($log, $init);
        if ($init['exit_code'] !== 0) {
            throw new RuntimeException('Local repository initialization failed');
        }
        $rename = gitRunFixedCommand(['git', '-C', $path, 'branch', '-M', 'main'], 20, [], false);
        gitAppendCommandLog($log, $rename);
        if ($rename['exit_code'] !== 0) {
            throw new RuntimeException('Local repository initialization failed');
        }
    }

    gitWriteInitialProjectFiles($project, $path);
    foreach ([
        ['git', '-C', $path, 'config', 'user.name', 'IOVON Dev Console'],
        ['git', '-C', $path, 'config', 'user.email', 'dev-console@localhost'],
        ['git', '-C', $path, 'add', 'README.md', '.gitignore'],
        ['git', '-C', $path, 'commit', '-m', 'Initialize project repository'],
    ] as $arguments) {
        $result = gitRunFixedCommand($arguments, 30, [], false);
        gitAppendCommandLog($log, $result);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Initial commit failed');
        }
    }

    $project = gitSetMetadata($project, [
        'provider' => 'github',
        'bootstrap_status' => 'local_created',
    ]);
    if (!gitSetBootstrapState($configuration, $project, 'local_created')) {
        throw new RuntimeException('Local repository initialization failed');
    }

    return $project;
}

function gitInitializeRepository(array $configuration, string $projectId): array
{
    $originalConfiguration = $configuration;
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $originalProject = $project;
    $github = devConsoleLoadGithubConfiguration();
    $log = [];
    if (!devConsoleGithubConfigured($github)) {
        return gitActionResult(false, 'GitHub must be configured before initializing repositories.');
    }
    if (!gitGhInstalled()) {
        return gitActionResult(false, 'GitHub CLI is not installed.');
    }
    $verification = gitGithubVerifyConnection(false);
    if (empty($verification['success'])) {
        return gitActionResult(false, 'GitHub authentication is not valid: ' . (string)$verification['message'], [(string)($verification['output'] ?? '')]);
    }
    if ($error = gitValidateProjectRepositoryPath($project)) return gitActionResult(false, $error);

    $fullName = gitRepositoryFullName($project, $github);
    $remoteUrl = gitExpectedRemoteUrl($project, $github);
    $cloneUrl = gitExpectedCloneUrl($project, $github);
    $path = gitProjectRepositoryPath($project);
    $matchingBootstrapMetadata = gitBootstrapMetadataMatches($project, $github);
    $createdLocalThisAction = false;
    $phase = 'preflight';

    if ((string)($project['git']['bootstrap_status'] ?? '') === 'ready' && gitExpectedLocalRepositoryValid($project, $github, $log)) {
        return gitActionResult(true, 'Repository is already initialized.', $log);
    }
    if (file_exists($path) && is_link($path)) {
        return gitActionResult(false, 'Local repository path must not be a symlink.');
    }
    if (is_dir($path . '/.git') || is_file($path . '/.git')) {
        if (!$matchingBootstrapMetadata && !gitProjectConnected($project)) {
            return gitActionResult(false, 'Local repository directory already contains a Git repository that was not created by this bootstrap process.');
        }
        if (!gitLocalBootstrapRepositoryValid($project, $log) && !gitExpectedLocalRepositoryValid($project, $github, $log)) {
            return gitActionResult(false, 'Local repository exists but does not match the expected GitHub repository.', $log);
        }
    } elseif (file_exists($path) && (!is_dir($path) || !gitDirectoryIsEmpty($path))) {
        return gitActionResult(false, 'Local repository directory must be absent or empty before initialization.');
    }
    if (!gitEnsureRepositoryBase($log)) {
        return gitActionResult(false, 'Unable to prepare Git repository base directory.', $log);
    }

    $remoteExists = gitRemoteExists($fullName, $github, $log);
    if ($remoteExists) {
        if (!$matchingBootstrapMetadata) {
            return gitActionResult(false, 'A repository with this name already exists and was not created by this Dev Console bootstrap process.', $log);
        }
    }

    try {
        if (!gitLocalBootstrapRepositoryValid($project, $log) && !gitExpectedLocalRepositoryValid($project, $github, $log)) {
            $phase = 'local';
            $createdLocalThisAction = !file_exists($path);
            $project = gitInitializeLocalRepository($configuration, $project, $log);
            $configuration = devConsoleLoadProjectConfiguration();
            $project = devConsoleFindProjectById($configuration, $projectId) ?? $project;
        }

        if (!$remoteExists) {
            $phase = 'github';
            $create = gitGithubRunCommand(['gh', 'repo', 'create', $fullName, '--private', '--source', $path, '--remote', 'origin', '--push'], $github, 180);
            gitAppendCommandLog($log, $create);
            if ($create['exit_code'] !== 0) {
                if (gitRemoteExists($fullName, $github, $log)) {
                    $project = gitSetMetadata($project, [
                        'provider' => 'github',
                        'repository_owner' => (string)$github['account'],
                        'repository_name' => (string)$project['id'],
                        'remote_url' => $remoteUrl,
                        'clone_url' => $cloneUrl,
                        'remote_created_at' => date('c'),
                        'connected' => false,
                    ]);
                    gitSetBootstrapState($configuration, $project, 'remote_created');
                } elseif ($createdLocalThisAction && is_dir($path) && !is_link($path)) {
                    gitRemoveDirectoryCreatedDuringAction($path);
                    gitSaveProject($originalConfiguration, $originalProject);
                    $log[] = 'Removed local repository created during this action.';
                }
                throw new RuntimeException('GitHub repository creation or push failed');
            }
            $project = gitSetMetadata($project, [
                'provider' => 'github',
                'repository_owner' => (string)$github['account'],
                'repository_name' => (string)$project['id'],
                'remote_url' => $remoteUrl,
                'clone_url' => $cloneUrl,
                'remote_created_at' => date('c'),
                'connected' => false,
            ]);
            if (!gitSetBootstrapState($configuration, $project, 'remote_created')) {
                throw new RuntimeException('GitHub repository creation or push failed');
            }
            $configuration = devConsoleLoadProjectConfiguration();
            $project = devConsoleFindProjectById($configuration, $projectId) ?? $project;
        }

        $phase = 'verify';
        $remoteSet = gitRunFixedCommand(['git', '-C', $path, 'remote', 'set-url', 'origin', $cloneUrl], 10, [], false);
        gitAppendCommandLog($log, $remoteSet);
        if ($remoteSet['exit_code'] !== 0) {
            throw new RuntimeException('Repository verification failed');
        }
        if ($verificationError = gitVerifyInitializedRepository($project, $github, $log)) {
            throw new RuntimeException($verificationError);
        }
        $project = gitSetMetadata($project, [
            'provider' => 'github',
            'repository_owner' => (string)$github['account'],
            'repository_name' => (string)$project['id'],
            'remote_url' => $remoteUrl,
            'clone_url' => $cloneUrl,
            'connected' => true,
            'connected_at' => date('c'),
            'created_at' => date('c'),
        ]);
        if (!gitSetBootstrapState($configuration, $project, 'ready')) {
            throw new RuntimeException('Unable to save Git metadata.');
        }
    } catch (Throwable $exception) {
        if ($phase === 'local' && $createdLocalThisAction && is_dir($path) && !is_link($path)) {
            gitRemoveDirectoryCreatedDuringAction($path);
            gitSaveProject($originalConfiguration, $originalProject);
            $log[] = 'Removed local repository created during this action.';
        } elseif ($phase !== 'github') {
            gitSaveBootstrapFailure($configuration, $project, $exception->getMessage());
        }
        $log[] = 'Repository initialization can be retried after the cause is fixed.';
        return gitActionResult(false, $exception->getMessage(), $log);
    }

    return gitActionResult(true, 'Repository initialized.', $log);
}

function gitAssertConnectedRepository(array $project, array $githubConfiguration): ?string
{
    if (!devConsoleGithubConfigured($githubConfiguration)) return 'GitHub is not configured.';
    if (!gitProjectConnected($project)) return 'Repository has not been created.';
    if ($error = gitValidateProjectRepositoryPath($project)) return $error;
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) return 'Repository is missing or invalid.';
    $expectedRemote = gitExpectedRemoteUrl($project, $githubConfiguration);
    $expectedClone = gitExpectedCloneUrl($project, $githubConfiguration);
    if ((string)($project['git']['remote_url'] ?? '') !== $expectedRemote) return 'Repository metadata does not match the configured GitHub account.';
    if ((string)($project['git']['clone_url'] ?? '') !== $expectedClone) return 'Repository clone metadata does not match the configured GitHub account.';
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') return 'Repository is not a valid Git working tree.';
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== $expectedClone) return 'Repository origin no longer matches the GitHub repository.';
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
