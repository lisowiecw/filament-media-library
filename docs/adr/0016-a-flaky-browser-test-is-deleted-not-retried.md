# 16. A flaky browser test is deleted, not retried

Date: 2026-08-31

## Status

Accepted

## Context

The browser suite drives a real Chromium against the workbench: a real panel,
real Livewire round trips, real uploads and a real queue. Everything that makes
it worth having also makes it capable of failing for reasons that have nothing
to do with the package. A modal is still animating, a notification has already
faded, a derivative is still being written.

The usual answer is a retry: run the test again, and count it green if the
second run agrees. That answer is what turns a suite into furniture. A retried
test no longer says the panel works, it says the panel worked at least once out
of two, and nobody can tell which of the tests still standing are load bearing.
The signal a browser suite exists to give is exactly the signal a retry removes.

There is also a cheaper reading available in almost every case. A test that only
passes sometimes is usually a test waiting on the wrong thing: on a delay rather
than on the text that appears at the end of the work, or on a selector that
matches two elements and picks whichever painted first. Those are fixable, and
fixing them makes the test say something sharper than it did before.

## Decision

No retries anywhere: not in the runner, not in CI, not per test. A browser test
that fails intermittently is first made deterministic, by waiting for the state
that actually settles rather than for time to pass. If it cannot be made
deterministic, it is deleted, and the behaviour it covered is either covered by
a Feature test or left uncovered and said so in the ticket that deletes it.

The suite is its own testsuite for the same reason. `composer test:unit` names
Arch, Feature and Unit, and `composer test:browser` is the only way to run this
one, so an environment without a browser is not an environment with a broken
build.

## Consequences

The suite stays small and every test in it means something. Coverage is lost
when a test is deleted, and that is the price: an honest gap is worth more than
a green tick that is true half the time.

CI runs the suite once, on one Filament major, and uploads the screenshot and
the run log when it fails, because a failure has to be diagnosable on the first
occurrence. There is no second occurrence to compare it against.
