@props(['composition', 'region' => null])

@php
    $blocks = $region === null
        ? $composition->blocks
        : ($composition->regions[$region] ?? []);
@endphp

@foreach ($blocks as $block)
    <x-nvl-content::block :block="$block" />
@endforeach
