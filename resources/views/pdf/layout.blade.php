{{--
    Shared shell for every generated document.

    dompdf supports a subset of CSS 2.1, so this uses tables and inline
    styles rather than flexbox or grid. Colours are spelled out because
    custom properties are not supported.
--}}
@php
    $gold = '#a86f2d';
    $goldLight = '#d8a35c';
    $ink = '#17352b';
    $muted = '#6f6358';
    $line = '#e8e0d5';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'GoldenHR')</title>
    <style>
        @page { margin: 28px 24px 40px 24px; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: {{ $ink }};
            margin: 0;
            line-height: 1.5;
        }
        table { width: 100%; border-collapse: collapse; }
        .doc-head { border-bottom: 3px solid {{ $gold }}; padding-bottom: 10px; margin-bottom: 16px; }
        .brand-name { font-size: 17px; font-weight: bold; letter-spacing: 2px; color: {{ $ink }}; }
        .brand-name span { color: {{ $gold }}; }
        .brand-tag { font-size: 8px; color: {{ $muted }}; letter-spacing: 1px; }
        .doc-title { font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .doc-ref { font-size: 9px; color: {{ $muted }}; }
        .eyebrow { font-size: 7.5px; letter-spacing: 1.4px; text-transform: uppercase; color: {{ $muted }}; margin: 0 0 2px 0; }
        .section { margin-bottom: 14px; }
        .section-title {
            font-size: 9px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1px; color: {{ $gold }}; border-bottom: 1px solid {{ $line }};
            padding-bottom: 4px; margin-bottom: 8px;
        }
        .kv td { padding: 3px 0; vertical-align: top; }
        .kv td.k { width: 38%; color: {{ $muted }}; }
        .kv td.v { font-weight: bold; }
        .grid td { width: 50%; padding: 0 8px 0 0; vertical-align: top; }
        .box { border: 1px solid {{ $line }}; background: #fdfaf6; padding: 8px; }
        .box.accent { background: #f8efe2; border-color: {{ $goldLight }}; }
        .pill { display: inline-block; padding: 2px 8px; border: 1px solid {{ $line }}; border-radius: 3px; font-size: 8px; }
        .pill.ok { background: #e1f7ea; color: #167647; border-color: #16764733; }
        .pill.off { background: #f3e6e4; color: #a8522f; border-color: #a8522f33; }
        .money { text-align: right; }
        .total-row td { border-top: 2px solid {{ $ink }}; font-weight: bold; font-size: 11px; padding-top: 6px; }
        table.data th {
            background: #f8efe2; text-align: left; padding: 5px 6px;
            font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px;
            border-bottom: 1px solid {{ $line }};
        }
        table.data td { padding: 4px 6px; border-bottom: 1px solid {{ $line }}; }
        table.data tr { page-break-inside: avoid; }
        .sign-line { border-top: 1px solid {{ $ink }}; padding-top: 3px; font-size: 8px; color: {{ $muted }}; }
        .foot {
            position: fixed; bottom: -24px; left: 0; right: 0;
            font-size: 7.5px; color: {{ $muted }};
            border-top: 1px solid {{ $line }}; padding-top: 5px;
        }
        .muted { color: {{ $muted }}; }
        .right { text-align: right; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
    <table class="doc-head">
        <tr>
            <td style="width: 55%;">
                <div class="brand-name">GOLDEN<span>HR</span></div>
                <div class="brand-tag">People, elevated.</div>
            </td>
            <td class="right">
                <div class="doc-title">@yield('doc-title', 'Document')</div>
                <div class="doc-ref">@yield('doc-ref', '')</div>
            </td>
        </tr>
    </table>

    @yield('body')

    <div class="foot">
        GoldenHR People Operations &middot; generated {{ $generatedAt->format('d M Y, H:i') }} UTC
        &middot; reference @yield('foot-ref', '—')
    </div>
</body>
</html>