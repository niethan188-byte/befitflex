<?php
declare(strict_types=1);

/**
 * Charts drawn as inline SVG, server-side.
 *
 * Why not a JavaScript charting library? Three reasons that matter here:
 * the pages already work offline apart from two CDN fonts; SVG prints
 * correctly, so a report exported to paper keeps its charts; and there is
 * no flash of an empty canvas while a bundle loads. Every method returns a
 * string you echo straight into the page.
 */
final class Chart
{
    private const RED  = '#E53935';
    private const HOT  = '#FF5A56';
    private const DIM  = '#6B7280';
    private const GRID = 'rgba(255,255,255,.07)';

    private static int $seq = 0;

    private static function uid(string $p): string
    {
        return $p . (++self::$seq);
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** Nice round upper bound so the axis labels are readable numbers. */
    private static function ceilNice(float $max): float
    {
        if ($max <= 0) {
            return 1;
        }
        $mag  = 10 ** floor(log10($max));
        $norm = $max / $mag;
        $step = $norm <= 1 ? 1 : ($norm <= 2 ? 2 : ($norm <= 5 ? 5 : 10));
        return $step * $mag;
    }

    /**
     * Area + line chart. Pass $forecastFrom to render the tail as a dashed
     * projection in a lighter tone.
     *
     * @param array<int, array{label:string, total:float|int}> $points
     */
    public static function area(array $points, array $o = []): string
    {
        $w   = $o['w']   ?? 720;
        $h   = $o['h']   ?? 220;
        $pad = ['l' => 52, 'r' => 14, 't' => 14, 'b' => 26];
        $fmt = $o['format'] ?? 'number';
        $fc  = $o['forecastFrom'] ?? null;   // index where projection begins

        $n = count($points);
        if ($n < 2) {
            return self::blank($w, $h, 'Not enough data yet');
        }

        $vals = array_map(static fn($p) => (float) $p['total'], $points);
        $max  = self::ceilNice(max($vals) * 1.12);
        $iw   = $w - $pad['l'] - $pad['r'];
        $ih   = $h - $pad['t'] - $pad['b'];

        $x = static fn(int $i): float => $pad['l'] + ($iw * $i / ($n - 1));
        $y = static fn(float $v): float => $pad['t'] + $ih - ($ih * ($max > 0 ? $v / $max : 0));

        $gid = self::uid('grad');
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';
        $svg .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
              . '<stop offset="0%" stop-color="' . self::RED . '" stop-opacity=".42"/>'
              . '<stop offset="100%" stop-color="' . self::RED . '" stop-opacity="0"/></linearGradient></defs>';

        /* horizontal gridlines + y labels */
        for ($g = 0; $g <= 4; $g++) {
            $gy = $pad['t'] + ($ih * $g / 4);
            $gv = $max - ($max * $g / 4);
            $svg .= '<line x1="' . $pad['l'] . '" y1="' . round($gy, 1) . '" x2="' . ($w - $pad['r'])
                  . '" y2="' . round($gy, 1) . '" stroke="' . self::GRID . '" stroke-width="1"/>';
            $svg .= '<text x="' . ($pad['l'] - 8) . '" y="' . round($gy + 4, 1)
                  . '" text-anchor="end" font-size="10" fill="' . self::DIM . '">'
                  . self::esc(self::fmt($gv, $fmt)) . '</text>';
        }

        /* solid portion */
        $solidEnd = $fc === null ? $n - 1 : max(0, $fc - 1);
        $line = $fill = '';
        for ($i = 0; $i <= $solidEnd; $i++) {
            $line .= ($i ? ' L' : 'M') . round($x($i), 1) . ',' . round($y($vals[$i]), 1);
        }
        $fill = $line . ' L' . round($x($solidEnd), 1) . ',' . ($pad['t'] + $ih)
              . ' L' . round($x(0), 1) . ',' . ($pad['t'] + $ih) . ' Z';

        $svg .= '<path d="' . $fill . '" fill="url(#' . $gid . ')"/>';
        $svg .= '<path d="' . $line . '" fill="none" stroke="' . self::RED . '" stroke-width="2.5" '
              . 'stroke-linejoin="round" stroke-linecap="round"/>';

        /* dashed projection */
        if ($fc !== null && $fc < $n) {
            $proj = '';
            for ($i = $solidEnd; $i < $n; $i++) {
                $proj .= ($i === $solidEnd ? 'M' : ' L') . round($x($i), 1) . ',' . round($y($vals[$i]), 1);
            }
            $svg .= '<path d="' . $proj . '" fill="none" stroke="' . self::HOT . '" stroke-width="2" '
                  . 'stroke-dasharray="5 4" opacity=".85"/>';
        }

        /* points + x labels */
        $every = (int) max(1, ceil($n / 12));
        for ($i = 0; $i < $n; $i++) {
            $isProj = $fc !== null && $i >= $fc;
            $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($vals[$i]), 1) . '" r="'
                  . ($isProj ? '3' : '3.4') . '" fill="' . ($isProj ? '#1F1F1F' : self::RED) . '" stroke="'
                  . ($isProj ? self::HOT : '#1F1F1F') . '" stroke-width="1.6"><title>'
                  . self::esc($points[$i]['label'] . ': ' . self::fmt($vals[$i], $fmt))
                  . ($isProj ? ' (projected)' : '') . '</title></circle>';

            if ($i % $every === 0 || $i === $n - 1) {
                $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" '
                      . 'font-size="10" fill="' . self::DIM . '">' . self::esc((string) $points[$i]['label']) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /** Vertical bars. @param array<int, array{label:string, total:float|int}> $points */
    public static function bars(array $points, array $o = []): string
    {
        $w   = $o['w'] ?? 720;
        $h   = $o['h'] ?? 200;
        $fmt = $o['format'] ?? 'number';
        $interactive = !empty($o['interactive']);
        $pad = ['l' => 46, 'r' => 12, 't' => 12, 'b' => 26];

        $n = count($points);
        if (!$n) {
            return self::blank($w, $h, 'No data');
        }

        $vals = array_map(static fn($p) => (float) $p['total'], $points);
        $max  = self::ceilNice(max($vals) * 1.1);
        $iw   = $w - $pad['l'] - $pad['r'];
        $ih   = $h - $pad['t'] - $pad['b'];
        $slot = $iw / $n;
        $bw   = min(38, $slot * 0.64);

        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';

        for ($g = 0; $g <= 3; $g++) {
            $gy = $pad['t'] + ($ih * $g / 3);
            $svg .= '<line x1="' . $pad['l'] . '" y1="' . round($gy, 1) . '" x2="' . ($w - $pad['r'])
                  . '" y2="' . round($gy, 1) . '" stroke="' . self::GRID . '"/>';
            $svg .= '<text x="' . ($pad['l'] - 8) . '" y="' . round($gy + 4, 1) . '" text-anchor="end" '
                  . 'font-size="10" fill="' . self::DIM . '">'
                  . self::esc(self::fmt($max - ($max * $g / 3), $fmt)) . '</text>';
        }

        $every = (int) max(1, ceil($n / 14));
        foreach ($vals as $i => $v) {
            $bh = $max > 0 ? ($ih * $v / $max) : 0;
            $bx = $pad['l'] + ($slot * $i) + (($slot - $bw) / 2);
            $by = $pad['t'] + $ih - $bh;

            $barClass = $interactive ? ' class="chart-bar"' : '';
            $barData = $interactive && !empty($points[$i]['d'])
                ? ' data-date="' . self::esc((string) $points[$i]['d']) . '"'
                : '';
            $svg .= '<rect' . $barClass . $barData . ' x="' . round($bx, 1) . '" y="' . round($by, 1)
                . '" width="' . round($bw, 1) . '" height="' . round(max(1.5, $bh), 1) . '" rx="3" fill="'
                . self::RED . '" opacity="' . ($v > 0 ? '.9' : '.22') . '"><title>'
                  . self::esc($points[$i]['label'] . ': ' . self::fmt($v, $fmt)) . '</title></rect>';

            if ($i % $every === 0 || $i === $n - 1) {
                $svg .= '<text x="' . round($bx + $bw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" '
                      . 'font-size="10" fill="' . self::DIM . '">' . self::esc((string) $points[$i]['label']) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /** Donut with a centre figure. @param array<int, array{label:string, value:float}> $slices */
    public static function donut(array $slices, array $o = []): string
    {
        $size   = $o['size'] ?? 190;
        $centre = $o['centre'] ?? null;
        $sub    = $o['sub'] ?? '';

        $total = 0.0;
        foreach ($slices as $s) {
            $total += (float) $s['value'];
        }
        if ($total <= 0) {
            return self::blank($size, $size, 'No data');
        }

        $shades = ['#E53935', '#FF5A56', '#9F2522', '#FF8F8C', '#6B2422', '#FFB3AC'];
        $r  = $size / 2 - 12;
        $c  = $size / 2;
        $sw = 22;
        $circ = 2 * M_PI * $r;

        $svg = '<svg class="chart donut" viewBox="0 0 ' . $size . ' ' . $size . '" role="img">';
        $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" '
              . 'stroke="rgba(255,255,255,.06)" stroke-width="' . $sw . '"/>';

        $offset = 0.0;
        foreach ($slices as $i => $s) {
            $frac = (float) $s['value'] / $total;
            $len  = $circ * $frac;
            $svg .= '<circle cx="' . $c . '" cy="' . $c . '" r="' . $r . '" fill="none" stroke="'
                  . $shades[$i % count($shades)] . '" stroke-width="' . $sw . '" stroke-linecap="butt" '
                  . 'stroke-dasharray="' . round($len, 2) . ' ' . round($circ - $len, 2) . '" '
                  . 'stroke-dashoffset="' . round(-$offset, 2) . '" '
                  . 'transform="rotate(-90 ' . $c . ' ' . $c . ')"><title>'
                  . self::esc($s['label'] . ': ' . round($frac * 100) . '%') . '</title></circle>';
            $offset += $len;
        }

        if ($centre !== null) {
            $svg .= '<text x="' . $c . '" y="' . ($c + 2) . '" text-anchor="middle" font-size="26" '
                  . 'font-weight="700" fill="#F3F4F6">' . self::esc((string) $centre) . '</text>';
            if ($sub !== '') {
                $svg .= '<text x="' . $c . '" y="' . ($c + 20) . '" text-anchor="middle" font-size="10" '
                      . 'fill="' . self::DIM . '">' . self::esc($sub) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /** Legend rows to sit beside a donut. */
    public static function legend(array $slices): string
    {
        $shades = ['#E53935', '#FF5A56', '#9F2522', '#FF8F8C', '#6B2422', '#FFB3AC'];
        $total = 0.0;
        foreach ($slices as $s) {
            $total += (float) $s['value'];
        }

        $out = '<div class="legend">';
        foreach ($slices as $i => $s) {
            $pct = $total > 0 ? round((float) $s['value'] / $total * 100) : 0;
            $out .= '<div class="legend-row"><span class="swatch" style="background:'
                  . $shades[$i % count($shades)] . '"></span>'
                  . '<span class="l">' . self::esc((string) $s['label']) . '</span>'
                  . '<span class="v">' . ($s['display_html'] ?? self::esc((string) ($s['display'] ?? $s['value']))) . '</span>'
                  . '<span class="p">' . $pct . '%</span></div>';
        }
        return $out . '</div>';
    }

    /** Day x hour intensity grid. */
    public static function heatmap(array $grid, int $max, array $options = []): string
    {
        $days  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $hours = range(5, 23);
        $interactive = !empty($options['interactive']);

        $out = '<div class="heat"><div class="heat-corner"></div>';
        foreach ($hours as $h) {
            $out .= '<div class="heat-h">' . ($h % 3 === 0 ? ($h % 12 ?: 12) . ($h < 12 ? 'a' : 'p') : '') . '</div>';
        }

        foreach ($days as $d => $name) {
            $out .= '<div class="heat-d">' . $name . '</div>';
            foreach ($hours as $h) {
                $n = $grid[$d][$h] ?? 0;
                $t = $max > 0 ? $n / $max : 0;
                $bg = $n === 0
                    ? 'rgba(255,255,255,.035)'
                    : 'rgba(225,6,0,' . round(0.18 + $t * 0.82, 2) . ')';
                    $label = $name . ' ' . (($h % 12) ?: 12) . ($h < 12 ? 'AM' : 'PM') . ' — '
                         . $n . ' check-in' . ($n === 1 ? '' : 's');
                    if ($interactive) {
                      $out .= '<button type="button" class="heat-c" style="background:' . $bg . '"'
                          . ' data-day="' . $d . '" data-hour="' . $h . '" title="' . self::esc($label)
                          . '" aria-label="' . self::esc($label) . '"></button>';
                    } else {
                      $out .= '<div class="heat-c" style="background:' . $bg . '" title="'
                          . self::esc($label) . '"></div>';
                    }
            }
        }

        return $out . '</div>';
    }

    /** Tiny trend line for a stat card. */
    public static function spark(array $vals, array $o = []): string
    {
        $w = $o['w'] ?? 110;
        $h = $o['h'] ?? 30;
        $n = count($vals);
        if ($n < 2) {
            return '';
        }

        $max = max($vals);
        $min = min($vals);
        $rng = max(0.0001, (float) ($max - $min));

        $d = '';
        foreach ($vals as $i => $v) {
            $x = $w * $i / ($n - 1);
            $y = $h - 3 - (($h - 6) * (($v - $min) / $rng));
            $d .= ($i ? ' L' : 'M') . round($x, 1) . ',' . round($y, 1);
        }

        $rising = ($vals[$n - 1] ?? 0) >= ($vals[0] ?? 0);
        $col    = $o['color'] ?? ($rising ? '#4ADE80' : '#F87171');

        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none">'
             . '<path d="' . $d . '" fill="none" stroke="' . $col . '" stroke-width="1.8" '
             . 'stroke-linecap="round" stroke-linejoin="round" opacity=".9"/></svg>';
    }

    /** Semi-circular gauge for a single percentage. */
    public static function gauge(float $pct, string $label, array $o = []): string
    {
        $w   = $o['w'] ?? 170;
        $h   = 100;
        $pct = max(0, min(100, $pct));
        $r   = 62;
        $cx  = $w / 2;
        $cy  = 84;
        $len = M_PI * $r;

        $col = $pct >= 70 ? '#4ADE80' : ($pct >= 40 ? '#FBBF24' : '#F87171');

        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';
        $svg .= '<path d="M' . ($cx - $r) . ',' . $cy . ' A' . $r . ',' . $r . ' 0 0 1 ' . ($cx + $r) . ',' . $cy
              . '" fill="none" stroke="rgba(255,255,255,.08)" stroke-width="13" stroke-linecap="round"/>';
        $svg .= '<path d="M' . ($cx - $r) . ',' . $cy . ' A' . $r . ',' . $r . ' 0 0 1 ' . ($cx + $r) . ',' . $cy
              . '" fill="none" stroke="' . $col . '" stroke-width="13" stroke-linecap="round" '
              . 'stroke-dasharray="' . round($len * $pct / 100, 2) . ' ' . round($len, 2) . '"/>';
        $svg .= '<text x="' . $cx . '" y="' . ($cy - 12) . '" text-anchor="middle" font-size="26" '
              . 'font-weight="700" fill="#F3F4F6">' . round($pct) . '%</text>';
        $svg .= '<text x="' . $cx . '" y="' . ($cy + 12) . '" text-anchor="middle" font-size="10" '
              . 'fill="' . self::DIM . '">' . self::esc($label) . '</text>';

        return $svg . '</svg>';
    }

    /** Horizontal ranked bars, for leaderboards. */
    public static function ranked(array $rows, array $o = []): string
    {
        $fmt = $o['format'] ?? 'number';
        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, (float) $r['value']);
        }
        if ($max <= 0) {
            return '<div class="empty"><i class="fa-solid fa-chart-simple"></i>No data yet.</div>';
        }

        $out = '<div class="ranked">';
        foreach ($rows as $r) {
            $pct = round((float) $r['value'] / $max * 100);
            $out .= '<div class="rank-row">'
                  . '<div class="rank-l">' . self::esc((string) $r['label']) . '</div>'
                  . '<div class="rank-bar"><span style="width:' . $pct . '%"></span></div>'
                  . '<div class="rank-v">' . self::esc($r['display'] ?? self::fmt((float) $r['value'], $fmt)) . '</div>'
                  . '</div>';
        }
        return $out . '</div>';
    }

    private static function fmt(float $v, string $mode): string
    {
        if ($mode === 'peso') {
            return $v >= 1000 ? '₱' . round($v / 1000, 1) . 'k' : '₱' . round($v);
        }
        if ($mode === 'pct') {
            return round($v) . '%';
        }
        return $v >= 1000 ? round($v / 1000, 1) . 'k' : (string) round($v);
    }

    private static function blank(int $w, int $h, string $msg): string
    {
        return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">'
             . '<text x="' . ($w / 2) . '" y="' . ($h / 2) . '" text-anchor="middle" font-size="12" '
             . 'fill="' . self::DIM . '">' . self::esc($msg) . '</text></svg>';
    }
}
