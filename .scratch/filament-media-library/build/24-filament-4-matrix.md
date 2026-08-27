# 24: Filament 4 Matrix

**What to build:** the `^4.0|^5.0` constraint stops being a claim and becomes something CI proves on every push, with a red v4 job blocking a release.

**Blocked by:** 23

**Status:** ready-for-agent

- [ ] One test suite runs against both Filament majors in a CI matrix
- [ ] Any v4 adapter shim is isolated behind the shared field and plugin APIs, leaving the build v5-first
- [ ] A red v4 job is a release blocker
- [ ] The README compatibility table is generated from the matrix, so drift is visible
- [ ] The documented way to end v4 support is narrowing to `^5.0` and dropping the job in the same commit
- [ ] Exact visual and interaction parity between the two majors is explicitly not an acceptance criterion
