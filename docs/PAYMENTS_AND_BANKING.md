# Banking and payment workflow: decision record

Status: **selected and implemented for statement files only.** Nothing here has been validated against a specific bank.

## Selected workflow

**Bank statement import and reconciliation, provider-agnostic.** Finance users export a statement from internet banking
and upload it (Bank → Import a statement):

| Format | Notes |
| --- | --- |
| ISO 20022 **camt.053** XML | Offered by SEPA banks in Latvia, Lithuania, Estonia and Germany and by many UK banks. Booked entries only; balances are cross-checked. |
| **CSV** | Needs a header with `date` and `amount` columns; `currency`, `reference`/`description`, `counterparty`/`name` and `id` are optional. Comma, semicolon or tab separated; `1,234.56` and `1.234,56` are both read. |

Every line is matched to a recorded customer receipt or vendor payment by **exact amount and currency**; date proximity
(±7 days) and a reference or counterparty name in the bank text only rank candidates. Automatic matching accepts only a
unique candidate whose reference appears in the bank text; everything else is for a person. Lines with no book record
(for example bank fees) are *ignored with a recorded reason*. The period report lists recorded payments with no bank
line and statement lines with no payment.

Safeguards: the same file cannot be imported twice; overlapping statements never duplicate a transaction; a statement
must belong to the account (IBAN) and currency, and its opening balance plus transactions must equal its closing
balance; XML with DOCTYPE or entity declarations is rejected; files are limited to 5 MB and 5 000 lines.

## What it deliberately does not do

- It **does not connect to a bank**, hold bank credentials, use PSD2/open-banking APIs, or initiate payments.
- It **does not post anything**: bank fees, interest and unrecorded receipts must be entered through the normal journal
  or payment workflows. It does not compare the ledger cash balance with the bank balance.
- **No payment-service-provider (card, direct debit, payment-link) integration was selected.** The invoice workflow
  records payments manually and the application never claims a payment is confirmed by an external party.

## Decisions for the organisation

1. Which bank(s) and which export format each can produce (camt.053 is preferable to CSV).
2. Whether open-banking feeds (for example through a licensed aggregator) are wanted. That needs a provider choice, a
   data-processing agreement, token storage and refresh, and consent handling, and should be a separate scoped project.
3. Whether online card or direct-debit collection is needed. That adds PCI scope, webhooks, refunds and chargebacks and
   must not be attempted without a provider and a security review.
4. Bank-fee, interest and FX-difference handling in the ledger.
