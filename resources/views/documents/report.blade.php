<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {{-- A report or admin export as a PDF (TableFile): the same table as the Excel and CSV files. --}}
    <style>
        @page { margin: 16mm 12mm 16mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $fontSize }}pt; color: #1f2933; }
        .brand { font-size: 8pt; letter-spacing: 0.08em; text-transform: uppercase; color: #0f766e; font-weight: bold; }
        h1 { font-size: 14pt; margin: 1mm 0 1mm 0; }
        .meta { color: #6b7280; font-size: 8.5pt; margin-bottom: 4mm; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { text-align: left; background: #e8f1ee; color: #134e4a; padding: 1.4mm 1.2mm; border-bottom: 1px solid #99c2b8; vertical-align: bottom; }
        td { padding: 1.2mm 1.2mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        tr:nth-child(even) td { background: #f8faf9; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; white-space: nowrap; }
        .note { margin-top: 3mm; color: #92400e; font-size: 8.5pt; }
        .empty { color: #6b7280; padding: 6mm 0; text-align: center; }
        footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="brand">DormLink</div>
    <h1>{{ $title }}</h1>
    <div class="meta">
        @if ($subtitle){{ $subtitle }} · @endif{{ number_format($total) }} {{ $total === 1 ? 'row' : 'rows' }} · Made {{ $made }}
    </div>

    <table>
        <thead>
            <tr>
                @foreach ($headers as $i => $header)
                    <th class="{{ in_array($i, $money, true) ? 'right' : '' }}">{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $i => $value)
                        <td class="{{ in_array($i, $money, true) ? 'right' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($headers) }}">Nothing in this report for the period chosen.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($cut)
        <p class="note">This PDF shows the first {{ number_format($cut) }} of {{ number_format($total) }} rows. Download the Excel or CSV file for all of them.</p>
    @endif

    <footer>DormLink · {{ $title }} · Amounts in Philippine pesos (PHP)</footer>
</body>
</html>
