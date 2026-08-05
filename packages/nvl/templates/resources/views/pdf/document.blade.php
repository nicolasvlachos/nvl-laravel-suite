<!doctype html>
<html lang="{{ $options->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $options->subject ?? $template->key }}</title>
    <style>
        body {
            color: #111827;
            font-family: dejavusans, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border-bottom: 0.2mm solid #d1d5db;
            padding: 2.5mm;
            text-align: left;
            vertical-align: top;
        }
    </style>
</head>
<body>
    @forelse($regions['main'] ?? $blocks as $block)
        <x-nvl-content::block :block="$block" />
    @empty
        {{ $data['content'] ?? '' }}
    @endforelse
</body>
</html>
