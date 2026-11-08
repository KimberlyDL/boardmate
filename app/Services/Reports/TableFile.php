<?php

namespace App\Services\Reports;

use App\Support\ManilaDate;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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

    /**
     * @param  list<string>  $headers
     * @param  iterable<list<string|int|float|null>>  $rows
     * @param  array{title: string, subtitle?: ?string}  $info  shown at the top of the PDF
     */
    public function download(string $format, string $filename, array $headers, iterable $rows, array $info): Response
    {
        return match ($format) {
            'xlsx' => $this->xlsx($filename, $headers, $rows, $info['title']),
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

    /** UTF-8 with a byte-order mark so Excel shows ₱ and ñ correctly. */
    private function csv(string $filename, array $headers, iterable $rows): Response
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function xlsx(string $filename, array $headers, iterable $rows, string $title): Response
    {
        return response()->streamDownload(function () use ($headers, $rows, $title) {
            $writer = new Writer(new Options(properties: new Properties(title: $title, application: 'DormLink', creator: 'DormLink', lastModifiedBy: 'DormLink')));
            $writer->openToFile('php://output');
            $sheet = $writer->getCurrentSheet();
            // Excel sheet names: at most 31 characters, none of \ / ? * [ ] :
            $sheet->setName(trim(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $title), 0, 31)) ?: 'Report');
            $sheet->setSheetView(new SheetView(freezeRow: 2));

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
            $styles = [
                'head' => (new Style)->withFontBold(true)->withBackgroundColor('E8F1EE'),
                'money' => (new Style)->withFormat('#,##0.00'),
                'percent' => (new Style)->withFormat('0.0'),
                'date' => (new Style)->withFormat('yyyy-mm-dd'),
            ];
            $writer->addRow(Row::fromValuesWithStyle($headers, $styles['head']));

            $write = function (array $row) use ($writer, $money, $percent, $styles) {
                $cells = [];
                foreach (array_values($row) as $i => $value) {
                    $cells[] = $this->cell($value, in_array($i, $money, true), in_array($i, $percent, true), $styles);
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
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param array<string, Style> $styles */
    private function cell(string|int|float|null $value, bool $money, bool $percent, array $styles): Cell
    {
        if (($money || $percent) && is_string($value) && is_numeric($value)) {
            return Cell::fromValue((float) $value, $money ? $styles['money'] : $styles['percent']);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && ($date = DateTimeImmutable::createFromFormat('!Y-m-d', $value))) {
            return Cell::fromValue($date, $styles['date']);
        }

        return Cell::fromValue($value ?? '');
    }

    /**
     * Characters per column, from the header and the sampled rows, kept
     * between 8 and 50 (long notes wrap in Excel when widened).
     *
     * @param  list<list<string|int|float|null>>  $sample
     * @return list<float>
     */
    private function widths(array $headers, array $sample): array
    {
        return array_map(function (string $header, int $i) use ($sample) {
            $longest = mb_strlen($header);
            foreach ($sample as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$i] ?? '')));
            }

            return (float) min(50, max(8, $longest + 2));
        }, $headers, array_keys($headers));
    }

    /** @param array{title: string, subtitle?: ?string} $info */
    private function pdf(string $filename, array $headers, iterable $rows, array $info): Response
    {
        $text = [];
        $total = 0;
        foreach ($rows as $row) {
            if ($total++ < self::PDF_MAX_ROWS) {
                $text[] = $this->asText($headers, $row);
            }
        }
        $columns = count($headers);

        return Pdf::loadView('documents.report', [
            'title' => $info['title'],
            'subtitle' => $info['subtitle'] ?? null,
            'made' => ManilaDate::today()->format('M j, Y'),
            'headers' => $headers,
            'rows' => $text,
            'total' => $total,
            'cut' => $total > self::PDF_MAX_ROWS ? self::PDF_MAX_ROWS : null,
            'money' => $this->moneyColumns($headers),
            // Many columns: smaller print on a landscape page.
            'fontSize' => match (true) {
                $columns > 12 => 6.5,
                $columns > 8 => 7.5,
                default => 9,
            },
        ])->setPaper('a4', $columns > 6 ? 'landscape' : 'portrait')
            ->setOption('isFontSubsettingEnabled', true)
            ->download($filename);
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
