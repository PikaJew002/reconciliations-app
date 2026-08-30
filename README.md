# Reconciliations App

A personal finance app that imports **bank transactions** and **merchant order history**, then turns them into categorized spending, budgets, and paycheck leftover.

The original reconciliation idea still holds:

> **Bank transactions represent how money moved. Orders represent what was purchased. Transaction allocations connect the two.**

Most everyday card spend never gets a line-item order. Those transactions are classified and categorized on the bank transaction itself. Walmart and Amazon charges wait for a real order import so spend can be attributed to products.

## Tech Stack

- **Backend:** Laravel 13, PHP 8.5+
- **Frontend:** Vue 3, Inertia.js, Tailwind CSS 4, Vite
- **API:** Laravel Sanctum (Chrome extension and pending-spend clients)

## Getting Started

```bash
composer setup
```

This installs dependencies, creates `.env`, generates an app key, runs migrations, and builds frontend assets.

Start the development environment (server, queue, logs, and Vite):

```bash
composer dev
```

The queue worker matters. Imports and reconciliation run as background jobs.

Run tests:

```bash
composer test
```

## What the app does

After login, the nav covers the working surface:

| Page | What it is for |
| --- | --- |
| **Home** | Month or year-to-month income, bills, and expenses vs budget, plus the current paycheck leftover window |
| **Accounts** | Checking, savings, and credit card accounts; CSV imports; per-account transaction lists |
| **Categories** | User-owned bill, expense, and income categories |
| **Budgets** | Monthly limits on a 12-month budget year |
| **Plans** | Recurring paychecks and bills, assigned to each other, then matched to posted transactions |
| **Rules** | Learned income and expense categorization rules |
| **Orders** | Walmart / Amazon imports, other merchant spend, product categorization |
| **Reconciliation** | Unmatched transactions, needs-review suggestions, and a manual “run reconciliation” action |

A first-run onboarding checklist walks through: add accounts → import bank history → optionally import retailer orders → start categorizing.

### Imports

| Source | How it arrives |
| --- | --- |
| **Cumberland Valley National Bank** | Checking/savings CSV |
| **Cumberland Valley National Bank Credit Card** | Card CSV |
| **Capital One** | Card CSV |
| **Walmart** | Order history CSV |
| **Amazon** | Chrome extension scrape → `POST /api/amazon/import` |
| **Venmo** | Activity CSV |

Each upload becomes an `ImportBatch`. After a successful import, a job chain pairs transfers, applies rules, matches merchants, links Venmo and pending spends, generates order components, and tries to match orders to bank charges.

### Reconciliation

The review pages are where most day-to-day work happens:

- Classify a transaction as **income**, **bill**, **expense**, **transfer**, or **reimbursement**
- Assign a category and optionally save a rule so the next similar line is automatic
- Confirm or reject suggested **transfers**, **credit card payments**, and **Venmo** matches
- Group reimbursed spend so only the leftover hits a category
- Resolve split-tender retailer orders (card + gift card, cash, Walmart balance, and so on)
- Log a **pending spend** from a client before the bank line posts; it counts toward the dashboard until it matches

Bank descriptions are normalized and matched to a `Merchant` with user-owned matching rules (Walmart and Amazon ship with default patterns). Unrecognized card/POS names can create a new merchant.

### Planning and leftover

**Plans** are recurring income and bill templates. Each template generates monthly **occurrences** and tries to match them to posted bank transactions.

Assign bills to a paycheck. Planned leftover is paycheck amount minus those assigned bills. The dashboard leftover hero subtracts spend and checking transfers in this paycheck window (not leftover carried from earlier paychecks). Remaining leftover still carries forward for a year check-in.

Debt payoff and credit-card balance tracking are not modeled as a ledger yet. Card payments are paired as transfers so they are not double-counted as spend.

## Domain Model

Everything below is scoped to a `User`.

### Account

A real financial account you import into. Types: checking, savings, credit card, cash.

Each account has a default classification (`bill` or `expense`) used as a hint when categorizing its spend. A system **Off-book** account holds gift-card, cash, and similar tenders that never hit a bank CSV.

