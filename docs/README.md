# Backend-specific planning

Backend-specific planning lives in [`backend-plan.md`](backend-plan.md).

This repo is also the canonical home for docs that used to live in the retired
`inventory-docs` repo, since `inventory-laravel` owns the schema and API both apps
depend on:

| Path                                              | Purpose                                                                       |
| -------------------------------------------------- | ------------------------------------------------------------------------------ |
| [`specs/data-model.md`](specs/data-model.md)       | Canonical schema / ERD — owned/migrated here, consumed by Android via the API. |
| [`specs/api-contract.md`](specs/api-contract.md)   | Canonical HTTP contract between `inventory-android` and `inventory-laravel`.   |
| [`planning/`](planning/)                           | Product-level planning (product description, project brief) spanning both apps. |
| [`deploy-runbook.md`](deploy-runbook.md)           | How to ship the package into the sd-admin host app.                           |
| [`archive/`](archive/)                             | Historical gap-analysis audits from the original build (covers both apps).    |

App-specific planning for the Android client lives in
[`inventory-android/docs/`](https://github.com/spdotdev/inventory-android/tree/main/docs).
