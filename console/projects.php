<?php

const DEV_CONSOLE_MANAGED_MARKER = '# Managed by IOVON Dev Console';
const DEV_CONSOLE_PLACEHOLDER_FILE = 'index.html';

function projectActionResult(bool $success, string $message, array $log = []): array
{
    return [
        'success' => $success,
        'message' => $message,
        'output' => implode("\n", $log),
    ];
}

function projectSafeId(string $projectId): bool
{
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $projectId) === 1;
}

function projectEnvironmentVhostName(array $project, string $environment): string
{
    $projectId = (string)($project['id'] ?? '');
    if (!projectSafeId($projectId) || !in_array($environment, ['production', 'preview'], true)) {
        throw new RuntimeException('Invalid project identifier.');
    }

    return 'dev-console-' . $projectId . '-' . $environment . '.conf';
}

function projectSafeLogName(array $project, string $environment): string
{
    return (string)$project['id'] . '-' . $environment;
}

function projectNormalizePath(string $path): string
{
    if ($path === '' || devConsoleHasControlCharacters($path) || !str_starts_with($path, '/')) {
        throw new RuntimeException('Path must be an absolute Unix path.');
    }

    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            throw new RuntimeException('Path traversal is not allowed.');
        }
        $parts[] = $part;
    }

    return '/' . implode('/', $parts);
}

function projectPathContains(string $parent, string $child): bool
{
    $parent = rtrim(projectNormalizePath($parent), '/');
    $child = rtrim(projectNormalizePath($child), '/');

    return $parent !== $child && str_starts_with($child . '/', $parent . '/');
}

function projectAssertManagedPathPolicy(array $project, string $allowedBase = '/var/www/projects'): array
{
    $base = rtrim(projectNormalizePath($allowedBase), '/');
    $repositoryPath = projectNormalizePath((string)($project['repository_path'] ?? ''));
    $productionPath = projectNormalizePath((string)($project['production']['path'] ?? ''));
    $previewPath = projectNormalizePath((string)($project['preview']['path'] ?? ''));
    $protected = ['/', '/var', '/var/www', $base];

    foreach (['Production' => $productionPath, 'Preview' => $previewPath] as $label => $path) {
        if (!str_starts_with($path . '/', $base . '/')) {
            throw new RuntimeException($label . ' path must be inside ' . $base . '.');
        }
        if (in_array($path, $protected, true)) {
            throw new RuntimeException($label . ' path is protected.');
        }
        if ($path === $repositoryPath || projectPathContains($path, $repositoryPath) || projectPathContains($repositoryPath, $path)) {
            throw new RuntimeException($label . ' path must not overlap the repository path.');
        }
        if (file_exists($path) && is_link($path)) {
            throw new RuntimeException($label . ' path must not be a symlink.');
        }
    }

    if ($productionPath === $previewPath) {
        throw new RuntimeException('Production and Preview paths must be different.');
    }
    if (projectPathContains($productionPath, $previewPath) || projectPathContains($previewPath, $productionPath)) {
        throw new RuntimeException('Production and Preview paths must not contain each other.');
    }

    return ['production' => $productionPath, 'preview' => $previewPath];
}

