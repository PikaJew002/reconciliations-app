# Plaid plan

## Goal

Add a Plaid integration as another source of transaction imports. Users link a bank account (Plaid Item) and transactions are imported into the existing reconciliation app. A manual boolean on the users table switches on the ability to use the Plaid integration. Existing import sources include bank CSV/file imports and Tiller. (workflow_id: wf_2ec268c859488349)

## Scope

- Products: Transactions only, via `/transactions/sync`. Ongoing US transaction history is the import feed (SELECT-001, TXN-001).
- Link placement: `transactions` is the only entry in `products`. Nothing is placed in `required_if_supported_products`, `optional_products`, or `additional_consented_products` (SELECT-015, CONV-001, CONV-002, TXN-010, TXN-021).
- Rejected: Auth, Identity, Balance, Investments, Liabilities, and Assets. The product only imports transactions, does not verify users, and does not move money. Each extra required product would drop institutions (SELECT-007, PITFALL-001). Recurring Transactions and Transactions Refresh stay unused; Refresh is entitled but is a separate billed pull (GUIDE-016, TXN-010, TXN-016).
- Platform: Laravel (PHP) with Vue and Inertia. Link loads from `https://cdn.plaid.com/link/v2/stable/link-initialize.js` (WEB-010).
- Environment: sandbox until acceptance passes (GUIDE-004). There is no Development environment (GUIDE-005).

## Human tasks

| Task | Why it matters | Where | State |
|------|----------------|-------|-------|
| Sandbox keys in `.env` (`PLAID_CLIENT_ID`, `PLAID_SECRET`, `PLAID_ENV=sandbox`) | A production secret against Sandbox fails before Link can open | https://dashboard.plaid.com/developers/keys | verified |
| Register `https://reconciliations-app.test/plaid/oauth` before an OAuth sandbox bank | OAuth banks redirect the browser back to that exact URL. First Platypus Bank does not. | https://dashboard.plaid.com/developers/api | not_applicable |
| Set `PLAID_WEBHOOK_URL` when Plaid should push updates | Local Sync is the accepted stopgap until a public receiver exists | App `.env` | not_applicable |
| Company profile and security questionnaire before production OAuth banks such as Chase | Those gates are not part of this Sandbox build | https://dashboard.plaid.com/settings/company | not_applicable |

Not applicable: Dashboard webhook receivers (Item webhooks are set on the link token, WEBHOOK-002, TASK-003). Android package names (web only). Layer templates.

Verified from Dashboard state on client `6ad163e00eb2d3000d9c0c39`: Transactions is entitled in Sandbox and in US production. `authorized_environments` includes `sandbox` and `production`. Link customization `default` exists. Redirect URIs are empty. The webhooks section is empty.

## Implementation checklist

- [x] `users.plaid_enabled` boolean, default false, not mass-assignable
- [x] `plaid_items` and `plaid_accounts`; access token encrypted at rest (DATA-001, GUIDE-002)
- [x] History prompt before the link token. Default is no history (`days_requested` 1, drop rows before the link date). Optional start date up to 730 days. Production requests under 30 are raised to 30 (TXN-009)
- [x] `/link/token/create` server-side, Link on click, `/item/public_token/exchange` server-side (GUIDE-010, GUIDE-003, WEB-001, WEB-002)
- [x] Duplicate institution check before exchange (ITEM-011)
- [x] Map Plaid accounts onto existing Spendable accounts. Suggested when exactly one `last_four` matches. Never create accounts
- [x] `/transactions/sync` with `count` 500, cursor persisted only after `has_more` is false, mutation restarts from the starting cursor (TXN-001, TXN-002, TXN-007, TXN-011, DATA-007)
- [x] Sign flip, original description, pending-to-posted stitch, removals of unmatched rows (TXN-004, TXN-005, TXN-013)
- [x] Webhook receiver verifies ES256 JWT and queues a sync on `SYNC_UPDATES_AVAILABLE` (WEBHOOK-003, WEBHOOK-004, WEBHOOK-005, TXN-003)
- [x] Update mode for login repair uses an empty `products` array and does not exchange again (ITEM-003, OAUTH-005)
- [x] Disconnect calls `/item/remove` (ITEM-005)

## Acceptance

- Round 1: backend Sandbox path passed for `user_good` (6 rows imported, historical update complete) and `user_transactions_dynamic` (initial update complete, no rows in that pass). Link in the browser was not clicked; `https://reconciliations-app.test/accounts` redirects to login. OAuth redirect registration and a public webhook URL are still human tasks.

## Maintenance

This integration is maintained with the Plaid MCP; this file is its durable state. Agents working in this repository: consult build_guidance before modifying any Plaid-touching code, and re-run build_check_acceptance before reporting such a change as working. The acceptance above applies only to the code as it was verified — later changes invalidate it.

## How to test

### Sandbox link on the Herd site

1. Set `PLAID_CLIENT_ID`, `PLAID_SECRET`, and `PLAID_ENV=sandbox` in `.env` from the Dashboard keys page. Do not paste them into chat.
2. Set `users.plaid_enabled` to true for your user in the database.
3. Open `https://reconciliations-app.test/accounts`.
4. Choose **Add a bank account**. Leave **Import existing transactions?** set to No, then continue.
5. In Link, open First Platypus Bank (`ins_109508`) and sign in with `user_good` / `pass_good`.
6. Map a Plaid account onto an existing Spendable account and save.
7. New rows appear on that account. Amounts that leave the bank are negative.

### OAuth sandbox bank

1. Register `https://reconciliations-app.test/plaid/oauth` in the Dashboard and set `PLAID_REDIRECT_URI` to that exact URL.
2. Link Platypus OAuth Bank (`ins_127287`) with `user_good` / `pass_good`.

### Webhook delivery

1. Point `PLAID_WEBHOOK_URL` at a public HTTPS URL that reaches `POST /plaid/webhook`.
2. Call `/sandbox/item/fire_webhook` for `SYNC_UPDATES_AVAILABLE`, or use Sync on the Accounts page until then.

## Decisions made

- Transactions only, no money movement. Confirmed by the developer.
- Ongoing transaction history only. Confirmed by the developer.
- US accounts only. Confirmed by the developer.
- No user verification. Confirmed by the developer.
- Do not create Spendable accounts. Suggest a last-four match and let the user pick. Confirmed by the developer.
- History is chosen per Item on the way into Link. Default is no history. Confirmed by the developer.
- Link is tested on `https://reconciliations-app.test` against Sandbox. Confirmed by the developer.
- Routes live under `/plaid`. Proposed in the plan and implemented as specified (GUIDE-020).
- `personal_finance_category_version` is pinned to `v2` on sync. Categories are stored on transaction metadata and do not change Spendable classification (TXN-006).
- `include_original_description` is true (TXN-013).
- The cursor value `now` is not used (TXN-002).
- Local updates use the Sync button. There is no scheduled poll (WEBHOOK-005, PITFALL-005).

## Open questions

- None for the first build. Raising history later still requires disconnecting that Item and linking again (TXN-009).
