# 04: Ingest Validation Floor

**What to build:** an upload that is too large, of a blocked type, or lying about what it is gets refused with a message that says why. An operator gets warned at boot when their configured limit cannot actually be reached.

**Blocked by:** 03

**Status:** ready-for-agent

- [ ] `media.max_upload_size` config, default 12 MB, env-readable, overridable per field in either direction
- [ ] A boot-time warning when the configured limit exceeds the PHP or Livewire ceiling
- [ ] `media.blocked_types` denylist defaulting to `php`, `phar`, `phtml`, `htaccess`, `application/x-httpd-php`, `application/x-msdownload`, matched on both extension and resolved mime, a floor a field can only narrow
- [ ] The accepted-type gate re-runs against the sniffed type rather than erroring on a declared/sniffed disagreement, so a `.csv` sniffing as `text/plain` passes
- [ ] A sniffed type in a different top-level family than the extension is refused even when both types are individually accepted
- [ ] Refusal messages name both the declared and the sniffed type, and never expose the object key
- [ ] Active content is stored but flagged so delivery can force a download; a public Placement refuses active content at upload as a validation failure, with no silent downgrade
- [ ] Blocked-type assets are excluded from every picker offer query
- [ ] Rules bind at ingest only; a config change never rejects, hides or deletes an already stored asset
