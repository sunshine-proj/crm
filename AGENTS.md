# CRM Workspace

This repository contains a Vue 3 + TypeScript single-page CRM frontend in `frontend/` and a PHP 8.4 / Symfony 8 JSON API in `src/`. The backend uses Doctrine ORM with PostgreSQL and JWT authentication. Docker Compose runs the PHP-FPM, Nginx, and PostgreSQL services; the API is exposed locally on port 8081. The Vite development server runs on port 5173 and proxies `/api` requests to the API.

## Working Across The Stack

- Keep frontend and backend API changes in sync. Treat the Symfony JSON response fields and request payloads as the contract; frontend code currently uses snake_case timestamp fields such as `created_at` and `start_datetime`.
- Invoices are authenticated project-linked records. Keep their API, Vue editor, manager filter, PDF output, and Doctrine migration synchronized. Invoice amounts use decimal strings at API boundaries and integer minor units for server calculations; never calculate money with binary floats.
- Project manager filters depend on `Project.manager`; keep the project editor, minimal manager selector endpoint, invoice snapshots, and migration aligned.
- Preserve the existing authentication flow: login is `POST /api/login`; authenticated frontend requests send the JWT as `Authorization: Bearer <token>`.
- Authorization differs by resource. Check the existing controller and security configuration before changing access rules; do not assume all authenticated users can perform the same operations.
- Keep changes within the owning app. Frontend-specific guidance is in `frontend/AGENTS.md`; backend application-source guidance is in `src/src/AGENTS.md`.
- Avoid committing credentials or changing persisted data under `storage/` as part of routine code changes.

## Common Commands

- Start the containerized backend and supporting services: `docker compose up -d`.
- Start the frontend from `frontend/`: `npm run dev`.
- Check and build the frontend from `frontend/`: `npm run build` (runs Vue/TypeScript type-checking and the Vite production build); run frontend unit tests with `npm test`.
- Run Symfony console commands from `src/`, or in the PHP container with `docker compose exec php bin/console ...`.
- Run backend tests from the repository root with `docker compose run --rm --no-deps php php bin/phpunit`.
- Generate invoice PDFs through the backend PDF service and return `application/pdf`; do not construct authoritative invoice totals or documents solely in the browser.

There is no frontend lint script currently defined in `frontend/package.json`. Do not report tests as run unless a relevant test command is available and has actually been executed.
