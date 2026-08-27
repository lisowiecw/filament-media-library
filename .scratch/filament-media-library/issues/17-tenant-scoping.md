# Define Tenant Scoping for the Library

Status: resolved
Type: grilling
Blocked by:

## Question

Ticket 06 ruled that tenant scoping must be a global query scope rather than a per-field setting, and left `->scopeLibrary()` as the sanctioned per-picker escape hatch. It did not say how that scope resolves a tenant.

Decide: what the plugin expects from the host application to know the current tenant (a Filament panel tenant, a resolver callback, a trait on the asset model, or nothing at all with the host registering its own scope); whether a Media Asset carries a tenant column the plugin owns or the host adds one; how the Delivery route authorizes a cross-tenant request, given ticket 07 made View a policy question and a global scope is not an authorization boundary; what an *untenanted* asset is, if such a thing exists, since the importer (ticket 08) adopts legacy objects that predate any tenant; and whether the management page (ticket 10), which lists every asset unscoped, stays unscoped under tenancy or becomes the one surface that must not.

## Answer

### The seam: a resolver, and nothing else

Tenancy enters the plugin through exactly one named seam: `MediaLibraryPlugin::make()->tenantUsing(fn () => ...)`, configured on the **panel plugin instance**, defaulting to `Filament::getTenant()` when the panel has tenancy configured. An unset resolver means the plugin is not tenanted at all and behaves precisely as tickets 01 to 16 describe, so single-tenant apps (including the blog-post example) are untouched.

The resolver returns a model (the plugin takes its key) or a scalar. It lives on the panel instance rather than in package config because Filament tenancy is per panel: an app may run a tenanted customer panel beside an untenanted admin panel, and those are two different answers to "who is the tenant". Reading `Filament::getTenant()` at the point of use was rejected because the Delivery route and the queued derivative jobs do not run inside a panel request and would silently receive `null`; a trait on the asset model was rejected because it hands the host a plugin concern.

### Tenancy is a policy boundary, not a query scope

A tenant is a **confidentiality boundary**: tenant A must never obtain tenant B's bytes. Ticket 07 already established that a query scope is not an authorization boundary, and that holds here, so the enforcement is split along the same [[View]] / [[Offer]] line already in `CONTEXT.md`:

- The **global query scope** decides what is *offered*: the picker grid, the management page listing. It is ergonomics.
- The **`MediaAssetPolicy`** decides what is *delivered*: it compares the asset's tenant to the resolved tenant on `view`, and the Delivery route fails closed on mismatch. This is the security.

The split matters because the Delivery route loads by id, and route-model binding sails straight past a global scope. Had tenancy been only a grid filter it would protect nothing, since ticket 07 deliberately leaves grid listing un-gated per row, while inviting an operator to assume otherwise.

### The column

The plugin owns a nullable, indexed `tenant_id` **string** column on `media_assets`, stamped once from the resolver at upload and never reassigned. A string even for integer keys, so a UUID tenant needs no migration change, and an exotic app can namespace its own value (`"team:17"`).

A polymorphic `tenant_type` + `tenant_id` pair was rejected: it buys a case that does not exist inside a single panel and costs every query a second column. Requiring the host to add the column was rejected because the resolver decision had just made the plugin the owner of this seam.

### Null is fail-closed, not a shared pool

An asset with a null `tenant_id` belongs to **no one**, not to everyone: invisible in every tenant's grid, refused by Delivery for any tenanted request, reachable only through the unscoped surface below.

The alternative (null as a shared pool) fails on the case that actually occurs: an app that ran single-tenant for two years and then configures a resolver has a library of assets that genuinely belonged to one customer, and a shared pool publishes all of them to every tenant the same day, silently. That is exactly the leak the boundary exists to prevent.

Ticket 08's importer therefore gains a **required `--tenant` option**, accepting the literal `none` for a deliberate untenanted import, so no new nulls appear once tenancy is on.

### Claiming is not moving

