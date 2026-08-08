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

function gitGhExecutable(): string
{
    if (function_exists('serverToolsFindExecutable') && function_exists('serverToolsDefaultPath')) {
        return serverToolsFindExecutable('gh', serverToolsDefaultPath());
    }

    foreach (explode(':', getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin') as $directory) {
        $path = rtrim($directory, '/') . '/gh';
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return '';
}

function gitGhInstalled(): bool
{
    return gitGhExecutable() !== '';
}

function gitGithubRunCommand(array $arguments, array $githubConfiguration, int $timeoutSeconds = 20): array
{
    $startedAt = microtime(true);
    $commandDisplay = processCommandDisplay($arguments);
    $token = (string)($githubConfiguration['token'] ?? '');
    if ($token === '') {
        return processResult($commandDisplay, '', 'GitHub Personal Access Token is not configured.', 127, false, $startedAt);
    }
    $gh = gitGhExecutable();
    if ($gh === '') {
        return processResult($commandDisplay, '', 'GitHub CLI is not installed.', 127, false, $startedAt);
    }
    if (($arguments[0] ?? '') !== 'gh') {
        return processResult($commandDisplay, '', 'Invalid GitHub CLI command.', 127, false, $startedAt);
    }
    $arguments[0] = $gh;

    return processRunCommand($arguments, [
        'cwd' => devConsoleRepositoryRoot(),
        'env' => [
            'GH_TOKEN' => $token,
            'GIT_TERMINAL_PROMPT' => '0',
        ],
        'inherit_env' => false,
        'timeout' => $timeoutSeconds,
    ]);
}

function gitGithubCliFailureMessage(array $result, string $fallback): string
{
    $output = strtolower((string)($result['output'] ?? ''));
    if (str_contains($output, 'personal access token is not configured')) {
        return 'GitHub Personal Access Token is not configured.';
    }
    if (str_contains($output, 'github cli is not installed')) {
        return 'GitHub CLI is not installed.';
    }
    if (str_contains($output, 'bad credentials') || str_contains($output, 'http 401') || str_contains($output, 'requires authentication') || str_contains($output, 'authentication failed')) {
        return 'GitHub Personal Access Token was rejected by GitHub.';
    }
    if (str_contains($output, 'could not resolve') || str_contains($output, 'failed to connect') || str_contains($output, 'error connecting') || str_contains($output, 'check your internet connection') || str_contains($output, 'timeout') || str_contains($output, 'http 5')) {
        return 'GitHub API is unavailable.';
    }

    return $fallback;
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
        return gitActionResult(false, gitGithubCliFailureMessage($user, 'GitHub authentication failed.'), $log);
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
            return gitActionResult(false, gitGithubCliFailureMessage($organization, 'The configured account is not the authenticated user or an accessible organization.'), $log);
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
    [$owner, $name] = gitExpectedRemoteIdentity($project, $githubConfiguration);
    if ($owner === '' || $name === '') {
        return '';
    }

    return 'git@github.com-dev-console-account:' . $owner . '/' . $name . '.git';
}

function gitExpectedCloneUrl(array $project, ?array $githubConfiguration = null): string
{
    return gitExpectedRemoteUrl($project, $githubConfiguration);
}

function gitExpectedHttpsUrl(array $project, ?array $githubConfiguration = null): string
{
    [$owner, $name] = gitExpectedRemoteIdentity($project, $githubConfiguration);
    if ($owner === '' || $name === '') {
        return '';
    }

    return 'https://github.com/' . $owner . '/' . $name . '.git';
}

function gitLegacyGithubSshUrl(array $project, ?array $githubConfiguration = null): string
{
    [$owner, $name] = gitExpectedRemoteIdentity($project, $githubConfiguration);
    if ($owner === '' || $name === '') {
        return '';
    }

    return 'git@github.com:' . $owner . '/' . $name . '.git';
}

function gitExpectedRemoteIdentity(array $project, ?array $githubConfiguration = null): array
{
    $owner = (string)($project['git']['repository_owner'] ?? '');
    if ($owner === '' && $githubConfiguration !== null) {
        $owner = (string)($githubConfiguration['account'] ?? '');
    }
    $name = (string)($project['git']['repository_name'] ?? '');
    if ($name === '') {
        $name = (string)($project['id'] ?? '');
    }
    return [$owner, $name];
}

function gitRepairableRemoteUrls(array $project, array $githubConfiguration): array
{
    return array_values(array_filter(array_unique([
        gitExpectedRemoteUrl($project, $githubConfiguration),
        gitExpectedHttpsUrl($project, $githubConfiguration),
        gitLegacyGithubSshUrl($project, $githubConfiguration),
    ])));
}

function gitRemoteUrlMatchesExpected(string $actualRemote, array $project, array $githubConfiguration): bool
{
    return in_array($actualRemote, gitRepairableRemoteUrls($project, $githubConfiguration), true);
}

function gitStatusClassName(string $status): string
{
    return match ($status) {
        'CONNECTED' => 'healthy',
        'INVALID REPOSITORY', 'REMOTE UNAVAILABLE' => 'error',
        default => 'warning',
    };
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
        'local_commit' => '',
        'remote_commit' => (string)($project['git']['remote_head'] ?? ''),
        'subject' => '',
        'working_tree' => 'Unknown',
        'ahead' => null,
        'behind' => null,
        'remote_verified_at' => (string)($project['git']['remote_verified_at'] ?? ''),
        'last_fetch_at' => (string)($project['git']['last_fetch_at'] ?? ''),
        'last_pull_at' => (string)($project['git']['last_pull_at'] ?? ''),
        'can_initialize' => $githubConfigured,
        'can_fetch' => false,
        'can_pull' => false,
        'pull_disabled_reason' => '',
        'diagnostic' => '',
    ];

    if (!file_exists($path)) {
        return $status;
    }
    if (is_link($path) || !is_dir($path)) {
        $status['status'] = 'INVALID REPOSITORY';
        $status['diagnostic'] = 'Repository path is not a safe directory.';
        return $status;
    }

    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') {
        $status['status'] = gitDirectoryIsEmpty($path) ? $status['status'] : 'INVALID REPOSITORY';
        $status['diagnostic'] = gitDirectoryIsEmpty($path) ? '' : 'Repository path is not a valid Git working tree.';
        return $status;
    }

    $status['status'] = 'INITIALIZATION INCOMPLETE';
    $status['branch'] = gitCurrentBranch($path);
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5);
    if ($remote['exit_code'] !== 0) {
        $status['diagnostic'] = 'Origin remote is not configured.';
        return $status;
    }
    $actualRemote = trim((string)$remote['stdout']);
    $status['remote_url'] = $actualRemote;
    if ($githubConfiguration === null || $expectedClone === '' || !gitRemoteUrlMatchesExpected($actualRemote, $project, $githubConfiguration)) {
        $status['status'] = 'INVALID REPOSITORY';
        $status['diagnostic'] = 'Origin remote does not match the expected GitHub repository.';
        return $status;
    }
    $status['can_fetch'] = $githubConfigured;

    $head = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', 'HEAD'], 5);
    if ($head['exit_code'] === 0) {
        $status['local_commit'] = trim((string)$head['stdout']);
        $status['commit'] = substr($status['local_commit'], 0, 12);
    }
    $subject = gitRunFixedCommand(['git', '-C', $path, 'log', '-1', '--pretty=%s'], 5);
    if ($subject['exit_code'] === 0) $status['subject'] = trim((string)$subject['stdout']);
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5);
    $dirty = $porcelain['exit_code'] === 0 && trim((string)$porcelain['stdout']) !== '';
    $status['working_tree'] = $dirty ? 'Dirty' : 'Clean';

    $originHead = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', 'origin/main'], 5);
    if ($originHead['exit_code'] !== 0) {
        $status['diagnostic'] = 'Remote branch origin/main has not been verified.';
        return $status;
    }
    $status['remote_commit'] = trim((string)$originHead['stdout']);
    $status['can_pull'] = $githubConfigured;
    if ($dirty) {
        $status['pull_disabled_reason'] = 'Working tree must be clean before pulling.';
    }

    $counts = gitRunFixedCommand(['git', '-C', $path, 'rev-list', '--left-right', '--count', 'HEAD...origin/main'], 5);
    if ($counts['exit_code'] === 0 && preg_match('/^(\d+)\s+(\d+)$/', trim((string)$counts['stdout']), $matches) === 1) {
        $status['ahead'] = (int)$matches[1];
        $status['behind'] = (int)$matches[2];
    }
    if (empty($project['git']['remote_verified']) && (string)($project['git']['last_error_at'] ?? '') !== '' && $bootstrapStatus === 'ready') {
        $status['status'] = 'REMOTE UNAVAILABLE';
        $status['diagnostic'] = 'Last authenticated remote access failed.';
        return $status;
    }
    if ($bootstrapStatus === 'ready' && !empty($project['git']['remote_verified'])) {
        if ($dirty) {
            $status['status'] = 'CHANGES PRESENT';
        } elseif (($status['ahead'] ?? 0) > 0 && ($status['behind'] ?? 0) > 0) {
            $status['status'] = 'AHEAD / BEHIND';
        } elseif (($status['ahead'] ?? 0) > 0) {
            $status['status'] = 'AHEAD';
        } elseif (($status['behind'] ?? 0) > 0) {
            $status['status'] = 'BEHIND';
        } elseif ($status['branch'] === 'main' && $status['local_commit'] === $status['remote_commit']) {
            $status['status'] = 'CONNECTED';
        }
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
    $configuration = devConsoleReplaceProject($configuration, $project);
    $configuration = devConsoleTouchProject($configuration, (string)($project['id'] ?? ''));
    return devConsoleSaveProjectConfiguration($configuration);
}

