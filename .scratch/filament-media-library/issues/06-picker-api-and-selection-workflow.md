# Define Picker API and Selection Workflow

Status: resolved
Type: prototype
Blocked by: 02, 03, 04

## Question

What reusable Filament field/component API and user workflow should provide the Media Library view, existing-asset selection, new upload, single selection, multiple ordered selection, replacement, previews, and resource-form persistence? Define the minimum UX contract without prematurely deciding the unresolved search, pagination, and library-management details.

## Answer

### Component

A single field component, `MediaPicker::make('field_name')`, is the only picker surface. It renders the current attachments inline and opens one full **library modal** with two tabs, Library and Upload. The same component serves single and multiple selection; there is no second picker shape and no separate inline-dropzone variant.

Prototype: branch `prototype/06-picker-workflow`, commit `6106e5e`, file `.scratch/filament-media-library/prototypes/06-picker-workflow.PROTOTYPE.html`. Variants A (chosen), B (inline dropzone + slide-over) and C (search-first combobox) are all drivable there; B and C are retained only as the rejected alternatives. Open it with:

```
git show prototype/06-picker-workflow:.scratch/filament-media-library/prototypes/06-picker-workflow.PROTOTYPE.html > /tmp/picker.html
```

### Drop is accepted everywhere

Dropping a file is honoured at every surface: on the inline field trigger (which uploads and attaches **without opening the modal**), on the Library tab body, and on the Upload tab. One handler behind all three. Drop is on by default; `->droppable(false)` opts out for a reuse-only field.

Drop commits immediately — it attaches on release rather than joining the pending selection that the modal's confirm button applies. Click-to-select and drop therefore have deliberately different commit points; this is what makes the trigger-drop shortcut coherent.

Dropping several files on a single-selection field uses the first and warns; it is not an error.

### Previews

Image assets render real thumbnails in the grid and in the inline attachment strip. Non-image assets fall back to a type glyph. `->thumbnailUsing(fn (MediaAsset $a) => ...)` overrides per-asset preview resolution.

### What the picker offers

An asset is offered when **both** hold:

1. its stored mime matches the field's `acceptedFileTypes`, and
2. it is public, **or** the field itself uploads private.

Rule 2 is deliberately one-directional: a public field must never offer a private asset, because the rendered page would require a temporary URL; a private field may offer public assets, which it can serve directly. This keeps private files private without partitioning the library.

`acceptedFileTypes` therefore does double duty — it gates uploads *and* scopes the library grid. Unset means the grid shows everything; opting out is the loud choice. Matching supports `type/*` families and exact mimes, against the asset's stored mime. Documentation steers consumers to families (`video/*`), because exact mimes surprise people (`video/quicktime` does not match `video/mp4`).

Search operates inside the scoped set, so a hidden asset cannot be reached by typing.

**Disk and directory never scope the library.** They are upload placement only, exactly like visibility; delivery uses the asset's own recorded `(disk, object_key)` per ticket 03, so a cross-disk attachment resolves correctly. Scoping by directory was rejected outright: `posts/covers` and `posts/gallery` are both public image directories, and path-scoping would prevent reusing a cover image in a gallery, defeating ticket 02.

In the common Cloudflare R2 topology of one public bucket and one private bucket, the visibility rule reproduces disk-scoping behaviour without the plugin assuming that topology.

**Accepted cost:** a privately-uploaded file can never be reused in a public field; the user must re-upload, creating a duplicate asset. The alternative — promoting an asset's visibility on attach — mutates shared state and changes delivery for every other attachment, which ticket 03 already forbids.

**Escape hatch:** `->scopeLibrary(fn (Builder $query) => ...)` narrows what one picker offers. It never widens, never runs before global scopes, and is not a substitute for authorization. It is the sanctioned extension point for cases visibility cannot express (e.g. several public buckets on one panel). Tenant and authorization scoping are **global** query scopes, not field settings — a per-field opt-in would leak the first time someone forgot to configure a field.

### Visibility is never a picker control

The picker exposes no public/private choice. Visibility is field configuration applied to **new uploads only**, sitting beside `->disk()` and `->directory()` per ticket 03, and defaulting to private. Rationale: an editor cannot reasonably judge public addressability — wrong one way it leaks, wrong the other the image breaks — whereas the field author knows by definition.

Attaching an existing asset never changes its disk, directory or visibility.

The picker's obligation is to **show** the resulting placement honestly: the field label states where uploads land and with what visibility, the drop banner repeats it before release, and every attachment and grid card carries a public/private badge.

### Selection semantics

- Single fields cast to `int|null`; multiple fields cast to an ordered `int[]` of asset ids.
- Attaching an asset already attached in that field context is blocked as a duplicate (ticket 02).
- Reordering is exposed in the inline attachment strip. The prototype used arrow controls; drag-reorder is the intended v1 affordance, with arrows as the accessible fallback.
- Replacing a single-selection field attaches the new asset and detaches the previous one; the previous asset is untouched (ticket 05).
- Detach is available inline. **Delete is not exposed in the picker at all** — it remains a library-management action per ticket 05.

### API surface fixed by this ticket

```php
MediaPicker::make('cover_image')
    ->label('Cover image')
    ->acceptedFileTypes(['image/*'])
    ->disk('media')->directory('posts/covers')
    ->visibility('public'),

MediaPicker::make('gallery')
    ->multiple()
    ->reorderable()
    ->maxItems(12)
    ->acceptedFileTypes(['image/*', 'application/pdf', 'video/mp4'])
    ->visibility('private'),

// optional
    ->droppable(false)
    ->modalWidth('7xl')
    ->defaultTab('library')
    ->thumbnailUsing(fn (MediaAsset $a) => $a->previewUrl())
    ->scopeLibrary(fn (Builder $query) => $query->where('disk', 'archive')),

// host model
class BlogPost
{
    use HasMediaAttachments;

    protected $mediaFields = ['cover_image', 'gallery'];
}
```

### Deferred

Grid search behaviour, filter controls, pagination and bulk actions are not fixed here beyond "search exists and is scoped" — graduated to ticket 09. The library-management surface that ticket 05 requires (rename, delete, force delete, usage list) is graduated to ticket 10.

## Comments

- Rejected variants B and C: B splits selection across two surfaces for a speed gain that drop-anywhere on variant A already delivers; C cannot browse visually and forces the management page to exist as a prerequisite rather than a follow-on.
- Resolved with the requester on 2026-08-26, driving the prototype directly. The visibility scoping rule came out of the requester spotting that a gallery drop was silently landing private.
