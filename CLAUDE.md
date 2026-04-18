# CLAUDE.md — MoodleManagement v2.0

> Contexto completo del plugin para sesiones de desarrollo con Claude Code (u otros agentes). Este archivo es el punto de entrada: leerlo primero.
>
> **Última actualización**: 2026-04-18 · tras cierre de Fases 15/16/17/18/19 del plan post-audit.

---

## 1. Visión general

- **Producto**: Plugin FacturaScripts que integra bidireccionalmente con una (o varias) instancia Moodle LMS.
- **Caso de uso**: producto facturado en FS = curso matriculado en Moodle; flujo "factura pagada → matrícula automática → certificado PDF".
- **Estado**: **v2.0 release-ready**. 14 fases originales + 5 fases post-audit (15/16/17/18/19) cerradas. Los 6 bloqueantes CRÍTICOS + los 18 HIGH del audit 2026-04-17 están resueltos; los 38 MEDIUM trabajados (12 fixed + 15 verificados + 11 diferidos con rationale); los LOW/INFO (30) + 4 migrados completados o documentados.
- **Gates de estabilidad verificados (Fase 19)**: PHPCS 0 errors, PHPStan level 5 (285 baseline, 0 new), PHP-CS-Fixer 0 diffs, suite 168/168 verde en PHP 8.0.30 / 8.1.34 / 8.2.30.
- **Ubicación**: `//wsl.localhost/Ubuntu/var/www/facturascripts/facturascripts/Plugins/MoodleManagement`
- **Repo git**: el plugin es su **propio** repositorio git (no monorepo con FS core).
- **Rama actual**: `v2.0-dev` (integración) · `v2.0/fase-19-stability-gates` (trabajo activo).
- **Último commit técnico**: `8d3b29d chore(v2.0): Fase 19 wave 1 - PHPCS/PHPStan/cs-fixer clean + INT-06/FE-12 + BE-04 rev2 [#AUDIT-F19]`.

---

## 2. Stack técnico

| Capa | Tecnología | Versión mínima | Notas |
|------|-----------|----------------|-------|
| Runtime | PHP | 8.0 | CI testa 8.0/8.1/8.2. FS core vendored phpunit 11 requiere 8.2 para run local. |
| Framework | FacturaScripts | 2025.6 | Declarado en `facturascripts.ini::min_version`. STD-04 sospecha que realmente necesita 2025.8+. |
| LMS target | Moodle | 4.1 LTS | `MoodleClient::MIN_MOODLE_RELEASE = '4.1'`. 4.0 marcado `unsupported` automático. |
| DB | MySQL 5.7+ · MariaDB 10.4+ · PostgreSQL 13+ | | SQLite solo para tests (phpunit in-memory). |
| Frontend | Bootstrap 5 · Chart.js 4.x · jQuery 3 | | F3.1 migró Chart 2→4. Requiere Safari 16+. |
| Tooling dev | PHPUnit ^9.6 · PHPStan ^1.10 level 5 · PHPCS PSR-12 · PHP-CS-Fixer ^3.40 | | CI matrix: `.github/workflows/ci.yml`. |
| Container dev | `php:8.2-cli` via Docker | | Mantenedor sin PHP nativo; tests se corren en container. |

---

## 3. Arquitectura

### 3.1 Capas

```
┌─────────────────────────────────────────────────────┐
│  HTTP boundary                                       │
│    · Controller/Edit*.php, List*.php                 │
│    · Controller/ApiMoodleWebhook.php (público, HMAC) │
│    · Controller/MoodleCertificatePdf.php (signed URL)│
├─────────────────────────────────────────────────────┤
│  Security primitives (Lib/Security/*)                │
│    HtmlSanitizer · CsvEscaper · SignedUrl            │
│    RateLimiter · CspHeader · TokenCipher · IpValidator│
├─────────────────────────────────────────────────────┤
│  Domain                                              │
│    · Lib/Moodle/ (HttpClient, BoundClient, Api/*,    │
│      Contract/*, RetryPolicy, CircuitBreaker)        │
│    · Lib/Webhook/ (Verifier, Dispatcher, Handler/*)  │
│    · Lib/Matching/UserMatcher                        │
│    · Lib/Cron/Lock                                   │
│    · Lib/Migration/SchemaMigrator                    │
│    · Lib/Logger/{BufferedLogger,PiiMasker}           │
│    · Lib/Model/SoftDeleteTrait                       │
│    · Lib/Enum/* (5 status enums)                     │
│    · Lib/Exception/* (6 typed exceptions)            │
├─────────────────────────────────────────────────────┤
│  Legacy (pending migration in v2.1)                  │
│    · Lib/MoodleClient.php (~1993 LOC, god class)    │
│       - 100+ static methods aún invocados por        │
│         workers y cron directamente                  │
├─────────────────────────────────────────────────────┤
│  Persistence                                         │
│    · Model/* (ActiveRecord vía FS ModelClass)        │
│    · Table/* (XML schema)                            │
│    · Update/v2_0.php (17 migrations idempotentes)    │
├─────────────────────────────────────────────────────┤
│  Async                                               │
│    · Worker/* (6 workers · WorkQueue)                │
│    · Cron.php (7 jobs, cooperative locking)          │
└─────────────────────────────────────────────────────┘
```

### 3.2 Diagramas Mermaid

Ver `README.md` §Arquitectura (dos diagramas):

1. **Flujo principal** — FS FacturaCliente → WorkQueue → Moodle WS → PDF/Email.
2. **Capas v2.0** — Security primitives + Webhook pipeline + Cron jobs + Observability.

