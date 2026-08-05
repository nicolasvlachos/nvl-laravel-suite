@props(['name', 'value', 'type' => null])

@if ($value instanceof \Nvl\Content\Data\RenderedRichTextData)
    <div data-content-field="{{ $name }}">{!! $value->html !!}</div>
@elseif ($value instanceof \Nvl\Media\Data\Display\PublicMedia)
    @if ($value->image !== null)
        <img
            data-content-field="{{ $name }}"
            src="{{ $value->image->src }}"
            alt="{{ $value->alt ?? '' }}"
            @if ($value->image->width !== null) width="{{ $value->image->width }}" @endif
            @if ($value->image->height !== null) height="{{ $value->image->height }}" @endif
            loading="lazy"
        >
    @else
        <a data-content-field="{{ $name }}" href="{{ $value->url }}">
            {{ $value->title ?? $name }}
        </a>
    @endif
@elseif ($value instanceof \Nvl\Content\Data\RenderedPrivateMediaData)
    @if (str_starts_with($value->mimeType, 'image/'))
        <img
            data-content-field="{{ $name }}"
            src="{{ $value->url }}"
            alt=""
            loading="lazy"
        >
    @else
        <a data-content-field="{{ $name }}" href="{{ $value->url }}">{{ $name }}</a>
    @endif
@elseif (is_bool($value))
    <span data-content-field="{{ $name }}">{{ $value ? '1' : '0' }}</span>
@elseif (is_scalar($value))
    <span data-content-field="{{ $name }}">{{ $value }}</span>
@elseif ($type === 'table' && is_array($value) && $value !== [])
    @php
        $firstRow = is_array($value[0] ?? null) ? $value[0] : [];
        $columns = array_values(array_filter(
            array_keys($firstRow),
            static fn (mixed $column): bool => is_string($column) && $column !== '_key',
        ));
    @endphp

    <table data-content-field="{{ $name }}">
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th scope="col">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($value as $row)
                @if (is_array($row))
                    <tr>
                        @foreach ($columns as $column)
                            <td>
                                <x-nvl-content::field
                                    :name="$column"
                                    :value="$row[$column] ?? null"
                                />
                            </td>
                        @endforeach
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
@elseif (is_array($value))
    <div data-content-field="{{ $name }}">
        @foreach ($value as $childName => $childValue)
            <x-nvl-content::field :name="is_string($childName) ? $childName : $name" :value="$childValue" />
        @endforeach
    </div>
@endif
