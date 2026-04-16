# Changelog

All notable changes to the MoodleManagement plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — Unreleased

Comprehensive audit remediation release. See [`docs/V2.0-ACTION-PLAN.md`](docs/V2.0-ACTION-PLAN.md)
for the full scope (166 findings across 13 phases).

### Added
- `docs/V2.0-ACTION-PLAN.md` — master remediation plan with 13 phases.
- `docs/V2.0-TASK-CHECKLIST.md` — trackable checklist for all 166 audit findings.
- `.editorconfig`, `phpcs.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php` — code quality tooling.
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `UPGRADE.md` — project governance docs.
- `Test/Unit/`, `Test/Integration/`, `Test/Fixtures/` + `phpunit.xml.dist` — test scaffolding.
- (Further entries will be appended as phases close.)

### Changed
- (Filled progressively as Fases 1–12 land.)

### Deprecated
- `Lib/MoodleClient.php` static god-class methods will be superseded in Fase 8 by
  namespaced APIs under `Lib/Moodle/Api/*`. A back-compat facade is preserved
  throughout v2.x.

### Removed
- (None yet.)

### Fixed
- (Filled progressively as Fases 1–12 land.)

### Security
- **Tracking top-14 CRITICAL findings** — see `docs/V2.0-TASK-CHECKLIST.md` Fases 2/4/5/6/7.
- Stored XSS in `UserChat.html.twig`, `UserNotes.html.twig`, `CourseContent.html.twig` scheduled for Fase 2.
- IDOR in `MoodleCertificatePdf.php` scheduled for Fase 4.
- Token-at-rest encryption (AES-256-GCM) scheduled for Fase 5.
- SSRF hardening + circuit-breaker scheduled for Fase 7.

---

## [1.1.0] — 2026-04-09

### Added
- 6 refinement features (v2.0-E through v2.0-J):
  - Configurable certificate templates.
  - Configurable expiry e-mail days.
  - Dashboard range selector.
  - Wizard memory cookie.
  - CSV export from lists.
  - Manual expiry e-mail resend.
- Certificate template model + controllers (`EditMoodleCertificateTemplate`,
  `ListMoodleCertificateTemplate`).
- `Lib/CertificatePdfGenerator.php`, `Lib/ExpiryNotifier.php`.
- `Controller/MoodleCertificatePdf.php`, `Controller/MoodleImportWizard.php`.
- `Assets/JS/CertificatePdf.js`.

### Changed
- `README.md` updated (bilingual ES/EN) with the 6 refinements.
- `docs/BRAINSTORMING.md` documents technical decisions for each refinement.

---

## [1.0.0] — 2025-03-05

### Added
- Initial public release.
- Bidirectional FS ↔ Moodle integration via Moodle REST API.
- 8 tables: `moodle_instances`, `moodle_user_map`, `moodle_course_map`,
  `moodle_enrolments`, `moodle_cohorts`, `moodle_role_map`,
  `moodle_course_categories`, `moodle_certificates`.
- 5 workers: `EnrolmentWorker`, `PreEnrolmentWorker`, `ContactSyncWorker`,
  `ContactDeleteWorker`, `BadgeSyncWorker`.
- 6 cron jobs: `healthCheck`, `userSync`, `courseSync`, `reconciliation`,
  `cleanup`, `expiryCheck`.
- 3 controller extensions: `EditContacto`, `EditCliente`, `EditProducto`.

[2.0.0]: https://github.com/your-org/MoodleManagement/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/your-org/MoodleManagement/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/your-org/MoodleManagement/releases/tag/v1.0.0
