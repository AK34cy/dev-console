<?php

function documentationUserSections(): array
{
    return [
        'getting-started' => ['title' => 'Getting Started', 'path' => dirname(__DIR__) . '/docs/user/getting-started.md'],
        'workflow' => ['title' => 'Workflow', 'path' => dirname(__DIR__) . '/docs/user/workflow.md'],
        'servers' => ['title' => 'Managed Servers', 'path' => dirname(__DIR__) . '/docs/user/servers.md'],
        'projects' => ['title' => 'Projects', 'path' => dirname(__DIR__) . '/docs/user/projects.md'],
        'git' => ['title' => 'Git & GitHub', 'path' => dirname(__DIR__) . '/docs/user/git.md'],
        'tasks-codex' => ['title' => 'Tasks & Codex', 'path' => dirname(__DIR__) . '/docs/user/tasks-codex.md'],
        'preview' => ['title' => 'Preview', 'path' => dirname(__DIR__) . '/docs/user/preview.md'],
        'production' => ['title' => 'Production', 'path' => dirname(__DIR__) . '/docs/user/production.md'],
        'troubleshooting' => ['title' => 'Troubleshooting', 'path' => dirname(__DIR__) . '/docs/user/troubleshooting.md'],
        'security' => ['title' => 'Security', 'path' => dirname(__DIR__) . '/docs/user/security.md'],
        'technical-reference' => ['title' => 'Technical Reference', 'path' => dirname(__DIR__) . '/docs/user/technical-reference.md'],
    ];
}

function documentationTechnicalSections(): array
{
    return [
        'technical-architecture' => ['title' => 'Architecture', 'path' => dirname(__DIR__) . '/docs/architecture.md'],
        'technical-workflow' => ['title' => 'Workflow', 'path' => dirname(__DIR__) . '/docs/workflow.md'],
        'technical-project-actions' => ['title' => 'Project Actions', 'path' => dirname(__DIR__) . '/docs/project-actions.md'],
        'technical-server-actions' => ['title' => 'Server Actions', 'path' => dirname(__DIR__) . '/docs/server-actions.md'],
        'technical-data-model' => ['title' => 'Data Model', 'path' => dirname(__DIR__) . '/docs/data-model.md'],
        'technical-security' => ['title' => 'Security', 'path' => dirname(__DIR__) . '/docs/security.md'],
        'technical-future-work' => ['title' => 'Future Work', 'path' => dirname(__DIR__) . '/docs/future-work.md'],
        'technical-dev-console' => ['title' => 'DEV_CONSOLE', 'path' => dirname(__DIR__) . '/docs/DEV_CONSOLE.md'],
    ];
}

function documentationAllSections(): array
{
    return documentationUserSections() + documentationTechnicalSections();
}

function documentationCurrentSlug(array $query): string
{
    $slug = isset($query['doc']) && is_scalar($query['doc']) ? (string)$query['doc'] : 'getting-started';
    return array_key_exists($slug, documentationAllSections()) ? $slug : 'getting-started';
}

function documentationMarkdownForSlug(string $slug): string
{
    $sections = documentationAllSections();
    $path = (string)($sections[$slug]['path'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '# Documentation unavailable' . "\n\n" . 'The requested documentation file could not be read.';
    }

    return (string)file_get_contents($path);
}

function documentationTitleForSlug(string $slug): string
{
    $sections = documentationAllSections();
    return (string)($sections[$slug]['title'] ?? 'Documentation');
}

function documentationRenderInline(string $text): string
{
    $code = [];
    $text = preg_replace_callback('/`([^`]+)`/', static function (array $matches) use (&$code): string {
        $key = '%%CODE' . count($code) . '%%';
        $code[$key] = '<code>' . h((string)$matches[1]) . '</code>';
        return $key;
    }, $text) ?? $text;

    $escaped = h($text);
    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $matches): string {
        $label = (string)$matches[1];
        $url = html_entity_decode((string)$matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safe = preg_match('/^https?:\/\//i', $url) === 1
            || str_starts_with($url, '?tab=documentation')
            || str_starts_with($url, '#');
        if (!$safe) {
            return $label;
        }
        $target = preg_match('/^https?:\/\//i', $url) === 1 ? ' target="_blank" rel="noopener noreferrer"' : '';
        return '<a href="' . h($url) . '"' . $target . '>' . $label . '</a>';
    }, $escaped) ?? $escaped;

    foreach ($code as $key => $html) {
        $escaped = str_replace($key, $html, $escaped);
    }

    return $escaped;
}

