# Financial Reconciliation Engine – Domain Model

## Overview

The system imports **bank transactions** and **merchant order history** separately, then turns them into categorized spending, budgets, and paycheck leftover.

The original principle still applies when a real order exists:

> **Bank transactions represent how money moved. Orders represent what was purchased. Transaction allocations connect the two.**

Most card/POS spend never gets a line-item order. Those transactions are classified and categorized on the bank transaction. Merchants with `supports_order_import = true` (Walmart, Amazon) wait for a real order import instead of being categorized as a lump sum.

Almost every record is scoped to a `User`.

---

# User-facing surface

| Area | Routes | Purpose |
| --- | --- | --- |
| Dashboard | `/` | Month or year-to-month income / bills / expenses vs budget, plus current paycheck leftover |
| Accounts | `/accounts` | Create accounts, import bank CSVs, browse posted lines |
| Categories | `/categories` | User-owned `bill`, `expense`, and `income` categories |
| Budgets | `/budgets` | Monthly limits on a 12-month `BudgetYear` |
| Plans | `/plans` | Recurring paychecks and bills; assign bills to a paycheck; match occurrences |
| Rules | `/rules` | Income and expense `TransactionCategorizationRule`s |
| Orders | `/orders` | Walmart / Amazon imports, other merchants, product categorization |
| Reconciliation | `/reconciliation/unmatched-transactions`, `/reconciliation/needs-review` | Classify, confirm suggestions, resolve payments, run the pipeline |
| Venmo | `/venmo/imports` | Activity CSV import |
| API tokens | `/api-tokens/pending-spend`, `/api-tokens/retailer-scraper` | Sanctum tokens for clients |
| Onboarding | checklist + tours | Add account → import bank → optionally import orders → categorize |

External clients:

- Chrome extension signs in at `/extension/auth` and posts Amazon scrapes to `POST /api/amazon/import` (`amazon:import` ability).
- Pending-spend clients call `GET /api/pending-spends/options` and `POST /api/pending-spends` (`pending-spend:create` ability).

---

# Account

A financial account the user imports into.

Types: `checking`, `savings`, `credit_card`, `cash`.

`default_classification` is `bill` or `expense` and is used as a hint when categorizing that account’s spend.

A system **Off-book** account (`external_id = system:off-book`) is created as needed for gift cards, cash, Walmart balance, and other tenders that never appear on a bank CSV. Off-book accounts are excluded from “tracked” account lists and onboarding.

```
Account
    belongsTo User
    hasMany BankTransactions
    hasMany PendingSpends
```

---

# ImportBatch

One import operation.

Current sources:

| `source` / `type` | Importer |
| --- | --- |
| `bank` / `transactions` | Institution-specific CSV importer from `InstitutionRegistry` |
| `walmart` / `orders` | `WalmartOrderImporter` |
| `amazon` / `orders` | `AmazonScrapeOrderImporter` |
| `venmo` / `activity` | `VenmoActivityImporter` |

Registered bank institutions:

- Cumberland Valley National Bank
- Cumberland Valley National Bank Credit Card
- Capital One

```
ImportBatch
    belongsTo User
    hasMany BankTransactions
    hasMany Orders
    hasMany VenmoActivities
```

After a successful import, `ProcessImportBatch` chains follow-up jobs (transfers, categorization, merchant matching, Venmo, planned occurrences, pending spends, order matching). A user can also enqueue the full `RunUserReconciliationPipeline` from the reconciliation page.

---

# Merchant

A canonical payee.

Types: `retailer`, `restaurant`, `service`, `utility`, `financial`, `government`, `other`.

`supports_order_import = true` means charges at this merchant wait for a real order. Walmart and Amazon imports create/update that merchant and seed default `MerchantMatchingRule`s (`wal-mart`, `amzn`, and similar). Other merchants are created from extracted card/POS names with `supports_order_import = false`.

Users can add matching rules, preview which unmatched lines they would hit, and merge duplicate merchants.

```
Merchant
    belongsTo User
    hasMany Orders
    hasMany BankTransactions
    hasMany Products
    hasMany MerchantMatchingRules
    hasMany PendingSpends
```

---

# MerchantMatchingRule

Maps a bank description to a merchant.

Match modes:

- `contains` — normalized description contains the pattern
- `extracted_name` — the institution’s merchant-name extractor produced this name

`MerchantMatcher` applies user rules first. If none hit, it extracts a name from the description (bank vs credit-card extractors), fuzzy-matches existing merchants, or creates one. Lines that look like transfers, Venmo, deposits, ATMs, or withdrawals are left untagged.

