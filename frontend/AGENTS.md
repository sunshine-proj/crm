# Frontend Instructions

This directory is the CRM single-page application, built with Vue 3, Vue Single-File Components, TypeScript, and Vite. `src/main.ts` mounts `src/App.vue`; `App.vue` handles sign-in and section selection, and the page-level views live in `src/components/` (dashboard, news, clients, projects, tasks, and invoices). Global styles are in `src/style.css`.

## Conventions

- Build user interfaces using strong UI/UX practices: clear hierarchy, predictable navigation, responsive layouts, accessible contrast and focus states, useful loading/empty/error feedback, and appropriately labeled controls.
- Use a modern, polished, business-oriented dark visual style with restrained futuristic details. Keep patterns consistent across pages; favor deep neutral surfaces, readable text, and a deliberate accent palette rather than decorative effects that reduce clarity.
- Use Vue `<script setup lang="ts">` and Composition API state (`ref`, `computed`, lifecycle hooks) to match the existing components. Keep page-specific behavior in the corresponding component unless it is genuinely shared.
- Keep API payload and response types aligned with Symfony. The API uses snake_case for serialized date fields; convert values for display at the UI boundary rather than silently changing the API shape.
- Authenticated API calls use `/api/...` and the JWT from `localStorage` as a Bearer token. Vite proxies `/api` to `http://localhost:8081` in development; keep requests relative so the proxy works.
- Follow existing loading, saving, empty, and error states when changing data workflows. Preserve accessible labels, button types, and alert semantics in forms and controls.
- Keep invoice filters server-backed. Invoice creation/editing sends line item amounts as decimal strings; the API returns the authoritative total. Download PDFs from the authenticated invoice PDF endpoint as a binary response, not from client-side total calculations.
- Invoice amount previews must use integer minor units (or another decimal-safe approach), never JavaScript floating-point arithmetic. Require a currency when filtering by amount.
- Match and extend the shared visual system in `src/style.css`; avoid introducing a second styling framework or a new state-management layer for a local page change.
- Keep shared planning/date validation in `src/lib/` and cover it with Vitest unit tests.

## Verification

Run `npm run type-check` for a focused TypeScript/Vue check, `npm run build` for type-checking plus a production build, `npm test` for the Vitest suite, and `npm run test:coverage` for a coverage report (currently limited to `src/lib`). Role checks in the UI (`src/lib/session.ts`) only hide controls; the API enforces access. `npm run dev` starts the Vite development server. No lint script is currently defined in `package.json`.

## Navigation And Shared Helpers

- Client and project detail views use hash routes (`#/clients/<id>`, `#/projects/<id>`) parsed by `src/lib/route.ts`; sidebar sections are otherwise component switches in `App.vue`. Use `src/lib/api.ts` (`apiRequest`) for new components rather than copying another request helper.
- Board and pipeline grouping/totals live in `src/lib/kanban.ts` and `src/lib/deals.ts` with Vitest tests; deal totals use integer minor units per currency.
- Tax is calculated by the server; the invoice editor only previews the net total. Never compute tax, credit-note totals or invoice numbers in the browser. `lib/api.ts` provides `apiRequest` and `downloadFile` (authenticated PDF downloads).