function documentationIsTableSeparator(string $line): bool
{
    return preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/', $line) === 1;
}

function documentationTableCells(string $line): array
{
    $line = trim($line);
    $line = trim($line, '|');
    return array_map('trim', explode('|', $line));
}

function documentationRenderMarkdown(string $markdown): string
{
    $lines = preg_split('/\R/', str_replace("\r\n", "\n", $markdown)) ?: [];
    $html = [];
    $paragraph = [];
    $count = count($lines);

    $flushParagraph = static function () use (&$html, &$paragraph): void {
        if (empty($paragraph)) {
            return;
        }
        $html[] = '<p>' . documentationRenderInline(implode(' ', array_map('trim', $paragraph))) . '</p>';
        $paragraph = [];
    };

    for ($index = 0; $index < $count; $index++) {
        $line = (string)$lines[$index];
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flushParagraph();
            continue;
        }

        if (str_starts_with($trimmed, '```')) {
            $flushParagraph();
            $code = [];
            $index++;
            while ($index < $count && !str_starts_with(trim((string)$lines[$index]), '```')) {
                $code[] = (string)$lines[$index];
                $index++;
            }
            $html[] = '<pre><code>' . h(implode("\n", $code)) . '</code></pre>';
            continue;
        }

        if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $matches) === 1) {
            $flushParagraph();
            $level = min(4, strlen((string)$matches[1]) + 1);
            $html[] = '<h' . $level . '>' . documentationRenderInline((string)$matches[2]) . '</h' . $level . '>';
            continue;
        }

        if ($index + 1 < $count && str_contains($trimmed, '|') && documentationIsTableSeparator((string)$lines[$index + 1])) {
            $flushParagraph();
            $headers = documentationTableCells($trimmed);
            $index += 2;
            $rows = [];
            while ($index < $count && str_contains((string)$lines[$index], '|') && trim((string)$lines[$index]) !== '') {
                $rows[] = documentationTableCells((string)$lines[$index]);
                $index++;
            }
            $index--;
            $table = '<div class="table-scroll"><table class="settings-table compact-sites"><thead><tr>';
            foreach ($headers as $header) {
                $table .= '<th>' . documentationRenderInline($header) . '</th>';
            }
            $table .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $table .= '<tr>';
                foreach ($row as $cell) {
                    $table .= '<td>' . documentationRenderInline($cell) . '</td>';
                }
                $table .= '</tr>';
            }
            $table .= '</tbody></table></div>';
            $html[] = $table;
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed) === 1) {
            $flushParagraph();
            $items = [];
            while ($index < $count && preg_match('/^[-*]\s+(.+)$/', trim((string)$lines[$index]), $matches) === 1) {
                $items[] = '<li>' . documentationRenderInline((string)$matches[1]) . '</li>';
                $index++;
            }
            $index--;
            $html[] = '<ul>' . implode('', $items) . '</ul>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/', $trimmed) === 1) {
            $flushParagraph();
            $items = [];
            while ($index < $count && preg_match('/^\d+\.\s+(.+)$/', trim((string)$lines[$index]), $matches) === 1) {
                $items[] = '<li>' . documentationRenderInline((string)$matches[1]) . '</li>';
                $index++;
            }
            $index--;
            $html[] = '<ol>' . implode('', $items) . '</ol>';
            continue;
        }

        if (str_starts_with($trimmed, '>')) {
            $flushParagraph();
            $quote = [];
            while ($index < $count && str_starts_with(trim((string)$lines[$index]), '>')) {
                $quote[] = preg_replace('/^>\s?/', '', trim((string)$lines[$index])) ?? '';
                $index++;
            }
            $index--;
            $html[] = '<blockquote>' . documentationRenderInline(implode(' ', $quote)) . '</blockquote>';
            continue;
        }

        $paragraph[] = $line;
    }
    $flushParagraph();

    return implode("\n", $html);
}
