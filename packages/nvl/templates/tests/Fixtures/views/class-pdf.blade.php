<!doctype html>
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <style>body { font-family: dejavusans; }</style>
</head>
<body>
    <h1>{{ $content->get('heading') }}</h1>
    <p>{{ $data['recipient_name'] }}</p>
    <p>{{ $options['reference'] ?? '' }}</p>
    <img src="{{ $assets->get('logo') }}" alt="">
</body>
</html>
