<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28mm 18mm 22mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; color: #1f2933; }
        h1 { font-size: 16pt; margin: 0 0 2mm 0; }
        .issuer { margin-bottom: 6mm; }
        .issuer .name { font-size: 13pt; font-weight: bold; }
        .muted { color: #6b7280; }
        .meta { width: 100%; margin: 4mm 0 6mm 0; }
        .meta td { padding: 1mm 0; vertical-align: top; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 3mm; }
        table.lines th { text-align: left; border-bottom: 1px solid #9ca3af; padding: 1.5mm 1mm; font-size: 9.5pt; }
        table.lines td { border-bottom: 1px solid #e5e7eb; padding: 1.5mm 1mm; vertical-align: top; }
        .right, table.lines th.right { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; border-top: 1px solid #9ca3af; }
        .stamp { color: #b91c1c; border: 2px solid #b91c1c; display: inline-block; padding: 1mm 3mm; font-weight: bold; font-size: 13pt; margin: 3mm 0; }
        .box { border: 1px solid #d1d5db; padding: 3mm 4mm; margin: 4mm 0; }
        footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; color: #6b7280; }
    </style>
</head>
<body>
    <div class="issuer">
        <div class="name">{{ $issuer['name'] }}</div>
        <div>{{ $issuer['property'] }}</div>
        @if ($issuer['address'])<div class="muted">{{ $issuer['address'] }}</div>@endif
        @if ($issuer['phone'])<div class="muted">{{ $issuer['phone'] }}</div>@endif
    </div>

    @yield('content')

    <footer>Made with DormLink on {{ now('Asia/Manila')->format('M j, Y g:i A') }}. @yield('footer')</footer>
</body>
</html>
