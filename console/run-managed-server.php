<?php
require __DIR__ . '/config.php';
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';
require __DIR__ . '/servers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Managed server worker must run from CLI.\n");
    exit(1);
}

$operationId = (string)($argv[1] ?? '');
if (!managedServerOperationValidateId($operationId)) {
    fwrite(STDERR, "Invalid operation ID.\n");
    exit(1);
}

try {
    managedServerRunOperationById($operationId);
    exit(0);
} catch (Throwable $exception) {
    managedServerOperationAppendLog($operationId, '[' . date('c') . '] Error: ' . $exception->getMessage());
    try {
        try {
            $state = managedServerOperationRead($operationId);
        } catch (Throwable $stateException) {
            $state = [
                'id' => $operationId,
                'operation_action' => 'unknown',
                'server_id' => '',
                'server_name' => '',
                'status' => 'running',
                'stage' => 'Starting',
                'started_at' => date('c'),
                'updated_at' => date('c'),
                'finished_at' => '',
                'message' => $stateException->getMessage(),
                'result' => null,
            ];
        }
        $state['status'] = 'failed';
        $state['stage'] = 'Failed';
        $state['finished_at'] = date('c');
        $state['message'] = $exception->getMessage();
        $state['result'] = [
            'success' => false,
            'message' => $exception->getMessage(),
            'output' => managedServerOperationLog($operationId),
        ];
        managedServerOperationWrite($state);
    } catch (Throwable) {
        // Detached worker cannot report further.
    }
    exit(1);
}
