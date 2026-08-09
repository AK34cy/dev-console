<?php

function processRedactDisplayArgument(string $argument): string
{
    return preg_replace('~^(https://)[^/@\s]+@~i', '$1[redacted]@', $argument) ?? $argument;
}

function processCommandDisplay(array $arguments): string
{
    return implode(' ', array_map(fn($argument): string => escapeshellarg(processRedactDisplayArgument((string)$argument)), $arguments));
}

function processRedactSensitiveOutput(string $output, array $environment): string
{
    foreach (['GH_TOKEN', 'GITHUB_TOKEN', 'IOVON_GIT_TOKEN'] as $name) {
        $value = $environment[$name] ?? '';
        if (is_scalar($value) && (string)$value !== '') {
            $output = str_replace((string)$value, '[redacted]', $output);
        }
    }

    $output = preg_replace('~Authorization:\s*(?:Bearer|token|Basic)\s+[^\r\n]+~i', 'Authorization: [redacted]', $output) ?? $output;
    $output = preg_replace('~https://[^/@\s:]+:[^/@\s]+@~i', 'https://[redacted]@', $output) ?? $output;

    return $output;
}

function processNormalizeEnvironment(array $environment, bool $inheritEnvironment = true): ?array
{
    if (empty($environment) && $inheritEnvironment) {
        return null;
    }

    $normalized = [];
    $baseEnvironment = $inheritEnvironment ? getenv() : false;
    if (is_array($baseEnvironment)) {
        foreach ($baseEnvironment as $name => $value) {
            $name = (string)$name;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || !is_scalar($value)) {
                continue;
            }
            $normalized[$name] = (string)$value;
        }
    }
    $normalized['PATH'] = $normalized['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    foreach ($environment as $name => $value) {
        $name = (string)$name;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1 || !is_scalar($value)) {
            continue;
        }
        $normalized[$name] = (string)$value;
    }

    return $normalized;
}

function processRunCommand(array $arguments, array $options = []): array
{
    $startedAt = microtime(true);
    $commandDisplay = processCommandDisplay($arguments);
    if (empty($arguments)) {
        return processResult($commandDisplay, '', 'No command specified.', 127, false, $startedAt);
    }

    $cwd = is_string($options['cwd'] ?? null) && is_dir((string)$options['cwd']) ? (string)$options['cwd'] : null;
    $timeoutSeconds = max(1, (int)($options['timeout'] ?? 10));
    $environment = processNormalizeEnvironment(is_array($options['env'] ?? null) ? $options['env'] : [], (bool)($options['inherit_env'] ?? true));
    $pipes = [];
    $process = @proc_open(array_values(array_map('strval', $arguments)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        return processResult($commandDisplay, '', 'Unable to start command.', 127, false, $startedAt);
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    $observedExitCode = null;

    while (true) {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        $status = proc_get_status($process);
        if (!$status['running']) {
            if (isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $observedExitCode = (int)$status['exitcode'];
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            usleep(200000);
            $status = proc_get_status($process);
            if (!empty($status['running'])) {
                proc_terminate($process, 9);
            } elseif (isset($status['exitcode']) && (int)$status['exitcode'] >= 0) {
                $observedExitCode = (int)$status['exitcode'];
            }
            break;
        }
        usleep(50000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closedExitCode = proc_close($process);
    $exitCode = $closedExitCode >= 0 ? $closedExitCode : $observedExitCode;
    if ($timedOut) {
        $exitCode = 124;
        $stderr = trim($stderr . "\nCommand timed out.");
    } elseif ($exitCode === null) {
        $exitCode = 127;
        $stderr = trim($stderr . "\nUnable to determine command exit status.");
    }

    return processResult($commandDisplay, processRedactSensitiveOutput($stdout, $environment ?? []), processRedactSensitiveOutput($stderr, $environment ?? []), $exitCode, $timedOut, $startedAt);
}

function processResult(string $commandDisplay, string $stdout, string $stderr, int $exitCode, bool $timedOut, float $startedAt): array
{
    $rawStdout = $stdout;
    $rawStderr = $stderr;
    $stdout = trim($stdout);
    $stderr = trim($stderr);
    $output = trim($stdout . ($stderr === '' ? '' : "\n" . $stderr));
    $success = !$timedOut && $exitCode === 0;

    return [
        'command_display' => $commandDisplay,
        'command' => $commandDisplay,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'stdout_raw' => $rawStdout,
        'stderr_raw' => $rawStderr,
        'exit_code' => $exitCode,
        'timed_out' => $timedOut,
        'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'success' => $success,
        'output' => $output,
    ];
}