---

# BankTransaction

One posted line from a financial institution.

Classifications: `income`, `transfer`, `bill`, `expense`, `reimbursement`.

Classification sources: `heuristic`, `learned`, `paired`, `manual`.

Statuses: `unmatched`, `matched`, `partial`, `ignored`.

Unlike the original write-up, bank transactions **do** carry `category_id` once they are classified as income, bill, or expense. Order-import merchants are the exception: those debits stay unmatched until a real order is allocated.

```
BankTransaction
    belongsTo User
    belongsTo Account
    belongsTo Merchant (nullable)
    belongsTo Category (nullable)
    belongsTo ImportBatch
    hasMany TransactionAllocations
    hasOne PlannedOccurrence
    hasOne PendingSpend
    hasMany VenmoActivities
    hasOne debitTransferLink / creditTransferLink
    hasOne reimbursementGroupLeg
```

Example:

```
Checking Account

-249.71
WAL-MART SUPERCENTER
```

---

# Order

One logical purchase from a merchant, independent of how it was paid.

Real orders come from Walmart or Amazon imports. They can have multiple payment methods in `metadata` (card last-four, gift card, cash, Walmart balance). `OrderPaymentResolutionService` allocates those tenders: card legs need a matching bank transaction; off-book kinds post to the Off-book account.

Orders that are entirely off-book can be auto-resolved during reconciliation.

```
Order
    belongsTo User
    belongsTo Merchant
    belongsTo ImportBatch
    hasMany OrderItems
    hasMany OrderComponents
```

---

# OrderItem

Merchandise only. Never tax, delivery, tip, or discounts.

```
OrderItem
    belongsTo Order
    belongsTo Product (nullable)
    hasMany OrderComponents
```

---

# Product

A canonical item **at one merchant**. There is no `ProductAlias` table anymore.

`ProductMatchingService` links Walmart and Sam’s Club order lines by SKU, then by normalized description, creating a product when none exists. Categorizing a product writes `category_id` onto it and onto the related product components.

Amazon (and other retailers) are categorized at the order or item level without building that catalog.

```
Product
    belongsTo User
    belongsTo Merchant
    belongsTo Category (nullable)
    hasMany OrderItems
```

---

# Category

Formerly `ExpenseCategory`. Categories now have a `kind`:

- `bill`
- `expense`
- `income`

They can nest via `parent_id`. Users create them from the Categories pages or inline while categorizing (type a name to create one).

```
Category
    belongsTo User
    belongsTo Category (parent, nullable)
    hasMany Categories (children)
    hasMany Products
    hasMany OrderComponents
    hasMany BankTransactions
    hasMany TransactionCategorizationRules
    hasMany PlannedTemplates
    hasMany PendingSpends
```

---

# OrderComponent

Every dollar on an order.

`OrderComponentGenerator` creates:

- one `product` component per order item (category copied from the product when present)
- order-level `tax`, `delivery`, `tip`, and `discount` components when those amounts are non-zero

Tax is **not** split across items.

```
OrderComponent
    belongsTo Order
    belongsTo OrderItem (nullable)
    belongsTo Category (nullable)
    hasMany TransactionAllocations
```

---

# TransactionAllocation

Connects bank transactions to order components.

Supports:

- one bank transaction → many components
- many bank transactions → one component
- many-to-many, including partial `allocated_amount`

Allocation types: `automatic`, `manual`, `imported`.

```
TransactionAllocation
    belongsTo BankTransaction
    belongsTo OrderComponent
```

`ReconciliationService` matches open orders to unmatched same-merchant debits:

1. Unique 1:1 amount match inside a date window (default 7 days), with payment-instrument alignment (card last-four).
2. Unique exact subset of multiple transactions that sum to the order total (capped candidate set; skipped near import-date edges).

---

# TransactionCategorizationRule

Learned rules that classify later transactions.

Persistable match modes include exact description + amount, description, merchant, amount + merchant, and bill-only modes (`check_and_amount`, `description_prefix_and_amount`). `once` is a one-off categorization without saving a reusable rule.

Income rules use the description modes. Expense/bill rules may also key off merchant.

`TransactionCategorizationService` applies active rules to unmatched lines that are **not** at an order-import merchant. Ambiguous multi-rule hits go to needs-review.

---

# TransactionTransferLink

Pairs a debit with a credit so the movement is not treated as spend or income.

Two pairing passes:

- `CreditCardPaymentPairingService` — card payment on a checking account ↔ payment credit on the card
- `TransferPairingService` — other same-amount debit/credit pairs

