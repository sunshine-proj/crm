# Frontend Instructions

This directory is the CRM single-page application, built with Vue 3, Vue Single-File Components, TypeScript, and Vite. `src/main.ts` mounts `src/App.vue`; `App.vue` handles sign-in and section selection, and the page-level views live in `src/components/` (dashboard, news, clients, projects, and tasks). Global styles are in `src/style.css`.

## Conventions

- Use Vue `<script setup lang="ts">` and Composition API state (`ref`, `computed`, lifecycle hooks) to match the existing components. Keep page-specific behavior in the corresponding component unless it is genuinely shared.
- Keep API payload and response types aligned with Symfony. The API uses snake_case for serialized date fields; convert values for display at the UI boundary rather than silently changing the API shape.
- Authenticated API calls use `/api/...` and the JWT from `localStorage` as a Bearer token. Vite proxies `/api` to `http://localhost:8081` in development; keep requests relative so the proxy works.
- Follow existing loading, saving, empty, and error states when changing data workflows. Preserve accessible labels, button types, and alert semantics in forms and controls.
- Match the existing visual system in `src/style.css`; avoid introducing a second styling framework or a new state-management layer for a local page change.
- Keep shared planning/date validation in `src/lib/` and cover it with Vitest unit tests.

## Verification

Run `npm run type-check` for a focused TypeScript/Vue check, `npm run build` for type-checking plus a production build, and `npm test` for the Vitest suite. `npm run dev` starts the Vite development server. No lint script is currently defined in `package.json`.
