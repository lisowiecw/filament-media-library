# 10: Library Tab Grid

**What to build:** a content editor opens the picker, browses what the library already holds, searches it, scrolls it, and clicks cards to select. Confirming attaches the selection in the order they picked.

**Blocked by:** 07

**Status:** ready-for-agent

- [ ] A library modal with Library and Upload tabs
- [ ] The offer scope lists an asset when its mime matches `acceptedFileTypes` and it is either public or the field uploads private, minus blocked types; disk and directory never scope the library
- [ ] One search input matching case-insensitive substrings across readable name, original filename, alt text, uploader and object key
- [ ] Whitespace splits the query into terms that all must match, and matches are highlighted in the card name
- [ ] Infinite scroll in batches of 48, plus an explicit "Load more" button, plus an end marker stating the total; no numbered pages and no page-size control
- [ ] Clicking a card toggles selection; the footer always shows the live ordered selection
- [ ] A search change resets the selection, and the reset is announced rather than silent
- [ ] Every card carries a public or private badge
- [ ] Assets with nothing to preview render a tinted glyph tile
- [ ] Tested as a Livewire component test at a realistic library size