function gitRuntimeUserAndGroup(): array
{
    $user = get_current_user() ?: 'www-data';
    $group = $user;
    if (function_exists('posix_geteuid')) {
        $entry = @posix_getpwuid(posix_geteuid());
        if (is_array($entry) && isset($entry['name'])) {
            $user = (string)$entry['name'];
        }
    }
    if (function_exists('posix_getegid')) {
        $entry = @posix_getgrgid(posix_getegid());
        if (is_array($entry) && isset($entry['name'])) {
            $group = (string)$entry['name'];
        }
    }

    return [$user, $group];
}

function gitRepositoryBaseProvisioningInstructions(): string
{
    [$user, $group] = gitRuntimeUserAndGroup();
    return "Git repository base directory is not ready. Ask an administrator to run:\n"
        . 'sudo mkdir -p ' . DEV_CONSOLE_GIT_BASE . "\n"
        . 'sudo chown ' . $user . ':' . $group . ' ' . DEV_CONSOLE_GIT_BASE . "\n"
        . 'sudo chmod 755 ' . DEV_CONSOLE_GIT_BASE;
}

function gitEnsureRepositoryBase(array &$log): bool
{
    if (!is_dir(DEV_CONSOLE_GIT_BASE)) {
        $log[] = gitRepositoryBaseProvisioningInstructions();
        return false;
    }
    if (is_link(DEV_CONSOLE_GIT_BASE)) {
        $log[] = 'Git repository base directory must not be a symlink.';
        return false;
    }
    if (!is_writable(DEV_CONSOLE_GIT_BASE)) {
        $log[] = gitRepositoryBaseProvisioningInstructions();
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
    return gitRunAuthenticatedGitCommand($arguments, $githubConfiguration, $timeoutSeconds);
}

function gitCreateAskpassHelper(): string
{
    $path = tempnam(sys_get_temp_dir(), 'iovon-git-askpass-');
    if ($path === false) {
        throw new RuntimeException('Git push authentication failed');
    }

    $script = <<<'SH'
#!/bin/sh
case "$1" in
  *Username*) printf '%s\n' "$IOVON_GIT_USERNAME" ;;
  *Password*) printf '%s\n' "$IOVON_GIT_TOKEN" ;;
  *) printf '\n' ;;