### 3.3 Data flow resumido

1. Admin crea producto con `moodle_course=true` + `MoodleCourseMap` linked.
2. Cliente paga factura → `FacturaCliente.Update` event.
3. `EnrolmentWorker` (WorkQueue) resuelve contacto, llama `MoodleClient::enrolUsers()`.
4. Estado persiste en `moodle_enrolments`.
5. `progressSync` cron (cada 6h) refresca completion via `CompletionApi`.
6. `ApiMoodleWebhook` (F10.1) recibe eventos Moodle-side → 4 handlers.
7. Certificados emitidos por `BadgeSyncWorker` → `MoodleCertificate` → PDF via `CertificatePdfGenerator`.

---

## 4. Mapa de archivos importantes

### 4.1 Entry points

| Archivo | Propósito |
|---------|-----------|
| `Init.php` | Plugin lifecycle: WorkQueue subscriptions, widget registry, `bootstrapSchema()` (createViews + runMigrations + seed). |
| `Cron.php` | 7 jobs cooperative-locked: healthCheck (1h) · userSync (6h) · courseSync (6h) · reconciliation (1d) · cleanup (1d) · expiryCheck (6h) · progressSync (6h, F10.2). |
| `facturascripts.ini` | Manifest: `version=2.0` · `min_version=2025.6` · `min_php=8.0`. |

### 4.2 Seguridad (crítico revisar en cada PR)

| Archivo | Función |
|---------|---------|
| `Lib/Security/TokenCipher.php` | AES-256-GCM + HKDF para token Moodle + webhook_secret at rest. **SEC-01** ✅ fail-closed (F15.1). **DB-01** ✅ migration transaccional (F16.14). |
| `Lib/Security/IpValidator.php` | SSRF guard. Lista bloqueada de RFC1918 + loopback + link-local + CGNAT + multicast + IPv6 equivalents. |
| `Lib/Security/HtmlSanitizer.php` | Allowlist HTML para contenido Moodle. **SEC-09** ✅ strtolower scheme (F17.1). **FE-03** ✅ `toPlainText()` DOM-based (F16.17). |
| `Lib/Security/SignedUrl.php` | HMAC-SHA256 URLs para certificados anónimos. **SEC-05** ✅ versionado `v=N` + fail-closed (F16.2). |
| `Lib/Security/SignedPayload.php` | **NEW F16.4** HMAC wrapper para cookies/forms tamper-evident (SEC-07). |
| `Lib/Security/RateLimiter.php` | Tools::cache-backed, fixed window. **SEC-11** ✅ SHA-256 hash (F17.3). |
| `Lib/Security/CsvEscaper.php` | Prefijo `'` para `=@+\t\r` (formula injection). |
| `Lib/Security/CspHeader.php` | **SEC-12** ✅ aplicado en PDF + Dashboard + Wizard (F17.4). |
| `Lib/View/JsonForScript.php` | **NEW F15.4** JSON_HEX_* encoder para `<script>` interpolation (FE-01). |
| `Lib/Webhook/PayloadValidator.php` | **NEW F16.16** Schema validator por event type (INT-01). |
| `Lib/WorkQueue/IdempotencyGuard.php` | **NEW F16.8** Cache-backed dedup para WorkEvents (BE-03). |
| `Lib/Contact/ContactTimestampUpdater.php` | **NEW F16.10** Helper extraído del raw SQL en EditContacto (BE-07). |

### 4.3 Integración Moodle

| Archivo | Función |
|---------|---------|
| `Lib/MoodleClient.php` | God class ~2000 LOC. 100+ static methods. `@deprecated since 2.0` docstring → facades. **BE-02** ✅ wrapped en `CircuitBreaker::allow` + `RetryPolicy::execute` (F16.7). **BE-05** ✅ partial-failure surface (F16.9). **SEC-03** ✅ rechaza `http://` en prod (F15.3). **SEC-08** ✅ Authorization: Bearer header (F16.5). |
| `Lib/Moodle/HttpClient.php` | Transporte HTTP bajo-nivel para los facades. |
| `Lib/Moodle/BoundClient.php` | Fluent API `MoodleClient::forInstance($i)->users()->get()`. |
| `Lib/Moodle/Api/*.php` | 7 facades: UserApi, CourseApi, EnrolmentApi, CohortApi, BadgeApi, FileApi, CompletionApi. |
| `Lib/Moodle/Contract/*.php` | Interfaces: HttpClient, UserApi, CourseApi, CompletionApi. |
| `Lib/Moodle/UsernameGenerator.php` | `name_based` vs `random_alias` strategies (F10.5). **INT-06** ✅ pre-insert probe para random_alias con retry (F19). |
| `Lib/Moodle/ConflictResolver.php` | Timestamp-based FS vs Moodle conflict resolution. |
| `Lib/Moodle/RetryPolicy.php` | ✅ **Wired en F16.7** — usado por `MoodleClient::dispatchWithRetry`. |
| `Lib/Moodle/CircuitBreaker.php` | ✅ **Wired en F16.7** — gate per-instance en `MoodleClient::callApi`. |

### 4.4 Webhooks (F10.1)

