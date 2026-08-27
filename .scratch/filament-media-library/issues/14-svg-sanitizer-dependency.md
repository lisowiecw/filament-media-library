# Choose the SVG Sanitizer Dependency

Status: resolved
Type: research
Blocked by: 13

## Question

Ticket 13 made a sanitized SVG a first-class inline image and an unsanitizable one a refused upload, with the sanitizer itself a probed optional dependency: absent sanitizer means SVG uploads are refused. It did not name the library.

Find which PHP SVG sanitizers are maintained and appropriate for a package dependency (`enshrined/svg-sanitize` being the obvious candidate, but confirm rather than assume), and establish for the chosen one: whether it reports failure distinguishably from "sanitized to nothing", so ticket 13's refuse-on-failure rule has something concrete to key on; whether it removes external references and event-handler attributes as ticket 13 assumed, or only script elements; and its PHP and Laravel version compatibility against ticket 01's Laravel 13 / PHP 8.3+ floor.

Decide whether it ships as a hard `require` or a `suggest` that the plugin probes at runtime. Ticket 13 assumed `suggest` by analogy with ticket 12's `ffmpeg`, but `ffmpeg` is a system binary and this is a Composer package, so the analogy may not hold.

## Answer

**`enshrined/svg-sanitize`, constrained `^0.22`, in `require`.** Full findings with source citations live on the throwaway branch `research/svg-sanitizer` at `.scratch/filament-media-library/research/svg-sanitizer.md` (commit `9a1acfc`).

**The library.** The obvious candidate holds up: roughly 49 million installs and 108 direct dependents, including TYPO3 core, Craft, Contao, October/Winter, `plank/laravel-mediable`, and `awcodes/filament-curator`, the last being an MIT Filament media picker on Filament `^4.0|^5.0` and therefore the closest possible comparable. Its public advisory history (three patched bypasses since 2022) reads as maturity rather than as a warning: a sanitizer nobody has attacked is not a safer one. The reservations are real but not disqualifying. Maintenance is slow, with the last commit twelve months old and 26 open issues, so treat it as stable and watched for security rather than actively developed. And it is GPL-2.0-or-later, not MIT, which this ticket did not anticipate; a hard `require` pulls GPL code into every installer's vendor tree. The ecosystem has already made that call (two MIT Laravel packages in this exact problem space require it outright), so the plugin follows suit and notes the license in the README under third-party licenses.

The only serious alternative, `rhukster/dom-sanitizer`, is MIT and more actively maintained with a noticeably more current CSS threat model, but it is disqualified on this ticket's own criterion: `sanitize()` is typed `: string` and swallows every parse error, so the refuse-on-failure rule would have nothing to key on. Its record of three medium bypasses in five months on a single-maintainer project settles it.

**Failure signalling: distinguishable, but `=== false` alone is not the check.** There is exactly one `return false` in `Sanitizer::sanitize()`, taken when `loadXML()` fails, with `getXmlIssues()` then holding the libxml error list for the refusal message. That is cleanly separable from "sanitized to nothing", which returns a string. Two traps sit behind it. A well-formed non-SVG has its root removed by the tag allowlist and comes back as a bare XML declaration, not `false`, so "not an SVG at all" would otherwise become "an empty accepted file". And `getXmlIssues()` is non-empty on essentially every successful sanitization (editor metadata trips it routinely), so it is an audit-trail input, never a gate. The rule ticket 13 needs is three-way: refuse when `sanitize()` returns `false`, when it throws, or when the returned string's root element is not `svg`. Accept otherwise and log the issue list.

**What it strips.** Script elements: yes, by a roughly 70-entry tag allowlist with `script`, `foreignObject` and `handler` all absent, plus CDATA folding, PHP-tag stripping and comment removal. Event-handler attributes: yes, and by the right construction, since the attribute allowlist contains zero `on*` entries so handlers go by omission rather than by blocklist, with `href` and `xlink:href` separately gated through `isHrefSafeValue()`. External references: **no**, see the amendment below.

**Version compatibility: nothing to reconcile.** `php: "^7.1 || ^8.0"` with a CI matrix actually exercising 8.3, 8.4 and 8.5, no framework dependency of any kind, and only `ext-dom` and `ext-libxml` required. `^0.22` is the constraint to use, since Composer reads it as `>=0.22.0 <0.23.0` and so will not silently take a release that changes the allowlists, which is the right posture for a security dependency and matches both comparable packages. One dead-at-runtime `libxml_disable_entity_loader()` call guarded by `LIBXML_VERSION < 20900` may need a static-analysis baseline entry.

**`require`, not `suggest`.** Ticket 13's `ffmpeg` analogy does not hold on any of the four points that matter. Composer cannot install a system binary but can install a Composer package, so `ffmpeg`'s `suggest` describes a limitation rather than expressing a choice. The failure modes are not comparable: a missing `ffmpeg` costs a video thumbnail, a cosmetic degradation, while a missing sanitizer silently refuses every SVG upload, a functional regression against the plugin's arbitrary-file-types promise that an installer reads as a bug. The cost is roughly 200KB of dependency-free PHP against a large native toolchain. And every comparable package in the ecosystem requires it outright. Ticket 13's runtime probe survives anyway, as a `class_exists()` fail-closed guard, so an installer who prunes or replaces the package gets the refuse-the-upload behaviour rather than a fatal error or, worse, an unsanitized file on disk. The `require` makes the sanitizer present; the probe makes its absence safe.

### Amendment to ticket 13

Ticket 13's Active-content section says sanitization strips "script elements, event-handler attributes and external references". The third is false, and ticket 13 is amended to drop it.

`removeRemoteReferences` is off by default, so `new Sanitizer()` removes none at all. Switched on, its matcher is `~^url\(\s*['"]\s*(.*)\s*['"]\s*\)$~xi`, anchored at both ends and requiring quotes, so it catches `filter="url('https://...')"` but misses unquoted `url(https://...)` and misses `style="fill: url('https://...')"` entirely, both reported upstream (issues #94 from 2023 and #116 from 2025) and both unaddressed. Independently, `isHrefSafeValue()` explicitly allowlists `http://` and `https://` and `<image>` is an allowed tag, so `<image href="https://tracker/pixel.png">` survives regardless of the setting. `<style>` and `<a>` likewise survive, so arbitrary CSS reaches the served file.

None of this is stored XSS: the boundary ticket 13 exists to hold is carried by the tag and attribute allowlists and by `isHrefSafeValue()`, all of which do work as assumed. What is left is a privacy and tracking leak, since an admin opening the library grid makes a third-party request carrying a referrer and an IP. Ticket 13's stated behaviour is nonetheless not what the library delivers, and its reasoning in "Sanitized SVG is its own thumbnail" ("sanitization has already removed the external references that make browser-side rendering risky") rests on the same wrong premise. How the plugin closes the gap is a product decision with real cost, so it graduates as [Close the SVG External Reference Gap](15-svg-external-reference-gap.md) rather than being settled here.

## Comments

- Resolved on 2026-08-27 by an AFK research subagent. Findings and citations on branch `research/svg-sanitizer`.
