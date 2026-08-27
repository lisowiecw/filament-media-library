# 20: Importer Attachments and Disk Traversal

**What to build:** the importer wires imported assets back to the hosts that referenced them, in the right order, and can still work on a legacy layout that has no column to read.

**Blocked by:** 06, 19

**Status:** ready-for-agent

- [ ] Cardinality is declared, never inferred, and a shape mismatch fails hard in both directions
- [ ] Array index order becomes attachment order verbatim
- [ ] In-array duplicates and missing or empty elements are skipped and reported
- [ ] A nested object or a URL in the column fails the run
- [ ] Attachment writes are idempotent on host, field and asset, and never rewrite an order a human has edited
- [ ] `--source=disk` is an explicitly degraded fallback requiring a prefix, iterating lazily with `listContents($prefix, true)` and never `allFiles()`
- [ ] A large bucket does not exhaust memory
- [ ] The report distinguishes skipped elements from failed rows