| Archivo | Función |
|---------|---------|
| `Controller/ApiMoodleWebhook.php` | Endpoint público. Verify → audit → dispatch. |
| `Lib/Webhook/WebhookVerifier.php` | HMAC-SHA256 + timestamp window 5min + nonce replay cache 1h. |
| `Lib/Webhook/WebhookDispatcher.php` | Routing por `X-MM-Event` header. |
| `Lib/Webhook/Handler/EnrolmentCreatedHandler.php` | Mirror Moodle-side enrol → `moodle_enrolments`. |
| `Lib/Webhook/Handler/EnrolmentDeletedHandler.php` | Flip `status=cancelled` (nunca physical delete). |
| `Lib/Webhook/Handler/CourseCompletedHandler.php` | Stamp `completion_date` + `final_grade`. |
| `Lib/Webhook/Handler/UserUpdatedHandler.php` | Refresh `moodle_user_map.moodle_username` / email. |
| `scripts/webhook-bridge-example.php` | Referencia CLI dependency-free para bridges Moodle-side. |

### 4.5 Workers y cron

| Worker | Evento suscrito | Subida Fase 6 |
|--------|-----------------|---------------|
| `EnrolmentWorker` | `Model.FacturaCliente.Update` | Transactional (F6.6). |
| `PreEnrolmentWorker` | Presupuesto/Pedido Update + `Linea*.Delete` | F6.10 line-delete + F6.2 skip-cache. |
| `ContactSyncWorker` | `Model.Contacto.Update` | F6.8 debounce 30s. |
| `ContactDeleteWorker` | `Model.Contacto.Delete` | F7.15 suspend-only (no physical delete Moodle). |
| `BadgeSyncWorker` | `Model.MoodleUserMap.Insert` | **F6.1 cut cascade** — rebindado de .Save a .Insert. |
| `OnboardingWorker` | `Model.MoodleUserMap.Insert` | F6.5 `loadMapWithRetry` 3×. |

### 4.6 Modelos y schema

| Tabla | Columnas añadidas en v2.0 |
|-------|----------------------------|
| `moodle_instances` | `webhook_secret` (F10.1) · `username_strategy` (F10.5) · `token` widened + encrypted (F5.11+F5.12). |
| `moodle_user_map` | `badge_sync_needed` (F6.1) · `deleted_at` (F5.20 + F13 SoftDeleteTrait). |
| `moodle_enrolments` | `idfactura_archived` (F5.5) · `progress_percent` + `completed_modules` + `total_modules` + `last_activity_at` + `progress_fetched_at` + `completion_date` + `final_grade` (F10.2) · `deleted_at` (F5.20). |
| `moodle_cohorts` | `deleted_at` (F5.20) · FK codgrupo (F5.7) · timestamps (F5.22). |
| `moodle_audit_log` | **Nueva** (F4.4). Append-only. Retention policy pendiente (DB-05). |
| `moodle_webhook_log` | **Nueva** (F10.1). Append-only. Retention pendiente (DB-05). |
| `moodle_schema_version` | **Nueva** (F5.1). Ledger de migrations aplicadas. |

### 4.7 Tests

- `Test/Unit/*.php` — 11 archivos, testean puros: UsernameGenerator, ConflictResolver, UserMatcher, PiiMasker, CsvEscaper, HtmlSanitizer, IpValidator, TokenCipher, SignedUrl, Enums.
- `Test/Integration/*.php` — 4 archivos: CertificatePdfGeneratorTest (path traversal), MoodleClientRejectsSsrfTest, WorkerCascadeGuardTest (source-level), ControllerAuthRegressionTest.
- `Test/bootstrap.php` — Carga plugin vendor primero (CI) luego FS core vendor (dev local). Dual-loading caveat documentado.
- `Test/Fixtures/` — vacío por ahora.

**Resultado Fase 14** (Docker php:8.2-cli + phpunit 11.5.55 desde FS core vendor):
```
Tests: 97, Assertions: 149, Errors: 0, Failures: 0, Deprecations: 16
```
Las 16 deprecations son phpunit 11→12 cosmetics (metadata en phpdoc vs attributes).

### 4.8 Documentación

| Archivo | Propósito |
|---------|-----------|
| `README.md` | Descripción bilingüe ES/EN. Incluye 2 diagramas Mermaid. |
| `CHANGELOG.md` | Keep-a-Changelog. `[2.0.0] 2026-04-17` release-grade. |
| `UPGRADE.md` | Steps v1→v2, 17 breaking changes, 7 known issues, rollback drill. |
| `SECURITY.md` | Posture table, threat model 5 zonas, crypto details, PGP placeholder. |
| `CONTRIBUTING.md` | Branching, commits, review checklist 11 items. |
| `docs/V2.0-ACTION-PLAN.md` | Plan master 14 fases. |
| `docs/V2.0-TASK-CHECKLIST.md` | **172/172** tasks trackables verdes. |
| `docs/TROUBLESHOOTING.md` | Symptom → cause → fix (7 secciones). |
| `docs/EVENTS.md` | Contrato público de eventos. |
| `docs/SUPPORTED-VERSIONS.md` | Matrix FS × PHP × Moodle × DB × navegador. |
| `docs/PHP-INI-HARDENING.md` | 8-section runbook php.ini. |
| `docs/DEAD-CODE-AUDIT.md` | Baseline + tooling. |
| `docs/QA-SMOKE-TEST.md` | Checklist 8 pantallas. |
| `docs/QA-V1-REGRESSION.md` | v1.x → v2.0 regression drill. |
| `docs/QA-PERFORMANCE.md` | Hard targets + harness. |
| `docs/QA-OWASP-ZAP.md` | Scan automation plan. |
| `docs/QA-LINT-ANALYSE.md` | Combined tooling gate. |
| `docs/RELEASE-CHECKLIST.md` | 9-section release gate. |
| `docs/V2.1-BACKLOG.md` | Deferred items del plan. |
| `.docs-dev/BRAINSTORMING.md` | Historia de product brainstorming. **Export-ignored** del ZIP via `.gitattributes`. |

