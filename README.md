# Laravel Banking Platform

A full digital-banking web application built with **Laravel 13** and **PHP 8.4**: customer accounts, internal and external transfers, cards, loans, savings, fixed deposits and bill payments, with a double-entry style ledger behind every money movement and an admin back office to run it.

> This repository is a **showcase**. The full source code is private because the platform is a commercial product.
> It contains an overview, the architecture and a few representative files. I'm happy to walk through the complete codebase on a call.

**Author:** Alabi Babatunde, PHP/Laravel backend developer · [LinkedIn](https://www.linkedin.com/in/tundeblog)

---

## At a glance

| | |
|---|---|
| Framework | Laravel 13, PHP 8.4, MySQL / MariaDB |
| Front end | Blade, Tailwind CSS, Alpine.js |
| Domain models | 57 Eloquent models |
| HTTP layer | 87 controllers (customer portal + admin panel) |
| Database | 84 migrations |
| Automated tests | 125 PHPUnit feature tests |
| Business logic | 37 dedicated service classes (`app/Services`) |

## Features

**Customer portal**
- Account opening and onboarding with tiered KYC (admin-defined dynamic fields)
- Internal transfers and external (inter-bank) transfers with beneficiary management and recipient lookup
- Transaction PIN, email OTP for sensitive actions, device tracking and login history
- Card lifecycle management and cardless withdrawals
- Loans with products, applications, custom fields and instalment schedules
- Savings plans, fixed deposits and investment products
- Bill payments, rewards, support tickets and a notification centre
- Downloadable transaction receipts

**Admin back office**
- Customer and account management, account limits, manual credit/debit adjustments
- Transfer review queue for flagged transfers
- Deposit gateways with per-currency limits, plus manual deposit methods with custom fields
- KYC review, loan application review, withdrawal processing
- Multi-currency with conversion and base-currency pricing
- CMS pages, menus, themes (install, preview, settings, backups), notification templates
- Email logs, Laravel log viewer, roles and admin onboarding

## Architecture

The code is organised as a **Core** (auth, security, settings, notifications, payment contracts, ledger, dynamic forms, audit logging) and a **Banking product** layer (accounts, transfers, cards, loans, savings), with strict rules between them:

1. Product code calls Core contracts. The Core never calls product controllers.
2. **All money movement goes through the ledger** and produces an audit record. Balances are never mutated directly from controllers.
3. Payment providers are adapters behind a `PaymentGatewayContract`. Provider SDKs never leak into controllers.
4. Dynamic forms (KYC, loans, deposits) are defined by a stable key and validated server-side.
5. Deposit approval and rejection are **atomic and idempotent**. Gateway limits and account ownership are enforced on the server.

```
Controller ──► Service (TransferService, DepositService, LoanService…)
                  │
                  ├──► LedgerService ──► DB transaction + row locks
                  │        ├── Transaction rows (debit / credit, balance before/after)
                  │        └── JournalEntry (debit account ↔ credit account)
                  └──► Notifications (templated email / SMS / push)
```

## Code samples

| File | What it shows |
|---|---|
| [`samples/LedgerService.php`](samples/LedgerService.php) | Money movement inside DB transactions, **row locking in a consistent order to avoid deadlocks**, `bccomp` balance checks, paired debit/credit transactions, journal entries and reversals |
| [`samples/OtpService.php`](samples/OtpService.php) | Purpose-scoped one-time codes, hashed storage, expiry and a brute-force attempt ceiling |
| [`samples/tests/TransferTest.php`](samples/tests/TransferTest.php) | Feature tests proving transfers move funds correctly, write exactly one journal entry, and reject insufficient balance and a wrong PIN without side effects |

## What I'd highlight

- **Correctness with money:** every balance change is wrapped in a transaction with pessimistic locks, records `balance_before`/`balance_after`, and is reversible through a compensating entry rather than an edit.
- **Security in depth:** PIN plus OTP for sensitive actions, device and login history, password history, and attempt limits.
- **Tested behaviour:** feature tests cover the customer and admin flows, including failure paths.