Null to tenant is a **one-way claim** of an unowned asset, allowed once. Tenant to a different tenant stays forbidden forever, which is the invariant that keeps a usage list honest about who could reach an asset. Ticket 03's immutability is restated accordingly: an asset never changes *owner*, and claiming an unowned one does not change an owner.

Claiming is available two ways: a `media:assign-tenant` command (disk, column and id filters, matching the importer's shell-tool shape) and a bulk action on the unscoped listing, gated by the ability below. Without this, the untenanted pile would be visible and permanently unfixable, which cannot be the answer.

### The management page becomes scoped

Ticket 10 built `MediaAssetResource` to list every asset unscoped. Under a confidentiality boundary that is a cross-tenant listing, so the page is **scoped by default** and the unscoped view moves behind a distinct, fail-closed `viewAllTenants` ability that also unlocks an "All tenants" toggle, a tenant column and a tenant facet. Per-asset abilities from ticket 10 are unchanged.

Ticket 10's promise survives intact for the person who actually holds cross-tenant authority; what changes is that the promise is now a claim about authority rather than about the page. `viewAny` alone was not enough, because ticket 10 wrote that page for the content editor, and in a tenanted app that editor sits inside a tenant.

This is also what makes an untenanted admin panel useful: it resolves null, so by the rule above it sees nothing tenanted, and `viewAllTenants` rather than the absence of a resolver is what gives it a library.

### Attachments: guard at attach, degrade at render

Attaching **refuses** when the asset's tenant differs from the resolved tenant. The grid will not offer such an asset anyway, but ticket 06's `->scopeLibrary()` never widens and a programmatic `attach()` bypasses the grid entirely, so the guard is real rather than belt-and-braces theatre.

An attachment that *already* exists and now mismatches renders as ticket 12's **dimmed glyph tile**, the same as a missing derivative, and still counts in the usage list so it continues to block deletion. This is not hypothetical: it is what every existing site sees on the day it configures a resolver, before claiming. Serving those bytes anyway would contradict the boundary; erroring the whole form would contradict ticket 12's rule that an unavailable image is a tile, not a failure.

The plugin **never inspects the host model's tenancy**. It cannot know how a host is tenanted, or whether it is. The comparison is always asset tenant against resolved tenant.

An [[External reference]] (a host-less Attachment, ADR-0002) carries no tenant of its own; the asset's tenant governs. A cross-tenant external reference is simply an attachment whose bytes the resolved tenant cannot reach, while it still appears in the unscoped usage list.

### Delivery refuses with 404

A cross-tenant Delivery request returns **404**, indistinguishable from a nonexistent asset, so tenant B cannot probe ids to learn what tenant A holds. This differs deliberately from ticket 07's ordinary `view` denial: a within-tenant denial can afford to say "not yours", a cross-tenant one cannot say "exists".

Derivatives (ticket 12) carry no tenant column; they are child rows and inherit the parent's, so the variant parameter changes nothing here.

The Delivery route is registered **per panel, inside that panel's middleware**, so the resolver evaluates in the same context the picker did. It loses ticket 07's single global registration. A single global route cannot know which panel's notion of tenant applies, and the honest fix would be to encode the tenant in the signed URL, which lets the requester name their own tenant and is therefore not a boundary at all.

### Where there is no request

Queued derivative jobs (ticket 12), the importer (ticket 08), `media:resolve-mimes` (ticket 11) and orphan reporting run outside any panel, where the resolver has no tenant to return. Fail-closed applied naively there would silently stop derivative generation for every tenanted asset.

The rule is one line: **the tenant scope narrows what is offered, the policy decides what is delivered, and neither applies where nothing is being offered to or delivered to a person.** Jobs and commands operate on an explicit asset by id and are never scoped and never policy-checked, exactly as they already are for `view`, since a queued job has no user either and nobody proposed giving it one. A null resolver inside a job is normal, not a denial.

Serialising a tenant onto every job was rejected: it makes the tenant a second thing every job must carry correctly forever, for no gain.
