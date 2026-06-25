# WR WB Customer Demo Seed

Lean demo dataset for customer presentation — outlet **WR WB** (`DEMO-WRWB`), period **May 2026**.

## Run

```bash
cd api

# Full reset + seed
php artisan demo:seed-wrwb --fresh

# Idempotent re-run (no migrate)
php artisan demo:seed-wrwb
```

## Login accounts

| Role | Email | Password | PIN | Notes |
|------|-------|----------|-----|-------|
| Admin | admin@wrwb.demo | demo123 | 0000 | Merchant, outlet, user management |
| Owner | owner@wrwb.demo | demo123 | 1234 | Business executive; edit assigned outlet only |
| Manager | manager@wrwb.demo | demo123 | 2345 | Printer, payment, tax settings |
| Kasir 1 | kasir1@wrwb.demo | demo123 | 3456 | POS cashier |
| Kasir 2 | kasir2@wrwb.demo | demo123 | 4567 | POS cashier |
| Kitchen | kitchen@wrwb.demo | demo123 | 5678 | Kitchen display |

## What is seeded

- 1 outlet, 6 users, cash + QRIS static payments only
- 15 menu items (3 categories × 5), recipes + stock
- 10 posted POS orders (May 2026) + 1 open bill + 2 QR orders + 1 closed shift
- 10 procurement flows (PR→PO→GRN→invoice/payment variants) with journals
- HR master, May attendance, payroll run (posted + paid + closed)
- 3 manual journals + 1 loyalty member with point accrual

## Verify

```bash
php artisan test --filter=WrWbMay2026DemoSeedTest
```
