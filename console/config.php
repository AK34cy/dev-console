<?php

function devConsoleRepositoryRoot(): string
{
    return dirname(__DIR__);
}

function devConsoleDefaultProjectConfiguration(): array
{
    return [
        'active_project_id' => null,
        'projects' => [],
    ];
}

function devConsoleProjectsConfigPath(): string
{
    return devConsoleRepositoryRoot() . '/config/projects.json';
}

function devConsoleGithubConfigPath(): string
{
    return devConsoleRepositoryRoot() . '/config/github.json';
}

function devConsoleDefaultGithubConfiguration(): array
{
    return [
        'account' => '',
        'token' => '',
        'default_visibility' => 'private',
        'configured_at' => null,
        'verified' => false,
        'last_verified_at' => null,
        'authenticated_login' => null,
        'ssh_transport_verified' => false,
        'ssh_transport_verified_at' => null,
        'ssh_alias' => null,
        'ssh_public_key_fingerprint' => null,
    ];
}

function devConsoleEmptyProject(): array
{
    return [
        'id' => '',
        'name' => '',
        'managed_server_id' => '',
        'repository_path' => '',
        'branch' => 'main',
        'production' => [
            'domain' => '',
            'path' => '',
        ],
        'preview' => [
            'domain' => '',
            'path' => '',
        ],
        'preview_deployment' => [
            'status' => 'never_deployed',
            'commit' => null,
            'branch' => null,
            'deployed_at' => null,
            'managed_server_id' => null,
            'duration_ms' => null,
            'operation_id' => null,
            'message' => null,
            'last_attempt_status' => null,
            'last_attempt_at' => null,
            'last_attempt_commit' => null,
            'last_attempt_message' => null,
        ],
        'production_deployment' => [
            'status' => 'never_deployed',
            'commit' => null,
            'branch' => null,
            'deployed_at' => null,
            'managed_server_id' => null,
            'duration_ms' => null,
            'operation_id' => null,
            'message' => null,
            'source' => null,
            'last_attempt_status' => null,
            'last_attempt_at' => null,
            'last_attempt_commit' => null,
            'last_attempt_message' => null,
            'preserve_paths' => [],
            'preflight' => null,
            'deletion_approval' => null,
        ],
        'git' => [
            'provider' => null,
            'repository_owner' => null,
            'repository_name' => null,
            'remote_url' => null,
            'clone_url' => null,
            'bootstrap_status' => 'not_started',
            'remote_created_at' => null,
            'last_error_at' => null,
            'connected' => false,
            'connected_at' => null,
            'created_at' => null,
            'local_head' => null,
            'remote_head' => null,
            'remote_verified' => false,
            'remote_verified_at' => null,
            'last_fetch_at' => null,
            'last_pull_at' => null,
        ],
        'last_activity_at' => null,
        'provisioning' => [
            'managed' => false,
            'provisioned_at' => null,
            'production_vhost' => null,
            'preview_vhost' => null,
            'routing_verified_at' => null,
            'production_routing_verified' => null,
            'preview_routing_verified' => null,
        ],
        'setup' => [
            'status' => 'Not configured',
            'server_id' => null,
            'timestamp' => null,
            'message' => null,
            'preview_site' => null,
            'production_site' => null,
            'apache_version' => null,
            'infrastructure_fingerprint' => null,
            'infrastructure' => [],
        ],
    ];
}

function devConsoleArrayIsList(array $values): bool
{
    return $values === [] || array_keys($values) === range(0, count($values) - 1);
}

function devConsoleMergeProjectArrays(array $base, array $changes): array
{
    $merged = $base;
    foreach ($changes as $key => $value) {
        if (
            is_array($value)
            && isset($merged[$key])
            && is_array($merged[$key])
            && !devConsoleArrayIsList($value)
            && !devConsoleArrayIsList($merged[$key])
        ) {
            $merged[$key] = devConsoleMergeProjectArrays($merged[$key], $value);
            continue;
        }

        $merged[$key] = $value;
    }

    return $merged;
}

