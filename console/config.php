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
    $repositoryPath = devConsoleScalarInput($input, 'repository_path');
    $branch = devConsoleScalarInput($input, 'branch');
    $productionDomain = devConsoleNormalizeDomain(devConsoleScalarInput($input, 'production_domain'));
    $productionPath = devConsoleScalarInput($input, 'production_path');
    $previewDomain = devConsoleNormalizeDomain(devConsoleScalarInput($input, 'preview_domain'));
    $previewPath = devConsoleScalarInput($input, 'preview_path');
    $projectId = devConsoleProjectIdFromName($name);
    $errors = [];

    foreach ([
        'Project name' => $name,
        'Repository path' => $repositoryPath,
        'Branch' => $branch,
        'Production domain' => $productionDomain,
        'Production path' => $productionPath,
        'Preview domain' => $previewDomain,
        'Preview path' => $previewPath,
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
    }
    if (!devConsoleIsHostname($previewDomain)) {
        $errors[] = 'Preview domain must be a hostname without scheme, port, path, query, or fragment.';
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
