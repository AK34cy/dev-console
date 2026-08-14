<?php

require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/preview-deployment.php';
require __DIR__ . '/production-deployment.php';

$operationId = (string)($argv[1] ?? '');
if (!productionDeploymentValidateOperationId($operationId)) {
    fwrite(STDERR, "Invalid Production deployment operation ID.\n");
    exit(1);
}

try {
    productionDeploymentRunById($operationId);
} catch (Throwable $exception) {
    try {
        productionDeploymentFailOperation($operationId, $exception->getMessage());
        productionDeploymentAppendLog($operationId, '[' . date('c') . '] Error: ' . $exception->getMessage());
    } catch (Throwable) {
        // Avoid leaking stack traces from the worker.
    }
    exit(1);
}
