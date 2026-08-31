<?php

function projectAdoptionEmptyInput(): array
{
    return [
        'project_name' => '',
        'managed_server_id' => '',
        'production_domain' => '',
        'production_path' => '',
    ];
}

function projectAdoptionStatusClass(string $status): string
{
    return match ($status) {
        'READY FOR ADOPTION' => 'healthy',
        'CANNOT ADOPT' => 'error',
        default => 'warning',
    };
}

function projectAdoptionReadOnlyNotice(): string
{
    return 'Discovery is read-only. No Production files, Apache configuration, Project configuration, Git repository, GitHub repository, Preview, or Production deployment was modified.';
}

function projectAdoptionMarkerValue(string $line, string $prefix): ?string
{
    return str_starts_with($line, $prefix) ? substr($line, strlen($prefix)) : null;
}

function projectAdoptionRunRemote(array $server, string $remoteCommand, int $timeout = 20): array
{
    $ssh = function_exists('managedServersSshExecutable') ? managedServersSshExecutable() : '';
    if ($ssh === '') {
        return [
            'success' => false,
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'SSH executable missing.',
            'output' => 'SSH executable missing.',
            'duration_ms' => 0,
        ];
    }

    $key = (string)($server['key'] ?? '');
    if ($key === '' || !is_file($key) || !is_readable($key)) {
        return [
            'success' => false,
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'SSH key file missing.',
            'output' => 'SSH key file missing.',
            'duration_ms' => 0,
        ];
    }

    if (function_exists('managedServersKeyPermissionsValid') && !managedServersKeyPermissionsValid($key)) {
        return [
            'success' => false,
            'exit_code' => 127,
            'stdout' => '',
            'stderr' => 'SSH key permissions are unsafe.',
            'output' => 'SSH key permissions are unsafe.',
            'duration_ms' => 0,
        ];
    }

    return processRunCommand(managedServerRemoteSshArguments($server, $remoteCommand), [
        'timeout' => $timeout,
        'env' => ['PATH' => function_exists('serverToolsDefaultPath') ? serverToolsDefaultPath() : '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'],
        'inherit_env' => false,
    ]);
}

function projectAdoptionRemoteFailureDetails(array $probe): array
{
    $stderr = trim((string)($probe['stderr'] ?? ''));
    $stdout = trim((string)($probe['stdout'] ?? ''));
    $output = trim((string)($probe['output'] ?? ''));
    $detail = trim(implode("\n", array_filter([$stderr, $stdout], static fn(string $value): bool => $value !== '')));
    if ($detail === '') {
        $detail = $output;
    }
    if ($detail === '') {
        $detail = 'No SSH output was returned.';
    }

    return [
        'exit_code' => isset($probe['exit_code']) ? (int)$probe['exit_code'] : null,
        'detail' => substr($detail, 0, 4000),
    ];
}

function projectAdoptionApacheInventoryCommand(): string
{
    return <<<'SH'
if [ -d /etc/apache2/sites-available ]; then
  for conf in /etc/apache2/sites-available/*.conf; do
    [ -f "$conf" ] || continue
    name="$(basename "$conf")"
    enabled=0
    if [ -e "/etc/apache2/sites-enabled/$name" ]; then
      enabled=1
    fi
    server_name="$(sed -n 's/^[[:space:]]*ServerName[[:space:]]\{1,\}//Ip' "$conf" | head -n 1 | tr '\t' ' ')"
    server_alias="$(sed -n 's/^[[:space:]]*ServerAlias[[:space:]]\{1,\}//Ip' "$conf" | tr '\n' ' ' | tr '\t' ' ' | sed 's/[[:space:]][[:space:]]*/ /g;s/^ //;s/ $//')"
    document_root="$(sed -n 's/^[[:space:]]*DocumentRoot[[:space:]]\{1,\}//Ip' "$conf" | head -n 1 | sed 's/^["'\'']//;s/["'\'']$//' | tr '\t' ' ')"
    managed=0
    if grep -F '# Managed by IOVON Dev Console' "$conf" >/dev/null 2>&1; then
      managed=1
    fi
    printf "__DEV_CONSOLE_VHOST__=%s\t%s\t%s\t%s\t%s\t%s\n" "$name" "$conf" "$enabled" "$server_name" "$server_alias" "$document_root"
    if [ "$managed" = 1 ]; then
      printf "__DEV_CONSOLE_MANAGED_VHOST__=%s\n" "$name"
    fi
  done
else
  printf "__DEV_CONSOLE_APACHE_UNAVAILABLE__=1\n"
fi
exit 0
SH;
}

function projectAdoptionParseApacheInventory(string $stdout): array
{
    $sites = [];
    $managed = [];
    $apacheAvailable = true;
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if ($line === '__DEV_CONSOLE_APACHE_UNAVAILABLE__=1') {
            $apacheAvailable = false;
            continue;
        }
        $managedName = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_MANAGED_VHOST__=');
        if ($managedName !== null) {
            $managed[$managedName] = true;
            continue;
        }
        $vhost = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_VHOST__=');
        if ($vhost === null) {
            continue;
        }
        [$name, $path, $enabled, $serverName, $aliases, $documentRoot] = array_pad(explode("\t", $vhost, 6), 6, '');
        $sites[] = [
            'name' => $name,
            'path' => $path,
            'enabled' => $enabled === '1',
            'server_name' => $serverName,
            'server_aliases' => trim($aliases),
            'document_root' => trim($documentRoot),
            'managed' => false,
        ];
    }

    foreach ($sites as $index => $site) {
        $sites[$index]['managed'] = !empty($managed[(string)$site['name']]);
    }

    return ['available' => $apacheAvailable, 'sites' => $sites];
}

function projectAdoptionEmptyScanInput(): array
{
    return ['managed_server_id' => ''];
}

function projectAdoptionSiteHostnames(array $site): array
{
    $hosts = [];
    foreach ([(string)($site['server_name'] ?? ''), (string)($site['server_aliases'] ?? '')] as $value) {
        foreach (preg_split('/\s+/', $value) ?: [] as $host) {
            $host = devConsoleNormalizeDomain($host);
            if ($host !== '' && !in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
    }

    return $hosts;
}

function projectAdoptionIsInfrastructureVhost(array $site, array $hosts): bool
{
    $name = strtolower((string)($site['name'] ?? ''));
    $documentRoot = rtrim((string)($site['document_root'] ?? ''), '/');
    $meaningfulHosts = array_values(array_filter($hosts, static function (string $host): bool {
        return $host !== ''
            && $host !== '_'
            && $host !== '*'
            && $host !== 'localhost'
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }));

    return in_array($name, ['000-default.conf', 'default-ssl.conf'], true)
        || (empty($meaningfulHosts) && in_array($documentRoot, ['', '/var/www/html'], true));
}

function projectAdoptionPreferredHostname(array $hosts): string
{
    $validHosts = array_values(array_filter($hosts, static function (string $host): bool {
        return devConsoleIsHostname($host) && filter_var($host, FILTER_VALIDATE_IP) === false;
    }));
    foreach ($validHosts as $host) {
        if (!str_starts_with($host, 'www.')) {
            return $host;
        }
    }

    return $validHosts[0] ?? ($hosts[0] ?? '');
}

function projectAdoptionSuggestedName(string $domain, string $fallback): string
{
    $domain = devConsoleNormalizeDomain($domain);
    if ($domain === '') {
        return $fallback !== '' ? ucwords(str_replace(['-', '_', '.conf'], [' ', ' ', ''], $fallback)) : 'Existing Website';
    }
    $parts = explode('.', preg_replace('/^www\./', '', $domain));
    $label = $parts[0] ?? $domain;

    return ucwords(str_replace('-', ' ', $label));
}

function projectAdoptionRootDomain(string $domain): string
{
    $domain = devConsoleNormalizeDomain($domain);
    $parts = array_values(array_filter(explode('.', $domain), static fn(string $part): bool => $part !== ''));
    if (count($parts) < 2) {
        return $domain;
    }

    return implode('.', array_slice($parts, -2));
}

function projectAdoptionProjectMatch(array $candidate, array $projects): ?string
{
    $candidatePath = (string)($candidate['document_root'] ?? '');
    $candidateHosts = is_array($candidate['hosts'] ?? null) ? $candidate['hosts'] : [];
    foreach ($projects as $project) {
        $projectName = (string)($project['name'] ?? ($project['id'] ?? ''));
        $productionDomain = devConsoleNormalizeDomain((string)($project['production']['domain'] ?? ''));
        $previewDomain = devConsoleNormalizeDomain((string)($project['preview']['domain'] ?? ''));
        $productionPath = (string)($project['production']['path'] ?? '');
        if ($candidatePath !== '' && $productionPath !== '' && $candidatePath === $productionPath) {
            return $projectName;
        }
        foreach ($candidateHosts as $host) {
            $host = devConsoleNormalizeDomain((string)$host);
            if ($host !== '' && ($host === $productionDomain || $host === $previewDomain)) {
                return $projectName;
            }
        }
    }

    return null;
}

function projectAdoptionGroupApacheSites(array $sites, array $projects): array
{
    $groups = [];
    foreach ($sites as $site) {
        $hosts = projectAdoptionSiteHostnames($site);
        $documentRoot = (string)($site['document_root'] ?? '');
        $primary = projectAdoptionPreferredHostname($hosts);
        $key = $documentRoot !== '' ? 'path:' . $documentRoot : 'host:' . ($primary !== '' ? $primary : (string)($site['name'] ?? ''));

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'type' => 'Apache site',
                'domain' => $primary,
                'project_name' => projectAdoptionSuggestedName($primary, (string)($site['name'] ?? '')),
                'document_root' => $documentRoot,
                'hosts' => [],
                'vhosts' => [],
                'enabled' => false,
                'managed' => false,
                'infrastructure' => false,
                'needs_review' => false,
                'existing_project' => '',
            ];
        }

        $groups[$key]['enabled'] = !empty($groups[$key]['enabled']) || !empty($site['enabled']);
        $groups[$key]['managed'] = !empty($groups[$key]['managed']) || !empty($site['managed']);
        $groups[$key]['infrastructure'] = !empty($groups[$key]['infrastructure']) || projectAdoptionIsInfrastructureVhost($site, $hosts);
        $groups[$key]['vhosts'][] = $site;
        foreach ($hosts as $host) {
            if (!in_array($host, $groups[$key]['hosts'], true)) {
                $groups[$key]['hosts'][] = $host;
            }
        }
        if ($groups[$key]['domain'] === '') {
            $groups[$key]['domain'] = projectAdoptionPreferredHostname($groups[$key]['hosts']);
            $groups[$key]['project_name'] = projectAdoptionSuggestedName($groups[$key]['domain'], (string)($site['name'] ?? ''));
        }
        if ($groups[$key]['document_root'] === '' && $documentRoot !== '') {
            $groups[$key]['document_root'] = $documentRoot;
        }
    }

    $candidates = array_values($groups);
    foreach ($candidates as $index => $candidate) {
        $candidate['needs_review'] = (string)$candidate['domain'] === '' || (string)$candidate['document_root'] === '';
        $candidate['existing_project'] = projectAdoptionProjectMatch($candidate, $projects) ?? '';
        if (!empty($candidate['infrastructure'])) {
            $candidate['type'] = 'Infrastructure / Default';
            $candidate['status'] = 'Infrastructure / Default';
        } elseif ($candidate['existing_project'] !== '') {
            $candidate['status'] = 'Already in Dev Console';
        } elseif (!empty($candidate['needs_review'])) {
            $candidate['status'] = 'Site configuration needs review';
        } elseif (!empty($candidate['enabled'])) {
            $candidate['status'] = 'Enabled';
        } else {
            $candidate['status'] = 'Disabled';
        }
        $candidate['inspectable'] = empty($candidate['infrastructure']) && (string)$candidate['domain'] !== '' && (string)$candidate['document_root'] !== '';
        $candidates[$index] = $candidate;
    }

    usort($candidates, static function (array $left, array $right): int {
        return [(int)!empty($left['infrastructure']), (string)($left['domain'] ?? ''), (string)($left['document_root'] ?? '')]
            <=> [(int)!empty($right['infrastructure']), (string)($right['domain'] ?? ''), (string)($right['document_root'] ?? '')];
    });

    return $candidates;
}