function projectDirectoryAcceptableForProvisioning(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }
    if (is_link($path)) {
        return false;
    }

    $entries = array_values(array_filter(scandir($path) ?: [], fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
    if (empty($entries)) {
        return true;
    }

    return count($entries) === 1 && $entries[0] === DEV_CONSOLE_PLACEHOLDER_FILE && projectIsPlaceholderFile($path . '/' . DEV_CONSOLE_PLACEHOLDER_FILE);
}

function projectIsPlaceholderFile(string $path): bool
{
    return is_file($path) && str_contains((string)@file_get_contents($path), 'Temporary Dev Console placeholder');
}

function projectPlaceholderContent(array $project, string $environment): string
{
    return "<!doctype html>\n<html lang=\"en\">\n<head><meta charset=\"utf-8\"><title>" .
        htmlspecialchars((string)$project['name'], ENT_QUOTES, 'UTF-8') . ' ' . ucfirst($environment) .
        "</title></head>\n<body><h1>Temporary Dev Console placeholder</h1><p>Project: " .
        htmlspecialchars((string)$project['id'], ENT_QUOTES, 'UTF-8') . "</p><p>Environment: " .
        htmlspecialchars($environment, ENT_QUOTES, 'UTF-8') . "</p></body>\n</html>\n";
}

function projectVhostPath(array $project, string $environment, string $availableDir = '/etc/apache2/sites-available'): string
{
    return rtrim($availableDir, '/') . '/' . projectEnvironmentVhostName($project, $environment);
}

function projectEnabledPath(array $project, string $environment, string $enabledDir = '/etc/apache2/sites-enabled'): string
{
    return rtrim($enabledDir, '/') . '/' . projectEnvironmentVhostName($project, $environment);
}

function projectVhostMarkersMatch(string $path, array $project, string $environment): bool
{
    if (!is_file($path)) {
        return false;
    }

    $contents = (string)@file_get_contents($path);
    return str_contains($contents, DEV_CONSOLE_MANAGED_MARKER)
        && str_contains($contents, '# Project: ' . (string)$project['id'])
        && str_contains($contents, '# Environment: ' . $environment);
}

function projectGenerateVhost(array $project, string $environment, string $documentRoot): string
{
    $domain = (string)$project[$environment]['domain'];
    $logName = projectSafeLogName($project, $environment);

    return DEV_CONSOLE_MANAGED_MARKER . "\n" .
        '# Project: ' . (string)$project['id'] . "\n" .
        '# Environment: ' . $environment . "\n" .
        "<VirtualHost *:80>\n" .
        '    ServerName ' . $domain . "\n" .
        '    DocumentRoot ' . $documentRoot . "\n\n" .
        '    <Directory ' . $documentRoot . ">\n" .
        "        Options FollowSymLinks\n" .
        "        AllowOverride All\n" .
        "        Require all granted\n" .
        "    </Directory>\n\n" .
        '    ErrorLog ${APACHE_LOG_DIR}/' . $logName . "-error.log\n" .
        '    CustomLog ${APACHE_LOG_DIR}/' . $logName . "-access.log combined\n" .
        "</VirtualHost>\n";
}

function projectVhostMatches(string $path, array $project, string $environment, string $documentRoot): bool
{
    if (!projectVhostMarkersMatch($path, $project, $environment)) {
        return false;
    }

    $parsed = devConsoleParseApacheSite($path);
    return devConsoleNormalizeDomain((string)$parsed['server_name']) === devConsoleNormalizeDomain((string)$project[$environment]['domain'])
        && projectNormalizePath((string)$parsed['document_root']) === $documentRoot;
}

function projectRunFixedCommand(array $arguments): array
{
    return apacheRunFixedCommand($arguments);
}

function projectApacheCommandPath(string $binary): string
{
    foreach (['/usr/sbin/' . $binary, '/usr/bin/' . $binary, '/bin/' . $binary] as $path) {
        if (is_file($path) && is_executable($path)) {
            return $path;
        }
    }

    return $binary;
}

function projectAppendCommandLog(array &$log, array $result): void
{
    $log[] = '$ ' . (string)$result['command'];
    $log[] = 'Exit code: ' . (string)$result['exit_code'];
    if (trim((string)$result['output']) !== '') {
        $log[] = trim((string)$result['output']);
    }
}

function projectEnvironmentStatus(array $project, string $environment, string $availableDir = '/etc/apache2/sites-available', string $enabledDir = '/etc/apache2/sites-enabled'): array
{
    $path = projectNormalizePath((string)$project[$environment]['path']);
    $vhostPath = projectVhostPath($project, $environment, $availableDir);
    $enabledPath = projectEnabledPath($project, $environment, $enabledDir);
    $serverNameMatches = false;
    $documentRootMatches = false;

    if (is_file($vhostPath)) {
        $parsed = devConsoleParseApacheSite($vhostPath);
        $serverNameMatches = devConsoleNormalizeDomain((string)$parsed['server_name']) === devConsoleNormalizeDomain((string)$project[$environment]['domain']);
        $documentRootMatches = $parsed['document_root'] !== '' && projectNormalizePath((string)$parsed['document_root']) === $path;
    }

    return [
        'directory_exists' => is_dir($path) && !is_link($path),
        'vhost_exists' => is_file($vhostPath),
        'site_enabled' => file_exists($enabledPath),
        'server_name_matches' => $serverNameMatches,
        'document_root_matches' => $documentRootMatches,
        'vhost_name' => basename($vhostPath),
    ];
}

function projectStatus(array $project, string $availableDir = '/etc/apache2/sites-available', string $enabledDir = '/etc/apache2/sites-enabled'): array
{
    $production = projectEnvironmentStatus($project, 'production', $availableDir, $enabledDir);
    $preview = projectEnvironmentStatus($project, 'preview', $availableDir, $enabledDir);
    $all = [$production, $preview];
    $resourceFlags = [];
    foreach ($all as $environmentStatus) {
        $resourceFlags[] = $environmentStatus['directory_exists'];
        $resourceFlags[] = $environmentStatus['vhost_exists'];
        $resourceFlags[] = $environmentStatus['site_enabled'];
    }

    $anyResources = in_array(true, $resourceFlags, true);
    $allProvisioned = true;
    foreach ($all as $environmentStatus) {
        foreach (['directory_exists', 'vhost_exists', 'site_enabled', 'server_name_matches', 'document_root_matches'] as $field) {
            $allProvisioned = $allProvisioned && !empty($environmentStatus[$field]);
        }
    }

    $drift = false;
    foreach ($all as $environmentStatus) {
        if (!empty($environmentStatus['vhost_exists']) && (empty($environmentStatus['server_name_matches']) || empty($environmentStatus['document_root_matches']))) {
            $drift = true;
        }
    }

    $label = 'Not provisioned';
    if ($drift) {
        $label = 'Drift detected';
    } elseif ($allProvisioned) {
        $label = 'Provisioned';
    } elseif ($anyResources) {
        $label = 'Partially provisioned';
    }

    return ['label' => $label, 'production' => $production, 'preview' => $preview];
}

function projectValidateStoredConfiguration(array $configuration, array $project): void
{
    $input = [
        'project_name' => (string)$project['name'],
        'repository_path' => (string)$project['repository_path'],
        'branch' => (string)$project['branch'],
        'production_domain' => (string)$project['production']['domain'],
        'production_path' => (string)$project['production']['path'],
        'preview_domain' => (string)$project['preview']['domain'],
        'preview_path' => (string)$project['preview']['path'],
    ];
    $withoutProject = devConsoleRemoveProjectFromConfiguration($configuration, (string)$project['id']);
    $validation = devConsoleValidateNewProject($withoutProject, $input);
    if (!$validation['valid'] || ($validation['project']['id'] ?? '') !== ($project['id'] ?? null)) {
        throw new RuntimeException('Stored project configuration is invalid.');
    }
}

function projectProvision(array $configuration, string $projectId, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) {
        return projectActionResult(false, 'Project not found.');
    }

    $availableDir = $options['available_dir'] ?? '/etc/apache2/sites-available';
    $enabledDir = $options['enabled_dir'] ?? '/etc/apache2/sites-enabled';
    $allowedBase = $options['allowed_base'] ?? '/var/www/projects';
    $runCommands = $options['run_commands'] ?? true;
    $log = [];
    $createdDirectories = [];
    $createdPlaceholders = [];
    $createdVhosts = [];
    $enabledSites = [];
    $siteStateChanged = false;

    try {
        projectValidateStoredConfiguration($configuration, $project);
        if ($runCommands) {
            $apacheState = apacheState();
            if (empty($apacheState['installed']) || empty($apacheState['running'])) {
                throw new RuntimeException('Apache must be installed and running before provisioning.');
            }
        }

        $paths = projectAssertManagedPathPolicy($project, $allowedBase);
        foreach (['production', 'preview'] as $environment) {
            if (!projectDirectoryAcceptableForProvisioning($paths[$environment])) {
                throw new RuntimeException(ucfirst($environment) . ' directory is not empty.');
            }
        }

        foreach (['production', 'preview'] as $environment) {
            $path = $paths[$environment];
            if (!is_dir($path)) {
                if (!@mkdir($path, 0755, true) && !is_dir($path)) {
                    throw new RuntimeException('Unable to create ' . $environment . ' directory.');
                }
                $createdDirectories[] = $path;
                $log[] = 'Created directory: ' . $path;
            }
            $entries = array_values(array_filter(scandir($path) ?: [], fn(string $entry): bool => $entry !== '.' && $entry !== '..'));
            if (empty($entries)) {
                $placeholder = $path . '/' . DEV_CONSOLE_PLACEHOLDER_FILE;
                if (@file_put_contents($placeholder, projectPlaceholderContent($project, $environment), LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write placeholder for ' . $environment . '.');
                }
                $createdPlaceholders[] = $placeholder;
                $log[] = 'Created placeholder: ' . $placeholder;
            }
        }

        if (!is_dir($availableDir) && !@mkdir($availableDir, 0755, true) && !is_dir($availableDir)) {
            throw new RuntimeException('Unable to access Apache sites-available directory.');
        }

        foreach (['production', 'preview'] as $environment) {
            $vhostPath = projectVhostPath($project, $environment, $availableDir);
            $content = projectGenerateVhost($project, $environment, $paths[$environment]);
            if (is_file($vhostPath)) {
                if (!projectVhostMatches($vhostPath, $project, $environment, $paths[$environment])) {
                    throw new RuntimeException('Refusing to overwrite unrelated Apache config: ' . basename($vhostPath));
                }
            } else {
                if (@file_put_contents($vhostPath, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Unable to write Apache config: ' . basename($vhostPath));
                }
                $createdVhosts[] = $vhostPath;
                $log[] = 'Created Apache config: ' . basename($vhostPath);
            }
        }

        if ($runCommands) {
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed.');
            foreach (['production', 'preview'] as $environment) {
                if (!file_exists(projectEnabledPath($project, $environment, $enabledDir))) {
                    $result = projectRunFixedCommand([projectApacheCommandPath('a2ensite'), projectEnvironmentVhostName($project, $environment)]);
                    projectAppendCommandLog($log, $result);
                    if ($result['exit_code'] !== 0) throw new RuntimeException('Unable to enable ' . $environment . ' site.');
                    $enabledSites[] = $environment;
                    $siteStateChanged = true;
                }
            }
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed after enabling sites.');
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache reload failed.');
        }

        $project['provisioning'] = [
            'managed' => true,
            'provisioned_at' => date('c'),
            'production_vhost' => projectEnvironmentVhostName($project, 'production'),
            'preview_vhost' => projectEnvironmentVhostName($project, 'preview'),
        ];
        if (empty($options['skip_save'])) {
            $updatedConfiguration = devConsoleReplaceProject($configuration, $project);
            if (!devConsoleSaveProjectConfiguration($updatedConfiguration)) {
                throw new RuntimeException('Unable to save provisioning metadata.');
            }
        }

        return projectActionResult(true, 'Project provisioned.', $log);
    } catch (Throwable $exception) {
        foreach (array_reverse($enabledSites) as $environment) {
            if ($runCommands) {
                $result = projectRunFixedCommand([projectApacheCommandPath('a2dissite'), projectEnvironmentVhostName($project, $environment)]);
                projectAppendCommandLog($log, $result);
                $siteStateChanged = true;
            }
        }
        foreach (array_reverse($createdVhosts) as $path) {
            if (is_file($path)) {
                @unlink($path);
                $log[] = 'Rolled back Apache config: ' . basename($path);
            }
        }
        foreach (array_reverse($createdPlaceholders) as $path) {
            if (is_file($path)) {
                @unlink($path);
                $log[] = 'Rolled back placeholder: ' . $path;
            }
        }
        foreach (array_reverse($createdDirectories) as $path) {
            @rmdir($path);
            $log[] = 'Rolled back directory if empty: ' . $path;
        }
        if ($siteStateChanged && $runCommands) {
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
        }

        return projectActionResult(false, $exception->getMessage(), $log);
    }
}

function projectRemoveFromConsole(array $configuration, string $projectId): array
{
    if (devConsoleFindProjectById($configuration, $projectId) === null) {
        return projectActionResult(false, 'Project not found.');
    }
    if (!devConsoleSaveProjectConfiguration(devConsoleRemoveProjectFromConfiguration($configuration, $projectId))) {
        return projectActionResult(false, 'Unable to save project configuration.');
    }

    return projectActionResult(true, 'Project removed from Dev Console.');
}

function projectSafeRecursiveDelete(string $path, string $expectedPath, string $repositoryPath, array &$log, string $allowedBase = '/var/www/projects'): void
{
    $target = projectNormalizePath($path);
    if ($target !== projectNormalizePath($expectedPath)) {
        throw new RuntimeException('Deletion target does not match stored project path.');
    }
    $paths = projectAssertManagedPathPolicy([
        'repository_path' => $repositoryPath,
        'production' => ['path' => $target],
        'preview' => ['path' => $target . '-delete-check'],
    ], $allowedBase);

    if (is_link($target)) {
        @unlink($target);
        $log[] = 'Deleted symlink: ' . $target;
        return;
    }
    if (!file_exists($target)) {
        return;
    }
    if (!is_dir($target)) {
        throw new RuntimeException('Deletion target is not a directory.');
    }

    foreach (scandir($target) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $target . '/' . $entry;
        if (is_link($child) || is_file($child)) {
            if (!@unlink($child)) throw new RuntimeException('Unable to delete file: ' . $child);
            $log[] = 'Deleted file: ' . $child;
        } elseif (is_dir($child)) {
            projectSafeRecursiveDelete($child, $child, $repositoryPath, $log, $allowedBase);
        }
    }
    if (!@rmdir($target)) {
        throw new RuntimeException('Unable to remove directory: ' . $target);
    }
    $log[] = 'Deleted directory: ' . $target;
}

function projectDelete(array $configuration, string $projectId, string $confirmation, array $options = []): array
{
    $project = devConsoleFindProjectById($configuration, $projectId);
    if ($project === null) return projectActionResult(false, 'Project not found.');
    if ($confirmation !== $projectId) return projectActionResult(false, 'Project confirmation did not match.');
    if (empty($project['provisioning']['managed'])) return projectActionResult(false, 'Delete Project is available only for Dev Console-managed projects.');

    $availableDir = $options['available_dir'] ?? '/etc/apache2/sites-available';
    $enabledDir = $options['enabled_dir'] ?? '/etc/apache2/sites-enabled';
    $allowedBase = $options['allowed_base'] ?? '/var/www/projects';
    $runCommands = $options['run_commands'] ?? true;
    $log = [];

    try {
        $paths = projectAssertManagedPathPolicy($project, $allowedBase);
        foreach (['production', 'preview'] as $environment) {
            $expectedVhost = projectEnvironmentVhostName($project, $environment);
            if (($project['provisioning'][$environment . '_vhost'] ?? null) !== $expectedVhost) {
                throw new RuntimeException('Managed vhost metadata does not match expected filename.');
            }
            $vhostPath = projectVhostPath($project, $environment, $availableDir);
            if (!is_file($vhostPath)) {
                throw new RuntimeException('Managed Apache config is missing: ' . basename($vhostPath));
            }
            if (!projectVhostMarkersMatch($vhostPath, $project, $environment)) {
                throw new RuntimeException('Refusing to delete unverified Apache config: ' . basename($vhostPath));
            }
        }

        if ($runCommands) {
            foreach (['production', 'preview'] as $environment) {
                if (file_exists(projectEnabledPath($project, $environment, $enabledDir))) {
                    $result = projectRunFixedCommand([projectApacheCommandPath('a2dissite'), projectEnvironmentVhostName($project, $environment)]);
                    projectAppendCommandLog($log, $result);
                    if ($result['exit_code'] !== 0) throw new RuntimeException('Unable to disable ' . $environment . ' site.');
                }
            }
            $result = projectRunFixedCommand([projectApacheCommandPath('apache2ctl'), 'configtest']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache configtest failed.');
            $result = projectRunFixedCommand([apacheSystemctlPath() ?: 'systemctl', 'reload', 'apache2']);
            projectAppendCommandLog($log, $result);
            if ($result['exit_code'] !== 0) throw new RuntimeException('Apache reload failed.');
        }

        foreach (['production', 'preview'] as $environment) {
            $vhostPath = projectVhostPath($project, $environment, $availableDir);
            if (is_file($vhostPath)) {
                if (!@unlink($vhostPath)) throw new RuntimeException('Unable to delete Apache config: ' . basename($vhostPath));
                $log[] = 'Deleted Apache config: ' . basename($vhostPath);
            }
        }
        foreach (['production', 'preview'] as $environment) {
            projectSafeRecursiveDelete($paths[$environment], $paths[$environment], (string)$project['repository_path'], $log, $allowedBase);
        }
        if (empty($options['skip_save'])) {
            if (!devConsoleSaveProjectConfiguration(devConsoleRemoveProjectFromConfiguration($configuration, $projectId))) {
                throw new RuntimeException('Unable to save project configuration.');
            }
        }

        return projectActionResult(true, 'Project deleted.', $log);
    } catch (Throwable $exception) {
        return projectActionResult(false, $exception->getMessage(), $log);
    }
}
