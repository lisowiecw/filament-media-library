# Define Tenant Scoping for the Library

Status: open
Type: grilling
Blocked by:

## Question

Ticket 06 ruled that tenant scoping must be a global query scope rather than a per-field setting, and left `->scopeLibrary()` as the sanctioned per-picker escape hatch. It did not say how that scope resolves a tenant.

Decide: what the plugin expects from the host application to know the current tenant (a Filament panel tenant, a resolver callback, a trait on the asset model, or nothing at all with the host registering its own scope); whether a Media Asset carries a tenant column the plugin owns or the host adds one; how the Delivery route authorizes a cross-tenant request, given ticket 07 made View a policy question and a global scope is not an authorization boundary; what an *untenanted* asset is, if such a thing exists, since the importer (ticket 08) adopts legacy objects that predate any tenant; and whether the management page (ticket 10), which lists every asset unscoped, stays unscoped under tenancy or becomes the one surface that must not.
