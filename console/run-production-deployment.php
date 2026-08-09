<?php

require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';
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
        $state = productionDeploymentReadOperation($operationId);
        if (!empty($state)) {
            $state['status'] = 'failed';
            $state['stage'] = 'Failed';
            $state['message'] = 'Production deployment failed.';
            $state['finished_at'] = date('c');
            $state['result'] = [
                'success' => false,
                'message' => 'Production deployment failed.',
            ];
            productionDeploymentWriteOperation($state);
        }
        productionDeploymentAppendLog($operationId, '[' . date('c') . '] Error: Production deployment failed.');
    } catch (Throwable) {
        // Avoid leaking stack traces from the worker.
    }
    exit(1);
}
