<?php

function devConsoleRepositoryRoot(): string
{
    return dirname(__DIR__);
}

function devConsoleDefaultProjectConfiguration(): array
{
    $repositoryRoot = devConsoleRepositoryRoot();

    return [
        'project' => [
            'name' => 'Dev Console',
            'repository_path' => $repositoryRoot,
            'staging_path' => '',
            'production_path' => '',
            'staging_url' => '',
            'production_url' => '',
            'web_server' => 'php',
            'web_server_config' => '',
            'branch' => 'main',
        ],
    ];
}

function devConsoleProjectsConfigPath(): string
{
    return devConsoleRepositoryRoot() . '/config/projects.json';
}

function devConsoleProjectDefaults(): array
{
    return devConsoleDefaultProjectConfiguration()['project'];
}

function devConsoleNormalizeProjectConfiguration(array $configuration): array
{
    $projectInput = $configuration['project'] ?? null;
    if (!is_array($projectInput)) {
        return devConsoleDefaultProjectConfiguration();
    }

    $project = devConsoleProjectDefaults();
    foreach ($project as $field => $fallback) {
        if (array_key_exists($field, $projectInput) && !is_array($projectInput[$field]) && !is_object($projectInput[$field])) {
            $project[$field] = (string)$projectInput[$field];
        } else {
            $project[$field] = $fallback;
        }
    }

    return [
        'project' => $project,
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

function devConsoleActiveProject(array $configuration): array
{
    if (isset($configuration['project']) && is_array($configuration['project'])) {
        return $configuration['project'];
    }

    return devConsoleDefaultProjectConfiguration()['project'];
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
