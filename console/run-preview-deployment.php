<?php

require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
require __DIR__ . '/projects.php';
require __DIR__ . '/preview-deployment.php';

$operationId = (string)($argv[1] ?? '');
if (!previewDeploymentValidateOperationId($operationId)) {
    fwrite(STDERR, "Invalid Preview deployment operation ID.\n");
    exit(1);
}

try {
    previewDeploymentRunById($operationId);
} catch (Throwable $exception) {
    try {
        previewDeploymentFailOperation($operationId, $exception->getMessage());
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Error: ' . $exception->getMessage());
    } catch (Throwable) {
        // Avoid leaking stack traces from the worker.
    }
    exit(1);
}
