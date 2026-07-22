# The listing floor (min_api) is computed once a day, never re-derived at listing time

Auto-sell does not evaluate a key's age itself when deciding whether to list it or at what price — it trusts the floor price a daily scheduled job already computed for every unsold key. This is deliberate: age-based floor overrides (old stock, long-listed limbo, near-expiry) are meant to be permanent once triggered — a key's floor must never recover just because it got listed — and computing that rule in two places risked the floor exceeding the ceiling once auto-sell locks an old key's ceiling at its listing price.

## Consequences

Any change to how the floor is computed only needs to happen in the daily job — auto-sell and any other consumer of the floor stay correct automatically. The trade-off is a day of lag: a key's floor can be up to 24h stale relative to its true age at the moment auto-sell runs.
