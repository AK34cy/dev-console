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
    managedServerRunConnectionTestById($operationId);
    exit(0);
} catch (Throwable $exception) {
    try {
        $state = managedServerOperationRead($operationId);
        if (!empty($state)) {
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
        }
        managedServerOperationAppendLog($operationId, '[' . date('c') . '] Error: ' . $exception->getMessage());
    } catch (Throwable) {
        // Detached worker cannot report further.
    }
    exit(1);
}
