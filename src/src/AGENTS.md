# Backend Application Instructions

This directory contains the Symfony 8 application code (PHP 8.4): API controllers, request DTOs, Doctrine entities, repositories, and focused application services. Routes are declared with Symfony `#[Route]` attributes. The API returns JSON and persists CRM data to PostgreSQL through Doctrine ORM; invoice PDF downloads return `application/pdf`.

## Development

- backend is run in Docker container

## Structure And Conventions

- Think hard.
- Follow applicable PHP-FIG PSR recommendations, especially PSR-12 coding style and PSR-4 autoloading, and use Symfony 8 development best practices and conventions for application code.
- Apply object-oriented design throughout application code: keep responsibilities cohesive, encapsulate state and business rules, use constructor dependency injection, and avoid procedural or static business logic. Keep controllers thin; put reusable use-case logic in services when it grows beyond HTTP coordination. Follow the existing Symfony and Doctrine patterns without adding abstractions that do not clarify a real responsibility.
- Keep HTTP concerns in `Controller/`, typed data-transfer objects and validation constraints in `Dto/`, persistence mappings and domain invariants in `Entity/`, entity queries in `Repository/`, and cross-cutting use cases in services. Inject dependencies through constructors.
- Use Doctrine ORM and repositories for all runtime database reads and writes. Do not use raw SQL, direct PDO, or another persistence mechanism in application code. SQL in Doctrine migration classes is expected; keep schema changes versioned under `src/migrations/`.
- Use DTO objects for data exchanged across application-layer boundaries and for external API input/output. Controllers should map validated request DTOs into application operations and map results into response DTOs; do not pass request/response associative arrays between layers or expose Doctrine entities directly as API responses. Keep entities as persistence/domain objects, not transport objects.
- Keep response serialization and request field names compatible with the frontend. Current resources are clients, news items, projects, and tasks; timestamps are serialized in ISO 8601 form using snake_case response keys.
- For create/update actions, preserve the existing input-validation and status-code patterns (`400` for invalid input, `404` for missing records, `201` for creation, and `204` for deletion). Keep partial-update behavior consistent with the specific controller.
- Authentication is stateless JWT for `/api`; login is public. Authorization is resource-specific: projects/tasks require `ROLE_USER`, client writes require `ROLE_ADMIN`, and news-item edits/deletes are restricted to the owning user. Inspect the controller attributes and `config/packages/security.yaml` before changing these rules.
- When changing entity mappings, add or update a Doctrine migration under `src/migrations/`; do not rely on schema auto-update. Keep migration changes consistent with the entity and existing database history. Use Doctrine migrations for schema changes, not as a way to perform unversioned application data access.
- Audit-sensitive client operations use the `monolog.logger.audit` logger. Preserve relevant audit events and avoid logging credentials, JWTs, or other secrets.
- Invoices are currently editable drafts linked to a client and project, with task and additional-cost line snapshots, explicit EUR/GBP/USD currency, server-calculated integer minor-unit totals, and PDF export through Dompdf. Add all invoice schema changes through Doctrine migrations and keep PDF output escaped and independent of remote resources.
- Never use floating-point arithmetic for invoice amounts. Validate decimal strings at the API boundary, calculate in integer minor units, and require a currency when filtering by amount. Do not imply that a draft PDF is a legally issued invoice.
- Do not put frontend code or container configuration in this directory; those have separate scopes in `frontend/` and the repository root.

## Testing And Verification

Automated tests are required for all project-owned backend behavior. Aim for complete coverage of production code, including successful flows, validation failures, missing records, authorization, and edge cases. Add regression tests with every behavior change; use unit tests for isolated rules and integration/API tests for persistence, HTTP, and security behavior. Use a coverage report to find and close gaps, and do not claim full coverage without a report.

PHPUnit 12 is configured with unit and SQLite-backed integration tests under `tests/`; run them with `php bin/phpunit` from the backend project directory, or `docker compose run --rm --no-deps php php bin/phpunit` from the repository root. Coverage reporting is not configured yet, so do not claim complete measured coverage. Do not describe syntax checks as test coverage.

Run Symfony commands from the backend project directory (`src/` at the repository root), for example `php bin/console debug:router` or `php bin/console doctrine:migrations:diff`. In the containerized environment, use `docker compose exec php bin/console ...` from the repository root. Run `php -l path/to/changed.php` for a quick syntax check, in addition to relevant automated tests. Do not claim a migration was applied unless it was explicitly executed against the intended database.
