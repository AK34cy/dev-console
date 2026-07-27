<?php

function devConsoleRepositoryRoot(): string
{
    return dirname(__DIR__);
}

function devConsoleDefaultProjectConfiguration(): array
{
    return [
        'projects' => [],
    ];
}

function devConsoleProjectsConfigPath(): string
{
    return devConsoleRepositoryRoot() . '/config/projects.json';
}

function devConsoleEmptyProject(): array
{
    return [
        'id' => '',
        'name' => '',
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
        'git' => [
            'remote_url' => null,
            'connected' => false,
            'connected_at' => null,
            'last_fetch_at' => null,
            'last_pull_at' => null,
        ],
        'provisioning' => [
            'managed' => false,
            'provisioned_at' => null,
            'production_vhost' => null,
            'preview_vhost' => null,
            'routing_verified_at' => null,
            'production_routing_verified' => null,
            'preview_routing_verified' => null,
        ],
    ];
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
        foreach (['id', 'name', 'repository_path', 'branch'] as $field) {
            if (isset($projectInput[$field]) && is_scalar($projectInput[$field])) {
                $project[$field] = trim((string)$projectInput[$field]);
            }
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
        foreach (['remote_url', 'connected_at', 'last_fetch_at', 'last_pull_at'] as $field) {
            if (array_key_exists($field, $gitInput)) {
                $value = $gitInput[$field];
                $project['git'][$field] = is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
            }
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

        if ($project['id'] !== '') {
            $projects[] = $project;
        }
    }

    return [
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

function devConsoleProjects(array $configuration): array
{
    return is_array($configuration['projects'] ?? null) ? $configuration['projects'] : [];
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
    return [
        'production' => '/var/www/projects/' . $projectId . '/production',
        'preview' => '/var/www/projects/' . $projectId . '/preview',
    ];
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

function devConsoleReplaceProject(array $configuration, array $updatedProject): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    foreach ($configuration['projects'] as $index => $project) {
        if (($project['id'] ?? '') === ($updatedProject['id'] ?? null)) {
            $configuration['projects'][$index] = devConsoleNormalizeProjectConfiguration(['projects' => [$updatedProject]])['projects'][0];
            return $configuration;
        }
    }

    return $configuration;
}

function devConsoleRemoveProjectFromConfiguration(array $configuration, string $projectId): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    $configuration['projects'] = array_values(array_filter($configuration['projects'], function (array $project) use ($projectId): bool {
        return ($project['id'] ?? '') !== $projectId;
    }));

    return $configuration;
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

function devConsoleValidateNewProject(array $configuration, array $input): array
{
    $name = devConsoleScalarInput($input, 'project_name');
    $productionDomain = devConsoleNormalizeDomain(devConsoleScalarInput($input, 'production_domain'));
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
        'repository_path' => $repositoryPath,
        'branch' => $branch,
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

function devConsoleAppendProjectToConfiguration(array $configuration, array $project): array
{
    $configuration = devConsoleNormalizeProjectConfiguration($configuration);
    $configuration['projects'][] = $project;

    return $configuration;
}

function devConsoleAppendProject(array $input): array
{
    $configuration = devConsoleLoadProjectConfiguration();
    $validation = devConsoleValidateNewProject($configuration, $input);
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
