# Accounting treatment of sales and inventory transactions

Status: **defined for the operational ledger; not validated by an accountant.** The treatment below describes what
the software does and is pinned by tests. It is not a statement of what any jurisdiction requires.

## Inventory costing method (decision record)

**Chosen: perpetual weighted-average cost**, per product and warehouse, in the currency of the purchase orders.

| Option | Why not (for now) |
| --- | --- |
| FIFO | Needs cost layers and layer-level returns; more state for little benefit to small service-oriented teams. |
| Standard cost | Needs variance accounts and a maintained standard; no demand yet. |
| LIFO | Not permitted under IFRS and not accepted in several target jurisdictions. |

Weighted average is accepted under IFRS, UK and the German/Baltic local frameworks in common use, needs one running
value per pool, and degrades gracefully when stock is returned or adjusted. **Confirm with the organisation's
accountant** (`docs/COMPLIANCE_SCOPE.md`, rows 2 and 7); changing the method later means revaluing history.

Rules implemented:
- A receipt adds goods cost (purchase-order unit cost × quantity) plus allocated landed cost.
- Every issue (sales dispatch, supplier return, negative stock adjustment) leaves at the pool's current average cost
  rounded half-up to a minor unit; the last unit takes the exact remaining value, so a pool always empties to zero.
- A customer return re-enters at the cost its dispatch removed, not at today's average.
- A counted gain enters at the pool's average cost, or at an entered unit cost when the pool is empty.
- Each pool holds one currency. Receiving in a different currency while stock is on hand is refused; there is no
  currency conversion.
- Landed costs (freight, duty, handling) are entered **on the goods receipt** by a finance user and allocated to its
  lines in proportion to goods value (largest remainder, so the shares always add up exactly). Costs that arrive after
  the receipt cannot be added to it yet.
- The feature is **off until four accounts are mapped** (inventory, cost of sales, goods received not invoiced,
  inventory adjustment). Until then stock stays quantity-only exactly as before.
- Stock received before valuation was switched on is "unvalued". It cannot be issued until an opening valuation gives
  it a cost (Inventory → Valuation). The offset of that entry goes to the inventory adjustment account.

## Postings

| Event | Journal posting | Notes |
| --- | --- | --- |
| Quote sent / accepted / converted to order | none | Commercial documents, not accounting events. |
| Deal won | none | A deal is a forecast record only. |
| Sales shipment recorded | none | Records shipped quantity against the order. |
| Warehouse dispatch (valuation on) | Dr Cost of sales, Cr Inventory — average cost × quantity | One entry per product line. |
| Invoice issued | Dr Accounts receivable, Cr Revenue — invoice total | Revenue is recognised **when the invoice is issued**, not when goods ship. No tax split. |
| Customer receipt | Dr Cash, Cr Accounts receivable | Over-payment is rejected. |
| Customer return of goods (valuation on) | Dr Inventory, Cr Cost of sales — original dispatch cost | **No credit note exists**, so AR and revenue are not reduced by a return. |
| Purchase order created / approved | none | Orders over the approval threshold wait for a finance user other than the creator. |
| Goods receipt (valuation on) | Dr Inventory, Cr Goods received not invoiced — goods + landed cost | One entry per product line. |
| Vendor bill linked to a purchase order | Dr Goods received not invoiced (up to the open accrual), Dr Purchase price variance (any excess), Cr Accounts payable | Needs the price-variance account only when there is an excess. |
| Vendor bill without a purchase order, or before valuation | Dr Project expense, Cr Accounts payable | As before. |
| Vendor payment | Dr Accounts payable, Cr Cash | |
| Supplier return (valuation on) | Dr Goods received not invoiced, Cr Inventory — average cost | If the bill was already paid, the accrual goes negative until a supplier credit is recorded (not implemented). |
| Stock adjustment (valuation on) | Loss: Dr Inventory adjustment, Cr Inventory. Gain: the reverse | |

## Purchasing controls

- **Approval:** with a threshold set (administrators), purchase orders at or above it in the default currency, and any
  order in another currency, start as `pending_approval`; goods cannot be received until a finance user other than
  the creator approves (administrators may approve their own). Rejection needs a comment; decisions are append-only.
- **Three-way match:** a bill linked to a purchase order must be for the same supplier and currency and, together with
  earlier bills, must not exceed goods received at order prices (less supplier returns) plus landed costs. A larger
  bill needs an override reason and is stored as an `exception`. Not matched: tax, partial-line price differences,
  and bills that span several orders.

## Tests and verification

`InventoryValuationServiceTest` (costing, rounding, currency, landed-cost allocation, legacy stock, control
reconciliation), `PurchaseMatchingServiceTest`, `PurchasingFlowTest` (approval, landed costs, matching over HTTP),
`SalesInventoryAccountingTreatmentTest` (no postings without mappings), and `scripts/verify-inventory-e2e.sh` against
PostgreSQL (receipt → dispatch → bill → reconciliation).

## Open decisions

1. Costing method and its jurisdictional acceptance (above).
2. **Revenue timing** — invoice issue vs delivery — per jurisdiction and contract type.
3. **Credit notes** reversing revenue/AR (and supplier credits against the accrual) for returns and corrections.
4. **Tax**: VAT/sales-tax accounts, input-tax on purchases, rounding. Nothing is posted today.
5. **Multi-currency**: valuation, bills and sales are single-currency per pool/document with no FX.
6. **Landed costs after receipt** (late freight invoices) and allocation by weight/volume instead of value.
7. **Period-end**: stock counts, write-downs to net realisable value and cut-off are not handled.

Decision owner: the organisation's accountant (see `docs/COMPLIANCE_SCOPE.md`, rows 2–4 and 7).
