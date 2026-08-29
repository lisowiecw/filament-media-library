@php
    $assets = $getSelectedAssets();
    $reorderable = $isReorderable();
    $droppable = $isDroppable();
    $key = $getKey();
    $dropStatePath = $getDropStatePath();
    $last = $assets->count() - 1;
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
            <ol
                class="fi-ml-picker-items @if ($reorderable) fi-ml-picker-items-reorderable @endif"
                @if ($reorderable)
                    x-data="{
                        from: null,
                        order() {
                            return [...$el.querySelectorAll('[data-asset-id]')].map((item) => Number(item.dataset.assetId))
                        },
                        drop(to) {
                            if (this.from === null || this.from === to) return
                            const ids = this.order()
                            ids.splice(to, 0, ids.splice(this.from, 1)[0])
                            this.from = null
                            $wire.callSchemaComponentMethod(@js($key), 'reorderItems', { ids })
                        },
                    }"
                @endif
            >
                @foreach ($assets as $index => $asset)
                    <li
                        class="fi-ml-picker-item"
                        data-asset-id="{{ $asset->id }}"
                        wire:key="fi-ml-item-{{ $asset->id }}"
                        @if ($reorderable)
                            draggable="true"
                            x-on:dragstart="from = {{ $index }}"
                            x-on:dragover.prevent
                            x-on:drop.prevent.stop="drop({{ $index }})"
                        @endif
                    >
                        @if ($thumbnail = $getThumbnailUrl($asset))
                            <img class="fi-ml-picker-item-thumb" src="{{ $thumbnail }}" alt="{{ $asset->alt }}" loading="lazy">
                        @endif

                        <span class="fi-ml-picker-item-name">{{ $asset->display_name }}</span>
                        <span class="fi-ml-picker-item-visibility">
                            {{ __('media-library::messages.visibility.' . $asset->visibility->value) }}
                        </span>

                        {{-- The arrows are the same rearrangement as the drag, so
                             the order is reachable without a pointer. --}}
                        @if ($reorderable)
                            <button
                                type="button"
                                class="fi-ml-picker-item-up"
                                @disabled($index === 0)
                                aria-label="{{ __('media-library::messages.picker.move_up', ['name' => $asset->display_name]) }}"
                                wire:click="callSchemaComponentMethod(@js($key), 'moveItem', { id: {{ $asset->id }}, step: -1 })"
                            >&uarr;</button>

                            <button
                                type="button"
                                class="fi-ml-picker-item-down"
                                @disabled($index === $last)
                                aria-label="{{ __('media-library::messages.picker.move_down', ['name' => $asset->display_name]) }}"
                                wire:click="callSchemaComponentMethod(@js($key), 'moveItem', { id: {{ $asset->id }}, step: 1 })"
                            >&darr;</button>
                        @endif

                        {{-- Detach, never delete: the asset outlives the field. --}}
                        <button
                            type="button"
                            class="fi-ml-picker-item-remove"
                            aria-label="{{ __('media-library::messages.picker.detach', ['name' => $asset->display_name]) }}"
                            wire:click="callSchemaComponentMethod(@js($key), 'removeItem', { id: {{ $asset->id }} })"
                        >&times;</button>
                    </li>
                @endforeach
            </ol>
        @endif

        {{-- The trigger is itself a drop surface: a dropped file uploads and
             attaches at once, so the common "add this one file" never pays for
             a modal round trip. --}}
        <div
            class="fi-ml-picker-trigger"
            @if ($droppable)
                data-droppable="true"
                x-data="{ hot: false }"
                x-bind:class="{ 'fi-ml-picker-trigger-hot': hot }"
                x-on:dragover.prevent="hot = true"
                x-on:dragleave.prevent="hot = false"
                x-on:drop.prevent="
                    hot = false
                    const files = [...$event.dataTransfer.files]
                    if (! files.length) return
                    $wire.uploadMultiple(
                        @js($dropStatePath),
                        files,
                        () => $wire.callSchemaComponentMethod(@js($key), 'dropped'),
                    )
                "
            @endif
        >
            {{ $getAction('library') }}

            @if ($droppable)
                <p class="fi-ml-picker-drop-hint">{{ __('media-library::messages.picker.drop_hint') }}</p>
            @endif
        </div>
    </div>
</x-dynamic-component>
