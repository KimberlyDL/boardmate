<?php

namespace App\Services\Finance;

/**
 * Charts for the finance PDFs, drawn as SVG and embedded as images (dompdf
 * draws SVG images but not inline <svg>). Only shapes and plain text, so they
 * look the same in any PDF reader; amounts on the axis are in pesos, shortened
 * ("12k").
 */
class FinanceChart
{
    private const BILLED = '#94a3b8';

    private const COLLECTED = '#0f766e';

    private const BAR = '#2563eb';

    /**
     * Billed and collected side by side for each month.
     *
     * @param  list<array{month: string, billed: int, collected: int}>  $series
     */
    public function trend(array $series, int $width = 640, int $height = 220): string
    {
        $left = 44;
        $bottom = 28;
        $top = 24;
        $plotW = $width - $left - 8;
        $plotH = $height - $top - $bottom;
        $max = $this->niceMax(max(1, ...array_map(fn ($m) => max($m['billed'], $m['collected']), $series ?: [['billed' => 0, 'collected' => 0]])));

        $svg = $this->gridlines($left, $top, $plotW, $plotH, $max);
        $slot = $plotW / max(1, count($series));
        $barW = min(16, $slot * 0.34);
        foreach ($series as $i => $m) {
            $x = $left + $i * $slot + ($slot - 2 * $barW - 2) / 2;
            $svg .= $this->column($x, $top, $plotH, $barW, $m['billed'] / $max, self::BILLED);
            $svg .= $this->column($x + $barW + 2, $top, $plotH, $barW, $m['collected'] / $max, self::COLLECTED);
            $svg .= $this->text($left + $i * $slot + $slot / 2, $height - 10, date('M', strtotime($m['month'].'-01')), 'middle');
        }
        $svg .= $this->legend($left, 12, [[self::BILLED, 'Billed'], [self::COLLECTED, 'Collected']]);

        return $this->dataUri($svg, $width, $height);
    }

    /**
     * Horizontal bars, one per label (e.g. what was billed, or unpaid by age).
     *
     * @param  array<string, int>  $values  label => centavos
     */
    public function bars(array $values, int $width = 320): string
    {
        $row = 22;
        $labelW = 105;
        $height = max(1, count($values)) * $row + 8;
        $plotW = $width - $labelW - 85;
        $max = max(1, ...array_map('abs', array_values($values) ?: [0]));

        $svg = '';
        $i = 0;
        foreach ($values as $label => $centavos) {
            $y = 4 + $i++ * $row;
            $w = max(0, $centavos) / $max * $plotW;
            $svg .= $this->text(0, $y + 14, $label);
            $svg .= sprintf('<rect x="%d" y="%d" width="%.1f" height="14" fill="%s"/>', $labelW, $y + 3, $w, self::BAR);
            $svg .= $this->text($labelW + $w + 6, $y + 14, $this->pesos($centavos));
        }

        return $this->dataUri($svg, $width, $height);
    }

    private function gridlines(int $left, int $top, float $plotW, int $plotH, int $max): string
    {
        $svg = '';
        foreach ([0, 0.25, 0.5, 0.75, 1] as $f) {
            $y = $top + $plotH - $f * $plotH;
            $svg .= sprintf('<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#e5e7eb" stroke-width="1"/>', $left, $y, $left + $plotW, $y);
            $svg .= $this->text($left - 6, $y + 4, $this->short((int) ($max * $f)), 'end');
        }

        return $svg;
    }

    private function column(float $x, int $top, int $plotH, float $w, float $share, string $color): string
    {
        $h = max(0, $share) * $plotH;

        return sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"/>', $x, $top + $plotH - $h, $w, $h, $color);
    }

    /** @param  list<array{0: string, 1: string}>  $items  color, label */
    private function legend(int $x, int $y, array $items): string
    {
        $svg = '';
        foreach ($items as [$color, $label]) {
            $svg .= sprintf('<rect x="%d" y="%d" width="10" height="10" fill="%s"/>', $x, $y - 9, $color);
            $svg .= $this->text($x + 14, $y, $label);
            $x += 14 + strlen($label) * 7 + 16;
        }

        return $svg;
    }

    private function text(float $x, float $y, string $text, string $anchor = 'start'): string
    {
        return sprintf('<text x="%.1f" y="%.1f" font-family="DejaVu Sans, sans-serif" font-size="10" fill="#4b5563" text-anchor="%s">%s</text>',
            $x, $y, $anchor, htmlspecialchars($text, ENT_XML1));
    }

    /** A round axis top just above the largest amount: 1, 2 or 5 times a power of ten (in centavos). */
    private function niceMax(int $centavos): int
    {
        $step = 10 ** max(0, (int) floor(log10($centavos)));
        foreach ([1, 2, 5, 10] as $m) {
            if ($m * $step >= $centavos) {
                return $m * $step;
            }
        }

        return 10 * $step;
    }

    /** 1250000 centavos → "12.5k" pesos. */
    private function short(int $centavos): string
    {
        $pesos = $centavos / 100;

        return match (true) {
            abs($pesos) >= 1_000_000 => rtrim(rtrim(number_format($pesos / 1_000_000, 1), '0'), '.').'M',
            abs($pesos) >= 1_000 => rtrim(rtrim(number_format($pesos / 1_000, 1), '0'), '.').'k',
            default => (string) (int) $pesos,
        };
    }

    /** "PHP 1,234.50" (the ₱ sign is not in the SVG text font). */
    private function pesos(int $centavos): string
    {
        return 'PHP '.number_format($centavos / 100, 2);
    }

    private function dataUri(string $body, int $width, int $height): string
    {
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">%s</svg>', $width, $height, $width, $height, $body);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
