<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\MoodleManagement;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Html;
use FacturaScripts\Core\Lib\Widget\BaseWidget;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\WorkQueue;
use FacturaScripts\Plugins\MoodleManagement\Lib\Migration\SchemaMigrator;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\HtmlSanitizer;
use FacturaScripts\Plugins\MoodleManagement\Lib\View\JsonForScript;
use FacturaScripts\Plugins\MoodleManagement\Lib\Widget\WidgetMoodleTimestamp;
use Twig\TwigFunction;

/**
 * Class Init
 *
 * @package FacturaScripts\Plugins\MoodleManagement
 */
class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\EditContacto());
        $this->loadExtension(new Extension\Controller\EditCliente());
        $this->loadExtension(new Extension\Controller\EditProducto());

        WorkQueue::addWorker('EnrolmentWorker', 'Model.FacturaCliente.Update');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.PresupuestoCliente.Update');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.PedidoCliente.Update');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.LineaPresupuestoCliente.Delete');
        WorkQueue::addWorker('PreEnrolmentWorker', 'Model.LineaPedidoCliente.Delete');
        WorkQueue::addWorker('ContactSyncWorker', 'Model.Contacto.Update');
        WorkQueue::addWorker('ContactDeleteWorker', 'Model.Contacto.Delete');
        // F6.1 — rebinding BadgeSyncWorker from Save to Insert cuts the
        // cascade where the worker's own ->save() re-triggered itself.
        // Post-onboarding re-syncs are driven explicitly by setting
        // badge_sync_needed=1 and enqueueing via WorkQueue::add().
        WorkQueue::addWorker('BadgeSyncWorker', 'Model.MoodleUserMap.Insert');
        WorkQueue::addWorker('OnboardingWorker', 'Model.MoodleUserMap.Insert');

        // F3.7 — register custom widget for Moodle Unix-epoch INT columns.
        if (method_exists(BaseWidget::class, 'addExtension')) {
            BaseWidget::addExtension('moodleTimestamp', WidgetMoodleTimestamp::class);
        }

        // FE-02 (2026-04-17) — expose `json_for_script` to Twig so
        // templates can serialise payloads destined for <script>
        // blocks through the same hex-escaping helper the dashboard
        // controller uses. Replaces the `|json_encode|raw` pipeline
        // which was vulnerable to `</script>` injection when the data
        // carried user-controlled strings.
        if (class_exists(Html::class) && method_exists(Html::class, 'addFunction')) {
            Html::addFunction(new TwigFunction(
                'json_for_script',
                static function ($value): string {
                    return JsonForScript::encode($value);
                },
                ['is_safe' => ['html', 'js']]
            ));

            // FE-03 (2026-04-17) — DOM-based strip_tags replacement
            // for Twig templates that previously used the built-in
            // `| striptags` filter. `HtmlSanitizer::toPlainText`
            // survives the classical `<script>body</script>` bypass.
            Html::addFunction(new TwigFunction(
                'mm_plain_text',
                static function ($value, int $maxLen = 0): string {
                    return HtmlSanitizer::toPlainText($value === null ? null : (string) $value, $maxLen);
                }
            ));
        }

        // F5.4 — Fresh-install bootstrap. The view and the v2 schema
        // deltas must be applied both on install (init) and on
        // upgrade (update). Previously createViews() only ran in
        // update(), leaving fresh installs with a missing view.
        $this->bootstrapSchema();
    }

    public function uninstall(): void
    {
        // Data is NOT dropped on uninstall so administrators do not
        // lose history if the plugin is toggled. See SECURITY.md for
        // GDPR purge instructions.
    }

    public function update(): void
    {
        $this->bootstrapSchema();
    }

    /**
     * Single entry point that prepares the schema for v2.0:
     *   1. createViews()                               — F5.4
     *   2. Run SchemaMigrator over Update/v2_0.php     — F5.1 + many
     *   3. Seed default certificate template           — F5.2
     *
     * Every step is idempotent and safe to call on every boot.
     *
     * @since 2.0 F5.1/F5.4 · guard reforzado 2026-04-19 para
     *             tolerar requests (/updater, CLI) donde la conexión
     *             FS a la DB no está disponible cuando `init()` corre.
     *             Antes, el primer hit a `/updater` surfaceaba
     *             `mm-migrations-failed` y `mm-seed-failed` con
     *             `escape_string() on null` aunque el try/catch ya
     *             los absorbía — ruidoso y confuso para el operador.
     */
    private function bootstrapSchema(): void
    {
        if (!$this->isDatabaseReady()) {
            // FS aún no ha inicializado la conexión (request de
            // /updater, CLI sin bootstrap completo, etc.). Saltamos
            // sin logs: el siguiente request con DB lista ejecuta
            // las migraciones por ser idempotentes.
            return;
        }

        try {
            $this->createViews();
        } catch (\Throwable $e) {
            Tools::log()->warning('mm-view-create-failed', [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->runMigrations();
        } catch (\Throwable $e) {
            Tools::log()->error('mm-migrations-failed', [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->seedDefaultCertificateTemplate();
        } catch (\Throwable $e) {
            Tools::log()->warning('mm-seed-failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Intenta una consulta trivial para detectar si FS ya tiene la
     * conexión a la DB activa. Usado como guard en `bootstrapSchema`
     * para evitar `escape_string() on null` en requests tempranos.
     *
     * @since 2.0 — 2026-04-19
     */
    private function isDatabaseReady(): bool
    {
        try {
            $db = new DataBase();
            // `select` contra un catálogo siempre disponible si la
            // conexión está lista; devuelve array vacío en cualquier
            // DB suportado cuando no existe, sin lanzar.
            $rows = $db->select('SELECT 1 AS ok');
            return is_array($rows) && isset($rows[0]['ok']);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function createViews(): void
    {
        $isPostgres = Tools::config('db_type') === 'postgresql';
        $sql = $isPostgres
            ? "CREATE OR REPLACE VIEW moodle_course_categories_view AS SELECT moodle_categoryid, name || ' (' || moodle_categoryid || ')' AS display_name FROM moodle_course_categories WHERE moodle_categoryid IS NOT NULL"
            : "CREATE OR REPLACE VIEW moodle_course_categories_view AS SELECT moodle_categoryid, CONCAT(name, ' (', moodle_categoryid, ')') AS display_name FROM moodle_course_categories WHERE moodle_categoryid IS NOT NULL";

        (new DataBase())->exec($sql);
    }

    /**
     * Execute every migration declared in Update/v2_0.php. Failures
     * are logged but do not abort plugin initialisation — a broken
     * DDL statement must not leave the plugin unloadable.
     */
    private function runMigrations(): void
    {
        $file = __DIR__ . '/Update/v2_0.php';
        if (!is_file($file)) {
            return;
        }
        /** @var array<string, callable(SchemaMigrator):bool> $migrations */
        $migrations = require $file;
        if (!is_array($migrations)) {
            return;
        }
        $migrator = new SchemaMigrator();
        $migrator->ensureVersionTable();
        foreach ($migrations as $version => $fn) {
            $migrator->apply((string) $version, $fn);
        }
    }

    /**
     * F5.2 / F5.21 — idempotent seed of the default certificate
     * template. Inserts once; subsequent calls become no-ops.
     */
    private function seedDefaultCertificateTemplate(): void
    {
        $db = new DataBase();
        // Only seed when the table exists (PluginsDeploy creates it
        // before Init::update runs, but we defend anyway).
        $tableExists = $db->select(
            'SELECT 1 FROM information_schema.tables WHERE table_name = '
            . $db->var2str('moodle_certificate_templates')
        );
        if (empty($tableExists)) {
            return;
        }
        // Idempotency — only seed when there is no row with the
        // reserved default name.
        $existing = $db->select(
            "SELECT id FROM moodle_certificate_templates WHERE name = 'Default' LIMIT 1"
        );
        if (!empty($existing)) {
            return;
        }
        $db->exec(
            'INSERT INTO moodle_certificate_templates (name, is_default, primary_color, accent_color, notes) VALUES ('
            . "'Default', 1, '#0056A3', '#D9B440', 'Seeded by v2.0 install (F5.2)'"
            . ')'
        );
    }
}
