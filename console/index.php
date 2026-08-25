<?php
require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
require __DIR__ . '/preview-deployment.php';
require __DIR__ . '/production-deployment.php';
require __DIR__ . '/deployment.php';
require __DIR__ . '/apache.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/git.php';
require __DIR__ . '/tasks.php';
require __DIR__ . '/task-lifecycle.php';
require __DIR__ . '/documentation.php';
require __DIR__ . '/runtime.php';

const DEV_CONSOLE_VERSION = '0.1';

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
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
$serverDiagnostics = is_array($serverDiagnosticsResult['diagnostics'] ?? null) ? $serverDiagnosticsResult['diagnostics'] : serverToolsDiagnostics();
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
$managedProductionDeploymentReadiness = productionDeploymentReadiness($activeProject, $managedServers);
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
    .project-selector { align-items: end; display: grid; gap: 12px; grid-template-columns: minmax(220px, 320px) minmax(0, 1fr); }
    .project-selector form { margin: 0; }
    .project-selector label { margin-top: 0; }
    .project-identity { display: flex; flex-wrap: wrap; gap: 8px 14px; }
    .project-identity span { color: var(--muted); font-size: 12px; }
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
    textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(0, 83, 133, 0.12); outline: none; }
    .metadata-preview { background: #f7fcfe; border: 1px solid var(--line); color: var(--muted); margin: 8px 0 14px; min-height: 78px; padding: 10px 12px; resize: none; }
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
    .task-list-scroll { max-height: 620px; overflow-y: auto; padding-right: 6px; }
    .task-group { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px; }
    .task-group:first-child { border-top: 0; margin-top: 0; padding-top: 0; }
    .task-group h3 { color: var(--blue); font-size: 13px; letter-spacing: 0; margin: 0 0 6px; }
    .task-list { list-style: none; margin: 0; padding: 0; }
    .task-list > li { border-top: 1px solid var(--line); padding: 8px 0; }
    .task-list li:first-child { border-top: 0; }
    .task-row-header { align-items: center; display: flex; gap: 8px; justify-content: space-between; }
    .task-summary-label { color: var(--blue); font-weight: 700; }
    .task-title { color: var(--ink); display: block; font-size: 12px; margin-top: 3px; }
    .task-metadata { color: var(--muted); display: flex; flex-wrap: wrap; font-size: 12px; gap: 5px 12px; margin-top: 6px; }
    .task-list .button-link { font-size: 12px; margin-top: 7px; padding: 6px 9px; }
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
    .workflow-summary { margin-bottom: 18px; }
    .workflow-stage-grid { align-items: stretch; display: grid; gap: 10px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .workflow-stage { background: #fff; border: 1px solid var(--line); border-radius: 7px; min-width: 0; padding: 12px; position: relative; }
    .workflow-stage:not(:last-child)::after { color: var(--muted); content: "→"; font-weight: 700; position: absolute; right: -10px; top: 50%; transform: translateY(-50%); }
    .workflow-stage h3 { color: var(--muted); font-size: 11px; letter-spacing: 0; margin: 0 0 8px; text-transform: uppercase; }
    .workflow-stage strong { color: var(--ink); display: block; font-size: 15px; overflow-wrap: anywhere; }
    .workflow-stage .meta { display: block; margin-top: 6px; overflow-wrap: anywhere; }
    .workflow-stage .status-pill { margin-bottom: 8px; }
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
    .page-context { color: var(--muted); min-width: 220px; text-align: right; }
    .page-context strong { color: var(--ink); display: block; font-size: 16px; }
    .page-context span { font-size: 13px; }
    .tab-nav { align-items: flex-end; border-bottom: 1px solid #9fbfcb; display: flex; flex-wrap: wrap; gap: 2px; margin-top: 18px; max-width: 100%; padding: 0 2px; }
    .tab-button { background: #edf4f7; border: 1px solid #9fbfcb; border-radius: 5px 5px 0 0; color: var(--blue); flex: 0 0 auto; font-size: 14px; margin: 0 0 -1px; padding: 8px 14px 10px; white-space: nowrap; }
    .tab-button:hover { background: #f5fbfd; }
    .tab-button.active { background: #fff; border-color: #5f8ea3; color: var(--ink); font-weight: 800; }
    .deployment-panel.production { border: 2px solid #8a1f1f; }
    .deployment-details { display: grid; gap: 10px 24px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 16px 0; }
    .deployment-details dt { color: var(--muted); font-size: 12px; font-weight: 700; text-transform: uppercase; }
    .deployment-details dd { margin: 3px 0 0; overflow-wrap: anywhere; }
    .deployment-status { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 5px 10px; text-transform: uppercase; }
    .deployment-status.pending { background: #edf0f2; color: #56636a; }
    .deployment-status.running { background: #fff2b8; color: #705900; }
    .deployment-status.success { background: #e4f6ea; color: #147544; }
    .deployment-status.failed { background: #fde8e8; color: #8a1f1f; }
    .deploy-production { background: #a51d1d; border-color: #a51d1d; font-size: 16px; }
    .deploy-production:hover { background: #801515; }
    dialog { border: 0; border-radius: 10px; box-shadow: 0 20px 70px #0006; max-width: 720px; padding: 0; width: calc(100% - 32px); }
    dialog::backdrop { background: #101820aa; }
    .modal-content { padding: 24px; }
    .modal-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: flex-end; margin-top: 20px; }
    .change-list { background: #f3f6f7; max-height: 220px; overflow: auto; padding: 12px 12px 12px 30px; }
    .deployment-error { color: #8a1f1f; font-weight: 700; }
    .environment-block { padding: 14px; }
    .environment-block a { color: var(--blue); }
    .dashboard-header { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; }
    .dashboard-header button { margin-top: 0; }
    .dashboard-header h2 { font-size: 18px; }
    .dashboard-header .meta { font-size: 11px; white-space: nowrap; }
    .dashboard-grid { display: grid; gap: 8px; margin-top: 10px; }
    .summary-grid { display: grid; gap: 8px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-card { background: #f8fbfc; border: 1px solid var(--line); border-radius: 7px; min-width: 0; padding: 9px 10px; }
    .dashboard-card h3 { color: var(--blue); font-size: 13px; margin: 0 0 6px; }
    .dashboard-list { display: grid; gap: 4px; margin: 0; }
    .dashboard-list div { display: grid; gap: 5px; grid-template-columns: minmax(68px, .75fr) minmax(0, 1.25fr); }
    .dashboard-list dt { color: var(--muted); font-size: 11px; font-weight: 700; }
    .dashboard-list dd { font-size: 12px; margin: 0; overflow-wrap: anywhere; }
    .dashboard-list code { padding: 1px 3px; }
    .status-pill { align-items: center; background: #edf0f2; border-radius: 999px; color: #56636a; display: inline-flex; font-size: 11px; font-weight: 700; justify-content: center; line-height: 1; min-height: 22px; padding: 4px 8px; text-transform: uppercase; vertical-align: middle; }
    .status-pill.pending { background: #edf0f2; color: #56636a; }
    .status-pill.running { background: #fff2b8; color: #705900; }
    .status-pill.healthy { background: #e4f6ea; color: var(--green); }
    .status-pill.warning { background: #fff2b8; color: #705900; }
    .status-pill.error { background: #fde8e8; color: #8a1f1f; }
    .health-row { display: flex; flex-wrap: wrap; gap: 6px 12px; }
    .health-item { align-items: center; display: inline-flex; font-size: 11px; font-weight: 700; gap: 5px; }
    .health-dot { background: #8a969c; border-radius: 50%; height: 8px; width: 8px; }
    .health-item.healthy .health-dot { background: #1b9a59; }
    .health-item.warning .health-dot { background: #d59b00; }
    .health-item.error .health-dot { background: #c83232; }
    .resource-grid { display: grid; gap: 9px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .resource-head { display: flex; font-size: 11px; font-weight: 700; justify-content: space-between; margin-bottom: 4px; }
    .progress-track { background: #dce8ed; border-radius: 999px; height: 7px; overflow: hidden; }
    .progress-bar { background: #238654; border-radius: inherit; height: 100%; }
    .progress-bar.warning { background: #d59b00; }
    .progress-bar.error { background: #c83232; }
    .resource-value { color: var(--muted); font-size: 10px; margin: 4px 0 0; }
    .compact-details summary { color: var(--blue); cursor: pointer; font-size: 13px; font-weight: 700; }
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
    .process-table { border-collapse: collapse; font-size: 12px; width: 100%; }
    .process-table th, .process-table td { border-top: 1px solid var(--line); padding: 6px; text-align: left; }
    .process-table th { border-top: 0; color: var(--muted); }
    .process-table td:last-child { overflow-wrap: anywhere; }
    @media (max-width: 900px) {
      .dashboard-columns { display: block; }
      .workflow-stage-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .workflow-stage:nth-child(2)::after { content: ""; }
	      .settings-layout, .server-layout, .projects-layout, .documentation-layout { grid-template-columns: 1fr; }
	      .documentation-nav { position: static; }
	      .project-item[data-project-card] > .project-summary { grid-template-columns: minmax(0, 1fr); }
	      .project-detail-grid { grid-template-columns: 1fr; }
	      .server-compact-summary { grid-template-columns: minmax(0, 1fr); }
	      .server-detail-grid { grid-template-columns: 1fr; }
      .apache-summary, .runtime-limit-row, .github-config-grid { grid-template-columns: 1fr; }
      .page-header { display: block; }
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
      <h1>IOVON Dev Console</h1>
      <p class="meta">Internal task creator. Run only on <code>127.0.0.1:8090</code>.</p>
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
        <span><strong><?= h((string)($activeProject['name'] ?? '')) ?></strong></span>
        <span>Server: <?= h(devConsoleManagedServerLabel($activeManagedServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></span>
        <span>Status: <?= h(devConsoleManagedServerStatusLabel($activeManagedServer)) ?></span>
        <span>Production: <?= h(configuredDisplayValue($activeProject['production']['domain'] ?? '')) ?></span>
        <span>Preview: <?= h(configuredDisplayValue($activeProject['preview']['domain'] ?? '')) ?></span>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($activeProject !== null): ?>
  <section class="panel workflow-summary" aria-label="Project workflow summary">
    <div class="dashboard-header">
      <h2>Workflow</h2>
      <span class="meta">Task → Codex → Preview → Production</span>
    </div>
    <div class="workflow-stage-grid">
      <?php foreach ($workflowStages as $stage): ?>
        <?php $stageState = (string)($stage['state'] ?? ''); ?>
        <section class="workflow-stage">
          <h3><?= h((string)($stage['name'] ?? '')) ?></h3>
          <span class="status-pill <?= h(workflowStateClass($stageState)) ?>"><?= h($stageState) ?></span>
          <strong><?= h((string)($stage['primary'] ?? '')) ?></strong>
          <?php if ((string)($stage['detail'] ?? '') !== ''): ?>
            <span class="meta"><?= h((string)$stage['detail']) ?></span>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="dashboard-columns">
  <div class="dashboard-column dashboard-column-left">
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
          <details class="compact-details">
            <summary>Show workflow details</summary>
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
            <pre class="codex-console" id="codexConsole">Loading activity...</pre>
            <div class="prompt-actions">
              <button type="button" class="secondary" id="refreshCodexLog">Refresh</button>
              <button type="button" class="secondary" id="copyCodexLog">Copy to Clipboard</button>
              <button type="button" class="secondary" id="downloadCodexLog">Download Log</button>
              <span class="hint" id="copyCodexMessage" aria-live="polite"></span>
            </div>
          </div>
        <?php else: ?>
          <div class="codex-run-panel">
            <p><strong>Run status:</strong> <span class="codex-status">Not started</span></p>
            <pre class="codex-console">Activity will appear here after a task is created and run.</pre>
          </div>
        <?php endif; ?>
      </section>

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
        <dl class="deployment-details">
          <div><dt>Managed Server</dt><dd><?= h(devConsoleManagedServerLabel($previewServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></dd></div>
          <div><dt>Remote path</dt><dd><code><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['remote_path'] ?? '')) ?></code></dd></div>
          <div><dt>Preview URL</dt><dd><?php if ((string)($managedPreviewDeploymentOverview['url'] ?? '') !== ''): ?><a href="<?= h((string)$managedPreviewDeploymentOverview['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$managedPreviewDeploymentOverview['url']) ?></a><?php else: ?>Not configured<?php endif; ?></dd></div>
          <div><dt>Repository</dt><dd><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['repository'] ?? '')) ?></dd></div>
          <div><dt>Branch</dt><dd id="previewDeploymentBranch"><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['branch'] ?? '')) ?></dd></div>
          <div><dt>GitHub commit</dt><dd id="previewDeploymentSourceCommit"><?= h($previewReady ? 'Resolved at deployment time' : 'Unavailable') ?></dd></div>
          <div><dt>Status</dt><dd><span id="previewDeploymentStatus" class="deployment-status <?= h($previewStatusClass) ?>"><?= h($previewStatusLabel) ?></span></dd></div>
          <div><dt>Preview version</dt><dd><code id="previewDeploymentCommit" title="<?= h($previewCommit) ?>"><?= h($previewCommit === '' ? 'Not deployed' : shortSha($previewCommit)) ?></code></dd></div>
          <div><dt>Last deployment</dt><dd id="previewLastDeploymentTime"><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['deployed_at'] ?? '')) ?></dd></div>
          <div><dt>Duration</dt><dd id="previewDeploymentDuration"><?= h($previewDuration === null ? 'Not configured' : ((string)round(((int)$previewDuration) / 1000, 1) . 's')) ?></dd></div>
          <?php if ((string)($managedPreviewDeploymentOverview['last_attempt_status'] ?? '') === 'failed'): ?>
            <div><dt>Latest attempt</dt><dd><?= h(configuredDisplayValue($managedPreviewDeploymentOverview['last_attempt_at'] ?? '')) ?>: <?= h(configuredDisplayValue($managedPreviewDeploymentOverview['last_attempt_message'] ?? 'Failed')) ?></dd></div>
          <?php endif; ?>
        </dl>
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
        <pre class="codex-console" id="previewDeploymentLog">No deployment log yet.</pre>
      </section>
  </div>

  <div class="dashboard-column dashboard-column-right">
    <section class="panel environment-block" id="environment">
      <div class="dashboard-header">
        <h2>Environment</h2>
        <span class="meta" id="dashboardUpdated">Loading...</span>
      </div>
      <div class="dashboard-grid" id="environmentDashboard" aria-live="polite"></div>
    </section>

    <section class="panel" id="tasks">
      <h2>Tasks</h2>
      <?php if ($legacyTasksDetected): ?>
        <p class="field-help">Legacy tasks detected. They belong to the previous global task storage and are associated with the default Project.</p>
      <?php endif; ?>
      <div class="task-list-scroll">
        <?php foreach ($taskGroups as $groupName => $groupTasks): ?>
          <section class="task-group">
            <h3><?= h($groupName) ?></h3>
            <?php if (empty($groupTasks)): ?>
              <p class="meta">No <?= h(strtolower($groupName)) ?> tasks.</p>
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
                    <a class="button-link secondary" href="?tab=dashboard&task=<?= h(rawurlencode($task['filename'])) ?>&task_source=<?= h(rawurlencode((string)$task['source'])) ?>">Use in Workflow</a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
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
      ?>
      <dl class="deployment-details">
        <div><dt>Managed Server</dt><dd id="productionDeploymentServer"><?= h(devConsoleManagedServerLabel($productionServer, (string)($activeProject['managed_server_id'] ?? ''))) ?></dd></div>
        <div><dt>Production path</dt><dd><code id="productionDeploymentPath"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['production_path'] ?? '')) ?></code></dd></div>
        <div><dt>Production URL</dt><dd id="productionDeploymentUrl"><?php if ((string)($managedProductionDeploymentOverview['production_url'] ?? '') !== ''): ?><a href="<?= h((string)$managedProductionDeploymentOverview['production_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$managedProductionDeploymentOverview['production_url']) ?></a><?php else: ?>Not configured<?php endif; ?></dd></div>
        <div><dt>Preview version</dt><dd><code id="productionPreviewCommit" title="<?= h($productionPreviewCommit) ?>"><?= h($productionPreviewCommit === '' ? 'Not deployed' : shortSha($productionPreviewCommit)) ?></code></dd></div>
        <div><dt>Preview deployed</dt><dd id="productionPreviewDeployedAt"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['preview_deployed_at'] ?? '')) ?></dd></div>
        <div><dt>Preview path</dt><dd><code id="productionPreviewPath"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['preview_path'] ?? '')) ?></code></dd></div>
        <div><dt>Status</dt><dd><span id="productionDeploymentStatus" class="deployment-status <?= h($productionStatusClass) ?>"><?= h($productionStatusLabel) ?></span></dd></div>
        <div><dt>Production version</dt><dd><code id="productionCommit" title="<?= h($productionCommit) ?>"><?= h($productionCommit === '' ? 'Not deployed' : shortSha($productionCommit)) ?></code></dd></div>
        <div><dt>Last deployed</dt><dd id="productionLastDeploymentTime"><?= h(configuredDisplayValue($managedProductionDeploymentOverview['deployed_at'] ?? '')) ?></dd></div>
        <div><dt>Duration</dt><dd id="productionDeploymentDuration"><?= h($productionDuration === null ? 'Not configured' : ((string)round(((int)$productionDuration) / 1000, 1) . 's')) ?></dd></div>
        <div><dt>Version state</dt><dd id="productionVersionState"><?= h((string)($managedProductionDeploymentOverview['version_state'] ?? 'Preview has not been deployed')) ?></dd></div>
        <?php if ((string)($managedProductionDeploymentOverview['last_attempt_status'] ?? '') === 'failed'): ?>
          <div><dt>Latest attempt</dt><dd><?= h(configuredDisplayValue($managedProductionDeploymentOverview['last_attempt_at'] ?? '')) ?>: <?= h(configuredDisplayValue($managedProductionDeploymentOverview['last_attempt_message'] ?? 'Failed')) ?></dd></div>
        <?php endif; ?>
      </dl>
      <?php if (!$productionReady): ?>
        <p class="field-help"><?= h(implode(' ', array_map('strval', $productionReasons))) ?></p>
      <?php endif; ?>
      <button type="button" class="deploy-production" id="deployProduction" data-operation-id="<?= h($productionOperationId) ?>" data-preview-commit="<?= h($productionPreviewCommit) ?>" data-server="<?= h(devConsoleManagedServerLabel($productionServer, (string)($activeProject['managed_server_id'] ?? ''))) ?>" data-production-path="<?= h((string)($managedProductionDeploymentOverview['production_path'] ?? '')) ?>"<?= $productionReady ? '' : ' disabled title="' . h(implode(' ', array_map('strval', $productionReasons))) . '"' ?>>Deploy to Production</button>
      <dl class="tool-operation-grid" id="productionDeploymentProgress"<?= $productionOperationId !== '' ? '' : ' hidden' ?>>
        <div><dt>Stage</dt><dd id="productionDeploymentStage">Preparing</dd></div>
        <div><dt>Elapsed</dt><dd id="productionDeploymentElapsed">0s</dd></div>
      </dl>
      <p class="deployment-error" id="productionDeploymentError" aria-live="assertive"></p>
      <div class="operation-actions">
        <button type="button" class="secondary" data-copy-log="productionDeploymentLog">Copy Log</button>
        <button type="button" class="secondary" data-download-log="productionDeploymentLog" data-download-name="production-deployment.log">Download Log</button>
        <span class="meta" data-log-message="productionDeploymentLog"></span>
      </div>
      <pre class="codex-console" id="productionDeploymentLog">No deployment log yet.</pre>
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
                $setupUnavailableReason = '';
                if (!$usesGeneratedPaths) {
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
                <?php if (!$usesGeneratedPaths): ?>
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
    return 'error';
  };
  const statusLabel = (status) => String(status).replaceAll('_', ' ');
  const dashboardCard = (title, rows, extraClass = '') => `<section class="dashboard-card ${extraClass}"><h3>${dashboardEscape(title)}</h3><dl class="dashboard-list">${rows.map(([label, value]) => `<div><dt>${dashboardEscape(label)}</dt><dd>${value}</dd></div>`).join('')}</dl></section>`;
  const dashboardLink = (url) => `<a href="${dashboardEscape(url)}" target="_blank" rel="noopener noreferrer">${dashboardEscape(url)}</a>`;
  const dashboardStatus = (status) => `<span class="status-pill ${statusClass(status)}">${dashboardEscape(statusLabel(status))}</span>`;
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
      const response = await fetch('?action=environment-status', { cache: 'no-store' });
      const payload = await response.json();
      if (!payload.ok) throw new Error(payload.error || 'Unable to load environment status.');
      const data = payload.dashboard;
      const development = data.environment.development;
      const preview = data.environment.preview;
      const production = data.environment.production;
      const memory = data.server.memory;
      const disk = data.server.disk;
      const softwareNames = ['PHP', 'Composer', 'Node.js', 'npm', 'Git', 'Codex CLI'];
      const readiness = {
        PHP: 'Required',
        Composer: 'Optional / project-dependent',
        'Node.js': 'Optional / project-dependent',
        npm: 'Optional / project-dependent',
        Git: 'Required',
        'Codex CLI': 'Required for Run Codex',
      };
      const softwareRows = softwareNames.map((name) => `<tr><th>${dashboardEscape(name)}</th><td>${dashboardEscape(data.software[name])}<br><span class="meta">${dashboardEscape(readiness[name] || '')}</span></td></tr>`).join('');
      const processes = data.processes.length ? data.processes.map((process) => `<tr><td>${process.pid}</td><td>${dashboardEscape(process.user)}</td><td>${process.cpu.toFixed(1)}</td><td>${process.memory.toFixed(1)}</td><td>${dashboardEscape(process.command)}</td></tr>`).join('') : '<tr><td colspan="5">No process data available.</td></tr>';
      const previewHealth = statusClass(preview.status);
      const productionHealth = statusClass(production.status);
      const consoleHealth = statusClass(data.environment.console.status);
      const gitHealth = data.software.Git === 'Not installed' ? 'error' : 'healthy';
      const webHealth = previewHealth === 'error' && productionHealth === 'error' ? 'error' : (previewHealth === 'warning' && productionHealth === 'warning' ? 'warning' : 'healthy');
      environmentDashboard.innerHTML =
        `<section class="dashboard-card"><div class="health-row">${healthItem('Preview', previewHealth)}${healthItem('Production', productionHealth)}${healthItem('Dev Console', consoleHealth)}${healthItem('Git', gitHealth)}${healthItem('Project Apache', webHealth)}${healthItem('Tailscale', consoleHealth)}</div></section>` +
        `<div class="summary-grid">` +
        dashboardCard('Development', [['Branch', dashboardEscape(development.branch)], ['Commit', `<code>${dashboardEscape(development.commit)}</code>`], ['Current task', dashboardEscape(activeTask)], ['Server', dashboardEscape(activeManagedServerLabel)], ['Server status', dashboardEscape(activeManagedServerStatus)]]) +
        dashboardCard('Preview', [['Status', dashboardStatus(preview.status)], ['URL', dashboardLink(preview.url)]]) +
        dashboardCard('Production', [['Status', dashboardStatus(production.status)], ['URL', dashboardLink(production.url)]]) +
        dashboardCard('Dev Console', [['Status', dashboardStatus(data.environment.console.status)], ['URL', dashboardLink(data.environment.console.url)]]) +
        `</div>` +
        `<section class="dashboard-card"><h3>Resources</h3><div class="resource-grid">${resourceBar('CPU', data.server.load_percentage, `Load ${data.server.load.join(' / ') || 'not detected'}`)}${resourceBar('Memory', memory.percentage, `${formatBytes(memory.used)} / ${formatBytes(memory.total)}`)}${resourceBar('Disk', disk.percentage, `${formatBytes(disk.used)} / ${formatBytes(disk.total)}`)}</div></section>` +
        `<section class="dashboard-card"><h3>Software Versions</h3><table class="compact-table"><tbody>${softwareRows}</tbody></table></section>` +
        dashboardCard('Repository', [['Size', formatBytes(data.statistics.development.bytes)], ['File count', data.statistics.development.files.toLocaleString()]]) +
        `<details class="dashboard-card compact-details" id="topProcesses"${topProcessesWasOpen ? ' open' : ''}><summary>Top Processes</summary><table class="process-table"><thead><tr><th>PID</th><th>User</th><th>CPU %</th><th>Memory %</th><th>Command</th></tr></thead><tbody>${processes}</tbody></table></details>`;
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
  const productionConfirmationHtml = () => `<p>Deploy current Preview to Production?</p><dl class="deployment-details"><div><dt>Preview version</dt><dd><code>${escapeHtml(deployButton?.dataset.previewCommit || 'Not deployed')}</code></dd></div><div><dt>Server</dt><dd>${escapeHtml(deployButton?.dataset.server || 'Not configured')}</dd></div><div><dt>Production path</dt><dd><code>${escapeHtml(deployButton?.dataset.productionPath || 'Not configured')}</code></dd></div></dl><p class="field-help">This will replace the current Production contents with Preview.</p>`;
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
