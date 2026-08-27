# Define Package Namespace and Release Line

Status: open
Type: grilling
Blocked by:

## Question

Ticket 01 fixed the support matrix (Laravel 13 / PHP 8.3+ guaranteed, Filament 5 guaranteed, Filament 4 best effort behind shared public APIs) but deliberately deferred how that matrix is *packaged*.

Decide: the Composer package name, the PHP namespace and the published config/migration/view prefixes, all of which are effectively irreversible once anyone installs it; whether Filament 4 support ships in the same Composer line with a widened constraint or as a separately tagged and separately tested branch, given "best effort" in one line means a resolver can hand an installer a combination nobody has run; the versioning contract for a 0.x or 1.0 launch and what counts as a breaking change for a package whose public surface is a Filament field, a policy, a route and a config file; and which of those four surfaces is actually promised as stable versus documented as internal.
