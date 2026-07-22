# Group auto-sell listing by shared marketplace product, not per key

Keys that share the same marketplace product share a single offer there; processing them one at a time (create offer → update → upload → change status, repeated per key) hit the marketplace API with rapid repeated mutations against the same offer, which it rejected with a contention error ("wait for the current action to end"). Auto-sell now evaluates listing eligibility per key — each key enters if the market price covers its own floor — then picks a single "governing" key, the oldest among those approved, to set the shared offer's price, and uploads every approved key in one batch call ordered oldest-first, matching the marketplace's first-in-first-out sell order.

## Consequences

Keys approved for listing but not confirmed in the offer after the batch upload stay eligible for the next run rather than being marked listed — a partial-failure path that has to keep working this way for the grouping to be safe.