esac
SH;
    if (@file_put_contents($path, $script, LOCK_EX) === false || !@chmod($path, 0700)) {
        @unlink($path);
        throw new RuntimeException('Git push authentication failed');
    }

    return $path;
}

function gitRunAuthenticatedGitCommand(array $arguments, array $githubConfiguration, int $timeoutSeconds = 120): array
{
    $helperPath = gitCreateAskpassHelper();
    try {
        return gitRunFixedCommand($arguments, $timeoutSeconds, [
            'GIT_ASKPASS' => $helperPath,
            'GIT_TERMINAL_PROMPT' => '0',
            'IOVON_GIT_USERNAME' => 'x-access-token',
            'IOVON_GIT_TOKEN' => (string)($githubConfiguration['token'] ?? ''),
        ], false);
    } finally {
        @unlink($helperPath);
    }
}

function gitRepositoryFullName(array $project, array $githubConfiguration): string
{
    return (string)$githubConfiguration['account'] . '/' . (string)$project['id'];
}

function gitGithubAuthenticatedLogin(array $githubConfiguration, array &$log): ?string
{
    $user = gitGithubRunCommand(['gh', 'api', 'user'], $githubConfiguration, 20);
    gitAppendCommandSummary($log, $user);
    if ($user['exit_code'] !== 0) {
        $log[] = gitGithubCliFailureMessage($user, 'GitHub authentication failed.');
        return null;
    }

    $decodedUser = json_decode((string)$user['stdout'], true);
    return is_array($decodedUser) && is_scalar($decodedUser['login'] ?? null) ? (string)$decodedUser['login'] : null;
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

    gitEnsureTaskDocumentation($project, $path);
}

