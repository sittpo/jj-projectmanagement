<?php
declare(strict_types=1);
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page, array $params = []): string { return '/index.php?' . http_build_query(['page' => $page] + $params); }
function redirect(string $page, array $params = []): never { header('Location: ' . url($page, $params), true, 303); exit; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function icon(string $name): string {
    $paths = [
        'tasks' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="m7 8 1 1 2-2m-3 7 1 1 2-2m3-5h4m-4 6h4"/>',
        'building' => '<path d="M4 21V3h12v18M16 9h4v12M2 21h20M8 7h1m3 0h1M8 11h1m3 0h1M8 15h1m3 0h1M9 21v-3h3v3"/>',
        'team' => '<circle cx="12" cy="6" r="3"/><path d="M6 21v-3a6 6 0 0 1 12 0v3M4 6a2 2 0 0 0 0 4m16-4a2 2 0 0 1 0 4M2 19v-3a4 4 0 0 1 3-4m17 7v-3a4 4 0 0 0-3-4"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'store' => '<path d="M3 10h18l-2-6H5l-2 6Zm2 0v10h14V10M9 20v-6h6v6"/>',
        'users' => '<circle cx="9" cy="7" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 4a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v3"/>',
        'chart' => '<path d="M4 3v18h17M8 16v-4m5 4V8m5 8V5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 11h18m-14 4 3 3 6-5"/>',
        'camera' => '<path d="m3 8 4-4h10l4 4v12H3Z"/><circle cx="12" cy="13" r="4"/>',
        'pulse' => '<path d="M2 12h5l3-8 4 16 3-8h5"/>',
        'network' => '<rect x="8" y="3" width="8" height="5" rx="1"/><path d="M12 8v6M5 14h14M5 14v3m14-3v3"/><rect x="2" y="17" width="6" height="4" rx="1"/><rect x="16" y="17" width="6" height="4" rx="1"/>',
        'audio' => '<path d="m11 5-6 5H2v4h3l6 5V5Zm4 3a6 6 0 0 1 0 8m3-11a10 10 0 0 1 0 14"/>',
        'rack' => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M5 9h14M5 15h14M8 5h1m-1 7h1m-1 6h1"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'moon' => '<path d="M20 15A9 9 0 0 1 9 4a9 9 0 1 0 11 11Z"/>',
        'logout' => '<path d="M9 4H4v16h5m5-14 6 6-6 6m-7-6h13"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'plus' => '<path d="M12 4v16M4 12h16"/>',
        'shield' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6Z"/><path d="m8 12 3 3 5-6"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
    ];
    return '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['grid']) . '</svg>';
}
