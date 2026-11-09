<?php

namespace App\Services\Reports;

use App\Support\ManilaDate;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * One table (a report or an admin export) as a file people keep, or as a
 * preview: CSV for any program, Excel (.xlsx) with real numbers and dates,
 * or a PDF to print or send. Every format reads the same headers and rows.
 *
 * - Columns named "… (PHP)" are money: numbers with two decimals in Excel,
 *   "1,234.56" in the PDF. "… (%)" columns are numbers too.
 * - A YYYY-MM-DD value is a real date in Excel.
 * - Other text stays text, so a reference number keeps its leading zeros.
 */
class TableFile
{
    /** @var list<string> */
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const PREVIEW_ROWS = 50;

    /** A PDF stops here (dompdf gets slow); the Excel file has every row. */
    public const PDF_MAX_ROWS = 1500;

    /** Rows read to size the Excel columns. */
    private const WIDTH_SAMPLE = 200;

    /** Row of the column headers in the Excel file (1-based): under the title and subtitle. */
    public const XLSX_HEADER_ROW = 4;

    private const BRAND = '0F766E';

    private const BRAND_SOFT = 'E8F1EE';

    private const STRIPE = 'F7FAF9';

    private const LINE = 'E5E7EB';

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     * @param  array{title: string, subtitle?: ?string, totals?: bool}  $info  shown at the top; `totals` adds a total of each money column
     */
    public function download(string $format, string $filename, array $headers, iterable $rows, array $info): Response
    {
        return match ($format) {
            'xlsx' => $this->xlsx($filename, $headers, $rows, $info),
            'pdf' => $this->pdf($filename, $headers, $rows, $info),
            default => $this->csv($filename, $headers, $rows),
        };
    }

    /**
     * The first rows as text, and how many there are in all.
     *
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     * @return array{headers: list<string>, rows: list<list<string>>, total: int, money_columns: list<int>}
     */
    public function preview(array $headers, iterable $rows): array
    {
        $first = [];
        $total = 0;
        foreach ($rows as $row) {
            if ($total++ < self::PREVIEW_ROWS) {
                $first[] = $this->asText($headers, $row);
            }
        }

        return ['headers' => $headers, 'rows' => $first, 'total' => $total, 'money_columns' => $this->moneyColumns($headers)];
    }

    /** "dormlink-collections-…" + the format's extension. */
    public static function filename(string $base, string $format): string
    {
        return $base.'.'.(in_array($format, self::FORMATS, true) ? $format : 'csv');
    }

