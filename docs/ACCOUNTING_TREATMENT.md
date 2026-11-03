# Accounting treatment of sales and inventory transactions

Status: **defined for the operational ledger; not validated by an accountant.** The treatment below describes what
the software does today and is pinned by tests. It is not a statement of what any jurisdiction requires.

## Sales

| Event | Journal posting | Notes |
| --- | --- | --- |
| Quote sent / accepted / converted to order | none | Commercial documents, not accounting events. |
| Deal won | none | A deal is a forecast record only. |
| Sales shipment recorded | none | Records shipped quantity against the order. |
| Invoice issued (from order, time, or milestone) | Dr Accounts receivable, Cr Revenue — invoice total | Revenue is recognised **when the invoice is issued**, not when goods ship or work is approved. No tax split: configured tax codes are not applied. |
| Customer receipt | Dr Cash, Cr Accounts receivable — receipt amount | Over-payment is rejected. |
| Customer return of goods | none (stock only) | No credit note or refund exists, so AR and revenue are **not** reduced by a stock return. |

## Inventory and purchasing

| Event | Journal posting | Notes |
| --- | --- | --- |
| Purchase order created / goods receipt | none | Quantity only; no inventory asset, GR/IR or accrual. |
| Warehouse dispatch of a sales shipment | none | No cost of goods sold. |
| Stock adjustment, supplier return | none | No gain/loss or valuation effect. |
| Vendor bill | Dr Project expense, Cr Accounts payable | Vendor bills are not matched to purchase orders or receipts. |
| Vendor payment | Dr Accounts payable, Cr Cash | |

`SalesInventoryAccountingTreatmentTest` asserts that receipts, dispatches and adjustments create no journal
entries; `scripts/verify-ledger-e2e.sh` and `FinancePostingServiceTest` cover the invoice, receipt, bill and
payment postings, idempotency and rollback.

## Consequences and open decisions

1. **No inventory asset and no cost of sales.** Gross margin on goods is overstated in the ledger and stock value
   is not reported. Fixing this needs a costing method (FIFO, weighted average, standard) — see the P1/P2 item
   "Select and validate an inventory costing method" — plus mappings for inventory, COGS and GR/IR accounts.
2. **Revenue timing** (invoice issue vs shipment/delivery) must be confirmed for each target jurisdiction and
   contract type before the ledger is used as a book of record.
3. **Returns and corrections** need credit notes that reverse revenue/AR (and later COGS/inventory); today a
   mistaken issued invoice can only be handled outside the application.
4. **Tax** is not posted; VAT/sales-tax liability accounts and rounding rules are undefined.

Decision owner: the organisation's accountant (see `docs/COMPLIANCE_SCOPE.md`, rows 2–4 and 7).
