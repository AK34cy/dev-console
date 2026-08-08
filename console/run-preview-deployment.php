<?php

require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
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
        $state = previewDeploymentReadOperation($operationId);
        if (!empty($state)) {
            $state['status'] = 'failed';
            $state['stage'] = 'Failed';
            $state['message'] = 'Preview deployment failed.';
            $state['finished_at'] = date('c');
            $state['result'] = [
                'success' => false,
                'message' => 'Preview deployment failed.',
            ];
            previewDeploymentWriteOperation($state);
        }
        previewDeploymentAppendLog($operationId, '[' . date('c') . '] Error: Preview deployment failed.');
    } catch (Throwable) {
        // Avoid leaking stack traces from the worker.
    }
    exit(1);
}
