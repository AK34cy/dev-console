<?php
require __DIR__ . '/process.php';
require __DIR__ . '/server-tools.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Server tool worker must run from CLI.\n");
    exit(1);
}

$operationId = (string)($argv[1] ?? '');
if (!serverToolsValidateOperationId($operationId)) {
    fwrite(STDERR, "Invalid operation ID.\n");
    exit(1);
}

try {
    serverToolsExecuteOperation($operationId);
    exit(0);
} catch (Throwable $exception) {
    try {
        $state = serverToolsReadOperation($operationId);
        if (!empty($state)) {
            $state['status'] = 'failed';
            $state['stage'] = 'Failed';
            $state['finished_at'] = date('c');
            $state['message'] = $exception->getMessage();
            $state['result'] = [
                'success' => false,
                'message' => $exception->getMessage(),
                'action' => 'server_tool_action',
                'output' => serverToolsOperationLog($operationId),
                'summary_steps' => [],
            ];
            serverToolsWriteOperation($state);
        }
        serverToolsAppendOperationLog($operationId, '[' . date('c') . '] Error: ' . $exception->getMessage());
    } catch (Throwable) {
        // Nothing else to do from the detached worker.
    }
    exit(1);
}
