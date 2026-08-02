# Financial Reconciliation Engine – Domain Model Summary

## Overview

The system imports **bank transactions** and **merchant order history** separately, then reconciles them into categorized spending.

The fundamental principle is:

> **Bank transactions represent how money moved. Orders represent what was purchased. Transaction allocations connect the two.**

---

# ImportBatch

Represents a single import operation.

Examples:

* Chase Checking CSV import
* Walmart Orders CSV import
* Amazon Order History import

Relationships:

```
ImportBatch
    hasMany BankTransactions
    hasMany Orders
```

---

# Merchant

Represents a canonical merchant.

Examples:

```
Walmart
Amazon
Kroger
Target
```

Used by both orders and bank transactions after merchant normalization.

Relationships:

```
Merchant
    hasMany Orders
    hasMany ProductAliases
    hasMany BankTransactions (nullable until matched)
```

---

# BankTransaction

Represents one transaction imported from a financial institution.

Examples:

```
-249.71 Walmart
-31.94 Walmart
1850.00 Payroll
```

Bank transactions never contain categories or purchased products.

Relationships:

```
BankTransaction
    belongsTo Account
    belongsTo Merchant (nullable)
    belongsTo ImportBatch

    hasMany TransactionAllocations
```

Example:

```
Checking Account

-249.71
WAL-MART SUPERCENTER
```

---

# Order

Represents one logical purchase from a merchant.

Examples:

```
Walmart Order #12345

Total:
249.71
```

An order exists independently of how it was paid.

Relationships:

```
Order
    belongsTo Merchant
    belongsTo ImportBatch

    hasMany OrderItems
    hasMany OrderComponents
```

---

# OrderItem

Represents one purchased product.

Examples:

```
Milk
Eggs
Shoes
Dog Food
```

Contains only merchandise.

Never contains:

* tax
* delivery
* tip
* discounts

Relationships:

```
OrderItem
    belongsTo Order

    belongsTo Product (nullable)

    hasMany OrderComponents
```

---

# Product

Represents a canonical product.

Many merchant descriptions may map to one product.

Example:

```
Great Value Whole Milk
```

Aliases:

```
GV Whole Milk

Great Value Milk 1 Gal

Great Value Whole Milk 128 oz
```

Relationships:

```
Product
    belongsTo ExpenseCategory

    hasMany OrderItems

    hasMany ProductAliases
```

---

# ProductAlias

Maps merchant-specific descriptions to canonical products.

Example:

```
Merchant:
Walmart

Description:
GV Whole Milk 128 oz

↓

Product:
Great Value Whole Milk
```

Relationships:

```
ProductAlias
    belongsTo Merchant

    belongsTo Product
```

---

# ExpenseCategory

Represents reporting categories.

Examples:

```
Groceries

Household

Clothing

Dining Out

Delivery Fees

Delivery Tips
```

Relationships:

```
ExpenseCategory
    hasMany Products

    hasMany OrderComponents
```

---

# OrderComponent

Represents every dollar within an order.

This is the financial representation of an order.

Examples:

```
Milk
3.49

Shoes
16.98

Dog Food
27.99

Sales Tax
2.70

Delivery Fee
7.95

Driver Tip
5.00

Discount
-3.50
```

Each component has:

* amount
* type
* category

Every component is eventually allocated to bank transactions.

Relationships:

```
OrderComponent
    belongsTo Order

    belongsTo OrderItem (nullable)

    belongsTo ExpenseCategory

    hasMany TransactionAllocations
```

---

# TransactionAllocation

Connects bank transactions to order components.

This is the reconciliation table.

Supports:

* one bank transaction → many components
* many bank transactions → one component
* many-to-many

Relationships:

```
TransactionAllocation
    belongsTo BankTransaction

    belongsTo OrderComponent
```

Example:

```
Transaction

-31.94

↓

Milk
3.49

↓

Eggs
5.20

↓

Bread
4.10

↓

Shoes
16.98

↓

Tax
2.17
```

Another transaction could fund the remaining components of the same order.

---

# Complete Relationship Diagram

```
ImportBatch
    │
    ├──────────────┐
    │              │
    ▼              ▼
BankTransaction   Order
                      │
                      ▼
                 OrderItem
                      │
                      ▼
                OrderComponent
                      ▲
                      │
            TransactionAllocation
                      │
                      ▼
               BankTransaction
```

Product hierarchy:

```
Merchant
     │
     ▼
ProductAlias
     │
     ▼
Product
     │
     ▼
ExpenseCategory
```

Order hierarchy:

```
Order

├── Milk
│      ├── Product Component
│      └── Tax Component
│
├── Shoes
│      ├── Product Component
│      └── Tax Component
│
├── Dog Food
│      ├── Product Component
│      └── Tax Component
│
├── Delivery Fee
│
├── Driver Tip
│
└── Discount
```

---

# End-to-End Example

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

Generated components:

```
Milk              Grocery
3.49

Shoes             Clothing
16.98

Dog Food          Pet Supplies
27.99

Tax (Milk)        Grocery
0.00

Tax (Shoes)       Clothing
1.02

Tax (Dog Food)    Pet Supplies
1.68

Delivery Fee      Delivery Fees
7.95

Driver Tip        Delivery Tips
5.00
```

Reconciliation:

```
Transaction 1 (-31.94)

→ Milk
→ Shoes
→ Shoes Tax
→ Partial Dog Food


Transaction 2 (-217.77)

→ Remaining Dog Food
→ Dog Food Tax
→ Delivery Fee
→ Driver Tip
→ Remaining order components
```

Reports are produced by summing `OrderComponent.amount` grouped by `ExpenseCategory`, while reconciliation is verified by ensuring that the sum of `TransactionAllocation.allocated_amount` equals each `BankTransaction.amount` and each `OrderComponent.amount`.
