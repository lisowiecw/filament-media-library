# 23: Documentation and the Promised Surface

**What to build:** an application developer can tell what will survive an upgrade, an operator can tell which obligations are theirs, and both are written down before the first release.

**Blocked by:** 09, 12, 14, 17, 18, 20, 21, 22

**Status:** ready-for-agent

- [ ] README documents the promised surface: the plugin class and its fluent config, `MediaPicker` and its config methods, `HasMedia`, the `MediaAsset` model with its `attachments` and `derivatives` relations, the ability and gate names, the config keys, and the command signatures `media:import`, `media:resolve-mimes`, `media:regenerate-derivatives` and `media:assign-tenant`
- [ ] README documents the internal surface: the Delivery route URL and name, Livewire components and view names, derivative key layout, jobs and queue payloads, and the schema beyond the exposed columns; `$asset->url()` is named as the supported way to get a URL
- [ ] README states the foreign-origin obligation from ADR-0009 plainly as the operator's, names a concrete edge content policy for public buckets, and notes that it needs a custom domain
- [ ] README records the manual R2 stored-header observation and its date, and states that CI does not re-run it
- [ ] README notes the GPL-2.0-or-later obligation from the SVG sanitizer
- [ ] README documents the two-step migration: import, then resolve mimes
- [ ] `UPGRADING.md` carries the four breaking-change rules (a migration demanding a data decision, a changed default about what is served or refused, a new fail-closed gate, a config key removed or redefined) and records that the promised command list now includes all four commands
- [ ] The package is versioned `0.1.0`
