# 7. Tenancy is a policy boundary, not a query scope

Date: 2026-08-27

## Status

Accepted. Narrows [1. Private media is always served through the plugin route](0001-private-media-always-served-through-plugin-route.md) by adding a second reason that route can refuse.

## Context

An earlier decision ruled that tenant scoping is a global query scope rather than a per-field setting, on the grounds that a per-field opt-in leaks the first time someone forgets to configure a field. That settled where scoping is *declared* and left open what it *is*.

A global query scope is not an authorization boundary. The Delivery route loads an asset by id, so route-model binding never runs the scope at all, and a scope that is bypassed by the one path to a private asset's bytes protects nothing. The library grid compounds this: listing is deliberately not gated per row, so if tenancy were only a grid filter it would be pure presentation while reading, to an operator, like a wall.

The alternatives were to treat tenancy as a convenience filter and say so plainly, or to make it a boundary and pay for the enforcement in the policy.

## Decision

A tenant is a confidentiality boundary. The global scope decides what is offered; the policy decides what is delivered, comparing the asset's tenant to the resolved tenant on View. A cross-tenant Delivery request returns 404 rather than 403.

An asset with no tenant belongs to no one rather than to everyone.

## Consequences

The enforcement lands in the same place, and along the same seam, as every other content rule: a reader looking for "who may reach these bytes" finds one answer, not two.

Cross-tenant refusal hides existence, which departs from the ordinary within-tenant denial. A within-tenant denial can afford to say "not yours"; a cross-tenant one cannot say "exists", or ids become a probe.

The management page loses its unscoped listing to a separate ability, and the Delivery route loses its single global registration in favour of one route per panel, since only a panel knows which resolver applies. Encoding the tenant in the signed URL would restore the single route and let the requester name their own tenant, which is why it was not done.

Every site that configures a resolver after running single-tenant inherits a library that belongs to no one and is therefore visible to no one. That is a deliberate, visible incomplete state rather than a silent one, and claiming is the supported way out of it. Had untenanted meant shared, the same site would publish its entire history to every tenant on the day it switched tenancy on.
