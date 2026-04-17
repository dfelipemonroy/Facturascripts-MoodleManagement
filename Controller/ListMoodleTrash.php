<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\DataSrc\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Audit;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.4 · §6.17
 *
 * Papelera (trash) viewer for rows soft-deleted via the deleted_at
 * column introduced in F5.20. Three tabs, one per soft-deletable
 * entity: user maps, enrolments, cohorts.
 *
 * Admin-only. Two row actions:
 *   - restore: clears deleted_at (row reappears in the main list).
 *   - purge:   issues a physical DELETE (irreversible).
 *
 * Every restore/purge is audit-logged via Audit::record().
 */
class ListMoodleTrash extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'moodle';
        $data['title'] = 'trash';
        $data['icon'] = 'fa-solid fa-trash-can-arrow-up';
        return $data;
    }

    protected function createViews()
    {
        $this->addView('ListMoodleTrashUserMap', 'MoodleUserMap', 'user-mappings', 'fa-solid fa-users-between-lines')
            ->addSearchFields(['moodle_username'])
            ->addOrderBy(['deleted_at'], 'deleted-at', 2);
        $this->setTrashSettings('ListMoodleTrashUserMap');

        $this->addView('ListMoodleTrashEnrolment', 'MoodleEnrolment', 'enrolments', 'fa-solid fa-graduation-cap')
            ->addSearchFields(['moodle_userid', 'moodle_courseid'])
            ->addOrderBy(['deleted_at'], 'deleted-at', 2);
        $this->setTrashSettings('ListMoodleTrashEnrolment');

        $this->addView('ListMoodleTrashCohort', 'MoodleCohort', 'cohorts', 'fa-solid fa-object-group')
            ->addSearchFields(['name', 'idnumber'])
            ->addOrderBy(['deleted_at'], 'deleted-at', 2);
        $this->setTrashSettings('ListMoodleTrashCohort');
    }

    /**
     * Common per-tab config: hide new/delete buttons, restrict the
     * default WHERE to only soft-deleted rows.
     */
    private function setTrashSettings(string $viewName): void
    {
        $this->setSettings($viewName, 'btnNew', false);
        $this->setSettings($viewName, 'btnDelete', false);
        $this->setSettings($viewName, 'btnPrint', false);
        // Add a filter-less constraint that the view must respect.
        $this->addFilterSelectWhere($viewName, 'trash', [
            [
                'label' => Tools::lang()->trans('trash-only'),
                'where' => [new DataBaseWhere('deleted_at', null, 'IS NOT')],
                'default' => true,
            ],
        ]);
    }

    /**
     * Hook into the action pipeline before the ListController defaults
     * take over. Two custom verbs: restore / purge — both dispatched
     * from the row buttons defined in XMLView/ListMoodleTrash*.xml.
     */
    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'mm-restore':
                return $this->handleRestoreOrPurge('restore');
            case 'mm-purge':
                return $this->handleRestoreOrPurge('purge');
            default:
                return parent::execPreviousAction($action);
        }
    }

    /**
     * Executes a restore or purge. The CSRF token check is provided by
     * the standard FS ListController pipeline; we only validate the
     * row still exists in soft-deleted state.
     */
    private function handleRestoreOrPurge(string $verb): bool
    {
        $entity = (string) $this->request->request->get('entity', '');
        $id = (int) $this->request->request->get('code', 0);
        if ($id <= 0 || $entity === '') {
            Tools::log()->warning('mm-trash-bad-args');
            return true;
        }

        $table = $this->tableOf($entity);
        if ($table === null) {
            Tools::log()->warning('mm-trash-unknown-entity', ['entity' => $entity]);
            return true;
        }

        $db = new DataBase();
        if ($verb === 'restore') {
            $ok = $db->exec('UPDATE ' . $table . ' SET deleted_at = NULL WHERE id = ' . $db->var2str($id));
            Audit::record('trash.restore', $ok ? Audit::OK : Audit::ERROR, [
                'operator_nick' => $this->user->nick ?? null,
                'target_type'   => $entity,
                'target_id'     => $id,
            ]);
            Tools::log()->notice('mm-trash-restored', ['entity' => $entity, 'id' => $id]);
        } elseif ($verb === 'purge') {
            // Require the row to be ALREADY soft-deleted before purging.
            // Route through forcePhysicalDelete() on the model (added
            // by SoftDeleteTrait in F13 DISCOVERED-02) so FS events
            // fire normally; the raw DELETE path left the Model.<X>
            // .Delete event silent, which downstream subscribers may
            // depend on.
            $model = $this->modelForEntity($entity);
            if ($model === null || false === $model->loadFromCode((string) $id)) {
                Tools::log()->warning('mm-trash-purge-not-found', ['entity' => $entity, 'id' => $id]);
                return true;
            }
            if (!method_exists($model, 'isTrashed') || !$model->isTrashed()) {
                Tools::log()->warning('mm-trash-purge-not-deleted', ['entity' => $entity, 'id' => $id]);
                return true;
            }
            $ok = method_exists($model, 'forcePhysicalDelete')
                ? $model->forcePhysicalDelete()
                : (bool) $db->exec('DELETE FROM ' . $table . ' WHERE id = ' . $db->var2str($id));
            Audit::record('trash.purge', $ok ? Audit::OK : Audit::ERROR, [
                'operator_nick' => $this->user->nick ?? null,
                'target_type'   => $entity,
                'target_id'     => $id,
            ]);
            Tools::log()->notice('mm-trash-purged', ['entity' => $entity, 'id' => $id]);
        }

        return true;
    }

    /**
     * Allow-list: only these three entities (the ones with deleted_at
     * columns introduced in F5.20) can be restored or purged here.
     */
    private function tableOf(string $entity): ?string
    {
        switch ($entity) {
            case 'usermap':
                return 'moodle_user_map';
            case 'enrolment':
                return 'moodle_enrolments';
            case 'cohort':
                return 'moodle_cohorts';
            default:
                return null;
        }
    }

    /**
     * Return an empty model instance for the allow-listed entity, or
     * null when the entity tag is unknown. Used by handleRestoreOrPurge
     * to route through `forcePhysicalDelete()` instead of raw SQL.
     *
     * @since 2.0 — F13 DISCOVERED-02
     */
    private function modelForEntity(string $entity): ?object
    {
        switch ($entity) {
            case 'usermap':
                return new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap();
            case 'enrolment':
                return new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment();
            case 'cohort':
                return new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleCohort();
            default:
                return null;
        }
    }
}