    /**
     * UTF-8 with a byte-order mark so Excel shows ₱ and ñ correctly. Plain
     * data, no title rows, so any program can read it. Text that a
     * spreadsheet would run as a formula (=, +, @, or - before a non-digit)
     * gets a leading apostrophe.
     */
    private function csv(string $filename, array $headers, iterable $rows): Response
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => self::safeText($v ?? ''), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Excel: the title and subtitle on top, the column headers in the brand
     * colour with filters, striped rows, real numbers and dates, and a total
     * row (live SUM formulas) when the table has totals.
     *
     * @param  array{title: string, subtitle?: ?string, totals?: bool}  $info
     */
    private function xlsx(string $filename, array $headers, iterable $rows, array $info): Response
    {
        return response()->streamDownload(function () use ($headers, $rows, $info) {
            $columns = count($headers);
            $options = new Options(properties: new Properties(title: $info['title'], application: 'DormLink', creator: 'DormLink', lastModifiedBy: 'DormLink'));
            if ($columns > 1) {
                $options->mergeCells(0, 1, $columns - 1, 1);
                $options->mergeCells(0, 2, $columns - 1, 2);
            }
            $writer = new Writer($options);
            $writer->openToFile('php://output');
            $sheet = $writer->getCurrentSheet();
            // Excel sheet names: at most 31 characters, none of \ / ? * [ ] :
            $sheet->setName(trim(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $info['title']), 0, 31)) ?: 'Report');
            $sheet->setSheetView(new SheetView(freezeRow: self::XLSX_HEADER_ROW + 1));

            // The first rows set the column widths, which Excel needs before any row.
            $sample = [];
            $rest = (function () use ($rows, &$sample) {
                foreach ($rows as $row) {
                    if (count($sample) < self::WIDTH_SAMPLE) {
                        $sample[] = $row;

                        continue;
                    }
                    yield $row;
                }
            })();
            $rest->current();
            foreach ($this->widths($headers, $sample) as $column => $width) {
                $sheet->setColumnWidth($width, $column + 1);
            }

            $money = $this->moneyColumns($headers);
            $percent = array_keys(array_filter($headers, fn ($h) => str_contains($h, '(%)')));
            $styles = $this->xlsxStyles();

            $writer->addRow(new Row([new StringCell($info['title'], $styles['title'])], 24));
            $made = 'Made '.ManilaDate::today()->format('M j, Y').' with DormLink'.($money ? ' · amounts in Philippine pesos' : '');
            $writer->addRow(new Row([new StringCell(implode(' · ', array_filter([$info['subtitle'] ?? null, $made])), $styles['subtitle'])]));
            $writer->addRow(new Row([]));
            $writer->addRow(new Row(array_map(fn (string $h, int $i) => new StringCell($h, in_array($i, $money, true) || in_array($i, $percent, true) ? $styles['head_right'] : $styles['head']), $headers, array_keys($headers)), 30));

            $count = 0;
            $sums = array_fill_keys($money, 0.0);
            $write = function (array $row) use ($writer, $money, $percent, $styles, &$count, &$sums) {
                $stripe = $count++ % 2 === 1;
                $cells = [];
                foreach (array_values($row) as $i => $value) {
                    if (isset($sums[$i]) && is_numeric($value)) {
                        $sums[$i] += (float) $value;
                    }
                    $cells[] = $this->cell($value, in_array($i, $money, true), in_array($i, $percent, true), $styles, $stripe);
                }
                $writer->addRow(new Row($cells));
            };
            foreach ($sample as $row) {
                $write($row);
            }
            while ($rest->valid()) {
                $write($rest->current());
                $rest->next();
            }

            $first = self::XLSX_HEADER_ROW + 1;
            $last = self::XLSX_HEADER_ROW + $count;
            if ($count === 0) {
                $writer->addRow(new Row([new StringCell('Nothing to show for this choice.', $styles['subtitle'])]));
            } elseif (($info['totals'] ?? false) && $money) {
                $cells = [];
                foreach ($headers as $i => $header) {
                    $letter = self::columnLetter($i);
                    $cells[] = in_array($i, $money, true)
                        ? new FormulaCell("=SUM({$letter}{$first}:{$letter}{$last})", round($sums[$i], 2), $styles['total_money'])
                        : new StringCell($i === 0 ? 'Total' : '', $styles['total']);
                }
                $writer->addRow(new Row($cells, 20));
            }
            if ($count > 0) {
                $sheet->setAutoFilter(new AutoFilter(0, self::XLSX_HEADER_ROW, $columns - 1, $last));
            }
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @return array<string, Style> */
    private function xlsxStyles(): array
    {
        $line = new Border(new BorderPart(BorderName::BOTTOM, self::LINE, BorderWidth::THIN, BorderStyle::SOLID));
        $base = (new Style)->withBorder($line)->withCellVerticalAlignment(CellVerticalAlignment::TOP);
        $head = (new Style)->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor(self::BRAND)
            ->withShouldWrapText(true)->withCellVerticalAlignment(CellVerticalAlignment::CENTER);
        $total = (new Style)->withFontBold(true)->withBackgroundColor(self::BRAND_SOFT)
            ->withBorder(new Border(new BorderPart(BorderName::TOP, self::BRAND, BorderWidth::MEDIUM, BorderStyle::SOLID)));
        $styles = [
            'title' => (new Style)->withFontBold(true)->withFontSize(15)->withFontColor(self::BRAND),
            'subtitle' => (new Style)->withFontColor('6B7280')->withFontSize(10),
            'head' => $head,
            'head_right' => $head->withCellAlignment(CellAlignment::RIGHT),
            'total' => $total,
            'total_money' => $total->withFormat('#,##0.00'),
        ];
        foreach (['' => $base, '_stripe' => $base->withBackgroundColor(self::STRIPE)] as $suffix => $style) {
            $styles['text'.$suffix] = $style;
            $styles['money'.$suffix] = $style->withFormat('#,##0.00');
            $styles['percent'.$suffix] = $style->withFormat('0.0');
            $styles['date'.$suffix] = $style->withFormat('mmm d, yyyy')->withCellAlignment(CellAlignment::LEFT);
        }

        return $styles;
    }

    /**
     * One cell: money and percentages as numbers, YYYY-MM-DD as a date,
     * everything else as text (never run as a formula).
     *
     * @param  array<string, Style>  $styles
     */
    private function cell(string|int|float|null $value, bool $money, bool $percent, array $styles, bool $stripe = false): Cell
    {
        $s = $stripe ? '_stripe' : '';
        if (($money || $percent) && is_numeric($value)) {
            return Cell::fromValue((float) $value, $money ? $styles['money'.$s] : $styles['percent'.$s]);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value))) {
            return Cell::fromValue($date, $styles['date'.$s]);
        }
        if (is_int($value) || is_float($value)) {
            return Cell::fromValue($value, $styles['text'.$s]);
        }

