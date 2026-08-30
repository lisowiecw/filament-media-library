# 13. A disk that cannot deliver its visibility is refused

Date: 2026-08-30

## Status

Accepted

## Context

On Cloudflare R2, access is a property of the bucket rather than of the object:
R2 implements no ACL operations, so the visibility the package passes to a write
changes nothing about who can read the bytes. Only the choice of bucket does.
That left the disk-to-visibility pairing to convention, with two silent failure
modes. A public placement on a disk with no public URL hands out an address that
answers 403 for every viewer. A private placement on the public bucket is routed
through the Delivery route and has View checked on it, while the bytes stay
fetchable by anyone who guesses the key: the package reports a private asset that
is not private.

Neither is detectable by reading the provider back, since R2 answers no
`GetObjectAcl`. ADR 12 deferred this check rather than folding it into
resolution.

## Decision

`Placement::resolve()` refuses a placement whose disk cannot deliver its
visibility, throwing `PlacementMisconfigured` naming the field, the disk, the
visibility and the rule broken. Two rules: a public placement on a configured
disk with no `url` key, and a private placement on a disk the application has
declared public, either as the configured `public_disk` or with
`'visibility' => 'public'` on the disk itself. The refusal happens when the placement resolves, so a
misconfigured field fails on the first render rather than on the first upload.

The check reads `filesystems.disks` and the package's own configuration and
nothing else: no `getVisibility()`, no provider-specific call. A disk the
application has not declared is left alone, since naming an unconfigured disk
fails elsewhere on its own. `media-library.enforce_disk_visibility` turns the
whole guard off in one flag.

## Consequences

A two-bucket deployment now states its invariant in configuration and has it
enforced, rather than re-asserting it at every call site. The declared world is
the only world the package judges, so a disk whose config says one thing and
whose bucket says another is still undetected: the guard catches configuration
that is wrong on its face, not a bucket left public by mistake.

The opt-out exists because an application may deliberately serve a disk with no
`url` through its own origin, which is a deployment the package cannot see.
Turning it off turns off both rules, since a deployment that owns its own
delivery path owns both halves of the question.
