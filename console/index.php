<?php
$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if (PHP_SAPI === 'cli-server' && str_starts_with($requestPath, '/assets/')) {
    $assetRoot = realpath(__DIR__ . '/assets');
    $decodedPath = rawurldecode($requestPath);
    $assetPath = realpath(__DIR__ . $decodedPath);
    $publicAssetExtensions = [
        'css' => true,
        'gif' => true,
        'ico' => true,
        'jpeg' => true,
        'jpg' => true,
        'js' => true,
        'map' => true,
        'png' => true,
        'svg' => true,
        'txt' => true,
        'webp' => true,
        'woff' => true,
        'woff2' => true,
    ];
    $extension = strtolower(pathinfo($decodedPath, PATHINFO_EXTENSION));

    if (
        $assetRoot !== false
        && $assetPath !== false
        && is_file($assetPath)
        && str_starts_with($assetPath, $assetRoot . DIRECTORY_SEPARATOR)
        && isset($publicAssetExtensions[$extension])
    ) {
        return false;
    }
}

require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
require __DIR__ . '/preview-deployment.php';
require __DIR__ . '/production-deployment.php';
require __DIR__ . '/deployment.php';
require __DIR__ . '/apache.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/project-adoption.php';
require __DIR__ . '/git.php';
require __DIR__ . '/tasks.php';
require __DIR__ . '/task-lifecycle.php';
require __DIR__ . '/documentation.php';
require __DIR__ . '/runtime.php';

const DEV_CONSOLE_VERSION = '0.1';

$devConsoleRoot = dirname(__DIR__);

function commandOutputOrNull(array $arguments, string $cwd): ?string
{
    $result = processRunCommand($arguments, ['cwd' => $cwd, 'timeout' => 5]);

    return !empty($result['success']) ? trim((string)$result['stdout']) : null;
}

function processUptime(): string
{
    $stat = @file_get_contents('/proc/self/stat');
    if ($stat !== false && preg_match('/\)\s+\S+\s+(?:\S+\s+){19}(\d+)/', $stat, $matches) === 1) {
        $bootTime = null;
        foreach (file('/proc/stat', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'btime ')) {
                $bootTime = (int)substr($line, 6);
                break;
            }
        }

        $ticksResult = processRunCommand(['getconf', 'CLK_TCK'], ['timeout' => 2]);
        $ticksPerSecond = !empty($ticksResult['success']) ? (int)trim((string)$ticksResult['stdout']) : 0;
        if ($bootTime !== null && $ticksPerSecond > 0) {
            $startedAt = $bootTime + ((int)$matches[1] / $ticksPerSecond);
            $seconds = max(0, time() - (int)$startedAt);
            return formatDuration($seconds);
        }
    }

    return 'unknown';
}

function formatDuration(int $seconds): string
{
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) $parts[] = $days . 'd';
    if ($hours > 0 || !empty($parts)) $parts[] = $hours . 'h';
    if ($minutes > 0 || !empty($parts)) $parts[] = $minutes . 'm';
    $parts[] = $seconds . 's';

    return implode(' ', $parts);
}

function sendHealthResponse(string $projectRoot): void
{
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'version' => DEV_CONSOLE_VERSION,
        'php_version' => PHP_VERSION,
        'timestamp' => date('c'),
        'uptime' => processUptime(),
        'git_commit' => commandOutputOrNull(['git', '-C', $projectRoot, 'rev-parse', 'HEAD'], $projectRoot),
    ], JSON_UNESCAPED_SLASHES);
}

if ($requestMethod === 'GET' && $requestPath === '/health') {
    sendHealthResponse($devConsoleRoot);
    exit;
}

$sessionDirectory = DEPLOY_STATE_DIR . '/sessions';
if (!is_dir($sessionDirectory)) mkdir($sessionDirectory, 0700, true);
session_save_path($sessionDirectory);
$isHttps = (
    isset($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== ''
) || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
session_set_cookie_params(['secure' => $isHttps, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$consoleToken = (string)getenv('IOVON_DEV_CONSOLE_TOKEN');
if ($requestMethod === 'POST' && isset($_POST['console_token']) && $consoleToken !== '' && hash_equals($consoleToken, (string)$_POST['console_token'])) {
    session_regenerate_id(true);
    $_SESSION['dev_console_authenticated'] = true;
    header('Location: /');
    exit;
}
if (PHP_SAPI !== 'cli' && empty($_SESSION['dev_console_authenticated'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IOVON Dev Console</title></head><body>';
    echo '<main><h1>IOVON Dev Console</h1><p>Enter the persistent console token to continue.</p>';
    echo '<form method="post"><label for="console_token">Console token</label> <input id="console_token" name="console_token" type="password" required autofocus autocomplete="current-password"> <button type="submit">Authenticate</button></form></main></body></html>';
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['csrf_token'];

$projectConfiguration = devConsoleLoadProjectConfiguration();
$projects = devConsoleProjects($projectConfiguration);
$managedServers = managedServersLoad();
$activeProject = devConsoleActiveProject($projectConfiguration);
$activeProjectId = $activeProject === null ? '' : (string)($activeProject['id'] ?? '');
deploymentSetProject($activeProject);
$githubConfiguration = devConsoleLoadGithubConfiguration();
$githubConfigured = devConsoleGithubConfigured($githubConfiguration);
$githubCliInstalled = gitGhInstalled();
$runtimeSettings = runtimeLoadSettings();
$runtimeEffectiveLimits = runtimeEffectiveLimits();
$runtimeRestartRequired = runtimeRestartRequired($runtimeSettings, $runtimeEffectiveLimits);
$runtimeServiceUsesWrapper = runtimeServiceUsesWrapper();
$runtimeApplyInstruction = runtimeApplyInstruction();
$legacyRepoRoot = dirname(__DIR__, 2);
$repoRoot = devConsoleProjectTaskRoot($projectConfiguration, $activeProject);
$todoDir = $repoRoot . '/TASKS/TODO';
$doneDir = $repoRoot . '/TASKS/DONE';
$attachmentsRoot = taskAttachmentRoot($repoRoot);
$runsDir = devConsoleProjectRunsDir($activeProject);
$legacyTaskRoot = dirname(devConsoleRepositoryRoot());

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function configuredDisplayValue($value): string
{
    $text = is_array($value) ? implode(', ', array_map('strval', $value)) : (string)$value;
    return trim($text) === '' ? 'Not configured' : $text;
}

function serverToolsStoredDiagnosticsPlaceholder(): array
{
    $context = [
        'service_name' => DEV_CONSOLE_SERVICE_NAME,
        'user' => 'Not refreshed',
        'group' => 'Not refreshed',
        'path' => 'Not refreshed',
        'working_directory' => 'Not refreshed',
        'php_executable' => PHP_BINARY,
        'status' => 'Not refreshed',
    ];
    $tools = [];
    foreach (serverToolsDefinitions() as $id => $definition) {
        $tools[$id] = [
            'display_name' => (string)$definition['display_name'],
            'purpose' => (string)$definition['purpose'],
            'installed' => false,
            'version' => '',
            'executable_path' => '',
            'available_to_service_user' => false,
            'diagnostic_status' => 'Not refreshed',
            'last_checked_at' => '',
            'latest_version' => '',
            'outdated' => false,
            'package_source' => (string)$definition['source'],
            'dependency_relationship' => (string)$definition['dependency'],
            'requirement' => (string)$definition['requirement'],
            'required_group' => (string)$definition['required_group'],
            'log' => (string)$definition['display_name'] . ': not refreshed during page load.',
        ];
    }

    return [
        'context' => $context,
        'tools' => $tools,
        'generated_at' => '',
        'log' => 'Diagnostics were not refreshed during page load.',
    ];
}

function projectAdoptionDisplayBytes($bytes): string
{
    if ($bytes === null || !is_numeric($bytes)) {
        return 'Not available';
    }
    $value = (float)$bytes;
    foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return ($unit === 'B' ? (string)(int)$value : number_format($value, 1)) . ' ' . $unit;
        }
        $value /= 1024;
    }

    return (string)$bytes . ' B';
}

function renderProjectAdoptionDetails(array $rows): void
{
    ?>
    <dl class="project-detail-grid">
      <?php foreach ($rows as $label => $value): ?>
        <div><dt><?= h((string)$label) ?></dt><dd><?= is_string($value) ? h($value) : $value ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php
}

function renderProjectAdoptionPlan(array $plan, string $csrfToken): void
{
    $project = is_array($plan['project'] ?? null) ? $plan['project'] : [];
    $current = is_array($plan['current'] ?? null) ? $plan['current'] : [];
    $currentSource = is_array($current['source'] ?? null) ? $current['source'] : [];
    $currentPreview = is_array($current['preview'] ?? null) ? $current['preview'] : [];
    $currentProduction = is_array($current['production'] ?? null) ? $current['production'] : [];
    $proposed = is_array($plan['proposed'] ?? null) ? $plan['proposed'] : [];
    $github = is_array($plan['github'] ?? null) ? $plan['github'] : [];
    $tasks = is_array($plan['tasks'] ?? null) ? $plan['tasks'] : [];
    $actions = is_array($plan['actions'] ?? null) ? $plan['actions'] : [];
    $productionOnly = is_array($plan['production_only'] ?? null) ? $plan['production_only'] : [];
    $productionOnlyFiles = is_array($productionOnly['files'] ?? null) ? $productionOnly['files'] : [];
    $manual = is_array($plan['manual_corrections'] ?? null) ? $plan['manual_corrections'] : [];
    $canAdopt = !empty($plan['can_adopt']);
    $adoptionBlockers = is_array($plan['adoption_blockers'] ?? null) ? $plan['adoption_blockers'] : [];
    ?>
    <section class="result-block project-adoption-plan">
      <div class="dashboard-header">
        <div>
          <h3>Adoption Plan</h3>
          <p class="field-help">Review the import plan before adopting this Project. Existing Preview, Production, and Apache configuration will not be modified.</p>
        </div>
        <span class="status-pill warning">Review required</span>
      </div>
      <div class="project-adoption-report">
        <section class="environment-block">
          <h4>Project</h4>
          <?php renderProjectAdoptionDetails([
              'Proposed name' => configuredDisplayValue($project['name'] ?? ''),
              'Proposed Project ID' => configuredDisplayValue($project['id'] ?? ''),
              'Managed Server' => configuredDisplayValue($project['managed_server'] ?? ''),
          ]); ?>
        </section>
        <section class="environment-block">
          <h4>GitHub</h4>
          <?php renderProjectAdoptionDetails([
              'Existing repository' => configuredDisplayValue($github['identity'] ?? ''),
              'Compatibility' => configuredDisplayValue($github['status'] ?? ''),
              'Adoption behavior' => !empty($currentSource['remote']) ? 'Preserve existing repository/history' : 'No existing repository to preserve',
          ]); ?>
        </section>
        <section class="environment-block">
          <h4>Current Project Source</h4>
          <?php renderProjectAdoptionDetails([
              'Path' => configuredDisplayValue($currentSource['path'] ?? ''),
              'Git repository' => configuredDisplayValue($currentSource['git_status'] ?? ''),
              'Git remote' => configuredDisplayValue($currentSource['remote'] ?? ''),
              'Branch' => configuredDisplayValue($currentSource['branch'] ?? ''),
              'HEAD' => configuredDisplayValue($currentSource['head'] ?? ''),
              'TASKS' => configuredDisplayValue($currentSource['tasks_status'] ?? ''),
              'Task count' => (string)(int)($currentSource['task_count'] ?? 0),
              'Highest task' => configuredDisplayValue($currentSource['highest_task_number'] ?? ''),
          ]); ?>
        </section>
        <section class="environment-block">
          <h4>Current Preview / Production</h4>
          <?php renderProjectAdoptionDetails([
              'Preview domain' => configuredDisplayValue($currentPreview['domain'] ?? ''),
              'Preview path' => configuredDisplayValue($currentPreview['path'] ?? ''),
              'Preview evidence' => configuredDisplayValue($currentPreview['evidence'] ?? ''),
              'Production domain' => configuredDisplayValue($currentProduction['domain'] ?? ''),
              'Production path' => configuredDisplayValue($currentProduction['path'] ?? ''),
          ]); ?>
        </section>
        <section class="environment-block">
          <h4>Proposed Dev Console Structure</h4>
          <?php renderProjectAdoptionDetails([
              'Source repository' => configuredDisplayValue($proposed['source_repository'] ?? '') . "\nLocated on Dev Console Host",
              'Preview' => configuredDisplayValue(($proposed['preview_domain'] ?? '') . "\n" . ($proposed['preview_path'] ?? '')) . "\n" . configuredDisplayValue($proposed['preview_classification'] ?? '') . "\nLocated on Managed Server",
              'Production' => configuredDisplayValue(($proposed['production_domain'] ?? '') . "\n" . ($proposed['production_path'] ?? '')) . "\n" . configuredDisplayValue($proposed['production_classification'] ?? '') . "\nLocated on Managed Server",
          ]); ?>
        </section>
        <section class="environment-block">
          <h4>TASKS</h4>
          <?php renderProjectAdoptionDetails([
              'Classification' => configuredDisplayValue($tasks['classification'] ?? ''),
              'Task count' => (string)(int)($tasks['task_count'] ?? 0),
              'Highest task' => configuredDisplayValue($tasks['highest_task_number'] ?? ''),
              'Renumbering' => 'Historical tasks will not be renumbered',
          ]); ?>
        </section>
        <section class="environment-block wide">
          <h4>Action Classification</h4>
          <div class="table-scroll">
            <table class="settings-table project-adoption-actions">
              <thead><tr><th>Component</th><th>Classification</th><th>Adoption behavior</th></tr></thead>
              <tbody>
                <?php foreach ($actions as $action): ?>
                  <tr>
                    <td><?= h(configuredDisplayValue($action['component'] ?? '')) ?></td>
                    <td><span class="status-pill warning"><?= h(configuredDisplayValue($action['classification'] ?? 'NEEDS REVIEW')) ?></span></td>
                    <td><?= h(configuredDisplayValue($action['detail'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
        <section class="environment-block">
          <h4>Production Safety</h4>
          <ul class="operation-summary">
            <?php foreach (($plan['safety'] ?? []) as $safety): ?>
              <li><?= h((string)$safety) ?></li>
            <?php endforeach; ?>
          </ul>
        </section>
        <section class="environment-block">
          <h4>Special / Unmanaged Production Files</h4>
          <?php if ((int)($productionOnly['count'] ?? 0) > 0): ?>
            <p class="field-help">Production contains files not detected in the proposed Project Source. Review before adoption; Dev Console will not copy them automatically.</p>
            <ul class="compact-list">
              <?php foreach ($productionOnlyFiles as $file): ?>
                <li><code><?= h((string)$file) ?></code></li>
              <?php endforeach; ?>
            </ul>
            <?php if ((int)$productionOnly['count'] > count($productionOnlyFiles)): ?>
              <p class="field-help">Showing <?= h((string)count($productionOnlyFiles)) ?> of <?= h((string)(int)$productionOnly['count']) ?> files.</p>
            <?php endif; ?>
          <?php elseif ((string)($productionOnly['error'] ?? '') !== ''): ?>
            <p class="field-help"><?= h((string)$productionOnly['error']) ?></p>
          <?php else: ?>
            <p class="field-help">No production-only files were identified from the available discovery data.</p>
          <?php endif; ?>
        </section>
        <section class="environment-block wide project-adoption-confirmation">
          <h4>Confirmation</h4>
          <p class="field-help">Review or correct these values before adoption. Existing Preview, Production, and Apache configuration will not be modified, and no deployment will occur.</p>
          <?php if (!$canAdopt && !empty($adoptionBlockers)): ?>
            <ul class="operation-summary">
              <?php foreach ($adoptionBlockers as $blocker): ?>
                <li>Needs review: <?= h((string)$blocker) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <form method="post" action="/?tab=projects&amp;adoption=1#addExistingProject" data-preserve-settings-scroll="1" onsubmit="return confirm('Adopt this Project into Dev Console? Existing Preview, Production, and Apache configuration will not be modified, and no deployment will occur.');">
            <input type="hidden" name="action" value="adopt_existing_project">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="managed_server_id" value="<?= h((string)($project['managed_server_id'] ?? '')) ?>">
            <input type="hidden" name="expected_source_head" value="<?= h((string)($currentSource['head'] ?? '')) ?>">
            <input type="hidden" name="expected_source_remote" value="<?= h((string)($currentSource['remote'] ?? '')) ?>">
            <input type="hidden" name="expected_highest_task_number" value="<?= h((string)($currentSource['highest_task_number'] ?? '')) ?>">
            <input type="hidden" name="expected_task_fingerprint" value="<?= h((string)($currentSource['task_fingerprint'] ?? '')) ?>">
            <div class="project-adoption-form-grid">
            <div>
              <label for="adoption_plan_project_name">Project name</label>
              <input id="adoption_plan_project_name" name="project_name" type="text" required maxlength="255" value="<?= h((string)($manual['project_name'] ?? '')) ?>">
            </div>
            <div>
              <label for="adoption_plan_project_id">Project ID</label>
              <input id="adoption_plan_project_id" name="project_id" type="text" required maxlength="120" value="<?= h((string)($manual['project_id'] ?? '')) ?>">
            </div>
            <div>
              <label for="adoption_plan_source_path">Selected Project Source</label>
              <input id="adoption_plan_source_path" name="source_path" type="text" required maxlength="4096" value="<?= h((string)($manual['source_path'] ?? '')) ?>">
            </div>
            <div aria-hidden="true"></div>
            <div>
              <label for="adoption_plan_preview_path">Preview path</label>
              <input id="adoption_plan_preview_path" name="preview_path" type="text" maxlength="4096" value="<?= h((string)($manual['preview_path'] ?? '')) ?>">
            </div>
            <div>
              <label for="adoption_plan_preview_domain">Preview domain</label>
              <input id="adoption_plan_preview_domain" name="preview_domain" type="text" maxlength="253" value="<?= h((string)($manual['preview_domain'] ?? '')) ?>">
            </div>
            <div>
              <label for="adoption_plan_production_path">Production path</label>
              <input id="adoption_plan_production_path" name="production_path" type="text" required maxlength="4096" value="<?= h((string)($manual['production_path'] ?? '')) ?>">
            </div>
            <div>
              <label for="adoption_plan_production_domain">Production domain</label>
              <input id="adoption_plan_production_domain" name="production_domain" type="text" required maxlength="253" value="<?= h((string)($manual['production_domain'] ?? '')) ?>">
            </div>
            </div>
            <div class="project-adoption-manual-actions">
              <button type="submit"<?= $canAdopt ? '' : ' disabled title="Resolve adoption plan blockers before adopting this Project."' ?>>Adopt Project</button>
            </div>
          </form>
        </section>
      </div>
    </section>
    <?php
}

function renderProjectAdoptionResult(array $result, string $csrfToken): void
{
    $status = (string)($result['status'] ?? 'NEEDS REVIEW');
    $identity = is_array($result['identity'] ?? null) ? $result['identity'] : [];
    $webServer = is_array($result['web_server'] ?? null) ? $result['web_server'] : [];
    $filesystem = is_array($result['filesystem'] ?? null) ? $result['filesystem'] : [];
    $technology = is_array($result['technology'] ?? null) ? $result['technology'] : [];
    $configuration = is_array($result['configuration'] ?? null) ? $result['configuration'] : [];
    $git = is_array($result['git'] ?? null) ? $result['git'] : [];
    $tasks = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];
    $history = is_array($result['history'] ?? null) ? $result['history'] : [];
    $matches = is_array($webServer['matches'] ?? null) ? $webServer['matches'] : [];
    $selectedVhost = is_array($webServer['selected_vhost'] ?? null) ? $webServer['selected_vhost'] : null;
    $technologyMarkers = is_array($technology['markers'] ?? null) ? $technology['markers'] : [];
    $taskDirs = is_array($tasks['directories'] ?? null) ? $tasks['directories'] : [];
    $remotes = is_array($git['remotes'] ?? null) ? $git['remotes'] : [];
    $sshError = is_array($result['ssh_error'] ?? null) ? $result['ssh_error'] : null;
    $relatedSites = is_array($result['related_sites'] ?? null) ? $result['related_sites'] : [];
    $proposal = is_array($result['proposed_structure'] ?? null) ? $result['proposed_structure'] : null;
    $adoptionPlan = is_array($result['adoption_plan'] ?? null) ? $result['adoption_plan'] : null;
    $directoryCounts = is_array($tasks['directory_counts'] ?? null) ? $tasks['directory_counts'] : [];
    $missingTasks = is_array($tasks['missing_task_numbers'] ?? null) ? $tasks['missing_task_numbers'] : [];
    $nonstandardTasks = is_array($tasks['nonstandard_task_files'] ?? null) ? $tasks['nonstandard_task_files'] : [];
    $duplicateTasks = is_array($tasks['duplicate_task_numbers'] ?? null) ? $tasks['duplicate_task_numbers'] : [];
    ?>
    <section class="result-block project-adoption-result <?= h(projectAdoptionStatusClass($status)) ?>">
      <h3>Existing Project Discovery</h3>
      <div class="project-adoption-report">
      <section class="environment-block project-adoption-overview">
        <h4>Overall Result</h4>
        <p><span class="status-pill <?= h(projectAdoptionStatusClass($status)) ?>"><?= h($status) ?></span></p>
        <?php if (!empty($result['errors']) && is_array($result['errors'])): ?>
          <ul class="operation-summary">
            <?php foreach ($result['errors'] as $error): ?><li>Error: <?= h((string)$error) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($result['warnings']) && is_array($result['warnings'])): ?>
          <ul class="operation-summary">
            <?php foreach ($result['warnings'] as $warning): ?><li>Needs review: <?= h((string)$warning) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!empty($result['notes']) && is_array($result['notes'])): ?>
          <ul class="operation-summary">
            <?php foreach ($result['notes'] as $note): ?><li>Note: <?= h((string)$note) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($sshError !== null): ?>
          <section class="environment-block project-adoption-ssh-detail">
            <h4>SSH Diagnostic</h4>
            <?php renderProjectAdoptionDetails([
                'Exit code' => ($sshError['exit_code'] ?? null) === null ? 'Not available' : (string)$sshError['exit_code'],
            ]); ?>
            <pre class="tool-operation-log"><?= h((string)($sshError['detail'] ?? 'No SSH output was returned.')) ?></pre>
          </section>
        <?php endif; ?>
      </section>
      <section class="environment-block">
        <h4>Identity</h4>
        <?php renderProjectAdoptionDetails([
            'Project name' => configuredDisplayValue($identity['project_name'] ?? ''),
            'Managed Server' => configuredDisplayValue($identity['managed_server'] ?? ''),
            'Production domain' => configuredDisplayValue($identity['production_domain'] ?? ''),
            'Production path' => configuredDisplayValue($identity['production_path'] ?? ''),
        ]); ?>
      </section>
      <?php if ($proposal !== null): ?>
        <section class="environment-block wide">
          <h4>Proposed Project Structure</h4>
          <?php renderProjectAdoptionDetails([
              'Project' => configuredDisplayValue($proposal['project_name'] ?? ''),
              'Project Source' => configuredDisplayValue($proposal['source_path'] ?? ''),
              'Source Git history' => !empty($proposal['source_git']) ? 'Detected' : 'Not detected',
              'Source TASKS history' => !empty($proposal['source_tasks']) ? 'Detected' : 'Not detected',
              'Production' => configuredDisplayValue((string)($proposal['production_domain'] ?? '') . "\n" . (string)($proposal['production_path'] ?? '')),
              'Historical Preview / Development' => configuredDisplayValue((string)($proposal['historical_preview_domain'] ?? '') . "\n" . (string)($proposal['historical_preview_path'] ?? '')),
          ]); ?>
        </section>
      <?php endif; ?>
      <?php if (!empty($relatedSites)): ?>
        <section class="environment-block wide">
          <h4>Related Sites / Project Locations</h4>
          <div class="table-scroll">
            <table class="settings-table project-related-sites">
              <thead><tr><th>Domain</th><th>Path</th><th>Possible role</th><th>Git</th><th>TASKS</th><th>History</th><th>Reason</th></tr></thead>
              <tbody>
                <?php foreach ($relatedSites as $site): ?>
                  <tr>
                    <td><?= h(configuredDisplayValue($site['domain'] ?? '')) ?></td>
                    <td><code><?= h(configuredDisplayValue($site['path'] ?? '')) ?></code></td>
                    <td><?= h(configuredDisplayValue($site['role'] ?? '')) ?></td>
                    <td><?= !empty($site['git']) ? 'Yes' : 'No' ?></td>
                    <td><?= !empty($site['tasks']) ? 'Yes' : 'No' ?><?= (string)($site['highest_task_number'] ?? '') !== '' ? '<br><span class="meta">' . h((string)$site['highest_task_number']) . '</span>' : '' ?></td>
                    <td><?= !empty($site['history']) ? 'Yes' : 'No' ?></td>
                    <td><?= h(configuredDisplayValue($site['reason'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
      <section class="environment-block wide">
        <h4>Web Server</h4>
        <?php renderProjectAdoptionDetails([
            'Apache inventory' => ($webServer['apache_available'] ?? null) === null ? 'Not inspected' : (!empty($webServer['apache_available']) ? 'Available' : 'Not available'),
            'Apache match' => configuredDisplayValue($webServer['match_status'] ?? ''),
            'Selected vhost' => $selectedVhost === null ? 'Not selected' : (string)$selectedVhost['name'],
            'DocumentRoot' => configuredDisplayValue($webServer['document_root'] ?? ''),
        ]); ?>
        <?php if (!empty($matches)): ?>
          <details class="compact-details">
            <summary>Matching Apache vhosts</summary>
            <table class="compact-table">
              <thead><tr><th>Vhost</th><th>Status</th><th>ServerName</th><th>Aliases</th><th>DocumentRoot</th></tr></thead>
              <tbody>
                <?php foreach ($matches as $site): ?>
                  <tr>
                    <td><?= h((string)($site['name'] ?? '')) ?></td>
                    <td><?= !empty($site['enabled']) ? 'Enabled' : 'Disabled' ?></td>
                    <td><?= h(configuredDisplayValue($site['server_name'] ?? '')) ?></td>
                    <td><?= h(configuredDisplayValue($site['server_aliases'] ?? '')) ?></td>
                    <td><code><?= h(configuredDisplayValue($site['document_root'] ?? '')) ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        <?php endif; ?>
      </section>
      <section class="environment-block">
        <h4>Filesystem</h4>
        <?php renderProjectAdoptionDetails([
            'Path' => configuredDisplayValue($filesystem['path'] ?? ''),
            'Exists' => !empty($filesystem['exists']) ? 'Yes' : 'No',
            'Readable' => !empty($filesystem['readable']) ? 'Yes' : 'No',
            'Owner / Group' => trim((string)($filesystem['owner'] ?? '') . ' / ' . (string)($filesystem['group'] ?? '')) === '/' ? 'Not available' : configuredDisplayValue((string)($filesystem['owner'] ?? '') . ' / ' . (string)($filesystem['group'] ?? '')),
            'Size' => projectAdoptionDisplayBytes($filesystem['size_bytes'] ?? null),
            'File count' => isset($filesystem['file_count']) ? number_format((int)$filesystem['file_count']) : 'Not available',
        ]); ?>
      </section>
      <section class="environment-block">
        <h4>Technology</h4>
        <?php renderProjectAdoptionDetails([
            'PHP files' => !empty($technology['php_files']) ? 'Detected' : 'Not detected',
            'Composer' => (!empty($technology['composer_json']) ? 'composer.json' : 'Not detected') . (!empty($technology['composer_lock']) ? ' + composer.lock' : ''),
            'Node/npm' => (!empty($technology['package_json']) ? 'package.json' : 'Not detected') . (!empty($technology['package_lock']) ? ' + package-lock.json' : '') . (!empty($technology['yarn_lock']) ? ' + yarn.lock' : '') . (!empty($technology['pnpm_lock']) ? ' + pnpm-lock.yaml' : ''),
            'Project markers' => empty($technologyMarkers) ? 'Not detected' : implode(', ', $technologyMarkers),
        ]); ?>
      </section>
      <section class="environment-block">
        <h4>Configuration</h4>
        <?php renderProjectAdoptionDetails([
            '.env' => !empty($configuration['env_present']) ? 'Present' : 'Not detected',
        ]); ?>
      </section>
      <section class="environment-block">
        <h4>Git</h4>
        <?php renderProjectAdoptionDetails([
            'Repository' => configuredDisplayValue($git['inspection'] ?? ''),
            'Branch' => configuredDisplayValue($git['branch'] ?? ''),
            'HEAD' => configuredDisplayValue($git['head'] ?? ''),
            'History' => !empty($git['history_available']) ? 'Available' : 'Not detected',
            'Remote' => empty($remotes) ? 'Not configured' : implode("\n", array_map('strval', $remotes)),
        ]); ?>
      </section>
      <section class="environment-block">
        <h4>TASKS</h4>
        <?php renderProjectAdoptionDetails([
            'Status' => !empty($tasks['detected']) ? 'Detected' : 'Not detected at Production path',
            'Lifecycle directories' => empty($taskDirs) ? 'Not detected' : implode(', ', $taskDirs),
            'Task count' => (string)(int)($tasks['expected_task_count'] ?? ($tasks['task_count'] ?? 0)),
            'Total files under TASKS' => (string)(int)($tasks['total_files'] ?? 0),
            'Other task-like files' => (string)(int)($tasks['other_task_count'] ?? 0),
            'Task range' => configuredDisplayValue(trim((string)($tasks['minimum_task_number'] ?? '') . ' - ' . (string)($tasks['maximum_task_number'] ?? ''), " -")),
            'Highest task number' => configuredDisplayValue($tasks['highest_task_number'] ?? ''),
            'Compatibility' => !empty($tasks['compatible']) ? 'Appears compatible' : (!empty($tasks['detected']) ? 'Needs review' : 'Not applicable'),
        ]); ?>
        <?php if (!empty($directoryCounts) || !empty($missingTasks) || !empty($nonstandardTasks) || !empty($duplicateTasks)): ?>
          <details class="compact-details">
            <summary>TASKS inventory details</summary>
            <?php if (!empty($directoryCounts)): ?>
              <h5>Files by directory</h5>
              <table class="compact-table">
                <tbody>
                  <?php foreach ($directoryCounts as $directory => $count): ?>
                    <tr><th><?= h((string)$directory) ?></th><td><?= h((string)(int)$count) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
            <?php if (!empty($missingTasks)): ?>
              <p class="field-help">Missing task numbers: <?= h(implode(', ', array_map('strval', array_slice($missingTasks, 0, 80)))) ?><?= count($missingTasks) > 80 ? ' ...' : '' ?></p>
            <?php endif; ?>
            <?php if (!empty($duplicateTasks)): ?>
              <p class="field-help">Duplicate task numbers: <?= h(implode(', ', array_map(static fn(array $item): string => (string)($item['task_id'] ?? '') . ' (' . (string)($item['count'] ?? 0) . ')', $duplicateTasks))) ?></p>
            <?php endif; ?>
            <?php if (!empty($nonstandardTasks)): ?>
              <p class="field-help">Non-standard task-like files:</p>
              <ul class="compact-list">
                <?php foreach ($nonstandardTasks as $taskFile): ?>
                  <li><?= h((string)$taskFile) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </details>
        <?php endif; ?>
      </section>
      <section class="environment-block">
        <h4>Dev Console History</h4>
        <?php renderProjectAdoptionDetails([
            'History' => configuredDisplayValue($history['status'] ?? ''),
        ]); ?>
      </section>
      <section class="environment-block">
        <h4>Safety</h4>
        <ul class="operation-summary">
          <?php foreach (($result['safety'] ?? [projectAdoptionReadOnlyNotice()]) as $safety): ?>
            <li><?= h((string)$safety) ?></li>
          <?php endforeach; ?>
        </ul>
      </section>
      </div>
    </section>
    <?php if ($adoptionPlan !== null): ?>
      <?php renderProjectAdoptionPlan($adoptionPlan, $csrfToken); ?>
    <?php endif; ?>
    <?php
}

function renderProjectAdoptionActionResult(array $result): void
{
    ?>
    <section class="result-block <?= !empty($result['success']) ? '' : 'error' ?>">
      <h3><?= h((string)($result['message'] ?? 'Adoption result')) ?></h3>
      <?php if (!empty($result['summary']) && is_array($result['summary'])): ?>
        <ul class="operation-summary">
          <?php foreach ($result['summary'] as $summary): ?>
            <li><?= h((string)$summary) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($result['success']) && (string)($result['project_id'] ?? '') !== ''): ?>
        <p><a class="button-link" href="/?tab=dashboard&amp;project=<?= h((string)$result['project_id']) ?>">Open adopted Project in Dev Console</a></p>
      <?php endif; ?>
      <?php if (trim((string)($result['output'] ?? '')) !== ''): ?>
        <details class="compact-details">
          <summary>Show adoption log</summary>
          <pre class="tool-operation-log"><?= h(trim((string)$result['output'])) ?></pre>
        </details>
      <?php endif; ?>
    </section>
    <?php
}

function renderProjectAdoptionScanResult(array $result, string $csrfToken): void
{
    $sites = is_array($result['sites'] ?? null) ? $result['sites'] : [];
    $sshError = is_array($result['ssh_error'] ?? null) ? $result['ssh_error'] : null;
    $serverId = (string)($result['values']['managed_server_id'] ?? '');
    ?>
    <section class="result-block project-adoption-result <?= !empty($result['success']) ? '' : 'warning' ?>">
      <h3>Discovered Sites &amp; Project Sources</h3>
      <div class="project-adoption-report">
        <section class="environment-block project-adoption-overview">
          <h4>Overall Result</h4>
          <p><span class="status-pill <?= !empty($result['success']) ? 'healthy' : 'warning' ?>"><?= h((string)($result['status'] ?? 'Scan result')) ?></span></p>
          <?php if ((string)($result['generated_at'] ?? '') !== ''): ?>
            <p class="field-help">Stored scan result from <?= h((string)$result['generated_at']) ?>. Reloading this page does not rescan the server.</p>
          <?php endif; ?>
          <?php if (!empty($result['errors']) && is_array($result['errors'])): ?>
            <ul class="operation-summary">
              <?php foreach ($result['errors'] as $error): ?><li>Error: <?= h((string)$error) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if (!empty($result['warnings']) && is_array($result['warnings'])): ?>
            <ul class="operation-summary">
              <?php foreach ($result['warnings'] as $warning): ?><li>Needs review: <?= h((string)$warning) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($sshError !== null): ?>
            <section class="environment-block project-adoption-ssh-detail">
              <h4>SSH Diagnostic</h4>
              <?php renderProjectAdoptionDetails([
                  'Exit code' => ($sshError['exit_code'] ?? null) === null ? 'Not available' : (string)$sshError['exit_code'],
              ]); ?>
              <pre class="tool-operation-log"><?= h((string)($sshError['detail'] ?? 'No SSH output was returned.')) ?></pre>
            </section>
          <?php endif; ?>
        </section>
        <?php if (!empty($sites)): ?>
          <section class="environment-block wide">
            <h4>Sites &amp; Project Sources</h4>
            <div class="table-scroll">
              <table class="settings-table project-adoption-sites">
                <thead>
                  <tr><th>Site / Source</th><th>Path</th><th>Apache</th><th>Git</th><th>TASKS</th><th>Status / Type</th><th>Action</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($sites as $site): ?>
                    <?php
                      $domain = (string)($site['domain'] ?? '');
                      $path = (string)($site['document_root'] ?? '');
                      $vhosts = is_array($site['vhosts'] ?? null) ? $site['vhosts'] : [];
                      $vhostNames = array_map(static fn(array $vhost): string => (string)($vhost['name'] ?? ''), $vhosts);
                      $inspectable = !empty($site['inspectable']);
                      $existingProject = (string)($site['existing_project'] ?? '');
                      $scanInspection = is_array($site['inspection'] ?? null) ? $site['inspection'] : [];
                      $scanGit = is_array($scanInspection['git'] ?? null) ? $scanInspection['git'] : [];
                      $scanTasks = is_array($scanInspection['tasks'] ?? null) ? $scanInspection['tasks'] : [];
                      $highestTask = (string)($scanTasks['highest_task_number'] ?? '');
                      $taskCount = (int)($scanTasks['expected_task_count'] ?? ($scanTasks['task_count'] ?? 0));
                      $type = (string)($site['type'] ?? 'Apache site');
                    ?>
                    <tr>
                      <td>
                        <strong><?= h(configuredDisplayValue($domain !== '' ? $domain : $type)) ?></strong>
                        <?php if (!empty($site['hosts']) && is_array($site['hosts'])): ?>
                          <br><span class="meta"><?= h(implode(', ', array_map('strval', $site['hosts']))) ?></span>
                        <?php endif; ?>
                      </td>
                      <td><code><?= h(configuredDisplayValue($path)) ?></code></td>
                      <td>
                        <?= h(empty($vhostNames) ? 'No active vhost' : implode(' + ', $vhostNames)) ?>
                        <?php if (!empty($vhosts)): ?>
                          <details class="compact-details">
                            <summary>Details</summary>
                            <ul class="compact-list">
                              <?php foreach ($vhosts as $vhost): ?>
                                <li><?= h((string)($vhost['name'] ?? '')) ?>: <?= !empty($vhost['enabled']) ? 'enabled' : 'disabled' ?>, <?= !empty($vhost['managed']) ? 'managed' : 'not managed' ?></li>
                              <?php endforeach; ?>
                            </ul>
                          </details>
                        <?php endif; ?>
                      </td>
                      <td><?= !empty($scanGit['repository_detected']) ? 'Yes' : 'No' ?><?= (string)($scanGit['branch'] ?? '') !== '' ? '<br><span class="meta">' . h((string)$scanGit['branch']) . '</span>' : '' ?></td>
                      <td><?= !empty($scanTasks['detected']) ? 'Yes' : 'No' ?><?= $highestTask !== '' || $taskCount > 0 ? '<br><span class="meta">' . h(trim((string)$taskCount . ' files ' . $highestTask)) . '</span>' : '' ?></td>
                      <td><?= h((string)($site['status'] ?? 'Needs review')) ?><br><span class="meta"><?= h($type) ?></span><?= $existingProject !== '' ? '<br><span class="meta">Already in Dev Console: ' . h($existingProject) . '</span>' : (!empty($site['managed']) ? '<br><span class="meta">Managed marker detected</span>' : '') ?></td>
                      <td>
                        <form method="post" action="/?tab=projects&amp;adoption=1#addExistingProject" data-preserve-settings-scroll="1">
                          <input type="hidden" name="action" value="discover_existing_project">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="discovery_source" value="inspect">
                          <input type="hidden" name="managed_server_id" value="<?= h($serverId) ?>">
                          <input type="hidden" name="project_name" value="<?= h((string)($site['project_name'] ?? 'Existing Website')) ?>">
                          <input type="hidden" name="production_domain" value="<?= h($domain) ?>">
                          <input type="hidden" name="production_path" value="<?= h($path) ?>">
                          <button type="submit" class="secondary"<?= $inspectable ? '' : ' disabled title="This site needs a hostname and DocumentRoot before it can be inspected."' ?>>Inspect</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
        <section class="environment-block wide">
          <h4>Safety</h4>
          <ul class="operation-summary">
            <?php foreach (($result['safety'] ?? [projectAdoptionReadOnlyNotice()]) as $safety): ?>
              <li><?= h((string)$safety) ?></li>
            <?php endforeach; ?>
          </ul>
        </section>
      </div>
    </section>
    <?php
}

function workflowStateClass(string $state): string
{
    $normalized = strtolower($state);
    if (str_contains($normalized, 'failed') || str_contains($normalized, 'outdated') || str_contains($normalized, 'promotion')) {
        return 'warning';
    }
    if (str_contains($normalized, 'completed') || str_contains($normalized, 'deployed') || str_contains($normalized, 'sync') || str_contains($normalized, 'done')) {
        return 'healthy';
    }
    if (str_contains($normalized, 'running') || str_contains($normalized, 'progress')) {
        return 'running';
    }

    return 'pending';
}

function workflowSummary(array $context): array
{
    $taskId = (string)($context['task_id'] ?? '');
    $taskStatus = $taskId === '' ? 'No active task' : (string)($context['task_status'] ?? 'TODO');
    $codexRunStatus = (string)($context['codex_run_status'] ?? 'not_started');
    $codexResult = is_array($context['codex_result'] ?? null) ? $context['codex_result'] : [];
    $gitCommit = (string)($context['git_commit'] ?? '');
    $preview = is_array($context['preview'] ?? null) ? $context['preview'] : [];
    $production = is_array($context['production'] ?? null) ? $context['production'] : [];

    $codexState = match ($codexRunStatus) {
        'queued', 'running' => 'Running',
        'completed' => 'Completed',
        'failed' => 'Failed',
        default => 'Not started',
    };
    $implementationCommit = (string)($codexResult['commit'] ?? '');
    if ($implementationCommit === '') {
        $implementationCommit = $gitCommit;
    }

    $previewCommit = (string)($preview['commit'] ?? '');
    $previewStatus = (string)($preview['status'] ?? 'never_deployed');
    $previewState = match ($previewStatus) {
        'running' => 'Running',
        'failed' => 'Failed',
        'deployed' => $implementationCommit !== '' && $previewCommit !== '' && $previewCommit !== $implementationCommit ? 'Outdated' : 'Deployed',
        default => 'Not deployed',
    };

    $productionCommit = (string)($production['commit'] ?? '');
    $productionStatus = (string)($production['status'] ?? 'never_deployed');
    $productionState = match ($productionStatus) {
        'running' => 'Running',
        'failed' => 'Failed',
        'deployed' => $previewCommit !== '' && $productionCommit === $previewCommit ? 'In sync with Preview' : ($previewCommit !== '' ? 'Preview ready for promotion' : 'Never deployed'),
        default => 'Never deployed',
    };

    return [
        [
            'name' => 'Task',
            'state' => $taskStatus,
            'primary' => $taskId === '' ? 'No active task' : $taskId,
            'detail' => $taskId !== '' && (string)($context['task_commit'] ?? '') !== '' ? 'Commit ' . shortSha((string)$context['task_commit']) : '',
        ],
        [
            'name' => 'Codex',
            'state' => $codexState,
            'primary' => $implementationCommit !== '' && $codexState === 'Completed' ? 'Commit ' . shortSha($implementationCommit) : $codexState,
            'detail' => implode(' · ', array_filter([
                (string)($codexResult['validation'] ?? ''),
                isset($codexResult['duration_seconds']) ? formatDuration((int)$codexResult['duration_seconds']) : '',
            ])),
        ],
        [
            'name' => 'Preview',
            'state' => $previewState,
            'primary' => $previewCommit === '' ? $previewState : shortSha($previewCommit),
            'detail' => (string)($preview['url'] ?? ''),
        ],
        [
            'name' => 'Production',
            'state' => $productionState,
            'primary' => $productionCommit === '' ? $productionState : shortSha($productionCommit),
            'detail' => (string)($production['production_url'] ?? ''),
        ],
    ];
}

function sendJson(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}
function renderCommandResult(array $result): void
{
    echo '<section class="result-block">';
    echo '<h2>' . h($result['command']) . '</h2>';
    echo '<p>Exit code: ' . h((string)$result['exit_code']) . '</p>';
    echo '<pre>' . h($result['output'] === '' ? '(no output)' : $result['output']) . '</pre>';
    echo '</section>';
}

function renderGitOutput(array $results): void
{
    echo '<details class="result-block git-output">';
    echo '<summary>Git output</summary>';

    foreach ($results as $result) {
        echo '<div class="command-output">';
        echo '<h3>' . h($result['command']) . '</h3>';
        echo '<p>Exit code: ' . h((string)$result['exit_code']) . '</p>';
        echo '<pre>' . h($result['output'] === '' ? '(no output)' : $result['output']) . '</pre>';
        echo '</div>';
    }

    echo '</details>';
}

function projectActionTitle(string $projectAction, bool $success): string
{
    if ($projectAction === 'provision_project') return $success ? 'Project set up' : 'Project setup failed';
    if ($projectAction === 'remove_project') return $success ? 'Project removed' : 'Project removal failed';
    if ($projectAction === 'delete_project') return $success ? 'Project deleted' : 'Project deletion failed';
    if ($projectAction === 'verify_project_routing') return $success ? 'Websites checked' : 'Website check failed';
    if ($projectAction === 'initialize_repository') return $success ? 'Repository initialized' : 'Repository initialization failed';
    if ($projectAction === 'fetch_git_repository') return $success ? 'Git fetch completed' : 'Git fetch failed';
    if ($projectAction === 'pull_git_repository') return $success ? 'Git pull completed' : 'Git pull failed';
    if ($projectAction === 'push_git_repository') return $success ? 'Git push completed' : 'Git push failed';
    if ($projectAction === 'cleanup_orphaned_project') return $success ? 'Orphaned infrastructure cleaned up' : 'Orphaned cleanup failed';
    if ($projectAction === 'refresh_server_diagnostics') return $success ? 'Server diagnostics refreshed' : 'Server diagnostics failed';
    if ($projectAction === 'server_tool_action') return $success ? 'Server tool action completed' : 'Server tool action failed';

    return $success ? 'Project action completed' : 'Project action failed';
}

function renderOperationResult(array $result, string $operationLogId, string $downloadName): void
{
    $projectAction = (string)($result['action'] ?? '');
    $success = !empty($result['success']);
    $operationSteps = operationSummarySteps($projectAction, $result);
    $operationLog = (string)($result['output'] ?? '');
    $hasOperationLog = trim($operationLog) !== '';
    ?>
    <section class="result-block <?= $success ? '' : 'error' ?>">
      <h2><?= h(projectActionTitle($projectAction, $success)) ?></h2>
      <p><?= h((string)$result['message']) ?></p>
      <?php if (!empty($operationSteps)): ?>
        <ul class="operation-summary">
          <?php foreach ($operationSteps as $step): ?>
            <?php $stepText = (string)$step; ?>
            <li><?= h(preg_match('/^(Done|Kept):\s/', $stepText) === 1 ? $stepText : ('Done: ' . $stepText)) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php $setupCommandForResult = trim((string)($result['setup_command'] ?? '')); ?>
      <?php if ($setupCommandForResult !== ''): ?>
        <?php $setupCommandId = $operationLogId . '-setup'; ?>
        <section class="generated-summary">
          <h3>Prepare this server for Dev Console</h3>
          <p class="field-help">Log in to the Managed Server as the configured SSH user, run this command, then return here and use Retry Setup. Enter the user's sudo password if prompted.</p>
          <div class="hosts-copy-row">
            <pre class="local-hosts" id="<?= h($setupCommandId) ?>"><?= h($setupCommandForResult) ?></pre>
            <button type="button" class="secondary" data-copy-log="<?= h($setupCommandId) ?>">Copy</button>
          </div>
        </section>
      <?php endif; ?>
      <?php if ($hasOperationLog): ?>
        <details<?= $success ? '' : ' open' ?>>
          <summary>Show operation log</summary>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="<?= h($operationLogId) ?>">Copy Log</button>
            <button type="button" class="secondary" data-download-log="<?= h($operationLogId) ?>" data-download-name="<?= h($downloadName) ?>">Download Log</button>
            <span class="hint" data-log-message="<?= h($operationLogId) ?>" aria-live="polite"></span>
          </div>
          <pre id="<?= h($operationLogId) ?>"><?= h($operationLog) ?></pre>
        </details>
      <?php endif; ?>
    </section>
    <?php
}

$taskContexts = taskStorageContexts($projectConfiguration, $activeProject);
$nextNumber = $activeProjectId === '' ? 1 : nextTaskNumber($taskContexts, $activeProjectId);
$latestTasks = $activeProjectId === '' ? [] : taskFileEntries($taskContexts, $activeProjectId);
$legacyTasksDetected = legacyTasksDetected($latestTasks);
$taskGroups = groupedTaskEntries($latestTasks, $runsDir);
$requestedTaskFile = (string)($_GET['task'] ?? '');
$requestedTaskSource = (string)($_GET['task_source'] ?? '');
$viewTask = $activeProjectId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $requestedTaskFile, $requestedTaskSource);
if ($viewTask !== null) {
    saveCurrentTaskSelection($activeProjectId, (string)$viewTask['task_id'], (string)$viewTask['source']);
} elseif ($requestedTaskFile === '' && $activeProjectId !== '') {
    $storedTaskId = is_scalar($_SESSION[currentTaskSessionKey($activeProjectId)] ?? null) ? (string)$_SESSION[currentTaskSessionKey($activeProjectId)] : '';
    $storedTaskSource = is_scalar($_SESSION[currentTaskSourceSessionKey($activeProjectId)] ?? null) ? (string)$_SESSION[currentTaskSourceSessionKey($activeProjectId)] : '';
    $viewTask = $storedTaskId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $storedTaskId . '.md', $storedTaskSource);
    if ($viewTask === null) {
        clearCurrentTaskSelection($activeProjectId);
    }
}
$createdTaskId = '';
$createdTaskPath = '';
$attachmentPaths = [];
$createdTaskAttachments = [];
$commitHash = '';
$prompt = '';
$error = '';
$taskSaveMessage = '';
$taskAttachmentMessage = '';
$taskLifecycleMessage = '';
$apacheActionResult = null;
$projectActionResult = null;
$githubActionResult = null;
$runtimeActionResult = null;
$serverDiagnosticsResult = null;
$managedServerActionResult = null;
$managedServerFormErrors = [];
$managedServerFormValues = managedServersEmptyServer();
$projectFormErrors = [];
$projectFormValues = [
    'project_name' => '',
    'production_domain' => '',
    'managed_server_id' => '',
];
$existingProjectDiscoveryValues = projectAdoptionEmptyInput();
$existingProjectDiscoveryResult = null;
$existingProjectDiscoverySource = '';
$existingProjectScanValues = projectAdoptionEmptyScanInput();
$existingProjectScanResult = null;
$existingProjectAdoptionResult = null;
$generatedPathTemplates = [
    'repository' => devConsoleGeneratedRepositoryPath('__PROJECT_ID__'),
    'production' => devConsoleGeneratedEnvironmentPaths('__PROJECT_ID__')['production'],
    'preview' => devConsoleGeneratedEnvironmentPaths('__PROJECT_ID__')['preview'],
];
$projectFlash = '';
$results = [];
$taskPushWarning = '';
$taskRepositoryReadiness = taskRepositoryReadiness($activeProject, $githubConfiguration);
$taskCreationReady = !empty($taskRepositoryReadiness['ready']);
$taskCreationUnavailableReason = (string)($taskRepositoryReadiness['reason'] ?? '');
$codexCliReady = codexCliInstalled();
$codexAuthReady = $codexCliReady && codexCliAuthenticated();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$postLimitExceeded = runtimePostLimitExceeded($runtimeEffectiveLimits);
if ($postLimitExceeded && $action === '') {
    $error = runtimePostLimitExceededMessage($runtimeEffectiveLimits);
}

if ($action === 'new_task') {
    if ($activeProjectId !== '') {
        clearCurrentTaskSelection($activeProjectId);
    }
    header('Location: /?tab=dashboard#dashboardTaskEditor');
    exit;
}

if ($action === 'task_attachment') {
    $taskId = is_scalar($_GET['task_id'] ?? null) ? (string)$_GET['task_id'] : '';
    $source = is_scalar($_GET['task_source'] ?? null) ? (string)$_GET['task_source'] : 'project';
    $name = is_scalar($_GET['name'] ?? null) ? (string)$_GET['name'] : '';
    $mode = (string)($_GET['mode'] ?? 'open');
    $context = taskContextForSource($taskContexts, $source);
    $record = $activeProjectId === '' ? null : taskAttachmentRecordByName($taskContexts, $activeProjectId, $taskId, $source, $name);
    if ($context === null || $record === null || !in_array($mode, ['open', 'download'], true)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    $path = taskAttachmentAbsolutePath($context, $record);
    $realRoot = realpath((string)$context['root'] . '/TASKS');
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot . '/') || !is_file($realPath)) {
        http_response_code(404);
        exit('Attachment not found.');
    }
    header('Content-Type: ' . (string)$record['mime']);
    header('Content-Length: ' . (string)filesize($realPath));
    header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . addcslashes((string)$record['name'], "\"\\") . '"');
    readfile($realPath);
    exit;
}

if ($action === 'codex-status') {
    $taskId = (string)($_GET['task'] ?? '');
    $taskSource = (string)($_GET['task_source'] ?? 'project');
    try {
        if (!isTaskId($taskId)) {
            throw new RuntimeException('Invalid task id.');
        }
        if ($activeProjectId === '' || findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $taskSource) === null) {
            throw new RuntimeException('Task does not belong to the active Project.');
        }
        $status = codexRunStatus($runsDir, $taskId, $taskSource);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status), 'result' => codexRunResult($runsDir, $taskId, $taskSource)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'codex-log') {
    $taskId = (string)($_GET['task'] ?? '');
    $taskSource = (string)($_GET['task_source'] ?? 'project');
    if (!isTaskId($taskId) || $activeProjectId === '' || findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $taskSource) === null) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Task does not belong to the active Project.';
        exit;
    }

    $logPath = runFile($runsDir, $taskId, 'log', $taskSource);
    header('Content-Type: text/plain; charset=UTF-8');
    echo is_file($logPath) ? (string)file_get_contents($logPath) : 'No log file yet.';
    exit;
}

if ($action === 'deployment-preview' || $action === 'deployment-start') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); sendJson(['ok' => false, 'error' => 'Invalid deployment request.']); exit;
    }
    try {
        if ($activeProject === null) {
            throw new RuntimeException('Select or create a Project before deploying.');
        }
        $environment = (string)($_POST['environment'] ?? '');
        $configuration = deploymentConfiguration($environment);
        $errors = deploymentValidation($environment);
        if ($errors) {
            $state = newDeploymentState($environment, 'failed', ['added' => 0, 'updated' => 0, 'deleted' => 0, 'files' => []], 'local-console');
            $state['finish_time'] = date('c'); $state['error'] = implode(' ', $errors);
            appendDeploymentLog($state, 'Validation started.');
            appendDeploymentLog($state, 'Validation result: failed. ' . $state['error']);
            appendDeploymentLog($state, 'Final status: Failed.'); writeDeploymentState($state);
            throw new RuntimeException($state['error']);
        }
        $dryRun = deploymentCommand(deploymentRsyncArguments($environment, true));
        if ($dryRun['exit_code'] !== 0) throw new RuntimeException('rsync dry-run failed.');
        $summary = deploymentChangeSummary($dryRun['stdout']);
        if ($action === 'deployment-preview') {
            sendJson(['ok' => true, 'overview' => deploymentOverview($environment), 'summary' => $summary]); exit;
        }
        $latestDeployment = readDeploymentState($environment);
        if ($latestDeployment && in_array((string)($latestDeployment['status'] ?? ''), ['pending', 'running'], true)) {
            throw new RuntimeException('Another deployment is already pending or running.');
        }
        if ($environment === 'production' && (string)($_POST['confirmation'] ?? '') !== 'DEPLOY') throw new RuntimeException('Type DEPLOY exactly to confirm.');
        $confirmed = json_decode((string)($_POST['summary'] ?? ''), true);
        if (!is_array($confirmed) || $confirmed !== $summary) throw new RuntimeException('Deployment preview changed; review it again.');
        $state = newDeploymentState($environment, 'pending', $summary, 'local-console');
        writeDeploymentState($state); appendDeploymentLog($state, 'Deployment queued.');
        $worker = __DIR__ . '/run-deployment.php';
        exec('nohup ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($environment) . ' ' . escapeshellarg($state['id']) . ' ' . escapeshellarg($activeProjectId) . ' >/dev/null 2>&1 &');
        sendJson(['ok' => true, 'deployment' => $state]); exit;
    } catch (Throwable $exception) {
        http_response_code(400); sendJson(['ok' => false, 'error' => $exception->getMessage()]); exit;
    }
}

if ($action === 'deployment-status') {
    try { $environment = (string)($_GET['environment'] ?? ''); deploymentConfiguration($environment); }
    catch (Throwable $exception) { http_response_code(400); sendJson(['ok' => false, 'error' => $exception->getMessage()]); exit; }
    $state = readDeploymentState($environment, isset($_GET['id']) ? (string)$_GET['id'] : null);
    $log = $state && is_file((string)$state['log_path']) ? (string)file_get_contents((string)$state['log_path']) : '';
    sendJson(['ok' => true, 'deployment' => $state, 'log' => $log]); exit;
}

if ($action === 'preview-deployment-status') {
    $operationId = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : '';
    try {
        if (!previewDeploymentValidateOperationId($operationId)) {
            throw new RuntimeException('Invalid Preview deployment operation ID.');
        }
        sendJson(['ok' => true, 'operation' => previewDeploymentStatus($operationId)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'production-deployment-status') {
    $operationId = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : '';
    try {
        if (!productionDeploymentValidateOperationId($operationId)) {
            throw new RuntimeException('Invalid Production deployment operation ID.');
        }
        sendJson(['ok' => true, 'operation' => productionDeploymentStatus($operationId)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'environment-status') {
    sendJson(['ok' => true, 'dashboard' => operationalDashboard()]);
    exit;
}

if ($action === 'server-tool-operation-status') {
    $operationId = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : '';
    try {
        if (!serverToolsValidateOperationId($operationId)) {
            throw new RuntimeException('Invalid server tool operation ID.');
        }
        sendJson(['ok' => true, 'operation' => serverToolsOperationStatus($operationId)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'deploy_preview_managed') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid Preview deployment request.']);
    } else {
        try {
            if ($activeProject === null) {
                throw new RuntimeException('Select a Project before deploying Preview.');
            }
            $operation = previewDeploymentStart(devConsoleLoadProjectConfiguration(), $activeProjectId);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'deploy_production_managed') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid Production deployment request.']);
    } else {
        try {
            if ($activeProject === null) {
                throw new RuntimeException('Select a Project before deploying Production.');
            }
            if ((string)($_POST['confirm'] ?? '') !== '1') {
                throw new RuntimeException('Production deployment confirmation is required.');
            }
            $operation = productionDeploymentStart(devConsoleLoadProjectConfiguration(), $activeProjectId);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'production_preflight_managed' || $action === 'production_add_preserve_path' || $action === 'production_approve_deletions') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid Production preflight request.']);
    } else {
        try {
            if ($activeProject === null) {
                throw new RuntimeException('Select a Project before checking Production.');
            }
            $configurationForPreflight = devConsoleLoadProjectConfiguration();
            if ($action === 'production_add_preserve_path') {
                productionDeploymentAddPreservePath($configurationForPreflight, $activeProjectId, is_scalar($_POST['path'] ?? null) ? (string)$_POST['path'] : '');
                $configurationForPreflight = devConsoleLoadProjectConfiguration();
            } elseif ($action === 'production_approve_deletions') {
                productionDeploymentApproveDeletions($configurationForPreflight, $activeProjectId);
                $configurationForPreflight = devConsoleLoadProjectConfiguration();
            }
            if ($action !== 'production_approve_deletions') {
                productionDeploymentRunPreflight($configurationForPreflight, $activeProjectId);
                $configurationForPreflight = devConsoleLoadProjectConfiguration();
            }
            $updatedProject = devConsoleFindProjectById($configurationForPreflight, $activeProjectId);
            $updatedDeployment = is_array($updatedProject['production_deployment'] ?? null) ? $updatedProject['production_deployment'] : [];
            $preflight = productionDeploymentPreflightForUi(
                is_array($updatedDeployment['preflight'] ?? null) ? $updatedDeployment['preflight'] : null,
                is_array($updatedDeployment['deletion_approval'] ?? null) ? $updatedDeployment['deletion_approval'] : []
            );
            sendJson(['ok' => true, 'preflight' => $preflight]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'managed-server-operation-status') {
    $operationId = is_scalar($_GET['id'] ?? null) ? (string)$_GET['id'] : '';
    try {
        if (!managedServerOperationValidateId($operationId)) {
            throw new RuntimeException('Invalid managed server operation ID.');
        }
        sendJson(['ok' => true, 'operation' => managedServerOperationStatus($operationId)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'run-codex' && $requestMethod === 'POST') {
    $taskId = (string)($_POST['task'] ?? '');
    $taskSource = (string)($_POST['task_source'] ?? 'project');
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid Codex request.');
        }
        if (!$codexCliReady) {
            throw new RuntimeException('Codex CLI is not installed on this server.');
        }
        if (!$codexAuthReady) {
            throw new RuntimeException('Codex CLI is not authenticated for the Dev Console service user.');
        }
        startCodexRun($taskContexts, $activeProjectId, $runsDir, $taskId, $taskSource);
        $status = codexRunStatus($runsDir, $taskId, $taskSource);
        sendJson(['ok' => true, 'task' => $taskId, 'status' => $status, 'label' => statusLabel($status)]);
    } catch (Throwable $exception) {
        http_response_code(400);
        sendJson(['ok' => false, 'error' => $exception->getMessage()]);
    }
    exit;
}

if ($action === 'recover_codex_lifecycle' && $requestMethod === 'POST') {
    $taskId = (string)($_POST['task'] ?? '');
    $taskSource = (string)($_POST['task_source'] ?? 'project');
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid lifecycle recovery request.');
        }
        if ($activeProject === null || $activeProjectId === '') {
            throw new RuntimeException('Select a Project before recovering task lifecycle.');
        }
        if (!isTaskId($taskId) || findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $taskSource) === null) {
            throw new RuntimeException('Task does not belong to the active Project.');
        }
        recoverCodexLifecycle($activeProject, $repoRoot, $runsDir, $taskId, $taskSource);
        saveCurrentTaskSelection($activeProjectId, $taskId, 'project');
        header('Location: /?tab=dashboard&task=' . rawurlencode($taskId . '.md') . '&task_source=project#codexRunPanel');
        exit;
    } catch (Throwable $exception) {
        $_SESSION['codex_recovery_error'] = $exception->getMessage();
        header('Location: /?tab=dashboard&task=' . rawurlencode($taskId . '.md') . '&task_source=' . rawurlencode($taskSource) . '#codexRunPanel');
        exit;
    }
}

if ($action === 'drop_task' && $requestMethod === 'POST') {
    $taskId = (string)($_POST['task'] ?? '');
    $taskSource = (string)($_POST['task_source'] ?? 'project');
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid task drop request.');
        }
        if ($activeProject === null || $activeProjectId === '') {
            throw new RuntimeException('Select a Project before dropping a task.');
        }
        if ($taskSource !== 'project') {
            throw new RuntimeException('Only Project tasks can be dropped.');
        }
        $task = findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $taskSource);
        if (!isTaskId($taskId) || $task === null) {
            throw new RuntimeException('Task does not belong to the active Project.');
        }
        $taskStatusForDrop = (string)($task['status'] ?? '');
        if (!in_array($taskStatusForDrop, ['TODO', 'IN PROGRESS'], true)) {
            throw new RuntimeException('Only TODO or IN PROGRESS tasks can be dropped.');
        }
        if ($taskStatusForDrop === 'IN PROGRESS' && codexRunStatus($runsDir, $taskId, $taskSource) !== 'failed') {
            throw new RuntimeException('Drop Task is available only after a failed Codex run.');
        }
        ensureRunsDir($runsDir);
        $logPath = workerRunFile($runsDir, $taskId, 'log', $taskSource);
        appendActivity($logPath, 'Drop task requested');
        dropTaskLifecycleTransaction($repoRoot, $taskId, $activeProjectId, $logPath);
        appendActivity($logPath, 'Task dropped');
        clearCurrentTaskSelection($activeProjectId);
        $_SESSION['task_lifecycle_message'] = $taskId . ' moved to DROPPED and synchronized.';
        header('Location: /?tab=dashboard#tasks');
        exit;
    } catch (Throwable $exception) {
        $_SESSION['codex_recovery_error'] = $exception->getMessage();
        header('Location: /?tab=dashboard&task=' . rawurlencode($taskId . '.md') . '&task_source=' . rawurlencode($taskSource) . '#codexRunPanel');
        exit;
    }
}

if ($action === 'refresh_server_diagnostics') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $serverDiagnosticsResult = [
            'success' => false,
            'message' => 'Invalid diagnostics refresh request.',
            'action' => 'refresh_server_diagnostics',
            'output' => '',
            'summary_steps' => [],
        ];
    } else {
        $serverDiagnosticsResult = serverToolsRefreshResult();
    }
    $_SESSION['server_diagnostics_result'] = $serverDiagnosticsResult;
    header('Location: /?tab=settings#dev-console-tools');
    exit;
}

if ($action === 'server_tool_action') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid server tool request.']);
    } else {
        try {
            $toolId = is_scalar($_POST['tool_id'] ?? null) ? (string)$_POST['tool_id'] : '';
            $toolAction = is_scalar($_POST['tool_action'] ?? null) ? (string)$_POST['tool_action'] : '';
            $operation = serverToolsStartOperation($toolId, $toolAction);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'test_managed_server') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid managed server request.']);
    } else {
        try {
            $serverId = managedServersNormalizeId(is_scalar($_POST['server_id'] ?? null) ? (string)$_POST['server_id'] : '');
            $operation = managedServerStartConnectionTest(managedServersLoad(), $serverId);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'install_managed_server_composer') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        sendJson(['ok' => false, 'error' => 'Invalid managed server request.']);
    } else {
        try {
            $serverId = managedServersNormalizeId(is_scalar($_POST['server_id'] ?? null) ? (string)$_POST['server_id'] : '');
            $operation = managedServerStartComposerInstall(managedServersLoad(), $serverId);
            sendJson(['ok' => true, 'operation' => $operation]);
        } catch (Throwable $exception) {
            http_response_code(400);
            sendJson(['ok' => false, 'error' => $exception->getMessage()]);
        }
    }
    exit;
}

if ($action === 'generate_managed_server_key') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['managed_server_result'] = ['success' => false, 'message' => 'Invalid Server SSH Key request.'];
    } else {
        $result = managedServersGenerateSharedKey();
        $_SESSION['managed_server_result'] = [
            'success' => !empty($result['success']),
            'message' => (string)($result['message'] ?? ''),
            'output' => (string)($result['output'] ?? ''),
        ];
    }
    header('Location: /?tab=servers#server-ssh-key');
    exit;
}

if (in_array($action, apacheAllowedActions(), true)) {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $apacheActionResult = [
            'success' => false,
            'message' => 'Invalid Apache management request.',
            'output' => '',
        ];
    } else {
        $apacheActionResult = apacheRunAction($action);
    }
    $_SESSION['apache_action_result'] = $apacheActionResult;
    header('Location: /?tab=settings#apache');
    exit;
}

if ($action === 'select_active_project') {
    $projectId = is_scalar($_POST['project_id'] ?? null) ? (string)$_POST['project_id'] : '';
    $projectForSelection = devConsoleFindProjectById(devConsoleLoadProjectConfiguration(), $projectId);
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_flash'] = 'Invalid project selection request.';
    } elseif (!devConsoleSaveActiveProject($projectId)) {
        $_SESSION['project_flash'] = 'Unable to select Project.';
    } else {
        $_SESSION['project_flash'] = 'Project "' . projectMessageName($projectForSelection, $projectId) . '" selected for Dashboard.';
    }
    $targetCandidate = (string)($_POST['target_tab'] ?? 'projects');
    $targetTab = in_array($targetCandidate, ['dashboard', 'projects', 'settings'], true) ? $targetCandidate : 'projects';
    header('Location: /?tab=' . $targetTab);
    exit;
}

if ($action === 'save_runtime_settings') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $runtimeActionResult = ['success' => false, 'message' => 'Invalid runtime settings request.'];
    } else {
        $validation = runtimeValidateSettingsInput($_POST);
        if (empty($validation['valid'])) {
            $runtimeActionResult = ['success' => false, 'message' => implode(' ', $validation['errors'])];
        } elseif (!runtimeSaveSettings($validation['settings'])) {
            $runtimeActionResult = ['success' => false, 'message' => 'Unable to save Dev Console runtime settings.'];
        } else {
            $runtimeActionResult = [
                'success' => true,
                'message' => 'Runtime settings saved. ' . runtimeApplyInstruction(),
            ];
        }
    }
    $_SESSION['runtime_action_result'] = $runtimeActionResult;
    header('Location: /?tab=settings#runtime');
    exit;
}

if (in_array($action, ['save_github_configuration', 'test_github_connection', 'remove_github_configuration', 'install_github_cli'], true)) {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $githubActionResult = gitActionResult(false, 'Invalid GitHub management request.');
    } elseif ($action === 'save_github_configuration') {
        $githubActionResult = gitGithubSaveConfiguration($_POST);
    } elseif ($action === 'test_github_connection') {
        $githubActionResult = gitGithubTestConnection();
    } elseif ($action === 'remove_github_configuration') {
        $githubActionResult = gitGithubRemoveConfiguration();
    } else {
        $githubActionResult = gitGithubInstallCli();
    }
    $githubActionResult['action'] = $action;
    $_SESSION['github_action_result'] = $githubActionResult;
    header('Location: /?tab=settings#github');
    exit;
}

if ($action === 'save_managed_server') {
    foreach (['server_id', 'server_name', 'server_host', 'server_port', 'server_user'] as $field) {
        $target = match ($field) {
            'server_id' => 'id',
            'server_name' => 'name',
            'server_host' => 'host',
            'server_port' => 'port',
            'server_user' => 'user',
            default => 'description',
        };
        $managedServerFormValues[$target] = devConsoleScalarInput($_POST, $field);
    }
    $existingId = managedServersNormalizeId(is_scalar($_POST['existing_server_id'] ?? null) ? (string)$_POST['existing_server_id'] : '');
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $managedServerFormErrors[] = 'Invalid managed server request.';
    } else {
        $managedServersForAction = managedServersLoad();
        $serverResult = managedServersBuildFromInput($_POST, $managedServersForAction, $existingId);
        $managedServerFormValues = $serverResult['server'];
        if (!empty($serverResult['valid']) && managedServersSave(managedServersUpsert($managedServersForAction, $serverResult['server'], $existingId))) {
            $_SESSION['managed_server_result'] = [
                'success' => true,
                'message' => 'Managed server saved.',
            ];
            header('Location: /?tab=servers#managed-servers');
            exit;
        }
        $managedServerFormErrors = $serverResult['errors'] ?? ['Unable to save managed server.'];
    }
}

if ($action === 'remove_managed_server') {
    $managedServersForAction = managedServersLoad();
    $serverId = managedServersNormalizeId(is_scalar($_POST['server_id'] ?? null) ? (string)$_POST['server_id'] : '');
    $referencingProjects = devConsoleProjectsReferencingManagedServer(devConsoleLoadProjectConfiguration(), $serverId);
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['managed_server_result'] = ['success' => false, 'message' => 'Invalid managed server request.'];
    } elseif (managedServersFind($managedServersForAction, $serverId) === null) {
        $_SESSION['managed_server_result'] = ['success' => false, 'message' => 'Managed server not found.'];
    } elseif (!empty($referencingProjects)) {
        $names = array_map(static fn(array $project): string => projectMessageName($project, (string)($project['id'] ?? '')), $referencingProjects);
        $_SESSION['managed_server_result'] = ['success' => false, 'message' => 'Managed server is assigned to Project(s): ' . implode(', ', $names) . '. Reassign those Projects before removing this server.'];
    } elseif (managedServersSave(managedServersRemove($managedServersForAction, $serverId))) {
        $_SESSION['managed_server_result'] = ['success' => true, 'message' => 'Managed server removed.'];
    } else {
        $_SESSION['managed_server_result'] = ['success' => false, 'message' => 'Unable to remove managed server.'];
    }
    header('Location: /?tab=servers#managed-servers');
    exit;
}

if ($action === 'create_project') {
    foreach ($projectFormValues as $field => $fallback) {
        $value = $_POST[$field] ?? $fallback;
        $projectFormValues[$field] = is_scalar($value) ? trim((string)$value) : '';
    }

    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $projectFormErrors[] = 'Invalid project request.';
    } else {
        $projectResult = devConsoleAppendProject($projectFormValues, managedServersLoad());
        if (!empty($projectResult['valid']) && !empty($projectResult['saved'])) {
            $_SESSION['project_flash'] = 'Project "' . projectMessageName($projectResult['project'] ?? null, $projectFormValues['project_name']) . '" created.';
            header('Location: /?tab=projects#projects');
            exit;
        }

        $projectFormErrors = $projectResult['errors'] ?? ['Unable to create project.'];
    }
}

if ($action === 'discover_existing_project') {
    foreach ($existingProjectDiscoveryValues as $field => $fallback) {
        $value = $_POST[$field] ?? $fallback;
        $existingProjectDiscoveryValues[$field] = is_scalar($value) ? trim((string)$value) : '';
    }

    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_adoption_result'] = [
            'success' => false,
            'status' => 'CANNOT ADOPT',
            'values' => $existingProjectDiscoveryValues,
            'errors' => ['Invalid discovery request.'],
            'warnings' => [],
            'notes' => [],
            'safety' => [projectAdoptionReadOnlyNotice()],
        ];
    } else {
        $_SESSION['project_adoption_result'] = projectAdoptionDiscover(
            $existingProjectDiscoveryValues,
            managedServersLoad(),
            is_array($_SESSION['project_adoption_scan_state'] ?? null) ? $_SESSION['project_adoption_scan_state'] : null
        );
    }
    $_SESSION['project_adoption_source'] = devConsoleScalarInput($_POST, 'discovery_source') === 'manual' ? 'manual' : 'inspect';
    header('Location: /?tab=projects&adoption=1#addExistingProject');
    exit;
}

if ($action === 'scan_existing_projects') {
    foreach ($existingProjectScanValues as $field => $fallback) {
        $value = $_POST[$field] ?? $fallback;
        $existingProjectScanValues[$field] = is_scalar($value) ? trim((string)$value) : '';
    }

    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_adoption_scan_state'] = [
            'success' => false,
            'status' => 'Cannot scan',
            'values' => $existingProjectScanValues,
            'errors' => ['Invalid server scan request.'],
            'warnings' => [],
            'sites' => [],
            'safety' => [projectAdoptionReadOnlyNotice()],
        ];
    } else {
        $_SESSION['project_adoption_scan_state'] = projectAdoptionScanServer(
            $existingProjectScanValues,
            managedServersLoad(),
            devConsoleProjects(devConsoleLoadProjectConfiguration())
        );
    }
    unset($_SESSION['project_adoption_result'], $_SESSION['project_adoption_source'], $_SESSION['project_adoption_action_result']);
    header('Location: /?tab=projects&adoption=1#addExistingProject');
    exit;
}

if ($action === 'adopt_existing_project') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_adoption_action_result'] = [
            'success' => false,
            'status' => 'FAILED',
            'message' => 'Invalid adoption request.',
            'summary' => ['CSRF validation failed.'],
            'output' => '',
        ];
    } else {
        $adoptionActionResult = projectAdoptionAdopt(
            $_POST,
            managedServersLoad(),
            devConsoleLoadGithubConfiguration()
        );
        $_SESSION['project_adoption_action_result'] = $adoptionActionResult;
        if (!empty($adoptionActionResult['success'])) {
            unset($_SESSION['project_adoption_result'], $_SESSION['project_adoption_source'], $_SESSION['project_adoption_scan_state']);
        }
    }
    header('Location: /?tab=projects&adoption=1#addExistingProject');
    exit;
}

if ($action === 'update_project') {
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $_SESSION['project_flash'] = 'Invalid project update request.';
    } else {
        $projectResult = devConsoleUpdateProject($_POST, managedServersLoad());
        if (!empty($projectResult['valid']) && !empty($projectResult['saved'])) {
            $_SESSION['project_flash'] = 'Project "' . projectMessageName($projectResult['project'] ?? null, (string)($_POST['project_id'] ?? '')) . '" updated.';
        } else {
            $_SESSION['project_flash'] = 'Project update failed: ' . implode(' ', $projectResult['errors'] ?? ['Unable to update project.']);
        }
    }
    header('Location: /?tab=projects#projects');
    exit;
}

if (in_array($action, ['provision_project', 'remove_project', 'delete_project', 'verify_project_routing', 'initialize_repository', 'fetch_git_repository', 'pull_git_repository', 'push_git_repository', 'cleanup_orphaned_project'], true)) {
    $projectConfigurationForAction = devConsoleLoadProjectConfiguration();
    $projectId = is_scalar($_POST['project_id'] ?? null) ? (string)$_POST['project_id'] : '';
    $projectForAction = devConsoleFindProjectById($projectConfigurationForAction, $projectId);
    $projectNameForAction = projectMessageName($projectForAction, $projectId);
    if ($requestMethod !== 'POST' || !hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $projectActionResult = projectActionResult(false, 'Invalid project action request.');
    } elseif ($action === 'provision_project') {
        $projectActionResult = projectProvision($projectConfigurationForAction, $projectId);
    } elseif ($action === 'verify_project_routing') {
        $projectActionResult = projectVerifyRoutingAction($projectConfigurationForAction, $projectId, ['require_apache_running' => true]);
    } elseif ($action === 'initialize_repository') {
        $repositoryName = is_scalar($_POST['repository_name'] ?? null) ? trim((string)$_POST['repository_name']) : '';
        $projectActionResult = gitInitializeRepository($projectConfigurationForAction, $projectId, $repositoryName);
    } elseif ($action === 'fetch_git_repository') {
        $projectActionResult = gitFetchRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'pull_git_repository') {
        $projectActionResult = gitPullRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'push_git_repository') {
        $projectActionResult = gitPushRepository($projectConfigurationForAction, $projectId);
    } elseif ($action === 'remove_project') {
        $projectActionResult = projectRemoveFromConsole($projectConfigurationForAction, $projectId);
    } elseif ($action === 'cleanup_orphaned_project') {
        $projectActionResult = projectCleanupOrphanedInfrastructure($projectConfigurationForAction, $projectId);
    } else {
        $confirmation = is_scalar($_POST['confirm_project_name'] ?? null) ? (string)$_POST['confirm_project_name'] : '';
        $githubRepositoryPolicy = (string)($_POST['github_repository_policy'] ?? 'keep');
        if (!in_array($githubRepositoryPolicy, ['keep', 'delete'], true)) {
            $githubRepositoryPolicy = 'keep';
        }
        $projectActionResult = projectDelete($projectConfigurationForAction, $projectId, $confirmation, [
            'github_repository_policy' => $githubRepositoryPolicy,
            'github_configuration' => $githubConfiguration,
        ]);
    }
    $projectActionResult['action'] = $action;
    $projectActionResult['project_id'] = $projectId;
    $projectActionResult['project_name'] = $projectNameForAction;
    if (!empty($projectActionResult['success'])) {
        if ($action === 'provision_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" set up.';
        } elseif ($action === 'verify_project_routing') {
            $projectActionResult['message'] = 'Websites for "' . $projectNameForAction . '" checked.';
        } elseif ($action === 'initialize_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" initialized.';
        } elseif ($action === 'fetch_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" fetched.';
        } elseif ($action === 'pull_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" pulled.';
        } elseif ($action === 'push_git_repository') {
            $projectActionResult['message'] = 'Repository for "' . $projectNameForAction . '" pushed.';
        } elseif ($action === 'remove_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" removed from Dev Console.';
        } elseif ($action === 'delete_project') {
            $projectActionResult['message'] = 'Project "' . $projectNameForAction . '" deleted.';
        } elseif ($action === 'cleanup_orphaned_project') {
            $projectActionResult['message'] = 'Infrastructure for "' . $projectNameForAction . '" cleaned up.';
        }
    } else {
        $prefix = $action === 'cleanup_orphaned_project'
            ? 'Infrastructure for "' . $projectNameForAction . '": '
            : 'Project "' . $projectNameForAction . '": ';
        $projectActionResult['message'] = $prefix . (string)($projectActionResult['message'] ?? 'Action failed.');
    }
    if (empty($projectActionResult['success']) && $projectForAction !== null && !in_array($action, ['remove_project', 'delete_project'], true)) {
        devConsoleSaveProjectConfiguration(devConsoleTouchProject($projectConfigurationForAction, $projectId));
    }
    $_SESSION['project_action_result'] = $projectActionResult;
    header('Location: /?tab=' . ($action === 'cleanup_orphaned_project' ? 'settings#apache' : 'projects#projects'));
    exit;
}

if ($requestMethod === 'POST' && $action === 'save_task') {
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid task save request.');
        }
        $taskId = is_scalar($_POST['task_id'] ?? null) ? (string)$_POST['task_id'] : '';
        $source = is_scalar($_POST['task_source'] ?? null) ? (string)$_POST['task_source'] : 'project';
        $body = trim((string)($_POST['task_body'] ?? ''));
        if ($body === '') {
            throw new RuntimeException('Task markdown body is required.');
        }
        if ($source !== 'project') {
            throw new RuntimeException('Legacy task files cannot be edited here.');
        }
        $task = $activeProjectId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $source);
        if ($task === null) {
            throw new RuntimeException('Task not found.');
        }
        if (!taskCanRemoveAttachments($task, $runsDir)) {
            throw new RuntimeException('Only TODO tasks can be edited before execution.');
        }
        $attachments = taskAttachmentRecordsForTask($taskContexts, $activeProjectId, $taskId, $source);
        $metadata = is_array($task['metadata'] ?? null) ? $task['metadata'] : taskSystemMetadata((string)$task['body']);
        $updatedBody = taskBodyWithProjectMetadata($body, $activeProjectId, $taskId, (string)$task['status'], $attachments, $metadata);
        if (@file_put_contents((string)$task['path'], $updatedBody . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to save task file.');
        }
        $relativeTaskPath = relativePath($repoRoot, (string)$task['path']);
        $results[] = taskGitCommand(['add', $relativeTaskPath], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }
        $results[] = taskGitCommand(['commit', '-m', 'Update ' . $taskId], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git commit failed.');
        }
        $results[] = taskGitAuthenticatedCommand(['push', 'origin', 'main'], $repoRoot, $githubConfiguration);
        if (end($results)['exit_code'] !== 0) {
            $taskSaveMessage = 'Task "' . $taskId . '" saved and committed locally, but GitHub synchronization failed. Use Push in Projects to retry.';
        } else {
            $taskSaveMessage = 'Task "' . $taskId . '" saved and synchronized with GitHub.';
        }
        $viewTask = findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $source);
        saveCurrentTaskSelection($activeProjectId, $taskId, $source);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($requestMethod === 'POST' && $action === 'remove_task_attachment') {
    try {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid attachment removal request.');
        }
        $taskId = is_scalar($_POST['task_id'] ?? null) ? (string)$_POST['task_id'] : '';
        $source = is_scalar($_POST['task_source'] ?? null) ? (string)$_POST['task_source'] : 'project';
        $name = is_scalar($_POST['attachment_name'] ?? null) ? (string)$_POST['attachment_name'] : '';
        if ($source !== 'project') {
            throw new RuntimeException('Legacy task attachments cannot be removed here.');
        }
        $task = $activeProjectId === '' ? null : findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $source);
        $context = taskContextForSource($taskContexts, $source);
        $record = $activeProjectId === '' ? null : taskAttachmentRecordByName($taskContexts, $activeProjectId, $taskId, $source, $name);
        if ($task === null || $context === null || $record === null) {
            throw new RuntimeException('Attachment not found.');
        }
        if (!taskCanRemoveAttachments($task, $runsDir)) {
            throw new RuntimeException('Attachments can be removed only before task execution.');
        }
        $path = taskAttachmentAbsolutePath($context, $record);
        $realRoot = realpath((string)$context['root'] . '/TASKS');
        $realPath = realpath($path);
        if ($realRoot === false || $realPath === false || !str_starts_with($realPath, $realRoot . '/') || !is_file($realPath)) {
            throw new RuntimeException('Attachment not found.');
        }
        if (!@unlink($realPath)) {
            throw new RuntimeException('Unable to remove attachment.');
        }
        $attachments = array_values(array_filter(taskAttachmentRecordsForTask($taskContexts, $activeProjectId, $taskId, $source), static fn(array $attachment): bool => (string)$attachment['name'] !== $name));
        $metadata = is_array($task['metadata'] ?? null) ? $task['metadata'] : taskSystemMetadata((string)$task['body']);
        $updatedBody = taskBodyWithProjectMetadata(taskEditableBody((string)$task['body']), $activeProjectId, $taskId, (string)$task['status'], $attachments, $metadata);
        if (@file_put_contents((string)$task['path'], $updatedBody . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to update task attachment metadata.');
        }
        $relativeTaskPath = relativePath($repoRoot, (string)$task['path']);
        $relativeAttachmentPath = relativePath($repoRoot, $path);
        $results[] = taskGitCommand(['add', $relativeTaskPath], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }
        $results[] = taskGitCommand(['add', '-u', $relativeAttachmentPath], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }
        $results[] = taskGitCommand(['commit', '-m', 'Remove attachment from ' . $taskId], $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git commit failed.');
        }
        $results[] = taskGitAuthenticatedCommand(['push', 'origin', 'main'], $repoRoot, $githubConfiguration);
        if (end($results)['exit_code'] !== 0) {
            $taskAttachmentMessage = 'Attachment removed and committed locally, but GitHub synchronization failed. Use Push in Projects to retry.';
        } else {
            $taskAttachmentMessage = 'Attachment removed and synchronized with GitHub.';
        }
        $viewTask = findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', $source);
        saveCurrentTaskSelection($activeProjectId, $taskId, $source);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

if ($requestMethod === 'POST' && $action === 'create_task') {
    try {
        if ($postLimitExceeded) {
            throw new RuntimeException(runtimePostLimitExceededMessage($runtimeEffectiveLimits));
        }
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid task request: CSRF token is missing or invalid.');
        }
        $body = trim((string)($_POST['task_body'] ?? ''));

        if ($body === '') {
            throw new RuntimeException('Task markdown body is required.');
        }
        if ($activeProject === null) {
            throw new RuntimeException('Select or create a Project before creating tasks.');
        }
        if (!$taskCreationReady) {
            throw new RuntimeException($taskCreationUnavailableReason);
        }

        if (!is_dir($todoDir) && !mkdir($todoDir, 0755, true)) {
            throw new RuntimeException('Unable to create TASKS/TODO.');
        }

        $uploads = uploadedAttachments();
        if (count($uploads) > 5) {
            throw new RuntimeException('A maximum of five attachments can be uploaded.');
        }

        foreach ($uploads as $upload) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(runtimeUploadErrorMessage((int)$upload['error'], (string)$upload['name'], $runtimeEffectiveLimits));
            }
        }

        $number = nextTaskNumber($taskContexts, $activeProjectId);
        $taskId = taskNumber($number);
        $createdTaskId = $taskId;
        $taskPath = $todoDir . '/' . $taskId . '.md';

        if (taskExists($number, $taskContexts, $activeProjectId)) {
            throw new RuntimeException($taskId . ' already exists.');
        }

        $attachmentRecords = [];
        if (!empty($uploads)) {
            $taskAttachmentDir = $attachmentsRoot . '/' . $taskId;
            if (!is_dir($taskAttachmentDir) && !mkdir($taskAttachmentDir, 0755, true)) {
                throw new RuntimeException('Unable to create task attachment directory.');
            }

            foreach ($uploads as $upload) {
                $safeFilename = sanitizeUploadName($upload['name']);
                $uploadPath = uniqueUploadPath($taskAttachmentDir, $safeFilename);

                if (!move_uploaded_file($upload['tmp_name'], $uploadPath)) {
                    throw new RuntimeException('Unable to save uploaded attachment.');
                }

                $attachmentPaths[] = relativePath($repoRoot, $uploadPath);
                $attachmentRecords[] = taskAttachmentRecordFromFile($repoRoot, $uploadPath);
            }
            $createdTaskAttachments = $attachmentRecords;
        }

        $handle = @fopen($taskPath, 'x');
        if (!$handle) {
            throw new RuntimeException('Unable to create task file without overwriting.');
        }
        $taskBody = taskBodyWithProjectMetadata($body, $activeProjectId, $taskId, 'TODO', $attachmentRecords);
        fwrite($handle, $taskBody . "\n");
        fclose($handle);

        $pathsToAdd = [relativePath($repoRoot, $taskPath)];
        $createdTaskPath = $pathsToAdd[0];
        if ($activeProject !== null) {
            $documentationPath = gitEnsureTaskDocumentation($activeProject, $repoRoot);
            if ($documentationPath !== null) {
                $documentationRelativePath = relativePath($repoRoot, $documentationPath);
                if (!in_array($documentationRelativePath, $pathsToAdd, true)) {
                    $pathsToAdd[] = $documentationRelativePath;
                }
            }
        }

        if (!empty($uploads)) {
            $pathsToAdd[] = relativePath($repoRoot, $taskAttachmentDir);
        }

        $taskCommitted = false;
        $results[] = taskGitCommand(array_merge(['add'], $pathsToAdd), $repoRoot);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git add failed.');
        }

        $results[] = taskGitCommand(['commit', '-m', 'Add ' . $taskId], $repoRoot);
        $commitHash = extractCommitHash(end($results)['output']);
        if (end($results)['exit_code'] !== 0) {
            throw new RuntimeException('git commit failed.');
        }
        $taskCommitted = true;

        $results[] = taskGitAuthenticatedCommand(['push', 'origin', 'main'], $repoRoot, $githubConfiguration);
        if (end($results)['exit_code'] !== 0) {
            $refreshedConfiguration = devConsoleLoadProjectConfiguration();
            $refreshedProject = devConsoleFindProjectById($refreshedConfiguration, $activeProjectId);
            if ($refreshedProject !== null) {
                gitSaveProject($refreshedConfiguration, gitSetMetadata($refreshedProject, [
                    'bootstrap_status' => 'ready',
                    'connected' => true,
                    'remote_verified' => true,
                    'last_error_at' => date('c'),
                ]));
            }
            $taskPushWarning = 'Task "' . $taskId . '" created and committed locally for Project "' . projectMessageName($activeProject, $activeProjectId) . '", but synchronization with GitHub failed. Use Push in Projects to retry.';
        } else {
            $results[] = taskGitAuthenticatedCommand(['fetch', '--prune', 'origin'], $repoRoot, $githubConfiguration);
            if (end($results)['exit_code'] === 0) {
                $refreshedConfiguration = devConsoleLoadProjectConfiguration();
                $refreshedProject = devConsoleFindProjectById($refreshedConfiguration, $activeProjectId);
                $verified = gitReadVerifiedRepositoryMetadata($repoRoot);
                if ($refreshedProject !== null && $verified !== null) {
                    gitSaveProject($refreshedConfiguration, gitSetMetadata($refreshedProject, $verified + [
                        'bootstrap_status' => 'ready',
                        'connected' => true,
                        'remote_verified' => true,
                        'last_error_at' => null,
                        'last_fetch_at' => date('c'),
                    ]));
                }
            }
        }

        saveCurrentTaskSelection($activeProjectId, $taskId, 'project');
        $taskContexts = taskStorageContexts(devConsoleLoadProjectConfiguration(), $activeProject);
        $viewTask = findTaskForView($taskContexts, $activeProjectId, $taskId . '.md', 'project');
        $prompt = codexPromptForTask($taskContexts, $activeProjectId, $taskId, 'project');

        $nextNumber = nextTaskNumber($taskContexts, $activeProjectId);
        $latestTasks = taskFileEntries($taskContexts, $activeProjectId);
        $legacyTasksDetected = legacyTasksDetected($latestTasks);
        $taskGroups = groupedTaskEntries($latestTasks, $runsDir);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        if (empty($taskCommitted ?? false) && isset($taskPath) && is_file($taskPath)) {
            @unlink($taskPath);
        }
        if (empty($taskCommitted ?? false) && isset($taskAttachmentDir) && is_dir($taskAttachmentDir)) {
            foreach (array_diff(scandir($taskAttachmentDir) ?: [], ['.', '..']) as $entry) {
                @unlink($taskAttachmentDir . '/' . $entry);
            }
            @rmdir($taskAttachmentDir);
        }
        if (empty($taskCommitted ?? false)) {
            $createdTaskId = '';
            $createdTaskPath = '';
            $attachmentPaths = [];
            $commitHash = '';
        }
    }
}

$activeTaskId = $createdTaskId;
if ($activeTaskId === '' && $viewTask) {
    $activeTaskId = pathinfo($viewTask['filename'], PATHINFO_FILENAME);
}
$activeTaskSource = $createdTaskId !== '' ? 'project' : ($viewTask ? (string)$viewTask['source'] : '');
$activeTaskStatus = '';
$activeTaskPath = '';
if ($createdTaskId !== '' && $error === '') {
    $activeTaskStatus = 'TODO';
    $activeTaskPath = $createdTaskPath;
} elseif ($viewTask) {
    $activeTaskStatus = $viewTask['status'];
    $activeTaskPath = $viewTask['relative_path'];
}
$activeRunStatus = $activeTaskId === '' ? 'not_started' : codexRunStatus($runsDir, $activeTaskId, $activeTaskSource === '' ? 'project' : $activeTaskSource);
$activeCodexResult = $activeTaskId === '' ? [] : codexRunResult($runsDir, $activeTaskId, $activeTaskSource === '' ? 'project' : $activeTaskSource);
$codexLifecycleRecovery = $activeTaskId === '' || $activeProject === null || $activeRunStatus !== 'failed' || $activeTaskSource !== 'project'
    ? ['recoverable' => false, 'reason' => 'No recoverable failed Project task run is selected.']
    : codexLifecycleRecoveryState($activeProject, $repoRoot, $runsDir, $activeTaskId, $activeTaskSource === '' ? 'project' : $activeTaskSource);
$codexRecoveryError = is_scalar($_SESSION['codex_recovery_error'] ?? null) ? (string)$_SESSION['codex_recovery_error'] : '';
unset($_SESSION['codex_recovery_error']);
$projectCodexRunActive = codexProjectHasActiveRun($runsDir);
$activeTaskRunnable = ($activeTaskStatus === 'TODO' && !in_array($activeRunStatus, ['queued', 'running', 'completed'], true))
    || ($activeTaskStatus === 'IN PROGRESS' && $activeRunStatus === 'failed');
$activeTaskDroppable = $activeTaskSource === 'project'
    && ($activeTaskStatus === 'TODO' || ($activeTaskStatus === 'IN PROGRESS' && $activeRunStatus === 'failed'));
$codexRetryWithPreservedChanges = $activeTaskStatus === 'IN PROGRESS'
    && $activeRunStatus === 'failed'
    && str_contains($taskCreationUnavailableReason, 'Repository synchronization is pending');
$codexRepositoryReady = $taskCreationReady || $codexRetryWithPreservedChanges;
$taskGitCompleted = $activeTaskId !== '';
$taskGitPushed = $activeTaskId !== '' && $taskPushWarning === '';
$editorTaskId = $viewTask ? pathinfo($viewTask['filename'], PATHINFO_FILENAME) : '';
$editorBody = $viewTask ? taskEditableBody((string)$viewTask['body']) : '';
$editorHeading = $editorTaskId === '' ? 'Create New Task' : 'View Task: ' . $editorTaskId;
$editorAttachments = $viewTask ? taskAttachmentRecordsForTask($taskContexts, $activeProjectId, $editorTaskId, (string)$viewTask['source']) : $createdTaskAttachments;
$editorCanSave = $viewTask !== null && taskCanRemoveAttachments($viewTask, $runsDir) && (string)$viewTask['source'] === 'project';
$editorMetadata = $viewTask ? taskSystemMetadata((string)$viewTask['body']) : [];
if ($viewTask) {
    $editorMetadata = array_merge($editorMetadata, [
        'task_id' => $editorTaskId,
        'project_id' => $activeProjectId,
        'title' => (string)($editorMetadata['title'] ?? taskTitleFromBody($editorBody)),
        'status' => (string)$viewTask['status'],
        'attachments' => $editorAttachments,
    ]);
}
$taskMetadataPreview = $activeProjectId === ''
    ? ''
    : ($viewTask
        ? taskMetadataBlockFromArray($editorMetadata)
        : taskMetadataBlock($activeProjectId, taskNumber($nextNumber), '', 'TODO'));
$taskDefaultTemplate = taskDefaultTemplate(taskNumber($nextNumber));
$previewDeploymentOverview = deploymentOverview('preview');
$productionDeploymentOverview = deploymentOverview('production');
$projectConfiguration = devConsoleLoadProjectConfiguration();
$projects = devConsoleProjects($projectConfiguration);
$apacheSites = devConsoleApacheSites();
$orphanedApacheInfrastructure = projectOrphanedApacheInfrastructure($projectConfiguration, $apacheSites);
$projectFlash = (string)($_SESSION['project_flash'] ?? '');
unset($_SESSION['project_flash']);
$projectActionResult = is_array($_SESSION['project_action_result'] ?? null) ? $_SESSION['project_action_result'] : $projectActionResult;
unset($_SESSION['project_action_result']);
$showExistingProjectWorkflow = (string)($_GET['adoption'] ?? '') === '1';
$existingProjectDiscoveryResult = $showExistingProjectWorkflow && is_array($_SESSION['project_adoption_result'] ?? null) ? $_SESSION['project_adoption_result'] : $existingProjectDiscoveryResult;
$existingProjectDiscoverySource = $showExistingProjectWorkflow && is_scalar($_SESSION['project_adoption_source'] ?? null) ? (string)$_SESSION['project_adoption_source'] : $existingProjectDiscoverySource;
$existingProjectScanResult = $showExistingProjectWorkflow && is_array($_SESSION['project_adoption_scan_state'] ?? null) ? $_SESSION['project_adoption_scan_state'] : $existingProjectScanResult;
$existingProjectAdoptionResult = $showExistingProjectWorkflow && is_array($_SESSION['project_adoption_action_result'] ?? null) ? $_SESSION['project_adoption_action_result'] : $existingProjectAdoptionResult;
unset($_SESSION['project_adoption_action_result']);
if (is_array($existingProjectScanResult['values'] ?? null)) {
    foreach ($existingProjectScanValues as $field => $fallback) {
        $value = $existingProjectScanResult['values'][$field] ?? $fallback;
        $existingProjectScanValues[$field] = is_scalar($value) ? (string)$value : '';
    }
}
if (is_array($existingProjectDiscoveryResult['values'] ?? null)) {
    foreach ($existingProjectDiscoveryValues as $field => $fallback) {
        $value = $existingProjectDiscoveryResult['values'][$field] ?? $fallback;
        $existingProjectDiscoveryValues[$field] = is_scalar($value) ? (string)$value : '';
    }
}
$apacheActionResult = is_array($_SESSION['apache_action_result'] ?? null) ? $_SESSION['apache_action_result'] : $apacheActionResult;
unset($_SESSION['apache_action_result']);
$githubActionResult = is_array($_SESSION['github_action_result'] ?? null) ? $_SESSION['github_action_result'] : $githubActionResult;
unset($_SESSION['github_action_result']);
$runtimeActionResult = is_array($_SESSION['runtime_action_result'] ?? null) ? $_SESSION['runtime_action_result'] : $runtimeActionResult;
unset($_SESSION['runtime_action_result']);
$taskLifecycleMessage = is_scalar($_SESSION['task_lifecycle_message'] ?? null) ? (string)$_SESSION['task_lifecycle_message'] : $taskLifecycleMessage;
unset($_SESSION['task_lifecycle_message']);
$managedServerActionResult = is_array($_SESSION['managed_server_result'] ?? null) ? $_SESSION['managed_server_result'] : $managedServerActionResult;
unset($_SESSION['managed_server_result']);
$serverDiagnosticsResult = is_array($_SESSION['server_diagnostics_result'] ?? null) ? $_SESSION['server_diagnostics_result'] : $serverDiagnosticsResult;
unset($_SESSION['server_diagnostics_result']);
$serverToolOperationId = is_scalar($_GET['server_tool_operation'] ?? null) ? (string)$_GET['server_tool_operation'] : '';
if (serverToolsValidateOperationId($serverToolOperationId)) {
    try {
        $serverToolOperation = serverToolsOperationStatus($serverToolOperationId);
        if (in_array((string)($serverToolOperation['status'] ?? ''), ['completed', 'failed'], true) && is_array($serverToolOperation['result'] ?? null)) {
            $serverDiagnosticsResult = $serverToolOperation['result'];
            $serverDiagnosticsResult['output'] = (string)($serverToolOperation['log'] ?? '');
            if (is_array($serverToolOperation['diagnostics'] ?? null)) {
                $serverDiagnosticsResult['diagnostics'] = $serverToolOperation['diagnostics'];
            }
        }
    } catch (Throwable) {
        // Ignore stale or invalid operation state during page rendering.
    }
}
$requestedTabForDiagnostics = (string)($_GET['tab'] ?? '');
$serverDiagnostics = is_array($serverDiagnosticsResult['diagnostics'] ?? null)
    ? $serverDiagnosticsResult['diagnostics']
    : ($requestedTabForDiagnostics === 'settings' ? serverToolsDiagnostics(false) : serverToolsStoredDiagnosticsPlaceholder());
$serverContext = is_array($serverDiagnostics['context'] ?? null) ? $serverDiagnostics['context'] : [];
$serverTools = is_array($serverDiagnostics['tools'] ?? null) ? $serverDiagnostics['tools'] : [];
$managedServers = managedServersLoad();
$managedServerSharedKey = managedServersSharedKeyInfo();
$activeManagedServer = $activeProject === null ? null : devConsoleFindManagedServerById($managedServers, (string)($activeProject['managed_server_id'] ?? ''));
$serverManagementSelectedId = managedServersNormalizeId(is_scalar($_GET['managed_server_id'] ?? null) ? (string)$_GET['managed_server_id'] : '');
if ($serverManagementSelectedId === '' && $activeManagedServer !== null) {
    $serverManagementSelectedId = (string)($activeManagedServer['id'] ?? '');
}
if ($serverManagementSelectedId === '' && !empty($managedServers)) {
    $serverManagementSelectedId = (string)($managedServers[0]['id'] ?? '');
}
$serverManagementSelectedServer = $serverManagementSelectedId === '' ? null : managedServersFind($managedServers, $serverManagementSelectedId);
if ($serverManagementSelectedServer === null && !empty($managedServers)) {
    $serverManagementSelectedServer = $managedServers[0];
    $serverManagementSelectedId = (string)($serverManagementSelectedServer['id'] ?? '');
}
$managedPreviewDeploymentOverview = $activeProject === null ? null : previewDeploymentOverview($activeProject, $activeManagedServer);
$managedPreviewDeploymentReadiness = previewDeploymentReadiness($activeProject, $managedServers);
$managedProductionDeploymentOverview = $activeProject === null ? null : productionDeploymentOverview($activeProject, $activeManagedServer);
$managedProductionDeploymentReadiness = productionDeploymentReadiness($activeProject, $managedServers, false);
$projectStatuses = [];
$gitStatuses = [];
foreach ($projects as $project) {
    try {
        $projectStatuses[(string)($project['id'] ?? '')] = projectStatus($project);
    } catch (Throwable) {
        $projectStatuses[(string)($project['id'] ?? '')] = [
            'label' => 'Configuration drift',
            'production' => [],
            'preview' => [],
        ];
    }
    $gitStatuses[(string)($project['id'] ?? '')] = gitStatus($project, $githubConfiguration);
}
$activeGitStatus = $activeProjectId === '' ? [] : ($gitStatuses[$activeProjectId] ?? []);
$dashboardTaskGroups = [
    'TODO' => $taskGroups['TODO'] ?? [],
    'History' => array_merge($taskGroups['DONE'] ?? [], $taskGroups['DROPPED'] ?? []),
];
usort($dashboardTaskGroups['History'], static function (array $left, array $right): int {
    return ((int)($right['number'] ?? 0)) <=> ((int)($left['number'] ?? 0));
});
$workflowStages = workflowSummary([
    'task_id' => $activeTaskId,
    'task_status' => $activeTaskStatus,
    'task_commit' => $viewTask['commit'] ?? '',
    'codex_run_status' => $activeRunStatus,
    'codex_result' => $activeCodexResult,
    'git_commit' => (string)($activeGitStatus['local_commit'] ?? ''),
    'preview' => is_array($managedPreviewDeploymentOverview) ? $managedPreviewDeploymentOverview : [],
    'production' => is_array($managedProductionDeploymentOverview) ? $managedProductionDeploymentOverview : [],
]);
$projectsForDisplay = devConsoleProjectsForDisplay($projectConfiguration);
$documentationSlug = documentationCurrentSlug($_GET);
$documentationUserSections = documentationUserSections();
$documentationTechnicalSections = documentationTechnicalSections();
$documentationHtml = documentationRenderMarkdown(documentationMarkdownForSlug($documentationSlug));
$documentationTitle = documentationTitleForSlug($documentationSlug);
$requestedTab = (string)($_GET['tab'] ?? '');
if ($requestPath === '/' && in_array($requestedTab, ['dashboard', 'projects', 'servers', 'server-management', 'documentation', 'settings'], true)) {
    $initialTab = $requestedTab;
} else {
    $initialTab = 'dashboard';
    if ($requestPath === '/' && (!empty($projectFormErrors) || $projectFlash !== '' || ($projectActionResult !== null && (string)($projectActionResult['action'] ?? '') !== 'cleanup_orphaned_project'))) {
        $initialTab = 'projects';
    } elseif ($requestPath === '/' && (!empty($managedServerFormErrors) || $managedServerActionResult !== null)) {
        $initialTab = 'servers';
    } elseif ($requestPath === '/' && ($apacheActionResult !== null || $githubActionResult !== null || ($projectActionResult !== null && (string)($projectActionResult['action'] ?? '') === 'cleanup_orphaned_project'))) {
        $initialTab = 'settings';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IOVON Dev Console</title>
  <style>
    :root { --blue: #005385; --green: #147544; --light: #e3f5f9; --line: #d8eef5; --ink: #1d2a32; --muted: #536b78; }
    body { background: #f4f8fb; color: var(--ink); font-family: Arial, sans-serif; margin: 0; }
    main { margin: 24px auto; max-width: 1280px; padding: 0 20px; }
    h1 { color: var(--blue); margin: 0 0 8px; }
    h2 { color: var(--blue); margin: 0 0 12px; }
    code { background: #edf7fb; border-radius: 4px; padding: 2px 5px; }
    .dashboard-columns { align-items: start; display: grid; gap: 18px; grid-template-columns: minmax(0, 1fr) 440px; }
    .dashboard-column { min-width: 0; }
    .dashboard-workspace { display: grid; gap: 16px; }
    .dashboard-task-grid { align-items: stretch; display: grid; gap: 16px; grid-template-columns: minmax(0, 2fr) minmax(320px, .95fr); }
    .dashboard-execution-grid, .dashboard-deployment-grid { align-items: stretch; display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-execution-grid > .panel { box-sizing: border-box; display: flex; flex-direction: column; width: 100%; }
    .dashboard-task-grid > .dashboard-column { display: flex; }
    .dashboard-task-grid > .dashboard-column > .panel, .dashboard-deployment-grid > .panel { box-sizing: border-box; display: flex; flex-direction: column; width: 100%; }
    .project-selector { align-items: start; display: grid; gap: 10px 14px; grid-template-columns: minmax(220px, 300px) minmax(0, 1fr); padding: 12px 14px; }
    .project-selector form { margin: 0; }
    .project-selector label { font-size: 12px; margin: 0 0 5px; }
    .project-identity { display: grid; gap: 6px; }
    .project-summary-line, .project-paths-inline { display: flex; flex-wrap: wrap; gap: 5px 14px; }
    .project-identity span { color: var(--muted); font-size: 12px; }
    .project-identity strong { color: var(--ink); }
    .project-paths-inline { border-top: 1px solid var(--line); padding-top: 6px; }
    .project-paths-inline span { font-size: 11px; }
    .project-paths-inline b { color: var(--muted); font-weight: 700; text-transform: uppercase; }
    .project-paths-inline code { padding: 1px 3px; }
    .panel, .result-block { background: #fff; border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 6px 18px rgba(0, 83, 133, 0.07); margin-top: 14px; padding: 18px; }
    .result-block h2, .command-output h3 { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 15px; }
    .result-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
    .result-actions button { font-size: 12px; margin-top: 0; padding: 7px 10px; }
    .operation-summary { background: #f7fcfe; border: 1px solid var(--line); border-radius: 7px; display: grid; gap: 6px; list-style: none; margin: 12px 0; padding: 10px; }
    .operation-summary li { color: var(--green); font-size: 13px; font-weight: 700; }
    .tool-operation-panel { border-left: 4px solid var(--blue); }
    .tool-operation-panel.failed { border-left-color: #8a1f1f; }
    .tool-operation-grid { display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 12px 0; }
    .tool-operation-grid div { background: #f7fcfe; border: 1px solid var(--line); border-radius: 7px; padding: 9px; }
    .tool-operation-grid dt { color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .tool-operation-grid dd { font-size: 13px; font-weight: 700; margin: 4px 0 0; overflow-wrap: anywhere; }
    .tool-operation-log { max-height: 360px; min-height: 120px; }
    label { display: block; font-weight: 700; margin: 18px 0 8px; }
    textarea { background: #fcfeff; border: 1px solid #bddfeb; border-radius: 8px; box-sizing: border-box; color: #10242f; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 14px; line-height: 1.5; min-height: 390px; padding: 14px; resize: vertical; tab-size: 2; width: 100%; }
    #task_body { font-size: 12.5px; }
    textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    .metadata-preview { background: #f7fcfe; border: 1px solid var(--line); color: var(--muted); font-size: 10.5px; margin: 8px 0 14px; min-height: 78px; padding: 10px 12px; resize: none; }
    input[type="text"], input[type="password"], select { background: #fcfeff; border: 1px solid #bddfeb; border-radius: 6px; box-sizing: border-box; color: #10242f; font-size: 14px; padding: 9px 10px; width: 100%; }
    input[type="text"]:focus, input[type="password"]:focus, select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    button, .button-link { align-items: center; background: var(--blue); border: 0; border-radius: 5px; color: #fff; cursor: pointer; display: inline-flex; font-size: 15px; font-weight: 700; gap: 8px; margin-top: 16px; padding: 11px 18px; text-decoration: none; }
    button:disabled { cursor: not-allowed; opacity: 0.55; }
    [hidden] { display: none !important; }
    button.secondary, .button-link.secondary { background: #e8f4f8; color: var(--blue); }
    pre { background: #f2f7fa; border-radius: 6px; overflow: auto; padding: 16px; white-space: pre-wrap; }
    .readonly-code-field { background: #f2f7fa; border: 1px solid var(--line); border-radius: 6px; box-sizing: border-box; color: #10242f; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; line-height: 1.45; min-height: 0; overflow-wrap: anywhere; padding: 10px 12px; resize: vertical; tab-size: 2; white-space: pre-wrap; width: 100%; }
    .readonly-code-field:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    .error { background: #fff4f4; border-color: #f0b8b8; color: #8a1f1f; }
    .meta, .hint { color: var(--muted); }
    .form-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; }
    .upload-zone { background: #f7fcfe; border: 2px dashed #9fcfe0; border-radius: 10px; cursor: pointer; padding: 16px; text-align: center; transition: border-color 0.2s ease, background 0.2s ease; }
    .upload-zone.dragover { background: var(--light); border-color: var(--blue); }
    .upload-zone strong { color: var(--blue); display: block; font-size: 17px; margin-bottom: 6px; }
    .upload-zone input { height: 1px; opacity: 0; position: absolute; width: 1px; }
    .selected-files { color: var(--muted); margin: 10px 0 0; }
    .selected-files ul { display: grid; gap: 6px; list-style: none; margin: 8px auto 0; max-width: 460px; padding: 0; text-align: left; }
    .selected-files li { align-items: center; background: #edf7fb; border-radius: 5px; display: flex; gap: 8px; justify-content: space-between; padding: 6px 8px; }
    .selected-files button { background: transparent; color: #8a1f1f; font-size: 12px; margin: 0; padding: 3px 6px; }
    .attachment-list { color: var(--muted); margin: 12px 0 0; }
    .attachment-list strong { color: var(--blue); display: block; margin-bottom: 6px; }
    .attachment-list ul { list-style: none; margin: 0; padding: 0; }
    .attachment-list li { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
    .inline-form { display: inline; margin: 0; }
    .link-button { background: none; border: 0; color: var(--blue); cursor: pointer; font: inherit; padding: 0; text-decoration: underline; }
    .dashboard-task-list .panel { min-height: 0; }
    .task-list-scroll { flex: 1 1 0; min-height: 0; overflow-y: auto; padding-right: 6px; }
    .task-group { border-top: 1px solid var(--line); margin-top: 9px; padding-top: 9px; }
    .task-group:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
    .task-group h3 { color: var(--blue); font-size: 12px; letter-spacing: 0; margin: 0 0 4px; }
    .task-group-empty { color: var(--muted); font-size: 11px; margin: 0; }
    .task-list { list-style: none; margin: 0; padding: 0; }
    .task-list > li { border-top: 1px solid var(--line); padding: 6px 0; }
    .task-list li:first-child { border-top: 0; }
    .task-row-header { align-items: center; display: flex; gap: 8px; justify-content: space-between; }
    .task-row-actions { display: flex; justify-content: flex-end; }
    .task-summary-label { color: var(--blue); font-weight: 700; }
    .task-title { color: var(--ink); display: block; font-size: 11.5px; margin-top: 2px; }
    .task-metadata { color: var(--muted); display: flex; flex-wrap: wrap; font-size: 11px; gap: 3px 10px; margin-top: 3px; }
    .task-list .button-link { font-size: 11px; margin-top: 4px; padding: 5px 8px; }
    .milestone { color: #7a5b00; }
    .badge { background: #e3f5f9; border-radius: 999px; color: var(--blue); display: inline-block; flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 4px 8px; }
    .badge.done { background: #e9f7ef; color: var(--green); }
    .badge.dropped { background: #edf0f2; color: #56636a; }
    .workflow-steps { display: grid; gap: 10px; list-style: none; margin: 16px 0 0; padding: 0; }
    .workflow-steps li { align-items: center; border-top: 1px solid var(--line); display: flex; gap: 10px; padding-top: 10px; }
    .workflow-steps li:first-child { border-top: 0; padding-top: 0; }
    .step-state { background: #edf4f7; border-radius: 999px; color: var(--muted); flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 4px 8px; text-transform: uppercase; }
    .step-state.done { background: #e9f7ef; color: var(--green); }
    .step-state.pending { background: #f4f1e8; color: #76622d; }
    .workflow-stage-grid { align-items: stretch; display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .current-workflow-stages { margin-top: auto; padding-top: 14px; }
    .workflow-stage { background: #f8fbfc; border: 1px solid var(--line); border-radius: 7px; min-width: 0; padding: 10px 10px 9px; position: relative; }
    .workflow-stage:not(:last-child)::after { color: var(--muted); content: "→"; font-weight: 700; position: absolute; right: -10px; top: 50%; transform: translateY(-50%); }
    .workflow-stage h3 { color: var(--muted); font-size: 10px; letter-spacing: 0; margin: 0 0 5px; text-transform: uppercase; }
    .workflow-stage strong { color: var(--ink); display: block; font-size: 13px; overflow-wrap: anywhere; }
    .workflow-stage .meta { display: block; font-size: 11px; margin-top: 4px; overflow-wrap: anywhere; }
    .workflow-stage .status-pill { margin-bottom: 5px; }
    .git-output summary { color: var(--blue); cursor: pointer; font-weight: 700; }
    .command-output { border-top: 1px solid var(--line); margin-top: 16px; padding-top: 16px; }
    .command-output:first-of-type { border-top: 0; }
    .prompt-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 12px; }
    .codex-run-panel { background: #f7fcfe; border: 1px solid var(--line); border-radius: 8px; padding: 18px; }
    .codex-run-panel strong { color: var(--blue); }
    .codex-status { font-weight: 700; }
    .codex-console { background: #101820; border-radius: 6px; color: #dce7ec; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; line-height: 1.5; max-height: 360px; min-height: 180px; overflow: auto; padding: 14px; white-space: pre-wrap; }
    .page-header { align-items: flex-start; display: flex; gap: 18px; justify-content: space-between; margin-bottom: 20px; }
    .page-header h1 { margin-bottom: 8px; }
    .brand-heading { align-items: center; display: flex; gap: 12px; }
    .brand-heading h1 { margin: 0; }
    .brand-logo { display: block; flex: 0 0 auto; height: 51px; object-fit: contain; width: auto; }
    .brand-separator { background: #9fbfcb; display: block; flex: 0 0 auto; height: 32px; width: 1px; }
    .page-context { color: var(--muted); min-width: 220px; text-align: right; }
    .page-context strong { color: var(--ink); display: block; font-size: 16px; }
    .page-context span { font-size: 13px; }
    .tab-nav { align-items: flex-end; border-bottom: 1px solid #9fbfcb; display: flex; flex-wrap: wrap; gap: 2px; margin-top: 18px; max-width: 100%; padding: 0 2px; }
    .tab-button { background: #edf4f7; border: 1px solid #9fbfcb; border-radius: 5px 5px 0 0; color: var(--blue); flex: 0 0 auto; font-size: 14px; margin: 0 0 -1px; padding: 8px 14px 10px; white-space: nowrap; }
    .tab-button:hover { background: #f5fbfd; }
    .tab-button.active { background: #fff; border-color: #5f8ea3; color: var(--ink); font-weight: 800; }
    .deployment-panel.production { border: 2px solid #8a1f1f; }
    .deployment-details { display: grid; gap: 10px 24px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 16px 0; }
    .deployment-primary { display: grid; gap: 8px 18px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 12px 0; }
    .deployment-primary div { min-width: 0; }
    .deployment-primary dt { color: var(--muted); font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .deployment-primary dd { font-size: 13px; margin: 3px 0 0; overflow-wrap: anywhere; }
    .deployment-details dt { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .deployment-details dd { margin: 3px 0 0; overflow-wrap: anywhere; }
    .deployment-status { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 5px 10px; text-transform: uppercase; }
    .deployment-status.pending { background: #edf0f2; color: #56636a; }
    .deployment-status.running { background: #fff2b8; color: #705900; }
    .deployment-status.success { background: #e4f6ea; color: #147544; }
    .deployment-status.failed { background: #fde8e8; color: #8a1f1f; }
    .deployment-panel .compact-details { margin-top: 10px; }
    .deployment-panel > button { align-self: flex-start; margin-top: auto; }
    .preflight-summary { background: #f9fbfc; border: 1px solid var(--line); border-radius: 7px; display: grid; gap: 8px; margin: 12px 0; padding: 10px; }
    .preflight-summary.review-required { background: #fff9e8; border-color: #e7c76a; }
    .preflight-counts { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
    .preflight-counts span { background: #eef4f7; border-radius: 999px; color: var(--muted); font-size: 12px; font-weight: 700; padding: 4px 8px; }
    .preflight-paths { display: grid; gap: 5px; margin: 0; padding: 0; }
    .preflight-paths li { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; list-style: none; }
    .preflight-paths code { overflow-wrap: anywhere; }
    .deploy-production { background: #a51d1d; border-color: #a51d1d; font-size: 16px; }
    .deploy-production:hover { background: #801515; }
    dialog { border: 0; border-radius: 10px; box-shadow: 0 20px 70px #0006; max-width: 720px; padding: 0; width: calc(100% - 32px); }
    dialog::backdrop { background: #101820aa; }
    .modal-content { padding: 24px; }
    .modal-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; margin-top: 20px; }
    .change-list { background: #f3f6f7; max-height: 220px; overflow: auto; padding: 12px 12px 12px 30px; }
    .deployment-error { color: #8a1f1f; font-weight: 700; }
    .environment-block { padding: 12px 14px; }
    .environment-block a { color: var(--blue); }
    .dashboard-header { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; }
    .dashboard-header button { margin-top: 0; }
    .dashboard-header h2 { font-size: 17px; margin-bottom: 0; }
    .dashboard-header .meta { font-size: 11px; white-space: nowrap; }
    .dashboard-grid { display: grid; gap: 8px; margin-top: 8px; }
    .summary-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .environment-host-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .environment-status-card { grid-column: 1 / -1; }
    .repository-card { grid-column: 1 / -1; }
    .dashboard-card { background: #f8fbfc; border: 1px solid var(--line); border-radius: 7px; min-width: 0; padding: 8px 9px; }
    .dashboard-card h3 { color: var(--blue); font-size: 12px; margin: 0 0 5px; }
    .dashboard-list { display: grid; gap: 3px; margin: 0; }
    .dashboard-list div { display: grid; gap: 5px; grid-template-columns: minmax(62px, .62fr) minmax(0, 1.38fr); }
    .dashboard-list dt { color: var(--muted); font-size: 10px; font-weight: 700; text-transform: uppercase; }
    .dashboard-list dd { font-size: 11px; margin: 0; overflow-wrap: anywhere; }
    .dashboard-list code { padding: 1px 3px; }
    .status-pill { align-items: center; background: #edf0f2; border-radius: 999px; color: #56636a; display: inline-flex; font-size: 11px; font-weight: 700; justify-content: center; line-height: 1; min-height: 22px; padding: 4px 8px; text-transform: uppercase; vertical-align: middle; }
    .status-pill.pending { background: #edf0f2; color: #56636a; }
    .status-pill.running { background: #fff2b8; color: #705900; }
    .status-pill.healthy { background: #e4f6ea; color: var(--green); }
    .status-pill.warning { background: #fff2b8; color: #705900; }
    .status-pill.error { background: #fde8e8; color: #8a1f1f; }
    .status-pill.quiet { background: #edf0f2; color: #56636a; }
    .health-row { display: flex; flex-wrap: wrap; gap: 5px 10px; }
    .health-item { align-items: center; display: inline-flex; font-size: 10.5px; font-weight: 700; gap: 5px; }
    .health-dot { background: #8a969c; border-radius: 50%; height: 8px; width: 8px; }
    .health-item.healthy .health-dot { background: #1b9a59; }
    .health-item.warning .health-dot { background: #d59b00; }
    .health-item.error .health-dot { background: #c83232; }
    .health-item.quiet { color: var(--muted); opacity: .78; }
    .health-item.quiet .health-dot { background: #9aa7ad; }
    .resource-grid { display: grid; gap: 7px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 7px; }
    .resource-head { display: flex; font-size: 10px; font-weight: 700; justify-content: space-between; margin-bottom: 3px; }
    .progress-track { background: #dce8ed; border-radius: 999px; height: 6px; overflow: hidden; }
    .progress-bar { background: #238654; border-radius: inherit; height: 100%; }
    .progress-bar.warning { background: #d59b00; }
    .progress-bar.error { background: #c83232; }
    .resource-value { color: var(--muted); font-size: 9.5px; margin: 3px 0 0; }
    .environment-summary-card h3 { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; }
    .environment-summary-card h3 span { color: var(--muted); font-size: 10px; font-weight: 400; overflow-wrap: anywhere; text-align: right; }
    .environment-metadata-row { align-items: center; display: flex; flex-wrap: wrap; gap: 4px 10px; }
    .environment-metadata-row span { color: var(--ink); font-size: 11px; min-width: 0; overflow-wrap: anywhere; }
    .environment-metadata-row b { color: var(--muted); font-size: 10px; text-transform: uppercase; }
    .environment-metadata-row .status-pill { min-height: 18px; padding: 3px 7px; }
    .compact-details summary { color: var(--blue); cursor: pointer; font-size: 13px; font-weight: 700; }
    .app-footer { color: var(--muted); font-size: 12px; margin: 22px 0 4px; text-align: center; }
    .compact-table { border-collapse: collapse; font-size: 11px; width: 100%; }
    .compact-table th, .compact-table td { border-top: 1px solid var(--line); padding: 4px 2px; text-align: left; }
    .compact-table tr:first-child th, .compact-table tr:first-child td { border-top: 0; }
    .compact-table th { color: var(--muted); width: 45%; }
	    .settings-layout { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 1.7fr) minmax(300px, 1fr); }
    #dev-console-tools { grid-column: 1 / -1; }
    #github { grid-column: 1 / -1; }
    .runtime-settings-form { margin-top: 8px; }
    .runtime-settings-form .field-help { margin-bottom: 0; }
    .runtime-note { margin: 7px 0 0; }
    .runtime-limit-row { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .runtime-limit-row label { margin-top: 0; }
    .apache-summary-grid.host-summary-grid { grid-template-columns: 1fr; }
    .apache-summary-grid.host-summary-grid div { grid-template-columns: minmax(70px, 78px) minmax(0, 1fr); }
    .apache-summary-grid.host-summary-grid dt { font-size: 12px; }
    .apache-summary-grid.host-summary-grid dd { font-size: 14px; }
    .host-tools-table { table-layout: fixed; }
    .host-tools-table th:nth-child(1), .host-tools-table td:nth-child(1) { width: 14%; }
    .host-tools-table th:nth-child(2), .host-tools-table td:nth-child(2) { width: 10%; }
    .host-tools-table th:nth-child(3), .host-tools-table td:nth-child(3) { width: 12%; }
    .host-tools-table th:nth-child(4), .host-tools-table td:nth-child(4) { width: 12%; }
    .host-tools-table th:nth-child(5), .host-tools-table td:nth-child(5) { width: 14%; }
    .host-tools-table th:nth-child(6), .host-tools-table td:nth-child(6) { width: 30%; }
    .host-tools-table th:nth-child(7), .host-tools-table td:nth-child(7) { width: 8%; }
    .host-tools-table td:last-child .project-actions { gap: 5px; }
    .host-tools-table td:last-child button { padding: 7px 9px; }
    .server-management-selector { align-items: end; border-bottom: 1px solid var(--line); display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 8px 0 14px; padding-bottom: 10px; }
    .server-management-selector form { align-items: end; display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
    .server-management-selector label { margin: 0; }
    .server-management-selector select { min-width: 240px; }
    .server-management-selector .field-help { margin: 0; }
    .github-config-grid { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .github-config-grid label { margin-top: 0; }
    .github-submit { width: 100%; }
	    .projects-layout { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 2fr) minmax(320px, .95fr); }
	    .project-sidebar { display: grid; gap: 14px; min-width: 0; }
	    .project-adoption-panel { margin-top: 14px; }
	    .project-adoption-form-grid { display: grid; gap: 12px 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
	    .project-adoption-form-grid label { margin-top: 4px; }
	    .project-adoption-scan-row { align-items: end; display: grid; gap: 12px; grid-template-columns: minmax(260px, 1fr) auto; }
	    .project-adoption-scan-row label { margin-top: 4px; }
	    .project-adoption-scan-row button { margin-top: 0; }
	    .project-adoption-manual { margin-top: 14px; }
	    .project-adoption-manual-actions { display: flex; justify-content: flex-end; }
	    .project-adoption-manual-actions button { margin-top: 0; }
	    .project-adoption-confirmation .project-adoption-manual-actions { margin-top: 14px; }
	    .project-adoption-result { margin-top: 16px; }
	    .project-adoption-report { display: grid; gap: 12px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
	    .project-adoption-overview, .project-adoption-report .wide { grid-column: 1 / -1; }
	    .project-adoption-report .environment-block { min-width: 0; }
	    .project-adoption-report .project-detail-grid { grid-template-columns: 1fr; }
	    .project-adoption-report code, .project-adoption-report dd, .project-adoption-report td { overflow-wrap: break-word; word-break: normal; }
	    .project-adoption-ssh-detail { margin-top: 10px; }
	    .project-adoption-ssh-detail .tool-operation-log { max-height: 220px; min-height: 0; }
	    .project-adoption-sites th:nth-child(1), .project-adoption-sites td:nth-child(1) { width: 16%; }
	    .project-adoption-sites th:nth-child(2), .project-adoption-sites td:nth-child(2) { width: 22%; }
	    .project-adoption-sites th:nth-child(3), .project-adoption-sites td:nth-child(3) { width: 19%; }
	    .project-adoption-sites th:nth-child(4), .project-adoption-sites td:nth-child(4) { width: 9%; }
	    .project-adoption-sites th:nth-child(5), .project-adoption-sites td:nth-child(5) { width: 10%; }
	    .project-adoption-sites th:nth-child(6), .project-adoption-sites td:nth-child(6) { width: 16%; }
	    .project-adoption-sites th:nth-child(7), .project-adoption-sites td:nth-child(7) { width: 8%; }
	    .project-related-sites th:nth-child(1), .project-related-sites td:nth-child(1) { width: 14%; }
	    .project-related-sites th:nth-child(2), .project-related-sites td:nth-child(2) { width: 20%; }
	    .project-related-sites th:nth-child(3), .project-related-sites td:nth-child(3) { width: 18%; }
	    .project-related-sites th:nth-child(7), .project-related-sites td:nth-child(7) { width: 28%; }
	    .compact-list { margin: 6px 0 0; padding-left: 16px; }
	    .server-layout { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 2fr) minmax(300px, .95fr); }
	    .server-sidebar { display: grid; gap: 14px; min-width: 0; }
	    .server-compact-summary { align-items: center; display: grid; gap: 8px 12px; grid-template-columns: minmax(150px, 1.2fr) auto repeat(3, minmax(105px, .8fr)) auto; }
	    .server-compact-summary > span { min-width: 0; overflow-wrap: anywhere; }
	    .server-detail-grid { display: grid; gap: 7px 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 10px; }
	    .server-detail-grid div { border-top: 1px solid var(--line); display: grid; gap: 3px; grid-template-columns: minmax(100px, .75fr) minmax(0, 1.25fr); padding-top: 7px; }
	    .server-detail-grid dt { color: var(--muted); font-size: 11px; font-weight: 700; }
	    .server-detail-grid dd { margin: 0; overflow-wrap: anywhere; }
	    .server-key-compact .tool-operation-log { max-height: 90px; min-height: 0; }
	    .server-key-compact .copy-field { min-width: 0; }
	    .server-key-compact .setup-command-field { height: 210px; max-height: 320px; overflow-x: hidden; overflow-y: auto; }
	    .server-key-compact .public-key-field { height: 92px; overflow-x: hidden; overflow-y: auto; word-break: break-word; }
	    .server-key-compact .copy-row { grid-template-columns: minmax(0, 1fr); }
	    .server-key-compact .copy-row button { justify-self: start; padding: 7px 12px; }
	    .server-form-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
	    .copy-field { display: grid; gap: 6px; margin-top: 10px; }
	    .copy-field label { color: var(--ink); font-size: 13px; font-weight: 700; margin: 0; }
	    .copy-row { align-items: start; display: grid; gap: 8px; grid-template-columns: minmax(0, 1fr) auto; }
	    .copy-row pre { margin: 0; }
	    .copy-row button { margin-top: 0; white-space: nowrap; }
	    .server-sidebar .field-help { line-height: 1.45; }
    .settings-table { border-collapse: collapse; font-size: 12px; width: 100%; }
    .settings-table th, .settings-table td { border-top: 1px solid var(--line); padding: 7px 6px; text-align: left; vertical-align: top; }
    .settings-table th { color: var(--muted); font-size: 11px; text-transform: uppercase; }
    .settings-table td { overflow-wrap: normal; }
    .table-scroll { overflow-x: auto; width: 100%; }
    .table-scroll .settings-table { min-width: 680px; }
    .table-scroll .settings-table.compact-sites { min-width: 560px; }
    .table-scroll .host-tools-table { min-width: 920px; }
    .documentation-layout { align-items: start; display: grid; gap: 18px; grid-template-columns: 260px minmax(0, 1fr); }
    .documentation-nav { position: sticky; top: 14px; }
    .documentation-nav h3 { color: var(--blue); font-size: 13px; margin: 14px 0 8px; }
    .documentation-nav h3:first-child { margin-top: 0; }
    .documentation-nav a { border-radius: 5px; color: var(--blue); display: block; font-size: 13px; padding: 7px 8px; text-decoration: none; }
    .documentation-nav a.active { background: var(--blue); color: #fff; font-weight: 700; }
    .documentation-content { line-height: 1.6; }
    .documentation-content h2 { border-bottom: 1px solid var(--line); padding-bottom: 8px; }
    .documentation-content h3 { color: var(--blue); margin: 22px 0 8px; }
    .documentation-content h4, .documentation-content h5 { color: var(--ink); margin: 18px 0 6px; }
    .documentation-content p, .documentation-content li { font-size: 14px; }
    .documentation-content ul, .documentation-content ol { padding-left: 22px; }
    .documentation-content pre { max-width: 100%; overflow-x: auto; white-space: pre; }
    .documentation-content blockquote { border-left: 4px solid var(--line); color: var(--muted); margin: 14px 0; padding: 2px 0 2px 14px; }
    .tool-status { white-space: nowrap; }
    .site-path, .path-value { overflow-wrap: anywhere; word-break: normal; }
    #projects, #github, #apache, #runtime, #dev-console-host, #dev-console-tools { scroll-margin-top: 18px; }
    #createProject { scroll-margin-top: 18px; }
    #createProject button[type="submit"] { width: 100%; }
    .subsection { border-top: 1px solid var(--line); margin-top: 18px; padding-top: 18px; }
    .project-form { display: grid; gap: 14px; }
    .project-form fieldset { border: 1px solid var(--line); border-radius: 8px; margin: 0; padding: 14px; }
    .project-form legend { color: var(--blue); font-weight: 700; padding: 0 4px; }
    .project-form label { margin-top: 12px; }
    .project-form label:first-of-type { margin-top: 4px; }
    .field-help { color: var(--muted); font-size: 12px; margin: 5px 0 0; }
    .project-list { display: grid; gap: 12px; }
    .project-item { background: #f8fbfc; border: 1px solid var(--line); border-radius: 8px; padding: 14px; }
    .project-summary { align-items: center; display: flex; flex-wrap: wrap; gap: 8px 12px; justify-content: space-between; }
    .project-item[data-project-card] > .project-summary { display: grid; grid-template-columns: minmax(180px, 1fr) auto auto auto; }
    .project-summary > span:first-child { display: grid; gap: 2px; min-width: 0; }
    .project-summary-line { color: var(--muted); display: flex; flex-wrap: wrap; font-size: 12px; gap: 4px 12px; }
    .project-summary .button-link { font-size: 12px; margin-top: 0; padding: 6px 9px; }
    .project-card-toggle { font-size: 12px; margin-top: 0; padding: 6px 9px; }
    .project-details { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px; }
    .project-item-header { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; }
    .project-detail-grid { display: grid; gap: 7px 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 12px; }
    .project-detail-grid div { border-top: 1px solid var(--line); display: grid; gap: 4px; grid-template-columns: minmax(100px, .75fr) minmax(0, 1.25fr); padding-top: 7px; }
    .project-detail-grid dt { color: var(--muted); font-size: 12px; font-weight: 700; }
    .project-detail-grid dd { font-size: 13px; margin: 0; overflow-wrap: anywhere; }
    .project-side-panel { display: grid; gap: 14px; }
    .project-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .project-actions form { margin: 0; }
    .project-actions button { font-size: 13px; margin-top: 0; padding: 8px 11px; }
    .project-actions .danger { background: #a51d1d; }
    .project-actions .danger:disabled { background: #a51d1d; }
    .action-note { color: var(--muted); flex-basis: 100%; font-size: 12px; margin: 0; }
    .lifecycle-note { color: var(--muted); font-size: 12px; margin: 8px 0 0; }
    .environment-grid { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .environment-block, .generated-summary { background: #fff; border: 1px solid var(--line); border-radius: 7px; padding: 10px; }
    .environment-block h4, .generated-summary h3 { color: var(--blue); font-size: 12px; margin: 0 0 8px; }
    .generated-preview { display: grid; gap: 6px; margin: 0; }
    .generated-preview div, .apache-summary-grid div { display: grid; gap: 8px; grid-template-columns: minmax(112px, .8fr) minmax(0, 1.2fr); }
    .generated-preview dt, .apache-summary-grid dt { color: var(--muted); font-size: 11px; font-weight: 700; }
    .generated-preview dd, .apache-summary-grid dd { margin: 0; overflow-wrap: anywhere; }
    .generated-preview dt, .generated-preview dd { font-size: 12px; line-height: 1.35; }
    .generated-preview dd { color: var(--ink); font-weight: 400; word-break: normal; }
    .apache-summary { align-items: start; display: grid; gap: 14px; grid-template-columns: minmax(0, 1fr) auto; }
    .apache-summary-grid { display: grid; gap: 6px 18px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; }
    .apache-summary .form-actions { align-self: start; margin: 0; }
    .apache-sites { display: grid; gap: 12px; margin-top: 16px; }
    .local-hosts { background: #edf7fb; border-radius: 6px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; overflow-wrap: anywhere; padding: 8px; }
    .hosts-copy-row { align-items: stretch; display: grid; gap: 8px; grid-template-columns: minmax(0, 1fr) auto; margin-top: 8px; }
    .hosts-copy-row .local-hosts { margin: 0; }
    .success-message { background: #e9f7ef; border: 1px solid #b8dfc7; border-radius: 6px; color: var(--green); padding: 10px 12px; }
    .dashboard-processes { padding: 7px 8px; }
    .dashboard-processes summary { font-size: 12px; }
    .process-table { border-collapse: collapse; font-size: 10.5px; width: 100%; }
    .process-table th, .process-table td { border-top: 1px solid var(--line); padding: 3px 4px; text-align: left; }
    .process-table th { border-top: 0; color: var(--muted); }
    .process-table td:last-child { overflow-wrap: anywhere; }
    @media (max-width: 900px) {
      .dashboard-columns { display: block; }
      .dashboard-task-grid, .dashboard-execution-grid, .dashboard-deployment-grid, .environment-host-grid { grid-template-columns: 1fr; }
	      .workflow-stage-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	      .workflow-stage:nth-child(2)::after { content: ""; }
	      .settings-layout, .server-layout, .projects-layout, .documentation-layout { grid-template-columns: 1fr; }
	      .project-adoption-form-grid, .project-adoption-report, .project-adoption-scan-row { grid-template-columns: 1fr; }
	      .documentation-nav { position: static; }
	      .project-item[data-project-card] > .project-summary { grid-template-columns: minmax(0, 1fr); }
	      .project-detail-grid { grid-template-columns: 1fr; }
	      .server-compact-summary { grid-template-columns: minmax(0, 1fr); }
	      .server-detail-grid { grid-template-columns: 1fr; }
      .apache-summary, .runtime-limit-row, .github-config-grid { grid-template-columns: 1fr; }
      .page-header { display: block; }
      .brand-logo { height: 30px; }
      .brand-separator { height: 24px; }
      .page-context { margin-top: 12px; text-align: left; }
      main { margin-top: 18px; }
    }
    @media (max-width: 520px) {
	      .summary-grid, .resource-grid, .environment-grid, .apache-summary-grid, .server-detail-grid { grid-template-columns: 1fr; }
      .generated-preview div, .apache-summary-grid div { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<main>
  <header class="page-header">
    <div>
      <div class="brand-heading">
        <img class="brand-logo" src="/assets/images/iovon-logo.svg" alt="IOVON">
        <span class="brand-separator" aria-hidden="true"></span>
        <h1>Dev Console</h1>
      </div>
      <p class="meta">Project development and deployment console</p>
      <nav class="tab-nav" role="tablist" aria-label="Primary">
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'dashboard' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'dashboard' ? 'active' : '' ?>" data-tab-target="dashboard">Dashboard</button>
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'projects' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'projects' ? 'active' : '' ?>" data-tab-target="projects">Projects</button>
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'servers' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'servers' ? 'active' : '' ?>" data-tab-target="servers">Servers</button>
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'server-management' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'server-management' ? 'active' : '' ?>" data-tab-target="server-management">Server Management</button>
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'documentation' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'documentation' ? 'active' : '' ?>" data-tab-target="documentation">Documentation</button>
        <button type="button" role="tab" aria-selected="<?= $initialTab === 'settings' ? 'true' : 'false' ?>" class="tab-button <?= $initialTab === 'settings' ? 'active' : '' ?>" data-tab-target="settings">Settings</button>
      </nav>
    </div>
    <div class="page-context" aria-live="polite">
      <div data-page-context="dashboard"<?= $initialTab === 'dashboard' ? '' : ' hidden' ?>>
        <strong>Dashboard</strong>
        <span>Tasks and deployments</span>
      </div>
      <div data-page-context="settings"<?= $initialTab === 'settings' ? '' : ' hidden' ?>>
        <strong>Settings</strong>
        <span>Dev Console configuration and host environment</span>
      </div>
      <div data-page-context="projects"<?= $initialTab === 'projects' ? '' : ' hidden' ?>>
        <strong>Projects</strong>
        <span>Project lifecycle and task setup</span>
      </div>
      <div data-page-context="servers"<?= $initialTab === 'servers' ? '' : ' hidden' ?>>
        <strong>Servers</strong>
        <span>Server registry and SSH connectivity</span>
      </div>
      <div data-page-context="server-management"<?= $initialTab === 'server-management' ? '' : ' hidden' ?>>
        <strong>Server Management</strong>
        <span>Selected Managed Server operations</span>
      </div>
      <div data-page-context="documentation"<?= $initialTab === 'documentation' ? '' : ' hidden' ?>>
        <strong>Documentation</strong>
        <span>Help and technical reference</span>
      </div>
    </div>
  </header>

  <section id="dashboardTab" data-tab-panel="dashboard"<?= $initialTab === 'dashboard' ? '' : ' hidden' ?>>
  <?php if ($error !== ''): ?>
    <section class="panel error">
      <h2>Task creation failed</h2>
      <p><?= h($error) ?></p>
      <?php foreach ($results as $result): ?>
        <?php renderCommandResult($result); ?>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="panel project-selector">
    <?php if ($activeProject === null): ?>
      <div>
        <h2>Project</h2>
        <p class="meta">No Projects are registered yet. Create a Project in Projects to use the Dashboard.</p>
      </div>
    <?php else: ?>
      <form method="post" action="/?tab=dashboard" data-project-selection-form>
        <input type="hidden" name="action" value="select_active_project">
        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
        <input type="hidden" name="target_tab" value="dashboard">
        <label for="activeProjectSelect">Project</label>
        <select id="activeProjectSelect" name="project_id" onchange="this.form.submit()">
          <?php foreach ($projects as $project): ?>
            <option value="<?= h((string)$project['id']) ?>"<?= (string)$project['id'] === $activeProjectId ? ' selected' : '' ?>><?= h((string)$project['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="project-identity">
        <?php
          $projectProductionDomain = (string)($activeProject['production']['domain'] ?? '');
          $projectPreviewDomain = (string)($activeProject['preview']['domain'] ?? '');
          $projectProductionUrl = $projectProductionDomain === '' ? '' : 'http://' . $projectProductionDomain;
          $projectPreviewUrl = $projectPreviewDomain === '' ? '' : 'http://' . $projectPreviewDomain;
        ?>
        <div class="project-summary-line">
          <span><strong><?= h((string)($activeProject['name'] ?? '')) ?></strong></span>
          <span>Server: <?= h(devConsoleManagedServerLabel($activeManagedServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></span>
          <span>Status: <?= h(devConsoleManagedServerStatusLabel($activeManagedServer)) ?></span>
          <span>Production: <?php if ($projectProductionUrl !== ''): ?><a href="<?= h($projectProductionUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($projectProductionDomain) ?></a><?php else: ?>Not configured<?php endif; ?></span>
          <span>Preview: <?php if ($projectPreviewUrl !== ''): ?><a href="<?= h($projectPreviewUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h($projectPreviewDomain) ?></a><?php else: ?>Not configured<?php endif; ?></span>
        </div>
        <div class="project-paths-inline" aria-label="Project paths">
          <span><b>Source</b> <code><?= h(configuredDisplayValue($activeProject['repository_path'] ?? '')) ?></code></span>
          <span><b>Preview</b> <code><?= h(configuredDisplayValue($activeProject['preview']['path'] ?? '')) ?></code></span>
          <span><b>Production</b> <code><?= h(configuredDisplayValue($activeProject['production']['path'] ?? '')) ?></code></span>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($activeProject !== null): ?>
  <section class="panel environment-block" id="environment">
    <div class="dashboard-header">
      <h2>Environment</h2>
      <span class="meta" id="dashboardUpdated">Loading...</span>
    </div>
    <div class="dashboard-grid" id="environmentDashboard" aria-live="polite"></div>
  </section>
  <div class="dashboard-workspace">
  <div class="dashboard-task-grid">
  <div class="dashboard-column dashboard-task-editor">
  <section class="panel" id="create-task">
    <div class="dashboard-header" id="dashboardTaskEditor">
      <h2 id="editorHeading"><?= h($editorHeading) ?></h2>
      <a class="button-link secondary" id="newTaskAction" href="/?tab=dashboard&action=new_task#dashboardTaskEditor">New Task</a>
    </div>
    <?php if ($editorTaskId !== ''): ?>
      <p class="meta" id="viewingTaskNote">Viewing existing task. TODO tasks can be edited and saved before execution.</p>
    <?php endif; ?>
    <?php if (!$taskCreationReady): ?>
      <p class="error"><?= h($taskCreationUnavailableReason === '' ? 'Repository is not ready for task creation. Review Git status in Projects.' : $taskCreationUnavailableReason) ?></p>
    <?php endif; ?>
    <?php if ($taskPushWarning !== ''): ?>
      <p class="success-message"><?= h($taskPushWarning) ?></p>
    <?php elseif ($taskSaveMessage !== ''): ?>
      <p class="success-message"><?= h($taskSaveMessage) ?></p>
    <?php elseif ($taskAttachmentMessage !== ''): ?>
      <p class="success-message"><?= h($taskAttachmentMessage) ?></p>
    <?php elseif ($taskLifecycleMessage !== ''): ?>
      <p class="success-message"><?= h($taskLifecycleMessage) ?></p>
    <?php elseif ($createdTaskId !== '' && $error === ''): ?>
      <p class="success-message">Task "<?= h($createdTaskId) ?>" created, committed locally, and synchronized with GitHub for Project "<?= h(projectMessageName($activeProject, $activeProjectId)) ?>".</p>
    <?php endif; ?>
    <p id="nextTaskNumber"><strong>Next task number:</strong> <?= h(taskNumber($nextNumber)) ?></p>
    <form method="post" enctype="multipart/form-data" id="taskForm" data-created="<?= h($createdTaskPath !== '' && $error === '' ? '1' : '0') ?>">
      <input type="hidden" name="action" value="<?= h($editorTaskId === '' ? 'create_task' : 'save_task') ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <?php if ($editorTaskId !== '' && $viewTask): ?>
        <input type="hidden" name="task_id" value="<?= h($editorTaskId) ?>">
        <input type="hidden" name="task_source" value="<?= h((string)$viewTask['source']) ?>">
      <?php endif; ?>
      <label for="task_metadata">Task metadata</label>
      <textarea id="task_metadata" class="metadata-preview" readonly rows="9" aria-readonly="true" tabindex="-1"><?= h($taskMetadataPreview) ?></textarea>
      <label for="task_body">Task markdown body</label>
      <textarea id="task_body" name="task_body" required spellcheck="false" data-default-template="<?= h($taskDefaultTemplate) ?>" placeholder="# TASK-<?= h(sprintf('%03d', $nextNumber)) ?>&#10;&#10;## Title&#10;&#10;..."<?= ($editorTaskId !== '' && !$editorCanSave) ? ' readonly aria-readonly="true"' : '' ?>><?= h($editorBody) ?></textarea>
      <?php if ($editorTaskId === ''): ?>
        <div class="form-actions">
          <button type="button" class="secondary" id="clearDraft">Clear draft</button>
          <span class="hint" id="draftStatus">Draft autosaves in this browser.</span>
        </div>
      <?php else: ?>
        <p class="field-help" id="draftStatus">Existing task content is loaded from the Project repository.</p>
      <?php endif; ?>

      <label for="attachment">Optional attachments</label>
      <p class="field-help">Maximum file size: <?= h((string)$runtimeEffectiveLimits['attachment_limit_mb']) ?> MB. Maximum total request size: <?= h((string)$runtimeEffectiveLimits['request_limit_mb']) ?> MB.<?= $runtimeRestartRequired ? ' Configured limits are pending a Dev Console restart.' : '' ?></p>
      <label class="upload-zone" id="uploadZone" for="attachment">
        <input id="attachment" name="attachments[]" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.svg,.md,.txt,.docx,application/pdf,image/png,image/jpeg,image/svg+xml,text/markdown,text/plain,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
        <strong>Drop files here</strong>
        <span>or click to select up to five attachments.</span>
        <div class="selected-files" id="selectedFiles">No files selected.</div>
      </label>

      <?php if (!empty($editorAttachments)): ?>
        <section class="attachment-list">
          <strong>Attachments</strong>
          <ul>
            <?php foreach ($editorAttachments as $attachment): ?>
              <?php
                $attachmentName = (string)$attachment['name'];
                $attachmentUrl = '?action=task_attachment&task_id=' . rawurlencode($editorTaskId !== '' ? $editorTaskId : $createdTaskId) . '&task_source=' . rawurlencode($viewTask ? (string)$viewTask['source'] : 'project') . '&name=' . rawurlencode($attachmentName);
                $canRemoveAttachment = $viewTask !== null && taskCanRemoveAttachments($viewTask, $runsDir) && (string)$viewTask['source'] === 'project';
              ?>
              <li>
                <span>Done: <?= h($attachmentName) ?> (<?= h(formatTaskAttachmentSize((int)$attachment['size'])) ?>)</span>
                <a href="<?= h($attachmentUrl . '&mode=open') ?>" target="_blank" rel="noopener noreferrer">Open</a>
                <a href="<?= h($attachmentUrl . '&mode=download') ?>">Download</a>
                <?php if ($canRemoveAttachment): ?>
                  <form method="post" class="inline-form" action="/?tab=dashboard&task=<?= h(rawurlencode($editorTaskId . '.md')) ?>&task_source=<?= h(rawurlencode((string)$viewTask['source'])) ?>" data-confirm-message="<?= h('Remove attachment ' . $attachmentName . ' from ' . $editorTaskId . '?') ?>" onsubmit="return confirm(this.dataset.confirmMessage);">
                    <input type="hidden" name="action" value="remove_task_attachment">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="task_id" value="<?= h($editorTaskId) ?>">
                    <input type="hidden" name="task_source" value="<?= h((string)$viewTask['source']) ?>">
                    <input type="hidden" name="attachment_name" value="<?= h($attachmentName) ?>">
                    <button type="submit" class="link-button">Remove</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <button type="submit"<?= $editorTaskId === '' ? ($taskCreationReady ? '' : ' disabled title="' . h($taskCreationUnavailableReason === '' ? 'Review Git status in Projects before creating tasks.' : $taskCreationUnavailableReason) . '"') : ($editorCanSave ? '' : ' disabled title="Only TODO Project tasks can be edited before execution."') ?>>Save Task</button>
    </form>
  </section>
  </div>

  <div class="dashboard-column dashboard-task-list">
    <section class="panel" id="tasks">
      <h2>Tasks</h2>
      <?php if ($legacyTasksDetected): ?>
        <p class="field-help">Legacy tasks detected. They belong to the previous global task storage and are associated with the default Project.</p>
      <?php endif; ?>
      <div class="task-list-scroll">
        <?php foreach ($dashboardTaskGroups as $groupName => $groupTasks): ?>
          <section class="task-group">
            <h3><?= h($groupName) ?></h3>
            <?php if (empty($groupTasks)): ?>
              <p class="task-group-empty">No <?= h(strtolower($groupName)) ?> tasks.</p>
            <?php else: ?>
              <ul class="task-list">
                <?php foreach ($groupTasks as $task): ?>
                  <li>
                    <div class="task-row-header">
                      <span class="task-summary-label"><?= h($task['task_id']) ?></span>
                      <span class="badge <?= h(strtolower((string)$task['status'])) ?>"><?= h($task['status']) ?></span>
                    </div>
                    <?php if ($task['title'] !== ''): ?><span class="task-title"><?= h($task['title']) ?></span><?php endif; ?>
                    <div class="task-metadata">
                      <?php if ((string)($task['source'] ?? '') === 'legacy'): ?><span>Legacy storage</span><?php endif; ?>
                      <?php if ($task['commit'] !== ''): ?><span>Commit: <code title="<?= h($task['commit']) ?>"><?= h(shortSha($task['commit'])) ?></code></span><?php endif; ?>
                      <?php if ($task['milestone'] !== ''): ?><span class="milestone">Milestone: <?= h($task['milestone']) ?></span><?php endif; ?>
                      <?php if ($task['tag'] !== ''): ?><span>Tag: <?= h($task['tag']) ?></span><?php endif; ?>
                    </div>
                    <div class="task-row-actions">
                      <a class="button-link secondary" href="?tab=dashboard&task=<?= h(rawurlencode($task['filename'])) ?>&task_source=<?= h(rawurlencode((string)$task['source'])) ?>">Use in Workflow</a>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
  </div>

  <div class="dashboard-execution-grid">
      <section class="panel">
        <h2>Current Workflow</h2>
        <?php if ($activeTaskId !== '' && $error === ''): ?>
          <dl class="dashboard-list">
            <div><dt>Current Task</dt><dd><strong><?= h($activeTaskId) ?></strong></dd></div>
            <div><dt>Status</dt><dd><?= h($activeTaskStatus) ?></dd></div>
            <div><dt>Current commit</dt><dd><?= h(configuredDisplayValue($activeGitStatus['subject'] ?? '')) ?><?= ($activeGitStatus['local_commit'] ?? '') !== '' ? '<br><code title="' . h((string)$activeGitStatus['local_commit']) . '">' . h(shortSha((string)$activeGitStatus['local_commit'])) . '</code>' : '' ?></dd></div>
            <div><dt>Current branch</dt><dd><?= h(configuredDisplayValue($activeGitStatus['branch'] ?? '')) ?></dd></div>
            <div><dt>Task location</dt><dd><code><?= h($activeTaskPath) ?></code></dd></div>
          </dl>
          <details class="compact-details" open>
            <summary>Workflow details</summary>
            <ul class="workflow-steps">
              <li><span class="step-state done">Done</span><span>Task file created<?php if ($activeTaskPath !== ''): ?>: <code><?= h($activeTaskPath) ?></code><?php endif; ?></span></li>
              <li><span class="step-state <?= h($taskGitCompleted ? 'done' : 'pending') ?>"><?= h($taskGitCompleted ? 'Done' : 'Ready') ?></span><span>Task committed locally<?php if ($commitHash !== ''): ?>: <code title="<?= h($commitHash) ?>"><?= h(shortSha($commitHash)) ?></code><?php endif; ?></span></li>
              <li><span class="step-state <?= h($taskGitPushed ? 'done' : 'pending') ?>"><?= h($taskGitPushed ? 'Done' : 'Pending') ?></span><span><?= h($taskGitPushed ? 'Task synchronized with GitHub' : 'GitHub synchronization needs retry from Projects') ?></span></li>
              <li><span class="step-state <?= h(in_array($activeRunStatus, ['completed', 'failed'], true) ? 'done' : 'pending') ?>"><?= h(statusLabel($activeRunStatus)) ?></span><span>Codex run status</span></li>
            </ul>
          </details>
          <div class="prompt-actions">
            <?php if ($activeTaskRunnable && $codexRepositoryReady && $codexCliReady && $codexAuthReady && !$projectCodexRunActive): ?>
              <button type="button" id="runCodex" data-task="<?= h($activeTaskId) ?>" data-task-source="<?= h($activeTaskSource) ?>">Run Codex</button>
            <?php else: ?>
              <?php
                if (!$codexCliReady) {
                    $runCodexDisabledReason = 'Codex CLI is not installed on this server.';
                } elseif (!$codexAuthReady) {
                    $runCodexDisabledReason = 'Codex CLI is not authenticated for the Dev Console service user.';
                } elseif ($projectCodexRunActive) {
                    $runCodexDisabledReason = 'Codex is already running for this Project.';
                } elseif (!$codexRepositoryReady) {
                    $runCodexDisabledReason = $taskCreationUnavailableReason === '' ? 'Project repository is not ready for Codex.' : $taskCreationUnavailableReason;
                } else {
                    $runCodexDisabledReason = 'Only TODO or failed IN PROGRESS tasks can be run with Codex.';
                }
              ?>
              <button type="button" disabled title="<?= h($runCodexDisabledReason) ?>">Run Codex</button>
            <?php endif; ?>
            <a class="button-link" href="?tab=dashboard&task=<?= h(rawurlencode($activeTaskId . '.md')) ?>&task_source=<?= h(rawurlencode($activeTaskSource)) ?>" target="_blank" rel="noopener">Open TASK</a>
            <?php if ($activeTaskDroppable): ?>
              <form method="post" class="inline-form" action="/?tab=dashboard&task=<?= h(rawurlencode($activeTaskId . '.md')) ?>&task_source=<?= h(rawurlencode($activeTaskSource)) ?>#codexRunPanel" onsubmit="return confirm('Drop <?= h($activeTaskId) ?>? The task file will be moved to TASKS/DROPPED and synchronized. Project application files will not be changed.');">
                <input type="hidden" name="action" value="drop_task">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="task" value="<?= h($activeTaskId) ?>">
                <input type="hidden" name="task_source" value="<?= h($activeTaskSource) ?>">
                <button type="submit" class="secondary">Drop Task</button>
              </form>
              <span class="hint"><?= h($activeTaskStatus === 'TODO' ? 'This TODO task can be run, edited, or dropped before execution.' : 'This failed IN PROGRESS task can be retried with Run Codex or explicitly dropped.') ?></span>
            <?php endif; ?>
            <?php if (!empty($codexLifecycleRecovery['recoverable'])): ?>
              <form method="post" class="inline-form" action="/?tab=dashboard&task=<?= h(rawurlencode($activeTaskId . '.md')) ?>&task_source=<?= h(rawurlencode($activeTaskSource)) ?>#codexRunPanel">
                <input type="hidden" name="action" value="recover_codex_lifecycle">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="task" value="<?= h($activeTaskId) ?>">
                <input type="hidden" name="task_source" value="<?= h($activeTaskSource) ?>">
                <button type="submit" class="secondary" title="Complete task lifecycle synchronization without running Codex again.">Recover Task Lifecycle</button>
              </form>
            <?php endif; ?>
            <?php if ($activeTaskRunnable && !$codexCliReady): ?>
              <span class="hint">Codex CLI is not installed on this server.</span>
            <?php elseif ($activeTaskRunnable && !$codexAuthReady): ?>
              <span class="hint">Codex CLI is not authenticated for the Dev Console service user.</span>
            <?php elseif ($projectCodexRunActive && !in_array($activeRunStatus, ['queued', 'running'], true)): ?>
              <span class="hint">Codex is already running for this Project.</span>
            <?php endif; ?>
            <?php if ($codexRecoveryError !== ''): ?>
              <span class="hint">Lifecycle recovery failed: <?= h($codexRecoveryError) ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($attachmentPaths)): ?>
            <div class="attachment-list">
              <strong>Attachments</strong>
              <ul>
                <?php foreach ($attachmentPaths as $path): ?>
                  <li>✓ <code><?= h($path) ?></code></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <p class="meta">No active task. Create a task or open a task from the list.</p>
        <?php endif; ?>
        <div class="workflow-stage-grid current-workflow-stages" aria-label="Project workflow summary">
          <?php foreach ($workflowStages as $stage): ?>
            <?php $stageState = (string)($stage['state'] ?? ''); ?>
            <section class="workflow-stage" data-workflow-stage="<?= h((string)($stage['name'] ?? '')) ?>">
              <h3><?= h((string)($stage['name'] ?? '')) ?></h3>
              <span class="status-pill <?= h(workflowStateClass($stageState)) ?>" data-workflow-state><?= h($stageState) ?></span>
              <strong data-workflow-primary><?= h((string)($stage['primary'] ?? '')) ?></strong>
              <?php if ((string)($stage['detail'] ?? '') !== ''): ?>
                <span class="meta" data-workflow-detail><?= h((string)$stage['detail']) ?></span>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel">
        <h2>Activity</h2>
        <?php if ($activeTaskId !== '' && $error === ''): ?>
          <ul class="operation-summary">
            <li>Done: Task selected</li>
            <li><?= $taskGitCompleted ? 'Done' : 'Ready' ?>: Local commit</li>
            <li><?= $taskGitPushed ? 'Done' : 'Pending' ?>: GitHub synchronized</li>
            <li><?= h($activeRunStatus === 'not_started' ? 'Ready: Codex not started' : statusLabel($activeRunStatus) . ': Codex run') ?></li>
          </ul>
          <?php if (!empty($activeCodexResult)): ?>
            <dl class="deployment-details" id="codexResultSummary">
              <div><dt>Task</dt><dd><?= h((string)($activeCodexResult['task_id'] ?? $activeTaskId)) ?></dd></div>
              <div><dt>Status</dt><dd><?= h((string)($activeCodexResult['status'] ?? statusLabel($activeRunStatus))) ?></dd></div>
              <div><dt>Commit</dt><dd><code title="<?= h((string)($activeCodexResult['commit'] ?? '')) ?>"><?= h((string)($activeCodexResult['commit'] ?? '') === '' ? 'Not configured' : shortSha((string)$activeCodexResult['commit'])) ?></code></dd></div>
              <div><dt>Files changed</dt><dd><?= h((string)($activeCodexResult['files_changed'] ?? 'Not configured')) ?></dd></div>
              <div><dt>Validation</dt><dd><?= h((string)($activeCodexResult['validation'] ?? 'Not configured')) ?></dd></div>
              <div><dt>Duration</dt><dd><?= h(isset($activeCodexResult['duration_seconds']) ? formatDuration((int)$activeCodexResult['duration_seconds']) : 'Not configured') ?></dd></div>
            </dl>
            <?php if ((string)($activeCodexResult['summary'] ?? '') !== ''): ?>
              <p><?= h((string)$activeCodexResult['summary']) ?></p>
            <?php endif; ?>
          <?php endif; ?>
          <div class="codex-run-panel" id="codexRunPanel" data-task="<?= h($activeTaskId) ?>" data-task-source="<?= h($activeTaskSource) ?>">
            <p><strong>Run status:</strong> <span class="codex-status" id="codexStatus"><?= h(statusLabel($activeRunStatus)) ?></span></p>
            <details class="compact-details">
              <summary>Show log</summary>
              <pre class="codex-console" id="codexConsole">Loading activity...</pre>
              <div class="prompt-actions">
                <button type="button" class="secondary" id="refreshCodexLog">Refresh</button>
                <button type="button" class="secondary" id="copyCodexLog">Copy to Clipboard</button>
                <button type="button" class="secondary" id="downloadCodexLog">Download Log</button>
                <span class="hint" id="copyCodexMessage" aria-live="polite"></span>
              </div>
            </details>
          </div>
        <?php else: ?>
          <div class="codex-run-panel">
            <p><strong>Run status:</strong> <span class="codex-status">Not started</span></p>
            <pre class="codex-console">Activity will appear here after a task is created and run.</pre>
          </div>
        <?php endif; ?>
      </section>

  </div>
  <div class="dashboard-deployment-grid">
      <section class="panel deployment-panel" id="previewDeployment">
        <h2>Preview Deployment</h2>
        <?php
          $previewServer = is_array($managedPreviewDeploymentOverview['managed_server'] ?? null) ? $managedPreviewDeploymentOverview['managed_server'] : null;
          $previewStatus = (string)($managedPreviewDeploymentOverview['status'] ?? 'never_deployed');
          $previewStatusLabel = match ($previewStatus) {
              'deployed' => 'Deployed',
              'failed' => 'Failed',
              'running' => 'Running',
              default => 'Never deployed',
          };
          $previewStatusClass = $previewStatus === 'deployed' ? 'success' : ($previewStatus === 'failed' ? 'failed' : ($previewStatus === 'running' ? 'running' : 'pending'));
          $previewCommit = (string)($managedPreviewDeploymentOverview['commit'] ?? '');
          $previewDuration = $managedPreviewDeploymentOverview['duration_ms'] ?? null;
          $previewReady = !empty($managedPreviewDeploymentReadiness['ready']);
          $previewReasons = $managedPreviewDeploymentReadiness['reasons'] ?? [];
          $previewWarnings = $managedPreviewDeploymentReadiness['warnings'] ?? [];
          $previewOperationId = $previewStatus === 'running' ? (string)($managedPreviewDeploymentOverview['operation_id'] ?? '') : '';
        ?>
        <dl class="deployment-primary">
          <div><dt>Status</dt><dd><span id="previewDeploymentStatus" class="deployment-status <?= h($previewStatusClass) ?>"><?= h($previewStatusLabel) ?></span></dd></div>
          <div><dt>Preview URL</dt><dd><?php if ((string)($managedPreviewDeploymentOverview['url'] ?? '') !== ''): ?><a href="<?= h((string)$managedPreviewDeploymentOverview['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$managedPreviewDeploymentOverview['url']) ?></a><?php else: ?>Not configured<?php endif; ?></dd></div>
          <div><dt>Preview version</dt><dd><code id="previewDeploymentCommit" title="<?= h($previewCommit) ?>"><?= h($previewCommit === '' ? 'Not deployed' : shortSha($previewCommit)) ?></code></dd></div>
          <div><dt>Last deployment</dt><dd id="previewLastDeploymentTime"><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['deployed_at'] ?? '')) ?></dd></div>
        </dl>
        <details class="compact-details" open>
          <summary>Deployment details</summary>
          <dl class="deployment-details">
            <div><dt>Managed Server</dt><dd><?= h(devConsoleManagedServerLabel($previewServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></dd></div>
            <div><dt>Remote path</dt><dd><code><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['remote_path'] ?? '')) ?></code></dd></div>
            <div><dt>Repository</dt><dd><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['repository'] ?? '')) ?></dd></div>
            <div><dt>Branch</dt><dd id="previewDeploymentBranch"><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['branch'] ?? '')) ?></dd></div>
            <div><dt>GitHub commit</dt><dd id="previewDeploymentSourceCommit"><?= h($previewReady ? 'Resolved at deployment time' : 'Unavailable') ?></dd></div>
            <div><dt>Duration</dt><dd id="previewDeploymentDuration"><?= h($previewDuration === null ? 'Not configured' : ((string)round(((int)$previewDuration) / 1000, 1) . 's')) ?></dd></div>
          <?php if ((string)($managedPreviewDeploymentOverview['last_attempt_status'] ?? '') === 'failed'): ?>
            <div><dt>Latest attempt</dt><dd><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['last_attempt_at'] ?? '')) ?>: <?= h(configuredDisplayValue($managedPreviewDeploymentOverview['last_attempt_message'] ?? 'Failed')) ?></dd></div>
          <?php endif; ?>
          </dl>
        </details>
        <?php if (!empty($previewWarnings)): ?>
          <ul class="operation-summary">
            <?php foreach ($previewWarnings as $warning): ?><li>Warning: <?= h((string)$warning) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!$previewReady): ?>
          <p class="field-help"><?= h(implode(' ', array_map('strval', $previewReasons))) ?></p>
        <?php endif; ?>
        <button type="button" id="deployPreview" data-operation-id="<?= h($previewOperationId) ?>"<?= ($previewReady && $previewStatus !== 'running') ? '' : ' disabled title="' . h($previewStatus === 'running' ? 'Preview deployment is already running.' : implode(' ', array_map('strval', $previewReasons))) . '"' ?>>Deploy to Preview</button>
        <dl class="tool-operation-grid" id="previewDeploymentProgress"<?= $previewOperationId !== '' ? '' : ' hidden' ?>>
          <div><dt>Stage</dt><dd id="previewDeploymentStage">Preparing</dd></div>
          <div><dt>Elapsed</dt><dd id="previewDeploymentElapsed">0s</dd></div>
        </dl>
        <p class="deployment-error" id="previewDeploymentError" aria-live="assertive"></p>
        <details class="compact-details">
          <summary>Show deployment log</summary>
          <div class="operation-actions">
            <button type="button" class="secondary" data-copy-log="previewDeploymentLog">Copy Log</button>
            <button type="button" class="secondary" data-download-log="previewDeploymentLog" data-download-name="preview-deployment.log">Download Log</button>
            <span class="meta" data-log-message="previewDeploymentLog"></span>
          </div>
          <pre class="codex-console" id="previewDeploymentLog">No deployment log yet.</pre>
        </details>
      </section>

    <section class="panel deployment-panel production" id="productionDeployment">
      <h2>Production Deployment</h2>
      <?php
        $productionServer = is_array($managedProductionDeploymentOverview['managed_server'] ?? null) ? $managedProductionDeploymentOverview['managed_server'] : null;
        $productionStatus = (string)($managedProductionDeploymentOverview['status'] ?? 'never_deployed');
        $productionStatusLabel = productionDeploymentStatusLabel($productionStatus);
        $productionStatusClass = $productionStatus === 'deployed' ? 'success' : ($productionStatus === 'failed' ? 'failed' : ($productionStatus === 'running' ? 'running' : 'pending'));
        $productionCommit = (string)($managedProductionDeploymentOverview['commit'] ?? '');
        $productionPreviewCommit = (string)($managedProductionDeploymentOverview['preview_commit'] ?? '');
        $productionDuration = $managedProductionDeploymentOverview['duration_ms'] ?? null;
        $productionReady = !empty($managedProductionDeploymentReadiness['ready']);
        $productionReasons = $managedProductionDeploymentReadiness['reasons'] ?? [];
        $productionOperationId = $productionStatus === 'running' ? (string)($managedProductionDeploymentOverview['operation_id'] ?? '') : '';
        $productionPreflight = is_array($managedProductionDeploymentOverview['preflight'] ?? null) ? $managedProductionDeploymentOverview['preflight'] : null;
        $productionPreflightChanges = is_array($productionPreflight['changes'] ?? null) ? $productionPreflight['changes'] : [];
        $productionBlockingDeletes = is_array($productionPreflight['blocking_deletes'] ?? null) ? $productionPreflight['blocking_deletes'] : [];
        $productionPreflightReviewRequired = $productionPreflight !== null && !empty($productionPreflight['review_required']);
        $productionPreflightDeletionApproved = $productionPreflight !== null && !empty($productionPreflight['deletion_approved']);
        $productionPreflightSummary = is_array($productionPreflight['summary'] ?? null) ? $productionPreflight['summary'] : [];
        $productionPreservePaths = is_array($managedProductionDeploymentOverview['preserve_paths'] ?? null) ? $managedProductionDeploymentOverview['preserve_paths'] : [];
      ?>
      <dl class="deployment-primary">
        <div><dt>Status</dt><dd><span id="productionDeploymentStatus" class="deployment-status <?= h($productionStatusClass) ?>"><?= h($productionStatusLabel) ?></span></dd></div>
        <div><dt>Production URL</dt><dd id="productionDeploymentUrl"><?php if ((string)($managedProductionDeploymentOverview['production_url'] ?? '') !== ''): ?><a href="<?= h((string)$managedProductionDeploymentOverview['production_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$managedProductionDeploymentOverview['production_url']) ?></a><?php else: ?>Not configured<?php endif; ?></dd></div>
        <div><dt>Preview version</dt><dd><code id="productionPreviewCommit" title="<?= h($productionPreviewCommit) ?>"><?= h($productionPreviewCommit === '' ? 'Not deployed' : shortSha($productionPreviewCommit)) ?></code></dd></div>
        <div><dt>Production version</dt><dd><code id="productionCommit" title="<?= h($productionCommit) ?>"><?= h($productionCommit === '' ? 'Not deployed' : shortSha($productionCommit)) ?></code></dd></div>
        <div><dt>Last deployed</dt><dd id="productionLastDeploymentTime"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['deployed_at'] ?? '')) ?></dd></div>
        <div><dt>Version state</dt><dd id="productionVersionState"><?= h((string)($managedProductionDeploymentOverview['version_state'] ?? 'Preview has not been deployed')) ?></dd></div>
      </dl>
      <details class="compact-details" open>
        <summary>Deployment details</summary>
        <dl class="deployment-details">
          <div><dt>Managed Server</dt><dd id="productionDeploymentServer"><?= h(devConsoleManagedServerLabel($productionServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></dd></div>
          <div><dt>Production path</dt><dd><code id="productionDeploymentPath"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['production_path'] ?? '')) ?></code></dd></div>
          <div><dt>Preview deployed</dt><dd id="productionPreviewDeployedAt"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['preview_deployed_at'] ?? '')) ?></dd></div>
          <div><dt>Preview path</dt><dd><code id="productionPreviewPath"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['preview_path'] ?? '')) ?></code></dd></div>
        <div><dt>Duration</dt><dd id="productionDeploymentDuration"><?= h($productionDuration === null ? 'Not configured' : ((string)round(((int)$productionDuration) / 1000, 1) . 's')) ?></dd></div>
        <?php if ((string)($managedProductionDeploymentOverview['last_attempt_status'] ?? '') === 'failed'): ?>
          <div><dt>Latest attempt</dt><dd><?= h(configuredDisplayValue($managedProductionDeploymentOverview['last_attempt_at'] ?? '')) ?>: <?= h(configuredDisplayValue($managedProductionDeploymentOverview['last_attempt_message'] ?? 'Failed')) ?></dd></div>
        <?php endif; ?>
        </dl>
      </details>
      <section class="preflight-summary<?= $productionPreflightReviewRequired ? ' review-required' : '' ?>" id="productionPreflight">
        <div class="dashboard-header">
          <h3>Production Preflight</h3>
          <?php if ($productionPreflightReviewRequired): ?>
            <span class="status-pill warning" id="productionPreflightStatus">REVIEW REQUIRED</span>
          <?php elseif ($productionPreflight !== null): ?>
            <span class="status-pill healthy" id="productionPreflightStatus">READY</span>
          <?php else: ?>
            <span class="status-pill pending" id="productionPreflightStatus">NOT CHECKED</span>
          <?php endif; ?>
        </div>
        <p class="field-help" id="productionPreflightMessage">
          <?php if ($productionPreflight === null): ?>
            Run preflight before deploying Production. It compares current remote Preview with current remote Production without changing either.
          <?php elseif ($productionPreflightReviewRequired): ?>
            Production contains unmanaged files that would be deleted by promotion. Preserve selected paths or approve the remaining deletions before deploying.
          <?php elseif ($productionPreflightDeletionApproved): ?>
            Deletions approved for this exact preflight result. Approved deletion candidates may be removed with managed privileges before sync. Checked <?= h(configuredDisplayValue($productionPreflight['checked_at'] ?? '')) ?> for Preview commit <?= h(shortSha((string)($productionPreflight['preview_commit'] ?? ''))) ?>.
          <?php else: ?>
            Checked <?= h(configuredDisplayValue($productionPreflight['checked_at'] ?? '')) ?> for Preview commit <?= h(shortSha((string)($productionPreflight['preview_commit'] ?? ''))) ?>.
          <?php endif; ?>
        </p>
        <p class="preflight-counts" id="productionPreflightCounts">
          <span>Add <?= h((string)($productionPreflightSummary['add'] ?? 0)) ?></span>
          <span>Update <?= h((string)($productionPreflightSummary['update'] ?? 0)) ?></span>
          <span>Delete <?= h((string)($productionPreflightSummary['delete'] ?? 0)) ?></span>
          <span>Preserved <?= h((string)($productionPreflightSummary['preserved'] ?? 0)) ?></span>
        </p>
        <ul class="preflight-paths" id="productionPreflightDeletes"<?= empty($productionBlockingDeletes) ? ' hidden' : '' ?>>
          <?php foreach (array_slice($productionBlockingDeletes, 0, 12) as $deletePath): ?>
            <li>
              <code><?= h((string)$deletePath) ?></code>
              <button type="button" class="secondary" data-preserve-production-path="<?= h((string)$deletePath) ?>">Preserve path</button>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if (!empty($productionPreservePaths)): ?>
          <p class="field-help" id="productionPreservePaths">Preserve rules: <?= h(implode(', ', array_map('strval', $productionPreservePaths))) ?></p>
        <?php else: ?>
          <p class="field-help" id="productionPreservePaths">Preserve rules: none</p>
        <?php endif; ?>
        <div class="operation-actions">
          <button type="button" class="secondary" id="refreshProductionPreflight">Refresh Preflight</button>
          <button type="button" class="secondary" id="approveProductionDeletions"<?= $productionPreflightReviewRequired ? '' : ' hidden' ?>>Approve deletions</button>
        </div>
      </section>
      <?php if (!$productionReady): ?>
        <p class="field-help"><?= h(implode(' ', array_map('strval', $productionReasons))) ?></p>
      <?php endif; ?>
      <button type="button" class="deploy-production" id="deployProduction" data-operation-id="<?= h($productionOperationId) ?>" data-preview-commit="<?= h($productionPreviewCommit) ?>" data-server="<?= h(devConsoleManagedServerLabel($productionServer, (string)($activeProject['managed_server_id'] ?? ''))) ?>" data-production-path="<?= h((string)($managedProductionDeploymentOverview['production_path'] ?? '')) ?>"<?= $productionReady ? '' : ' disabled title="' . h(implode(' ', array_map('strval', $productionReasons))) . '"' ?>>Deploy to Production</button>
      <dl class="tool-operation-grid" id="productionDeploymentProgress"<?= $productionOperationId !== '' ? '' : ' hidden' ?>>
        <div><dt>Stage</dt><dd id="productionDeploymentStage">Preparing</dd></div>
        <div><dt>Elapsed</dt><dd id="productionDeploymentElapsed">0s</dd></div>
      </dl>
      <p class="deployment-error" id="productionDeploymentError" aria-live="assertive"></p>
      <details class="compact-details">
        <summary>Show deployment log</summary>
        <div class="operation-actions">
          <button type="button" class="secondary" data-copy-log="productionDeploymentLog">Copy Log</button>
          <button type="button" class="secondary" data-download-log="productionDeploymentLog" data-download-name="production-deployment.log">Download Log</button>
          <span class="meta" data-log-message="productionDeploymentLog"></span>
        </div>
        <pre class="codex-console" id="productionDeploymentLog">No deployment log yet.</pre>
      </details>
    </section>
  </div>

  </div>
  <?php endif; ?>

  <?php if (!$viewTask && $requestedTaskFile !== ''): ?>
    <section class="panel error">
      <h2>Task not found</h2>
      <p>The requested task file does not belong to the active Project or could not be opened.</p>
    </section>
  <?php endif; ?>

  <dialog id="previewDeploymentDialog">
    <div class="modal-content">
      <h2>Confirm Preview Deployment</h2>
      <div id="previewDeploymentSummary"></div>
      <p class="deployment-error" id="previewModalDeploymentError" aria-live="assertive"></p>
      <div class="modal-actions">
        <button type="button" class="secondary" id="cancelPreviewDeployment">Cancel</button>
        <button type="button" id="confirmPreviewDeployment">Deploy to Preview</button>
      </div>
    </div>
  </dialog>

  <dialog id="deploymentDialog">
    <div class="modal-content">
      <h2>Confirm Production Deployment</h2>
      <div id="deploymentPreview"></div>
      <p class="deployment-error" id="modalDeploymentError" aria-live="assertive"></p>
      <div class="modal-actions">
        <button type="button" class="secondary" id="cancelDeployment">Cancel</button>
        <button type="button" class="deploy-production" id="confirmDeployment">Deploy to Production</button>
      </div>
    </div>
  </dialog>

  <dialog id="deleteProjectDialog">
    <div class="modal-content">
      <h2 id="deleteProjectTitle">Delete Project</h2>
      <label for="deleteProjectConfirmation">Type the Project name to confirm deletion.</label>
      <input id="deleteProjectConfirmation" type="text" autocomplete="off">
      <section class="environment-block">
        <h4>GitHub repository</h4>
        <label id="deleteGithubRepositoryOption"><input id="deleteGithubRepositoryCheckbox" type="checkbox"> <span id="deleteGithubRepositoryLabel"></span></label>
        <p class="field-help" id="deleteGithubRepositoryHelp">If unchecked, the GitHub repository will be preserved.</p>
        <p class="field-help" id="deleteGithubRepositoryUnavailable" hidden>GitHub repository deletion is unavailable.</p>
        <p class="field-help" id="deleteGithubRepositoryUnavailableReason" hidden></p>
      </section>
      <div class="modal-actions">
        <button type="button" class="secondary" id="cancelProjectDelete">Cancel</button>
        <button type="button" class="danger" id="confirmProjectDelete" disabled>Delete Project</button>
      </div>
    </div>
  </dialog>
  </section>

  <section id="projectsTab" data-tab-panel="projects"<?= $initialTab === 'projects' ? '' : ' hidden' ?>>
    <div class="projects-layout">
      <section class="panel" id="projects">
        <h2>Projects</h2>
        <?php if ($projectFlash !== ''): ?>
          <p class="success-message"><?= h($projectFlash) ?></p>
        <?php endif; ?>
        <?php if ($projectActionResult !== null && in_array((string)($projectActionResult['action'] ?? ''), ['remove_project', 'delete_project'], true)): ?>
          <?php renderOperationResult($projectActionResult, 'projectOperationLog', ((string)($projectActionResult['action'] ?? 'project-operation')) . '.log'); ?>
        <?php endif; ?>
        <?php if (empty($projects)): ?>
          <p class="meta">No projects configured yet.</p>
        <?php else: ?>
          <div class="project-list">
            <?php foreach ($projectsForDisplay as $project): ?>
              <?php
                $projectIdForCard = (string)($project['id'] ?? '');
                $projectStatus = $projectStatuses[(string)($project['id'] ?? '')] ?? projectStatus($project);
                $statusLabel = (string)$projectStatus['label'];
                $statusClass = $statusLabel === 'Ready' ? 'healthy' : (in_array($statusLabel, ['Configuration drift', 'Setup failed'], true) ? 'error' : 'warning');
                $lifecycleLabel = projectLifecycleLabel($project, $projectStatus);
                $isManaged = !empty($project['provisioning']['managed']);
                $isActiveProject = $projectIdForCard === $activeProjectId;
                $projectManagedServerId = (string)($project['managed_server_id'] ?? '');
                $projectManagedServer = devConsoleFindManagedServerById($managedServers, $projectManagedServerId);
                $projectManagedServerStatusClass = $projectManagedServer === null ? 'warning' : managedServersStatusClass($projectManagedServer);
                $projectSetupMetadata = projectRemoteSetupMetadata($project);
                $cardActionResult = $projectActionResult !== null
                    && (string)($projectActionResult['project_id'] ?? '') === $projectIdForCard
                    && !in_array((string)($projectActionResult['action'] ?? ''), ['remove_project', 'delete_project', 'cleanup_orphaned_project'], true)
                        ? $projectActionResult
                        : null;
                $cardOpen = $cardActionResult !== null || $projectIdForCard === (string)($projectsForDisplay[0]['id'] ?? '');
                $usesGeneratedPaths = devConsoleProjectUsesGeneratedEnvironmentPaths($project);
                $adoptedInPlace = devConsoleProjectAdoptedInPlace($project);
                $setupUnavailableReason = '';
                if (!$usesGeneratedPaths && !$adoptedInPlace) {
                    $setupUnavailableReason = 'This project cannot be set up automatically with its current environment paths.';
                } elseif ($projectManagedServerId === '') {
                    $setupUnavailableReason = 'Assign a Managed Server before setup.';
                } elseif ($projectManagedServer === null) {
                    $setupUnavailableReason = 'The assigned Managed Server does not exist.';
                } elseif ((string)($projectManagedServer['status'] ?? '') !== 'reachable') {
                    $setupUnavailableReason = 'The assigned Managed Server must be reachable before setup.';
                } elseif ((string)($project['production']['domain'] ?? '') === '' || (string)($project['preview']['domain'] ?? '') === '') {
                    $setupUnavailableReason = 'Production and Preview domains are required before setup.';
                }
                $setupActionLabel = match ($statusLabel) {
                    'Setup failed' => 'Retry Setup',
                    'Update required', 'Configuration drift', 'Incomplete' => 'Update Infrastructure',
                    default => 'Set up',
                };
                $showSetupAction = $statusLabel !== 'Ready';
                $canSetUp = $showSetupAction && $setupUnavailableReason === '';
                $gitStatus = $gitStatuses[(string)($project['id'] ?? '')] ?? gitStatus($project, $githubConfiguration);
                $gitStatusClass = gitStatusClassName((string)$gitStatus['status']);
                $gitCanInitialize = in_array($gitStatus['status'], ['NOT INITIALIZED', 'INITIALIZATION INCOMPLETE', 'REMOTE UNAVAILABLE'], true);
                $gitCanFetch = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['INITIALIZATION INCOMPLETE', 'CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
                $gitCanPull = !empty($gitStatus['can_pull']) && in_array($gitStatus['status'], ['CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND'], true);
                $gitCanPush = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['AHEAD', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
                $githubDeletion = gitGithubRepositoryDeletionAvailable($project, $githubConfiguration);
                $githubRepositoryLabel = !empty($githubDeletion['available'])
                    ? ((string)$githubDeletion['owner'] . '/' . (string)$githubDeletion['name'])
                    : '';
                $projectNameForDisplay = projectMessageName($project, $projectIdForCard);
                $showProjectIdInHeader = $projectIdForCard !== '' && $projectNameForDisplay !== $projectIdForCard;
                $managedServerLabel = devConsoleManagedServerLabel($projectManagedServer, $projectManagedServerId);
                $managedServerStatusLabel = devConsoleManagedServerStatusLabel($projectManagedServer);
                $projectServerAddress = (string)($projectManagedServer['host'] ?? '');
                $hostsLine = trim(implode(' ', array_filter([
                    $projectServerAddress,
                    (string)($project['production']['domain'] ?? ''),
                    (string)($project['preview']['domain'] ?? ''),
                ], static fn(string $value): bool => trim($value) !== '')));
              ?>
              <section class="project-item" data-project-card data-project-id="<?= h($projectIdForCard) ?>" data-expanded="<?= $cardOpen ? '1' : '0' ?>">
                <div class="project-summary">
                  <span>
                    <strong><?= h($projectNameForDisplay) ?></strong>
                    <?php if ($showProjectIdInHeader): ?><span class="meta">Project ID: <?= h($projectIdForCard) ?></span><?php endif; ?>
                    <span class="project-summary-line">
                      <span>Production: <?= h(configuredDisplayValue($project['production']['domain'] ?? '')) ?></span>
                      <span>Preview: <?= h(configuredDisplayValue($project['preview']['domain'] ?? '')) ?></span>
                    </span>
                    <span class="project-summary-line">
                      <span>Server: <?= h($managedServerLabel) ?></span>
                      <span>Status: <?= h($managedServerStatusLabel) ?></span>
                    </span>
                  </span>
                  <span class="status-pill <?= h($statusClass) ?>"><?= h($lifecycleLabel) ?></span>
                  <?php if ($isActiveProject): ?><span class="status-pill healthy">CURRENT</span><?php endif; ?>
                  <button type="button" class="secondary project-card-toggle" data-project-toggle aria-expanded="<?= $cardOpen ? 'true' : 'false' ?>"><?= $cardOpen ? 'Hide details' : 'Show details' ?></button>
                </div>
                <div class="project-details"<?= $cardOpen ? '' : ' hidden' ?>>
                <?php if ($cardActionResult !== null): ?>
                  <?php renderOperationResult($cardActionResult, 'projectOperationLog-' . $projectIdForCard, ((string)($cardActionResult['action'] ?? 'project-operation')) . '-' . $projectIdForCard . '.log'); ?>
                  <?php if (!empty($cardActionResult['repository_collision'])): ?>
                    <section class="result-block warning" data-repository-collision-panel>
                      <h4>Repository already exists</h4>
                      <dl class="dashboard-list">
                        <div><dt>Preferred</dt><dd><?= h((string)($cardActionResult['repository_owner'] ?? '') . '/' . (string)($cardActionResult['repository_name'] ?? '')) ?></dd></div>
                        <?php if ((string)($cardActionResult['suggested_repository_name'] ?? '') !== ''): ?>
                          <div><dt>Suggested</dt><dd><?= h((string)($cardActionResult['repository_owner'] ?? '') . '/' . (string)$cardActionResult['suggested_repository_name']) ?></dd></div>
                        <?php endif; ?>
                      </dl>
                      <div class="project-actions">
                        <?php if ((string)($cardActionResult['suggested_repository_name'] ?? '') !== ''): ?>
                          <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                            <input type="hidden" name="action" value="initialize_repository">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                            <input type="hidden" name="repository_name" value="<?= h((string)$cardActionResult['suggested_repository_name']) ?>">
                            <button type="submit">Create <?= h((string)$cardActionResult['suggested_repository_name']) ?></button>
                          </form>
                        <?php endif; ?>
                        <button type="button" class="secondary" data-show-repository-name-choice>Choose another name</button>
                        <button type="button" class="secondary" data-cancel-repository-collision>Cancel</button>
                        <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1" data-repository-name-choice hidden>
                          <input type="hidden" name="action" value="initialize_repository">
                          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                          <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                          <label for="repository_name_retry_<?= h($projectIdForCard) ?>">Manual repository name</label>
                          <input id="repository_name_retry_<?= h($projectIdForCard) ?>" name="repository_name" type="text" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,99}" value="<?= h((string)($cardActionResult['suggested_repository_name'] ?? '')) ?>">
                          <button type="submit" class="secondary">Create chosen repository</button>
                        </form>
                      </div>
                    </section>
                  <?php endif; ?>
                <?php endif; ?>
                <div class="project-item-header">
                  <div>
                    <h3><?= h($projectNameForDisplay) ?></h3>
                    <?php if ($showProjectIdInHeader): ?><p class="meta">Project ID: <?= h($projectIdForCard) ?></p><?php endif; ?>
                  </div>
                  <div class="project-actions">
                    <span class="status-pill <?= h($statusClass) ?>">Infrastructure: <?= h($statusLabel) ?></span>
                    <?php if ($isActiveProject): ?>
                      <span class="status-pill healthy">CURRENT</span>
                    <?php else: ?>
                      <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1" data-project-selection-form>
                        <input type="hidden" name="action" value="select_active_project">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="target_tab" value="projects">
                        <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                        <button type="submit" class="secondary">Open on Dashboard</button>
                      </form>
                    <?php endif; ?>
                    <button type="button" class="secondary" data-project-edit-toggle aria-expanded="false">Edit</button>
                  </div>
                </div>
                <dl class="project-detail-grid">
                  <?php if (!$showProjectIdInHeader): ?><div><dt>Project ID</dt><dd><?= h(configuredDisplayValue($projectIdForCard)) ?></dd></div><?php endif; ?>
                  <div><dt>Repository</dt><dd><?= h(configuredDisplayValue($project['repository_path'] ?? '')) ?></dd></div>
                  <div><dt>Managed Server</dt><dd><?= h($managedServerLabel) ?> · <?= h($managedServerStatusLabel) ?></dd></div>
                  <div><dt>Host</dt><dd><?= h(configuredDisplayValue($projectManagedServer['host'] ?? '')) ?></dd></div>
                  <div><dt>SSH User</dt><dd><?= h(configuredDisplayValue($projectManagedServer['user'] ?? '')) ?></dd></div>
                  <div><dt>Branch</dt><dd><?= h(configuredDisplayValue($project['branch'] ?? '')) ?></dd></div>
                </dl>
                <div class="environment-grid">
                  <?php foreach (['production' => 'Production', 'preview' => 'Preview'] as $environmentKey => $environmentLabel): ?>
                    <?php
                      $environmentStatus = $projectStatus[$environmentKey] ?? [];
                      $environmentReady = !empty($environmentStatus['directory_exists']) && !empty($environmentStatus['vhost_exists']) && !empty($environmentStatus['site_enabled']) && !empty($environmentStatus['server_name_matches']) && !empty($environmentStatus['document_root_matches']);
                      $environmentHasAny = !empty($environmentStatus['directory_exists']) || !empty($environmentStatus['vhost_exists']) || !empty($environmentStatus['site_enabled']);
                      $environmentLabelStatus = in_array($statusLabel, ['Configuration drift', 'Update required', 'Setup failed'], true) ? $statusLabel : ($environmentReady ? 'Ready' : ($environmentHasAny ? 'Incomplete' : 'Not set up'));
                    ?>
                    <section class="environment-block">
                      <h4><?= h($environmentLabel) ?></h4>
                      <dl class="dashboard-list">
                        <div><dt>Domain</dt><dd><?= h(configuredDisplayValue($project[$environmentKey]['domain'] ?? '')) ?></dd></div>
                        <div><dt>Directory</dt><dd><?= h(configuredDisplayValue($project[$environmentKey]['path'] ?? '')) ?></dd></div>
                        <div><dt>Status</dt><dd><?= h($environmentLabelStatus) ?></dd></div>
                      </dl>
                      <details class="compact-details">
                        <summary>Details</summary>
                        <table class="compact-table">
                          <tbody>
                            <tr><th>Directory</th><td><?= !empty($environmentStatus['directory_exists']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Effective DocumentRoot</th><td><code><?= h(configuredDisplayValue($environmentStatus['document_root'] ?? '')) ?></code></td></tr>
                            <tr><th>Vhost</th><td><?= !empty($environmentStatus['vhost_exists']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>Enabled</th><td><?= !empty($environmentStatus['site_enabled']) ? 'Yes' : 'No' ?></td></tr>
                            <tr><th>ServerName</th><td><?= !empty($environmentStatus['server_name_matches']) ? 'OK' : 'Mismatch' ?></td></tr>
                            <tr><th>DocumentRoot</th><td><?= !empty($environmentStatus['document_root_matches']) ? 'OK' : 'Mismatch' ?></td></tr>
                            <tr><th>Routing</th><td><?= h(configuredDisplayValue($environmentStatus['routing_status'] ?? '')) ?></td></tr>
                            <tr><th>Last verified</th><td><?= h(configuredDisplayValue($environmentStatus['routing_verified_at'] ?? '')) ?></td></tr>
                          </tbody>
                        </table>
                      </details>
                    </section>
                  <?php endforeach; ?>
                </div>
                <?php if (!$usesGeneratedPaths && !$adoptedInPlace): ?>
                  <p class="error">This project uses custom environment paths and cannot be set up automatically. Remove it from Console and create it again.</p>
                <?php endif; ?>
                <div class="project-actions">
                  <?php if ($showSetupAction): ?>
                    <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                      <input type="hidden" name="action" value="provision_project">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                      <button type="submit"<?= $canSetUp ? '' : ' disabled title="' . h($setupUnavailableReason) . '"' ?>><?= h($setupActionLabel) ?></button>
                    </form>
                  <?php else: ?>
                    <p class="lifecycle-note">Infrastructure Ready: <?= h(configuredDisplayValue($projectSetupMetadata['timestamp'] ?? ($project['provisioning']['provisioned_at'] ?? ''))) ?></p>
                  <?php endif; ?>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1" onsubmit="return confirm('Remove this project from Dev Console?\nServer files will not be deleted.');">
                    <input type="hidden" name="action" value="remove_project">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                    <button type="submit" class="secondary" aria-label="Remove the project registration from Dev Console. Directories, Apache configuration, and Git repositories remain." title="Remove the project registration from Dev Console. Directories, Apache configuration, and Git repositories remain.">Remove from Console</button>
                  </form>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1" data-delete-project-form="1" data-project-id="<?= h((string)$project['id']) ?>" data-project-name="<?= h(projectMessageName($project, (string)$project['id'])) ?>" data-github-delete-available="<?= !empty($githubDeletion['available']) ? '1' : '0' ?>" data-github-repository="<?= h($githubRepositoryLabel) ?>" data-github-delete-reason="<?= h((string)($githubDeletion['reason'] ?? 'Repository identity cannot be verified.')) ?>">
                    <input type="hidden" name="action" value="delete_project">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h((string)$project['id']) ?>">
                    <input type="hidden" name="confirm_project_name" value="">
                    <input type="hidden" name="github_repository_policy" value="keep">
                    <button type="button" class="danger" data-open-delete-project aria-label="Delete the project registration and Dev Console-managed Project directories and Apache configuration. GitHub repository deletion or preservation is selected during deletion." title="Delete the project registration and Dev Console-managed Project directories and Apache configuration. GitHub repository deletion or preservation is selected during deletion."<?= $isManaged ? '' : ' disabled' ?>>Delete Project</button>
                  </form>
                </div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <aside class="project-sidebar">
      <section class="panel" id="createProject" data-project-create-panel>
          <h2>Create Project</h2>
          <?php if (!empty($projectFormErrors)): ?>
            <section class="result-block error">
              <h2>Project not created</h2>
              <ul>
                <?php foreach ($projectFormErrors as $formError): ?>
                  <li><?= h((string)$formError) ?></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>
          <form method="post" class="project-form" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="create_project">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <fieldset>
              <legend>Project</legend>
              <label for="project_name">Project name</label>
              <input id="project_name" name="project_name" type="text" required maxlength="120" placeholder="Client Website" value="<?= h($projectFormValues['project_name']) ?>">
              <p class="field-help">Used to generate the project ID and server directories.</p>
              <label for="managed_server_id">Managed Server</label>
              <?php if (empty($managedServers)): ?>
                <p class="field-help">No Managed Servers are configured yet. Add one on the <a href="/?tab=servers#add-server">Servers page</a> before creating a Project.</p>
              <?php else: ?>
                <select id="managed_server_id" name="managed_server_id" required>
                  <option value="">Select configured server</option>
                  <?php foreach ($managedServers as $serverOption): ?>
                    <?php $serverOptionId = (string)($serverOption['id'] ?? ''); ?>
                    <option value="<?= h($serverOptionId) ?>"<?= $serverOptionId === (string)$projectFormValues['managed_server_id'] ? ' selected' : '' ?>><?= h(devConsoleManagedServerLabel($serverOption) . ' - ' . devConsoleManagedServerStatusLabel($serverOption)) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="field-help">This records where Preview and Production will live later. It does not deploy anything.</p>
              <?php endif; ?>
            </fieldset>

            <fieldset>
              <legend>Production</legend>
              <label for="production_domain">Domain</label>
              <input id="production_domain" name="production_domain" type="text" required maxlength="253" placeholder="example.com" value="<?= h($projectFormValues['production_domain']) ?>">
              <p class="field-help">Main hostname, without https:// or a path.</p>
            </fieldset>

            <section class="generated-summary">
              <h3>Generated configuration</h3>
              <dl class="generated-preview">
                <div><dt>Project ID</dt><dd id="projectIdPreview">-</dd></div>
                <div><dt>Repository</dt><dd id="repositoryPreview">-</dd></div>
                <div><dt>Production</dt><dd id="productionDomainPreview">-</dd></div>
                <div><dt>Preview</dt><dd id="previewDomainPreview">-</dd></div>
                <div><dt>Production directory</dt><dd id="productionDirectoryPreview">-</dd></div>
                <div><dt>Preview directory</dt><dd id="previewDirectoryPreview">-</dd></div>
              </dl>
            </section>

            <button type="submit"<?= empty($managedServers) ? ' disabled title="Add a Managed Server before creating a Project."' : '' ?>>Create Project</button>
          </form>
      </section>

      <?php foreach ($projectsForDisplay as $project): ?>
        <?php
          $projectIdForCard = (string)($project['id'] ?? '');
          $projectManagedServerId = (string)($project['managed_server_id'] ?? '');
          $projectManagedServer = devConsoleFindManagedServerById($managedServers, $projectManagedServerId);
          $projectNameForDisplay = projectMessageName($project, $projectIdForCard);
          $gitStatus = $gitStatuses[$projectIdForCard] ?? gitStatus($project, $githubConfiguration);
          $gitStatusClass = gitStatusClassName((string)$gitStatus['status']);
          $gitCanInitialize = in_array($gitStatus['status'], ['NOT INITIALIZED', 'INITIALIZATION INCOMPLETE', 'REMOTE UNAVAILABLE'], true);
          $gitCanFetch = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['INITIALIZATION INCOMPLETE', 'CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
          $gitCanPull = !empty($gitStatus['can_pull']) && in_array($gitStatus['status'], ['CONNECTED', 'CHANGES PRESENT', 'AHEAD', 'BEHIND', 'AHEAD / BEHIND'], true);
          $gitCanPush = !empty($gitStatus['can_fetch']) && in_array($gitStatus['status'], ['AHEAD', 'AHEAD / BEHIND', 'REMOTE UNAVAILABLE'], true);
          $projectServerAddress = (string)($projectManagedServer['host'] ?? '');
          $hostsLine = trim(implode(' ', array_filter([
              $projectServerAddress,
              (string)($project['production']['domain'] ?? ''),
              (string)($project['preview']['domain'] ?? ''),
          ], static fn(string $value): bool => trim($value) !== '')));
        ?>
        <section class="panel" data-project-edit-panel data-project-edit-id="<?= h($projectIdForCard) ?>" hidden>
          <h2>Edit Project</h2>
          <form method="post" class="project-form" action="/?tab=projects#projects">
            <input type="hidden" name="action" value="update_project">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
            <fieldset>
              <legend><?= h($projectNameForDisplay) ?></legend>
              <label for="project_name_<?= h($projectIdForCard) ?>">Project name</label>
              <input id="project_name_<?= h($projectIdForCard) ?>" name="project_name" type="text" required maxlength="120" value="<?= h((string)($project['name'] ?? '')) ?>">
              <label for="production_domain_<?= h($projectIdForCard) ?>">Production domain</label>
              <input id="production_domain_<?= h($projectIdForCard) ?>" name="production_domain" type="text" required maxlength="253" value="<?= h((string)($project['production']['domain'] ?? '')) ?>">
              <label for="managed_server_id_<?= h($projectIdForCard) ?>">Managed Server</label>
              <select id="managed_server_id_<?= h($projectIdForCard) ?>" name="managed_server_id">
                <option value="">Not assigned</option>
                <?php foreach ($managedServers as $serverOption): ?>
                  <?php $serverOptionId = (string)($serverOption['id'] ?? ''); ?>
                  <option value="<?= h($serverOptionId) ?>"<?= $serverOptionId === $projectManagedServerId ? ' selected' : '' ?>><?= h(devConsoleManagedServerLabel($serverOption) . ' - ' . devConsoleManagedServerStatusLabel($serverOption)) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="field-help">Changing the Managed Server only updates Project configuration. It does not deploy, copy files, or run SSH commands.</p>
            </fieldset>
            <div class="project-actions">
              <button type="submit">Save Project</button>
              <button type="button" class="secondary" data-project-edit-cancel>Cancel</button>
            </div>
          </form>
          <section class="generated-summary subsection">
            <h3>Generated configuration</h3>
            <dl class="generated-preview">
              <div><dt>Project ID</dt><dd><?= h(configuredDisplayValue($projectIdForCard)) ?></dd></div>
              <div><dt>Repository</dt><dd><?= h(configuredDisplayValue($project['repository_path'] ?? '')) ?></dd></div>
              <div><dt>Production domain</dt><dd><?= h(configuredDisplayValue($project['production']['domain'] ?? '')) ?></dd></div>
              <div><dt>Preview domain</dt><dd><?= h(configuredDisplayValue($project['preview']['domain'] ?? '')) ?></dd></div>
              <div><dt>Production directory</dt><dd><?= h(configuredDisplayValue($project['production']['path'] ?? '')) ?></dd></div>
              <div><dt>Preview directory</dt><dd><?= h(configuredDisplayValue($project['preview']['path'] ?? '')) ?></dd></div>
            </dl>
          </section>
        </section>

        <section class="panel project-side-panel" data-project-side-panel data-project-side-id="<?= h($projectIdForCard) ?>"<?= $projectIdForCard === (string)($projectsForDisplay[0]['id'] ?? '') ? '' : ' hidden' ?>>
          <section class="environment-block">
            <h4>Git</h4>
            <table class="compact-table">
              <tbody>
                <tr><th>Status</th><td><span class="status-pill <?= h($gitStatusClass) ?>"><?= h($gitStatus['status']) ?></span></td></tr>
                <tr><th>Repository path</th><td><?= h(configuredDisplayValue($gitStatus['repository_path'] ?? '')) ?></td></tr>
                <tr><th>Remote</th><td><?= h(configuredDisplayValue($gitStatus['remote_url'] ?? '')) ?></td></tr>
                <tr><th>Branch</th><td><?= h(configuredDisplayValue($gitStatus['branch'] ?? '')) ?></td></tr>
                <tr><th>Local commit</th><td><?= h(configuredDisplayValue($gitStatus['subject'] ?? '')) ?><?= ($gitStatus['local_commit'] ?? '') !== '' ? '<br><span class="meta">' . h(substr((string)$gitStatus['local_commit'], 0, 12)) . '</span>' : '' ?></td></tr>
                <tr><th>Remote commit</th><td><?= h(($gitStatus['remote_commit'] ?? '') !== '' ? substr((string)$gitStatus['remote_commit'], 0, 12) : 'Not verified') ?></td></tr>
                <tr><th>Working tree</th><td><?= h(configuredDisplayValue($gitStatus['working_tree'] ?? '')) ?></td></tr>
                <tr><th>Ahead / Behind</th><td><?= h(($gitStatus['ahead'] ?? null) === null || ($gitStatus['behind'] ?? null) === null ? 'Not available' : ((string)$gitStatus['ahead'] . ' / ' . (string)$gitStatus['behind'])) ?></td></tr>
                <tr><th>Last remote verification</th><td><?= h(configuredDisplayValue($gitStatus['remote_verified_at'] ?? '')) ?></td></tr>
                <tr><th>Last fetch</th><td><?= h(configuredDisplayValue($gitStatus['last_fetch_at'] ?? '')) ?></td></tr>
                <tr><th>Last pull</th><td><?= h(configuredDisplayValue($gitStatus['last_pull_at'] ?? '')) ?></td></tr>
              </tbody>
            </table>
            <?php if (($gitStatus['diagnostic'] ?? '') !== ''): ?>
              <p class="field-help"><?= h((string)$gitStatus['diagnostic']) ?></p>
            <?php endif; ?>
            <?php if ($gitStatus['status'] === 'GitHub not configured'): ?>
              <p class="field-help">Configure GitHub in Settings to create this repository.</p>
            <?php elseif ($gitStatus['status'] !== 'INVALID REPOSITORY'): ?>
              <div class="project-actions">
                <?php if ($gitCanInitialize): ?>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                    <input type="hidden" name="action" value="initialize_repository">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                    <?php if ((string)($project['git']['repository_name'] ?? '') !== ''): ?>
                      <input type="hidden" name="repository_name" value="<?= h((string)$project['git']['repository_name']) ?>">
                    <?php endif; ?>
                    <button type="submit"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub in Settings before initializing repositories."' ?>><?= $gitStatus['status'] === 'NOT INITIALIZED' ? 'Initialize Repository' : ($gitStatus['status'] === 'REMOTE UNAVAILABLE' ? 'Retry remote verification' : 'Retry Initialization') ?></button>
                  </form>
                <?php endif; ?>
                <?php if ($gitCanFetch): ?>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                    <input type="hidden" name="action" value="fetch_git_repository">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                    <button type="submit" class="secondary"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub before network Git actions."' ?>>Fetch</button>
                  </form>
                <?php endif; ?>
                <?php if ($gitCanPull): ?>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                    <input type="hidden" name="action" value="pull_git_repository">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                    <button type="submit" class="secondary"<?= $githubConfigured && ($gitStatus['pull_disabled_reason'] ?? '') === '' ? '' : ' disabled title="' . h((string)($gitStatus['pull_disabled_reason'] ?: 'Configure GitHub before network Git actions.')) . '"' ?>>Pull</button>
                  </form>
                <?php endif; ?>
                <?php if ($gitCanPush): ?>
                  <form method="post" action="/?tab=projects#projects" data-preserve-settings-scroll="1">
                    <input type="hidden" name="action" value="push_git_repository">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="project_id" value="<?= h($projectIdForCard) ?>">
                    <button type="submit" class="secondary"<?= $githubConfigured ? '' : ' disabled title="Configure GitHub before network Git actions."' ?>>Push</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </section>
          <section class="environment-block">
            <h4>Local DNS testing</h4>
            <?php if ($projectServerAddress !== '' && $hostsLine !== ''): ?>
              <p class="field-help">If DNS is not configured yet, add this line to your local hosts file:</p>
              <div class="hosts-copy-row">
                <p class="local-hosts" id="hostsLine-<?= h($projectIdForCard) ?>"><?= h($hostsLine) ?></p>
                <button type="button" class="secondary" data-copy-log="hostsLine-<?= h($projectIdForCard) ?>">Copy</button>
              </div>
            <?php else: ?>
              <p class="field-help">Server address is not available.</p>
            <?php endif; ?>
          </section>
        </section>
      <?php endforeach; ?>
      </aside>

    </div>
    <section class="panel project-adoption-panel" id="addExistingProject">
        <h2>Add Existing Project</h2>
        <p class="field-help">Scan a Managed Server for existing Apache websites and project sources, inspect a candidate, then review and confirm adoption. Scan and inspect are read-only.</p>
        <form method="post" class="project-form project-adoption-scan-form" action="/?tab=projects&amp;adoption=1#addExistingProject" data-preserve-settings-scroll="1">
          <input type="hidden" name="action" value="scan_existing_projects">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <fieldset>
            <legend>Server scan</legend>
            <div class="project-adoption-scan-row">
              <div>
                <label for="existing_scan_managed_server_id">Managed Server</label>
                <?php if (empty($managedServers)): ?>
                  <p class="field-help">No Managed Servers are configured yet. Add one on the <a href="/?tab=servers#add-server">Servers page</a> before scanning.</p>
                <?php else: ?>
                  <select id="existing_scan_managed_server_id" name="managed_server_id" required>
                    <option value="">Select configured server</option>
                    <?php foreach ($managedServers as $serverOption): ?>
                      <?php $serverOptionId = (string)($serverOption['id'] ?? ''); ?>
                      <option value="<?= h($serverOptionId) ?>"<?= $serverOptionId === (string)$existingProjectScanValues['managed_server_id'] ? ' selected' : '' ?>><?= h(devConsoleManagedServerLabel($serverOption) . ' - ' . devConsoleManagedServerStatusLabel($serverOption)) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </div>
              <button type="submit"<?= empty($managedServers) ? ' disabled title="Add a Managed Server before scanning for existing Projects."' : '' ?>>Scan Server</button>
            </div>
          </fieldset>
        </form>
        <?php if ($existingProjectScanResult !== null): ?>
          <?php renderProjectAdoptionScanResult($existingProjectScanResult, $csrfToken); ?>
        <?php endif; ?>
        <?php if ($existingProjectAdoptionResult !== null): ?>
          <?php renderProjectAdoptionActionResult($existingProjectAdoptionResult); ?>
        <?php endif; ?>
        <details class="compact-details project-adoption-manual"<?= $existingProjectDiscoverySource === 'manual' ? ' open' : '' ?>>
          <summary>Add manually</summary>
          <form method="post" class="project-form" action="/?tab=projects&amp;adoption=1#addExistingProject" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="discover_existing_project">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="discovery_source" value="manual">

            <fieldset>
              <legend>Existing site</legend>
              <div class="project-adoption-form-grid">
                <div>
                  <label for="existing_project_name">Project name</label>
                  <input id="existing_project_name" name="project_name" type="text" required maxlength="120" placeholder="Existing Website" value="<?= h($existingProjectDiscoveryValues['project_name']) ?>">
                </div>
                <div>
                  <label for="existing_managed_server_id">Managed Server</label>
                  <?php if (empty($managedServers)): ?>
                    <p class="field-help">No Managed Servers are configured yet. Add one on the <a href="/?tab=servers#add-server">Servers page</a> before discovery.</p>
                  <?php else: ?>
                    <select id="existing_managed_server_id" name="managed_server_id" required>
                      <option value="">Select configured server</option>
                      <?php foreach ($managedServers as $serverOption): ?>
                        <?php $serverOptionId = (string)($serverOption['id'] ?? ''); ?>
                        <option value="<?= h($serverOptionId) ?>"<?= $serverOptionId === (string)$existingProjectDiscoveryValues['managed_server_id'] ? ' selected' : '' ?>><?= h(devConsoleManagedServerLabel($serverOption) . ' - ' . devConsoleManagedServerStatusLabel($serverOption)) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php endif; ?>
                </div>
                <div>
                  <label for="existing_production_domain">Production domain</label>
                  <input id="existing_production_domain" name="production_domain" type="text" required maxlength="253" placeholder="example.com" value="<?= h($existingProjectDiscoveryValues['production_domain']) ?>">
                  <p class="field-help">Use the hostname without https:// or a path.</p>
                </div>
                <div>
                  <label for="existing_production_path">Production path <span class="meta">(optional)</span></label>
                  <input id="existing_production_path" name="production_path" type="text" maxlength="4096" placeholder="/var/www/example/current" value="<?= h($existingProjectDiscoveryValues['production_path']) ?>">
                  <p class="field-help">Optional. Use this when Apache discovery is ambiguous or the path is already known.</p>
                </div>
              </div>
            </fieldset>

            <div class="project-adoption-manual-actions">
              <button type="submit"<?= empty($managedServers) ? ' disabled title="Add a Managed Server before discovering an existing Project."' : '' ?>>Discover Project</button>
            </div>
          </form>
        </details>
        <?php if ($existingProjectDiscoveryResult !== null): ?>
          <?php renderProjectAdoptionResult($existingProjectDiscoveryResult, $csrfToken); ?>
        <?php endif; ?>
    </section>
  </section>

  <section id="serversTab" data-tab-panel="servers"<?= $initialTab === 'servers' ? '' : ' hidden' ?>>
    <div class="server-layout">
      <section class="panel" id="managed-servers">
        <div class="dashboard-header">
          <h2>Managed Servers</h2>
          <span class="meta"><?= h((string)count($managedServers)) ?> configured</span>
        </div>
        <?php if ($managedServerActionResult !== null): ?>
          <section class="result-block <?= !empty($managedServerActionResult['success']) ? '' : 'error' ?>">
            <h2><?= !empty($managedServerActionResult['success']) ? 'Server configuration saved' : 'Server configuration failed' ?></h2>
            <p><?= h((string)($managedServerActionResult['message'] ?? '')) ?></p>
            <?php if (trim((string)($managedServerActionResult['output'] ?? '')) !== ''): ?>
              <pre class="tool-operation-log"><?= h(trim((string)$managedServerActionResult['output'])) ?></pre>
            <?php endif; ?>
          </section>
        <?php endif; ?>
        <section class="result-block tool-operation-panel" id="managedServerOperationPanel" data-managed-server-operation-panel="servers" hidden>
          <h2 id="managedServerOperationTitle" data-managed-server-field="title">Managed server operation</h2>
          <p id="managedServerOperationMessage" data-managed-server-field="message">Starting...</p>
          <dl class="tool-operation-grid">
            <div><dt>Server</dt><dd id="managedServerOperationServer" data-managed-server-field="server">-</dd></div>
            <div><dt>Status</dt><dd id="managedServerOperationStatus" data-managed-server-field="status">Starting</dd></div>
            <div><dt>Stage</dt><dd id="managedServerOperationStage" data-managed-server-field="stage">Starting</dd></div>
            <div><dt>Elapsed</dt><dd id="managedServerOperationElapsed" data-managed-server-field="elapsed">0s</dd></div>
          </dl>
          <dl class="apache-summary-grid" id="managedServerConnectionDetails" data-managed-server-field="details" hidden>
            <div><dt>SSH access</dt><dd id="managedServerResultSshAccess" data-managed-server-field="ssh_access">-</dd></div>
            <div><dt>Passwordless sudo</dt><dd id="managedServerResultSudo" data-managed-server-field="sudo">-</dd></div>
            <div><dt>Hostname</dt><dd id="managedServerResultHostname" data-managed-server-field="hostname">-</dd></div>
            <div><dt>OS</dt><dd id="managedServerResultOs" data-managed-server-field="os">-</dd></div>
            <div><dt>Kernel</dt><dd id="managedServerResultKernel" data-managed-server-field="kernel">-</dd></div>
            <div><dt>Current user</dt><dd id="managedServerResultUser" data-managed-server-field="user">-</dd></div>
            <div><dt>Working directory</dt><dd id="managedServerResultWorkingDirectory" data-managed-server-field="working_directory">-</dd></div>
            <div><dt>Round-trip time</dt><dd id="managedServerResultRtt" data-managed-server-field="rtt">-</dd></div>
          </dl>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="managedServerLiveLog">Copy Log</button>
            <button type="button" class="secondary" data-download-log="managedServerLiveLog" data-download-name="managed-server-connection.log">Download Log</button>
            <span class="hint" data-log-message="managedServerLiveLog" aria-live="polite"></span>
          </div>
          <pre id="managedServerLiveLog" class="tool-operation-log" data-managed-server-field="log">Waiting for operation log...</pre>
        </section>

        <?php if (empty($managedServers)): ?>
          <p class="meta">No managed servers configured yet.</p>
        <?php else: ?>
          <div class="project-list">
            <?php foreach ($managedServers as $server): ?>
              <?php
	                $serverId = (string)($server['id'] ?? '');
	                $serverUsesSharedKey = (string)($server['key'] ?? '') === (string)($managedServerSharedKey['path'] ?? '');
	                $serverKeyLabel = $serverUsesSharedKey ? 'Dev Console Server Key' : 'Custom SSH Key';
	                $serverTestDisabledReason = $serverUsesSharedKey && empty($managedServerSharedKey['generated']) ? 'Generate the Dev Console Server SSH Key before testing this server.' : '';
	                $serverHost = configuredDisplayValue($server['host'] ?? '');
	                $serverRemoteHostname = (string)($server['remote_hostname'] ?? '');
	                $serverHostDetail = $serverRemoteHostname !== '' && strcasecmp($serverRemoteHostname, (string)($server['host'] ?? '')) !== 0
	                    ? configuredDisplayValue($server['host'] ?? '') . ' / ' . $serverRemoteHostname
	                    : configuredDisplayValue($server['host'] ?? '');
	                $serverUser = configuredDisplayValue($server['user'] ?? '');
	                $serverRemoteUser = (string)($server['remote_user'] ?? '');
	                $serverUserDetailLabel = $serverRemoteUser !== '' && $serverRemoteUser !== (string)($server['user'] ?? '') ? 'SSH User / Remote user' : 'SSH User';
	                $serverUserDetail = $serverRemoteUser !== '' && $serverRemoteUser !== (string)($server['user'] ?? '')
	                    ? configuredDisplayValue($server['user'] ?? '') . ' / ' . $serverRemoteUser
	                    : configuredDisplayValue($server['user'] ?? '');
	                $serverSudoState = (string)($server['passwordless_sudo'] ?? 'unknown');
	                $serverSudoLabel = match ($serverSudoState) {
	                    'ready', 'root' => 'Ready',
	                    'setup_required' => 'Setup required',
	                    default => 'Unknown',
	                };
	                $serverSshAccessLabel = match ((string)($server['status'] ?? 'never_tested')) {
	                    'reachable' => 'Ready',
	                    'unreachable' => 'Not ready',
	                    default => 'Not tested',
	                };
	                $serverPhpInstalled = !empty($server['php_installed']);
	                $serverComposerInstalled = !empty($server['composer_installed']);
	              ?>
	              <section class="project-item" data-server-card data-server-id="<?= h($serverId) ?>" data-server-host="<?= h((string)($server['host'] ?? '')) ?>" data-server-user="<?= h((string)($server['user'] ?? '')) ?>">
	                <div class="project-summary server-compact-summary">
	                  <span>
	                    <strong><?= h(configuredDisplayValue($server['name'] ?? '')) ?></strong>
	                  </span>
		                  <span class="status-pill <?= h(managedServersStatusClass($server)) ?>" data-server-card-status><?= h(managedServersStatusLabel($server)) ?></span>
		                  <span>Host: <span data-server-card-host><?= h($serverHost) ?></span></span>
		                  <span>Last checked: <span data-server-card-last-checked><?= h(configuredDisplayValue($server['last_connection_test_at'] ?? '')) ?></span></span>
		                  <span>Response: <span data-server-card-response><?= h(($server['response_time_ms'] ?? null) === null ? 'Not configured' : ((string)$server['response_time_ms'] . ' ms')) ?></span></span>
                  <div class="project-actions">
                    <form method="post" action="/?tab=servers#managed-servers" data-managed-server-test-form="1" data-server-id="<?= h($serverId) ?>" data-server-name="<?= h(configuredDisplayValue($server['name'] ?? '')) ?>">
                      <input type="hidden" name="action" value="test_managed_server">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="server_id" value="<?= h($serverId) ?>">
                      <button type="submit"<?= $serverTestDisabledReason === '' ? '' : ' disabled title="' . h($serverTestDisabledReason) . '"' ?>>Test Connection</button>
                    </form>
                    <button type="button" class="secondary project-card-toggle" data-project-toggle aria-expanded="false">Show details</button>
	                    <button type="button" class="secondary" data-server-edit-toggle data-server-id="<?= h($serverId) ?>" aria-expanded="false">Select/Edit</button>
                    <form method="post" action="/?tab=servers#managed-servers" onsubmit="return confirm('Remove this managed server from Dev Console? SSH keys and remote data will not be deleted.');">
                      <input type="hidden" name="action" value="remove_managed_server">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="server_id" value="<?= h($serverId) ?>">
                      <button type="submit" class="secondary">Remove</button>
                    </form>
                  </div>
                </div>
	                <div class="project-details" hidden>
	                  <dl class="server-detail-grid">
	                    <div><dt>Server ID</dt><dd><?= h(configuredDisplayValue($server['id'] ?? '')) ?></dd></div>
	                    <div><dt>Display name</dt><dd><?= h(configuredDisplayValue($server['name'] ?? '')) ?></dd></div>
		                    <div><dt>Host / hostname</dt><dd data-server-detail-host><?= h($serverHostDetail) ?></dd></div>
		                    <div><dt data-server-detail-user-label><?= h($serverUserDetailLabel) ?></dt><dd data-server-detail-user><?= h($serverUserDetail) ?></dd></div>
		                    <div><dt>SSH Port</dt><dd><?= h((string)((int)($server['port'] ?? 22))) ?></dd></div>
		                    <div><dt>SSH Key</dt><dd><?= h($serverKeyLabel) ?></dd></div>
		                    <div><dt>Status</dt><dd><span class="status-pill <?= h(managedServersStatusClass($server)) ?>" data-server-detail-status><?= h(managedServersStatusLabel($server)) ?></span><?= (string)($server['last_error'] ?? '') !== '' ? '<br><span class="meta" data-server-detail-error>' . h((string)$server['last_error']) . '</span>' : '<br><span class="meta" data-server-detail-error></span>' ?></dd></div>
		                    <div><dt>SSH access</dt><dd data-server-detail-ssh-access><?= h($serverSshAccessLabel) ?></dd></div>
		                    <div><dt>Passwordless sudo</dt><dd data-server-detail-sudo><?= h($serverSudoLabel) ?></dd></div>
		                    <div><dt>PHP</dt><dd data-server-detail-php><?= $serverPhpInstalled ? 'Installed' . ((string)($server['php_version'] ?? '') !== '' ? '<br><span class="meta">' . h((string)$server['php_version']) . '</span>' : '') : 'Missing' ?></dd></div>
		                    <div><dt>Composer</dt><dd data-server-detail-composer><?= $serverComposerInstalled ? 'Installed' . ((string)($server['composer_version'] ?? '') !== '' ? '<br><span class="meta">' . h((string)$server['composer_version']) . '</span>' : '') : 'Missing' ?></dd></div>
		                    <div><dt>Last checked</dt><dd data-server-detail-last-checked><?= h(configuredDisplayValue($server['last_connection_test_at'] ?? '')) ?></dd></div>
		                    <div><dt>Response time</dt><dd data-server-detail-response><?= h(($server['response_time_ms'] ?? null) === null ? 'Not configured' : ((string)$server['response_time_ms'] . ' ms')) ?></dd></div>
		                    <div><dt>Working directory</dt><dd data-server-detail-working-directory><?= h(configuredDisplayValue($server['remote_working_directory'] ?? '')) ?></dd></div>
	                  </dl>
	                </div>
              </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

	      <aside class="server-sidebar" id="server-sidebar">
	        <section class="dashboard-card server-key-compact" id="server-ssh-key">
	          <div class="dashboard-header">
	            <h3>Configure SSH access to your server</h3>
	          </div>
	          <?php if (empty($managedServerSharedKey['generated'])): ?>
	            <p class="field-help">Dev Console needs one SSH key to connect to Managed Servers.</p>
	            <form method="post" action="/?tab=servers#server-ssh-key">
	              <input type="hidden" name="action" value="generate_managed_server_key">
	              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
	              <button type="submit">Generate SSH Key</button>
	            </form>
	          <?php else: ?>
	            <?php
	              $setupCommandUser = (string)$managedServerFormValues['user'];
	              $setupCommand = managedServersSetupCommand((string)$managedServerSharedKey['public_key'], $setupCommandUser);
	            ?>
	            <ol class="field-help">
	              <li>Log in as the SSH user you want Dev Console to use.</li>
	              <li>Run the setup command below.</li>
	              <li>Enter your sudo password if prompted.</li>
	              <li>Add the server and test the connection.</li>
	            </ol>
	            <div class="copy-field">
	              <label for="serverSetupCommand">Setup command</label>
	              <div class="copy-row">
	                <textarea id="serverSetupCommand" class="readonly-code-field setup-command-field" readonly aria-readonly="true" spellcheck="false" data-server-public-key="<?= h((string)$managedServerSharedKey['public_key']) ?>"><?= h($setupCommand !== '' ? $setupCommand : 'Select an existing Managed Server to generate the setup command for that server.') ?></textarea>
	                <button type="button" class="secondary" data-copy-log="serverSetupCommand" aria-label="Copy setup command" title="Copy setup command">Copy</button>
	              </div>
	              <span class="hint" data-log-message="serverSetupCommand" aria-live="polite"></span>
	            </div>
	            <details class="compact-details subsection">
	              <summary>Advanced</summary>
	              <div class="copy-field">
	                <label for="serverPublicKey">Public key</label>
	                <div class="copy-row">
	                  <textarea id="serverPublicKey" class="readonly-code-field public-key-field" readonly aria-readonly="true" spellcheck="false"><?= h((string)$managedServerSharedKey['public_key']) ?></textarea>
	                  <button type="button" class="secondary" data-copy-log="serverPublicKey" aria-label="Copy public key" title="Copy public key">Copy</button>
	                </div>
	              </div>
	            </details>
	          <?php endif; ?>
	        </section>

	      <section class="panel" id="add-server" data-server-add-panel>
	        <h2>Add Server</h2>
	        <?php if (!empty($managedServerFormErrors)): ?>
          <section class="result-block error">
            <h2>Server not saved</h2>
            <ul>
              <?php foreach ($managedServerFormErrors as $serverError): ?>
                <li><?= h((string)$serverError) ?></li>
              <?php endforeach; ?>
            </ul>
          </section>
        <?php endif; ?>
        <form method="post" class="project-form" action="/?tab=servers#add-server" data-managed-server-form="1">
          <input type="hidden" name="action" value="save_managed_server">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <fieldset>
            <legend>Server</legend>
            <label for="server_id">Server ID</label>
	            <input id="server_id" name="server_id" type="text" required maxlength="80" placeholder="my-server" value="<?= h((string)$managedServerFormValues['id']) ?>">
	            <label for="server_name">Display name</label>
	            <input id="server_name" name="server_name" type="text" required maxlength="120" placeholder="My Server" value="<?= h((string)$managedServerFormValues['name']) ?>">
	            <label for="server_host">Hostname / IP</label>
	            <input id="server_host" name="server_host" type="text" required maxlength="253" placeholder="10.0.0.1" value="<?= h((string)$managedServerFormValues['host']) ?>">
            <label for="server_port">SSH port</label>
            <input id="server_port" name="server_port" type="text" required inputmode="numeric" value="<?= h((string)($managedServerFormValues['port'] ?: 22)) ?>">
            <label for="server_user">SSH username</label>
	            <input id="server_user" name="server_user" type="text" required maxlength="64" placeholder="deploy" value="<?= h((string)$managedServerFormValues['user']) ?>">
	            <label>SSH Key</label>
	            <p><strong>Dev Console Server Key</strong></p>
	          </fieldset>
          <button type="submit"<?= !empty($managedServerSharedKey['generated']) ? '' : ' disabled title="Generate the Dev Console Server SSH Key before adding new servers."' ?>>Add Server</button>
	        </form>
	      </section>
	        <?php foreach ($managedServers as $server): ?>
	          <?php
	            $serverId = (string)($server['id'] ?? '');
	            $serverUsesSharedKey = (string)($server['key'] ?? '') === (string)($managedServerSharedKey['path'] ?? '');
	            $serverKeyLabel = $serverUsesSharedKey ? 'Dev Console Server Key' : 'Custom SSH Key';
	          ?>
	          <section class="panel" data-server-edit-panel data-server-edit-id="<?= h($serverId) ?>" hidden>
	            <div class="dashboard-header">
	              <h2>Edit Server</h2>
	              <button type="button" class="secondary" data-server-edit-cancel>Cancel</button>
	            </div>
	            <form method="post" class="project-form" action="/?tab=servers#managed-servers" data-managed-server-form="1">
	              <input type="hidden" name="action" value="save_managed_server">
	              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
	              <input type="hidden" name="existing_server_id" value="<?= h($serverId) ?>">
	              <fieldset>
	                <legend>Server</legend>
	                <label for="server_id_<?= h($serverId) ?>">Server ID</label>
	                <input id="server_id_<?= h($serverId) ?>" name="server_id" type="text" required maxlength="80" value="<?= h($serverId) ?>">
	                <label for="server_name_<?= h($serverId) ?>">Display name</label>
	                <input id="server_name_<?= h($serverId) ?>" name="server_name" type="text" required maxlength="120" value="<?= h((string)$server['name']) ?>">
	                <label for="server_host_<?= h($serverId) ?>">Hostname / IP</label>
	                <input id="server_host_<?= h($serverId) ?>" name="server_host" type="text" required maxlength="253" value="<?= h((string)$server['host']) ?>">
	                <label for="server_port_<?= h($serverId) ?>">SSH port</label>
	                <input id="server_port_<?= h($serverId) ?>" name="server_port" type="text" required inputmode="numeric" value="<?= h((string)((int)$server['port'])) ?>">
	                <label for="server_user_<?= h($serverId) ?>">SSH username</label>
	                <input id="server_user_<?= h($serverId) ?>" name="server_user" type="text" required maxlength="64" value="<?= h((string)$server['user']) ?>">
	                <label>SSH Key</label>
	                <p><strong><?= h($serverKeyLabel) ?></strong></p>
	                <?php if ($serverUsesSharedKey): ?>
	                  <details class="compact-details">
	                    <summary>Advanced</summary>
	                    <p class="field-help">This server uses the Dev Console Server Key.</p>
	                  </details>
	                <?php else: ?>
	                  <details class="compact-details">
	                    <summary>Advanced</summary>
	                    <p class="field-help"><code><?= h(configuredDisplayValue($server['key'] ?? '')) ?></code></p>
	                  </details>
	                  <button type="submit" name="use_shared_server_key" value="1" class="secondary"<?= !empty($managedServerSharedKey['generated']) ? '' : ' disabled title="Generate the Dev Console Server SSH Key first."' ?>>Use Dev Console Server Key</button>
	                <?php endif; ?>
	              </fieldset>
	              <div class="server-form-actions">
	                <button type="submit">Save</button>
	                <button type="button" class="secondary" data-server-edit-cancel>Cancel</button>
	              </div>
	            </form>
	          </section>
	        <?php endforeach; ?>
	      </aside>
	    </div>
	  </section>

  <section id="documentationTab" data-tab-panel="documentation"<?= $initialTab === 'documentation' ? '' : ' hidden' ?>>
    <div class="documentation-layout">
      <aside class="panel documentation-nav" aria-label="Documentation navigation">
        <h3>Help</h3>
        <?php foreach ($documentationUserSections as $docSlug => $docSection): ?>
          <a href="/?tab=documentation&doc=<?= h((string)$docSlug) ?>" class="<?= $documentationSlug === (string)$docSlug ? 'active' : '' ?>"><?= h((string)$docSection['title']) ?></a>
        <?php endforeach; ?>
        <h3>Technical Reference</h3>
        <?php foreach ($documentationTechnicalSections as $docSlug => $docSection): ?>
          <a href="/?tab=documentation&doc=<?= h((string)$docSlug) ?>" class="<?= $documentationSlug === (string)$docSlug ? 'active' : '' ?>"><?= h((string)$docSection['title']) ?></a>
        <?php endforeach; ?>
      </aside>
      <article class="panel documentation-content">
        <h2><?= h($documentationTitle) ?></h2>
        <?= $documentationHtml ?>
      </article>
    </div>
  </section>

  <section id="settingsTab" data-tab-panel="settings"<?= $initialTab === 'settings' ? '' : ' hidden' ?>>
    <div class="settings-layout">
      <section class="panel" id="runtime">
        <h2>Dev Console Runtime</h2>
        <?php if ($runtimeActionResult !== null): ?>
          <section class="result-block <?= !empty($runtimeActionResult['success']) ? '' : 'error' ?>">
            <h2><?= !empty($runtimeActionResult['success']) ? 'Runtime settings saved' : 'Runtime settings failed' ?></h2>
            <p><?= h((string)$runtimeActionResult['message']) ?></p>
          </section>
        <?php endif; ?>
        <dl class="apache-summary-grid">
          <div><dt>Configured attachment limit</dt><dd><?= h((string)$runtimeSettings['attachment_limit_mb']) ?> MB</dd></div>
          <div><dt>Configured request limit</dt><dd><?= h((string)$runtimeSettings['request_limit_mb']) ?> MB</dd></div>
          <div><dt>Effective attachment limit</dt><dd><?= h((string)$runtimeEffectiveLimits['attachment_limit_mb']) ?> MB <span class="meta">(<?= h((string)$runtimeEffectiveLimits['attachment_ini']) ?>)</span></dd></div>
          <div><dt>Effective request limit</dt><dd><?= h((string)$runtimeEffectiveLimits['request_limit_mb']) ?> MB <span class="meta">(<?= h((string)$runtimeEffectiveLimits['request_ini']) ?>)</span></dd></div>
          <div><dt>Maximum files</dt><dd><?= h((string)$runtimeEffectiveLimits['max_file_uploads']) ?></dd></div>
          <div><dt>Runtime unit</dt><dd><?= $runtimeServiceUsesWrapper ? 'Managed wrapper installed' : 'Unit update required' ?></dd></div>
          <div><dt>Restart</dt><dd><?= ($runtimeRestartRequired || !$runtimeServiceUsesWrapper) ? 'Required' : 'Not required' ?></dd></div>
        </dl>
        <form method="post" class="project-form runtime-settings-form" action="/?tab=settings#runtime" data-preserve-settings-scroll="1">
          <input type="hidden" name="action" value="save_runtime_settings">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <div class="runtime-limit-row">
            <div>
              <label for="attachment_limit_mb">Maximum attachment size</label>
              <input id="attachment_limit_mb" name="attachment_limit_mb" type="number" min="1" max="100" step="1" required value="<?= h((string)$runtimeSettings['attachment_limit_mb']) ?>">
              <p class="field-help">MB, allowed range 1-100.</p>
            </div>
            <div>
              <label for="request_limit_mb">Maximum request size</label>
              <input id="request_limit_mb" name="request_limit_mb" type="number" min="1" max="200" step="1" required value="<?= h((string)$runtimeSettings['request_limit_mb']) ?>">
              <p class="field-help">MB, allowed range 1-200. Must be greater than or equal to the attachment limit.</p>
            </div>
          </div>
          <button type="submit">Apply Settings</button>
        </form>
        <?php if ($runtimeRestartRequired || !$runtimeServiceUsesWrapper): ?>
          <p class="field-help runtime-note">Saved values apply after the Dev Console PHP runtime restarts. <?= h($runtimeApplyInstruction) ?></p>
        <?php else: ?>
          <p class="field-help runtime-note">These limits apply only to the Dev Console PHP runtime on this host.</p>
        <?php endif; ?>
      </section>
      <section class="panel" id="dev-console-host">
        <div class="dashboard-header">
          <h2>Dev Console Host</h2>
          <span class="meta">The host where this console runs</span>
        </div>
        <dl class="apache-summary-grid host-summary-grid">
          <div><dt>Service</dt><dd><?= h(configuredDisplayValue($serverContext['service_name'] ?? '')) ?></dd></div>
          <div><dt>User</dt><dd><?= h(configuredDisplayValue($serverContext['user'] ?? '')) ?></dd></div>
          <div><dt>Group</dt><dd><?= h(configuredDisplayValue($serverContext['group'] ?? '')) ?></dd></div>
          <div><dt>Working directory</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['working_directory'] ?? '')) ?></dd></div>
          <div><dt>PATH</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['path'] ?? '')) ?></dd></div>
          <div><dt>PHP executable</dt><dd class="path-value"><?= h(configuredDisplayValue($serverContext['php_executable'] ?? '')) ?></dd></div>
        </dl>
        <p class="field-help">These values describe the Dev Console service process, not a remote Managed Server.</p>
      </section>

      <section class="panel" id="dev-console-tools">
        <div class="dashboard-header">
          <h2>Dev Console Host Tools</h2>
          <form method="post" action="/?tab=settings#dev-console-tools" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="refresh_server_diagnostics">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <button type="submit" class="secondary">Refresh Diagnostics</button>
          </form>
        </div>
        <?php if ($serverDiagnosticsResult !== null): ?>
          <?php renderOperationResult($serverDiagnosticsResult, 'serverDiagnosticsOperationLog', 'server-diagnostics.log'); ?>
        <?php endif; ?>
        <section class="result-block tool-operation-panel" id="serverToolOperationPanel" hidden>
          <h2>Dev Console host tool operation</h2>
          <p id="serverToolOperationMessage">Starting...</p>
          <dl class="tool-operation-grid">
            <div><dt>Tool</dt><dd id="serverToolOperationTool">-</dd></div>
            <div><dt>Action</dt><dd id="serverToolOperationAction">-</dd></div>
            <div><dt>Status</dt><dd id="serverToolOperationStatus">Starting</dd></div>
            <div><dt>Elapsed</dt><dd id="serverToolOperationElapsed">0s</dd></div>
          </dl>
          <p><strong>Stage:</strong> <span id="serverToolOperationStage">Starting</span></p>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="serverToolLiveLog">Copy Log</button>
            <button type="button" class="secondary" data-download-log="serverToolLiveLog" data-download-name="server-tool-operation.log">Download Log</button>
            <span class="hint" data-log-message="serverToolLiveLog" aria-live="polite"></span>
          </div>
          <pre id="serverToolLiveLog" class="tool-operation-log">Waiting for operation log...</pre>
        </section>
        <?php foreach (['required' => ['Dev Console prerequisites', true]] as $toolGroup => [$toolGroupLabel, $toolGroupOpen]): ?>
          <details class="compact-details" open>
            <summary><?= h($toolGroupLabel) ?></summary>
            <div class="table-scroll">
              <table class="settings-table compact-sites host-tools-table">
                <thead>
                  <tr>
                    <th>Tool</th>
                    <th>Status</th>
                    <th>Installed version</th>
                    <th>Latest available</th>
                    <th>Executable</th>
                    <th>Installation source</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($serverTools as $toolId => $tool): ?>
                    <?php if ((string)($tool['required_group'] ?? '') !== $toolGroup) continue; ?>
                    <?php
                      $diagnosticStatus = (string)($tool['diagnostic_status'] ?? 'Diagnostic unavailable');
                      $statusClass = $diagnosticStatus === 'Installed' ? 'healthy' : ($toolGroup === 'required' ? 'error' : 'warning');
                      $actions = serverToolsAllowedActionsForTool((string)$toolId, $tool);
                    ?>
                    <tr>
                      <td>
                        <strong><?= h(configuredDisplayValue($tool['display_name'] ?? '')) ?></strong><br>
                        <span class="meta"><?= h(configuredDisplayValue($tool['requirement'] ?? '')) ?></span>
                      </td>
                      <td class="tool-status"><span class="status-pill <?= h($statusClass) ?>"><?= h($diagnosticStatus) ?></span></td>
                      <td><?= h(configuredDisplayValue($tool['version'] ?? '')) ?></td>
                      <td><?= h(configuredDisplayValue($tool['latest_version'] ?? '')) ?></td>
                      <td class="path-value"><?= h(configuredDisplayValue($tool['executable_path'] ?? '')) ?></td>
                      <td>
                        <?= h(configuredDisplayValue($tool['package_source'] ?? '')) ?><br>
                        <span class="meta"><?= h(configuredDisplayValue($tool['purpose'] ?? '')) ?></span><br>
                        <span class="meta">Service user: <?= !empty($tool['available_to_service_user']) ? 'Executable' : 'Unavailable' ?></span><br>
                        <span class="meta">Last checked: <?= h(configuredDisplayValue($tool['last_checked_at'] ?? '')) ?></span>
                      </td>
                      <td>
                        <div class="project-actions">
                          <?php foreach ($actions as $toolAction): ?>
                            <form method="post" action="/?tab=settings#dev-console-tools" data-server-tool-form="1" data-tool-id="<?= h((string)$toolId) ?>" data-tool-name="<?= h(configuredDisplayValue($tool['display_name'] ?? '')) ?>" data-tool-action="<?= h((string)$toolAction) ?>" data-action-label="<?= h(serverToolsActionLabel((string)$toolAction)) ?>">
                              <input type="hidden" name="action" value="server_tool_action">
                              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                              <input type="hidden" name="tool_id" value="<?= h((string)$toolId) ?>">
                              <input type="hidden" name="tool_action" value="<?= h((string)$toolAction) ?>">
                              <button type="submit" class="<?= $toolAction === 'refresh' ? 'secondary' : '' ?>"><?= h(serverToolsActionLabel((string)$toolAction)) ?></button>
                            </form>
                          <?php endforeach; ?>
                        </div>
                        <?php if (in_array((string)$toolId, ['git', 'php'], true)): ?>
                          <p class="field-help">Diagnostics only.</p>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        <?php endforeach; ?>
      </section>
      <section class="panel" id="github">
        <h2>GitHub</h2>
        <?php if ($githubActionResult !== null): ?>
          <?php
            $githubAction = (string)($githubActionResult['action'] ?? '');
            $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub action completed' : 'GitHub action failed';
            if ($githubAction === 'save_github_configuration') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub configuration saved' : 'GitHub configuration needs attention';
            } elseif ($githubAction === 'test_github_connection') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub connection verified' : 'GitHub connection failed';
            } elseif ($githubAction === 'remove_github_configuration') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub configuration removed' : 'GitHub configuration removal failed';
            } elseif ($githubAction === 'install_github_cli') {
                $githubActionTitle = !empty($githubActionResult['success']) ? 'GitHub CLI installed' : 'GitHub CLI installation failed';
            }
          ?>
          <section class="result-block <?= !empty($githubActionResult['success']) ? '' : 'error' ?>">
            <h2><?= h($githubActionTitle) ?></h2>
            <p><?= h((string)$githubActionResult['message']) ?></p>
            <details<?= !empty($githubActionResult['success']) ? '' : ' open' ?>>
              <summary>Show operation log</summary>
              <pre><?= h((string)($githubActionResult['output'] ?? '')) ?></pre>
            </details>
          </section>
        <?php endif; ?>

        <?php
          $githubConnectionFailed = $githubActionResult !== null
              && empty($githubActionResult['success'])
              && in_array((string)($githubActionResult['action'] ?? ''), ['save_github_configuration', 'test_github_connection'], true);
          $githubConnectionLabel = !empty($githubConfiguration['verified'])
              ? 'Verified'
              : ($githubConnectionFailed ? 'Failed' : ($githubConfigured ? 'Not verified' : 'Not configured'));
        ?>
        <dl class="apache-summary-grid">
          <div><dt>Account / organization</dt><dd><?= h(configuredDisplayValue($githubConfigured ? (string)$githubConfiguration['account'] : '')) ?></dd></div>
          <div><dt>Authentication</dt><dd><?= $githubConfigured ? 'Configured' : 'Not configured' ?></dd></div>
          <div><dt>GitHub CLI</dt><dd><?= $githubCliInstalled ? 'Installed' : 'Not installed' ?></dd></div>
          <div><dt>Connection</dt><dd><?= h($githubConnectionLabel) ?></dd></div>
          <div><dt>Last verified</dt><dd><?= h(configuredDisplayValue($githubConfiguration['last_verified_at'] ?? '')) ?></dd></div>
          <?php if ($githubConfigured): ?>
            <div><dt>Authenticated login</dt><dd><?= h(configuredDisplayValue($githubConfiguration['authenticated_login'] ?? '')) ?></dd></div>
          <?php endif; ?>
        </dl>

        <?php if (!$githubCliInstalled): ?>
          <p class="field-help">GitHub CLI is not installed.</p>
          <form method="post" class="form-actions" action="/?tab=settings#github" data-preserve-settings-scroll="1">
            <input type="hidden" name="action" value="install_github_cli">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <button type="submit" class="secondary">Install GitHub CLI</button>
          </form>
        <?php endif; ?>

        <form method="post" class="project-form subsection" action="/?tab=settings#github" data-preserve-settings-scroll="1">
          <input type="hidden" name="action" value="save_github_configuration">
          <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
          <fieldset>
            <legend><?= $githubConfigured ? 'Update configuration' : 'Configure GitHub' ?></legend>
            <div class="github-config-grid">
              <div>
                <label for="github_account">Account or organization</label>
                <input id="github_account" name="github_account" type="text" required maxlength="39" placeholder="account-or-organization" value="<?= h($githubConfigured ? (string)$githubConfiguration['account'] : '') ?>">
                <p class="field-help">GitHub owner where Dev Console will create repositories.</p>
              </div>

              <div>
                <label for="github_token">Personal Access Token</label>
                <input id="github_token" name="github_token" type="password" maxlength="4096" placeholder="github_pat_..." autocomplete="new-password" spellcheck="false" autocorrect="off" autocapitalize="off"<?= $githubConfigured ? '' : ' required' ?>>
                <p class="field-help">Stored only in the server's local configuration. Leave empty to keep the current token.</p>
                <p class="field-help">Recommended token: Classic Personal Access Token with repo scope.</p>
                <p class="field-help"><a href="https://github.com/settings/tokens/new" target="_blank" rel="noopener noreferrer">Create GitHub Personal Access Token</a></p>
              </div>
            </div>
          </fieldset>
          <button type="submit" class="github-submit"><?= $githubConfigured ? 'Update configuration' : 'Save and test' ?></button>
        </form>

        <?php if ($githubConfigured): ?>
          <div class="project-actions">
            <form method="post" action="/?tab=settings#github" data-preserve-settings-scroll="1">
              <input type="hidden" name="action" value="test_github_connection">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <button type="submit" class="secondary">Test connection</button>
            </form>
            <form method="post" action="/?tab=settings#github" data-preserve-settings-scroll="1" onsubmit="return confirm('Remove GitHub configuration?\nLocal and remote repositories will not be deleted.');">
              <input type="hidden" name="action" value="remove_github_configuration">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <button type="submit" class="secondary">Remove configuration</button>
            </form>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </section>

  <section id="serverManagementTab" data-tab-panel="server-management"<?= $initialTab === 'server-management' ? '' : ' hidden' ?>>
    <div class="dashboard-header">
      <h2>Server Management</h2>
      <span class="meta">Runtime and operational management of one selected Managed Server</span>
    </div>
    <?php if (empty($managedServers)): ?>
      <section class="panel">
        <p class="meta">No Managed Servers configured.</p>
      </section>
    <?php else: ?>
      <div class="server-management-selector">
        <form method="get" class="project-selector compact-selector" action="/">
          <input type="hidden" name="tab" value="server-management">
          <label for="server_management_server_id">Managed Server</label>
          <select id="server_management_server_id" name="managed_server_id" onchange="this.form.submit()">
            <?php foreach ($managedServers as $server): ?>
              <?php $optionId = (string)($server['id'] ?? ''); ?>
              <option value="<?= h($optionId) ?>"<?= $optionId === $serverManagementSelectedId ? ' selected' : '' ?>><?= h(configuredDisplayValue($server['name'] ?? $optionId)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ($serverManagementSelectedServer !== null): ?>
          <p class="field-help">
            Selected: <strong><?= h(configuredDisplayValue($serverManagementSelectedServer['name'] ?? $serverManagementSelectedId)) ?></strong>
            <span class="status-pill <?= h(managedServersStatusClass($serverManagementSelectedServer)) ?>" data-selected-server-detail="reachability"><?= h(managedServersStatusLabel($serverManagementSelectedServer)) ?></span>
          </p>
        <?php endif; ?>
      </div>
      <?php if ($serverManagementSelectedServer !== null): ?>
        <?php
          $selectedServerId = (string)($serverManagementSelectedServer['id'] ?? '');
          $selectedServerSudoState = (string)($serverManagementSelectedServer['passwordless_sudo'] ?? 'unknown');
          $selectedServerSudoLabel = match ($selectedServerSudoState) {
              'ready', 'root' => 'Ready',
              'setup_required' => 'Not available',
              default => 'Unknown',
          };
          $selectedServerPhpInstalled = !empty($serverManagementSelectedServer['php_installed']);
          $selectedServerNodeInstalled = !empty($serverManagementSelectedServer['node_installed']);
          $selectedServerNpmInstalled = !empty($serverManagementSelectedServer['npm_installed']);
          $selectedServerComposerInstalled = !empty($serverManagementSelectedServer['composer_installed']);
          $selectedServerComposerInstallDisabledReason = (string)($serverManagementSelectedServer['status'] ?? '') !== 'reachable'
              ? 'Refresh diagnostics successfully before installing Composer.'
              : (!in_array($selectedServerSudoState, ['ready', 'root'], true)
                  ? 'Passwordless sudo is required before installing Composer.'
                  : '');
          $projectsOnSelectedServer = array_values(array_filter($projects, static fn(array $project): bool => (string)($project['managed_server_id'] ?? '') === $selectedServerId));
          $selectedServerApache = is_array($serverManagementSelectedServer['apache'] ?? null)
              ? array_merge(['installed' => false, 'running' => null, 'enabled' => null, 'version' => '', 'binary_path' => '', 'diagnostic_error' => ''], $serverManagementSelectedServer['apache'])
              : ['installed' => false, 'running' => null, 'enabled' => null, 'version' => '', 'binary_path' => '', 'diagnostic_error' => ''];
          $selectedServerApacheSites = is_array($serverManagementSelectedServer['apache_sites'] ?? null) ? $serverManagementSelectedServer['apache_sites'] : [];
          $selectedServerManagedSiteKeys = [];
          foreach ($projectsOnSelectedServer as $projectOnSelectedServer) {
              $projectKey = (string)($projectOnSelectedServer['id'] ?? '');
              if ($projectKey !== '') {
                  $selectedServerManagedSiteKeys[$projectKey . '|preview'] = true;
                  $selectedServerManagedSiteKeys[$projectKey . '|production'] = true;
              }
          }
          $selectedServerApacheDetected = !empty($selectedServerApache['installed'])
              || (string)($selectedServerApache['binary_path'] ?? '') !== ''
              || (string)($selectedServerApache['version'] ?? '') !== ''
              || ($selectedServerApache['running'] ?? null) === true
              || ($selectedServerApache['enabled'] ?? null) === true;
          $selectedServerApacheKnownNotInstalled = !$selectedServerApacheDetected
              && (string)($serverManagementSelectedServer['status'] ?? '') === 'reachable'
              && ($serverManagementSelectedServer['last_connection_test_at'] ?? null) !== null;
          $selectedServerApacheStatus = $selectedServerApacheDetected
              ? (($selectedServerApache['running'] ?? null) === true ? 'Running' : (($selectedServerApache['running'] ?? null) === false ? 'Installed, stopped' : 'Installed'))
              : ($selectedServerApacheKnownNotInstalled ? 'Not installed' : 'Unknown');
          $selectedServerApacheEnabled = ($selectedServerApache['enabled'] ?? null) === true
              ? 'Yes'
              : (($selectedServerApache['enabled'] ?? null) === false ? 'No' : 'Unknown');
          $serverManagementTools = [
              [
                  'id' => 'php',
                  'name' => 'PHP',
                  'status' => $selectedServerPhpInstalled ? 'Installed' : 'Missing',
                  'version' => (string)($serverManagementSelectedServer['php_version'] ?? ''),
                  'latest' => '',
                  'executable' => (string)($serverManagementSelectedServer['php_path'] ?? ''),
                  'source' => 'Detected by Managed Server SSH diagnostics.',
                  'actions' => [],
              ],
              [
                  'id' => 'composer',
                  'name' => 'Composer',
                  'status' => $selectedServerComposerInstalled ? 'Installed' : 'Missing',
                  'version' => (string)($serverManagementSelectedServer['composer_version'] ?? ''),
                  'latest' => '',
                  'executable' => (string)($serverManagementSelectedServer['composer_path'] ?? ''),
                  'source' => 'Detected by Managed Server SSH diagnostics. Installation uses the existing fixed apt-get command path.',
                  'actions' => $selectedServerComposerInstalled ? [] : ['install_composer'],
              ],
              [
                  'id' => 'node',
                  'name' => 'Node.js',
                  'status' => $selectedServerNodeInstalled ? 'Installed' : 'Missing',
                  'version' => (string)($serverManagementSelectedServer['node_version'] ?? ''),
                  'latest' => '',
                  'executable' => (string)($serverManagementSelectedServer['node_path'] ?? ''),
                  'source' => 'Detected by Managed Server SSH diagnostics.',
                  'actions' => [],
              ],
              [
                  'id' => 'npm',
                  'name' => 'npm',
                  'status' => $selectedServerNpmInstalled ? 'Installed' : 'Missing',
                  'version' => (string)($serverManagementSelectedServer['npm_version'] ?? ''),
                  'latest' => '',
                  'executable' => (string)($serverManagementSelectedServer['npm_path'] ?? ''),
                  'source' => 'Detected by Managed Server SSH diagnostics.',
                  'actions' => [],
              ],
          ];
        ?>
        <section class="result-block tool-operation-panel" data-managed-server-operation-panel="server-management" hidden>
          <h2 data-managed-server-field="title">Managed server operation</h2>
          <p data-managed-server-field="message">Starting...</p>
          <dl class="tool-operation-grid">
            <div><dt>Server</dt><dd data-managed-server-field="server">-</dd></div>
            <div><dt>Status</dt><dd data-managed-server-field="status">Starting</dd></div>
            <div><dt>Stage</dt><dd data-managed-server-field="stage">Starting</dd></div>
            <div><dt>Elapsed</dt><dd data-managed-server-field="elapsed">0s</dd></div>
          </dl>
          <dl class="apache-summary-grid" data-managed-server-field="details" hidden>
            <div><dt>SSH access</dt><dd data-managed-server-field="ssh_access">-</dd></div>
            <div><dt>Passwordless sudo</dt><dd data-managed-server-field="sudo">-</dd></div>
            <div><dt>Hostname</dt><dd data-managed-server-field="hostname">-</dd></div>
            <div><dt>OS</dt><dd data-managed-server-field="os">-</dd></div>
            <div><dt>Kernel</dt><dd data-managed-server-field="kernel">-</dd></div>
            <div><dt>Current user</dt><dd data-managed-server-field="user">-</dd></div>
            <div><dt>Working directory</dt><dd data-managed-server-field="working_directory">-</dd></div>
            <div><dt>Round-trip time</dt><dd data-managed-server-field="rtt">-</dd></div>
          </dl>
          <div class="result-actions">
            <button type="button" class="secondary" data-copy-log="serverManagementLiveLog">Copy Log</button>
            <button type="button" class="secondary" data-download-log="serverManagementLiveLog" data-download-name="managed-server-operation.log">Download Log</button>
            <span class="hint" data-log-message="serverManagementLiveLog" aria-live="polite"></span>
          </div>
          <pre id="serverManagementLiveLog" class="tool-operation-log" data-managed-server-field="log">Waiting for operation log...</pre>
        </section>

        <section class="panel">
          <div class="dashboard-header">
            <h2>Server Overview</h2>
            <span class="meta">Selected Managed Server connection summary</span>
          </div>
          <dl class="project-detail-grid">
            <div><dt>Display name</dt><dd><?= h(configuredDisplayValue($serverManagementSelectedServer['name'] ?? '')) ?></dd></div>
            <div><dt>Host / IP</dt><dd><?= h(configuredDisplayValue($serverManagementSelectedServer['host'] ?? '')) ?></dd></div>
            <div><dt>SSH user</dt><dd><?= h(configuredDisplayValue($serverManagementSelectedServer['user'] ?? '')) ?></dd></div>
            <div><dt>SSH port</dt><dd><?= h((string)((int)($serverManagementSelectedServer['port'] ?? 22))) ?></dd></div>
            <div><dt>Last checked</dt><dd data-selected-server-detail="last_checked"><?= h(configuredDisplayValue($serverManagementSelectedServer['last_connection_test_at'] ?? '')) ?></dd></div>
            <div><dt>Remote hostname</dt><dd data-selected-server-detail="hostname"><?= h(configuredDisplayValue($serverManagementSelectedServer['remote_hostname'] ?? '')) ?></dd></div>
            <div><dt>Remote OS</dt><dd data-selected-server-detail="os"><?= h(configuredDisplayValue($serverManagementSelectedServer['remote_os'] ?? $serverManagementSelectedServer['os'] ?? '')) ?></dd></div>
            <div><dt>Passwordless sudo</dt><dd data-selected-server-detail="sudo"><?= h($selectedServerSudoLabel) ?></dd></div>
          </dl>
          <div class="project-actions">
            <form method="post" action="/?tab=server-management&managed_server_id=<?= rawurlencode($selectedServerId) ?>#server-management-tools" data-managed-server-test-form="1" data-operation-target="server-management" data-server-id="<?= h($selectedServerId) ?>" data-server-name="<?= h(configuredDisplayValue($serverManagementSelectedServer['name'] ?? '')) ?>">
              <input type="hidden" name="action" value="test_managed_server">
              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
              <input type="hidden" name="server_id" value="<?= h($selectedServerId) ?>">
              <button type="submit">Refresh Diagnostics</button>
            </form>
          </div>
        </section>

        <section class="panel" id="server-management-tools">
          <div class="dashboard-header">
            <h2>Runtime &amp; Development Tools</h2>
            <span class="meta">Software inventory for the selected Managed Server</span>
          </div>
          <div class="table-scroll">
            <table class="settings-table compact-sites host-tools-table">
              <thead>
                <tr>
                  <th>Tool</th>
                  <th>Status</th>
                  <th>Installed version</th>
                  <th>Latest available</th>
                  <th>Executable</th>
                  <th>Installation source</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($serverManagementTools as $tool): ?>
                  <?php
                    $toolStatus = (string)$tool['status'];
                    $toolStatusClass = $toolStatus === 'Installed' ? 'healthy' : ($toolStatus === 'Missing' ? 'warning' : '');
                  ?>
                  <tr>
                    <td><strong><?= h((string)$tool['name']) ?></strong></td>
                    <td><span class="status-pill <?= h($toolStatusClass) ?>" data-selected-server-detail="<?= h('tool_' . (string)$tool['id']) ?>"><?= h($toolStatus) ?></span></td>
                    <td data-selected-server-detail="<?= h('tool_' . (string)$tool['id'] . '_version') ?>"><?= h(configuredDisplayValue($tool['version'])) ?></td>
                    <td><?= h(configuredDisplayValue($tool['latest'])) ?></td>
                    <td class="path-value" data-selected-server-detail="<?= h('tool_' . (string)$tool['id'] . '_path') ?>"><?= h(configuredDisplayValue($tool['executable'])) ?></td>
                    <td><?= h(configuredDisplayValue($tool['source'])) ?></td>
                    <td>
                      <div class="project-actions">
                        <?php foreach ($tool['actions'] as $toolAction): ?>
                          <?php if ($toolAction === 'install_composer'): ?>
                            <form method="post" action="/?tab=server-management&managed_server_id=<?= rawurlencode($selectedServerId) ?>#server-management-tools" data-managed-server-test-form="1" data-operation-target="server-management" data-server-id="<?= h($selectedServerId) ?>" data-server-name="<?= h(configuredDisplayValue($serverManagementSelectedServer['name'] ?? '')) ?>">
                              <input type="hidden" name="action" value="install_managed_server_composer">
                              <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                              <input type="hidden" name="server_id" value="<?= h($selectedServerId) ?>">
                              <button type="submit"<?= $selectedServerComposerInstallDisabledReason === '' ? ' onclick="return confirm(\'Install Composer on this managed server using apt-get?\');"' : ' disabled title="' . h($selectedServerComposerInstallDisabledReason) . '"' ?>>Install</button>
                            </form>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="panel">
          <div class="dashboard-header">
            <h2>Web Server</h2>
            <span class="meta">Apache state for the selected Managed Server</span>
          </div>
          <dl class="project-detail-grid">
            <div><dt>Status</dt><dd data-selected-server-detail="apache_status"><?= h($selectedServerApacheStatus) ?></dd></div>
            <div><dt>Service enabled</dt><dd data-selected-server-detail="apache_enabled"><?= h($selectedServerApacheEnabled) ?></dd></div>
            <div><dt>Version</dt><dd data-selected-server-detail="apache_version"><?= h(configuredDisplayValue($selectedServerApache['version'] ?? '')) ?></dd></div>
            <div><dt>Binary path</dt><dd class="path-value" data-selected-server-detail="apache_path"><?= h(configuredDisplayValue($selectedServerApache['binary_path'] ?? '')) ?></dd></div>
          </dl>
          <?php if ($selectedServerApacheKnownNotInstalled): ?>
            <p class="meta">Apache is not installed on this Managed Server.</p>
          <?php elseif (!$selectedServerApacheDetected): ?>
            <p class="meta">Apache diagnostics have not been refreshed successfully for this Managed Server.</p>
          <?php elseif (empty($selectedServerApacheSites)): ?>
            <p class="meta">No Apache virtual host configurations detected.</p>
          <?php else: ?>
            <div class="table-scroll">
              <table class="settings-table compact-sites">
                <thead>
                  <tr>
                    <th>Site/config</th>
                    <th>Status</th>
                    <th>ServerName</th>
                    <th>DocumentRoot</th>
                    <th>Managed by Dev Console</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($selectedServerApacheSites as $site): ?>
                    <?php
                      $siteProjectKey = (string)($site['project_id'] ?? '') . '|' . (string)($site['environment'] ?? '');
                      $siteManaged = !empty($site['managed_marker']) && isset($selectedServerManagedSiteKeys[$siteProjectKey]);
                      $siteEnabled = $site['enabled'] ?? null;
                      $siteStatus = $siteEnabled === true ? 'Enabled' : ($siteEnabled === false ? 'Disabled' : 'Unknown');
                    ?>
                    <tr>
                      <td>
                        <strong><?= h(configuredDisplayValue($site['name'] ?? '')) ?></strong><br>
                        <span class="meta path-value"><?= h(configuredDisplayValue($site['path'] ?? '')) ?></span>
                      </td>
                      <td><?= h($siteStatus) ?></td>
                      <td><?= h(configuredDisplayValue($site['server_name'] ?? '')) ?></td>
                      <td class="path-value"><?= h(configuredDisplayValue($site['document_root'] ?? '')) ?></td>
                      <td><?= $siteManaged ? 'Yes' : 'No' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="panel">
          <div class="dashboard-header">
            <h2>Projects on this Server</h2>
            <span class="meta"><?= h((string)count($projectsOnSelectedServer)) ?> assigned</span>
          </div>
          <?php if (empty($projectsOnSelectedServer)): ?>
            <p class="meta">No Dev Console projects are assigned to this server.</p>
          <?php else: ?>
            <div class="table-scroll">
              <table class="settings-table compact-sites">
                <thead>
                  <tr>
                    <th>Project</th>
                    <th>Preview</th>
                    <th>Production</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($projectsOnSelectedServer as $project): ?>
                    <?php
                      $previewDomain = (string)($project['preview']['domain'] ?? '');
                      $previewPath = (string)($project['preview']['path'] ?? '');
                      $productionDomain = (string)($project['production']['domain'] ?? '');
                      $productionPath = (string)($project['production']['path'] ?? '');
                    ?>
                    <tr>
                      <td>
                        <strong><?= h(configuredDisplayValue($project['name'] ?? '')) ?></strong><br>
                        <span class="meta"><?= h(configuredDisplayValue($project['id'] ?? '')) ?></span>
                      </td>
                      <td>
                        <?= h($previewDomain !== '' || $previewPath !== '' ? 'Configured' : 'Not configured') ?><br>
                        <span class="meta"><?= h(configuredDisplayValue($previewDomain)) ?></span><br>
                        <span class="meta path-value"><?= h(configuredDisplayValue($previewPath)) ?></span>
                      </td>
                      <td>
                        <?= h($productionDomain !== '' || $productionPath !== '' ? 'Configured' : 'Not configured') ?><br>
                        <span class="meta"><?= h(configuredDisplayValue($productionDomain)) ?></span><br>
                        <span class="meta path-value"><?= h(configuredDisplayValue($productionPath)) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <footer class="app-footer">&copy; <?= h(date('Y')) ?> IOVON</footer>
</main>
<script>
(() => {
  const textarea = document.getElementById('task_body');
  const newTaskAction = document.getElementById('newTaskAction');
  const clearDraft = document.getElementById('clearDraft');
  const draftStatus = document.getElementById('draftStatus');
  const form = document.getElementById('taskForm');
  const uploadZone = document.getElementById('uploadZone');
  const fileInput = document.getElementById('attachment');
  const selectedFiles = document.getElementById('selectedFiles');
  const runCodex = document.getElementById('runCodex');
  const codexRunPanel = document.getElementById('codexRunPanel');
  const codexStatus = document.getElementById('codexStatus');
  const codexConsole = document.getElementById('codexConsole');
  const refreshCodexLog = document.getElementById('refreshCodexLog');
  const copyCodexLog = document.getElementById('copyCodexLog');
  const downloadCodexLog = document.getElementById('downloadCodexLog');
  const copyCodexMessage = document.getElementById('copyCodexMessage');
  const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const activeProjectId = <?= json_encode($activeProjectId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const editorTaskId = <?= json_encode($editorTaskId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const nextTaskId = <?= json_encode(taskNumber($nextNumber), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const activeManagedServerLabel = <?= json_encode(devConsoleManagedServerLabel($activeManagedServer, (string)($activeProject['managed_server_id'] ?? '')), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const activeManagedServerStatus = <?= json_encode(devConsoleManagedServerStatusLabel($activeManagedServer), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const activeGitStatusLabel = <?= json_encode((string)($activeGitStatus['status'] ?? 'Not initialized'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const draftKey = `dev-console-task-draft-${activeProjectId || 'none'}-${editorTaskId || `new-${nextTaskId || 'none'}`}`;
  const environmentDashboard = document.getElementById('environmentDashboard');
  const dashboardUpdated = document.getElementById('dashboardUpdated');
  const activeTask = <?= json_encode($activeTaskId === '' ? 'None' : $activeTaskId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tabButtons = Array.from(document.querySelectorAll('[data-tab-target]'));
  const tabPanels = Array.from(document.querySelectorAll('[data-tab-panel]'));
  const pageContexts = Array.from(document.querySelectorAll('[data-page-context]'));
  const scrollKey = 'iovon.devConsole.scrollPosition';
  const settingsScrollKey = 'iovon.devConsole.settingsScrollPosition';
  let scrollSaveFrame = null;
  const selectedTab = () => tabButtons.find((button) => button.classList.contains('active'))?.dataset.tabTarget || 'dashboard';
  const activateTab = (target) => {
    tabButtons.forEach((button) => {
      const active = button.dataset.tabTarget === target;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    tabPanels.forEach((panel) => {
      panel.hidden = panel.dataset.tabPanel !== target;
    });
    pageContexts.forEach((context) => {
      context.hidden = context.dataset.pageContext !== target;
    });
    const url = new URL(window.location.href);
    url.searchParams.set('tab', target);
    window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
  };
  tabButtons.forEach((button) => {
    button.addEventListener('click', () => activateTab(button.dataset.tabTarget || 'dashboard'));
  });
  const saveScrollPosition = () => {
    sessionStorage.setItem(scrollKey, String(window.scrollY));
  };
  const restoreScrollPosition = () => {
    const savedPosition = Number(sessionStorage.getItem(scrollKey));
    if (Number.isFinite(savedPosition) && savedPosition >= 0) {
      requestAnimationFrame(() => window.scrollTo(0, savedPosition));
    }
  };
  const restoreSettingsScrollPosition = () => {
    const settingsPanel = document.getElementById('settingsTab');
    if (!settingsPanel || settingsPanel.hidden) return false;
    const savedPosition = Number(sessionStorage.getItem(settingsScrollKey));
    sessionStorage.removeItem(settingsScrollKey);
    if (Number.isFinite(savedPosition) && savedPosition >= 0) {
      requestAnimationFrame(() => window.scrollTo(0, savedPosition));
      return true;
    }
    return false;
  };
  if (!restoreSettingsScrollPosition()) {
    restoreScrollPosition();
  }
  document.querySelectorAll('#settingsTab form[data-preserve-settings-scroll="1"]').forEach((settingsForm) => {
    settingsForm.addEventListener('submit', () => {
      sessionStorage.setItem(settingsScrollKey, String(window.scrollY));
    });
  });
  document.querySelectorAll('form[data-project-selection-form]').forEach((projectForm) => {
    projectForm.addEventListener('submit', () => {
      const target = selectedTab();
      const targetInput = projectForm.querySelector('input[name="target_tab"]');
      if (targetInput) targetInput.value = target;
      projectForm.action = target === 'dashboard' ? '/?tab=dashboard' : '/?tab=projects#projects';
    });
  });
  const deleteProjectDialog = document.getElementById('deleteProjectDialog');
  const deleteProjectTitle = document.getElementById('deleteProjectTitle');
  const deleteProjectConfirmation = document.getElementById('deleteProjectConfirmation');
  const deleteGithubRepositoryOption = document.getElementById('deleteGithubRepositoryOption');
  const deleteGithubRepositoryCheckbox = document.getElementById('deleteGithubRepositoryCheckbox');
  const deleteGithubRepositoryLabel = document.getElementById('deleteGithubRepositoryLabel');
  const deleteGithubRepositoryHelp = document.getElementById('deleteGithubRepositoryHelp');
  const deleteGithubRepositoryUnavailable = document.getElementById('deleteGithubRepositoryUnavailable');
  const deleteGithubRepositoryUnavailableReason = document.getElementById('deleteGithubRepositoryUnavailableReason');
  const cancelProjectDelete = document.getElementById('cancelProjectDelete');
  const confirmProjectDelete = document.getElementById('confirmProjectDelete');
  let pendingDeleteProjectForm = null;
  const updateDeleteProjectConfirmation = () => {
    const expected = pendingDeleteProjectForm?.dataset.projectName || '';
    if (confirmProjectDelete) confirmProjectDelete.disabled = !deleteProjectConfirmation || deleteProjectConfirmation.value !== expected;
  };
  document.querySelectorAll('[data-open-delete-project]').forEach((button) => {
    button.addEventListener('click', () => {
      pendingDeleteProjectForm = button.closest('form[data-delete-project-form="1"]');
      if (!pendingDeleteProjectForm || !deleteProjectDialog) return;
      const projectName = pendingDeleteProjectForm.dataset.projectName || '';
      const githubAvailable = pendingDeleteProjectForm.dataset.githubDeleteAvailable === '1';
      const githubRepository = pendingDeleteProjectForm.dataset.githubRepository || '';
      const githubReason = pendingDeleteProjectForm.dataset.githubDeleteReason || 'Repository identity cannot be verified.';
      if (deleteProjectTitle) deleteProjectTitle.textContent = `Delete Project: ${projectName}`;
      if (deleteProjectConfirmation) deleteProjectConfirmation.value = '';
      if (deleteGithubRepositoryCheckbox) {
        deleteGithubRepositoryCheckbox.checked = false;
        deleteGithubRepositoryCheckbox.disabled = !githubAvailable;
      }
      if (deleteGithubRepositoryLabel) deleteGithubRepositoryLabel.textContent = githubAvailable ? `Delete GitHub repository ${githubRepository}` : 'Delete GitHub repository';
      if (deleteGithubRepositoryOption) deleteGithubRepositoryOption.hidden = !githubAvailable;
      if (deleteGithubRepositoryHelp) deleteGithubRepositoryHelp.hidden = !githubAvailable;
      if (deleteGithubRepositoryUnavailable) {
        deleteGithubRepositoryUnavailable.hidden = githubAvailable;
      }
      if (deleteGithubRepositoryUnavailableReason) {
        deleteGithubRepositoryUnavailableReason.hidden = githubAvailable;
        deleteGithubRepositoryUnavailableReason.textContent = githubAvailable ? '' : `Reason: ${githubReason}`;
      }
      updateDeleteProjectConfirmation();
      deleteProjectDialog.showModal();
      deleteProjectConfirmation?.focus();
    });
  });
  deleteProjectConfirmation?.addEventListener('input', updateDeleteProjectConfirmation);
  cancelProjectDelete?.addEventListener('click', () => {
    pendingDeleteProjectForm = null;
    deleteProjectDialog?.close();
  });
  confirmProjectDelete?.addEventListener('click', () => {
    if (!pendingDeleteProjectForm || !deleteProjectConfirmation || confirmProjectDelete.disabled) return;
    const confirmationInput = pendingDeleteProjectForm.querySelector('input[name="confirm_project_name"]');
    const githubPolicyInput = pendingDeleteProjectForm.querySelector('input[name="github_repository_policy"]');
    if (confirmationInput) confirmationInput.value = deleteProjectConfirmation.value;
    if (githubPolicyInput) githubPolicyInput.value = deleteGithubRepositoryCheckbox?.checked ? 'delete' : 'keep';
    deleteProjectDialog?.close();
    pendingDeleteProjectForm.submit();
  });
  document.querySelectorAll('[data-show-repository-name-choice]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.closest('[data-repository-collision-panel]');
      const form = panel?.querySelector('[data-repository-name-choice]');
      if (form) form.hidden = false;
      button.hidden = true;
    });
  });
  document.querySelectorAll('[data-cancel-repository-collision]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.closest('[data-repository-collision-panel]');
      if (panel) panel.hidden = true;
    });
  });
  document.querySelectorAll('[data-copy-log]').forEach((button) => {
    button.addEventListener('click', async () => {
      const target = document.getElementById(button.dataset.copyLog || '');
      const message = document.querySelector(`[data-log-message="${button.dataset.copyLog || ''}"]`);
      if (!target) return;
      const text = target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement ? target.value : (target.textContent || '');
      try {
        await navigator.clipboard.writeText(text);
        const original = button.textContent;
        button.textContent = 'Copied';
        if (message) message.textContent = '';
        window.setTimeout(() => { button.textContent = original; }, 1500);
      } catch (error) {
        if (message) message.textContent = 'Unable to copy log.';
      }
    });
  });
  document.querySelectorAll('[data-download-log]').forEach((button) => {
    button.addEventListener('click', () => {
      const target = document.getElementById(button.dataset.downloadLog || '');
      if (!target) return;
      const blob = new Blob([target.textContent || ''], { type: 'text/plain;charset=utf-8' });
      const link = document.createElement('a');
      link.href = URL.createObjectURL(blob);
      link.download = button.dataset.downloadName || 'operation.log';
      document.body.appendChild(link);
      link.click();
      URL.revokeObjectURL(link.href);
      link.remove();
    });
  });

  const serverToolPanel = document.getElementById('serverToolOperationPanel');
  const serverToolMessage = document.getElementById('serverToolOperationMessage');
  const serverToolTool = document.getElementById('serverToolOperationTool');
  const serverToolAction = document.getElementById('serverToolOperationAction');
  const serverToolStatus = document.getElementById('serverToolOperationStatus');
  const serverToolElapsed = document.getElementById('serverToolOperationElapsed');
  const serverToolStage = document.getElementById('serverToolOperationStage');
  const serverToolLog = document.getElementById('serverToolLiveLog');
  const serverToolForms = Array.from(document.querySelectorAll('[data-server-tool-form="1"]'));
  const formatElapsed = (seconds) => {
    const value = Math.max(0, Number(seconds) || 0);
    const minutes = Math.floor(value / 60);
    const remainder = value % 60;
    return minutes > 0 ? `${minutes}m ${remainder}s` : `${remainder}s`;
  };
  const setServerToolButtons = (toolId, disabled) => {
    serverToolForms.forEach((form) => {
      if (form.dataset.toolId !== toolId) return;
      form.querySelectorAll('button').forEach((button) => { button.disabled = disabled; });
    });
  };
  const showServerToolOperation = (operation) => {
    if (!serverToolPanel) return;
    serverToolPanel.hidden = false;
    serverToolPanel.classList.toggle('failed', operation.status === 'failed');
    if (serverToolTool) serverToolTool.textContent = operation.tool_name || '-';
    if (serverToolAction) serverToolAction.textContent = operation.action_label || operation.tool_action || '-';
    if (serverToolStatus) serverToolStatus.textContent = String(operation.status || 'starting').replace(/^\w/, (letter) => letter.toUpperCase());
    if (serverToolStage) serverToolStage.textContent = operation.stage || 'Starting';
    if (serverToolElapsed) serverToolElapsed.textContent = formatElapsed(operation.elapsed_seconds || 0);
    if (serverToolMessage) serverToolMessage.textContent = operation.message || 'Operation running.';
    if (serverToolLog) {
      const nextLog = operation.log && operation.log.trim() !== '' ? operation.log : 'Waiting for operation log...';
      serverToolLog.textContent = nextLog;
      serverToolLog.scrollTop = serverToolLog.scrollHeight;
    }
  };
  const pollServerToolOperation = (operationId, toolId) => {
    let poll = null;
    const update = async () => {
      const response = await fetch(`?action=server-tool-operation-status&id=${encodeURIComponent(operationId)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to read server tool operation.');
      showServerToolOperation(payload.operation);
      if (payload.operation.status === 'completed' || payload.operation.status === 'failed') {
        clearInterval(poll);
        setServerToolButtons(toolId, false);
        window.setTimeout(() => {
          window.location.href = `/?tab=settings&server_tool_operation=${encodeURIComponent(operationId)}#dev-console-tools`;
        }, 900);
      }
    };
    update().catch((error) => {
      clearInterval(poll);
      setServerToolButtons(toolId, false);
      if (serverToolPanel) serverToolPanel.hidden = false;
      if (serverToolPanel) serverToolPanel.classList.add('failed');
      if (serverToolMessage) serverToolMessage.textContent = error.message;
      if (serverToolStatus) serverToolStatus.textContent = 'Failed';
      if (serverToolStage) serverToolStage.textContent = 'Failed';
    });
    poll = window.setInterval(() => update().catch((error) => {
      clearInterval(poll);
      setServerToolButtons(toolId, false);
      if (serverToolMessage) serverToolMessage.textContent = error.message;
      if (serverToolStatus) serverToolStatus.textContent = 'Failed';
    }), 1000);
  };
  serverToolForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const toolId = form.dataset.toolId || '';
      setServerToolButtons(toolId, true);
      showServerToolOperation({
        tool_name: form.dataset.toolName || toolId,
        tool_action: form.dataset.toolAction || '',
        action_label: form.dataset.actionLabel || '',
        status: 'starting',
        stage: 'Starting',
        elapsed_seconds: 0,
        message: 'Starting server tool operation.',
        log: '',
      });
      try {
        const response = await fetch('/?tab=settings#dev-console-tools', { method: 'POST', body: new FormData(form) });
        const payload = await response.json();
        if (!payload.ok) throw new Error(payload.error || 'Unable to start server tool operation.');
        showServerToolOperation(payload.operation);
        pollServerToolOperation(payload.operation.id, toolId);
      } catch (error) {
        setServerToolButtons(toolId, false);
        if (serverToolPanel) serverToolPanel.classList.add('failed');
        if (serverToolMessage) serverToolMessage.textContent = error.message;
        if (serverToolStatus) serverToolStatus.textContent = 'Failed';
        if (serverToolStage) serverToolStage.textContent = 'Failed';
      }
    });
  });

  const managedServerPanel = document.getElementById('managedServerOperationPanel');
  const managedServerTitle = document.getElementById('managedServerOperationTitle');
  const managedServerMessage = document.getElementById('managedServerOperationMessage');
  const managedServerName = document.getElementById('managedServerOperationServer');
  const managedServerStatus = document.getElementById('managedServerOperationStatus');
  const managedServerStage = document.getElementById('managedServerOperationStage');
  const managedServerElapsed = document.getElementById('managedServerOperationElapsed');
  const managedServerLog = document.getElementById('managedServerLiveLog');
  const managedServerDetails = document.getElementById('managedServerConnectionDetails');
  const managedServerResultSshAccess = document.getElementById('managedServerResultSshAccess');
  const managedServerResultSudo = document.getElementById('managedServerResultSudo');
  const managedServerResultHostname = document.getElementById('managedServerResultHostname');
  const managedServerResultOs = document.getElementById('managedServerResultOs');
  const managedServerResultKernel = document.getElementById('managedServerResultKernel');
  const managedServerResultUser = document.getElementById('managedServerResultUser');
  const managedServerResultWorkingDirectory = document.getElementById('managedServerResultWorkingDirectory');
  const managedServerResultRtt = document.getElementById('managedServerResultRtt');
  const managedServerForms = Array.from(document.querySelectorAll('[data-managed-server-test-form="1"]'));
  const managedServerPanelElements = (target) => {
    const panel = document.querySelector(`[data-managed-server-operation-panel="${target || 'servers'}"]`) || managedServerPanel;
    const field = (name, fallback) => panel?.querySelector(`[data-managed-server-field="${name}"]`) || fallback || null;
    return {
      panel,
      title: field('title', managedServerTitle),
      message: field('message', managedServerMessage),
      server: field('server', managedServerName),
      status: field('status', managedServerStatus),
      stage: field('stage', managedServerStage),
      elapsed: field('elapsed', managedServerElapsed),
      log: field('log', managedServerLog),
      details: field('details', managedServerDetails),
      sshAccess: field('ssh_access', managedServerResultSshAccess),
      sudo: field('sudo', managedServerResultSudo),
      hostname: field('hostname', managedServerResultHostname),
      os: field('os', managedServerResultOs),
      kernel: field('kernel', managedServerResultKernel),
      user: field('user', managedServerResultUser),
      workingDirectory: field('working_directory', managedServerResultWorkingDirectory),
      rtt: field('rtt', managedServerResultRtt),
    };
  };
	  const setManagedServerButtons = (serverId, disabled) => {
	    managedServerForms.forEach((form) => {
	      if (form.dataset.serverId !== serverId) return;
	      form.querySelectorAll('button').forEach((button) => { button.disabled = disabled; });
	    });
	  };
	  const setServerStatusPill = (element, reachable) => {
	    if (!element) return;
	    element.textContent = reachable ? 'Reachable' : 'Unreachable';
	    element.classList.remove('healthy', 'warning', 'error');
	    element.classList.add(reachable ? 'healthy' : 'error');
	  };
	  const updateManagedServerCard = (operation) => {
	    const serverId = operation.server_id || '';
	    const card = Array.from(document.querySelectorAll('[data-server-card]')).find((candidate) => candidate.dataset.serverId === serverId);
	    if (!card) return;
	    const result = operation.result || {};
	    const reachable = operation.status === 'completed' && result.success !== false;
	    const checkedAt = operation.finished_at || new Date().toISOString();
	    const response = result.round_trip_ms ? `${result.round_trip_ms} ms` : 'Not configured';
	    const configuredHost = card.dataset.serverHost || '';
	    const configuredUser = card.dataset.serverUser || '';
	    const remoteHostname = result.hostname || '';
	    const remoteUser = result.remote_user || '';
	    const hostDetail = remoteHostname && remoteHostname.toLowerCase() !== configuredHost.toLowerCase()
	      ? `${configuredHost} / ${remoteHostname}`
	      : configuredHost;
	    const userDiffers = remoteUser && remoteUser !== configuredUser;
	    const userDetail = userDiffers ? `${configuredUser} / ${remoteUser}` : (remoteUser || configuredUser);
	    const sudoState = result.passwordless_sudo || 'unknown';
	    const sudoLabel = sudoState === 'ready' || sudoState === 'root' ? 'Ready' : (sudoState === 'setup_required' ? 'Setup required' : 'Unknown');
	    setServerStatusPill(card.querySelector('[data-server-card-status]'), reachable);
	    setServerStatusPill(card.querySelector('[data-server-detail-status]'), reachable);
	    const lastCheckedNodes = card.querySelectorAll('[data-server-card-last-checked], [data-server-detail-last-checked]');
	    lastCheckedNodes.forEach((node) => { node.textContent = checkedAt; });
	    const responseNodes = card.querySelectorAll('[data-server-card-response], [data-server-detail-response]');
	    responseNodes.forEach((node) => { node.textContent = response; });
	    const hostNode = card.querySelector('[data-server-detail-host]');
	    if (hostNode) hostNode.textContent = hostDetail || 'Not configured';
	    const userLabelNode = card.querySelector('[data-server-detail-user-label]');
	    if (userLabelNode) userLabelNode.textContent = userDiffers ? 'SSH User / Remote user' : 'SSH User';
	    const userNode = card.querySelector('[data-server-detail-user]');
	    if (userNode) userNode.textContent = userDetail || 'Not configured';
	    const workingDirectoryNode = card.querySelector('[data-server-detail-working-directory]');
	    if (workingDirectoryNode) workingDirectoryNode.textContent = result.working_directory || 'Not configured';
	    const sshAccessNode = card.querySelector('[data-server-detail-ssh-access]');
	    if (sshAccessNode) sshAccessNode.textContent = reachable ? 'Ready' : 'Not ready';
	    const sudoNode = card.querySelector('[data-server-detail-sudo]');
	    if (sudoNode) sudoNode.textContent = reachable ? sudoLabel : 'Unknown';
	    const phpNode = card.querySelector('[data-server-detail-php]');
	    if (phpNode && Object.prototype.hasOwnProperty.call(result, 'php_installed')) {
	      phpNode.textContent = result.php_installed ? `Installed${result.php_version ? ` ${result.php_version}` : ''}` : 'Missing';
	    }
	    const composerNode = card.querySelector('[data-server-detail-composer]');
	    if (composerNode && Object.prototype.hasOwnProperty.call(result, 'composer_installed')) {
	      composerNode.textContent = result.composer_installed ? `Installed${result.composer_version ? ` ${result.composer_version}` : ''}` : 'Missing';
	    }
	    const errorNode = card.querySelector('[data-server-detail-error]');
	    if (errorNode) errorNode.textContent = reachable ? '' : (result.message || 'SSH connection failed');
	  };
  const updateSelectedManagedServerSummary = (operation) => {
    const result = operation.result || {};
    const completed = operation.status === 'completed' && result.success !== false;
    const sudoState = result.passwordless_sudo || 'unknown';
    const sudoLabel = sudoState === 'ready' || sudoState === 'root' ? 'Ready' : (sudoState === 'setup_required' ? 'Not available' : 'Unknown');
    const setDetail = (name, value) => {
      document.querySelectorAll(`[data-selected-server-detail="${name}"]`).forEach((node) => {
        node.textContent = value || 'Not configured';
      });
    };
    if (operation.operation_action === 'connection_test') {
      const checkedAt = operation.finished_at || new Date().toISOString();
      setDetail('reachability', completed ? 'Reachable' : 'Unreachable');
      document.querySelectorAll('[data-selected-server-detail="reachability"]').forEach((node) => {
        node.classList.toggle('healthy', completed);
        node.classList.toggle('error', !completed);
        node.classList.remove('warning');
      });
      setDetail('last_checked', checkedAt);
      setDetail('hostname', result.hostname || '');
      setDetail('os', result.os || '');
      setDetail('sudo', sudoLabel);
      setDetail('tool_sudo', sudoLabel);
    }
    ['php', 'composer', 'node', 'npm'].forEach((tool) => {
      const installedKey = `${tool}_installed`;
      if (!Object.prototype.hasOwnProperty.call(result, installedKey)) return;
      const installed = Boolean(result[installedKey]);
      setDetail(`tool_${tool}`, installed ? 'Installed' : 'Missing');
      document.querySelectorAll(`[data-selected-server-detail="tool_${tool}"]`).forEach((node) => {
        node.classList.toggle('healthy', installed);
        node.classList.toggle('warning', !installed);
      });
      setDetail(`tool_${tool}_version`, result[`${tool}_version`] || '');
      setDetail(`tool_${tool}_path`, result[`${tool}_path`] || '');
    });
    if (result.apache && typeof result.apache === 'object') {
      const apache = result.apache;
      const detected = Boolean(apache.installed || apache.binary_path || apache.version || apache.running === true || apache.enabled === true);
      const status = !detected
        ? (completed ? 'Not installed' : 'Unknown')
        : (apache.running === true ? 'Running' : (apache.running === false ? 'Installed, stopped' : 'Installed'));
      const enabled = apache.enabled === true ? 'Yes' : (apache.enabled === false ? 'No' : 'Unknown');
      setDetail('apache_status', status);
      setDetail('apache_enabled', enabled);
      setDetail('apache_version', apache.version || '');
      setDetail('apache_path', apache.binary_path || '');
    }
  };
	  const showManagedServerOperation = (operation, target = 'servers') => {
    const elements = managedServerPanelElements(target);
    if (!elements.panel) return;
    const result = operation.result || {};
    elements.panel.hidden = false;
    elements.panel.classList.toggle('failed', operation.status === 'failed');
    if (elements.title) elements.title.textContent = operation.operation_action === 'install_composer' ? 'Install Composer' : 'Refresh Diagnostics';
    if (elements.server) elements.server.textContent = operation.server_name || operation.server_id || '-';
    if (elements.status) elements.status.textContent = String(operation.status || 'running').replace(/^\w/, (letter) => letter.toUpperCase());
    if (elements.stage) elements.stage.textContent = operation.stage || 'Starting';
    if (elements.elapsed) elements.elapsed.textContent = formatElapsed(operation.elapsed_seconds || 0);
    if (elements.message) elements.message.textContent = operation.message || 'Testing SSH connection.';
    if (elements.log) {
      elements.log.textContent = operation.log && operation.log.trim() !== '' ? operation.log : 'Waiting for operation log...';
      elements.log.scrollTop = elements.log.scrollHeight;
    }
    if (elements.details) {
      const hasDetails = Boolean(result.hostname || result.os || result.kernel || result.remote_user || result.working_directory || result.round_trip_ms || result.passwordless_sudo || Object.prototype.hasOwnProperty.call(result, 'php_installed') || Object.prototype.hasOwnProperty.call(result, 'composer_installed'));
      elements.details.hidden = !hasDetails;
      const sudoState = result.passwordless_sudo || 'unknown';
      const sudoLabel = sudoState === 'ready' || sudoState === 'root' ? 'Ready' : (sudoState === 'setup_required' ? 'Setup required' : 'Unknown');
      if (elements.sshAccess) elements.sshAccess.textContent = result.success === false ? 'Not ready' : (hasDetails ? 'Ready' : '-');
      if (elements.sudo) elements.sudo.textContent = sudoLabel;
      if (elements.hostname) elements.hostname.textContent = result.hostname || '-';
      if (elements.os) elements.os.textContent = result.os || '-';
      if (elements.kernel) elements.kernel.textContent = result.kernel || '-';
      if (elements.user) elements.user.textContent = result.remote_user || '-';
      if (elements.workingDirectory) elements.workingDirectory.textContent = result.working_directory || '-';
      if (elements.rtt) elements.rtt.textContent = result.round_trip_ms ? `${result.round_trip_ms} ms` : '-';
    }
  };
  const pollManagedServerOperation = (operationId, serverId, target = 'servers') => {
    let poll = null;
    const update = async () => {
      const response = await fetch(`?action=managed-server-operation-status&id=${encodeURIComponent(operationId)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to read managed server operation.');
      showManagedServerOperation(payload.operation, target);
	      if (payload.operation.status === 'completed' || payload.operation.status === 'failed') {
	        clearInterval(poll);
	        setManagedServerButtons(serverId, false);
	        updateManagedServerCard(payload.operation);
          if (target === 'server-management') updateSelectedManagedServerSummary(payload.operation);
	      }
    };
    update().catch((error) => {
      clearInterval(poll);
      setManagedServerButtons(serverId, false);
      const elements = managedServerPanelElements(target);
      if (elements.panel) elements.panel.hidden = false;
      if (elements.panel) elements.panel.classList.add('failed');
      if (elements.message) elements.message.textContent = error.message;
      if (elements.status) elements.status.textContent = 'Failed';
    });
    poll = window.setInterval(() => update().catch((error) => {
      clearInterval(poll);
      setManagedServerButtons(serverId, false);
      const elements = managedServerPanelElements(target);
      if (elements.message) elements.message.textContent = error.message;
      if (elements.status) elements.status.textContent = 'Failed';
    }), 1000);
  };
  managedServerForms.forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const serverId = form.dataset.serverId || '';
      const target = form.dataset.operationTarget || 'servers';
      setManagedServerButtons(serverId, true);
      showManagedServerOperation({
        server_id: serverId,
        server_name: form.dataset.serverName || serverId,
        status: 'running',
        stage: 'Starting',
        elapsed_seconds: 0,
        operation_action: (form.querySelector('input[name="action"]')?.value || '') === 'install_managed_server_composer' ? 'install_composer' : 'connection_test',
        message: (form.querySelector('input[name="action"]')?.value || '') === 'install_managed_server_composer' ? 'Starting Composer installation.' : 'Starting SSH connection test.',
        log: '',
      }, target);
      try {
        const response = await fetch(form.getAttribute('action') || '/?tab=servers#managed-servers', { method: 'POST', body: new FormData(form) });
        const payload = await response.json();
        if (!payload.ok) throw new Error(payload.error || 'Unable to start SSH connection test.');
        showManagedServerOperation(payload.operation, target);
        pollManagedServerOperation(payload.operation.id, serverId, target);
      } catch (error) {
        setManagedServerButtons(serverId, false);
        const elements = managedServerPanelElements(target);
        if (elements.panel) elements.panel.classList.add('failed');
        if (elements.message) elements.message.textContent = error.message;
        if (elements.status) elements.status.textContent = 'Failed';
      }
    });
  });

  const projectNameInput = document.getElementById('project_name');
  const productionDomainInput = document.getElementById('production_domain');
  const projectIdPreview = document.getElementById('projectIdPreview');
  const repositoryPreview = document.getElementById('repositoryPreview');
  const productionDomainPreview = document.getElementById('productionDomainPreview');
  const previewDomainPreview = document.getElementById('previewDomainPreview');
  const productionDirectoryPreview = document.getElementById('productionDirectoryPreview');
  const previewDirectoryPreview = document.getElementById('previewDirectoryPreview');
  const slugFromProjectName = (value) => String(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
  const normalizeDomainPreview = (value) => String(value)
    .trim()
    .replace(/^https?:\/\//i, '')
    .split(/[/:?#]/)[0]
    .replace(/\.$/, '')
    .toLowerCase();
  const setPreviewText = (element, value) => {
    if (element) element.textContent = value || '-';
  };
  const generatedPathTemplates = <?= json_encode($generatedPathTemplates, JSON_UNESCAPED_SLASHES) ?>;
  const generatedPath = (key, slug) => slug ? String(generatedPathTemplates[key] || '').replace('__PROJECT_ID__', slug) : '';
  const updateGeneratedPreview = () => {
    const slug = slugFromProjectName(projectNameInput?.value || '');
    const domain = normalizeDomainPreview(productionDomainInput?.value || '');
    setPreviewText(projectIdPreview, slug);
    setPreviewText(repositoryPreview, generatedPath('repository', slug));
    setPreviewText(productionDomainPreview, domain);
    setPreviewText(previewDomainPreview, domain ? `preview.${domain}` : '');
    setPreviewText(productionDirectoryPreview, generatedPath('production', slug));
    setPreviewText(previewDirectoryPreview, generatedPath('preview', slug));
  };
  projectNameInput?.addEventListener('input', updateGeneratedPreview);
  productionDomainInput?.addEventListener('input', updateGeneratedPreview);
  updateGeneratedPreview();
  window.addEventListener('scroll', () => {
    if (scrollSaveFrame !== null) cancelAnimationFrame(scrollSaveFrame);
    scrollSaveFrame = requestAnimationFrame(() => {
      saveScrollPosition();
      scrollSaveFrame = null;
    });
  }, { passive: true });
  window.addEventListener('pagehide', saveScrollPosition);
  const dashboardEscape = (value) => String(value).replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const formatBytes = (bytes) => {
    let value = Number(bytes) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
    return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
  };
  const statusClass = (status) => {
    const value = String(status).toLowerCase();
    if (['running', 'success', 'healthy'].includes(value)) return 'healthy';
    if (['pending', 'not_started'].includes(value)) return 'warning';
    if (['unknown', 'not configured', 'not_configured', 'not deployed', 'not_deployed', 'not available', 'not_available', ''].includes(value)) return 'quiet';
    return 'error';
  };
  const statusLabel = (status) => String(status).replaceAll('_', ' ');
  const dashboardCard = (title, rows, extraClass = '') => `<section class="dashboard-card ${extraClass}"><h3>${dashboardEscape(title)}</h3><dl class="dashboard-list">${rows.map(([label, value]) => `<div><dt>${dashboardEscape(label)}</dt><dd>${value}</dd></div>`).join('')}</dl></section>`;
  const environmentSummaryCard = (title, subtitle, rows, resources) => `<section class="dashboard-card environment-summary-card"><h3>${dashboardEscape(title)}<span>${dashboardEscape(subtitle)}</span></h3><div class="environment-metadata-row">${rows.map(([label, value]) => `<span><b>${dashboardEscape(label)}</b> ${value}</span>`).join('')}</div>${resources ? `<div class="resource-grid">${resources}</div>` : ''}</section>`;
  const dashboardLink = (url) => `<a href="${dashboardEscape(url)}" target="_blank" rel="noopener noreferrer">${dashboardEscape(url)}</a>`;
  const dashboardStatus = (status) => `<span class="status-pill ${statusClass(status)}">${dashboardEscape(statusLabel(status))}</span>`;
  const safeLink = (url) => url ? dashboardLink(url) : 'Not configured';
  const healthItem = (label, state) => `<span class="health-item ${state}"><span class="health-dot" aria-hidden="true"></span>${dashboardEscape(label)}</span>`;
  const resourceBar = (label, percentage, numericValue) => {
    const value = Math.max(0, Math.min(100, Number(percentage) || 0));
    const state = value >= 90 ? 'error' : (value >= 80 ? 'warning' : 'healthy');
    return `<div><div class="resource-head"><span>${dashboardEscape(label)}</span><span>${dashboardEscape(`${value}%`)}</span></div><div class="progress-track" role="progressbar" aria-label="${dashboardEscape(label)} usage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${value}"><div class="progress-bar ${state}" style="width:${value}%"></div></div><p class="resource-value">${dashboardEscape(numericValue)}</p></div>`;
  };
  let environmentRefreshInProgress = false;
  const refreshEnvironmentDashboard = async () => {
    if (!environmentDashboard || environmentRefreshInProgress) return;
    environmentRefreshInProgress = true;
    try {
      const topProcessesWasOpen = environmentDashboard.querySelector('#topProcesses')?.open ?? false;
      const managedProcessesWasOpen = environmentDashboard.querySelector('#managedTopProcesses')?.open ?? false;
      const response = await fetch('?action=environment-status', { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to load environment status.');
      const data = payload.dashboard;
      const development = data.environment.development;
      const preview = data.environment.preview;
      const production = data.environment.production;
      const memory = data.server.memory;
      const disk = data.server.disk;
      const managedServer = data.managed_server || {};
      const managedMetrics = managedServer.server || null;
      const managedMemory = managedMetrics?.memory || { percentage: 0, used: 0, total: 0 };
      const managedDisk = managedMetrics?.disk || { percentage: 0, used: 0, total: 0 };
      const processes = data.processes.length ? data.processes.map((process) => `<tr><td>${process.pid}</td><td>${dashboardEscape(process.user)}</td><td>${process.cpu.toFixed(1)}</td><td>${process.memory.toFixed(1)}</td><td>${dashboardEscape(process.command)}</td></tr>`).join('') : '<tr><td colspan="5">No process data available.</td></tr>';
      const managedProcesses = Array.isArray(managedServer.processes) && managedServer.processes.length ? managedServer.processes.map((process) => `<tr><td>${process.pid}</td><td>${dashboardEscape(process.user)}</td><td>${Number(process.cpu || 0).toFixed(1)}</td><td>${Number(process.memory || 0).toFixed(1)}</td><td>${dashboardEscape(process.command || '')}</td></tr>`).join('') : '<tr><td colspan="5">No managed server process data available.</td></tr>';
      const storage = managedServer.storage || {};
      const storageRows = (environment, label) => {
        const item = storage[environment] || {};
        if (item.status === 'available') {
          return [[`${label} size`, formatBytes(item.bytes || 0)], [`${label} files`, Number(item.files || 0).toLocaleString()]];
        }
        const state = item.status === 'not_deployed' ? 'Not deployed' : (item.status === 'not_readable' ? 'Not readable' : 'Not available');
        return [[label, dashboardEscape(state)]];
      };
      const previewHealth = statusClass(preview.status);
      const productionHealth = statusClass(production.status);
      const consoleHealth = statusClass(data.environment.console.status);
      const gitHealth = /connected|ready|initialized/i.test(activeGitStatusLabel) ? 'healthy' : (/not|incomplete|failed|error/i.test(activeGitStatusLabel) ? 'error' : 'warning');
      const apache = managedServer.apache || {};
      const sites = Array.isArray(managedServer.apache_sites) ? managedServer.apache_sites : [];
      const projectSites = sites.filter((site) => site && site.project_id === activeProjectId && site.managed_marker);
      const webHealth = apache.installed === false ? 'error' : (projectSites.some((site) => site.enabled === true) ? 'healthy' : (projectSites.length ? 'warning' : 'quiet'));
      const tailscaleHealth = 'quiet';
      const tailscaleLabel = 'Tailscale: Unknown';
      const devConsoleResources = `${resourceBar('CPU', data.server.load_percentage, `Load ${data.server.load.join(' / ') || 'not detected'}`)}${resourceBar('Memory', memory.percentage, `${formatBytes(memory.used)} / ${formatBytes(memory.total)}`)}${resourceBar('Disk', disk.percentage, `${formatBytes(disk.used)} / ${formatBytes(disk.total)}`)}`;
      const managedResources = managedMetrics && managedServer.available
        ? `${resourceBar('CPU', managedMetrics.load_percentage, `Load ${(managedMetrics.load || []).join(' / ') || 'not detected'}`)}${resourceBar('Memory', managedMemory.percentage, `${formatBytes(managedMemory.used)} / ${formatBytes(managedMemory.total)}`)}${resourceBar('Disk', managedDisk.percentage, `${formatBytes(managedDisk.used)} / ${formatBytes(managedDisk.total)}`)}`
        : '';
      const devConsoleHostRows = [
        ['Status', dashboardStatus(data.environment.console.status)],
        ['Repository size', formatBytes(data.statistics.development.bytes)],
        ['Repository files', data.statistics.development.files.toLocaleString()],
      ];
      const managedServerRows = [
        ['Server', dashboardEscape(activeManagedServerLabel || 'Not configured')],
        ['Status', dashboardEscape(activeManagedServerStatus || 'Unknown')],
        ...storageRows('preview', 'Preview'),
        ...storageRows('production', 'Production'),
      ];
      if (!managedResources) {
        managedServerRows.push(['Diagnostics', dashboardEscape(managedServer.message || 'Unavailable until the server is reachable')]);
      }
      environmentDashboard.innerHTML =
        `<section class="dashboard-card environment-status-card"><div class="health-row">${healthItem(`Preview: ${statusLabel(preview.status)}`, previewHealth)}${healthItem(`Production: ${statusLabel(production.status)}`, productionHealth)}${healthItem('Dev Console: Running', consoleHealth)}${healthItem(`Git: ${activeGitStatusLabel || 'Unknown'}`, gitHealth)}${healthItem(`Project Apache: ${projectSites.length ? 'Configured' : 'Unknown'}`, webHealth)}${healthItem(tailscaleLabel, tailscaleHealth)}</div></section>` +
        `<div class="environment-host-grid">` +
        environmentSummaryCard('Dev Console Host', 'Runtime and repository', devConsoleHostRows, devConsoleResources) +
        environmentSummaryCard('Managed Server', managedServer.available ? 'Remote infrastructure' : 'Not reachable', managedServerRows, managedResources) +
        `</div>` +
        `<div class="environment-host-grid">` +
        `<details class="dashboard-card compact-details dashboard-processes" id="topProcesses"${topProcessesWasOpen ? ' open' : ''}><summary>Dev Console Host Top Processes</summary><table class="process-table"><thead><tr><th>PID</th><th>User</th><th>CPU %</th><th>Memory %</th><th>Command</th></tr></thead><tbody>${processes}</tbody></table></details>` +
        `<details class="dashboard-card compact-details dashboard-processes" id="managedTopProcesses"${managedProcessesWasOpen ? ' open' : ''}><summary>Managed Server Top Processes</summary><table class="process-table"><thead><tr><th>PID</th><th>User</th><th>CPU %</th><th>Memory %</th><th>Command</th></tr></thead><tbody>${managedProcesses}</tbody></table></details>` +
        `</div>`;
      dashboardUpdated.textContent = `Updated ${new Date(data.generated_at).toLocaleTimeString()}`;
    } finally {
      environmentRefreshInProgress = false;
    }
  };

  if (form && form.dataset.created === '1') {
    localStorage.removeItem(draftKey);
  }

  if (textarea && !textarea.value) {
    textarea.value = localStorage.getItem(draftKey) || textarea.dataset.defaultTemplate || '';
  }

  textarea?.addEventListener('input', () => {
    localStorage.setItem(draftKey, textarea.value);
    if (draftStatus) {
      draftStatus.textContent = 'Draft saved locally.';
    }
  });

  clearDraft?.addEventListener('click', () => {
    localStorage.removeItem(draftKey);
    if (textarea) {
      textarea.value = textarea.dataset.defaultTemplate || '';
      textarea.focus();
    }
    if (draftStatus) {
      draftStatus.textContent = 'Draft cleared.';
    }
  });

  newTaskAction?.addEventListener('click', () => {
    localStorage.removeItem(draftKey);
  });

  document.querySelectorAll('[data-project-card], [data-server-card]').forEach((card) => {
    const toggle = card.querySelector('[data-project-toggle]');
    const details = card.querySelector('.project-details:not([data-server-edit-panel])');
    const setExpanded = (expanded) => {
      card.dataset.expanded = expanded ? '1' : '0';
      if (details) details.hidden = !expanded;
      if (toggle) {
        toggle.textContent = expanded ? 'Hide details' : 'Show details';
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
    };
    setExpanded(card.dataset.expanded === '1');
    toggle?.addEventListener('click', () => {
      setExpanded(card.dataset.expanded !== '1');
    });
  });

  const projectCreatePanel = document.querySelector('[data-project-create-panel]');
  const projectEditPanels = Array.from(document.querySelectorAll('[data-project-edit-panel]'));
  const projectSidePanels = Array.from(document.querySelectorAll('[data-project-side-panel]'));
  const projectEditToggles = Array.from(document.querySelectorAll('[data-project-edit-toggle]'));
  const setProjectSidebar = (projectId = '', editing = false) => {
    if (projectCreatePanel) projectCreatePanel.hidden = editing;
    projectEditPanels.forEach((panel) => {
      panel.hidden = !editing || panel.dataset.projectEditId !== projectId;
    });
    projectSidePanels.forEach((panel) => {
      panel.hidden = panel.dataset.projectSideId !== projectId;
    });
    projectEditToggles.forEach((toggle) => {
      const card = toggle.closest('[data-project-card]');
      const active = editing && card?.dataset.projectId === projectId;
      toggle.textContent = active ? 'Editing' : 'Edit';
      toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
    });
  };
  document.querySelectorAll('[data-project-card]').forEach((card) => {
    const toggle = card.querySelector('[data-project-edit-toggle]');
    const detailsToggle = card.querySelector('[data-project-toggle]');
    detailsToggle?.addEventListener('click', () => {
      if (card.dataset.expanded === '1') {
        setProjectSidebar(card.dataset.projectId || '', false);
      }
    });
    toggle?.addEventListener('click', () => {
      const projectId = card.dataset.projectId || '';
      const alreadyEditing = projectEditPanels.some((panel) => !panel.hidden && panel.dataset.projectEditId === projectId);
      setProjectSidebar(projectId, !alreadyEditing);
    });
  });
  document.querySelectorAll('[data-project-edit-cancel]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.closest('[data-project-edit-panel]');
      setProjectSidebar(panel?.dataset.projectEditId || '', false);
    });
  });

  const serverAddPanel = document.querySelector('[data-server-add-panel]');
  const serverEditPanels = Array.from(document.querySelectorAll('[data-server-edit-panel]'));
  const serverEditToggles = Array.from(document.querySelectorAll('[data-server-edit-toggle]'));
  const serverSetupCommand = document.querySelector('[data-server-public-key]');
  const serverSetupNoSelectionMessage = 'Select an existing Managed Server to generate the setup command for that server.';
  const serverShellQuote = (value) => `'${String(value).replaceAll("'", "'\"'\"'")}'`;
  const managedServerSetupCommandForUser = (publicKey, user, emptyMessage = serverSetupNoSelectionMessage) => {
    if (!publicKey) {
      return serverSetupNoSelectionMessage;
    }
    if (!user) {
      return emptyMessage;
    }
    if (!/^[a-z_][a-z0-9_-]*$/i.test(user)) {
      return 'Enter a valid Linux SSH username to generate the setup command.';
    }
    const sudoersPath = `/etc/sudoers.d/dev-console-${user.replace(/[^A-Za-z0-9_-]/g, '_')}`;
    const lines = [
      'set -eu',
      `DEV_CONSOLE_EXPECTED_USER=${serverShellQuote(user)}`,
      'DEV_CONSOLE_USER="$(id -un)"',
      'DEV_CONSOLE_GROUP="$(id -gn)"',
      'if [ "$DEV_CONSOLE_USER" != "$DEV_CONSOLE_EXPECTED_USER" ]; then printf "%s\\n" "Run this command as $DEV_CONSOLE_EXPECTED_USER, not $DEV_CONSOLE_USER." >&2; exit 1; fi',
      'case "$DEV_CONSOLE_USER" in ""|[0-9]*|*[!A-Za-z0-9_-]*) printf "%s\\n" "Unsafe Linux username: $DEV_CONSOLE_USER" >&2; exit 1 ;; esac',
      'mkdir -p "$HOME/.ssh"',
      'chmod 700 "$HOME/.ssh"',
      'touch "$HOME/.ssh/authorized_keys"',
      'chmod 600 "$HOME/.ssh/authorized_keys"',
      `grep -qxF ${serverShellQuote(publicKey)} "$HOME/.ssh/authorized_keys" || printf ${serverShellQuote('%s\\n')} ${serverShellQuote(publicKey)} >> "$HOME/.ssh/authorized_keys"`,
      'chmod 600 "$HOME/.ssh/authorized_keys"',
    ];
    if (user === 'root') {
      lines.push('install -d -m 0755 /var/www/projects');
    } else {
      lines.push(
        'sudoers_tmp="$(mktemp)"',
        `trap ${serverShellQuote('rm -f "$sudoers_tmp"')} EXIT`,
        `printf ${serverShellQuote('%s ALL=(ALL) NOPASSWD: ALL\\n')} "$DEV_CONSOLE_USER" > "$sudoers_tmp"`,
        'sudo visudo -cf "$sudoers_tmp"',
        `sudo install -m 0440 -o root -g root "$sudoers_tmp" ${serverShellQuote(sudoersPath)}`,
        `sudo visudo -cf ${serverShellQuote(sudoersPath)}`,
        'sudo install -d -m 0755 -o "$DEV_CONSOLE_USER" -g "$DEV_CONSOLE_GROUP" /var/www/projects',
      );
    }
    return `(\n  ${lines.join('\n  ')}\n)`;
  };
  const updateServerSetupCommand = () => {
    if (!serverSetupCommand) return;
    const activeEditPanel = serverEditPanels.find((panel) => !panel.hidden);
    const addUserInput = serverAddPanel?.querySelector('input[name="server_user"]');
    const addUserActive = addUserInput && (document.activeElement === addUserInput || (addUserInput.value || '').trim() !== '');
    const activePanel = activeEditPanel || (addUserActive ? serverAddPanel : null);
    const userInput = activePanel?.querySelector('input[name="server_user"]');
    const user = (userInput?.value || '').trim();
    const emptyMessage = activePanel === serverAddPanel
      ? 'Enter the SSH username for the new server to generate the setup command.'
      : serverSetupNoSelectionMessage;
    const command = managedServerSetupCommandForUser(serverSetupCommand.dataset.serverPublicKey || '', user, emptyMessage);
    if (serverSetupCommand instanceof HTMLTextAreaElement || serverSetupCommand instanceof HTMLInputElement) {
      serverSetupCommand.value = command;
    } else {
      serverSetupCommand.textContent = command;
    }
  };
  const setServerEditing = (serverId = '') => {
    serverEditPanels.forEach((panel) => {
      panel.hidden = (panel.dataset.serverEditId || '') !== serverId;
    });
    serverEditToggles.forEach((toggle) => {
      const active = (toggle.dataset.serverId || '') === serverId;
      toggle.textContent = active ? 'Cancel edit' : 'Select/Edit';
      toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
    });
    if (serverAddPanel) serverAddPanel.hidden = serverId !== '';
    updateServerSetupCommand();
  };
  setServerEditing('');
  serverEditToggles.forEach((toggle) => {
    toggle.addEventListener('click', () => {
      const serverId = toggle.dataset.serverId || '';
      setServerEditing(toggle.getAttribute('aria-expanded') === 'true' ? '' : serverId);
    });
  });
  document.querySelectorAll('[data-server-edit-cancel]').forEach((button) => {
    button.addEventListener('click', () => setServerEditing(''));
  });
  document.querySelectorAll('input[name="server_user"]').forEach((input) => {
    input.addEventListener('focus', updateServerSetupCommand);
    input.addEventListener('input', updateServerSetupCommand);
  });

  let pendingFiles = [];

  const syncFileInput = () => {
    if (!fileInput) {
      return;
    }

    const transfer = new DataTransfer();
    pendingFiles.slice(0, 5).forEach((file) => transfer.items.add(file));
    pendingFiles = Array.from(transfer.files);
    fileInput.files = transfer.files;
  };

  const renderSelectedFiles = () => {
    if (!selectedFiles) {
      return;
    }

    if (pendingFiles.length === 0) {
      selectedFiles.textContent = 'No files selected.';
      return;
    }

    const list = document.createElement('ul');
    pendingFiles.forEach((file, index) => {
      const item = document.createElement('li');
      const name = document.createElement('span');
      const remove = document.createElement('button');

      name.textContent = `✓ ${file.name}`;
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.addEventListener('click', () => {
        pendingFiles.splice(index, 1);
        syncFileInput();
        renderSelectedFiles();
      });

      item.append(name, remove);
      list.append(item);
    });

    selectedFiles.replaceChildren(list);
  };

  const setSelectedFiles = (files) => {
    pendingFiles = Array.from(files).slice(0, 5);
    syncFileInput();
    renderSelectedFiles();
  };

  fileInput?.addEventListener('change', () => {
    setSelectedFiles(fileInput.files);
  });

  ['dragenter', 'dragover'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.add('dragover');
    });
  });

  ['dragleave', 'drop'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.remove('dragover');
    });
  });

  uploadZone?.addEventListener('drop', (event) => {
    if (!fileInput || !event.dataTransfer || !event.dataTransfer.files.length) {
      return;
    }
    setSelectedFiles(event.dataTransfer.files);
  });

  const taskForCodexPanel = () => codexRunPanel?.dataset.task || '';
  const taskSourceForCodexPanel = () => codexRunPanel?.dataset.taskSource || 'project';

  const updateCodexLog = async (scrollToBottom = false) => {
    if (!codexConsole || !codexRunPanel) {
      return;
    }
    const response = await fetch(`?action=codex-log&task=${encodeURIComponent(taskForCodexPanel())}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`, { cache: 'no-store' });
    const logText = await response.text();
    codexConsole.textContent = logText;
    if (scrollToBottom) {
      codexConsole.scrollTop = codexConsole.scrollHeight;
    }
  };

  const updateCodexStatus = async () => {
    if (!codexRunPanel || !codexStatus) {
      return 'not_started';
    }
    const response = await fetch(`?action=codex-status&task=${encodeURIComponent(taskForCodexPanel())}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`, { cache: 'no-store' });
    const payload = await response.json();
    if (!payload.ok) {
      throw new Error(payload.error || 'Unable to read Codex status.');
    }
    codexStatus.textContent = payload.label;
    codexStatus.dataset.status = payload.status;
    return payload.status;
  };

  let codexPoll = null;
  const startCodexPolling = () => {
    if (codexPoll) {
      clearInterval(codexPoll);
    }
    codexPoll = setInterval(async () => {
      try {
        const status = await updateCodexStatus();
        await updateCodexLog(status === 'queued' || status === 'running');
        if (status === 'completed' || status === 'failed') {
          clearInterval(codexPoll);
          if (status === 'completed') {
            window.setTimeout(() => {
              saveScrollPosition();
              window.location.href = `?tab=dashboard&task=${encodeURIComponent(`${taskForCodexPanel()}.md`)}&task_source=${encodeURIComponent(taskSourceForCodexPanel())}`;
            }, 1000);
          }
        }
      } catch (error) {
        if (codexStatus) {
          codexStatus.textContent = 'Failed';
        }
        clearInterval(codexPoll);
      }
    }, 3000);
  };

  runCodex?.addEventListener('click', async () => {
    if (!runCodex || !codexStatus) {
      return;
    }
    runCodex.disabled = true;
    codexStatus.textContent = 'Queued';
    const formData = new FormData();
    formData.set('action', 'run-codex');
    formData.set('csrf_token', csrfToken);
    formData.set('task', runCodex.dataset.task || '');
    formData.set('task_source', runCodex.dataset.taskSource || 'project');

    try {
      const response = await fetch('', { method: 'POST', body: formData });
      const payload = await response.json();
      if (!payload.ok) {
        throw new Error(payload.error || 'Unable to start Codex.');
      }
      codexStatus.textContent = payload.label;
      await updateCodexLog(true);
      startCodexPolling();
    } catch (error) {
      codexStatus.textContent = 'Failed';
      runCodex.disabled = false;
    }
  });

  refreshCodexLog?.addEventListener('click', () => {
    updateCodexLog(false).catch(() => {
      if (codexConsole) {
        codexConsole.textContent = 'Unable to load activity.';
      }
    });
  });

  copyCodexLog?.addEventListener('click', async () => {
    if (!codexConsole || !copyCodexLog) return;
    try {
      await navigator.clipboard.writeText(codexConsole.textContent || '');
      copyCodexLog.textContent = 'Copied';
      if (copyCodexMessage) copyCodexMessage.textContent = '';
      window.setTimeout(() => { copyCodexLog.textContent = 'Copy to Clipboard'; }, 1500);
    } catch (error) {
      if (copyCodexMessage) copyCodexMessage.textContent = 'Unable to copy activity.';
    }
  });
  downloadCodexLog?.addEventListener('click', () => {
    if (!codexConsole) return;
    const blob = new Blob([codexConsole.textContent || ''], { type: 'text/plain;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${taskForCodexPanel() || 'codex-run'}.log`;
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
  });

  const deployButton = document.getElementById('deployProduction');
  const previewDeployButton = document.getElementById('deployPreview');
  const previewDeployDialog = document.getElementById('previewDeploymentDialog');
  const previewSummaryBox = document.getElementById('previewDeploymentSummary');
  const previewConfirmButton = document.getElementById('confirmPreviewDeployment');
  const previewCancelButton = document.getElementById('cancelPreviewDeployment');
  const previewModalError = document.getElementById('previewModalDeploymentError');
  const deployDialog = document.getElementById('deploymentDialog');
  const previewBox = document.getElementById('deploymentPreview');
  const confirmButton = document.getElementById('confirmDeployment');
  const cancelButton = document.getElementById('cancelDeployment');
  const deploymentError = document.getElementById('productionDeploymentError');
  const modalError = document.getElementById('modalDeploymentError');
  const deploymentLog = document.getElementById('productionDeploymentLog');
  const deploymentStatus = document.getElementById('productionDeploymentStatus');
  const productionDeploymentStage = document.getElementById('productionDeploymentStage');
  const productionDeploymentElapsed = document.getElementById('productionDeploymentElapsed');
  const productionDeploymentProgress = document.getElementById('productionDeploymentProgress');
  const productionDeploymentDuration = document.getElementById('productionDeploymentDuration');
  const productionVersionState = document.getElementById('productionVersionState');
  const productionPreflight = document.getElementById('productionPreflight');
  const productionPreflightStatus = document.getElementById('productionPreflightStatus');
  const productionPreflightMessage = document.getElementById('productionPreflightMessage');
  const productionPreflightCounts = document.getElementById('productionPreflightCounts');
  const productionPreflightDeletes = document.getElementById('productionPreflightDeletes');
  const productionPreservePaths = document.getElementById('productionPreservePaths');
  const refreshProductionPreflight = document.getElementById('refreshProductionPreflight');
  const approveProductionDeletions = document.getElementById('approveProductionDeletions');
  const previewDeploymentError = document.getElementById('previewDeploymentError');
  const previewDeploymentLog = document.getElementById('previewDeploymentLog');
  const previewDeploymentStatus = document.getElementById('previewDeploymentStatus');
  const previewDeploymentStage = document.getElementById('previewDeploymentStage');
  const previewDeploymentElapsed = document.getElementById('previewDeploymentElapsed');
  const previewDeploymentProgress = document.getElementById('previewDeploymentProgress');
  const previewDeploymentCommit = document.getElementById('previewDeploymentCommit');
  const previewDeploymentSourceCommit = document.getElementById('previewDeploymentSourceCommit');
  const previewDeploymentBranch = document.getElementById('previewDeploymentBranch');
  const previewDeploymentDuration = document.getElementById('previewDeploymentDuration');
  let previewConfirmedSummary = null;
  const deploymentPolls = { preview: null, production: null };
  const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));

  const postDeployment = async (action, values = {}) => {
    const body = new FormData();
    body.set('action', action); body.set('csrf_token', csrfToken);
    Object.entries(values).forEach(([key, value]) => body.set(key, value));
    const response = await fetch(`?action=${encodeURIComponent(action)}`, { method: 'POST', body });
    const payload = await response.json();
    if (!payload.ok) throw new Error(payload.error || 'Deployment request failed.');
    return payload;
  };
  const setDeploymentStatus = (environment, status) => {
    const element = environment === 'preview' ? previewDeploymentStatus : deploymentStatus;
    element.textContent = status.charAt(0).toUpperCase() + status.slice(1);
    element.className = `deployment-status ${status}`;
  };
  const setWorkflowStage = (name, state, primary = '', detail = '') => {
    const stage = document.querySelector(`[data-workflow-stage="${name}"]`);
    if (!stage) return;
    const pill = stage.querySelector('[data-workflow-state]');
    const primaryElement = stage.querySelector('[data-workflow-primary]');
    const detailElement = stage.querySelector('[data-workflow-detail]');
    if (pill) {
      pill.textContent = state;
      pill.className = `status-pill ${state === 'Failed' ? 'error' : (['Deployed', 'Completed', 'In sync with Preview'].includes(state) ? 'healthy' : (state === 'Running' ? 'running' : 'warning'))}`;
    }
    if (primaryElement && primary) primaryElement.textContent = primary;
    if (detailElement && detail !== '') detailElement.textContent = detail;
  };
  const productionConfirmationHtml = () => `<p>Deploy current Preview to Production?</p><dl class="deployment-details"><div><dt>Preview version</dt><dd><code>${escapeHtml(deployButton?.dataset.previewCommit || 'Not deployed')}</code></dd></div><div><dt>Server</dt><dd>${escapeHtml(deployButton?.dataset.server || 'Not configured')}</dd></div><div><dt>Production path</dt><dd><code>${escapeHtml(deployButton?.dataset.productionPath || 'Not configured')}</code></dd></div></dl><p class="field-help">The latest Production preflight must be clean or explicitly preserved. This will replace the current Production contents with Preview.</p>`;
  const renderProductionPreflight = (preflight) => {
    if (!preflight) return;
    const summary = preflight.summary || {};
    const deletes = Array.isArray(preflight.blocking_deletes) ? preflight.blocking_deletes : [];
    const preservePaths = Array.isArray(preflight.preserve_paths) ? preflight.preserve_paths : [];
    const reviewRequired = Boolean(preflight.review_required);
    const deletionApproved = Boolean(preflight.deletion_approved);
    if (productionPreflight) productionPreflight.classList.toggle('review-required', reviewRequired);
    if (productionPreflightStatus) {
      productionPreflightStatus.textContent = reviewRequired ? 'REVIEW REQUIRED' : 'READY';
      productionPreflightStatus.className = `status-pill ${reviewRequired ? 'warning' : 'healthy'}`;
    }
    if (productionPreflightMessage) {
      productionPreflightMessage.textContent = reviewRequired
        ? 'Production contains unmanaged files that would be deleted by promotion. Preserve selected paths or approve the remaining deletions before deploying.'
        : (deletionApproved
            ? `Deletions approved for this exact preflight result. Approved deletion candidates may be removed with managed privileges before sync. Checked ${preflight.checked_at || 'now'} for Preview commit ${(preflight.preview_commit || '').slice(0, 12) || 'unknown'}.`
            : `Checked ${preflight.checked_at || 'now'} for Preview commit ${(preflight.preview_commit || '').slice(0, 12) || 'unknown'}.`);
    }
    if (productionPreflightCounts) {
      productionPreflightCounts.innerHTML = `<span>Add ${Number(summary.add || 0)}</span><span>Update ${Number(summary.update || 0)}</span><span>Delete ${Number(summary.delete || 0)}</span><span>Preserved ${Number(summary.preserved || 0)}</span>`;
    }
    if (productionPreflightDeletes) {
      productionPreflightDeletes.hidden = deletes.length === 0;
      productionPreflightDeletes.innerHTML = deletes.slice(0, 12).map((path) => `<li><code>${escapeHtml(path)}</code><button type="button" class="secondary" data-preserve-production-path="${escapeHtml(path)}">Preserve path</button></li>`).join('');
    }
    if (approveProductionDeletions) {
      approveProductionDeletions.hidden = !reviewRequired;
      approveProductionDeletions.disabled = !reviewRequired;
    }
    if (productionPreservePaths) {
      productionPreservePaths.textContent = `Preserve rules: ${preservePaths.length ? preservePaths.join(', ') : 'none'}`;
    }
    if (deployButton) {
      deployButton.disabled = reviewRequired;
      deployButton.title = reviewRequired ? 'Production preflight requires review before deployment.' : '';
    }
  };
  const setPreviewManagedStatus = (operation) => {
    if (!operation) return;
    const result = operation.result || {};
    if (previewDeploymentProgress) previewDeploymentProgress.hidden = false;
    if (previewDeploymentStage) previewDeploymentStage.textContent = operation.stage || 'Preparing';
    if (previewDeploymentElapsed) previewDeploymentElapsed.textContent = formatElapsed(operation.elapsed_seconds || 0);
    if (previewDeploymentLog) {
      previewDeploymentLog.textContent = operation.log && operation.log.trim() !== '' ? operation.log : 'Waiting for deployment log...';
      previewDeploymentLog.scrollTop = previewDeploymentLog.scrollHeight;
    }
    const status = operation.status === 'completed' ? 'deployed' : (operation.status === 'failed' ? 'failed' : 'running');
    if (previewDeploymentStatus) {
      previewDeploymentStatus.textContent = status === 'deployed' ? 'Deployed' : (status === 'failed' ? 'Failed' : 'Running');
      previewDeploymentStatus.className = `deployment-status ${status === 'deployed' ? 'success' : status}`;
    }
    setWorkflowStage('Preview', status === 'deployed' ? 'Deployed' : (status === 'failed' ? 'Failed' : 'Running'), result.commit ? result.commit.slice(0, 7) : '', '');
    if (previewDeploymentError) previewDeploymentError.textContent = operation.status === 'failed' ? (operation.message || 'Preview deployment failed.') : '';
    if (result.commit && previewDeploymentCommit) {
      previewDeploymentCommit.textContent = result.commit.slice(0, 7);
      previewDeploymentCommit.title = result.commit;
    }
    if (result.commit && previewDeploymentSourceCommit) {
      previewDeploymentSourceCommit.textContent = result.commit.slice(0, 12);
      previewDeploymentSourceCommit.title = result.commit;
    }
    if (result.branch && previewDeploymentBranch) previewDeploymentBranch.textContent = result.branch;
    if (result.duration_ms && previewDeploymentDuration) previewDeploymentDuration.textContent = `${(Number(result.duration_ms) / 1000).toFixed(1)}s`;
    const lastDeployment = document.getElementById('previewLastDeploymentTime');
    if (lastDeployment && operation.status === 'completed' && operation.finished_at) lastDeployment.textContent = operation.finished_at;
    if (operation.status === 'completed') {
      if (deployButton) {
        deployButton.dataset.previewCommit = result.commit || deployButton.dataset.previewCommit || '';
        deployButton.disabled = true;
        deployButton.title = 'Run Production preflight before deploying.';
      }
      if (productionPreflightStatus) {
        productionPreflightStatus.textContent = 'Not checked';
        productionPreflightStatus.className = 'status-pill pending';
      }
      if (approveProductionDeletions) {
        approveProductionDeletions.hidden = true;
        approveProductionDeletions.disabled = true;
      }
      if (productionPreflightMessage) {
        productionPreflightMessage.textContent = 'Preview changed. Run Production preflight before deploying.';
      }
    }
  };
  const pollPreviewManagedDeployment = (operationId) => {
    if (!operationId) return;
    clearInterval(deploymentPolls.preview);
    const update = async () => {
      const response = await fetch(`?action=preview-deployment-status&id=${encodeURIComponent(operationId)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to read Preview deployment.');
      setPreviewManagedStatus(payload.operation);
      if (['completed', 'failed'].includes(payload.operation.status)) {
        clearInterval(deploymentPolls.preview);
        if (previewDeployButton) {
          previewDeployButton.disabled = false;
          previewDeployButton.title = '';
        }
      }
    };
    update().catch((error) => {
      clearInterval(deploymentPolls.preview);
      if (previewDeploymentError) previewDeploymentError.textContent = error.message;
      if (previewDeployButton) previewDeployButton.disabled = false;
    });
    deploymentPolls.preview = setInterval(() => update().catch((error) => {
      clearInterval(deploymentPolls.preview);
      if (previewDeploymentError) previewDeploymentError.textContent = error.message;
      if (previewDeployButton) previewDeployButton.disabled = false;
    }), 2000);
  };
  previewDeployButton?.addEventListener('click', async () => {
    if (previewDeploymentError) previewDeploymentError.textContent = '';
    previewDeployButton.disabled = true;
    try {
      const payload = await postDeployment('deploy_preview_managed');
      setPreviewManagedStatus(payload.operation);
      pollPreviewManagedDeployment(payload.operation.id);
    } catch (error) {
      if (previewDeploymentError) previewDeploymentError.textContent = error.message;
      previewDeployButton.disabled = false;
      if (previewDeploymentStatus) {
        previewDeploymentStatus.textContent = 'Failed';
        previewDeploymentStatus.className = 'deployment-status failed';
      }
    }
  });
  if (previewDeployButton?.dataset.operationId) {
    pollPreviewManagedDeployment(previewDeployButton.dataset.operationId);
  }
  const setProductionManagedStatus = (operation) => {
    if (!operation) return;
    const result = operation.result || {};
    if (productionDeploymentProgress) productionDeploymentProgress.hidden = false;
    if (productionDeploymentStage) productionDeploymentStage.textContent = operation.stage || 'Preparing';
    if (productionDeploymentElapsed) productionDeploymentElapsed.textContent = formatElapsed(operation.elapsed_seconds || 0);
    if (deploymentLog) {
      deploymentLog.textContent = operation.log && operation.log.trim() !== '' ? operation.log : 'Waiting for deployment log...';
      deploymentLog.scrollTop = deploymentLog.scrollHeight;
    }
    const status = operation.status === 'completed' ? 'deployed' : (operation.status === 'failed' ? 'failed' : 'running');
    if (deploymentStatus) {
      deploymentStatus.textContent = status === 'deployed' ? 'Deployed' : (status === 'failed' ? 'Failed' : 'Running');
      deploymentStatus.className = `deployment-status ${status === 'deployed' ? 'success' : status}`;
    }
    setWorkflowStage('Production', status === 'deployed' ? 'In sync with Preview' : (status === 'failed' ? 'Failed' : 'Running'), result.commit && operation.status === 'completed' ? result.commit.slice(0, 7) : '', '');
    if (deploymentError) deploymentError.textContent = operation.status === 'failed' ? (operation.message || 'Production deployment failed.') : '';
    if (result.commit) {
      const productionCommit = document.getElementById('productionCommit');
      if (productionCommit && operation.status === 'completed') {
        productionCommit.textContent = result.commit.slice(0, 7);
        productionCommit.title = result.commit;
      }
    }
    if (result.duration_ms && productionDeploymentDuration) productionDeploymentDuration.textContent = `${(Number(result.duration_ms) / 1000).toFixed(1)}s`;
    const lastDeployment = document.getElementById('productionLastDeploymentTime');
    if (lastDeployment && operation.status === 'completed' && operation.finished_at) lastDeployment.textContent = operation.finished_at;
    if (productionVersionState && operation.status === 'completed') productionVersionState.textContent = 'In sync with Preview';
  };
  const pollProductionManagedDeployment = (operationId) => {
    if (!operationId) return;
    clearInterval(deploymentPolls.production);
    const update = async () => {
      const response = await fetch(`?action=production-deployment-status&id=${encodeURIComponent(operationId)}`, { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to read Production deployment.');
      setProductionManagedStatus(payload.operation);
      if (['completed', 'failed'].includes(payload.operation.status)) {
        clearInterval(deploymentPolls.production);
        if (deployButton) {
          deployButton.disabled = false;
          deployButton.title = '';
        }
      }
    };
    update().catch((error) => {
      clearInterval(deploymentPolls.production);
      if (deploymentError) deploymentError.textContent = error.message;
      if (deployButton) deployButton.disabled = false;
    });
    deploymentPolls.production = setInterval(() => update().catch((error) => {
      clearInterval(deploymentPolls.production);
      if (deploymentError) deploymentError.textContent = error.message;
      if (deployButton) deployButton.disabled = false;
    }), 2000);
  };
  deployButton?.addEventListener('click', async () => {
    if (deploymentError) deploymentError.textContent = '';
    if (modalError) modalError.textContent = '';
    if (previewBox) previewBox.innerHTML = productionConfirmationHtml();
    deployDialog?.showModal();
  });
  cancelButton?.addEventListener('click', () => { deployDialog.close(); });
  confirmButton?.addEventListener('click', async () => {
    confirmButton.disabled = true;
    if (modalError) modalError.textContent = '';
    if (deployButton) deployButton.disabled = true;
    try {
      const payload = await postDeployment('deploy_production_managed', { confirm: '1' });
      deployDialog.close();
      setProductionManagedStatus(payload.operation);
      pollProductionManagedDeployment(payload.operation.id);
    } catch (error) {
      if (modalError) modalError.textContent = error.message;
      if (deployButton) deployButton.disabled = false;
    } finally {
      confirmButton.disabled = false;
    }
  });
  if (deployButton?.dataset.operationId) {
    pollProductionManagedDeployment(deployButton.dataset.operationId);
  }
  refreshProductionPreflight?.addEventListener('click', async () => {
    refreshProductionPreflight.disabled = true;
    if (deploymentError) deploymentError.textContent = '';
    try {
      const payload = await postDeployment('production_preflight_managed');
      renderProductionPreflight(payload.preflight);
    } catch (error) {
      if (deploymentError) deploymentError.textContent = error.message;
    } finally {
      refreshProductionPreflight.disabled = false;
    }
  });
  productionPreflightDeletes?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-preserve-production-path]');
    if (!button) return;
    button.disabled = true;
    if (deploymentError) deploymentError.textContent = '';
    try {
      const payload = await postDeployment('production_add_preserve_path', { path: button.dataset.preserveProductionPath || '' });
      renderProductionPreflight(payload.preflight);
    } catch (error) {
      if (deploymentError) deploymentError.textContent = error.message;
      button.disabled = false;
    }
  });
  approveProductionDeletions?.addEventListener('click', async () => {
    approveProductionDeletions.disabled = true;
    if (deploymentError) deploymentError.textContent = '';
    try {
      const payload = await postDeployment('production_approve_deletions');
      renderProductionPreflight(payload.preflight);
    } catch (error) {
      if (deploymentError) deploymentError.textContent = error.message;
      approveProductionDeletions.disabled = false;
    }
  });

  if (codexRunPanel) {
    updateCodexStatus().then((status) => {
      updateCodexLog(status === 'queued' || status === 'running').catch(() => {});
      if (status === 'queued' || status === 'running') {
        startCodexPolling();
      }
    }).catch(() => {});
  }

  refreshEnvironmentDashboard().catch(() => {
    if (dashboardUpdated) dashboardUpdated.textContent = 'Status unavailable';
  });
  window.setInterval(() => refreshEnvironmentDashboard().catch(() => {
    if (dashboardUpdated) dashboardUpdated.textContent = 'Refresh failed';
  }), 5000);
})();
</script>
</body>
</html>