function devConsoleNormalizeProjectConfiguration(array $configuration): array
{
    $projectsInput = $configuration['projects'] ?? null;
    if (!is_array($projectsInput)) {
        return devConsoleDefaultProjectConfiguration();
    }

    $projects = [];
    foreach ($projectsInput as $projectInput) {
        if (!is_array($projectInput)) {
            continue;
        }

        $project = devConsoleEmptyProject();
        foreach (['id', 'name', 'managed_server_id', 'repository_path', 'branch'] as $field) {
            if (isset($projectInput[$field]) && is_scalar($projectInput[$field])) {
                $project[$field] = trim((string)$projectInput[$field]);
            }
        }
        if (array_key_exists('last_activity_at', $projectInput)) {
            $value = $projectInput['last_activity_at'];
            $project['last_activity_at'] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
        }

        foreach (['production', 'preview'] as $environment) {
            $environmentInput = is_array($projectInput[$environment] ?? null) ? $projectInput[$environment] : [];
            foreach (['domain', 'path'] as $field) {
                if (isset($environmentInput[$field]) && is_scalar($environmentInput[$field])) {
                    $project[$environment][$field] = trim((string)$environmentInput[$field]);
                }
            }
        }

        $gitInput = is_array($projectInput['git'] ?? null) ? $projectInput['git'] : [];
        $project['git']['connected'] = !empty($gitInput['connected']);
        $project['git']['remote_verified'] = !empty($gitInput['remote_verified']);
        foreach (['provider', 'repository_owner', 'repository_name', 'remote_url', 'clone_url', 'remote_created_at', 'last_error_at', 'connected_at', 'created_at', 'local_head', 'remote_head', 'remote_verified_at', 'last_fetch_at', 'last_pull_at'] as $field) {
            if (array_key_exists($field, $gitInput)) {
                $value = $gitInput[$field];
                $project['git'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
        }
        if (isset($gitInput['bootstrap_status']) && is_scalar($gitInput['bootstrap_status']) && in_array((string)$gitInput['bootstrap_status'], ['not_started', 'local_created', 'remote_created', 'ready', 'failed'], true)) {
            $project['git']['bootstrap_status'] = (string)$gitInput['bootstrap_status'];
        }

        $previewDeploymentInput = is_array($projectInput['preview_deployment'] ?? null) ? $projectInput['preview_deployment'] : [];
        if (isset($previewDeploymentInput['status']) && is_scalar($previewDeploymentInput['status']) && in_array((string)$previewDeploymentInput['status'], ['never_deployed', 'running', 'deployed', 'failed'], true)) {
            $project['preview_deployment']['status'] = (string)$previewDeploymentInput['status'];
        }
        foreach (['commit', 'branch', 'deployed_at', 'managed_server_id', 'operation_id', 'message', 'last_attempt_status', 'last_attempt_at', 'last_attempt_commit', 'last_attempt_message'] as $field) {
            if (array_key_exists($field, $previewDeploymentInput)) {
                $value = $previewDeploymentInput[$field];
                $project['preview_deployment'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
        }
        if (array_key_exists('duration_ms', $previewDeploymentInput)) {
            $project['preview_deployment']['duration_ms'] = is_numeric($previewDeploymentInput['duration_ms']) ? (int)$previewDeploymentInput['duration_ms'] : null;
        }

        $productionDeploymentInput = is_array($projectInput['production_deployment'] ?? null) ? $projectInput['production_deployment'] : [];
        if (isset($productionDeploymentInput['status']) && is_scalar($productionDeploymentInput['status']) && in_array((string)$productionDeploymentInput['status'], ['never_deployed', 'running', 'deployed', 'failed'], true)) {
            $project['production_deployment']['status'] = (string)$productionDeploymentInput['status'];
        }
        foreach (['commit', 'branch', 'deployed_at', 'managed_server_id', 'operation_id', 'message', 'source', 'last_attempt_status', 'last_attempt_at', 'last_attempt_commit', 'last_attempt_message'] as $field) {
            if (array_key_exists($field, $productionDeploymentInput)) {
                $value = $productionDeploymentInput[$field];
                $project['production_deployment'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
        }
        if (array_key_exists('duration_ms', $productionDeploymentInput)) {
            $project['production_deployment']['duration_ms'] = is_numeric($productionDeploymentInput['duration_ms']) ? (int)$productionDeploymentInput['duration_ms'] : null;
        }
        if (array_key_exists('preserve_paths', $productionDeploymentInput) && is_array($productionDeploymentInput['preserve_paths'])) {
            $project['production_deployment']['preserve_paths'] = array_values(array_unique(array_filter(array_map(
                static fn($value): string => is_scalar($value) ? trim((string)$value) : '',
                $productionDeploymentInput['preserve_paths']
            ), static fn(string $value): bool => $value !== '')));
        }
        if (array_key_exists('preflight', $productionDeploymentInput)) {
            $project['production_deployment']['preflight'] = is_array($productionDeploymentInput['preflight']) ? $productionDeploymentInput['preflight'] : null;
        }
        if (array_key_exists('deletion_approval', $productionDeploymentInput)) {
            $project['production_deployment']['deletion_approval'] = is_array($productionDeploymentInput['deletion_approval']) ? $productionDeploymentInput['deletion_approval'] : null;
        }

        $provisioningInput = is_array($projectInput['provisioning'] ?? null) ? $projectInput['provisioning'] : [];
        $project['provisioning']['managed'] = !empty($provisioningInput['managed']);
        foreach (['provisioned_at', 'production_vhost', 'preview_vhost', 'routing_verified_at'] as $field) {
            if (array_key_exists($field, $provisioningInput)) {
                $value = $provisioningInput[$field];
                $project['provisioning'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
        }
        foreach (['production_routing_verified', 'preview_routing_verified'] as $field) {
            if (array_key_exists($field, $provisioningInput)) {
                $project['provisioning'][$field] = $provisioningInput[$field] === null ? null : !empty($provisioningInput[$field]);
            }
        }

        $setupInput = is_array($projectInput['setup'] ?? null) ? $projectInput['setup'] : [];
        if (isset($setupInput['status']) && is_scalar($setupInput['status']) && in_array((string)$setupInput['status'], ['Not configured', 'Configured', 'Failed', 'Update required'], true)) {
            $project['setup']['status'] = (string)$setupInput['status'];
        }
        foreach (['server_id', 'timestamp', 'message', 'preview_site', 'production_site', 'apache_version', 'infrastructure_fingerprint'] as $field) {
            if (array_key_exists($field, $setupInput)) {
                $value = $setupInput[$field];
                $project['setup'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
        }
        if (array_key_exists('infrastructure', $setupInput) && is_array($setupInput['infrastructure'])) {
            $project['setup']['infrastructure'] = $setupInput['infrastructure'];
        }

        $project = devConsoleMergeProjectArrays($projectInput, $project);

        if ($project['id'] !== '') {
            $projects[] = $project;
        }
    }

    $activeProjectId = null;
    if (isset($configuration['active_project_id']) && is_scalar($configuration['active_project_id'])) {
        $candidate = trim((string)$configuration['active_project_id']);
        foreach ($projects as $project) {
            if ((string)($project['id'] ?? '') === $candidate) {
                $activeProjectId = $candidate;
                break;
            }
        }
    }
    if ($activeProjectId === null && !empty($projects)) {
        $activeProjectId = (string)($projects[0]['id'] ?? '');
    }

    return [
        'active_project_id' => $activeProjectId,
        'projects' => $projects,
    ];
}

function devConsoleEnsureProjectConfigurationFile(string $path, array $configuration): void
{
    if (is_file($path)) {
        return;
    }

    $configDirectory = dirname($path);
    if (!is_dir($configDirectory) && !@mkdir($configDirectory, 0750, true) && !is_dir($configDirectory)) {
        return;
    }

    $json = json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    if (@file_put_contents($path, $json . "\n", LOCK_EX) !== false) {
        @chmod($path, 0640);
    }
}

function devConsoleLoadProjectConfiguration(): array
{
    $path = devConsoleProjectsConfigPath();
    $defaultConfiguration = devConsoleDefaultProjectConfiguration();
    if (!is_file($path) || !is_readable($path)) {
        devConsoleEnsureProjectConfigurationFile($path, $defaultConfiguration);
        return $defaultConfiguration;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return $defaultConfiguration;
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return $defaultConfiguration;
    }

    return devConsoleNormalizeProjectConfiguration($decoded);
}

function devConsoleSaveProjectConfiguration(array $configuration): bool
{
    $path = devConsoleProjectsConfigPath();
    $configDirectory = dirname($path);
    if (!is_dir($configDirectory) && !@mkdir($configDirectory, 0750, true) && !is_dir($configDirectory)) {
        return false;
    }

    $normalized = devConsoleNormalizeProjectConfiguration($configuration);
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $temporaryPath = $configDirectory . '/projects.json.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    @chmod($temporaryPath, 0640);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }

    return true;
}

function devConsoleNormalizeGithubConfiguration(array $configuration): array
{
    $normalized = devConsoleDefaultGithubConfiguration();
    foreach (['account', 'token', 'default_visibility'] as $field) {
        if (isset($configuration[$field]) && is_scalar($configuration[$field])) {
            $normalized[$field] = trim((string)$configuration[$field]);
        }
    }
    if ($normalized['default_visibility'] !== 'private') {
        $normalized['default_visibility'] = 'private';
    }
    $normalized['verified'] = !empty($configuration['verified']);
    $normalized['ssh_transport_verified'] = !empty($configuration['ssh_transport_verified']);
    foreach (['configured_at', 'last_verified_at', 'authenticated_login', 'ssh_transport_verified_at', 'ssh_alias', 'ssh_public_key_fingerprint'] as $field) {
        if (array_key_exists($field, $configuration)) {
            $value = $configuration[$field];
            $normalized[$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
        }
    }

    return $normalized;
}

function devConsoleLoadGithubConfiguration(): array
{
    $path = devConsoleGithubConfigPath();
    $defaultConfiguration = devConsoleDefaultGithubConfiguration();
    if (!is_file($path) || !is_readable($path)) {
        return $defaultConfiguration;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return $defaultConfiguration + ['_load_error' => 'Unable to read GitHub configuration.'];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return $defaultConfiguration + ['_load_error' => 'GitHub configuration contains invalid JSON.'];
    }

    return devConsoleNormalizeGithubConfiguration($decoded);
}

function devConsoleSaveGithubConfiguration(array $configuration): bool
{
    $path = devConsoleGithubConfigPath();
    $configDirectory = dirname($path);
    if (!is_dir($configDirectory) && !@mkdir($configDirectory, 0750, true) && !is_dir($configDirectory)) {
        return false;
    }

    $normalized = devConsoleNormalizeGithubConfiguration($configuration);
    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $temporaryPath = $configDirectory . '/github.json.tmp.' . bin2hex(random_bytes(8));
    if (@file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        return false;
    }

    @chmod($temporaryPath, 0600);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }
    @chmod($path, 0600);

    return true;
}

function devConsoleRemoveGithubConfiguration(): bool
{
    $path = devConsoleGithubConfigPath();
    return !is_file($path) || @unlink($path);
}

function devConsoleGithubConfigured(array $configuration): bool
{
    return (string)($configuration['account'] ?? '') !== '' && (string)($configuration['token'] ?? '') !== '';
}

function devConsoleValidateGithubAccount(string $account): array
{
    $errors = [];
    if ($account === '' || strlen($account) > 39 || devConsoleHasControlCharacters($account) || preg_match('/^(?!-)[A-Za-z0-9-]{1,39}(?<!-)$/', $account) !== 1) {
        $errors[] = 'Account or organization must be a GitHub login using only letters, digits, and hyphens, and it cannot begin or end with a hyphen.';
    }
    if (str_starts_with($account, '-') || str_contains($account, '/') || preg_match('/\s/', $account) === 1 || preg_match('~^https?://~i', $account) === 1) {
        $errors[] = 'Account or organization must not be a URL, path, whitespace value, or command option.';
    }

    return $errors;
}

function devConsoleValidateGithubToken(string $token, bool $required): array
{
    if ($token === '' && !$required) {
        return [];
    }
    if ($token === '' || strlen($token) > 4096 || devConsoleHasControlCharacters($token)) {
        return ['Token is required and must not contain control characters.'];
    }

    return [];
}

function devConsoleValidateGithubVisibility(string $visibility): array
{
    return $visibility === 'private' ? [] : ['Repository visibility is always private.'];
}

function devConsoleBuildGithubConfiguration(array $input, array $existing): array
{
    $account = devConsoleScalarInput($input, 'github_account');
    $tokenInput = is_scalar($input['github_token'] ?? null) ? trim((string)$input['github_token']) : '';
    $visibility = 'private';

    $requiresToken = !devConsoleGithubConfigured($existing);
    $errors = array_merge(
        devConsoleValidateGithubAccount($account),
        devConsoleValidateGithubToken($tokenInput, $requiresToken),
        devConsoleValidateGithubVisibility($visibility)
    );

    $token = $tokenInput !== '' ? $tokenInput : (string)($existing['token'] ?? '');
    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'configuration' => [
            'account' => $account,
            'token' => $token,
            'default_visibility' => $visibility,
            'configured_at' => (string)($existing['configured_at'] ?? '') !== '' ? $existing['configured_at'] : date('c'),
            'verified' => false,
            'last_verified_at' => null,
            'authenticated_login' => null,
        ],
    ];
}

function devConsoleProjects(array $configuration): array
{
    return is_array($configuration['projects'] ?? null) ? $configuration['projects'] : [];
}

function devConsoleFindManagedServerById(array $managedServers, string $serverId): ?array
{
    if ($serverId === '') {
        return null;
    }
    foreach ($managedServers as $server) {
        if ((string)($server['id'] ?? '') === $serverId) {
            return $server;
        }
    }

    return null;
}

function devConsoleManagedServerLabel(?array $server, string $serverId = ''): string
{
    if ($server === null) {
        return $serverId === '' ? 'Not assigned' : 'Unknown server (' . $serverId . ')';
    }
    $name = (string)($server['name'] ?? '');
    $id = (string)($server['id'] ?? '');
    return $name === '' ? $id : $name . ' (' . $id . ')';
}

function devConsoleManagedServerStatusLabel(?array $server): string
{
    if ($server === null) {
        return 'Not assigned';
    }
    return match ((string)($server['status'] ?? 'never_tested')) {
        'reachable' => 'Reachable',
        'unreachable' => 'Unreachable',
        default => 'Never Tested',
    };
}

function devConsoleProjectsReferencingManagedServer(array $configuration, string $serverId): array
{
    $matches = [];
    foreach (devConsoleProjects($configuration) as $project) {
        if ((string)($project['managed_server_id'] ?? '') === $serverId) {
            $matches[] = $project;
        }
    }

    return $matches;
}

function devConsoleActiveProjectId(array $configuration): string
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    return is_scalar($configuration['active_project_id'] ?? null) ? (string)$configuration['active_project_id'] : '';
}

function devConsoleActiveProject(array $configuration): ?array
{
    $activeProjectId = devConsoleActiveProjectId($configuration);
    return $activeProjectId === '' ? null : devConsoleFindProjectById($configuration, $activeProjectId);
}

function devConsoleSetActiveProject(array $configuration, string $projectId): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    if ($projectId !== '' && devConsoleFindProjectById($configuration, $projectId) !== null) {
        $configuration = devConsoleTouchProject($configuration, $projectId);
        $configuration['active_project_id'] = $projectId;
    } else {
        $configuration['active_project_id'] = null;
    }
    return devConsoleNormalizeProjectConfiguration($configuration);
}

function devConsoleSaveActiveProject(string $projectId): bool
{
    $configuration = devConsoleLoadProjectConfiguration();
    if ($projectId !== '' && devConsoleFindProjectById($configuration, $projectId) === null) {
        return false;
    }

    return devConsoleSaveProjectConfiguration(devConsoleSetActiveProject($configuration, $projectId));
}

function devConsoleFirstProjectId(array $configuration): string
{
    $projects = devConsoleProjects($configuration);
    return empty($projects) ? '' : (string)($projects[0]['id'] ?? '');
}

function devConsoleProjectTaskRoot(array $configuration, ?array $project): string
{
    if ($project === null) {
        return dirname(devConsoleRepositoryRoot());
    }

    return (string)($project['repository_path'] ?? dirname(devConsoleRepositoryRoot()));
}

function devConsoleProjectRunsDir(?array $project): string
{
    $base = devConsoleRepositoryRoot() . '/console/runs';
    if ($project === null || (string)($project['id'] ?? '') === '') {
        return $base;
    }

    return $base . '/projects/' . (string)$project['id'];
}

function devConsoleFindProjectById(array $configuration, string $id): ?array
{
    foreach (devConsoleProjects($configuration) as $project) {
        if (($project['id'] ?? '') === $id) {
            return $project;
        }
    }

    return null;
}

function devConsoleGeneratedEnvironmentPaths(string $projectId): array
{
    $root = devConsoleGeneratedProjectRoot($projectId);
    return [
        'production' => $root . '/production',
        'preview' => $root . '/preview',
    ];
}

function devConsoleGeneratedProjectRoot(string $projectId): string
{
    return '/var/www/projects/' . $projectId;
}

function devConsoleGeneratedRepositoryPath(string $projectId): string
{
    return '/var/www/git/' . $projectId;
}

function devConsoleGeneratedPreviewDomain(string $productionDomain): string
{
    return 'preview.' . devConsoleNormalizeDomain($productionDomain);
}

function devConsoleProjectUsesGeneratedEnvironmentPaths(array $project): bool
{
    $projectId = (string)($project['id'] ?? '');
    if ($projectId === '') {
        return false;
    }

    $paths = devConsoleGeneratedEnvironmentPaths($projectId);
    return (string)($project['production']['path'] ?? '') === $paths['production']
        && (string)($project['preview']['path'] ?? '') === $paths['preview'];
}

function devConsoleProjectAdoptedInPlace(array $project): bool
{
    $setup = is_array($project['setup'] ?? null) ? $project['setup'] : [];
    $infrastructure = is_array($setup['infrastructure'] ?? null) ? $setup['infrastructure'] : [];

    return !empty($infrastructure['adopted_in_place'])
        && (string)($setup['status'] ?? '') === 'Configured'
        && devConsoleIsAbsoluteUnixPath((string)($project['production']['path'] ?? ''))
        && devConsoleIsAbsoluteUnixPath((string)($project['preview']['path'] ?? ''))
        && devConsoleIsHostname((string)($project['production']['domain'] ?? ''))
        && devConsoleIsHostname((string)($project['preview']['domain'] ?? ''));
}

function devConsoleReplaceProject(array $configuration, array $updatedProject): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    foreach ($configuration['projects'] as $index => $project) {
        if (($project['id'] ?? '') === ($updatedProject['id'] ?? null)) {
            $mergedProject = devConsoleMergeProjectArrays($project, $updatedProject);
            $configuration['projects'][$index] = devConsoleNormalizeProjectConfiguration(['projects' => [$mergedProject]])['projects'][0];
            return $configuration;
        }
    }

    return $configuration;
}

function devConsoleTouchProject(array $configuration, string $projectId, ?string $timestamp = null): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    $timestamp ??= date('c');
    foreach ($configuration['projects'] as $index => $project) {
        if ((string)($project['id'] ?? '') === $projectId) {
            $project['last_activity_at'] = $timestamp;
            unset($configuration['projects'][$index]);
            array_unshift($configuration['projects'], $project);
            break;
        }
    }

    return devConsoleNormalizeProjectConfiguration($configuration);
}

function devConsoleProjectActivityTimestamp(array $project): int
{
    $timestamp = (string)($project['last_activity_at'] ?? '');
    $parsed = $timestamp === '' ? false : strtotime($timestamp);
    return $parsed === false ? 0 : $parsed;
}

function devConsoleProjectsForDisplay(array $configuration): array
{
    $projects = devConsoleProjects($configuration);
    usort($projects, static function (array $left, array $right): int {
        $activity = devConsoleProjectActivityTimestamp($right) <=> devConsoleProjectActivityTimestamp($left);
        if ($activity !== 0) {
            return $activity;
        }

        return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    });

    return $projects;
}

function devConsoleRemoveProjectFromConfiguration(array $configuration, string $projectId): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    $configuration['projects'] = array_values(array_filter($configuration['projects'], function (array $project) use ($projectId): bool {
        return ($project['id'] ?? '') !== $projectId;
    }));

    return devConsoleNormalizeProjectConfiguration($configuration);
}

function devConsoleProjectIdFromName(string $name): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $base = is_string($ascii) && $ascii !== '' ? $ascii : $name;
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = preg_replace('/-+/', '-', $base) ?? '';

    return trim($base, '-');
}

function devConsoleHasControlCharacters(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
}

function devConsoleIsAbsoluteUnixPath(string $path): bool
{
    return $path !== '' && strlen($path) <= 255 && str_starts_with($path, '/') && !devConsoleHasControlCharacters($path);
}

function devConsoleNormalizeDomain(string $domain): string
{
    return strtolower(rtrim(trim($domain), '.'));
}

function devConsoleIsHostname(string $domain): bool
{
    $domain = devConsoleNormalizeDomain($domain);
    if ($domain === '' || strlen($domain) > 253 || devConsoleHasControlCharacters($domain)) {
        return false;
    }

    if (preg_match('~[/:?#@]~', $domain) === 1) {
        return false;
    }

    foreach (explode('.', $domain) as $label) {
        if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
            return false;
        }
    }

    return true;
}

function devConsoleScalarInput(array $input, string $key): string
{
    $value = $input[$key] ?? '';
    return is_scalar($value) ? trim((string)$value) : '';
}

function devConsoleValidateNewProject(array $configuration, array $input, array $managedServers = []): array
{
    $name = devConsoleScalarInput($input, 'project_name');
    $productionDomain = devConsoleNormalizeDomain(devConsoleScalarInput($input, 'production_domain'));
    $managedServerId = devConsoleScalarInput($input, 'managed_server_id');
    $projectId = devConsoleProjectIdFromName($name);
    $repositoryPath = $projectId === '' ? '' : devConsoleGeneratedRepositoryPath($projectId);
    $branch = 'main';
    $previewDomain = $productionDomain === '' ? '' : devConsoleGeneratedPreviewDomain($productionDomain);
    $generatedPaths = $projectId === '' ? ['production' => '', 'preview' => ''] : devConsoleGeneratedEnvironmentPaths($projectId);
    $productionPath = $generatedPaths['production'];
    $previewPath = $generatedPaths['preview'];
    $errors = [];

    foreach ([
        'Project name' => $name,
        'Production domain' => $productionDomain,
    ] as $label => $value) {
        if ($value === '') {
            $errors[] = $label . ' is required.';
        } elseif (strlen($value) > 255 || devConsoleHasControlCharacters($value)) {
            $errors[] = $label . ' contains invalid characters or is too long.';
        }
    }

    if ($projectId === '') {
        $errors[] = 'Project name must contain at least one letter or number.';
    } elseif (devConsoleFindProjectById($configuration, $projectId) !== null) {
        $errors[] = 'A project with this name already exists.';
    }
    if (empty($managedServers)) {
        $errors[] = 'Create a Managed Server before creating a Project.';
    } elseif ($managedServerId === '') {
        $errors[] = 'Managed Server is required.';
    } elseif (devConsoleFindManagedServerById($managedServers, $managedServerId) === null) {
        $errors[] = 'Selected Managed Server does not exist.';
    }

    if (!devConsoleIsAbsoluteUnixPath($repositoryPath)) {
        $errors[] = 'Repository path must be an absolute Unix path.';
    }
    if (!devConsoleIsAbsoluteUnixPath($productionPath)) {
        $errors[] = 'Production path must be an absolute Unix path.';
    }
    if (!devConsoleIsAbsoluteUnixPath($previewPath)) {
        $errors[] = 'Preview path must be an absolute Unix path.';
    }
    if (!devConsoleIsHostname($productionDomain)) {
        $errors[] = 'Production domain must be a hostname without scheme, port, path, query, or fragment.';
    } elseif (str_starts_with($productionDomain, 'preview.')) {
        $errors[] = 'Production domain must not begin with preview.';
    }
    if (!devConsoleIsHostname($previewDomain)) {
        $errors[] = 'Generated Preview domain is too long or invalid.';
    }
    if ($productionDomain !== '' && $previewDomain !== '' && $productionDomain === $previewDomain) {
        $errors[] = 'Production and Preview domains must be different.';
    }
    if ($productionPath !== '' && $previewPath !== '' && $productionPath === $previewPath) {
        $errors[] = 'Production and Preview paths must be different.';
    }

    foreach (devConsoleProjects($configuration) as $project) {
        foreach (['production', 'preview'] as $environment) {
            $existingDomain = devConsoleNormalizeDomain((string)($project[$environment]['domain'] ?? ''));
            $existingPath = (string)($project[$environment]['path'] ?? '');
            if ($existingDomain !== '' && ($existingDomain === $productionDomain || $existingDomain === $previewDomain)) {
                $errors[] = 'Domain is already registered by another project environment.';
            }
            if ($existingPath !== '' && ($existingPath === $productionPath || $existingPath === $previewPath)) {
                $errors[] = 'Path is already registered by another project environment.';
            }
        }
    }

    $project = [
        'id' => $projectId,
        'name' => $name,
        'managed_server_id' => $managedServerId,
        'repository_path' => $repositoryPath,
        'branch' => $branch,
        'last_activity_at' => date('c'),
        'production' => [
            'domain' => $productionDomain,
            'path' => $productionPath,
        ],
        'preview' => [
            'domain' => $previewDomain,
            'path' => $previewPath,
        ],
        'provisioning' => devConsoleEmptyProject()['provisioning'],
        'git' => devConsoleEmptyProject()['git'],
    ];

    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'project' => $project,
    ];
}

function devConsoleValidateProjectUpdate(array $configuration, array $input, array $managedServers = []): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    $projectId = devConsoleScalarInput($input, 'project_id');
    $existing = devConsoleFindProjectById($configuration, $projectId);
    $name = devConsoleScalarInput($input, 'project_name');
    $productionDomain = devConsoleNormalizeDomain(devConsoleScalarInput($input, 'production_domain'));
    $previewDomain = $productionDomain === '' ? '' : devConsoleGeneratedPreviewDomain($productionDomain);
    $managedServerId = devConsoleScalarInput($input, 'managed_server_id');
    $errors = [];

    if ($existing === null) {
        $errors[] = 'Project not found.';
    }
    if ($name === '' || strlen($name) > 255 || devConsoleHasControlCharacters($name)) {
        $errors[] = 'Project name is required and must not contain invalid characters.';
    }
    if (!devConsoleIsHostname($productionDomain)) {
        $errors[] = 'Production domain must be a hostname without scheme, port, path, query, or fragment.';
    } elseif (str_starts_with($productionDomain, 'preview.')) {
        $errors[] = 'Production domain must not begin with preview.';
    }
    if (!devConsoleIsHostname($previewDomain)) {
        $errors[] = 'Generated Preview domain is too long or invalid.';
    }
    if ($managedServerId !== '' && devConsoleFindManagedServerById($managedServers, $managedServerId) === null) {
        $errors[] = 'Selected Managed Server does not exist.';
    }

    foreach (devConsoleProjects($configuration) as $project) {
        if ((string)($project['id'] ?? '') === $projectId) {
            continue;
        }
        foreach (['production', 'preview'] as $environment) {
            $existingDomain = devConsoleNormalizeDomain((string)($project[$environment]['domain'] ?? ''));
            if ($existingDomain !== '' && ($existingDomain === $productionDomain || $existingDomain === $previewDomain)) {
                $errors[] = 'Domain is already registered by another project environment.';
            }
        }
    }

    $project = $existing ?? devConsoleEmptyProject();
    $infrastructureChanged = $existing !== null && (
        (string)($existing['managed_server_id'] ?? '') !== $managedServerId
        || devConsoleNormalizeDomain((string)($existing['production']['domain'] ?? '')) !== $productionDomain
        || devConsoleNormalizeDomain((string)($existing['preview']['domain'] ?? '')) !== $previewDomain
        || (string)($existing['production']['path'] ?? '') !== (string)($project['production']['path'] ?? '')
        || (string)($existing['preview']['path'] ?? '') !== (string)($project['preview']['path'] ?? '')
    );
    $project['name'] = $name;
    $project['managed_server_id'] = $managedServerId;
    $project['production']['domain'] = $productionDomain;
    $project['preview']['domain'] = $previewDomain;
    if ($infrastructureChanged && (string)($project['setup']['status'] ?? '') === 'Configured') {
        $project['setup']['status'] = 'Update required';
        $project['setup']['message'] = 'Project infrastructure settings changed. Update Infrastructure is required.';
    }
    $project['last_activity_at'] = date('c');

    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'project' => $project,
    ];
}

