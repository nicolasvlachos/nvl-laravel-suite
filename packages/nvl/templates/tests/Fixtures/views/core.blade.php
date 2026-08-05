<article lang="{{ $options->locale }}">
    <h1>{{ $options->subject }}</h1>
    <p>{{ $data['message'] ?? '' }}</p>
    <small>{{ $settings['tone'] ?? '' }}</small>
</article>
