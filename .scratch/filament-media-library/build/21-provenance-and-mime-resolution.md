# 21: Provenance and MIME Resolution

**What to build:** an imported asset says where it came from and how confident its type is, and a second explicit pass upgrades that confidence when the operator decides to pay for it.

**Blocked by:** 19

**Status:** ready-for-agent

- [ ] `source`, `mime_source` and `import_source` are real columns, surfaced on the management page only
- [ ] `media:resolve-mimes` re-runs the ladder, defaulting to `--from=extension`, and requires `--sniff` to fetch bytes
- [ ] The command writes `mime_type` and `mime_source` together, so a row never claims a rung it did not come from
- [ ] Sniffing uses `Symfony\Component\Mime\MimeTypes::guessMimeType()`
- [ ] MIME resolution never happens lazily on the Delivery route, so a read path never performs a write or a fetch
- [ ] `--sniff` on the importer and this command are never chained; the window between them is covered by the `mime_source` facet rather than a banner
- [ ] The two-step migration (import, then resolve) is written down for the README ticket, explaining why a fresh import renders as downloads
