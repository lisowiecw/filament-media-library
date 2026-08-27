# SVG Sanitizer Dependency

Research for ticket 14, `Choose the SVG Sanitizer Dependency`. Resolves the library choice, the failure-signalling contract ticket 13 needs, what the library actually strips, version compatibility against ticket 01's Laravel 13 / PHP 8.3+ floor, and whether it ships as `require` or `suggest`.

All source reading below is against `darylldoyle/svg-sanitizer` at `master` as of 2026-08-27 (latest tag 0.22.0, released 2025-08-12).

## Summary of what changed for ticket 13

Two of ticket 13's assumptions do not survive contact with the source. Both are recorded in full below.

1. **External references are not removed.** Ticket 13 listed "external references" alongside script elements and event handlers as things the sanitizer strips. `enshrined/svg-sanitize` does not strip them by default, and even with the opt-in flag turned on it strips only a narrow subset. `https://` in an `href` is explicitly allowlisted and always survives.
2. **"Fails sanitization" is narrower than ticket 13 implies.** The library returns `false` for exactly one condition, an XML parse failure. Everything else, including an input that is well formed XML but not an SVG at all, comes back as a string. Ticket 13's refuse-on-failure rule needs a second check beyond `=== false`.

## 1. Which library

**Recommendation: `enshrined/svg-sanitize`, constraint `^0.22`.**

Source: https://github.com/darylldoyle/svg-sanitizer (note the repository is `svg-sanitizer`, the Composer package is `svg-sanitize`), https://packagist.org/packages/enshrined/svg-sanitize

The obvious candidate does hold up, with reservations worth writing down.

In its favour: it is the de facto standard for this job in PHP, at roughly 49 million total installs and 108 direct Packagist dependents, among them TYPO3 core, Craft CMS, Contao, Concrete, October/Winter, Bagisto, Flarum's `fof/upload`, `plank/laravel-mediable`, and `awcodes/filament-curator`. The last two matter most here: both are MIT-licensed Laravel packages in the same problem space as this plugin, and both hard-require it (see section 5). It has a real security-advisory history that is public and patched (GHSA-fqx8-v33p-4qcc in 2022, GHSA-xrqq-wqh4-5hg2 in 2023, GHSA-22wq-q86m-83fh in 2025), which is a maturity signal rather than a red flag: a sanitizer with no reported bypasses is usually one nobody attacks.

