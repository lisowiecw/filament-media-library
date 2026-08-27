# 19: Importer, Column Driven

**What to build:** an application with thousands of existing hashed uploads runs one command and gets Media Asset rows pointing at those objects where they already are, with every existing URL still working. Re-running it changes nothing.

**Blocked by:** 02

**Status:** ready-for-agent

- [ ] `media:import` registers objects in place and never writes to the source disk
- [ ] Discovery is driven by a declared host model, column, disk and field context
- [ ] `object_key` is the legacy path verbatim; `original_client_filename` is its basename and `display_name` its stem
- [ ] Size read via the filesystem contract; mime resolved by the ladder header, then `--sniff` if given, then extension, then null, writing `mime_type` and `mime_source` together
- [ ] `--copy` is opt-in, asserts the destination is missing, and never deletes the source; there is no move mode
- [ ] An unknown disk fails the run hard; a missing object writes no row; an unknown uploader stays null
- [ ] Visibility is never read via `getVisibility()` on an s3-driver disk
- [ ] Identity is the unique disk and object key pair created with `firstOrCreate`, so re-runs are idempotent and later edits survive them
- [ ] `source: import` and `import_source` written on the `host.column` convention
- [ ] The denylist applies, and a blocked row is reported by path; ingest rules such as size and mismatch refusal do not apply
- [ ] An import report as a console summary plus a machine-readable file defaulting to a run-timestamped path under `storage/logs/`, naming omissions rather than successes
- [ ] Tested end to end at the Artisan seam against a faked disk and a seeded legacy schema