function gitTaskDocumentationContent(array $project): string
{
    $projectId = (string)($project['id'] ?? '');

    return "# Task Workflow\n\n"
        . "Tasks in this repository are isolated to Project `{$projectId}`.\n\n"
        . "- `TASKS/TODO/` stores open task files.\n"
        . "- `TASKS/DONE/` stores completed task files.\n"
        . "- `TASKS/ATTACHMENTS/<TASK-ID>/` stores files attached to a task.\n\n"
        . "Task numbers are project-specific and use the next available `TASK-001`, `TASK-002`, and so on.\n\n"
        . "Dev Console automatically prepends YAML metadata to each task:\n\n"
        . "```yaml\n---\nproject_id: {$projectId}\n---\n```\n\n"
        . "When creating a task manually, keep the YAML metadata at the top and write the task body below it.\n\n"
        . "Use **Use in Workflow** to select a task in Dev Console, then use **Run Codex** to execute it.\n";
}

function gitEnsureTaskDocumentation(array $project, string $path): ?string
{
    $tasksPath = rtrim($path, '/') . '/TASKS';
    foreach ([$tasksPath, $tasksPath . '/TODO', $tasksPath . '/DONE', $tasksPath . '/ATTACHMENTS'] as $directory) {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create TASKS directory.');
        }
    }

    $readmePath = $tasksPath . '/README.md';
    if (is_file($readmePath)) {
        return null;
    }

    if (@file_put_contents($readmePath, gitTaskDocumentationContent($project), LOCK_EX) === false) {
        throw new RuntimeException('Unable to write TASKS/README.md.');
    }

    return $readmePath;
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

function gitBootstrapAttemptedByDevConsole(array $project): bool
{
    $git = is_array($project['git'] ?? null) ? $project['git'] : [];
    $status = (string)($git['bootstrap_status'] ?? 'not_started');
    return in_array($status, ['local_created', 'remote_created', 'failed'], true)
        && ((string)($git['provider'] ?? '') === '' || (string)($git['provider'] ?? '') === 'github');
}

function gitProjectHasExpectedBootstrapContent(array $project, string $path): bool
{
    if (!is_file($path . '/README.md') || !is_file($path . '/.gitignore')) {
        return false;
    }

    $readme = (string)@file_get_contents($path . '/README.md');
    $gitignore = (string)@file_get_contents($path . '/.gitignore');
    if (!str_contains($readme, '# ' . (string)($project['name'] ?? '')) || !str_contains($readme, 'Created by IOVON Dev Console.')) {
        return false;
    }
    foreach ([".env\n", ".env.*\n", "vendor/\n", "node_modules/\n", ".DS_Store\n"] as $line) {
        if (!str_contains($gitignore, $line)) {
            return false;
        }
    }

    $subject = gitRunFixedCommand(['git', '-C', $path, 'log', '-1', '--pretty=%s'], 5, [], false);
    return $subject['exit_code'] === 0 && trim((string)$subject['stdout']) === 'Initialize project repository';
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
    return gitRemoteUrlMatchesExpected($actualRemote, $project, $githubConfiguration);
}

function gitEnsureExpectedOrigin(array $project, array $githubConfiguration, array &$log): ?string
{
    $path = gitProjectRepositoryPath($project);
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    gitAppendCommandLog($log, $remote);
    if ($remote['exit_code'] !== 0) {
        return 'Git remote configuration failed';
    }

    $actualRemote = trim((string)$remote['stdout']);
    $expectedRemote = gitExpectedRemoteUrl($project, $githubConfiguration);
    if ($actualRemote === $expectedRemote) {
        return null;
    }
    if (!gitRemoteUrlMatchesExpected($actualRemote, $project, $githubConfiguration)) {
        return 'Repository origin no longer matches the GitHub repository.';
    }

    $remoteSet = gitRunFixedCommand(['git', '-C', $path, 'remote', 'set-url', 'origin', $expectedRemote], 10, [], false);
    gitAppendCommandLog($log, $remoteSet);
    if ($remoteSet['exit_code'] !== 0) {
        return 'Git remote configuration failed';
    }

    return null;
}