function devConsoleUpdateProjectInConfiguration(array $configuration, array $project): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    foreach ($configuration['projects'] as $index => $existing) {
        if ((string)($existing['id'] ?? '') === (string)($project['id'] ?? '')) {
            $configuration['projects'][$index] = devConsoleMergeProjectArrays($existing, $project);
            break;
        }
    }

    return devConsoleNormalizeProjectConfiguration($configuration);
}

function devConsoleUpdateProject(array $input, array $managedServers = []): array
{
    $configuration = devConsoleLoadProjectConfiguration();
    $validation = devConsoleValidateProjectUpdate($configuration, $input, $managedServers);
    if (!$validation['valid']) {
        return $validation + ['saved' => false];
    }
    $updatedConfiguration = devConsoleUpdateProjectInConfiguration($configuration, $validation['project']);
    if (!devConsoleSaveProjectConfiguration($updatedConfiguration)) {
        return [
            'valid' => false,
            'saved' => false,
            'errors' => ['Unable to save project configuration.'],
            'project' => $validation['project'],
        ];
    }

    return $validation + ['saved' => true];
}

function devConsoleAppendProjectToConfiguration(array $configuration, array $project): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    array_unshift($configuration['projects'], $project);
    if ((string)($configuration['active_project_id'] ?? '') === '') {
        $configuration['active_project_id'] = (string)($project['id'] ?? '');
    }

    return devConsoleNormalizeProjectConfiguration($configuration);
}

