<?php
/**
 * Small view components shared across screens, so an empty state or a page
 * header looks the same everywhere without copy-pasting markup.
 */
declare(strict_types=1);

/**
 * An empty state that tells the reader what to do next, rather than just
 * announcing that a table is empty.
 */
function empty_state(string $icon, string $headline, string $body = '', array $action = []): string
{
    $out = '<div class="empty"><i class="fa-solid fa-' . e($icon) . '"></i>'
         . '<h4>' . e($headline) . '</h4>';
    if ($body !== '') {
        $out .= '<p class="note">' . e($body) . '</p>';
    }
    if ($action) {
        $out .= '<a class="btn red" href="' . e($action['href'] ?? '#') . '">'
              . '<i class="fa-solid fa-' . e($action['icon'] ?? 'plus') . '"></i> '
              . e($action['label'] ?? 'Get started') . '</a>';
    }
    return $out . '</div>';
}

/** A copyable identifier chip. */
function id_chip(string $id): string
{
    return '<span class="mono" data-copy="' . e($id) . '" style="cursor:pointer" title="Click to copy">'
         . e($id) . '</span>';
}

/** Relative timestamp that the interaction layer rewrites client-side. */
function ago(?string $ts): string
{
    if (!$ts) {
        return '<span class="note">—</span>';
    }
    return '<time data-ts="' . e(date('c', strtotime($ts))) . '">' . dt($ts) . '</time>';
}
