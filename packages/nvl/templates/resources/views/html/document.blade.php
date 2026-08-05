<!doctype html>
<html lang="{{ $options->locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $options->subject ?? $template->key }}</title>
</head>
<body>
    <main>
        @forelse($regions['main'] ?? $blocks as $block)
            <x-nvl-content::block :block="$block" />
        @empty
            {{ $data['content'] ?? '' }}
        @endforelse
    </main>
</body>
</html>