function devConsoleAppendProject(array $input, array $managedServers = []): array
{
    $configuration = devConsoleLoadProjectConfiguration();
    $validation = devConsoleValidateNewProject($configuration, $input, $managedServers);
    if (!$validation['valid']) {
        return $validation + ['saved' => false];
    }

    $updatedConfiguration = devConsoleAppendProjectToConfiguration($configuration, $validation['project']);
    if (!devConsoleSaveProjectConfiguration($updatedConfiguration)) {
        return [
            'valid' => false,
            'saved' => false,
            'errors' => ['Unable to save project configuration.'],
            'project' => $validation['project'],
        ];
    }

    return $validation + ['saved' => true];
}

function devConsoleDiscoveredGitRepositories(string $baseDirectory = '/var/www', int $limit = 50): array
{
    if (!is_dir($baseDirectory) || !is_readable($baseDirectory)) {
        return [];
    }

    $repositories = [];
    $directoriesToInspect = [$baseDirectory];

    foreach (scandir($baseDirectory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $baseDirectory . '/' . $entry;
        if (is_dir($path) && is_readable($path)) {
            $directoriesToInspect[] = $path;
            foreach (scandir($path) ?: [] as $childEntry) {
                if ($childEntry === '.' || $childEntry === '..') {
                    continue;
                }

                $childPath = $path . '/' . $childEntry;
                if (is_dir($childPath) && is_readable($childPath)) {
                    $directoriesToInspect[] = $childPath;
                }
            }
        }
    }

    foreach ($directoriesToInspect as $directory) {
        if (is_dir($directory . '/.git') || is_file($directory . '/.git')) {
            $realPath = realpath($directory);
            if (is_string($realPath) && $realPath !== '') {
                $repositories[$realPath] = $realPath;
            }
        }
    }

    $repositories = array_values($repositories);
    sort($repositories, SORT_NATURAL | SORT_FLAG_CASE);

    return array_slice($repositories, 0, max(1, $limit));
}

function devConsoleApacheDirectiveValue(string $value): string
{
    return trim($value, " \t\n\r\0\x0B\"'");
}

function devConsoleParseApacheSite(string $path): array
{
    $site = [
        'server_name' => '',
        'server_aliases' => [],
        'document_root' => '',
    ];

    if (!is_file($path) || !is_readable($path)) {
        return $site;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = preg_replace('/\s+#.*$/', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if ($site['server_name'] === '' && preg_match('/^ServerName\s+(.+)$/i', $line, $matches) === 1) {
            $site['server_name'] = devConsoleApacheDirectiveValue($matches[1]);
            continue;
        }

        if (preg_match('/^ServerAlias\s+(.+)$/i', $line, $matches) === 1) {
            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $alias) {
                $alias = devConsoleApacheDirectiveValue($alias);
                if ($alias !== '') {
                    $site['server_aliases'][] = $alias;
                }
            }
            continue;
        }

        if ($site['document_root'] === '' && preg_match('/^DocumentRoot\s+(.+)$/i', $line, $matches) === 1) {
            $site['document_root'] = devConsoleApacheDirectiveValue($matches[1]);
        }
    }

    $site['server_aliases'] = array_values(array_unique($site['server_aliases']));

    return $site;
}

function devConsoleApacheConfFiles(string $directory): array
{
    if (!is_dir($directory) || !is_readable($directory)) {
        return [];
    }

    $files = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.conf')) {
            continue;
        }

        $path = $directory . '/' . $entry;
        if (is_file($path) || is_link($path)) {
            $files[$entry] = $path;
        }
    }

    ksort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files;
}

function devConsoleApacheSites(): array
{
    $available = devConsoleApacheConfFiles('/etc/apache2/sites-available');
    $enabled = devConsoleApacheConfFiles('/etc/apache2/sites-enabled');
    if (empty($available) && empty($enabled)) {
        return [];
    }

    $siteNames = array_unique(array_merge(array_keys($available), array_keys($enabled)));
    sort($siteNames, SORT_NATURAL | SORT_FLAG_CASE);

    $sites = [];
    foreach ($siteNames as $siteName) {
        $path = $available[$siteName] ?? $enabled[$siteName] ?? '';
        if ($path === '') {
            continue;
        }

        $parsed = devConsoleParseApacheSite($path);
        $sites[] = [
            'name' => $siteName,
            'path' => $path,
            'enabled' => array_key_exists($siteName, $enabled),
            'server_name' => $parsed['server_name'],
            'server_aliases' => $parsed['server_aliases'],
            'document_root' => $parsed['document_root'],
        ];
    }

    return $sites;
}