Statuses: `suggested`, `confirmed`, `rejected`. Confirmed/suggested pairs are excluded from expense matching.

---

# VenmoActivity

A row from a Venmo activity CSV.

Bank-facing rows (direct debit or cashout to a last-four) are matched to bank transactions. Wallet-only activity can stay unmatched. Confirmed matches can attach a human-readable summary (counterparty + note) onto the bank line.

Pending spends with `source = venmo` match against these rows.

```
VenmoActivity
    belongsTo User
    belongsTo ImportBatch
    belongsTo BankTransaction (nullable)
    hasOne PendingSpend
    belongsTo / hasMany cashed-out payment rows
```

---

# PendingSpend

A spend logged before the bank (or Venmo) line posts.

Sources: `debit_card`, `credit_card`, `venmo`.

Statuses: `pending`, `needs_review`, `resolved`, `cancelled`.

The matcher looks in a window of 1 day back / 7 days forward. A unique hit resolves and copies merchant/category/classification onto the bank transaction. Ambiguous or missing hits stay in review.

Unmatched pending spend **counts on the dashboard** by `spent_at`. Resolved and cancelled rows do not; the posted bank line takes over.

Order-import merchants are not eligible — those charges still wait for a real order.

---

# ReimbursementGroup

Groups expense legs with reimbursement legs.

While a transaction is in a group it is excluded from raw category spend. Closing an under-reimbursed group posts the positive net to `remainder_category_id`. An over-reimbursed close contributes to uncategorized income. Open positive nets are “awaiting reimbursement,” not category spend.

```
ReimbursementGroup
    belongsTo User
    belongsTo Category (remainder, nullable)
    hasMany ReimbursementGroupTransactions
```

---

# PlannedTemplate and PlannedOccurrence

Recurring income (paycheck) or bill definitions, plus one expected instance per month.

Templates store match mode, pattern, optional merchant/amount, expected day-of-month, and a lookback/lookforward window. `PlannedOccurrenceGenerator` fills months; `PlannedOccurrenceMatcher` links a unique posted transaction and classifies it.

Paychecks can be assigned a set of bills. A bill whose expected day is before the paycheck’s expected day is treated as belonging to the **next** month’s occurrence of that paycheck.

```
PlannedTemplate
    belongsTo User
    belongsTo Category
    belongsTo Merchant (nullable)
    hasMany PlannedOccurrences
    belongsToMany PlannedTemplates (assigned bills / assigned paycheck)

PlannedOccurrence
    belongsTo PlannedTemplate
    belongsTo BankTransaction (nullable)
    belongsTo Category
    belongsTo Merchant (nullable)
```

### Leftover math

For one paycheck occurrence:

```
planned leftover = paycheck amount − assigned bill expected amounts
```

Resolved paychecks/bills use the posted bank amount when present.

Dashboard leftover windows run from this paycheck’s start (posted date if resolved, otherwise expected date) until the next paycheck. Remaining leftover is:

```
brought forward + planned leftover − unassigned spend in the window
```

Unassigned spend includes categorized bank/order spend in the window **and** still-planned unassigned bills. Assigned bill transactions are excluded so they are not subtracted twice. Remaining leftover carries into the next window.

Windows start at a leftover origin: the first paycheck occurrence on or after `users.leftover_starts_on`. That date defaults to the current calendar month the first time leftover is computed (or a paycheck plan is created) and then stays put. Brought forward is $0 at that origin paycheck; spend before it is ignored. The start month can be changed on Plans.

Credit-card **payments** are transfers, not leftover spend. There is no account-balance ledger; debt payoff is not subtracted as its own concept.

---

# BudgetYear and BudgetCategoryLimit

A budget year is a 12-month period starting on the first of a month (`starts_on`). One year can be `is_current`.

Each year has optional monthly limits per category. Dashboard views:

- `month` — that calendar month vs one month of limit
- `ytm` — year-to-month vs `months_elapsed ×` monthly limit

`BudgetProgressService` compares categorized spend (from `CategorySpendQuery`) to those allowed amounts, and also compares expense spend to leftover income.

```
BudgetYear
    belongsTo User
    hasMany BudgetCategoryLimits

BudgetCategoryLimit
    belongsTo BudgetYear
    belongsTo Category
```

---

# Reconciliation pipeline

`RunUserReconciliationPipeline` (manual “Run reconciliation”) runs, in order:

