@php
    $assets = $getSelectedAssets();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fi-ml-picker" data-field="{{ $getName() }}">
        {{-- Directly under the label, so the placement is read before anything is added. --}}
        <p class="fi-ml-picker-banner">{{ $getPlacementSummary() }}</p>

        @if ($assets->isEmpty())
            <p class="fi-ml-picker-empty">
                {{ __('media-library::messages.picker.empty') }}
            </p>
        @else
            <ul class="fi-ml-picker-items">
                @foreach ($assets as $asset)
                    <li class="fi-ml-picker-item" data-asset-id="{{ $asset->id }}">
                        <span class="fi-ml-picker-item-name">{{ $asset->display_name }}</span>
                        <span class="fi-ml-picker-item-visibility">
                            {{ __('media-library::messages.visibility.' . $asset->visibility) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif

        {{ $getAction('upload') }}
    </div>
</x-dynamic-component>
