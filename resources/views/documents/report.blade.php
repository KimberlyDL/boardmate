<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {{-- A report or export as a PDF (TableFile): the same table as the Excel and CSV files. --}}
    <style>
        @page { margin: 14mm 12mm 16mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $fontSize }}pt; color: #1f2933; }

        .top { width: 100%; border-collapse: collapse; margin-bottom: 5mm; }
        .top td { vertical-align: middle; padding: 0; }
        .top td.mark { width: 8mm; }
        .top td.mark img { width: 7.6mm; height: auto; display: block; }
        .brand { padding-left: 2.5mm !important; font-weight: bold; font-size: 11pt; color: #0f766e; }
        .made { text-align: right; color: #6b7280; font-size: 8pt; }

        .band { border-left: 1.2mm solid #0f766e; padding: 1mm 0 1mm 3.5mm; margin-bottom: 4mm; }
        h1 { font-size: 16pt; margin: 0; color: #134e4a; }
        .sub { color: #4b5563; font-size: 9pt; margin-top: 1mm; }
        .chip { display: inline-block; background: #e8f1ee; color: #134e4a; border-radius: 3mm; padding: 0.6mm 2.5mm; font-size: 8pt; margin-top: 1.5mm; }

        .figures { width: 100%; border-collapse: separate; border-spacing: 2.5mm 0; margin: 0 -2.5mm 5mm -2.5mm; }
        .figure { background: #f3f8f6; border: 1px solid #d6e7e1; border-radius: 2mm; padding: 2.5mm 3mm; }
        .figure .label { color: #4b5563; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.04em; }
        .figure .value { font-size: 12pt; font-weight: bold; color: #0f766e; margin-top: 0.8mm; }
        .figure .peso { font-size: 8pt; color: #6b7280; font-weight: normal; }

        table.data { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        table.data th { text-align: left; background: #0f766e; color: #ffffff; padding: 1.8mm 1.4mm; vertical-align: bottom; font-weight: bold; }
        table.data th:first-child { border-top-left-radius: 1.5mm; }
        table.data th:last-child { border-top-right-radius: 1.5mm; }
        table.data td { padding: 1.4mm 1.4mm; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.data tr.alt td { background: #f7faf9; }
        tr { page-break-inside: avoid; }
        .right { text-align: right; white-space: nowrap; }
        table.data tr.total td { background: #e8f1ee; font-weight: bold; color: #134e4a; border-top: 1.5px solid #0f766e; border-bottom: none; }

        .note { margin-top: 3mm; color: #92400e; font-size: 8.5pt; }
        .empty { color: #6b7280; padding: 8mm 0; text-align: center; }

        footer { position: fixed; bottom: -11mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 1.5mm; }
        footer table { width: 100%; border-collapse: collapse; }
        footer td { padding: 0; }
    </style>
</head>
<body>
    <footer>
        <table><tr>
            <td>DormLink · {{ $title }}@if ($money) · Amounts in Philippine pesos (PHP)@endif</td>
            <td class="right">{{-- page numbers are drawn by TableFile --}}</td>
        </tr></table>
    </footer>

    <table class="top"><tr>
        <td class="mark"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('images/logo-mark.png'))) }}" alt="DormLink"></td>
        <td class="brand">DormLink</td>
        <td class="made">Made {{ $made }}</td>
    </tr></table>

    <div class="band">
        <h1>{{ $title }}</h1>
        @if ($subtitle)<div class="sub">{{ $subtitle }}</div>@endif
        <span class="chip">{{ number_format($total) }} {{ $total === 1 ? 'row' : 'rows' }}</span>
    </div>

    @if ($figures)
        <table class="figures"><tr>
            @foreach ($figures as $figure)
                <td class="figure" style="width: {{ floor(100 / count($figures)) }}%;">
                    <div class="label">{{ $figure['label'] }}</div>
                    <div class="value"><span class="peso">PHP</span> {{ $figure['value'] }}</div>
                </td>
            @endforeach
        </tr></table>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($headers as $i => $header)
                    <th class="{{ in_array($i, $money, true) ? 'right' : '' }}">{{ str_replace(' (PHP)', '', $header) }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r => $row)
                <tr class="{{ $r % 2 === 1 ? 'alt' : '' }}">
                    @foreach ($row as $i => $value)
                        <td class="{{ in_array($i, $money, true) ? 'right' : '' }}">{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ count($headers) }}">Nothing to show for this choice.</td></tr>
            @endforelse
            @if ($totals && ! $cut)
                <tr class="total">
                    @foreach ($headers as $i => $header)
                        <td class="{{ in_array($i, $money, true) ? 'right' : '' }}">{{ in_array($i, $money, true) ? $totals[$i] : ($i === 0 ? 'Total' : '') }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @if ($cut)
        <p class="note">This PDF shows the first {{ number_format($cut) }} of {{ number_format($total) }} rows; the totals above count all of them. Download the Excel or CSV file for every row.</p>
    @endif
</body>
</html>
