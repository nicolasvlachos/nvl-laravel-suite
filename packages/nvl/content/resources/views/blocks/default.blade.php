<section
    data-content-block="{{ $block->id }}"
    data-content-definition="{{ $block->definitionKey }}"
    @if ($block->key !== null) data-content-key="{{ $block->key }}" @endif
>
    @foreach ($block->values as $name => $value)
        <x-nvl-content::field
            :name="$name"
            :type="$block->fieldTypes[$name] ?? null"
            :value="$value"
        />
    @endforeach

    @foreach ($block->children as $child)
        <x-nvl-content::block :block="$child" />
    @endforeach
</section>