function projectAdoptionFilesystemDirectoryScanCommand(): string
{
    return <<<'SH'
if [ -d /var/www ] && [ -r /var/www ]; then
  find /var/www -mindepth 1 -maxdepth 1 -type d -print 2>/dev/null | sort | sed 's/^/__DEV_CONSOLE_WWW_DIR__=/'
else
  printf "__DEV_CONSOLE_WWW_UNAVAILABLE__=1\n"
fi
exit 0
SH;
}

function projectAdoptionParseFilesystemDirectoryScan(string $stdout): array
{
    $directories = [];
    $available = true;
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if ($line === '__DEV_CONSOLE_WWW_UNAVAILABLE__=1') {
            $available = false;
            continue;
        }
        $path = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_WWW_DIR__=');
        if ($path !== null && devConsoleIsAbsoluteUnixPath($path) && !devConsoleHasControlCharacters($path)) {
            $directories[] = $path;
        }
    }

    return ['available' => $available, 'directories' => array_values(array_unique($directories))];
}

function projectAdoptionInspectionHasProjectSignals(array $inspection): bool
{
    $git = is_array($inspection['git'] ?? null) ? $inspection['git'] : [];
    $tasks = is_array($inspection['tasks'] ?? null) ? $inspection['tasks'] : [];
    $technology = is_array($inspection['technology'] ?? null) ? $inspection['technology'] : [];

    return !empty($git['repository_detected'])
        || !empty($tasks['detected'])
        || !empty($technology['composer_json'])
        || !empty($technology['package_json'])
        || !empty($technology['index_php'])
        || !empty($technology['public_directory']);
}

function projectAdoptionEnrichScanSites(array $sites, array $server, array $filesystemDirectories = []): array
{
    $inspectionCache = [];
    foreach ($sites as $index => $site) {
        $path = (string)($site['document_root'] ?? '');
        if (!empty($site['infrastructure']) || $path === '' || !devConsoleIsAbsoluteUnixPath($path) || devConsoleHasControlCharacters($path)) {
            continue;
        }
        if (!array_key_exists($path, $inspectionCache)) {
            $probe = projectAdoptionRunRemote($server, projectAdoptionInspectPathCommand($path), 35);
            $inspectionCache[$path] = empty($probe['success'])
                ? ['error' => projectAdoptionRemoteFailureDetails($probe)]
                : ['inspection' => projectAdoptionParsePathInspection((string)($probe['stdout'] ?? ''))];
        }
        if (isset($inspectionCache[$path]['inspection'])) {
            $sites[$index]['inspection'] = $inspectionCache[$path]['inspection'];
        } elseif (isset($inspectionCache[$path]['error'])) {
            $sites[$index]['inspection_error'] = $inspectionCache[$path]['error'];
        }
    }

    $knownPaths = [];
    foreach ($sites as $site) {
        $path = (string)($site['document_root'] ?? '');
        if ($path !== '') {
            $knownPaths[$path] = true;
        }
    }

    foreach ($filesystemDirectories as $path) {
        $path = (string)$path;
        if ($path === '' || isset($knownPaths[$path]) || !devConsoleIsAbsoluteUnixPath($path) || devConsoleHasControlCharacters($path)) {
            continue;
        }
        if (!array_key_exists($path, $inspectionCache)) {
            $probe = projectAdoptionRunRemote($server, projectAdoptionInspectPathCommand($path), 35);
            $inspectionCache[$path] = empty($probe['success'])
                ? ['error' => projectAdoptionRemoteFailureDetails($probe)]
                : ['inspection' => projectAdoptionParsePathInspection((string)($probe['stdout'] ?? ''))];
        }
        if (!isset($inspectionCache[$path]['inspection']) || !projectAdoptionInspectionHasProjectSignals($inspectionCache[$path]['inspection'])) {
            continue;
        }

        $sites[] = [
            'type' => 'Project Source / Workspace',
            'domain' => '',
            'project_name' => projectAdoptionSuggestedName('', basename($path)),
            'document_root' => $path,
            'hosts' => [],
            'vhosts' => [],
            'enabled' => false,
            'managed' => false,
            'infrastructure' => false,
            'needs_review' => false,
            'existing_project' => '',
            'status' => 'Project Source / Workspace',
            'inspectable' => false,
            'inspection' => $inspectionCache[$path]['inspection'],
        ];
    }

    return $sites;
}

function projectAdoptionScanServer(array $input, array $managedServers, array $projects): array
{
    $values = projectAdoptionEmptyScanInput();
    $values['managed_server_id'] = is_scalar($input['managed_server_id'] ?? null) ? trim((string)$input['managed_server_id']) : '';
    $errors = [];
    $warnings = [];
    $server = $values['managed_server_id'] === '' ? null : managedServersFind($managedServers, $values['managed_server_id']);
    if ($values['managed_server_id'] === '') {
        $errors[] = 'Managed Server is required.';
    } elseif ($server === null) {
        $errors[] = 'Selected Managed Server does not exist.';
    }

    $result = [
        'success' => false,
        'status' => 'Cannot scan',
        'values' => $values,
        'errors' => $errors,
        'warnings' => [],
        'server' => $server === null ? $values['managed_server_id'] : devConsoleManagedServerLabel($server, $values['managed_server_id']),
        'sites' => [],
        'apache_available' => null,
        'ssh_error' => null,
        'generated_at' => date('c'),
        'safety' => [projectAdoptionReadOnlyNotice()],
    ];
    if (!empty($errors) || $server === null) {
        return $result;
    }

    $apacheProbe = projectAdoptionRunRemote($server, projectAdoptionApacheInventoryCommand(), 20);
    if (empty($apacheProbe['success'])) {
        $result['errors'][] = managedServerConnectionResultMessage((string)($apacheProbe['output'] ?? 'Server scan failed.'));
        $result['ssh_error'] = projectAdoptionRemoteFailureDetails($apacheProbe);
        return $result;
    }

    $inventory = projectAdoptionParseApacheInventory((string)($apacheProbe['stdout'] ?? ''));
    $result['apache_available'] = (bool)$inventory['available'];
    if (empty($inventory['available'])) {
        $result['status'] = 'Apache not detected';
        $result['warnings'][] = 'Apache site configuration directory was not detected on this Managed Server.';
    }

    $filesystemDirectories = [];
    $filesystemProbe = projectAdoptionRunRemote($server, projectAdoptionFilesystemDirectoryScanCommand(), 20);
    if (empty($filesystemProbe['success'])) {
        $result['warnings'][] = 'Filesystem source scan could not be completed.';
    } else {
        $filesystemScan = projectAdoptionParseFilesystemDirectoryScan((string)($filesystemProbe['stdout'] ?? ''));
        if (empty($filesystemScan['available'])) {
            $result['warnings'][] = '/var/www was not readable for filesystem source discovery.';
        }
        $filesystemDirectories = $filesystemScan['directories'];
    }

    $result['sites'] = projectAdoptionEnrichScanSites(projectAdoptionGroupApacheSites($inventory['sites'], $projects), $server, $filesystemDirectories);
    if (empty($result['sites'])) {
        $result['status'] = 'No candidate sites found';
        $result['warnings'][] = 'No Apache virtual host configuration files were found.';
    } else {
        $result['success'] = true;
        $result['status'] = 'Scan complete';
    }
    $result['warnings'] = array_values(array_unique(array_merge($warnings, $result['warnings'])));

    return $result;
}

function projectAdoptionSiteMatchesDomain(array $site, string $domain): bool
{
    $domain = devConsoleNormalizeDomain($domain);
    if ($domain === '') {
        return false;
    }
    if (devConsoleNormalizeDomain((string)($site['server_name'] ?? '')) === $domain) {
        return true;
    }
    foreach (preg_split('/\s+/', (string)($site['server_aliases'] ?? '')) ?: [] as $alias) {
        if (devConsoleNormalizeDomain($alias) === $domain) {
            return true;
        }
    }

    return false;
}

function projectAdoptionInspectPathCommand(string $path): string
{
    $quotedPath = managedServersShellQuote($path);

    return 'p=' . $quotedPath . '; ' . <<<'SH'
printf "__DEV_CONSOLE_PATH__=%s\n" "$p"
[ -e "$p" ] && printf "__DEV_CONSOLE_EXISTS__=1\n" || printf "__DEV_CONSOLE_EXISTS__=0\n"
[ -d "$p" ] && printf "__DEV_CONSOLE_IS_DIR__=1\n" || printf "__DEV_CONSOLE_IS_DIR__=0\n"
[ -r "$p" ] && printf "__DEV_CONSOLE_READABLE__=1\n" || printf "__DEV_CONSOLE_READABLE__=0\n"
if [ -e "$p" ]; then stat -c "__DEV_CONSOLE_OWNER__=%U\t%G" "$p" 2>/dev/null || true; fi
if [ -d "$p" ] && [ -r "$p" ]; then
  du -sb "$p" 2>/dev/null | awk '{print "__DEV_CONSOLE_SIZE__="$1}' || true
  find "$p" -type f -printf "." 2>/dev/null | wc -c | awk '{print "__DEV_CONSOLE_FILE_COUNT__="$1}' || true
  find "$p" -type f -name "*.php" -print -quit 2>/dev/null | grep -q . && printf "__DEV_CONSOLE_HAS_PHP__=1\n" || printf "__DEV_CONSOLE_HAS_PHP__=0\n"
  for marker in composer.json composer.lock package.json package-lock.json yarn.lock pnpm-lock.yaml public index.php index.html .env; do
    if [ -e "$p/$marker" ]; then printf "__DEV_CONSOLE_MARKER__=%s\n" "$marker"; fi
  done
  if command -v git >/dev/null 2>&1; then
    printf "__DEV_CONSOLE_GIT_AVAILABLE__=1\n"
    if [ -d "$p/.git" ] || [ -f "$p/.git" ]; then
      printf "__DEV_CONSOLE_GIT_REPO__=1\n"
      git -C "$p" branch --show-current 2>/dev/null | sed "s/^/__DEV_CONSOLE_GIT_BRANCH__=/"
      git -C "$p" rev-parse HEAD 2>/dev/null | sed "s/^/__DEV_CONSOLE_GIT_HEAD__=/"
      git -C "$p" rev-parse --verify HEAD >/dev/null 2>&1 && printf "__DEV_CONSOLE_GIT_HISTORY__=1\n" || printf "__DEV_CONSOLE_GIT_HISTORY__=0\n"
      git -C "$p" remote -v 2>/dev/null | sed "s/^/__DEV_CONSOLE_GIT_REMOTE__=/"
    else
      printf "__DEV_CONSOLE_GIT_REPO__=0\n"
    fi
  else
    printf "__DEV_CONSOLE_GIT_AVAILABLE__=0\n"
  fi
  if [ -d "$p/TASKS" ]; then
    printf "__DEV_CONSOLE_TASKS__=1\n"
    for d in TODO "IN PROGRESS" DONE DROPPED attachments ATTACHMENTS; do
      if [ -d "$p/TASKS/$d" ]; then printf "__DEV_CONSOLE_TASK_DIR__=%s\n" "$d"; fi
    done
    find "$p/TASKS" -type f 2>/dev/null | awk -v root="$p/TASKS/" '
      {
        rel=$0
        sub("^" root, "", rel)
        total++
        dir=rel
        if (dir ~ /\//) {
          sub("/[^/]*$", "", dir)
        } else {
          dir="."
        }
        dirs[dir]++
        base=rel
        sub("^.*/", "", base)
        if (base ~ /^TASK-[0-9][0-9][0-9]\.md$/) {
          expected++
          n=substr(base, 6, 3) + 0
          nums[n]++
          if (min == 0 || n < min) min=n
          if (n > max) max=n
        } else if (base ~ /TASK|task|\.md$/) {
          other++
          if (other <= 40) print "__DEV_CONSOLE_TASK_OTHER__=" rel
        }
      }
      END {
        print "__DEV_CONSOLE_TASK_TOTAL_FILES__=" (total + 0)
        print "__DEV_CONSOLE_TASK_EXPECTED_COUNT__=" (expected + 0)
        print "__DEV_CONSOLE_TASK_OTHER_COUNT__=" (other + 0)
        if (min > 0) print "__DEV_CONSOLE_TASK_MIN__=" min
        if (max > 0) print "__DEV_CONSOLE_TASK_MAX__=" max
        for (dir in dirs) print "__DEV_CONSOLE_TASK_DIR_COUNT__=" dir "\t" dirs[dir]
        for (n in nums) if (nums[n] > 1) printf "__DEV_CONSOLE_TASK_DUPLICATE__=TASK-%03d\t%d\n", n, nums[n]
        if (min > 0 && max > 0) {
          for (i=min; i<=max; i++) if (!(i in nums)) printf "__DEV_CONSOLE_TASK_MISSING__=TASK-%03d\n", i
        }
      }'
    if command -v sha256sum >/dev/null 2>&1; then
      (
        cd "$p/TASKS" &&
        find . -type f -name "TASK-[0-9][0-9][0-9].md" -print 2>/dev/null |
        sort |
        while IFS= read -r file; do
          sha256sum "$file" | awk -v rel="${file#./}" '{print rel "\t" $1}'
        done |
        sha256sum |
        awk '{print "__DEV_CONSOLE_TASK_FINGERPRINT__="$1}'
      ) || true
    fi
  else
    printf "__DEV_CONSOLE_TASKS__=0\n"
  fi
fi
exit 0
SH;
}