function gitGithubRepositoryMetadata(string $fullName, array $githubConfiguration, array &$log): array
{
    $view = gitGithubRunCommand(['gh', 'api', 'repos/' . $fullName], $githubConfiguration, 20);
    gitAppendCommandSummary($log, $view);
    if ($view['exit_code'] !== 0) {
        $output = (string)($view['output'] ?? '');
        if (stripos($output, 'not found') !== false || stripos($output, 'HTTP 404') !== false) {
            return ['exists' => false, 'error' => null, 'metadata' => null];
        }
        return ['exists' => false, 'error' => gitGithubCliFailureMessage($view, 'GitHub repository check failed'), 'metadata' => null];
    }

    $metadata = json_decode((string)$view['stdout'], true);
    return ['exists' => true, 'error' => null, 'metadata' => is_array($metadata) ? $metadata : []];
}

function gitRemoteExists(string $fullName, array $githubConfiguration, array &$log): bool
{
    $metadata = gitGithubRepositoryMetadata($fullName, $githubConfiguration, $log);
    return empty($metadata['error']) && !empty($metadata['exists']);
}

function gitGithubRemoteIdentityMatches(array $metadata, array $project, array $githubConfiguration): bool
{
    $owner = is_array($metadata['owner'] ?? null) && is_scalar($metadata['owner']['login'] ?? null) ? (string)$metadata['owner']['login'] : '';
    $name = is_scalar($metadata['name'] ?? null) ? (string)$metadata['name'] : '';
    return strcasecmp($owner, (string)$githubConfiguration['account']) === 0
        && $name === (string)$project['id'];
}

