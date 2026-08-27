# 12: Full Picker Surface

**What to build:** the picker's complete configured API: galleries, reordering, drop anywhere, and the escape hatches a field author needs.

**Blocked by:** 10

**Status:** ready-for-agent

- [ ] `->multiple()`, `->reorderable()`, `->maxItems()`
- [ ] Reordering by dragging and by arrow controls, so it works from the keyboard
- [ ] Drop accepted at every surface: the inline field trigger, the Library tab body and the Upload tab
- [ ] A drop on the inline trigger uploads and attaches without opening the modal
- [ ] A drop commits immediately; a click-selection commits on modal confirm
- [ ] Several files dropped on a single-selection field uses the first and warns
- [ ] `->droppable(false)` opts a field out of upload entirely
- [ ] `->scopeLibrary(fn (Builder $query) => ...)` narrows the offer scope and can only narrow it
- [ ] `->thumbnailUsing()`, `->modalWidth()`, `->defaultTab()`
- [ ] Attaching the same asset twice to one field is prevented
- [ ] Removing an attached item detaches and never deletes; a changed id in a single-selection field is just the diff, and the previous asset survives
- [ ] The configured surface matches the prototype on branch `prototype/06-picker-workflow`:

```php
MediaPicker::make('gallery')
    ->multiple()->reorderable()->maxItems(12)
    ->acceptedFileTypes(['image/*', 'application/pdf', 'video/mp4'])
    ->visibility('private')
    ->droppable(false)
    ->modalWidth('7xl')
    ->defaultTab('library')
    ->thumbnailUsing(fn (MediaAsset $a) => $a->previewUrl())
    ->scopeLibrary(fn (Builder $query) => $query->where('disk', 'archive'));
```