Against it: maintenance is slow. The last commit to `master` is 2025-08-12, roughly twelve months stale. There are 26 open issues, including small correctness PRs sitting unmerged (#123, #124 opened July 2026), a long-standing request to tag a 1.0 (#120, August 2025), and two separate reports of the remote-reference gap described in section 3 (#94 from 2023, #116 from 2025), neither addressed. The maintainer is responsive to security reports (the 2025-08-11 mixed-case-attribute fix landed and shipped the next day) but not much else. Realistically this is a "stable and watched for security, not actively developed" dependency.

**License caveat, and it is the one genuinely awkward fact here.** `enshrined/svg-sanitize` is **GPL-2.0-or-later**, not MIT. Source: https://github.com/darylldoyle/svg-sanitizer/blob/master/composer.json. Its origin is the WordPress ecosystem (`darylldoyle/safe-svg`), where GPL is the house license. Composer does not merge licenses, and the plugin's own MIT source stays MIT, but a hard `require` means anyone installing the plugin pulls GPL code into their vendor tree. In practice the Laravel and Filament ecosystem has already made this call: `plank/laravel-mediable` and `awcodes/filament-curator` are both MIT and both require it outright, as do the MIT-licensed Craft CMS and the LGPL Contao. This is worth a line in the plugin's README rather than a blocker, but the decision should be made knowingly rather than by accident. It is also a mild argument for `suggest`, addressed in section 5.

### Alternative considered: `rhukster/dom-sanitizer`

Source: https://github.com/rhukster/dom-sanitizer

This is the only serious alternative found. It is MIT-licensed (which resolves the license caveat outright), actively maintained (commits within the last three weeks, versus twelve months for enshrined), and its threat model is noticeably more current: it strips external URLs from every attribute by default, parses CSS in a string-aware and paren-aware way, and handles `image-set()`, `@import`, and CSS hex-escape bypasses. It is used by Grav CMS and has roughly 3.2 million installs.

It is **not** recommended, for two reasons.

First, it cannot signal failure at all. `DOMSanitizer::sanitize()` is typed `: string` and its `loadDocument()` calls `@$document->loadXML($content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)` with the error suppressor and both error-suppressing libxml flags, then returns the resulting `DOMDocument` unconditionally. A malformed SVG produces no exception, no `false`, and no error list. Ticket 13's refuse-on-failure rule would have nothing to key on. That is decisive.

Second, its recent history is three medium-severity sanitizer bypasses in five months (GHSA-93vf-569f-22cq April 2026, GHSA-jfrr-ch68-f2w9 August 2026, GHSA-ww22-4mqv-x5w3 August 2026), plus a referenced CVE-2026-33172 bypass. The fixes are prompt and the code comments are unusually candid about what was wrong, which speaks well of the maintainer. But it is a 12-star, single-maintainer project, and its hardening is visibly still in progress. For a stored-XSS boundary against a logged-in panel session, the boring high-traffic option is the better bet.

Everything else on Packagist is either abandoned (`t3g/svg-sanitize-elts7`, `t3g/svg-sanitizer`), a wrapper around `enshrined/svg-sanitize` (`darylldoyle/safe-svg`, several Laravel one-offs with single-digit install counts), or not a sanitizer at all (`mathiasreker/php-svg-optimizer` is an optimizer that mentions sanitizing).

## 2. Failure signalling

**Ticket 13's refuse-on-failure rule has something to key on, but `=== false` alone is not enough.**

Source: https://github.com/darylldoyle/svg-sanitizer/blob/master/src/Sanitizer.php, `Sanitizer::sanitize()`

The method is documented `@return string|false` and there is exactly one `return false` in it:

```php
$loaded = $this->xmlDocument->loadXML($dirty, $this->getAllowHugeFiles() ? LIBXML_PARSEHUGE : 0);

// If we couldn't parse the XML then we go no further. Reset and return false
if (!$loaded) {
    $this->xmlIssues = self::getXmlErrors();
    $this->resetAfter();
    return false;
}
```

So `false` means, and only means, libxml could not parse the input as XML. That is a clean, unambiguous signal and it is genuinely distinguishable from "sanitized to nothing":

- **Parse failure** returns `false`, and `getXmlIssues()` then holds the libxml error list (message and line per entry), which is usable for the refusal message shown to the uploader.
- **Everything removed** returns a string. Removals are recorded as entries in `getXmlIssues()` with messages like `Suspicious tag 'script'`, `Suspicious attribute 'onload'`, and `Suspicious node '...'`, alongside the surviving markup.
- **Empty input** returns `''` early, before any parsing, and never `false`.

Three consequences the plugin has to handle, none of which ticket 13 anticipates:

1. **A well formed non-SVG returns a string, not `false`.** `startClean()` removes any element whose lowercased tag name is not in the allowlist, root included. Feed it `<foo/>` and you get back a document consisting of the XML declaration and nothing else. The plugin must therefore also assert the output still has an `svg` root element before accepting the upload, otherwise "not an SVG" silently becomes "an empty accepted file".
2. **`getXmlIssues()` being non-empty is not grounds for refusal.** It is populated on every ordinary sanitization that removed anything, which is the normal, successful path. Refusing on non-empty issues would refuse most real-world SVGs (editor metadata and namespaced attributes routinely trip it). It is useful for logging and for the audit trail ticket 11 established, not as a gate.
3. **No exception escapes.** `NestingException` exists but `Resolver::determineInvalidSubjects()` catches it internally (line 141), so `sanitize()` does not throw on the `<use>`-nesting DoS path. The plugin still wants a `try`/`catch` around the call as belt and braces (libxml and DOM can raise on pathological input), and should treat a thrown exception the same as `false`.

Practical rule for ticket 13: refuse if `sanitize()` returns `false`, throws, or returns a string whose parsed root element is not `svg`. Accept otherwise, and log `getXmlIssues()`.

There is an open feature request (#95, September 2023) for exactly the "is this SVG safe, yes or no" API the plugin wants. It is unanswered. Building the three-way check above locally is the answer.

## 3. What it actually strips

**Script elements: yes. Event-handler attributes: yes. External references: no, and this is where ticket 13 is wrong.**

### Script elements: confirmed

Sources: `src/data/AllowedTags.php`, `Sanitizer::startClean()`

The tag policy is a strict allowlist of about 70 entries. `script`, `foreignObject`, and `handler` are all absent, so any of them is removed outright by `startClean()`, which deletes any element whose lowercased tag name is not in the list. `<font>` elements carrying `face`, `color`, or `size` are additionally removed as foreign-content breakouts, and `<use>` elements are removed when dirty or over the nesting threshold. CDATA sections are converted to encoded text nodes, and any node that is neither `DOMElement` nor `DOMText` (processing instructions, comments) is removed by `cleanUnsafeNodes()`. PHP tags are stripped by regex, recursively, before parsing even starts.

Note that `a`, `style`, `animate`, `animateColor`, `animateMotion`, `animateTransform`, and `set` **are** allowed. The `<style>` element in particular means arbitrary CSS survives into the served file.

### Event-handler attributes: confirmed

Source: `src/data/AllowedAttributes.php`, `Sanitizer::cleanAttributesOnWhitelist()`

The attribute policy is likewise a strict allowlist, and it contains **zero** `on*` entries (a grep for `'on` across the file returns nothing). Anything not on the list, not `aria-*`, and not `data-*` is removed and logged as `Suspicious attribute '<name>'`. So `onload`, `onclick`, `onerror`, and every other handler go, by omission rather than by blocklist, which is the right construction.

`href` and `xlink:href` values are separately gated through `isHrefSafeValue()`, which permits only: empty, `#fragment`, `/relative`, `http://`, `https://`, and `data:image/{png,gif,jpg,jpe,pjp}` (plus the `data:img/...` short forms). `javascript:` and every other scheme is removed. Since the 2025-08-11 fix (GHSA-22wq-q86m-83fh), mixed-case attribute names such as `XLinK:href` and `HrEf` are normalized or removed rather than slipping past the lowercase comparison.

### External references: NOT removed by default, and only narrowly removed when enabled

Sources: `Sanitizer::removeRemoteReferences()`, `Sanitizer::hasRemoteReference()`, `Sanitizer::isHrefSafeValue()`, issues https://github.com/darylldoyle/svg-sanitizer/issues/94 and https://github.com/darylldoyle/svg-sanitizer/issues/116

**This is the assumption ticket 13 gets wrong.** Three separate problems, in increasing order of how much they matter:

**(a) It is off by default.** The property is declared `protected $removeRemoteReferences = false;` and the setter's own default is `false`. A plugin that calls `new Sanitizer()` and then `sanitize()` removes no remote references at all. The plugin must call `$sanitizer->removeRemoteReferences(true)` explicitly. The README notes this "will stop HTTP leaks but will add an overhead to the sanitizer".

**(b) Even switched on, the matcher is very narrow.** `hasRemoteReference()` is:

```php
$wrapped_in_url = preg_match('~^url\(\s*[\'"]\s*(.*)\s*[\'"]\s*\)$~xi', $value, $match);
if (!$wrapped_in_url){
    return false;
}
```

It is anchored at both ends and requires quotes inside `url(...)`. So it catches an attribute whose entire value is `url("https://evil/x")`, for example `filter="url('http://...')"`. It does not catch `url(https://evil/x)` unquoted, and it does not catch `style="fill: url('https://evil/x')"` because the value does not *start* with `url(`. Issue #116 is precisely this case, filed May 2025, reproduced against `removeRemoteReferences(true)`, still open. Issue #94 (2023) is the same gap for remote images. Neither has been addressed.

**(c) `https://` in an `href` is allowlisted and always survives.** `isHrefSafeValue()` returns `true` for anything starting `http://` or `https://`, and `<image>` is an allowed tag. So `<image href="https://tracker.example/pixel.png"/>` passes sanitization cleanly regardless of the `removeRemoteReferences` setting. Issue #94's own discussion concludes that the only real fix is to drop `image` from the allowed tags.

Nothing here is stored XSS. External references are a privacy and tracking leak (the viewer's browser makes a request to a third party, carrying a referrer and an IP, whenever an admin opens the library grid), plus a mild availability and mixed-content concern. The stored-XSS boundary that motivates ticket 13 is held by the tag and attribute allowlists and by `isHrefSafeValue()`, all of which do work as ticket 13 assumed. But ticket 13's stated behaviour, "strip external references", is not what the chosen library delivers, and the ticket should either be amended to say so or the plugin should add its own pass.

If the plugin wants ticket 13's promise honoured, the options are, roughly in order of cost: (i) call `removeRemoteReferences(true)` and accept the residual gaps, documenting them; (ii) additionally narrow the allowed tag list with `setAllowedTags()` to drop `image`, and the attribute list to drop `style`, which closes both remaining vectors at the cost of rejecting legitimate SVGs; or (iii) serve SVGs under a restrictive `Content-Security-Policy` header on the Delivery route, which is a stronger and cheaper control than any amount of markup filtering. Option (iii) is worth raising as its own follow-up: since ticket 13 already routes SVGs through the plugin-owned Delivery route, that route is the natural place to add `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`, which neutralizes both remote fetches and any script that got past the sanitizer. Defence in depth, and it does not depend on the sanitizer being perfect.

## 4. Version compatibility

**No conflict with ticket 01's Laravel 13 / PHP 8.3+ floor. There is nothing to reconcile.**

Source: https://github.com/darylldoyle/svg-sanitizer/blob/master/composer.json

```json
"require": {
    "ext-dom": "*",
    "ext-libxml": "*",
    "php": "^7.1 || ^8.0"
}
```

- **PHP.** `^7.1 || ^8.0` covers 8.3, 8.4, and 8.5. The CI matrix (`.github/workflows/tests.yml`) runs `['7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5']`, so 8.3 and above are actually exercised, not merely permitted by the constraint. PHP 8.5 was added to the matrix on 2025-08-12.
- **Laravel.** The package has no framework dependency of any kind. Laravel 13 is simply not a consideration.
- **Extensions.** It requires `ext-dom` and `ext-libxml`. Both are enabled in essentially every PHP distribution and Laravel itself already depends on `ext-dom` transitively, but if the plugin ships this as a `suggest` rather than a `require`, the runtime probe should check for the extensions too, not just the class.
- **Deprecation noise.** `libxml_disable_entity_loader()` is deprecated as of PHP 8.0 and the library still calls it, but only inside `if (\LIBXML_VERSION < 20900)`, and every PHP 8.x build ships libxml 2.9 or newer, so the branch is dead at runtime on the plugin's supported floor. It does trip static analysers; open PR #124 (July 2026) adds a `function_exists()` guard and has not been merged. If the plugin runs PHPStan or Larastan over its vendor tree this may need a baseline entry, otherwise it is invisible.

**Constraint to use: `^0.22`.** Note the 0.x caret semantics: Composer reads `^0.22` as `>=0.22.0 <0.23.0`, so this pins to the 0.22 line and will not silently take a 0.23 that changes the allowlists. That is the right posture for a security dependency, and it matches what `awcodes/filament-curator` and `plank/laravel-mediable` both use. If a 1.0 is ever tagged (issue #120), widen to `^0.22 || ^1.0` after reviewing the diff, not before.

## 5. `require` versus `suggest`

**Recommendation: hard `require`.** Ticket 13's analogy to ticket 12's `ffmpeg` does not hold, and following it would leave the plugin shipping a security-relevant code path that is off by default.

Ticket 13's reasoning was: `ffmpeg` is optional and probed, SVG sanitization is optional and probed, so treat them alike. The two cases differ in every respect that matters.

**The `ffmpeg` analogy breaks on four points.**

1. **Composer can install a Composer package; it cannot install a system binary.** `ffmpeg` has to be `suggest` because there is no other option: Composer has no mechanism to put a binary on the host. That is a limitation being described, not a design choice being made. `enshrined/svg-sanitize` is an ordinary Composer package, so the constraint that forces `ffmpeg` into `suggest` simply does not apply.
2. **The failure modes are not comparable.** Missing `ffmpeg` means no video thumbnails, a cosmetic degradation with an obvious visible symptom. Missing sanitizer means, per ticket 13, SVG uploads are silently refused: a functional regression in the plugin's advertised "arbitrary file types" promise, and one the installing developer will experience as a bug rather than as a missing optional feature. Worse, a `suggest` invites the failure mode where someone reads "optional" as "not important", and the plugin's SVG story quietly does not work in production.
3. **The cost is negligible.** The package is roughly 200KB of pure PHP with no dependencies beyond two ubiquitous extensions. `ffmpeg` is a large native toolchain with a real installation burden and its own licensing complexity. There is no meaningful weight argument for making this optional.
4. **The ecosystem has already answered.** `awcodes/filament-curator` (MIT, Filament `^4.0|^5.0`, the closest possible comparable, a Filament media picker plugin) has `"enshrined/svg-sanitize": "^0.22"` in `require`. `plank/laravel-mediable` (MIT) likewise. Craft CMS, TYPO3 core, Contao, and October/Winter all require it outright. Nobody in this space treats it as optional. A `suggest` would make the plugin the odd one out and would surprise anyone comparing it against Curator.

**The one real argument for `suggest`** is the GPL-2.0-or-later license from section 1. A `suggest` keeps GPL code out of the vendor tree of installers who never upload SVGs, and leaves the licensing choice to them. This is a coherent position and it is the only reason worth weighing. Two things count against it: the ecosystem precedent above means MIT Laravel packages requiring this GPL package is already normal and uncontroversial, and the safety cost of a default-off security control outweighs a licensing nicety that affects almost nobody in practice. Note it in the README under a "Third-party licenses" heading and move on.

**Recommended shape:**

- `"enshrined/svg-sanitize": "^0.22"` in `require`.
- Keep ticket 13's runtime probe anyway, as a cheap `class_exists(\enshrined\svgSanitize\Sanitizer::class)` guard, so that an installer who removes the package through a Composer `replace`, a vendor prune, or a conflicting constraint gets ticket 13's refuse-the-upload behaviour rather than a fatal error or, far worse, an unsanitized file on disk. The probe costs nothing and preserves the fail-closed property that is the actual point of ticket 13's rule. The `require` makes the sanitizer present by default; the probe makes its absence safe rather than catastrophic.
- Note the GPL-2.0-or-later license in the plugin README.

## Open questions for ticket 13

1. Ticket 13 says the sanitizer strips external references. It does not (section 3). Either amend ticket 13's wording to match what the library does, or add the CSP header on the Delivery route so the promise is kept by a different mechanism. The CSP route is recommended and probably deserves its own ticket.
2. Ticket 13's refuse-on-failure rule needs the three-way check from section 2 (`false`, thrown, or non-`svg` root), not just `=== false`. Worth writing into the ticket explicitly, because the non-SVG-returns-a-string case is genuinely surprising.
3. `<style>` and `<image>` survive sanitization. If the plugin wants the tighter posture, it needs `setAllowedTags()` and `setAllowedAttrs()` overrides, which is a product decision (rejecting legitimate SVGs) rather than a library one.

## Sources

- https://github.com/darylldoyle/svg-sanitizer (repository, `master`, last commit 2025-08-12)
- https://github.com/darylldoyle/svg-sanitizer/blob/master/composer.json (license, PHP constraint, extensions)
- https://github.com/darylldoyle/svg-sanitizer/blob/master/src/Sanitizer.php (`sanitize()`, `startClean()`, `cleanAttributesOnWhitelist()`, `isHrefSafeValue()`, `hasRemoteReference()`, `cleanUnsafeNodes()`, `getXmlIssues()`)
- https://github.com/darylldoyle/svg-sanitizer/blob/master/src/data/AllowedTags.php
- https://github.com/darylldoyle/svg-sanitizer/blob/master/src/data/AllowedAttributes.php
- https://github.com/darylldoyle/svg-sanitizer/blob/master/src/ElementReference/Resolver.php (`NestingException` handling)
- https://github.com/darylldoyle/svg-sanitizer/blob/master/.github/workflows/tests.yml (PHP test matrix)
- https://github.com/darylldoyle/svg-sanitizer/blob/master/README.md
- https://github.com/darylldoyle/svg-sanitizer/issues/94, /issues/95, /issues/116, /issues/120, /issues/124
- https://github.com/darylldoyle/svg-sanitizer/security/advisories (GHSA-22wq-q86m-83fh, GHSA-xrqq-wqh4-5hg2, GHSA-fqx8-v33p-4qcc)
- https://packagist.org/packages/enshrined/svg-sanitize (installs, dependents, release dates)
- https://github.com/awcodes/filament-curator/blob/main/composer.json (`require` precedent, Filament v4/v5)
- https://github.com/plank/laravel-mediable/blob/master/composer.json (`require` precedent)
- https://github.com/rhukster/dom-sanitizer (alternative: MIT license, `src/DOMSanitizer.php`, advisories GHSA-ww22-4mqv-x5w3, GHSA-jfrr-ch68-f2w9, GHSA-93vf-569f-22cq)
