@php
    $statePath = $getStatePath();
    $facets = $getFacets();
    $assets = $getAssets();
    $total = $getTotal();
    $selected = $getSelectedAssets();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fi-ml-library">
        {{-- The sidebar is rendered before the grid so a screen reader meets
             the narrowing before the results it produced. --}}
        <nav class="fi-ml-library-facets" aria-label="{{ __('media-library::messages.picker.grid.facets') }}">
            @foreach ($facets as $facet)
                <fieldset class="fi-ml-facet" data-facet="{{ $facet->key() }}">
                    <legend>{{ $facetLabel($facet) }}</legend>

                    <ul>
                        @foreach ($getSidebar()->options($facet) as $option)
                            @php($count = $facetCount($facet, $option))
                            <li>
                                <button
                                    type="button"
                                    class="fi-ml-facet-option @if ($isFacetSelected($facet, $option)) fi-ml-facet-option-selected @endif"
                                    data-facet-option="{{ $facet->key() }}:{{ $option }}"
                                    @if ($count !== null) data-facet-count="{{ $count }}" @endif
                                    aria-pressed="{{ $isFacetSelected($facet, $option) ? 'true' : 'false' }}"
                                    wire:key="fi-ml-facet-{{ $facet->key() }}-{{ $loop->index }}"
                                    wire:click="$set('{{ $statePath }}.filters', {{ \Illuminate\Support\Js::from($toggleFacet($facet, $option)) }})"
                                >
                                    <span class="fi-ml-facet-option-label">{{ $facetOptionLabel($facet, $option) }}</span>

                                    {{-- A library too large to count leaves the option
                                         listed and clickable with no number beside it. --}}
                                    @if ($count !== null)
                                        <span class="fi-ml-facet-option-count">{{ $count }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </fieldset>
            @endforeach
        </nav>

        <label class="fi-ml-library-sort">
            {{ __('media-library::messages.picker.grid.sort') }}

            <select
                class="fi-input"
                wire:model.live="{{ $statePath }}.sort"
            >
                @foreach ($getSortOptions() as $sort)
                    <option value="{{ $sort->value }}" @selected($getSort() === $sort)>{{ $sortLabel($sort) }}</option>
                @endforeach
            </select>
        </label>

        <input
            type="search"
            class="fi-input fi-ml-library-search"
            wire:model.live.debounce.{{ $getSearchDebounce() }}ms="{{ $statePath }}.search"
            aria-label="{{ __('media-library::messages.picker.grid.search') }}"
            placeholder="{{ __('media-library::messages.picker.grid.search_placeholder') }}"
        >

        {{-- A reset the person did not ask for is stated rather than left to be noticed. --}}
        <p class="fi-ml-library-announcement" role="status" aria-live="polite">
            @if ($wasReset())
                {{ __('media-library::messages.picker.grid.reset') }}
            @endif
        </p>

        @if ($assets->isEmpty())
            <p class="fi-ml-library-empty">{{ __('media-library::messages.picker.grid.empty') }}</p>
        @else
            <ul class="fi-ml-library-grid">
                @foreach ($assets as $asset)
                    <li>
                        <button
                            type="button"
                            class="fi-ml-card @if ($isSelected($asset)) fi-ml-card-selected @endif"
                            data-asset-id="{{ $asset->id }}"
                            aria-pressed="{{ $isSelected($asset) ? 'true' : 'false' }}"
                            wire:key="fi-ml-card-{{ $asset->id }}"
                            wire:click="$set('{{ $statePath }}.selection', {{ \Illuminate\Support\Js::from($toggle($asset)) }})"
                        >
                            @if ($canPreview($asset))
                                <img class="fi-ml-card-thumb" src="{{ $asset->url() }}" alt="{{ $asset->alt }}" loading="lazy">
                            @else
                                {{-- Nothing to preview: a quiet tinted tile rather than a spinner. --}}
                                <span class="fi-ml-card-glyph fi-ml-card-glyph-{{ $glyphFamily($asset) }}" aria-hidden="true">
                                    {{ $glyph($asset) }}
                                </span>
                            @endif

                            <span class="fi-ml-card-name">{!! $highlight($asset->display_name) !!}</span>

                            <span class="fi-ml-card-visibility fi-ml-card-visibility-{{ $asset->visibility->value }}">
                                {{ __('media-library::messages.visibility.' . $asset->visibility->value) }}
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($hasMore())
            {{-- The observer and the button ask for the same thing, so the grid is
                 continuous with a pointer and operable without one. --}}
            <div
                class="fi-ml-library-sentinel"
                x-data
                x-init="new IntersectionObserver((entries) => entries[0].isIntersecting && $wire.set('{{ $statePath }}.loaded', {{ $getNextBatch() }})).observe($el)"
            ></div>

            <button
                type="button"
                class="fi-ml-library-more"
                wire:click="$set('{{ $statePath }}.loaded', {{ $getNextBatch() }})"
            >
                {{ __('media-library::messages.picker.grid.load_more') }}
            </button>
        @else
            <p class="fi-ml-library-end">
                {{ trans_choice('media-library::messages.picker.grid.end', $total, ['count' => $total]) }}
            </p>
        @endif

        <footer class="fi-ml-library-selection" aria-live="polite">
            @if ($selected->isEmpty())
                {{ __('media-library::messages.picker.grid.selection_empty') }}
            @else
                <ol class="fi-ml-library-selection-items">
                    @foreach ($selected as $asset)
                        <li data-asset-id="{{ $asset->id }}">{{ $asset->display_name }}</li>
                    @endforeach
                </ol>
            @endif
        </footer>
    </div>
</x-dynamic-component>
