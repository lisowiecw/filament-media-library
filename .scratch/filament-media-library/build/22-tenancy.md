# 22: Tenancy

**What to build:** a multi-tenant panel gets a library scoped to the current tenant, with the boundary enforced where content is delivered rather than only where it is listed, and an existing untenanted library that can be claimed rather than published to everyone.

**Blocked by:** 08, 10, 17

**Status:** ready-for-agent

- [ ] `MediaLibraryPlugin::make()->tenantUsing(fn () => ...)` on the panel plugin instance, defaulting to the panel's own tenant where the panel has tenancy
- [ ] An unset resolver means the plugin is not tenanted at all, so single-tenant applications are untouched
- [ ] The nullable indexed `tenant_id` string column is stamped once at upload and never reassigned between tenants
- [ ] The query scope decides what is offered; the policy decides what is delivered, so route-model binding cannot sail past the boundary
- [ ] A null tenant belongs to no one, not to everyone, and is fail-closed
- [ ] A cross-tenant Delivery request returns 404, not 403, so ids cannot be probed
- [ ] The Delivery route is registered per panel inside that panel's middleware, so the resolver evaluates in the context the picker did
- [ ] The management page is scoped by default; the unscoped view sits behind a fail-closed `viewAllTenants` ability unlocking an "All tenants" toggle, a tenant column and a tenant facet
- [ ] Attaching refuses on a tenant mismatch; an existing mismatched attachment degrades to a dimmed glyph tile and still blocks deletion
- [ ] Claiming is one way and allowed once, via `media:assign-tenant` and a bulk action on the unscoped listing
- [ ] `media:import` gains a required `--tenant` option accepting the literal `none`
- [ ] Jobs and commands are neither scoped nor policy-checked
- [ ] The plugin never inspects the host model's tenancy
