# Jurisdiction, accounting and retention scope — decision record

Status: **open**. The application cannot answer these questions; a qualified accountant or lawyer for each
country must. Until a row is signed off, do not describe the product as compliant for that country (PRD ORG-1,
ORG-2, ORG-5, FN-6, FN-13).

## Decisions required

| # | Question | Owner (name / firm) | Answer | Source / reference | Date |
| --- | --- | --- | --- | --- | --- |
| 1 | Which countries will the first production installation operate in (LV, LT, EE, DE, GB)? One installation = one organization. | Business owner | | | |
| 2 | Legal entity types and accounting standard per country (e.g. small-company regime, local GAAP, IFRS). | Accountant | | | |
| 3 | Mandatory invoice content and numbering rules per country (sequence, gaps, series, language, e-invoicing). | Accountant / lawyer | | | |
| 4 | Tax regime per country: VAT registration, rates, rounding, reverse charge, reporting periods. | Tax adviser | | | |
| 5 | Retention: which event starts the clock (document date, end of financial year, contract end) and the period for financial records, client data, audit logs and uploaded documents. | Lawyer | | | |
| 6 | Personal-data rules for client contacts: lawful basis, erasure vs. financial retention conflicts, export requests. | Data-protection adviser | | | |
| 7 | Whether the operational ledger may serve as the books of record, or an external accounting system remains authoritative. | Accountant | | | |
| 8 | Which finance duties map to `ROLE_FINANCE` vs `ROLE_ADMIN`, and who approves period close/reopen (segregation of duties). | Business owner / accountant | | | |

## What the software currently does (so reviewers can check it)

- Country and default currency are stored; no country-specific rule is applied. Tax codes are configuration only.
- Invoice identifiers are ID-based (`INV-<year>-<id>`), not jurisdiction-approved numbering. Journals are numbered `JE-<year>-<id>`.
- Retention is a reference value (default 10 years); nothing is deleted automatically.
- Roles: `ROLE_USER`, `ROLE_FINANCE` (ledger, payables, invoice issue, customer receipts, cost visibility) and
  `ROLE_ADMIN` (everything, plus configuration, period close/reopen, reversals, user roles and record recovery).
  Assign them with `php bin/console app:user:roles <email> ROLE_FINANCE`.

Record the sign-off for each row, then implement the validated rules as separate, tested changes.

## How the software uses your answers (jurisdiction gate)

Rows 3 and 4 above are not just paperwork: in Settings → Jurisdiction an administrator records, per country and scope
(tax, numbering), **who** confirmed the rules, **what** they relied on, **when**, and for tax **which rounding method**
they confirmed. Until a sign-off exists for the organisation's country:

- invoices are not taxed and keep the ID-based numbers (`INV-<year>-<id>`);
- credit notes are numbered `CN-<year>-<id>`.

While it exists, tax is calculated on the server and numbers come from a gapless sequence. Revoking a sign-off switches
the behaviour off again for new documents; issued documents keep what they were issued with. The application records the
claim and cannot verify it, and it never states that any document is legally compliant.

Seed reference data in the catalog (all unvalidated): LV PVN 21%, LT PVM 21%, EE KM 24%, DE USt 19%, GB VAT 20%.
Reduced rates, exemptions, reverse charge, e-invoicing formats (for example XRechnung in Germany or Peppol), the
tax-amount-in-local-currency rule, and statutory reporting remain open for the adviser of each country.