function projectAdoptionParsePathInspection(string $stdout): array
{
    $filesystem = [
        'path' => '',
        'exists' => false,
        'is_dir' => false,
        'readable' => false,
        'owner' => '',
        'group' => '',
        'size_bytes' => null,
        'file_count' => null,
    ];
    $technology = [
        'php_files' => false,
        'composer_json' => false,
        'composer_lock' => false,
        'package_json' => false,
        'package_lock' => false,
        'yarn_lock' => false,
        'pnpm_lock' => false,
        'public_directory' => false,
        'index_php' => false,
        'index_html' => false,
        'markers' => [],
    ];
    $configuration = ['env_present' => false];
    $git = [
        'inspection' => 'Not detected',
        'git_available' => null,
        'repository_detected' => false,
        'branch' => '',
        'head' => '',
        'history_available' => false,
        'remotes' => [],
    ];
    $tasks = [
        'detected' => false,
        'directories' => [],
        'task_count' => 0,
        'highest_task_number' => '',
        'total_files' => 0,
        'expected_task_count' => 0,
        'other_task_count' => 0,
        'directory_counts' => [],
        'minimum_task_number' => '',
        'maximum_task_number' => '',
        'missing_task_numbers' => [],
        'nonstandard_task_files' => [],
        'duplicate_task_numbers' => [],
        'fingerprint' => '',
        'compatible' => false,
    ];

    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_PATH__=')) !== null) {
            $filesystem['path'] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_EXISTS__=')) !== null) {
            $filesystem['exists'] = $value === '1';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_IS_DIR__=')) !== null) {
            $filesystem['is_dir'] = $value === '1';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_READABLE__=')) !== null) {
            $filesystem['readable'] = $value === '1';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_OWNER__=')) !== null) {
            [$owner, $group] = array_pad(explode("\t", $value, 2), 2, '');
            $filesystem['owner'] = $owner;
            $filesystem['group'] = $group;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_SIZE__=')) !== null) {
            $filesystem['size_bytes'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_FILE_COUNT__=')) !== null) {
            $filesystem['file_count'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_HAS_PHP__=')) !== null) {
            $technology['php_files'] = $value === '1';
        } elseif (($marker = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_MARKER__=')) !== null) {
            $technology['markers'][] = $marker;
            match ($marker) {
                'composer.json' => $technology['composer_json'] = true,
                'composer.lock' => $technology['composer_lock'] = true,
                'package.json' => $technology['package_json'] = true,
                'package-lock.json' => $technology['package_lock'] = true,
                'yarn.lock' => $technology['yarn_lock'] = true,
                'pnpm-lock.yaml' => $technology['pnpm_lock'] = true,
                'public' => $technology['public_directory'] = true,
                'index.php' => $technology['index_php'] = true,
                'index.html' => $technology['index_html'] = true,
                '.env' => $configuration['env_present'] = true,
                default => null,
            };
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_AVAILABLE__=')) !== null) {
            $git['git_available'] = $value === '1';
            $git['inspection'] = $git['git_available'] ? 'Not detected' : 'Inspection unavailable';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_REPO__=')) !== null) {
            $git['repository_detected'] = $value === '1';
            if ($git['repository_detected']) {
                $git['inspection'] = 'Detected';
            }
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_BRANCH__=')) !== null) {
            $git['branch'] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_HEAD__=')) !== null) {
            $git['head'] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_HISTORY__=')) !== null) {
            $git['history_available'] = $value === '1';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_GIT_REMOTE__=')) !== null) {
            $git['remotes'][] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASKS__=')) !== null) {
            $tasks['detected'] = $value === '1';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_DIR__=')) !== null) {
            $tasks['directories'][] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_COUNT__=')) !== null) {
            $tasks['task_count'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_HIGHEST__=')) !== null) {
            $highest = (int)$value;
            $tasks['highest_task_number'] = $highest > 0 ? sprintf('TASK-%03d', $highest) : '';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_TOTAL_FILES__=')) !== null) {
            $tasks['total_files'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_EXPECTED_COUNT__=')) !== null) {
            $tasks['expected_task_count'] = (int)$value;
            $tasks['task_count'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_OTHER_COUNT__=')) !== null) {
            $tasks['other_task_count'] = (int)$value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_DIR_COUNT__=')) !== null) {
            [$directory, $count] = array_pad(explode("\t", $value, 2), 2, '0');
            $tasks['directory_counts'][$directory] = (int)$count;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_MIN__=')) !== null) {
            $minimum = (int)$value;
            $tasks['minimum_task_number'] = $minimum > 0 ? sprintf('TASK-%03d', $minimum) : '';
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_MAX__=')) !== null) {
            $maximum = (int)$value;
            $tasks['maximum_task_number'] = $maximum > 0 ? sprintf('TASK-%03d', $maximum) : '';
            $tasks['highest_task_number'] = $maximum > 0 ? sprintf('TASK-%03d', $maximum) : $tasks['highest_task_number'];
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_MISSING__=')) !== null) {
            $tasks['missing_task_numbers'][] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_OTHER__=')) !== null) {
            $tasks['nonstandard_task_files'][] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_DUPLICATE__=')) !== null) {
            [$taskId, $count] = array_pad(explode("\t", $value, 2), 2, '0');
            $tasks['duplicate_task_numbers'][] = ['task_id' => $taskId, 'count' => (int)$count];
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_TASK_FINGERPRINT__=')) !== null) {
            $tasks['fingerprint'] = trim($value);
        }
    }

    $technology['markers'] = array_values(array_unique($technology['markers']));
    $tasks['directories'] = array_values(array_unique($tasks['directories']));
    $tasks['missing_task_numbers'] = array_values(array_unique($tasks['missing_task_numbers']));
    $tasks['nonstandard_task_files'] = array_values(array_unique($tasks['nonstandard_task_files']));
    if ($tasks['expected_task_count'] === 0 && $tasks['task_count'] > 0) {
        $tasks['expected_task_count'] = $tasks['task_count'];
    }
    $tasks['compatible'] = projectAdoptionTasksCompatible($tasks);

    return compact('filesystem', 'technology', 'configuration', 'git', 'tasks');
}

function projectAdoptionTasksCompatible(array $tasks): bool
{
    if (empty($tasks['detected'])) {
        return false;
    }

    $directories = is_array($tasks['directories'] ?? null) ? $tasks['directories'] : [];
    if (empty(array_intersect(['TODO', 'IN PROGRESS', 'DONE', 'DROPPED'], $directories))) {
        return false;
    }
    if (!empty($tasks['duplicate_task_numbers'])) {
        return false;
    }
    if (!empty($tasks['nonstandard_task_files'])) {
        return false;
    }

    return (int)($tasks['expected_task_count'] ?? 0) > 0 || (int)($tasks['task_count'] ?? 0) > 0;
}

function projectAdoptionInspectionHasDevConsoleHistory(array $inspection): bool
{
    return !empty($inspection['tasks']['detected']) || !empty($inspection['tasks']['compatible']);
}

function projectAdoptionFirstGithubRemote(array $remotes): string
{
    foreach ($remotes as $remote) {
        $remote = trim((string)$remote);
        if ($remote === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $remote) ?: [];
        foreach ($parts as $part) {
            if (str_contains($part, 'github.com')) {
                return $part;
            }
        }
    }

    return '';
}

function projectAdoptionSiteInspectionSummary(array $site): array
{
    $inspection = is_array($site['inspection'] ?? null) ? $site['inspection'] : [];
    $git = is_array($inspection['git'] ?? null) ? $inspection['git'] : [];
    $tasks = is_array($inspection['tasks'] ?? null) ? $inspection['tasks'] : [];

    return [
        'git' => !empty($git['repository_detected']),
        'tasks' => !empty($tasks['detected']),
        'history' => projectAdoptionInspectionHasDevConsoleHistory($inspection),
        'task_count' => (int)($tasks['expected_task_count'] ?? ($tasks['task_count'] ?? 0)),
        'task_max' => projectAdoptionTaskNumberValue((string)($tasks['maximum_task_number'] ?? ($tasks['highest_task_number'] ?? ''))),
        'highest_task_number' => (string)($tasks['highest_task_number'] ?? ''),
        'task_fingerprint' => (string)($tasks['fingerprint'] ?? ''),
        'remotes' => is_array($git['remotes'] ?? null) ? $git['remotes'] : [],
    ];
}

function projectAdoptionTaskNumberValue(string $taskId): int
{
    if (preg_match('/TASK-(\d+)/', $taskId, $matches) !== 1) {
        return 0;
    }

    return (int)$matches[1];
}

function projectAdoptionCandidateNameMatches(array $site, array $values): bool
{
    $domain = devConsoleNormalizeDomain((string)($values['production_domain'] ?? ''));
    $domainLabel = preg_replace('/^www\./', '', $domain);
    $domainLabel = explode('.', $domainLabel)[0] ?? '';
    $projectName = strtolower(preg_replace('/[^a-z0-9]+/', '-', (string)($values['project_name'] ?? '')));
    $projectName = trim($projectName, '-');
    $pathName = strtolower((string)basename((string)($site['document_root'] ?? '')));
    $siteDomain = devConsoleNormalizeDomain((string)($site['domain'] ?? ''));
    $siteDomainLabel = explode('.', preg_replace('/^www\./', '', $siteDomain))[0] ?? '';

    foreach ([$domainLabel, $projectName] as $token) {
        if (strlen((string)$token) < 4) {
            continue;
        }
        if ($pathName !== '' && str_contains($pathName, (string)$token)) {
            return true;
        }
        if ($siteDomainLabel !== '' && str_contains($siteDomainLabel, (string)$token)) {
            return true;
        }
    }

    return false;
}

function projectAdoptionCandidateSharesRemote(array $selectedInspection, array $summary): bool
{
    $selectedRemotes = is_array($selectedInspection['git']['remotes'] ?? null) ? $selectedInspection['git']['remotes'] : [];
    $candidateRemotes = is_array($summary['remotes'] ?? null) ? $summary['remotes'] : [];
    if (empty($selectedRemotes) || empty($candidateRemotes)) {
        return false;
    }

    foreach ($selectedRemotes as $selectedRemote) {
        foreach ($candidateRemotes as $candidateRemote) {
            if ((string)$selectedRemote !== '' && (string)$selectedRemote === (string)$candidateRemote) {
                return true;
            }
        }
    }

    return false;
}

function projectAdoptionBuildRelationships(array $values, array $selectedInspection, ?array $scanContext): array
{
    $sites = is_array($scanContext['sites'] ?? null) ? $scanContext['sites'] : [];
    $selectedDomain = devConsoleNormalizeDomain((string)$values['production_domain']);
    $selectedPath = (string)$values['production_path'];
    $selectedRootDomain = projectAdoptionRootDomain($selectedDomain);
    $related = [];
    $sourceCandidates = [];

    foreach ($sites as $site) {
        if (!empty($site['infrastructure'])) {
            continue;
        }
        $domain = devConsoleNormalizeDomain((string)($site['domain'] ?? ''));
        $path = (string)($site['document_root'] ?? '');
        $hosts = is_array($site['hosts'] ?? null) ? $site['hosts'] : [];
        $summary = projectAdoptionSiteInspectionSummary($site);
        $reasons = [];
        $score = 0;
        $weakOnly = true;

        if ($path !== '' && $path === $selectedPath) {
            $reasons[] = 'Same DocumentRoot as inspected site.';
            $score += 5;
            $weakOnly = false;
        }
        if ($domain !== '' && $selectedRootDomain !== '' && projectAdoptionRootDomain($domain) === $selectedRootDomain) {
            $reasons[] = 'Shared root domain weak signal.';
            $score += 1;
        } else {
            foreach ($hosts as $host) {
                if ($selectedRootDomain !== '' && projectAdoptionRootDomain((string)$host) === $selectedRootDomain) {
                    $reasons[] = 'Shared root-domain alias weak signal.';
                    $score += 1;
                    break;
                }
            }
        }
        if (projectAdoptionCandidateSharesRemote($selectedInspection, $summary)) {
            $reasons[] = 'Matching Git remote.';
            $score += 4;
            $weakOnly = false;
        }
        if (projectAdoptionCandidateNameMatches($site, $values)) {
            $reasons[] = 'Related project name or path.';
            $score += 2;
            $weakOnly = false;
        }
        if (!empty($summary['history']) && ($score > 0 || projectAdoptionCandidateNameMatches($site, $values))) {
            $reasons[] = 'Dev Console history markers detected.';
            $score += 3;
            $weakOnly = false;
        } elseif (!empty($summary['tasks']) && ($score > 0 || projectAdoptionCandidateNameMatches($site, $values))) {
            $reasons[] = 'TASKS history detected.';
            $score += 2;
            $weakOnly = false;
        } elseif (!empty($summary['git']) && ($score > 0 || projectAdoptionCandidateNameMatches($site, $values))) {
            $reasons[] = 'Git repository detected.';
            $score += 2;
            $weakOnly = false;
        }

        $isSelected = $path !== '' && $path === $selectedPath;
        if (!$isSelected && ($score < 2 || $weakOnly)) {
            continue;
        }

        $role = $isSelected
            ? 'Production'
            : ((!empty($summary['git']) || !empty($summary['tasks'])) ? 'Historical Preview / Development / Project Source' : 'Related site');
        $item = [
            'domain' => $domain,
            'path' => $path,
            'role' => $role,
            'git' => $summary['git'],
            'tasks' => $summary['tasks'],
            'history' => $summary['history'],
            'task_count' => $summary['task_count'],
            'task_max' => $summary['task_max'],
            'highest_task_number' => $summary['highest_task_number'],
            'task_fingerprint' => (string)($summary['task_fingerprint'] ?? ''),
            'branch' => (string)($site['inspection']['git']['branch'] ?? ''),
            'head' => (string)($site['inspection']['git']['head'] ?? ''),
            'remotes' => is_array($site['inspection']['git']['remotes'] ?? null) ? $site['inspection']['git']['remotes'] : [],
            'relationship_score' => $score,
            'reason' => implode(' ', array_values(array_unique($reasons))),
        ];
        $related[] = $item;
        if (!$isSelected && (!empty($summary['git']) || !empty($summary['tasks']))) {
            $sourceCandidates[] = $item;
        }
    }

    if (empty($related)) {
        $selectedGit = !empty($selectedInspection['git']['repository_detected']);
        $selectedTasks = !empty($selectedInspection['tasks']['detected']);
        $related[] = [
            'domain' => $selectedDomain,
            'path' => $selectedPath,
            'role' => 'Production',
            'git' => $selectedGit,
            'tasks' => $selectedTasks,
            'history' => projectAdoptionInspectionHasDevConsoleHistory($selectedInspection),
            'task_count' => (int)($selectedInspection['tasks']['expected_task_count'] ?? ($selectedInspection['tasks']['task_count'] ?? 0)),
            'task_max' => projectAdoptionTaskNumberValue((string)($selectedInspection['tasks']['maximum_task_number'] ?? ($selectedInspection['tasks']['highest_task_number'] ?? ''))),
            'highest_task_number' => (string)($selectedInspection['tasks']['highest_task_number'] ?? ''),
            'task_fingerprint' => (string)($selectedInspection['tasks']['fingerprint'] ?? ''),
            'branch' => (string)($selectedInspection['git']['branch'] ?? ''),
            'head' => (string)($selectedInspection['git']['head'] ?? ''),
            'remotes' => is_array($selectedInspection['git']['remotes'] ?? null) ? $selectedInspection['git']['remotes'] : [],
            'relationship_score' => 5,
            'reason' => 'Inspected site.',
        ];
        if ($selectedGit || $selectedTasks) {
            $sourceCandidates[] = $related[0];
        }
    }

    if (count($sourceCandidates) === 0 && (!empty($selectedInspection['git']['repository_detected']) || !empty($selectedInspection['tasks']['detected']))) {
        $sourceCandidates[] = [
            'domain' => $selectedDomain,
            'path' => $selectedPath,
            'role' => 'Project Source',
            'git' => !empty($selectedInspection['git']['repository_detected']),
            'tasks' => !empty($selectedInspection['tasks']['detected']),
            'history' => projectAdoptionInspectionHasDevConsoleHistory($selectedInspection),
            'task_count' => (int)($selectedInspection['tasks']['expected_task_count'] ?? ($selectedInspection['tasks']['task_count'] ?? 0)),
            'task_max' => projectAdoptionTaskNumberValue((string)($selectedInspection['tasks']['maximum_task_number'] ?? ($selectedInspection['tasks']['highest_task_number'] ?? ''))),
            'highest_task_number' => (string)($selectedInspection['tasks']['highest_task_number'] ?? ''),
            'task_fingerprint' => (string)($selectedInspection['tasks']['fingerprint'] ?? ''),
            'branch' => (string)($selectedInspection['git']['branch'] ?? ''),
            'head' => (string)($selectedInspection['git']['head'] ?? ''),
            'remotes' => is_array($selectedInspection['git']['remotes'] ?? null) ? $selectedInspection['git']['remotes'] : [],
            'relationship_score' => 5,
            'reason' => 'Inspected site contains Git or TASKS history.',
        ];
    }

    $proposal = null;
    $warnings = [];
    if (count($sourceCandidates) > 1) {
        usort($sourceCandidates, static fn(array $left, array $right): int => ((int)($right['task_max'] ?? 0)) <=> ((int)($left['task_max'] ?? 0)));
    }
    if (count($sourceCandidates) === 1 || (count($sourceCandidates) > 1 && (int)($sourceCandidates[0]['task_max'] ?? 0) > (int)($sourceCandidates[1]['task_max'] ?? 0))) {
        $source = $sourceCandidates[0];
        $proposal = [
            'project_name' => (string)$values['project_name'],
            'source_path' => (string)$source['path'],
            'source_domain' => (string)$source['domain'],
            'source_git' => !empty($source['git']),
            'source_tasks' => !empty($source['tasks']),
            'source_branch' => (string)($source['branch'] ?? ''),
            'source_head' => (string)($source['head'] ?? ''),
            'source_remote' => projectAdoptionFirstGithubRemote(is_array($source['remotes'] ?? null) ? $source['remotes'] : []),
            'source_task_count' => (int)($source['task_count'] ?? 0),
            'source_highest_task_number' => (string)($source['highest_task_number'] ?? ''),
            'source_task_fingerprint' => (string)($source['task_fingerprint'] ?? ''),
            'production_domain' => $selectedDomain,
            'production_path' => $selectedPath,
            'historical_preview_domain' => '',
            'historical_preview_path' => '',
            'status' => 'Proposed',
        ];
    } elseif (count($sourceCandidates) > 1) {
        $warnings[] = 'Multiple possible Project Source locations were detected without a clearly newer task history. Review related sites before adoption.';
    }

    return [
        'related_sites' => $related,
        'proposed_structure' => $proposal,
        'warnings' => $warnings,
    ];
}

function projectAdoptionProductionOnlyFilesCommand(string $productionPath, string $sourcePath): string
{
    $quotedProduction = managedServersShellQuote($productionPath);
    $quotedSource = managedServersShellQuote($sourcePath);

    return 'prod=' . $quotedProduction . '; src=' . $quotedSource . '; ' . <<<'SH'
count=0
if [ -d "$prod" ] && [ -r "$prod" ] && [ -d "$src" ]; then
  while IFS= read -r file; do
    rel=${file#"$prod"/}
    case "$rel" in
      .git/*|TASKS/*|vendor/*|node_modules/*|tools/dev-console/runs/*) continue ;;
    esac
    if [ ! -e "$src/$rel" ]; then
      count=$((count + 1))
      if [ "$count" -le 80 ]; then
        printf "__DEV_CONSOLE_PRODUCTION_ONLY__=%s\n" "$rel"
      fi
    fi
  done <<EOF
$(find "$prod" -type f 2>/dev/null)
EOF
fi
printf "__DEV_CONSOLE_PRODUCTION_ONLY_COUNT__=%s\n" "$count"
exit 0
SH;
}

function projectAdoptionPlanDisplayToken(string $value): string
{
    $value = trim($value);
    return $value === '' ? 'Not configured' : $value;
}

function projectAdoptionParseProductionOnlyFiles(string $stdout): array
{
    $files = [];
    $count = 0;
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_PRODUCTION_ONLY__=')) !== null) {
            $files[] = $value;
        } elseif (($value = projectAdoptionMarkerValue($line, '__DEV_CONSOLE_PRODUCTION_ONLY_COUNT__=')) !== null) {
            $count = max(0, (int)$value);
        }
    }

    return ['count' => $count, 'files' => array_values(array_unique($files))];
}

function projectAdoptionFindRelatedPreview(array $relatedSites, string $productionPath, string $productionDomain, string $sourcePath): array
{
    $productionDomain = devConsoleNormalizeDomain($productionDomain);
    $best = null;
    foreach ($relatedSites as $site) {
        $path = (string)($site['path'] ?? '');
        $domain = devConsoleNormalizeDomain((string)($site['domain'] ?? ''));
        if ($path === '' || $path === $productionPath || $path === $sourcePath || $domain === $productionDomain) {
            continue;
        }
        if ($domain === '' && empty($site['git']) && empty($site['tasks'])) {
            continue;
        }
        if ($best === null || (int)($site['relationship_score'] ?? 0) > (int)($best['relationship_score'] ?? 0)) {
            $best = $site;
        }
    }

    return is_array($best) ? $best : [];
}

function projectAdoptionGithubCompatibility(string $remote, array $githubConfiguration): array
{
    if ($remote === '') {
        return ['status' => 'No GitHub remote detected', 'identity' => '', 'compatible' => false];
    }
    if (!function_exists('gitParseGithubRepositoryUrl')) {
        return ['status' => 'GitHub remote parser unavailable', 'identity' => $remote, 'compatible' => false];
    }

    [$owner, $name] = gitParseGithubRepositoryUrl($remote);
    $identity = ($owner !== '' && $name !== '') ? $owner . '/' . $name : $remote;
    $account = (string)($githubConfiguration['account'] ?? '');
    if ($owner === '' || $name === '') {
        return ['status' => 'Remote is not a recognized GitHub repository URL', 'identity' => $identity, 'compatible' => false];
    }
    if ($account === '') {
        return ['status' => 'Global GitHub account is not configured', 'identity' => $identity, 'compatible' => false];
    }
    if (strcasecmp($owner, $account) !== 0) {
        return ['status' => 'Repository owner differs from the global GitHub account', 'identity' => $identity, 'compatible' => false];
    }

    return ['status' => 'Compatible with the global GitHub account', 'identity' => $identity, 'compatible' => true];
}

function projectAdoptionBuildPlan(array $result, ?array $server = null, array $githubConfiguration = []): array
{
    $values = is_array($result['values'] ?? null) ? $result['values'] : [];
    $identity = is_array($result['identity'] ?? null) ? $result['identity'] : [];
    $proposal = is_array($result['proposed_structure'] ?? null) ? $result['proposed_structure'] : [];
    $git = is_array($result['git'] ?? null) ? $result['git'] : [];
    $tasks = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];
    $relatedSites = is_array($result['related_sites'] ?? null) ? $result['related_sites'] : [];

    $projectName = (string)($identity['project_name'] ?? ($values['project_name'] ?? ''));
    $projectId = devConsoleProjectIdFromName($projectName);
    $productionPath = (string)($proposal['production_path'] ?? ($identity['production_path'] ?? ''));
    $productionDomain = devConsoleNormalizeDomain((string)($proposal['production_domain'] ?? ($identity['production_domain'] ?? '')));
    $sourcePath = (string)($proposal['source_path'] ?? '');
    $sourceGit = !empty($proposal['source_git']);
    $sourceTasks = !empty($proposal['source_tasks']);
    $sourceBranch = (string)($proposal['source_branch'] ?? '');
    $sourceHead = (string)($proposal['source_head'] ?? '');
    $sourceRemote = (string)($proposal['source_remote'] ?? '');
    $sourceTaskCount = (int)($proposal['source_task_count'] ?? 0);
    $sourceHighestTask = (string)($proposal['source_highest_task_number'] ?? '');
    $sourceTaskFingerprint = (string)($proposal['source_task_fingerprint'] ?? '');

    if ($sourcePath === '' && (!empty($git['repository_detected']) || !empty($tasks['detected']))) {
        $sourcePath = $productionPath;
        $sourceGit = !empty($git['repository_detected']);
        $sourceTasks = !empty($tasks['detected']);
        $sourceBranch = (string)($git['branch'] ?? '');
        $sourceHead = (string)($git['head'] ?? '');
        $sourceRemote = projectAdoptionFirstGithubRemote(is_array($git['remotes'] ?? null) ? $git['remotes'] : []);
        $sourceTaskCount = (int)($tasks['expected_task_count'] ?? ($tasks['task_count'] ?? 0));
        $sourceHighestTask = (string)($tasks['highest_task_number'] ?? '');
        $sourceTaskFingerprint = (string)($tasks['fingerprint'] ?? '');
    }

    $preview = projectAdoptionFindRelatedPreview($relatedSites, $productionPath, $productionDomain, $sourcePath);
    $previewPath = (string)($preview['path'] ?? '');
    $previewDomain = devConsoleNormalizeDomain((string)($preview['domain'] ?? ''));
    $github = projectAdoptionGithubCompatibility($sourceRemote, $githubConfiguration);
    $productionOnly = ['count' => 0, 'files' => []];
    $productionOnlyError = '';
    if ($server !== null && $productionPath !== '' && $sourcePath !== '' && $productionPath !== $sourcePath) {
        $probe = projectAdoptionRunRemote($server, projectAdoptionProductionOnlyFilesCommand($productionPath, $sourcePath), 35);
        if (!empty($probe['success'])) {
            $productionOnly = projectAdoptionParseProductionOnlyFiles((string)($probe['stdout'] ?? ''));
        } else {
            $details = projectAdoptionRemoteFailureDetails($probe);
            $productionOnlyError = 'Production-only file scan failed. Exit code: ' . projectAdoptionPlanDisplayToken((string)($details['exit_code'] ?? '')) . '.';
        }
    }

    $generatedPaths = $projectId === '' ? ['preview' => '', 'production' => ''] : devConsoleGeneratedEnvironmentPaths($projectId);
    $proposedPreviewPath = $previewPath !== '' ? $previewPath : $generatedPaths['preview'];
    $proposedPreviewDomain = $previewDomain !== '' ? $previewDomain : ($productionDomain === '' ? '' : devConsoleGeneratedPreviewDomain($productionDomain));
    $proposedProductionPath = $productionPath;
    $adoptionBlockers = [];
    if ($projectName === '' || $projectId === '') {
        $adoptionBlockers[] = 'Project name and Project ID are required.';
    }
    if ($sourcePath === '') {
        $adoptionBlockers[] = 'Selected Project Source is required.';
    }
    if ($productionDomain === '' || $productionPath === '') {
        $adoptionBlockers[] = 'Production domain and path are required.';
    }
    if ($sourceRemote !== '' && empty($github['compatible'])) {
        $adoptionBlockers[] = 'Existing GitHub repository compatibility must be resolved.';
    }

    $actions = [
        ['component' => 'Existing source ' . projectAdoptionPlanDisplayToken($sourcePath), 'classification' => $sourcePath === '' ? 'NEEDS REVIEW' : 'PRESERVE / IMPORT', 'detail' => 'Import this source into the Dev Console Host repository while preserving the original location.'],
        ['component' => 'Existing Git history', 'classification' => $sourceGit ? 'PRESERVE' : 'NEEDS REVIEW', 'detail' => $sourceGit ? 'Preserve existing repository history and remote metadata.' : 'No existing Git repository was detected for the proposed source.'],
        ['component' => 'Existing TASKS history', 'classification' => $sourceTasks ? 'PRESERVE' : 'NEEDS REVIEW', 'detail' => $sourceTasks ? 'Preserve compatible historical tasks without renumbering.' : 'No compatible TASKS history was detected for the proposed source.'],
        ['component' => 'Preview ' . projectAdoptionPlanDisplayToken(trim($previewDomain . ' ' . $previewPath)), 'classification' => $previewPath === '' ? 'CREATE LATER' : 'ADOPT IN PLACE', 'detail' => $previewPath === '' ? 'No existing Preview was selected; Dev Console may create a Preview location later.' : 'Use the existing Preview path/domain without relocating it.'],
        ['component' => 'Production ' . projectAdoptionPlanDisplayToken(trim($productionDomain . ' ' . $productionPath)), 'classification' => $productionPath === '' ? 'NEEDS REVIEW' : 'ADOPT IN PLACE', 'detail' => 'Use the existing Production path/domain without relocating it. Existing Production remains untouched during adoption.'],
        ['component' => 'Dev Console source ' . ($projectId === '' ? 'Not available' : devConsoleGeneratedRepositoryPath($projectId)), 'classification' => $projectId === '' ? 'NEEDS REVIEW' : 'IMPORT', 'detail' => 'Local Project repository on the Dev Console Host.'],
    ];

    return [
        'project' => [
            'name' => $projectName,
            'id' => $projectId,
            'managed_server_id' => (string)($values['managed_server_id'] ?? ''),
            'managed_server' => (string)($identity['managed_server'] ?? ''),
        ],
        'current' => [
            'source' => [
                'path' => $sourcePath,
                'git_status' => $sourceGit ? 'Detected' : 'Not detected',
                'remote' => $sourceRemote,
                'branch' => $sourceBranch,
                'head' => $sourceHead,
                'tasks_status' => $sourceTasks ? 'Detected' : 'Not detected',
                'task_count' => $sourceTaskCount,
                'highest_task_number' => $sourceHighestTask,
                'task_fingerprint' => $sourceTaskFingerprint,
            ],
            'preview' => [
                'domain' => $previewDomain,
                'path' => $previewPath,
                'evidence' => (string)($preview['reason'] ?? ''),
            ],
            'production' => [
                'domain' => $productionDomain,
                'path' => $productionPath,
            ],
        ],
        'proposed' => [
            'source_repository' => $projectId === '' ? '' : devConsoleGeneratedRepositoryPath($projectId),
            'preview_path' => $proposedPreviewPath,
            'preview_domain' => $proposedPreviewDomain,
            'preview_classification' => $previewPath === '' ? 'CREATE LATER' : 'ADOPT IN PLACE',
            'production_path' => $proposedProductionPath,
            'production_domain' => $productionDomain,
            'production_classification' => $productionPath === '' ? 'NEEDS REVIEW' : 'ADOPT IN PLACE',
        ],
        'github' => $github,
        'tasks' => [
            'classification' => $sourceTasks ? 'PRESERVE' : 'NEEDS REVIEW',
            'task_count' => $sourceTaskCount,
            'highest_task_number' => $sourceHighestTask,
            'fingerprint' => $sourceTaskFingerprint,
        ],
        'actions' => $actions,
        'production_only' => $productionOnly + ['error' => $productionOnlyError],
        'can_adopt' => empty($adoptionBlockers),
        'adoption_blockers' => $adoptionBlockers,
        'safety' => [
            'Existing Production will not be modified during adoption.',
            'Existing Apache configuration will not be modified during adoption.',
            'Existing Preview will not be modified during adoption.',
            'No Preview or Production deployment occurs during adoption.',
            'Existing Preview and Production paths are adopted in place when valid locations are selected.',
        ],
        'manual_corrections' => [
            'project_name' => $projectName,
            'project_id' => $projectId,
            'source_path' => $sourcePath,
            'preview_domain' => $previewDomain,
            'preview_path' => $previewPath,
            'production_domain' => $productionDomain,
            'production_path' => $productionPath,
            'expected_task_fingerprint' => $sourceTaskFingerprint,
        ],
    ];
}

function projectAdoptionValidatedInput(array $input, array $configuration, array $managedServers): array
{
    $values = [
        'project_name' => devConsoleScalarInput($input, 'project_name'),
        'project_id' => devConsoleScalarInput($input, 'project_id'),
        'managed_server_id' => devConsoleScalarInput($input, 'managed_server_id'),
        'source_path' => devConsoleScalarInput($input, 'source_path'),
        'preview_domain' => devConsoleNormalizeDomain(devConsoleScalarInput($input, 'preview_domain')),
        'preview_path' => devConsoleScalarInput($input, 'preview_path'),
        'production_domain' => devConsoleNormalizeDomain(devConsoleScalarInput($input, 'production_domain')),
        'production_path' => devConsoleScalarInput($input, 'production_path'),
        'expected_source_head' => devConsoleScalarInput($input, 'expected_source_head'),
        'expected_source_remote' => devConsoleScalarInput($input, 'expected_source_remote'),
        'expected_highest_task_number' => devConsoleScalarInput($input, 'expected_highest_task_number'),
        'expected_task_fingerprint' => devConsoleScalarInput($input, 'expected_task_fingerprint'),
    ];
    $errors = [];

    if ($values['project_name'] === '' || strlen($values['project_name']) > 255 || devConsoleHasControlCharacters($values['project_name'])) {
        $errors[] = 'Project name is required and must not contain invalid characters.';
    }
    if ($values['project_id'] === '' || $values['project_id'] !== devConsoleProjectIdFromName($values['project_id']) || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $values['project_id'])) {
        $errors[] = 'Project ID must contain only lowercase letters, digits, and hyphens.';
    } elseif (devConsoleFindProjectById($configuration, $values['project_id']) !== null) {
        $errors[] = 'Project ID is already registered.';
    }
    $server = managedServersFind($managedServers, $values['managed_server_id']);
    if ($values['managed_server_id'] === '' || $server === null) {
        $errors[] = 'Managed Server is required.';
    }
    foreach (['source_path' => 'Selected Project Source', 'production_path' => 'Production path'] as $field => $label) {
        if ($values[$field] === '' || !devConsoleIsAbsoluteUnixPath($values[$field]) || devConsoleHasControlCharacters($values[$field])) {
            $errors[] = $label . ' must be an absolute Unix path.';
        }
    }
    if ($values['source_path'] !== '' && !projectAdoptionPathIsSafeRsyncRemoteSource($values['source_path'])) {
        $errors[] = 'Selected Project Source may contain only letters, digits, slash, dot, underscore, and hyphen for remote import.';
    }
    if ($values['preview_path'] !== '' && (!devConsoleIsAbsoluteUnixPath($values['preview_path']) || devConsoleHasControlCharacters($values['preview_path']))) {
        $errors[] = 'Preview path must be an absolute Unix path when supplied.';
    }
    if ($values['production_domain'] === '' || !devConsoleIsHostname($values['production_domain'])) {
        $errors[] = 'Production domain must be a hostname without scheme, port, path, query, or fragment.';
    }
    if ($values['preview_domain'] !== '' && !devConsoleIsHostname($values['preview_domain'])) {
        $errors[] = 'Preview domain must be a hostname without scheme, port, path, query, or fragment.';
    }
    if ($values['preview_domain'] !== '' && $values['preview_domain'] === $values['production_domain']) {
        $errors[] = 'Preview and Production domains must be different.';
    }
    if ($values['preview_path'] !== '' && $values['preview_path'] === $values['production_path']) {
        $errors[] = 'Preview and Production paths must be different.';
    }
    foreach (devConsoleProjects($configuration) as $project) {
        foreach (['production', 'preview'] as $environment) {
            $existingDomain = devConsoleNormalizeDomain((string)($project[$environment]['domain'] ?? ''));
            $existingPath = (string)($project[$environment]['path'] ?? '');
            if ($existingDomain !== '' && in_array($existingDomain, array_filter([$values['production_domain'], $values['preview_domain']]), true)) {
                $errors[] = 'Domain is already registered by another project environment.';
            }
            if ($existingPath !== '' && in_array($existingPath, array_filter([$values['production_path'], $values['preview_path']]), true)) {
                $errors[] = 'Path is already registered by another project environment.';
            }
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'values' => $values,
        'server' => $server,
    ];
}

function projectAdoptionAppendLog(array &$log, string $stage, string $message): void
{
    $log[] = '[' . date('c') . '] ' . $stage . ': ' . $message;
}

function projectAdoptionLocalRsync(): string
{
    return serverToolsFindExecutable('rsync', serverToolsDefaultPath());
}

function projectAdoptionPathIsSafeRsyncRemoteSource(string $path): bool
{
    return devConsoleIsAbsoluteUnixPath($path) && preg_match('/\A\/[A-Za-z0-9._\/-]+\z/', $path) === 1;
}

function projectAdoptionNormalizeBranchName(string $branch): string
{
    $branch = trim($branch);
    foreach (['refs/heads/', 'origin/'] as $prefix) {
        if (str_starts_with($branch, $prefix)) {
            return substr($branch, strlen($prefix));
        }
    }

    return $branch;
}

function projectAdoptionBranchesMatch(string $expected, string $actual): bool
{
    $expected = projectAdoptionNormalizeBranchName($expected);
    $actual = projectAdoptionNormalizeBranchName($actual);

    return $expected === '' || $actual === '' || $expected === $actual;
}

function projectAdoptionCommitIdentityMatches(string $approved, string $canonical): bool
{
    $approved = trim($approved);
    $canonical = trim($canonical);
    if ($approved === '' || $canonical === '') {
        return true;
    }
    if ($approved === $canonical) {
        return true;
    }
    if (preg_match('/\A[0-9a-f]{7,40}\z/i', $approved) === 1 && preg_match('/\A[0-9a-f]{40}\z/i', $canonical) === 1) {
        return str_starts_with(strtolower($canonical), strtolower($approved));
    }

    return false;
}

function projectAdoptionGithubRemotesMatch(string $expected, string $actual): bool
{
    $expected = trim($expected);
    $actual = trim($actual);
    if ($expected === '' || $actual === '') {
        return true;
    }
    if ($expected === $actual) {
        return true;
    }
    if (!function_exists('gitParseGithubRepositoryUrl')) {
        return false;
    }

    [$expectedOwner, $expectedName] = gitParseGithubRepositoryUrl($expected);
    [$actualOwner, $actualName] = gitParseGithubRepositoryUrl($actual);
    return $expectedOwner !== ''
        && $actualOwner !== ''
        && strcasecmp($expectedOwner, $actualOwner) === 0
        && $expectedName === $actualName;
}

function projectAdoptionRemoteRsyncSource(array $server, string $sourcePath): string
{
    if (!projectAdoptionPathIsSafeRsyncRemoteSource($sourcePath)) {
        throw new InvalidArgumentException('Selected Project Source path contains characters unsupported by remote import.');
    }

    return (string)$server['user'] . '@' . (string)$server['host'] . ':' . rtrim($sourcePath, '/') . '/';
}

function projectAdoptionRsyncImportArguments(array $server, string $sourcePath, string $targetPath, bool $dryRun = false): array
{
    $ssh = implode(' ', array_map('escapeshellarg', [
        managedServersSshExecutable(),
        '-i', (string)$server['key'],
        '-p', (string)((int)$server['port']),
        '-o', 'BatchMode=yes',
        '-o', 'ConnectTimeout=10',
        '-o', 'StrictHostKeyChecking=accept-new',
    ]));

    $arguments = [
        projectAdoptionLocalRsync(),
        '-a',
        '--delete',
        '--no-owner',
        '--no-group',
        '-e',
        $ssh,
        projectAdoptionRemoteRsyncSource($server, $sourcePath),
        rtrim($targetPath, '/') . '/',
    ];
    if ($dryRun) {
        array_splice($arguments, 1, 0, ['--dry-run']);
    }

    return $arguments;
}

function projectAdoptionRemoteSourceValidationCommand(string $sourcePath, bool $expectGit): string
{
    $path = managedServersShellQuote($sourcePath);
    $command = 'test -d ' . $path . ' && test -r ' . $path;
    if ($expectGit) {
        $command .= ' && git -C ' . $path . ' rev-parse --is-inside-work-tree >/dev/null'
            . ' && git -C ' . $path . ' rev-parse HEAD | sed "s/^/__DEV_CONSOLE_SOURCE_HEAD__=/"'
            . ' && git -C ' . $path . ' branch --show-current | sed "s/^/__DEV_CONSOLE_SOURCE_BRANCH__=/"';
    }
    $command .= ' && printf "__DEV_CONSOLE_SOURCE_VALID__=1\n"';

    return $command;
}

function projectAdoptionSourceValidationMarker(array $result, string $marker): string
{
    foreach (preg_split('/\R/', (string)($result['stdout'] ?? '')) ?: [] as $line) {
        $value = projectAdoptionMarkerValue($line, $marker);
        if ($value !== null) {
            return trim($value);
        }
    }

    return '';
}

function projectAdoptionTemporaryTargetPath(string $projectId): string
{
    $suffix = bin2hex(random_bytes(8));
    return rtrim(DEV_CONSOLE_GIT_BASE, '/') . '/.adopt-' . $projectId . '-' . $suffix;
}

function projectAdoptionRemoveCreatedTarget(string $targetPath): void
{
    $base = realpath(DEV_CONSOLE_GIT_BASE);
    $target = realpath($targetPath);
    if ($base === false || $target === false || $target === $base || !str_starts_with($target, $base . DIRECTORY_SEPARATOR)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($target);
}

function projectAdoptionGitMetadataFromImportedRepository(array $values, string $targetPath, array $sourceInspection): array
{
    $git = devConsoleEmptyProject()['git'];
    $inspectionGit = is_array($sourceInspection['git'] ?? null) ? $sourceInspection['git'] : [];
    $remotes = is_array($inspectionGit['remotes'] ?? null) ? $inspectionGit['remotes'] : [];
    $remote = projectAdoptionFirstGithubRemote($remotes);
    [$owner, $name] = function_exists('gitParseGithubRepositoryUrl') ? gitParseGithubRepositoryUrl($remote) : ['', ''];
    $branch = trim((string)($inspectionGit['branch'] ?? ''));
    if ($branch === '') {
        $branchResult = gitRunFixedCommand(['git', '-C', $targetPath, 'branch', '--show-current'], 5, [], false);
        $branch = trim((string)($branchResult['stdout'] ?? ''));
    }
    $headResult = gitRunFixedCommand(['git', '-C', $targetPath, 'rev-parse', 'HEAD'], 5, [], false);
    $head = $headResult['exit_code'] === 0 ? trim((string)$headResult['stdout']) : (string)($inspectionGit['head'] ?? '');

    $git['provider'] = $owner !== '' ? 'github' : null;
    $git['repository_owner'] = $owner !== '' ? $owner : null;
    $git['repository_name'] = $name !== '' ? $name : null;
    $git['remote_url'] = $remote !== '' ? $remote : null;
    $git['clone_url'] = $remote !== '' ? $remote : null;
    $git['bootstrap_status'] = 'ready';
    $git['connected'] = is_dir($targetPath . '/.git');
    $git['connected_at'] = date('c');
    $git['created_at'] = date('c');
    $git['local_head'] = $head !== '' ? $head : null;
    $git['remote_head'] = null;
    $git['remote_verified'] = $remote !== '';
    $git['remote_verified_at'] = $remote !== '' ? date('c') : null;

    return [$git, $branch !== '' ? $branch : 'main'];
}

function projectAdoptionImportedRepositoryRemotes(string $targetPath): array
{
    $result = gitRunFixedCommand(['git', '-C', $targetPath, 'remote', '-v'], 5, [], false);
    if ($result['exit_code'] !== 0) {
        return [];
    }

    $remotes = [];
    foreach (preg_split('/\R/', (string)$result['stdout']) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        if (isset($parts[1])) {
            $remotes[] = $parts[1];
        }
    }

    return array_values(array_unique($remotes));
}

function projectAdoptionLocalTasksFingerprint(string $targetPath): string
{
    $tasksPath = rtrim($targetPath, '/') . '/TASKS';
    if (!is_dir($tasksPath)) {
        return '';
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tasksPath, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $basename = $entry->getBasename();
        if (preg_match('/\ATASK-[0-9]{3}\.md\z/', $basename) !== 1) {
            continue;
        }
        $relative = substr($entry->getPathname(), strlen($tasksPath) + 1);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $files[$relative] = hash_file('sha256', $entry->getPathname()) ?: '';
    }
    ksort($files, SORT_STRING);

    $context = hash_init('sha256');
    foreach ($files as $relative => $hash) {
        hash_update($context, $relative . "\t" . $hash . "\n");
    }

    return hash_final($context);
}

function projectAdoptionVerifyGithubCompatibility(array $sourceInspection, array $githubConfiguration): ?string
{
    $git = is_array($sourceInspection['git'] ?? null) ? $sourceInspection['git'] : [];
    if (empty($git['repository_detected'])) {
        return null;
    }
    $remotes = is_array($git['remotes'] ?? null) ? $git['remotes'] : [];
    if (empty($remotes)) {
        return null;
    }
    $remote = projectAdoptionFirstGithubRemote($remotes);
    if ($remote === '') {
        return 'Existing Git remotes were detected, but no compatible GitHub remote could be identified.';
    }
    $compatibility = projectAdoptionGithubCompatibility($remote, $githubConfiguration);
    if (empty($compatibility['compatible'])) {
        return 'Existing GitHub repository needs review: ' . (string)$compatibility['status'];
    }

    return null;
}

function projectAdoptionInitializeBaselineRepository(array $project, string $targetPath, array &$log): array
{
    gitEnsureTaskDocumentation($project, $targetPath);
    $init = gitRunFixedCommand(['git', '-C', $targetPath, 'init', '-b', 'main'], 30, [], false);
    gitAppendCommandLog($log, $init);
    if ($init['exit_code'] !== 0) {
        $init = gitRunFixedCommand(['git', '-C', $targetPath, 'init'], 30, [], false);
        gitAppendCommandLog($log, $init);
        if ($init['exit_code'] !== 0) {
            throw new RuntimeException('Baseline Git initialization failed.');
        }
        $branch = gitRunFixedCommand(['git', '-C', $targetPath, 'checkout', '-B', 'main'], 30, [], false);
        gitAppendCommandLog($log, $branch);
        if ($branch['exit_code'] !== 0) {
            throw new RuntimeException('Baseline Git branch setup failed.');
        }
    }

    foreach ([
        ['git', '-C', $targetPath, 'config', 'user.name', 'IOVON Dev Console'],
        ['git', '-C', $targetPath, 'config', 'user.email', 'dev-console@localhost'],
        ['git', '-C', $targetPath, 'add', '.'],
        ['git', '-C', $targetPath, 'commit', '-m', 'Adopt existing project baseline'],
    ] as $arguments) {
        $result = gitRunFixedCommand($arguments, 60, [], false);
        gitAppendCommandLog($log, $result);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Baseline Git initialization failed.');
        }
    }

    $git = devConsoleEmptyProject()['git'];
    $head = gitRunFixedCommand(['git', '-C', $targetPath, 'rev-parse', 'HEAD'], 5, [], false);
    $git['bootstrap_status'] = 'ready';
    $git['connected'] = true;
    $git['connected_at'] = date('c');
    $git['created_at'] = date('c');
    $git['local_head'] = $head['exit_code'] === 0 ? trim((string)$head['stdout']) : null;

    return [$git, 'main'];
}

function projectAdoptionCommitTaskDocumentation(array $project, string $targetPath, array &$log): ?string
{
    gitEnsureTaskDocumentation($project, $targetPath);
    $status = gitRunFixedCommand(['git', '-C', $targetPath, 'status', '--porcelain', '--', 'TASKS'], 10, [], false);
    gitAppendCommandLog($log, $status);
    if ($status['exit_code'] !== 0 || trim((string)$status['stdout']) === '') {
        return null;
    }

    foreach ([
        ['git', '-C', $targetPath, 'config', 'user.name', 'IOVON Dev Console'],
        ['git', '-C', $targetPath, 'config', 'user.email', 'dev-console@localhost'],
        ['git', '-C', $targetPath, 'add', 'TASKS'],
        ['git', '-C', $targetPath, 'commit', '-m', 'Initialize Dev Console task structure'],
    ] as $arguments) {
        $result = gitRunFixedCommand($arguments, 60, [], false);
        gitAppendCommandLog($log, $result);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Unable to commit Dev Console TASKS structure.');
        }
    }

    $head = gitRunFixedCommand(['git', '-C', $targetPath, 'rev-parse', 'HEAD'], 5, [], false);
    gitAppendCommandLog($log, $head);

    return $head['exit_code'] === 0 ? trim((string)$head['stdout']) : null;
}

function projectAdoptionAdopt(array $input, array $managedServers, array $githubConfiguration): array
{
    $log = [];
    $summary = [];
    $createdTarget = false;
    $createdTemporaryTarget = false;
    $targetPath = '';
    $temporaryTargetPath = '';
    $configuration = devConsoleLoadProjectConfiguration();
    $validation = projectAdoptionValidatedInput($input, $configuration, $managedServers);
    if (!$validation['valid']) {
        return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'Adoption preflight failed.', 'summary' => $validation['errors'], 'output' => implode("\n", $log)];
    }
    $values = $validation['values'];
    $server = $validation['server'];
    $targetPath = devConsoleGeneratedRepositoryPath($values['project_id']);
    $projectShell = [
        'id' => $values['project_id'],
        'name' => $values['project_name'],
    ];

    try {
        projectAdoptionAppendLog($log, 'Preflight', 'Checking Project registration and local source target.');
        if (file_exists($targetPath)) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'Dev Console source target already exists and will not be overwritten.', 'summary' => ['Conflict: ' . $targetPath], 'output' => implode("\n", $log)];
        }
        $base = DEV_CONSOLE_GIT_BASE;
        if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
            throw new RuntimeException('Unable to create Dev Console Git base directory: ' . $base);
        }
        if (!is_writable($base)) {
            throw new RuntimeException('Dev Console Git base directory is not writable: ' . $base);
        }

        projectAdoptionAppendLog($log, 'Preflight', 'Reinspecting selected source and existing environments.');
        $sourceProbe = projectAdoptionRunRemote($server, projectAdoptionInspectPathCommand($values['source_path']), 35);
        if (empty($sourceProbe['success'])) {
            throw new RuntimeException('Selected Project Source could not be inspected.');
        }
        $sourceInspection = projectAdoptionParsePathInspection((string)$sourceProbe['stdout']);
        if (empty($sourceInspection['filesystem']['exists']) || empty($sourceInspection['filesystem']['is_dir']) || empty($sourceInspection['filesystem']['readable'])) {
            throw new RuntimeException('Selected Project Source must exist, be readable, and be a directory.');
        }
        $expectedRemote = $values['expected_source_remote'];
        $actualRemote = projectAdoptionFirstGithubRemote(is_array($sourceInspection['git']['remotes'] ?? null) ? $sourceInspection['git']['remotes'] : []);
        if (!projectAdoptionGithubRemotesMatch($expectedRemote, $actualRemote)) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'Source Git remote changed since discovery. Rediscover before adoption.', 'summary' => ['Expected ' . $expectedRemote . ', found ' . $actualRemote], 'output' => implode("\n", $log)];
        }
        $expectedTask = $values['expected_highest_task_number'];
        $actualTask = (string)($sourceInspection['tasks']['highest_task_number'] ?? '');
        if ($expectedTask !== '' && $actualTask !== '' && $expectedTask !== $actualTask) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'TASKS history changed since discovery. Rediscover before adoption.', 'summary' => ['Expected ' . $expectedTask . ', found ' . $actualTask], 'output' => implode("\n", $log)];
        }
        $expectedTaskFingerprint = $values['expected_task_fingerprint'];
        $actualTaskFingerprint = (string)($sourceInspection['tasks']['fingerprint'] ?? '');
        if ($expectedTaskFingerprint !== '' && $actualTaskFingerprint !== '' && $expectedTaskFingerprint !== $actualTaskFingerprint) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'TASKS history changed since discovery. Rediscover before adoption.', 'summary' => ['TASKS fingerprint changed since discovery.'], 'output' => implode("\n", $log)];
        }
        $remoteReview = projectAdoptionVerifyGithubCompatibility($sourceInspection, $githubConfiguration);
        if ($remoteReview !== null) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => $remoteReview, 'summary' => [$remoteReview], 'output' => implode("\n", $log)];
        }
        $sourceValidation = projectAdoptionRunRemote(
            $server,
            projectAdoptionRemoteSourceValidationCommand($values['source_path'], !empty($sourceInspection['git']['repository_detected'])),
            35
        );
        gitAppendCommandLog($log, $sourceValidation);
        if (empty($sourceValidation['success']) || !str_contains((string)($sourceValidation['stdout'] ?? ''), '__DEV_CONSOLE_SOURCE_VALID__=1')) {
            throw new RuntimeException('Selected Project Source failed remote import validation.');
        }
        $canonicalSourceHead = projectAdoptionSourceValidationMarker($sourceValidation, '__DEV_CONSOLE_SOURCE_HEAD__=');
        $canonicalSourceBranch = projectAdoptionSourceValidationMarker($sourceValidation, '__DEV_CONSOLE_SOURCE_BRANCH__=');
        $expectedHead = $values['expected_source_head'];
        if (!projectAdoptionCommitIdentityMatches($expectedHead, $canonicalSourceHead)) {
            return ['success' => false, 'status' => 'NEEDS REVIEW', 'message' => 'Source Git HEAD changed since discovery. Rediscover before adoption.', 'summary' => ['Expected ' . $expectedHead . ', found ' . $canonicalSourceHead], 'output' => implode("\n", $log)];
        }
        if (!projectAdoptionPathIsSafeRsyncRemoteSource($values['source_path'])) {
            throw new RuntimeException('Selected Project Source path contains characters unsupported by remote import.');
        }

        foreach (['preview' => $values['preview_path'], 'production' => $values['production_path']] as $environment => $path) {
            if ($path === '') {
                continue;
            }
            $probe = projectAdoptionRunRemote($server, projectAdoptionInspectPathCommand($path), 35);
            if (empty($probe['success'])) {
                throw new RuntimeException(ucfirst($environment) . ' path could not be inspected.');
            }
            $inspection = projectAdoptionParsePathInspection((string)$probe['stdout']);
            if (empty($inspection['filesystem']['exists']) || empty($inspection['filesystem']['is_dir']) || empty($inspection['filesystem']['readable'])) {
                throw new RuntimeException(ucfirst($environment) . ' path must exist, be readable, and be a directory.');
            }
        }
        $summary[] = 'Preflight complete';

        projectAdoptionAppendLog($log, 'Import Source', 'Preparing operation-owned temporary target.');
        $temporaryTargetPath = projectAdoptionTemporaryTargetPath($values['project_id']);
        if (!@mkdir($temporaryTargetPath, 0755, true) && !is_dir($temporaryTargetPath)) {
            throw new RuntimeException('Unable to create temporary Dev Console source target.');
        }
        $createdTemporaryTarget = true;
        $rsync = projectAdoptionLocalRsync();
        if ($rsync === '') {
            throw new RuntimeException('rsync is not installed on the Dev Console Host.');
        }
        $dryRun = processRunCommand(projectAdoptionRsyncImportArguments($server, $values['source_path'], $temporaryTargetPath, true), [
            'timeout' => 120,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        gitAppendCommandLog($log, $dryRun);
        if ($dryRun['exit_code'] !== 0) {
            throw new RuntimeException('Source import preflight failed.');
        }
        $import = processRunCommand(projectAdoptionRsyncImportArguments($server, $values['source_path'], $temporaryTargetPath), [
            'timeout' => 600,
            'env' => ['PATH' => serverToolsDefaultPath()],
            'inherit_env' => false,
        ]);
        gitAppendCommandLog($log, $import);
        if ($import['exit_code'] !== 0) {
            throw new RuntimeException('Source import failed.');
        }
        $summary[] = 'Source imported to temporary target';

        $hasGit = is_dir($temporaryTargetPath . '/.git') || is_file($temporaryTargetPath . '/.git');
        projectAdoptionAppendLog($log, $hasGit ? 'Preserve Git' : 'Initialize Git', $hasGit ? 'Existing Git repository was imported with history.' : 'No Git repository was detected; initializing baseline repository.');
        if ($hasGit) {
            $validImportedRepository = gitRunFixedCommand(['git', '-C', $temporaryTargetPath, 'rev-parse', '--is-inside-work-tree'], 10, [], false);
            gitAppendCommandLog($log, $validImportedRepository);
            if ($validImportedRepository['exit_code'] !== 0 || trim((string)$validImportedRepository['stdout']) !== 'true') {
                throw new RuntimeException('Imported Git repository is not usable on the Dev Console Host.');
            }
            $importedHead = gitRunFixedCommand(['git', '-C', $temporaryTargetPath, 'rev-parse', 'HEAD'], 10, [], false);
            gitAppendCommandLog($log, $importedHead);
            $expectedHead = $canonicalSourceHead !== '' ? $canonicalSourceHead : (string)($sourceInspection['git']['head'] ?? '');
            $actualImportedHead = $importedHead['exit_code'] === 0 ? trim((string)$importedHead['stdout']) : '';
            if (!projectAdoptionCommitIdentityMatches($expectedHead, $actualImportedHead) || ($canonicalSourceHead !== '' && $actualImportedHead !== $canonicalSourceHead)) {
                throw new RuntimeException('Imported Git HEAD does not match selected Project Source.');
            }
            $importedBranch = gitRunFixedCommand(['git', '-C', $temporaryTargetPath, 'branch', '--show-current'], 10, [], false);
            gitAppendCommandLog($log, $importedBranch);
            $expectedBranch = $canonicalSourceBranch !== '' ? $canonicalSourceBranch : (string)($sourceInspection['git']['branch'] ?? '');
            $actualImportedBranch = trim((string)($importedBranch['stdout'] ?? ''));
            if (!projectAdoptionBranchesMatch($expectedBranch, $actualImportedBranch)) {
                throw new RuntimeException('Imported Git branch does not match selected Project Source.');
            }
            $importedRemote = projectAdoptionFirstGithubRemote(projectAdoptionImportedRepositoryRemotes($temporaryTargetPath));
            $expectedRemote = projectAdoptionFirstGithubRemote(is_array($sourceInspection['git']['remotes'] ?? null) ? $sourceInspection['git']['remotes'] : []);
            if (!projectAdoptionGithubRemotesMatch($expectedRemote, $importedRemote)) {
                throw new RuntimeException('Imported Git remote does not match selected Project Source.');
            }
            [$gitMetadata, $branch] = projectAdoptionGitMetadataFromImportedRepository($values, $temporaryTargetPath, $sourceInspection);
            $summary[] = 'Git history preserved';
        } else {
            [$gitMetadata, $branch] = projectAdoptionInitializeBaselineRepository($projectShell, $temporaryTargetPath, $log);
            $summary[] = 'Baseline Git repository initialized';
        }

        projectAdoptionAppendLog($log, 'Preserve / Initialize TASKS', 'Checking TASKS lifecycle structure.');
        if (!empty($sourceInspection['tasks']['detected'])) {
            if (empty($sourceInspection['tasks']['compatible'])) {
                throw new RuntimeException('Existing TASKS history needs review before adoption.');
            }
            $sourceTaskFingerprint = (string)($sourceInspection['tasks']['fingerprint'] ?? '');
            $importedTaskFingerprint = projectAdoptionLocalTasksFingerprint($temporaryTargetPath);
            if ($sourceTaskFingerprint !== '' && $importedTaskFingerprint !== '' && $sourceTaskFingerprint !== $importedTaskFingerprint) {
                throw new RuntimeException('Imported TASKS history does not match selected Project Source.');
            }
            $highestTask = (string)($sourceInspection['tasks']['highest_task_number'] ?? '');
            if ($highestTask !== '') {
                $taskMatches = glob(rtrim($temporaryTargetPath, '/') . '/TASKS/*/' . $highestTask . '.md') ?: [];
                if (empty($taskMatches)) {
                    throw new RuntimeException('Imported TASKS history does not contain expected highest task.');
                }
            }
            projectAdoptionAppendLog($log, 'Preserve / Initialize TASKS', 'TASKS validation passed.');
            $summary[] = 'TASKS preserved through ' . projectAdoptionPlanDisplayToken((string)($sourceInspection['tasks']['highest_task_number'] ?? ''));
        } else {
            if ($hasGit) {
                $taskHead = projectAdoptionCommitTaskDocumentation($projectShell, $temporaryTargetPath, $log);
                if ($taskHead !== null) {
                    $gitMetadata['local_head'] = $taskHead;
                }
            } else {
                gitEnsureTaskDocumentation($projectShell, $temporaryTargetPath);
            }
            $summary[] = 'TASKS structure initialized';
        }

        projectAdoptionAppendLog($log, 'Import Source', 'Promoting temporary target to ' . $targetPath . '.');
        if (file_exists($targetPath)) {
            throw new RuntimeException('Dev Console source target appeared during import and will not be overwritten.');
        }
        if (!@rename($temporaryTargetPath, $targetPath)) {
            throw new RuntimeException('Unable to promote imported source to final Dev Console source target.');
        }
        $createdTemporaryTarget = false;
        $createdTarget = true;
        $summary[] = 'Source imported to ' . $targetPath;

        $project = devConsoleMergeProjectArrays(devConsoleEmptyProject(), [
            'id' => $values['project_id'],
            'name' => $values['project_name'],
            'managed_server_id' => $values['managed_server_id'],
            'repository_path' => $targetPath,
            'branch' => $branch,
            'last_activity_at' => date('c'),
            'production' => [
                'domain' => $values['production_domain'],
                'path' => $values['production_path'],
            ],
            'preview' => [
                'domain' => $values['preview_domain'],
                'path' => $values['preview_path'],
            ],
            'git' => $gitMetadata,
            'provisioning' => [
                'managed' => false,
                'provisioned_at' => date('c'),
                'production_vhost' => null,
                'preview_vhost' => null,
                'routing_verified_at' => null,
                'production_routing_verified' => null,
                'preview_routing_verified' => null,
            ],
            'setup' => [
                'status' => 'Configured',
                'server_id' => $values['managed_server_id'],
                'timestamp' => date('c'),
                'message' => 'Adopted existing Preview and Production in place. Apache unchanged.',
                'preview_site' => $values['preview_domain'] !== '' ? $values['preview_domain'] : null,
                'production_site' => $values['production_domain'],
                'infrastructure_fingerprint' => null,
                'infrastructure' => [
                    'adopted_in_place' => true,
                    'source_imported_from' => $values['source_path'],
                ],
            ],
        ]);

        projectAdoptionAppendLog($log, 'Register Project', 'Saving Project configuration.');
        $updated = devConsoleAppendProjectToConfiguration($configuration, $project);
        $updated['active_project_id'] = $values['project_id'];
        if (!devConsoleSaveProjectConfiguration($updated)) {
            throw new RuntimeException('Unable to save Project configuration.');
        }
        $summary[] = 'Project registered in Dev Console';
        $summary[] = $values['preview_path'] !== '' ? 'Preview adopted in place' : 'Preview not configured';
        $summary[] = 'Production adopted in place';
        $summary[] = 'Apache unchanged';
        $summary[] = 'No deployment performed';
        projectAdoptionAppendLog($log, 'Complete', 'Project adopted successfully.');

        return [
            'success' => true,
            'status' => 'SUCCESS',
            'message' => 'Project adopted successfully.',
            'project_id' => $values['project_id'],
            'summary' => $summary,
            'output' => implode("\n", $log),
            'source' => $targetPath,
            'git_mode' => $hasGit ? 'preserved existing history' : 'initialized baseline repository',
            'tasks_mode' => !empty($sourceInspection['tasks']['detected']) ? 'preserved through ' . projectAdoptionPlanDisplayToken((string)($sourceInspection['tasks']['highest_task_number'] ?? '')) : 'initialized new TASKS structure',
        ];
    } catch (Throwable $exception) {
        projectAdoptionAppendLog($log, 'Failed', $exception->getMessage());
        if ($createdTemporaryTarget) {
            projectAdoptionRemoveCreatedTarget($temporaryTargetPath);
        }
        if ($createdTarget) {
            projectAdoptionRemoveCreatedTarget($targetPath);
        }
        return [
            'success' => false,
            'status' => 'FAILED',
            'message' => $exception->getMessage(),
            'summary' => ['Adoption failed before Project registration.'],
            'output' => implode("\n", $log),
        ];
    }
}

function projectAdoptionDiscover(array $input, array $managedServers, ?array $scanContext = null): array
{
    $values = projectAdoptionEmptyInput();
    foreach ($values as $field => $_) {
        $value = $input[$field] ?? '';
        $values[$field] = is_scalar($value) ? trim((string)$value) : '';
    }
    $values['production_domain'] = devConsoleNormalizeDomain($values['production_domain']);
    $errors = [];
    $warnings = [];
    $notes = [];

    if ($values['project_name'] === '' || strlen($values['project_name']) > 255 || devConsoleHasControlCharacters($values['project_name'])) {
        $errors[] = 'Project name is required.';
    }
    if ($values['managed_server_id'] === '') {
        $errors[] = 'Managed Server is required.';
    }
    if ($values['production_domain'] === '' || !devConsoleIsHostname($values['production_domain'])) {
        $errors[] = 'Production domain must be a hostname without scheme, port, path, query, or fragment.';
    }
    if ($values['production_path'] !== '' && (!devConsoleIsAbsoluteUnixPath($values['production_path']) || devConsoleHasControlCharacters($values['production_path']))) {
        $errors[] = 'Production path must be an absolute Unix path when supplied.';
    }
    $server = $values['managed_server_id'] === '' ? null : managedServersFind($managedServers, $values['managed_server_id']);
    if ($server === null && $values['managed_server_id'] !== '') {
        $errors[] = 'Selected Managed Server does not exist.';
    }

    $result = [
        'success' => false,
        'status' => 'CANNOT ADOPT',
        'values' => $values,
        'errors' => $errors,
        'warnings' => [],
        'notes' => [],
        'identity' => [
            'project_name' => $values['project_name'],
            'managed_server' => $server === null ? $values['managed_server_id'] : devConsoleManagedServerLabel($server, $values['managed_server_id']),
            'production_domain' => $values['production_domain'],
            'production_path' => $values['production_path'],
        ],
        'web_server' => [
            'apache_available' => null,
            'match_status' => 'Not inspected',
            'matches' => [],
            'selected_vhost' => null,
            'document_root' => '',
        ],
        'filesystem' => null,
        'technology' => null,
        'configuration' => null,
        'git' => null,
        'tasks' => null,
        'history' => ['status' => 'Not inspected'],
        'related_sites' => [],
        'proposed_structure' => null,
        'adoption_plan' => null,
        'safety' => [projectAdoptionReadOnlyNotice()],
    ];

    if (!empty($errors) || $server === null) {
        $result['errors'] = $errors;
        return $result;
    }

    $apacheProbe = projectAdoptionRunRemote($server, projectAdoptionApacheInventoryCommand(), 20);
    if (empty($apacheProbe['success'])) {
        $result['errors'][] = 'SSH connection failed';
        $result['ssh_error'] = projectAdoptionRemoteFailureDetails($apacheProbe);
        return $result;
    }

    $inventory = projectAdoptionParseApacheInventory((string)($apacheProbe['stdout'] ?? ''));
    $matches = array_values(array_filter(
        $inventory['sites'],
        static fn(array $site): bool => projectAdoptionSiteMatchesDomain($site, $values['production_domain'])
    ));
    if ($values['production_path'] !== '' && count($matches) > 1) {
        $pathMatches = array_values(array_filter(
            $matches,
            static fn(array $site): bool => (string)($site['document_root'] ?? '') === $values['production_path']
        ));
        if (count($pathMatches) === 1) {
            $matches = $pathMatches;
            $notes[] = 'Production path resolved the Apache mapping ambiguity.';
        }
    }

    $selectedPath = $values['production_path'];
    $selectedVhost = null;
    if (count($matches) === 1) {
        $selectedVhost = $matches[0];
        $selectedPath = $selectedPath !== '' ? $selectedPath : (string)$selectedVhost['document_root'];
    } elseif (count($matches) > 1) {
        $warnings[] = 'Multiple Apache virtual hosts match the Production domain. Supply the Production path to resolve ambiguity.';
    } elseif ($selectedPath !== '') {
        $warnings[] = 'No Apache virtual host matched the Production domain. Filesystem discovery used the supplied Production path.';
    } else {
        $warnings[] = 'No Apache virtual host matched the Production domain and no Production path was supplied.';
    }

    $result['web_server'] = [
        'apache_available' => (bool)$inventory['available'],
        'match_status' => count($matches) === 1 ? 'One matching Apache vhost' : (count($matches) > 1 ? 'Ambiguous Apache matches' : 'No Apache match'),
        'matches' => $matches,
        'selected_vhost' => $selectedVhost,
        'document_root' => $selectedPath,
    ];
    $result['identity']['production_path'] = $selectedPath;
    $values['production_path'] = $selectedPath;

    if ($selectedPath === '') {
        $result['warnings'] = $warnings;
        $result['notes'] = $notes;
        $result['status'] = 'NEEDS REVIEW';
        return $result;
    }
    if (!devConsoleIsAbsoluteUnixPath($selectedPath) || devConsoleHasControlCharacters($selectedPath)) {
        $result['errors'][] = 'Discovered Production path is not a safe absolute Unix path.';
        $result['warnings'] = $warnings;
        $result['notes'] = $notes;
        return $result;
    }

    $pathProbe = projectAdoptionRunRemote($server, projectAdoptionInspectPathCommand($selectedPath), 35);
    if (empty($pathProbe['success'])) {
        $result['errors'][] = 'SSH connection failed';
        $result['ssh_error'] = projectAdoptionRemoteFailureDetails($pathProbe);
        $result['warnings'] = $warnings;
        $result['notes'] = $notes;
        return $result;
    }

    $inspection = projectAdoptionParsePathInspection((string)($pathProbe['stdout'] ?? ''));
    foreach (['filesystem', 'technology', 'configuration', 'git', 'tasks'] as $section) {
        $result[$section] = $inspection[$section];
    }
    $relationship = projectAdoptionBuildRelationships($values, $inspection, $scanContext);
    $result['related_sites'] = $relationship['related_sites'];
    $result['proposed_structure'] = $relationship['proposed_structure'];
    $result['adoption_plan'] = projectAdoptionBuildPlan(
        $result,
        $server,
        function_exists('devConsoleLoadGithubConfiguration') ? devConsoleLoadGithubConfiguration() : []
    );
    $warnings = array_merge($warnings, $relationship['warnings']);
    $result['history'] = [
        'status' => !empty($inspection['tasks']['detected']) || !empty($inspection['git']['repository_detected'])
            ? 'Detected at Production path'
            : 'Not detected at Production path',
    ];

    if (empty($inspection['filesystem']['exists'])) {
        $result['errors'][] = 'Production path does not exist.';
    } elseif (empty($inspection['filesystem']['is_dir'])) {
        $result['errors'][] = 'Production path is not a directory.';
    } elseif (empty($inspection['filesystem']['readable'])) {
        $result['errors'][] = 'Production path is not readable.';
    }
    if (!empty($inspection['tasks']['detected']) && empty($inspection['tasks']['compatible'])) {
        $warnings[] = 'TASKS directory was detected but does not fully match the current Dev Console lifecycle structure.';
    }
    if (!empty($inspection['git']['repository_detected']) && !empty($inspection['git']['remotes'])) {
        $planGithub = is_array($result['adoption_plan']['github'] ?? null) ? $result['adoption_plan']['github'] : [];
        if (empty($planGithub['compatible'])) {
            $warnings[] = 'Git remote ownership must be reviewed before adoption.';
        }
    }

    $result['errors'] = $result['errors'];
    $result['warnings'] = array_values(array_unique($warnings));
    $result['notes'] = array_values(array_unique($notes));
    $result['success'] = empty($result['errors']);
    if (!empty($result['errors'])) {
        $result['status'] = 'CANNOT ADOPT';
    } elseif (!empty($result['warnings'])) {
        $result['status'] = 'NEEDS REVIEW';
    } else {
        $result['status'] = 'READY FOR ADOPTION';
    }

    return $result;
}