```
Account
    belongsTo User
    hasMany BankTransactions
    hasMany PendingSpends
```

### ImportBatch

One import operation: a bank CSV, a Walmart CSV, an Amazon scrape, or a Venmo activity file.

```
ImportBatch
    belongsTo User
    hasMany BankTransactions
    hasMany Orders
    hasMany VenmoActivities
```

### Merchant

A canonical payee (Walmart, Amazon, a local restaurant, the electric company). Used by orders, bank transactions, pending spends, and matching rules.

Retailers that can import line items set `supports_order_import = true`. Those charges wait for a real order. Other merchants are categorized on the bank transaction.

```
Merchant
    belongsTo User
    hasMany Orders
    hasMany BankTransactions
    hasMany Products
    hasMany MerchantMatchingRules
    hasMany PendingSpends
```

### BankTransaction

One posted line from a financial institution.

It can be classified as `income`, `transfer`, `bill`, `expense`, or `reimbursement`, and it can have a `category_id`. Status is typically `unmatched`, `matched`, `partial`, or `ignored`.

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
```

### Order

One logical purchase from a merchant, independent of how it was paid. Real imports come from Walmart or Amazon. Split tenders (card + gift card, cash, store balance) are resolved against bank lines and the off-book account.

```
Order
    belongsTo User
    belongsTo Merchant
    belongsTo ImportBatch
    hasMany OrderItems
    hasMany OrderComponents
```

### OrderItem

Merchandise only — never tax, delivery, tip, or discounts.

Walmart (and Sam’s Club) lines are linked to a merchant-scoped `Product` by SKU or normalized description. Amazon lines are usually categorized at the order or item level without a product catalog.

```
OrderItem
    belongsTo Order
    belongsTo Product (nullable)
    hasMany OrderComponents
```

### Product

A canonical item at one merchant. Categorizing a product applies that category to its order-item components.

```
Product
    belongsTo User
    belongsTo Merchant
    belongsTo Category (nullable)
    hasMany OrderItems
```

### Category

Reporting buckets with a `kind` of `bill`, `expense`, or `income`. Categories can nest (`parent_id`).

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

### OrderComponent

Every dollar on an order. Product components come from line items. Tax, delivery, tip, and discount are **order-level** components (not split across items).

```
OrderComponent
    belongsTo Order
    belongsTo OrderItem (nullable)
    belongsTo Category (nullable)
    hasMany TransactionAllocations
```

### TransactionAllocation

The reconciliation table. Connects bank transactions to order components. Supports one-to-many, many-to-one, and many-to-many, including partial amounts.

```
TransactionAllocation
    belongsTo BankTransaction
    belongsTo OrderComponent
```

### Supporting records

| Model | Role |
| --- | --- |
| `MerchantMatchingRule` | Maps bank description text to a merchant (`contains` or extracted name) |
| `TransactionCategorizationRule` | Auto-classifies later transactions by description, merchant, and/or amount |
| `TransactionTransferLink` | Suggested or confirmed pairing of a debit and a credit (transfers and card payments) |
| `VenmoActivity` | Imported Venmo payment or cashout, optionally linked to a bank line |
| `PendingSpend` | Spend logged before it posts; matched later to a bank line or Venmo activity |
| `ReimbursementGroup` | Expense + reimbursement legs; closed remainder can hit a category |
| `PlannedTemplate` / `PlannedOccurrence` | Recurring paycheck or bill, and each month’s expected instance |
| `BudgetYear` / `BudgetCategoryLimit` | A 12-month budget period and per-category monthly amounts |

## Reporting

Dashboard totals are **not** “sum every order component.” `CategorySpendQuery` combines:

- Classified, categorized bank spend (and income)
- Categorized order components
- Unmatched pending spends (as a stand-in until the bank line posts)
- Still-planned income occurrences (expected amount on the expected date)
- Closed reimbursement remainders

Transactions inside an open or closed reimbursement group are excluded from the raw bank totals so they are not double-counted.

Reconciliation for imported orders still checks that allocations cover each bank transaction and each order component.

## License

MIT
