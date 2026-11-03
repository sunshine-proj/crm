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
