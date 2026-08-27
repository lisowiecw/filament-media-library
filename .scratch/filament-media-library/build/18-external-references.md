# 18: External References

**What to build:** something outside any host model, a newsletter or an export, can record that it uses an asset, so it blocks deletion like any other usage and can be revoked when its creating code is gone.

**Blocked by:** 17

**Status:** ready-for-agent

- [ ] An External reference is an Attachment with a null host, carrying an identifier and a label
- [ ] `$asset->attachments()->createExternal('newsletter-2026-08', 'Campaign #412')` writes one
- [ ] External references count as usage and block deletion through the existing mechanism, with no second mechanism
- [ ] They are excluded from every field-context query, so `HasMedia` never returns them
- [ ] They are revocable per row from the usage panel
- [ ] Host-model attachment rows are not removable from that panel; detaching stays on the host record
