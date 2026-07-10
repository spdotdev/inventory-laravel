# Inventory — documentation

Canonical documentation home for the **Inventory** product. `inventory-laravel` owns
the schema and the API contract every client depends on, so product-wide specs and
planning live here.

| Path | Purpose |
| --- | --- |
| [`specs/data-model.md`](specs/data-model.md) | Canonical schema / ERD — owned and migrated here, consumed by clients via the API. |
| [`specs/api-contract.md`](specs/api-contract.md) | Canonical HTTP contract between `inventory-android` and `inventory-laravel`. |
| [`specs/mcp-tools.json`](specs/mcp-tools.json) | Machine-readable manifest of the admin MCP tool surface — CI in both MCP servers diffs against it. |
| [`planning/`](planning/) | Product-level planning: [`project-brief.md`](planning/project-brief.md) (decision log — source of truth for decisions) and [`product-description.md`](planning/product-description.md) (narrative). |
| [`deploy-runbook.md`](deploy-runbook.md) | Operations: how the package ships inside the sd-admin host app, env reference, smoke tests, rollback. |
| [`backend-plan.md`](backend-plan.md) | Backend-specific engineering plan (routing model, auth, tenancy internals). |
| [`archive/`](archive/) | Historical gap-analysis audits from the original build (cover both apps). |

App-specific planning for the Android client lives in
[`inventory-android/docs/`](https://github.com/spdotdev/inventory-android/tree/main/docs).
