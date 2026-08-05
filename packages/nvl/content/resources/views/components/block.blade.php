@php
    $view = $block->view;
@endphp

@include($view, ['block' => $block])