function gitCreateGithubRepository(array $project, array $githubConfiguration, array &$log): array
{
    $login = gitGithubAuthenticatedLogin($githubConfiguration, $log);
    if ($login === null) {
        return ['success' => false, 'message' => 'GitHub repository creation failed'];
    }

    $account = (string)$githubConfiguration['account'];
    $endpoint = strcasecmp($account, $login) === 0 ? 'user/repos' : 'orgs/' . $account . '/repos';
    $create = gitGithubRunCommand([
        'gh',
        'api',
        '-X',
        'POST',
        $endpoint,
        '-f',
        'name=' . (string)$project['id'],
        '-F',
        'private=true',
        '-F',
        'auto_init=false',
    ], $githubConfiguration, 60);
    gitAppendCommandSummary($log, $create);

    if ($create['exit_code'] !== 0) {
        return ['success' => false, 'message' => gitGithubCliFailureMessage($create, 'GitHub repository creation failed')];
    }

    $metadata = json_decode((string)$create['stdout'], true);
    return ['success' => true, 'message' => 'GitHub repository created.', 'metadata' => is_array($metadata) ? $metadata : []];
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

    return gitProjectHasExpectedBootstrapContent($project, $path);
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
    if ($originError = gitEnsureExpectedOrigin($project, $githubConfiguration, $log)) {
        return $originError;
    }
    $fetch = gitRunAuthenticatedGitCommand(['git', '-C', $path, 'fetch', '--prune', 'origin'], $githubConfiguration, 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) {
        return 'Repository verification failed';
    }
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    gitAppendCommandLog($log, $remote);
    if ($remote['exit_code'] !== 0 || trim((string)$remote['stdout']) !== gitExpectedRemoteUrl($project, $githubConfiguration)) {
        return 'Repository verification failed';
    }
    $originHead = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', 'origin/main'], 5, [], false);
    gitAppendCommandLog($log, $originHead);
    if ($originHead['exit_code'] !== 0 || trim((string)$originHead['stdout']) !== trim((string)$head['stdout'])) {
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

function gitReadVerifiedRepositoryMetadata(string $path): ?array
{
    $local = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', 'HEAD'], 5, [], false);
    $remote = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', 'origin/main'], 5, [], false);
    if ($local['exit_code'] !== 0 || $remote['exit_code'] !== 0) {
        return null;
    }

    return [
        'local_head' => trim((string)$local['stdout']),
        'remote_head' => trim((string)$remote['stdout']),
        'remote_verified' => true,
        'remote_verified_at' => date('c'),
    ];
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
        ['git', '-C', $path, 'add', 'README.md', '.gitignore', 'TASKS/README.md'],
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
    $bootstrapAttempted = gitBootstrapAttemptedByDevConsole($project);
    $createdLocalThisAction = false;
    $phase = 'preflight';

    if ((string)($project['git']['bootstrap_status'] ?? '') === 'ready' && gitStatus($project, $github)['status'] === 'CONNECTED') {
        return gitActionResult(true, 'Repository is already initialized.', $log);
    }
    if (file_exists($path) && is_link($path)) {
        return gitActionResult(false, 'Local repository path must not be a symlink.');
    }
    if (is_dir($path . '/.git') || is_file($path . '/.git')) {
        if (!$matchingBootstrapMetadata && !$bootstrapAttempted) {
            return gitActionResult(false, 'An existing repository was found, but it cannot be verified as a repository created by this Dev Console initialization process.');
        }
        if (!gitLocalBootstrapRepositoryValid($project, $log)) {
            return gitActionResult(false, 'An existing repository was found, but it cannot be verified as a repository created by this Dev Console initialization process.', $log);
        }
    } elseif (file_exists($path) && (!is_dir($path) || !gitDirectoryIsEmpty($path))) {
        return gitActionResult(false, 'Local repository directory must be absent or empty before initialization.');
    }
    if (!gitEnsureRepositoryBase($log)) {
        return gitActionResult(false, gitRepositoryBaseProvisioningInstructions(), $log);
    }

    $remoteCheck = gitGithubRepositoryMetadata($fullName, $github, $log);
    if (!empty($remoteCheck['error'])) {
        return gitActionResult(false, (string)$remoteCheck['error'], $log);
    }
    $remoteExists = !empty($remoteCheck['exists']);
    if ($remoteExists) {
        if (!gitGithubRemoteIdentityMatches(is_array($remoteCheck['metadata'] ?? null) ? $remoteCheck['metadata'] : [], $project, $github)) {
            return gitActionResult(false, 'GitHub repository check failed', $log);
        }
        if (!$matchingBootstrapMetadata && !$bootstrapAttempted) {
            return gitActionResult(false, 'An existing repository was found, but it cannot be verified as a repository created by this Dev Console initialization process.', $log);
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
            $create = gitCreateGithubRepository($project, $github, $log);
            if (empty($create['success'])) {
                $createFailureMessage = (string)($create['message'] ?? 'GitHub repository creation failed');
                $postFailureRemote = gitGithubRepositoryMetadata($fullName, $github, $log);
                if (!empty($postFailureRemote['error'])) {
                    $createFailureMessage = (string)$postFailureRemote['error'];
                }
                if (empty($postFailureRemote['error']) && !empty($postFailureRemote['exists'])) {
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
                throw new RuntimeException($createFailureMessage);
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
                throw new RuntimeException('GitHub repository creation failed');
            }
            $configuration = devConsoleLoadProjectConfiguration();
            $project = devConsoleFindProjectById($configuration, $projectId) ?? $project;
        } elseif (!$matchingBootstrapMetadata) {
            $project = gitSetMetadata($project, [
                'provider' => 'github',
                'repository_owner' => (string)$github['account'],
                'repository_name' => (string)$project['id'],
                'remote_url' => $remoteUrl,
                'clone_url' => $cloneUrl,
                'connected' => false,
            ]);
            if (!gitSetBootstrapState($configuration, $project, 'remote_created')) {
                throw new RuntimeException('Unable to save Git metadata.');
            }
            $configuration = devConsoleLoadProjectConfiguration();
            $project = devConsoleFindProjectById($configuration, $projectId) ?? $project;
        }

        $phase = 'remote';
        $existingOrigin = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
        gitAppendCommandLog($log, $existingOrigin);
        if ($existingOrigin['exit_code'] === 0 && !gitRemoteUrlMatchesExpected(trim((string)$existingOrigin['stdout']), $project, $github)) {
            throw new RuntimeException('Git remote configuration failed');
        }
        $remoteCommand = $existingOrigin['exit_code'] === 0
            ? ['git', '-C', $path, 'remote', 'set-url', 'origin', $remoteUrl]
            : ['git', '-C', $path, 'remote', 'add', 'origin', $remoteUrl];
        $remoteSet = gitRunFixedCommand($remoteCommand, 10, [], false);
        gitAppendCommandLog($log, $remoteSet);
        if ($remoteSet['exit_code'] !== 0) {
            throw new RuntimeException('Git remote configuration failed');
        }
        $phase = 'push';
        $push = gitRunAuthenticatedGitCommand(['git', '-C', $path, 'push', '-u', 'origin', 'main'], $github, 120);
        gitAppendCommandLog($log, $push);
        if ($push['exit_code'] !== 0) {
            $pushOutput = strtolower((string)($push['output'] ?? ''));
            if (str_contains($pushOutput, 'authentication') || str_contains($pushOutput, 'permission denied') || str_contains($pushOutput, 'could not read username') || str_contains($pushOutput, 'could not read password')) {
                throw new RuntimeException('Git push authentication failed');
            }
            throw new RuntimeException('Git push failed');
        }
        $phase = 'verify';
        if ($verificationError = gitVerifyInitializedRepository($project, $github, $log)) {
            throw new RuntimeException($verificationError);
        }
        $verified = gitReadVerifiedRepositoryMetadata($path);
        if ($verified === null || $verified['local_head'] !== $verified['remote_head']) {
            throw new RuntimeException('Local and remote commits do not match');
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
            'last_fetch_at' => date('c'),
        ] + $verified);
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
    if ($error = gitValidateProjectRepositoryPath($project)) return $error;
    $path = gitProjectRepositoryPath($project);
    if (!is_dir($path) || is_link($path)) return 'Repository is missing or invalid.';
    $expectedRemote = gitExpectedRemoteUrl($project, $githubConfiguration);
    $expectedClone = gitExpectedCloneUrl($project, $githubConfiguration);
    $inside = gitRunFixedCommand(['git', '-C', $path, 'rev-parse', '--is-inside-work-tree'], 5, [], false);
    if ($inside['exit_code'] !== 0 || trim((string)$inside['stdout']) !== 'true') return 'Repository is not a valid Git working tree.';
    if (gitCurrentBranch($path) !== 'main') return 'Current branch does not match project branch.';
    $remote = gitRunFixedCommand(['git', '-C', $path, 'remote', 'get-url', 'origin'], 5, [], false);
    if ($remote['exit_code'] !== 0 || !gitRemoteUrlMatchesExpected(trim((string)$remote['stdout']), $project, $githubConfiguration)) return 'Repository origin no longer matches the GitHub repository.';
    if ($expectedRemote === '' || $expectedClone === '') return 'Repository metadata does not match the configured GitHub account.';
    return null;
}

function gitFetchRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $github = devConsoleLoadGithubConfiguration();
    if ($error = gitAssertConnectedRepository($project, $github)) return gitActionResult(false, $error);
    $log = [];
    if ($originError = gitEnsureExpectedOrigin($project, $github, $log)) return gitActionResult(false, $originError, $log);
    $fetch = gitRunAuthenticatedCommand(['git', '-C', gitProjectRepositoryPath($project), 'fetch', '--prune', 'origin'], $github, 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) {
        gitSaveProject($configuration, gitSetMetadata($project, ['remote_verified' => false, 'connected' => false, 'last_error_at' => date('c')]));
        return gitActionResult(false, 'Git fetch failed.', $log);
    }
    $baseMetadata = [
        'provider' => 'github',
        'repository_owner' => (string)$github['account'],
        'repository_name' => (string)$project['id'],
        'remote_url' => gitExpectedRemoteUrl($project, $github),
        'clone_url' => gitExpectedCloneUrl($project, $github),
        'last_fetch_at' => date('c'),
        'connected' => false,
        'remote_verified' => false,
        'last_error_at' => null,
    ];
    $verified = gitReadVerifiedRepositoryMetadata(gitProjectRepositoryPath($project));
    if ($verified === null) {
        gitSaveProject($configuration, gitSetMetadata($project, $baseMetadata));
        return gitActionResult(false, 'Remote branch verification failed', $log);
    }
    $baseMetadata['connected'] = true;
    if (($verified['local_head'] ?? '') === ($verified['remote_head'] ?? '') && (string)($project['git']['bootstrap_status'] ?? '') !== 'ready') {
        $baseMetadata['bootstrap_status'] = 'ready';
    }
    $project = gitSetMetadata($project, $verified + $baseMetadata);
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
    if (gitCurrentBranch($path) !== 'main') return gitActionResult(false, 'Current branch does not match project branch.');
    $porcelain = gitRunFixedCommand(['git', '-C', $path, 'status', '--porcelain'], 5, [], false);
    if ($porcelain['exit_code'] !== 0 || trim((string)$porcelain['stdout']) !== '') return gitActionResult(false, 'Working tree must be clean before pulling.');

    $log = [];
    if ($originError = gitEnsureExpectedOrigin($project, $github, $log)) return gitActionResult(false, $originError, $log);
    $fetch = gitRunAuthenticatedCommand(['git', '-C', $path, 'fetch', '--prune', 'origin'], $github, 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) {
        gitSaveProject($configuration, gitSetMetadata($project, ['remote_verified' => false, 'connected' => false, 'last_error_at' => date('c')]));
        return gitActionResult(false, 'Git fetch failed.', $log);
    }
    $pull = gitRunAuthenticatedCommand(['git', '-C', $path, 'pull', '--ff-only', 'origin', 'main'], $github, 120);
    gitAppendCommandLog($log, $pull);
    if ($pull['exit_code'] !== 0) return gitActionResult(false, 'Git pull failed.', $log);
    $verified = gitReadVerifiedRepositoryMetadata($path);
    if ($verified === null || ($verified['local_head'] ?? '') !== ($verified['remote_head'] ?? '')) {
        return gitActionResult(false, 'Local and remote commits do not match', $log);
    }
    $project = gitSetMetadata($project, $verified + [
        'provider' => 'github',
        'repository_owner' => (string)$github['account'],
        'repository_name' => (string)$project['id'],
        'remote_url' => gitExpectedRemoteUrl($project, $github),
        'clone_url' => gitExpectedCloneUrl($project, $github),
        'bootstrap_status' => 'ready',
        'connected' => true,
        'last_error_at' => null,
        'last_fetch_at' => date('c'),
        'last_pull_at' => date('c'),
    ]);
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to save Git pull metadata.', $log);
    return gitActionResult(true, 'Git pull completed.', $log);
}

function gitPushRepository(array $configuration, string $projectId): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return gitActionResult(false, 'Project not found.');
    $github = devConsoleLoadGithubConfiguration();
    if ($error = gitAssertConnectedRepository($project, $github)) return gitActionResult(false, $error);
    $path = gitProjectRepositoryPath($project);
    if (gitCurrentBranch($path) !== 'main') return gitActionResult(false, 'Current branch does not match project branch.');

    $log = [];
    if ($originError = gitEnsureExpectedOrigin($project, $github, $log)) return gitActionResult(false, $originError, $log);
    $push = gitRunAuthenticatedCommand(['git', '-C', $path, 'push', 'origin', 'main'], $github, 120);
    gitAppendCommandLog($log, $push);
    if ($push['exit_code'] !== 0) {
        gitSaveProject($configuration, gitSetMetadata($project, [
            'bootstrap_status' => 'ready',
            'connected' => true,
            'remote_verified' => true,
            'last_error_at' => date('c'),
        ]));
        return gitActionResult(false, 'Git push failed.', $log);
    }
    $fetch = gitRunAuthenticatedCommand(['git', '-C', $path, 'fetch', '--prune', 'origin'], $github, 120);
    gitAppendCommandLog($log, $fetch);
    if ($fetch['exit_code'] !== 0) {
        return gitActionResult(false, 'Git push completed, but remote verification failed.', $log);
    }
    $verified = gitReadVerifiedRepositoryMetadata($path);
    if ($verified === null || ($verified['local_head'] ?? '') !== ($verified['remote_head'] ?? '')) {
        return gitActionResult(false, 'Local and remote commits do not match', $log);
    }
    $project = gitSetMetadata($project, $verified + [
        'provider' => 'github',
        'repository_owner' => (string)$github['account'],
        'repository_name' => (string)$project['id'],
        'remote_url' => gitExpectedRemoteUrl($project, $github),
        'clone_url' => gitExpectedCloneUrl($project, $github),
        'bootstrap_status' => 'ready',
        'connected' => true,
        'last_error_at' => null,
        'last_fetch_at' => date('c'),
    ]);
    if (!gitSaveProject($configuration, $project)) return gitActionResult(false, 'Unable to save Git push metadata.', $log);
    return gitActionResult(true, 'Git push completed.', $log);
}