        return new StringCell((string) ($value ?? ''), $styles['text'.$s]);
    }

    /** A, B, … Z, AA, AB … for a 0-based column. */
    private static function columnLetter(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /** Text a spreadsheet would read as a formula gets a leading apostrophe. */
    private static function safeText(string|int|float $value): string|int|float
    {
        return is_string($value) && preg_match('/^(?:[=+@\t\r]|-(?![\d.]))/', $value) ? "'".$value : $value;
    }

    /**
     * Characters per column, from the header and the sampled rows, kept
     * between 10 and 50 (long notes wrap in Excel when widened).
     *
     * @param  list<list<string|int|float|null>>  $sample
     * @return list<float>
     */
    private function widths(array $headers, array $sample): array
    {
        return array_map(function (string $header, int $i) use ($sample) {
            $longest = (int) ceil(mb_strlen($header) * 0.8); // headers wrap onto two lines
            foreach ($sample as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$i] ?? '')));
            }

            return (float) min(50, max(10, $longest + 3));
        }, $headers, array_keys($headers));
    }

    /**
     * PDF: a branded header, the totals as figures on top (when the table
     * has totals), the table with a total row, and page numbers.
     *
     * @param  array{title: string, subtitle?: ?string, totals?: bool}  $info
     */
    private function pdf(string $filename, array $headers, iterable $rows, array $info): Response
    {
        $money = $this->moneyColumns($headers);
        $text = [];
        $sums = array_fill_keys($money, 0.0);
        $total = 0;
        foreach ($rows as $row) {
            $row = array_values($row);
            foreach ($money as $i) {
                $sums[$i] += is_numeric($row[$i] ?? null) ? (float) $row[$i] : 0;
            }
            if ($total++ < self::PDF_MAX_ROWS) {
                // Dates as people read them on paper ("Oct 5, 2026"); the Excel and CSV files keep YYYY-MM-DD.
                $text[] = array_map(fn (string $v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && ($d = DateTimeImmutable::createFromFormat('!Y-m-d', $v)) ? $d->format('M j, Y') : $v,
                    $this->asText($headers, $row));
            }
        }
        $columns = count($headers);
        $totals = ($info['totals'] ?? false) && $money && $total > 0
            ? array_map(fn (float $sum) => number_format($sum, 2), $sums)
            : null;

        $pdf = Pdf::loadView('documents.report', [
            'title' => $info['title'],
            'subtitle' => $info['subtitle'] ?? null,
            'made' => ManilaDate::today()->format('M j, Y'),
            'headers' => $headers,
            'rows' => $text,
            'total' => $total,
            'cut' => $total > self::PDF_MAX_ROWS ? self::PDF_MAX_ROWS : null,
            'money' => $money,
            'totals' => $totals,
            // The figures on top: up to four totals, by their header without "(PHP)".
            'figures' => $totals ? array_slice(array_map(fn (int $i) => ['label' => trim(str_replace('(PHP)', '', $headers[$i])), 'value' => $totals[$i]], $money), 0, 4) : [],
            // Many columns: smaller print on a landscape page.
            'fontSize' => match (true) {
                $columns > 12 => 6.5,
                $columns > 8 => 7.5,
                default => 9,
            },
        ])->setPaper('a4', $columns > 6 ? 'landscape' : 'portrait')
            ->setOption('isFontSubsettingEnabled', true);

        // "Page 1 of 3" at the bottom right of every page (CSS cannot count the pages in dompdf).
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $font = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 12 * 2.835 - 44, $canvas->get_height() - 10.1 * 2.835, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.42, 0.45, 0.5]);

        return $pdf->download($filename);
    }

    /**
     * A row as people read it: money with thousands separators, empty for null.
     *
     * @param  list<string|int|float|null>  $row
     * @return list<string>
     */
    private function asText(array $headers, array $row): array
    {
        $money = $this->moneyColumns($headers);

        return array_map(function ($value, int $i) use ($money) {
            if ($value === null) {
                return '';
            }
            if (in_array($i, $money, true) && is_numeric($value)) {
                return number_format((float) $value, 2);
            }

            return (string) $value;
        }, array_values($row), array_keys(array_values($row)));
    }

    /** @return list<int> */
    private function moneyColumns(array $headers): array
    {
        return array_keys(array_filter($headers, fn ($h) => str_contains($h, '(PHP)')));
    }
}