1. Pair credit-card payments
2. Pair other transfers
3. Apply categorization rules
4. Match/create products for eligible retailer order lines
5. Generate missing order components
6. Match merchants onto untagged bank lines
7. Match planned occurrences
8. Match Venmo activities
9. Match pending spends
10. Auto-resolve orders that are entirely off-book
11. Match remaining open orders to bank transactions

A bank import runs a shorter chain of the same pieces. Order and Venmo imports skip the bank-only pairing/categorization/planned-occurrence steps.

Needs-review holds suggested transfers, Venmo matches, and ambiguous rule or pending-spend hits for confirm/reject.

### Bank-derived synthetic orders (not in the live pipeline)

`SyntheticBankSpendReconciler` can still create a 1:1 synthetic `Order` + `OrderComponent` (`metadata.source = bank_synthetic`) for non-order-import merchants. That job is **not** dispatched by the current import or pipeline path. Spend at those merchants is categorized on the bank transaction instead.

`php artisan reconcile:remove-synthetic-bank-spend` deletes leftover synthetic orders and resets those bank lines to unmatched.

---

# Reporting contract

`CategorySpendQuery` is the spend/income source for the dashboard and leftover windows. Half-open `[from, to)` windows apply to `posted_at`, planned `expected_date`, order `ordered_at`, reimbursement `closed_at`, and pending `spent_at`.

- Ungrouped categorized bank bill/expense spend counts toward `category_id`.
- Ungrouped bill/expense with no category counts as uncategorized spend.
- Categorized order components count toward their `category_id` (signed amounts; merge at the call site).
- Uncategorized order components count as uncategorized spend.
- Any transaction in a reimbursement group is excluded from those raw bank totals.
- Closed under-reimbursed groups add their positive net to `remainder_category_id`.
- Closed over-reimbursed `|net|` counts as uncategorized income.
- Open positive nets are awaiting reimbursement, not category spend.
- Ungrouped categorized income credits count toward `category_id`.
- Income linked to a planned occurrence is attributed on the occurrence `expected_date`, not `posted_at`.
- Still-planned income occurrences count `expected_amount` toward their category.
- Unmatched pending spend (`pending` or `needs_review`) counts by `spent_at` as a stand-in.
- Resolved and cancelled pending spend do not count.

Order-import reconciliation is still verified by:

- sum of `TransactionAllocation.allocated_amount` ≈ each `BankTransaction.amount`
- sum of `TransactionAllocation.allocated_amount` ≈ each `OrderComponent.amount`

---

# Relationship diagrams

### Import and reconciliation

```
ImportBatch
    │
    ├── BankTransaction ── Merchant
    │         │
    │         ├── Category (direct classify)
    │         ├── TransactionTransferLink
    │         ├── PlannedOccurrence
    │         ├── PendingSpend
    │         ├── VenmoActivity
    │         └── TransactionAllocation ── OrderComponent ── Order
    │
    ├── Order ── OrderItem ── Product ── Category
    │
    └── VenmoActivity
```

### Product hierarchy (retailer imports)

```
Merchant
     │
     ▼
Product
     │
     ▼
Category
```

### Order hierarchy

```
Order
├── Milk          → product component
├── Shoes         → product component
├── Dog Food      → product component
├── Sales Tax     → order-level
├── Delivery Fee  → order-level
├── Driver Tip    → order-level
└── Discount      → order-level
```

---

# End-to-end examples

## Retailer order (Walmart / Amazon)

Bank imports:

```
Checking

-31.94 Walmart
-217.77 Walmart
```

Walmart order:

```
Milk            3.49
Shoes          16.98
Dog Food       27.99
Tax             2.70
Delivery        7.95
Tip             5.00
```

Generated components (tax is one order-level row, not per item):

```
Milk              Groceries        3.49
Shoes             Clothing        16.98
Dog Food          Pet Supplies    27.99
Sales Tax         (uncategorized)  2.70
Delivery Fee      (uncategorized)  7.95
Driver Tip        (uncategorized)  5.00
```

Reconciliation can allocate one bank charge to several components, or several charges to one order, as long as amounts and payment instruments line up.

## Everyday card spend (no order import)

```
-42.18  CHIPOTLE 1234
```

Merchant matcher extracts “Chipotle” (or a user rule hits). The user classifies the line as an expense in Dining Out and optionally saves a merchant rule. Later Chipotle charges categorize automatically. No synthetic order is created.

## Paycheck leftover

```
Paycheck May 15    +2,400.00
  assigned: rent     1,200.00
  assigned: car        350.00
  planned leftover     850.00

Unassigned spend before the next paycheck   210.00
Remaining leftover                          640.00  → carries forward
```
