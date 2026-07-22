# Pruned Clean Architecture — no repository/adapter abstractions, no premature multi-marketplace split

This is an internal system with two users that will never need to swap frameworks, so we deliberately skip the usual Clean Architecture ceremony — no abstract repository interfaces, no adapters — even though `Domain/Pricing` is implicitly coupled to Gamivo (Gamivo-specific fee tiers, Gamivo-shaped comparison constants). Domain stays pure PHP and testable without a framework; UseCases still orchestrate through Services and Domain — we just don't add indirection with no second implementation to justify it.

## Consequences

When a second marketplace becomes real, `Domain/Pricing` will need a real look — today nothing there is marketplace-agnostic. Marketplace-specific orchestration already lives under its own namespace per marketplace, so a new marketplace's UseCases can slot in beside the existing ones without touching the marketplace-agnostic Key UseCases.