### 4.9 CI / Tooling

| Archivo | Propósito |
|---------|-----------|
| `.github/workflows/ci.yml` | Matrix PHP 8.0/8.1/8.2 — lint + analyse + test. Clona FS core en runtime (fix F14). |
| `.github/workflows/benchmark.yml` | Manual dispatch benchmark (D-07). |
| `phpstan.neon.dist` | Level 5 + baseline vacío. |
| `phpstan-baseline.neon` | Empty at v2.0 release. Strict-monotonicity rule. |
| `phpcs.xml.dist` | PSR-12. Scans `scripts/` desde F12.6. |
| `phpunit.xml.dist` | Schema 9.6 (CI-compat). `--` en comentarios removido en F14. |
| `.php-cs-fixer.dist.php` | PSR-12 + short arrays. |
| `qa/zap-baseline.yaml` | OWASP ZAP automation plan (F12.4). |
| `scripts/benchmark.php` | Seed/run/clean harness (F12.3). |
| `scripts/webhook-bridge-example.php` | Reference bridge Moodle-side (D-01). |
| `.gitattributes` | `export-ignore`: `.docs-dev/`, algunos `docs/*`, `Test/`, CI config. Slim release ZIP. |

---

## 5. Convenciones

### 5.1 Código

- **PHP**: strict PSR-12. `declare(strict_types=1);` obligatorio en nuevos ficheros `Lib/*`. **27 controladores aún sin strict** (STD-01 pendiente).
- **Clases utility**: `final class X { private function __construct() {} }` + métodos `static`. No instanciables.
- **Namespace**: PSR-4 `FacturaScripts\Plugins\MoodleManagement\` → `Plugins/MoodleManagement/`.
- **`@since 2.0`** obligatorio en clases/métodos nuevos.
- **Excepciones**: preferir `Lib/Exception/*` sobre retornar `['exception' => ...]` arrays (ARCH-02, migración pendiente).
- **Enums**: usar `EnrolmentStatus::PENDING` no `'pending'` literal (ARCH-04, adopción incompleta).
- **Magic numbers**: constantes de clase, nunca literales en el cuerpo (F1.2).

### 5.2 Git

- **Rama base**: `main` (stable). `v2.0-dev` (integración). `v2.0/fase-NN-slug` (trabajo por fase).
- **Merges**: fast-forward only (`git update-ref refs/heads/v2.0-dev refs/heads/v2.0/fase-NN-slug`). Sin merge commits.
- **Tags**: `pre-fase-NN` al cerrar cada fase. `v2.0.0` aplicado por release engineer siguiendo `RELEASE-CHECKLIST.md`.
- **Commits**: Conventional Commits + audit ref:
  ```
  <type>(<scope>): <subject> [#AUDIT-FX.Y]

  <body — why, not what>

  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  ```
  Tipos: `feat` · `fix` · `refactor` · `security` · `perf` · `docs` · `test` · `chore` · `ci` · `style`.

### 5.3 Review

11 items en `CONTRIBUTING.md §8`. Los más rompedores:
- Sin `|raw` en Twig salvo justificación documentada.
- Sin llamadas directas a `MoodleClient` en código nuevo (usar `Lib/Moodle/Api/*`).
- Cambios de schema → migration idempotente en `Update/v2_0.php` + entry en base XML.
- Cambios de eventos → documentar en `docs/EVENTS.md`.

### 5.4 Tests

- Unit: puros, sin DB, sin HTTP. Usar `ReflectionClass::newInstanceWithoutConstructor()` para modelos FS que tocan DbUpdater al construir.
- Integration: SQLite in-memory + reflexión sobre código fuente para asserts estructurales.
- Data providers: `public static function` (requisito PHPUnit 10+).

### 5.5 Trigger phrases

Frases configuradas en `MEMORY.md` del usuario:
- `"Iniciar plan v2.0 MoodleManagement"` → arranca la siguiente tarea del plan.
- `"Ejecutar V2.0-ACTION-PLAN.md — siguiente tarea pendiente"` → explícito.
- `"continuar"` → avanza a la siguiente fase tras cierre de la anterior.

---

## 6. Decisiones arquitectónicas clave (ADR-lite)

### ADR-01 · AES-256-GCM + HKDF para token cipher

- **Contexto**: Tokens Moodle en plaintext (audit CRÍTICO §4.6).
- **Decisión**: F5.12 — `Lib/Security/TokenCipher` con AES-256-GCM. Clave derivada por HKDF-SHA256 desde `FS_COOKIES_EXPIRE`, info label `mm/token-cipher/v1`. Wire format: `mm2g:<base64url(iv|tag|ct)>`.
- **Alternativas rechazadas**: libsodium (peor compat con PHP 8.0 hosts antiguos); clave independiente en config (otro secret a rotar).
- **Trade-offs**: Si `FS_COOKIES_EXPIRE` rota, todos los tokens se invalidan simultáneamente (SEC-05 documenta).

### ADR-02 · WorkQueue cascade cut F6.1

- **Contexto**: BadgeSyncWorker suscrito a `Model.MoodleUserMap.Save` → worker hace `$map->save()` → re-dispara el mismo evento → loop.
- **Decisión**: Rebindar a `.Insert`. Nuevo flag `badge_sync_needed` permite re-syncs manuales futuros (pendiente wiring UI, INT-09).
- **Guard**: `Test/Integration/WorkerCascadeGuardTest` assert source-level que el binding no regresa.

### ADR-03 · Migration framework propietario vs doctrine/migrations

- **Decisión**: `Lib/Migration/SchemaMigrator` propio, idempotente, backed por tabla `moodle_schema_version`. Cada migration es un closure en `Update/v2_0.php`.
- **Rationale**: Doctrine añade peso + dependencia no presente en FS core. Migrator propio ~200 LOC, cubre MySQL + Postgres, usa `information_schema` introspection.

### ADR-04 · SoftDeleteTrait sin model events

- **Contexto**: F10.4 requería papelera. FS model events auto-disparan `Model.<X>.Update` al save → cascadas.
- **Decisión**: Raw UPDATE en `Lib/Model/SoftDeleteTrait::delete()` para evitar emitir Update. Similar pattern al F6.1 cascade cut.
- **Limitación**: Asume PK simple (DB-02 tracker).

### ADR-05 · Webhook envelope HMAC + nonce + timestamp

- **Decisión**: `X-MM-Signature: sha256=<hex>` sobre raw body, `X-MM-Timestamp` con ventana 5min, `X-MM-Nonce` opaco ≤128 chars con replay cache 1h en `Tools::cache()`.
- **Rationale**: Evita reimplementar OAuth/JWT. Ventana 5min protege vs clock skew; nonce protege vs replay dentro de ventana.
- **Trade-offs**: Secret compartido per-instance vs key asimétrica. Secret es más simple de provisionar en Moodle.

### ADR-06 · BRAINSTORMING.md en .docs-dev/

- **Decisión**: Historia de brainstorming movida fuera de `docs/` + `export-ignore` en `.gitattributes`. Queda versionada en el repo pero no viaja en el ZIP del release.
- **Rationale**: Operador no necesita ver product design history; reduce peso del artefacto.

---

## 7. Resumen de las 14 fases

| # | Nombre | Tareas | Branch | Hitos |
|---|--------|--------|--------|-------|
| 0 | Setup tooling/docs | 9 | `fase-00-setup` | `.editorconfig`, phpcs/phpstan/phpunit dist, plan master. |
| 1 | Quick wins | 12 | `fase-01-quick-wins` | Enums, exceptions hierarchy, magic numbers → const, Mermaid diagram. |
| 2 | Seguridad básica | 11 | `fase-02-seguridad-basica` | 4 XSS críticos cerrados, Lib/Security/* (7 clases), cookie hardening. |
| 3 | Frontend | 14 | `fase-03-frontend` | Chart.js 2→4, `data-mm-*` delegation, backoff polling, debounce. |
| 4 | AuthZ & ownership | 4 | `fase-04-authz` | IDOR certificados, Audit::record, rate limits. |
| 5 | DB & migrations | 23 | `fase-05-database` | SchemaMigrator, 17 migrations, TokenCipher, FK indexes, utf8mb4. |
| 6 | WorkQueue/crons | 13 | `fase-06-workqueue` | Cascade cut F6.1/F6.2, pagination, cooperative locks, RetryPolicy+CircuitBreaker (ambos **dead code BE-02**). |
| 7 | Moodle hardening | 21 | `fase-07-moodle-hardening` | SSRF, Bearer auth downloads, path traversal, Moodle 4.1 min. |
| 8 | Refactor arquitectónico | 12 | `fase-08-refactor` | MoodleClient split a Lib/Moodle/Api/* + Contract/*. **Facades dead code hasta migrar callers**. |
| 9 | Tests automatizados | 9 | `fase-09-tests` | 10 Unit + 4 Integration + CI matrix. |
| 10 | Features avanzadas | 10 | `fase-10-features-avanzadas` | Webhook receiver F10.1, progress sync F10.2, audit/papelera UIs, random_alias, BufferedLogger. |
| 11 | Docs final | 10 | `fase-11-docs-final` | Known Limitations, TROUBLESHOOTING, EVENTS, UPGRADE/SECURITY/CONTRIBUTING final. |
| 12 | QA & release | 7 | `fase-12-qa-release` | Smoke test checklist, v1 regression, benchmark harness, OWASP ZAP plan, RELEASE-CHECKLIST. |
| 13 | Discovered | 8 | `fase-13-discovered` | SoftDeleteTrait wiring, CompletionApiInterface, CohortLifecycle enum, overview cache, webhook bridge example, V2.1-BACKLOG. |
| 14 | PHPUnit greenify | 9 | `fase-14-phpunit-greenify` | 97/97 tests green + 3 bugs reales encontrados (HtmlSanitizer exec tag leak, PiiMasker ccTLD, buildUsernameCandidate `.` fallback). |

**Total tareas**: 172/172 ✅. **16/16 CRÍTICOS del audit original cerrados**.

---

## 8. Resultado de la 2ª iteración de auditoría (91 findings)

Realizada el 2026-04-17 por 5 agentes paralelos (Security, DB, Frontend, Integration, Architecture) más review directo. Detecta lo que el plan original **no cubrió** o **reintrodujo**.

### 8.1 Distribución por severidad

| Severidad | Cantidad | Destino |
|-----------|----------|---------|
| 🔴 CRÍTICO | **5** | **Bloqueantes v2.0.0** — aplicar antes del tag. |
| 🟠 ALTO | **18** | v2.0.1 (semana post-release). |
| 🟡 MEDIO | **38** | Tracker v2.1. |
| 🔵 BAJO | **22** | Backlog informativo. |
| ⚪ INFO | **8** | Polish / cosmético. |
| **Total** | **91** | |

### 8.2 Los 6 bloqueantes v2.0.0 (must-fix)

| ID | Archivo | Problema | Fix | Horas |
|----|---------|----------|-----|-------|
| **SEC-01** | `Lib/Security/TokenCipher.php:189-197` | Fallback `'mm-fallback-insecure-secret'` hardcoded si falta `FS_COOKIES_EXPIRE`. Tokens recuperables offline. | Fail-closed con `throw RuntimeException`. | 0.5 |
| **SEC-02** | `Controller/ListMoodleAuditLog.php` | No verifica `$user->admin` explícitamente. Non-admin puede enumerar IPs/acciones. | Guard `if (empty($user->admin)) return 403` en `privateCore`. Aplicar a `ListMoodleTrash` también. | 0.5 |
| **SEC-03** | `Lib/MoodleClient.php:110` | Acepta `http://` para instancia; token va en body sobre HTTP plano. | Rechazar non-HTTPS salvo `environment='development'`. | 0.5 |
| **FE-01** | `View/MoodleDashboard.html.twig:227,230,270,284,322,324` | `json_encode \| raw` sin flags HEX. `</script>` en label rompe contexto. | Serializar en PHP con `JSON_HEX_TAG\|JSON_HEX_APOS\|JSON_HEX_QUOT\|JSON_HEX_AMP`. | 1.0 |
| **FE-02** | `View/Tab/CourseContent.html.twig:199,314,586-598` | Idem FE-01 en payloads de módulos Moodle. | Idem + grep linter CI. | 2.0 |
| **BE-04** | `Cron.php:509-534` | Reconciliation marca `unenrolled` en masa si `getEnrolledUsers` devuelve vacío por error silencioso. | Distinguir empty-real vs empty-por-error con health probe. | 2.0 |

**Total**: ~7 horas de trabajo concentrado.

### 8.3 Top-10 HIGH (v2.0.1)

1. **BE-02** `Lib/Moodle/RetryPolicy.php` + `CircuitBreaker.php` son **dead code**. Workers fallan a la primera. → Integrar en `MoodleClient::callApi` (~6 h).
2. **BE-03** `EnrolmentWorker` + `OnboardingWorker` no son idempotentes. Retry = duplicados (~4 h).
3. **BE-05** `callApi` no propaga fallos parciales en bulk WS (~4 h).
4. **BE-01** 27 controladores sin `declare(strict_types=1)` (~3 h barrido).
5. **SEC-04** `WebhookVerifier::resolveSecret` fallback a plaintext en error de decrypt = bypass HMAC (~0.5 h).
6. **SEC-05** `SignedUrl` sin versionado de clave, rotación = downtime (~2 h).
7. **SEC-06** `MoodleCertificatePdf::isAuthorised` solo chequea `idcontactofact` (~1 h).
8. **SEC-07** Wizard cookie sin firma HMAC = tamper-able (~2 h).
9. **DB-01** `TokenCipher::encryptExistingRows` sin transacción explícita (~1 h).
10. **DB-03** F5.11→F5.12 migration ordering no forzado (~1 h).

### 8.4 Findings por categoría

**Arquitectura** (11): ARCH-01 god class MoodleClient · ARCH-02 dos sistemas de error · ARCH-03 controllers 1000+ LOC · ARCH-04 enums no adoptados · ARCH-05 ProductoMoodleDecorator mal nombrado · ARCH-06 BoundClient churn · ARCH-07 extensions poco documentadas · ARCH-11 BoundClient cache.

**Backend** (10): BE-01 strict_types · BE-02 retry/breaker dead · BE-03 idempotencia workers · BE-04 reconciliation mass unenrol · BE-05 bulk partial fails · BE-06 health token quarantine · BE-07 SQL crudo EditContacto · BE-08 N+1 cleanup · BE-09 BufferedLogger unbounded · BE-10 Trash switch silencioso.

**Frontend** (15): FE-01/02 JSON escape · FE-03 striptags · FE-04 innerHTML helpers · FE-05 scheme case · FE-06 chat error handler · FE-07 dashboard TTL UX · FE-08 asset cache-busting · FE-09 escapeAttr · FE-10 confirm dialog mix · FE-11 i18n en JS · FE-12 textarea maxlength · FE-13 Chart.js fallback · FE-14 label notes · FE-15 search spinner.

**Seguridad** (14): SEC-01..14. Destacan los 3 bloqueantes + SignedUrl versioning + HtmlSanitizer scheme + cookie wizard firma.

**Base de datos** (13): DB-01..13. Destacan F5.12 transaction + SoftDeleteTrait composite PK + F5.11→F5.12 ordering + Trash regex guard + retention logs.

**Integración Moodle** (11): INT-01..11. Destacan payload validator · downloadFile cap log · progressSync force-retry · ContactDelete remap block · DNS rebinding · random_alias probe.

**Rendimiento** (7): PERF-01..07. Principalmente overlap con BE-08/BE-09 + cache coverage generalization + stream JSON.

**Estándares** (10): STD-01..10. strict_types · min_version · final modifiers · phpstan baseline · licenses · translation parity · test coverage.

**Documentación** (6): DOC-01..06. PGP placeholder · worker order · webhook runbook · ARCHITECTURE.md missing · ADRs · wizard storyline.

Detalle completo de los 91 findings con file:line y fix concreto está en la 2ª iteración de auditoría en el transcript de la sesión (2026-04-17).

---

## 9. Cómo correr los tests localmente

### 9.1 Contexto: no hay PHP nativo en Git Bash

El mantenedor trabaja en Windows + WSL sin PHP en el PATH del shell host. Docker está disponible.

### 9.2 Full suite (recomendado)

```bash
# Desde el root del FS (host Windows Git Bash):
wsl.exe -d Ubuntu -- sh -c 'docker run --rm \
    -v /var/www/facturascripts/facturascripts:/work \
    -w /work/Plugins/MoodleManagement \
    php:8.2-cli \
    php /work/vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage --colors=never'
```

Output esperado post-Fase 19:
```
Tests: 168, Assertions: 281, Errors: 0, Failures: 0, Deprecations: 28
```

### 9.3 Solo Unit

Cambiar `--no-coverage --colors=never` por `--testsuite=Unit --no-coverage --colors=never`.

### 9.4 Solo Integration

`--testsuite=Integration --no-coverage --colors=never`.

### 9.5 CI local sim

El workflow `.github/workflows/ci.yml` clona FS core en `/tmp/fs-core` y stagea el plugin. Reproducible localmente:

```bash
wsl.exe -d Ubuntu -- sh -c '
    git clone --depth 1 https://github.com/NeoRazorX/facturascripts.git /tmp/fs-core
    cp -r /var/www/facturascripts/facturascripts/Plugins/MoodleManagement /tmp/fs-core/Plugins/
    cd /tmp/fs-core && composer install --no-dev
    cd /tmp/fs-core/Plugins/MoodleManagement && composer require --dev phpunit/phpunit:^9.6
    vendor/bin/phpunit
'
```

### 9.6 PHPStan + PHPCS

```bash
# dentro del contenedor con FS core montado:
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## 10. Proceso de release

Ver `docs/RELEASE-CHECKLIST.md` (9 secciones). Resumen:

1. Aplicar los 6 bloqueantes v2.0.0 (SEC-01/02/03, FE-01/02, BE-04).
2. `vendor/bin/phpunit` verde (ya en 97/97).
3. Smoke test según `docs/QA-SMOKE-TEST.md` sobre staging.
4. v1 regression según `docs/QA-V1-REGRESSION.md`.
5. OWASP ZAP scan según `qa/zap-baseline.yaml`. 0 CRIT/HIGH required.
6. Publicar PGP key real en `https://diegomonroydev.com/.well-known/pgp-key.asc` + actualizar fingerprint en `SECURITY.md`.
7. `git tag -a -s v2.0.0 -m "MoodleManagement v2.0.0..."` + push.
8. `git archive --format=zip --prefix=MoodleManagement/ v2.0.0` — verify no `.docs-dev/` ni `Test/` ni CI en el ZIP.
9. Publish release notes + SHA-256 del ZIP.
10. Merge `v2.0-dev` → `main` + abrir `v2.1-dev`.

---

## 11. Cosas pendientes (priorizadas) — actualizado 2026-04-18

### 11.1 BLOQUEANTES v2.0.0

- [x] **SEC-01** Fail-closed TokenCipher · `393cafc`
- [x] **SEC-02** Explicit admin check ListMoodleAuditLog + ListMoodleTrash · `7be5196`
- [x] **SEC-03** Rechazar HTTP plano en MoodleClient · `80233ad`
- [x] **FE-01** JSON_HEX_* en MoodleDashboard · `1cc75ae`
- [x] **FE-02** JSON_HEX_* en CourseContent + linter CI · `1cc75ae`
- [x] **BE-04** Reconciliation health-probe guard · `12e1c2d` + rev 2 en F19

### 11.2 HIGH — TODOS CERRADOS EN FASE 16

- [x] **BE-01** declare(strict_types=1) en 33 ficheros · `573bfb2`
- [x] **BE-02** Wire RetryPolicy + CircuitBreaker en callApi · `273ccee`
- [x] **BE-03** IdempotencyGuard wired en 2 workers · `7a87d56`
- [x] **BE-05** callApi partial-bulk failures · `8e25643`
- [x] **BE-07** ContactTimestampUpdater · `6e6cdee`
- [x] **BE-08** Orphan cleanup single-SQL DELETE · `cb2fc9b`
- [x] **BE-09** BufferedLogger hard limit · `6e6cdee`
- [x] **BE-10** Trash tableOf throws InvalidArgumentException · `6e6cdee`
- [x] **SEC-04** Webhook resolveSecret fail-closed · `01a1188`
- [x] **SEC-05** SignedUrl key versioning · `7780d42`
- [x] **SEC-06** MoodleCertificatePdf full contact list · `4d3d712`
- [x] **SEC-07** Wizard cookie HMAC (SignedPayload) · `3df0647`
- [x] **SEC-08** Authorization: Bearer header · `840adfa`
- [x] **DB-01** TokenCipher migration transaccional · `2b5a9d3`
- [x] **DB-03** F5.12 runtime precondition · `2b5a9d3`
- [x] **INT-01** PayloadValidator · `b792045`
- [x] **FE-03** HtmlSanitizer::toPlainText DOMDocument · `38dde8d`
- [x] **FE-04** mm-dom-safe.js helpers · `38dde8d`

### 11.3 MEDIUM — FASE 17 (38 items: 12 code-fixed, 15 verified/superseded, 11 deferred)

Ver `docs/V2.0-POST-AUDIT-PLAN.md` §FASE 17 para per-item status.

### 11.4 LOW + INFO + migrados — FASE 18 (34 items: 7 code-fixed, 27 deferred/verified)

Ver `docs/V2.0-POST-AUDIT-PLAN.md` §FASE 18.

### 11.5 Gates de estabilidad — FASE 19 (2026-04-18)

- [x] PHPCS PSR-12 — 0 errors, 0 warnings
- [x] PHPStan level 5 — 0 errors (baseline 285)
- [x] PHP-CS-Fixer — 0 diffs after auto-apply
- [x] Multi-PHP matrix — 168/168 green en 8.0.30 / 8.1.34 / 8.2.30
- [x] INT-06 random_alias probe implementado
- [x] FE-12 maxlength en 4 textareas
- [x] BE-04 rev 2: early-exit tras 3 probes fallidos
- [x] CLAUDE.md actualizado con estado real

### 11.6 Release gates todavía pendientes (ops)

- [ ] **DOC-01** Publicar PGP key real en `.well-known/pgp-key.asc` y actualizar `SECURITY.md`
- [ ] Smoke test sobre staging (`docs/QA-SMOKE-TEST.md`)
- [ ] v1 → v2 regression drill (`docs/QA-V1-REGRESSION.md`)
- [ ] OWASP ZAP scan (`qa/zap-baseline.yaml`) — 0 CRIT/HIGH required
- [ ] Tag `git tag -a -s v2.0.0` + release notes + SHA-256 + git archive

### 11.7 v2.1 scope (`docs/V2.1-BACKLOG.md`)

Tras migrar U1/U4/S3/T4 a v2.0 Fase 18, el backlog v2.1 queda con scope genuinamente nuevo:

- Features: Rename wizard (B1) · wkhtmltopdf renderer (B2) · outbound webhook delivery (B3) · Redis RateLimiter (B4) · cascade-tighter line-delete (B5).
- Tooling: tomasvotruba/unused-public (T1) · FS bootstrap composite action (T2) · psalm (T3).
- UX polish: WCAG 2.1 AA audit (U2) · mobile reflow (U3).
- Schema: partitioning (S1) · FS core FK coordination (S2).
- Observability: Prometheus counters (O1) · BufferedLogger JSON-lines (O2).
- Audit-log SEC-14 chain **verifier tool** (CLI scan + alert on break).

### 11.8 Architectural refactors (v2.1 target)

- **ARCH-01** Migrar workers + cron a `BoundClient::forInstance()` facades. Deprecar static methods de `MoodleClient` con 2 versiones de gracia. Docstring `@deprecated since 2.0` ya colocado en F18.
- **ARCH-02** Decisión sobre error handling: exceptions vs array. Eliminar el patrón descartado.
- **ARCH-03** Extraer lógica de dominio de `EditMoodleCourseMap` / `EditMoodleUserMap` / `MoodleImportWizard` a `Lib/Action/*`.
- **ARCH-04** Adoptar enums en modelos (test() + comparisons).

### 11.9 Tests roadmap

- Target 60% coverage para v2.1.
- Suites nuevas: worker integration (EnrolmentWorker happy path, reconciliation, webhook dispatch E2E), controller integration (EditMoodleCourseMap actions), cron integration.

---

## 12. Contactos y responsabilidades

- **Autor**: Diego Felipe Monroy `<dfelipe.monroyc@gmail.com>`
- **Site**: [diegomonroydev.com](https://diegomonroydev.com)
- **Security disclosure**: `security@moodlemanagement.diegomonroydev.com` (PGP key a publicar en `.well-known/pgp-key.asc` antes de v2.0.0 tag).
- **Repo upstream**: GitHub (owner/moodlemanagement).

---

## 13. Cómo continuar este proyecto en una nueva sesión

1. Leer este `CLAUDE.md` (estás aquí).
2. Leer `docs/V2.0-ACTION-PLAN.md` §5.2 para estado de fases.
3. Leer `docs/V2.0-TASK-CHECKLIST.md` para trackeo granular.
4. Consultar §8 de este doc para los findings de la 2ª iteración.
5. Si el usuario pide continuar: arrancar por §11.1 (bloqueantes v2.0.0).
6. Si el usuario pide trabajar en v2.1: consultar `docs/V2.1-BACKLOG.md`.
7. Branch strategy: crear `v2.0/fase-15-post-audit-fixes` (o equivalente) desde `v2.0-dev`. Fast-forward al cerrar.

### 13.1 Estado actual (2026-04-18)

- Rama activa: `v2.0/fase-19-stability-gates`.
- HEAD: `8d3b29d chore(v2.0): Fase 19 wave 1 - PHPCS/PHPStan/cs-fixer clean + INT-06/FE-12 + BE-04 rev2`.
- `v2.0-dev` se fast-forwarded al cerrar cada fase; pendiente el último FF al cerrar F19 (después de commits doc).
- Tags disponibles: `pre-fase-01..18` + `pre-v2.0.0` + `pre-v2.0.0-final`.
- `v2.0.0` **NO APLICADO**. Release gates operativos pendientes (§11.6): PGP, smoke, v1 regression, OWASP ZAP.
- Suite: **168 tests / 281 assertions green** en PHP 8.0 + 8.1 + 8.2.

### 13.2 Señales de alerta

- Si tests dan <168 → Fase 14-19 se ha regresado; investigar qué fix tocó.
- Si `phpstan-baseline.neon` crece por encima de 285 → strict-monotonicity violation; requiere review en PR.
- Si PHPCS summary muestra cualquier error → break del gate (debería estar siempre en 0).
- Si aparece un CRÍTICO/HIGH nuevo que no esté documentado aquí → actualizar §8 + §11 y abrir fase dedicada.
- Si se aplica un cambio al `MoodleClient.php` (god class con `@deprecated since 2.0`), ARCH-01 debe ser considerado: se está pagando deuda o añadiéndola?
- Si el CHANGELOG se toca antes del tag, dejar la sección como `[2.0.0] — Unreleased` hasta cerrar el release checklist completo.

---

*Documento vivo. Cada cierre de fase o auditoría nueva debe actualizar §7, §8 y §11.*
