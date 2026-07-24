<?php
require __DIR__ . '/deployment.php';

$environment = (string)($argv[1] ?? '');
$id = (string)($argv[2] ?? '');
try { $configuration = deploymentConfiguration($environment); } catch (Throwable) { exit(1); }
$target = $configuration['target'];
$state = readDeploymentState($environment, $id);
if (!$state || ($state['status'] ?? '') !== 'pending') exit(1);
$lockHandle = fopen(deploymentStateDir($environment) . '/deployment.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $state['status'] = 'failed'; $state['finish_time'] = date('c');
    $state['error'] = 'Another deployment is already running.';
    appendDeploymentLog($state, 'Final status: Failed. Another deployment is already running.');
    writeDeploymentState($state); exit(1);
}

try {
    $state['status'] = 'running';
    writeDeploymentState($state);
    appendDeploymentLog($state, 'Validation started.');
    if (($state['environment'] ?? '') !== $environment || ($state['target'] ?? '') !== $target || !deploymentTargetIsExpected($environment)) {
        throw new RuntimeException('Deployment target verification failed; expected ' . $target . '.');
    }
    $errors = deploymentValidation($environment);
    if ($errors) throw new RuntimeException(implode(' ', $errors));
    appendDeploymentLog($state, 'Validation result: passed.');

    $dryRun = deploymentCommand(deploymentRsyncArguments($environment, true));
    if ($dryRun['exit_code'] !== 0) throw new RuntimeException('rsync dry-run failed: ' . trim($dryRun['stderr']));
    $actualSummary = deploymentChangeSummary($dryRun['stdout']);
    appendDeploymentLog($state, sprintf('rsync dry-run summary: %d added, %d updated, %d deleted.', $actualSummary['added'], $actualSummary['updated'], $actualSummary['deleted']));
    if ($actualSummary !== $state['summary']) throw new RuntimeException('Source or target changed after confirmation; request a new preview.');

    appendDeploymentLog($state, 'Deployment started.');
    $sync = deploymentCommand(deploymentRsyncArguments($environment, false));
    if ($sync['exit_code'] !== 0) throw new RuntimeException('rsync failed: ' . trim($sync['stderr']));
    appendDeploymentLog($state, 'Files synchronized.');
    appendDeploymentLog($state, 'Permissions checked and preserved by rsync archive mode.');
    appendDeploymentLog($state, 'Post-deployment verification started.');
    if (!deploymentTargetIsExpected($environment)) throw new RuntimeException('Synchronization targeted the wrong directory; expected ' . $target . '.');
    if (!is_file($target . '/index.php')) throw new RuntimeException(ucfirst($environment) . ' entry page is missing.');
    $sourceIndexHash = hash_file('sha256', DEPLOY_SOURCE . '/index.php');
    $targetIndexHash = hash_file('sha256', $target . '/index.php');
    if ($sourceIndexHash === false || $targetIndexHash === false || !hash_equals($sourceIndexHash, $targetIndexHash)) {
        throw new RuntimeException('Deployed index.php does not match the source version.');
    }
    appendDeploymentLog($state, 'Source and ' . $environment . ' index.php versions match.');
    if (!is_readable($target . '/pages/articles/articles.json')) throw new RuntimeException(ucfirst($environment) . ' articles JSON is not readable.');
    foreach (DEPLOY_REQUIRED_DIRS as $directory) {
        if (!is_dir($target . '/' . $directory)) throw new RuntimeException('Expected ' . $environment . ' directory is missing: ' . $directory);
    }
    $health = deploymentCommand(['curl', '--silent', '--show-error', '--location', '--max-redirs', '5', '--max-time', '15', '--output', '/dev/null', '--write-out', '%{http_code}', '--resolve', $configuration['host'] . ':443:127.0.0.1', $configuration['url'] . '/']);
    if ($health['exit_code'] === 0 && trim($health['stdout']) === '200') {
        appendDeploymentLog($state, 'Local HTTPS health check passed with HTTP 200.');
    } elseif ($health['exit_code'] !== 0) {
        appendDeploymentLog($state, 'Local HTTPS health check was not technically available; filesystem verification passed.');
    } else {
        throw new RuntimeException(ucfirst($environment) . ' HTTPS health check returned HTTP ' . trim($health['stdout']) . '.');
    }
    appendDeploymentLog($state, 'Post-deployment verification passed.');
    $state['status'] = 'success';
    appendDeploymentLog($state, 'Final status: Success.');
} catch (Throwable $exception) {
    $state['status'] = 'failed';
    $state['error'] = $exception->getMessage();
    appendDeploymentLog($state, 'Final status: Failed. ' . $exception->getMessage());
}
$state['finish_time'] = date('c');
writeDeploymentState($state);
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit($state['status'] === 'success' ? 0 : 1);
