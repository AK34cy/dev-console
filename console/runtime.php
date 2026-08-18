<?php

const DEV_CONSOLE_RUNTIME_CONFIG_FILE = __DIR__ . '/config/runtime.json';
const DEV_CONSOLE_DEFAULT_ATTACHMENT_LIMIT_MB = 25;
const DEV_CONSOLE_DEFAULT_REQUEST_LIMIT_MB = 50;
const DEV_CONSOLE_SYSTEMD_SERVICE_FILE = '/etc/systemd/system/iovon-dev-console.service';

function runtimeDefaultSettings(): array
{
    return [
        'attachment_limit_mb' => DEV_CONSOLE_DEFAULT_ATTACHMENT_LIMIT_MB,
        'request_limit_mb' => DEV_CONSOLE_DEFAULT_REQUEST_LIMIT_MB,
        'updated_at' => null,
    ];
}

function runtimeConfigPath(): string
{
    return DEV_CONSOLE_RUNTIME_CONFIG_FILE;
}

function runtimeLoadSettings(): array
{
    $defaults = runtimeDefaultSettings();
    $path = runtimeConfigPath();
    if (!is_file($path)) {
        runtimeSaveSettings($defaults);
        return $defaults;
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return runtimeNormalizeSettings($decoded);
}

function runtimeNormalizeSettings(array $settings): array
{
    $defaults = runtimeDefaultSettings();
    $attachment = (int)($settings['attachment_limit_mb'] ?? $defaults['attachment_limit_mb']);
    $request = (int)($settings['request_limit_mb'] ?? $defaults['request_limit_mb']);
    if ($attachment < 1 || $attachment > 100) {
        $attachment = $defaults['attachment_limit_mb'];
    }
    if ($request < 1 || $request > 200 || $request < $attachment) {
        $request = max($attachment, $defaults['request_limit_mb']);
    }

    return [
        'attachment_limit_mb' => $attachment,
        'request_limit_mb' => $request,
        'updated_at' => is_scalar($settings['updated_at'] ?? null) && trim((string)$settings['updated_at']) !== '' ? trim((string)$settings['updated_at']) : null,
    ];
}

function runtimeValidateSettingsInput(array $input): array
{
    $attachmentRaw = trim((string)($input['attachment_limit_mb'] ?? ''));
    $requestRaw = trim((string)($input['request_limit_mb'] ?? ''));
    $errors = [];
    if ($attachmentRaw === '' || preg_match('/^\d+$/', $attachmentRaw) !== 1) {
        $errors[] = 'Maximum attachment size must be a whole number.';
    }
    if ($requestRaw === '' || preg_match('/^\d+$/', $requestRaw) !== 1) {
        $errors[] = 'Maximum request size must be a whole number.';
    }
    $attachment = (int)$attachmentRaw;
    $request = (int)$requestRaw;
    if ($attachment < 1 || $attachment > 100) {
        $errors[] = 'Maximum attachment size must be between 1 and 100 MB.';
    }
    if ($request < 1 || $request > 200) {
        $errors[] = 'Maximum request size must be between 1 and 200 MB.';
    }
    if ($request < $attachment) {
        $errors[] = 'Maximum request size must be greater than or equal to maximum attachment size.';
    }

    return [
        'valid' => empty($errors),
        'errors' => array_values(array_unique($errors)),
        'settings' => [
            'attachment_limit_mb' => $attachment,
            'request_limit_mb' => $request,
            'updated_at' => date('c'),
        ],
    ];
}

function runtimeSaveSettings(array $settings): bool
{
    $settings = runtimeNormalizeSettings($settings);
    $path = runtimeConfigPath();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return false;
    }
    $temporaryPath = $directory . '/runtime.json.tmp.' . bin2hex(random_bytes(8));
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($temporaryPath, $json . "\n", LOCK_EX) === false) {
        @unlink($temporaryPath);
        return false;
    }
    @chmod($temporaryPath, 0640);
    if (!@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        return false;
    }
    @chmod($path, 0640);

    return true;
}

function runtimeIniSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($unit) {
        'g' => (int)round($number * 1024 * 1024 * 1024),
        'm' => (int)round($number * 1024 * 1024),
        'k' => (int)round($number * 1024),
        default => (int)round($number),
    };
}

function runtimeBytesToMegabytes(int $bytes): int
{
    return (int)ceil(max(0, $bytes) / 1048576);
}

function runtimeEffectiveLimits(): array
{
    $uploadBytes = runtimeIniSizeToBytes((string)ini_get('upload_max_filesize'));
    $postBytes = runtimeIniSizeToBytes((string)ini_get('post_max_size'));

    return [
        'attachment_limit_mb' => runtimeBytesToMegabytes($uploadBytes),
        'request_limit_mb' => runtimeBytesToMegabytes($postBytes),
        'attachment_ini' => (string)ini_get('upload_max_filesize'),
        'request_ini' => (string)ini_get('post_max_size'),
        'max_file_uploads' => (int)ini_get('max_file_uploads'),
    ];
}

function runtimeRestartRequired(array $configured, array $effective): bool
{
    return (int)$configured['attachment_limit_mb'] !== (int)$effective['attachment_limit_mb']
        || (int)$configured['request_limit_mb'] !== (int)$effective['request_limit_mb'];
}

function runtimeServiceUsesWrapper(): bool
{
    $contents = (string)@file_get_contents(DEV_CONSOLE_SYSTEMD_SERVICE_FILE);
    return $contents !== '' && str_contains($contents, '/bin/run-dev-console');
}

function runtimeApplyInstruction(): string
{
    if (runtimeServiceUsesWrapper()) {
        return 'Restart Dev Console with: sudo systemctl restart iovon-dev-console.service';
    }

    return 'Install the updated Dev Console service unit with: sudo ./bootstrap.sh';
}

function runtimeUploadErrorMessage(int $error, string $filename, array $effectiveLimits): string
{
    $name = trim($filename) === '' ? 'attachment' : $filename;
    $attachmentLimit = (int)($effectiveLimits['attachment_limit_mb'] ?? 0);
    $requestLimit = (int)($effectiveLimits['request_limit_mb'] ?? 0);

    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Attachment "' . $name . '" is too large. Maximum allowed file size is ' . $attachmentLimit . ' MB.',
        UPLOAD_ERR_PARTIAL => 'Attachment "' . $name . '" was only partially uploaded. Try uploading it again.',
        UPLOAD_ERR_NO_FILE => 'No attachment file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Attachment "' . $name . '" could not be uploaded because the server temporary upload directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Attachment "' . $name . '" could not be written to disk.',
        UPLOAD_ERR_EXTENSION => 'Attachment "' . $name . '" was blocked by a PHP extension.',
        default => 'Attachment "' . $name . '" could not be uploaded. Check the file and try again.',
    };
}

function runtimePostLimitExceeded(array $effectiveLimits): bool
{
    if ((string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return false;
    }
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimitBytes = runtimeIniSizeToBytes((string)ini_get('post_max_size'));

    return $postLimitBytes > 0 && $contentLength > $postLimitBytes && empty($_POST) && empty($_FILES);
}

function runtimePostLimitExceededMessage(array $effectiveLimits): string
{
    return 'Request is too large. Maximum total request size is ' . (int)($effectiveLimits['request_limit_mb'] ?? 0) . ' MB.';
}
