<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.3 · §6.16
 *
 * Read-only browser over the F4.4 audit trail. Admin-only because the
 * table cross-references operator nicks with IP addresses and
 * user-agents — PII that should not be visible to non-admin roles.
 *
 * Two tabs in one controller:
 *   - Audit log      (moodle_audit_log)
 *   - Webhook log    (moodle_webhook_log, filled by F10.1 receiver)
 */
class ListMoodleAuditLog extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['submenu'] = 'moodle';
        $data['title'] = 'moodle-audit-log';
        $data['icon'] = 'fa-solid fa-clipboard-list';
        return $data;
    }

    protected function createViews()
    {
        $this->createAuditView();
        $this->createWebhookView();
    }

    private function createAuditView(): void
    {
        $this->addView('ListMoodleAuditLog', 'MoodleAuditLog', 'audit-log', 'fa-solid fa-clipboard-list')
            ->addSearchFields(['action', 'operator_nick', 'ip', 'user_agent'])
            ->addOrderBy(['created_at'], 'date', 2)
            ->addOrderBy(['action'], 'action')
            ->addOrderBy(['outcome'], 'outcome');

        $this->addFilterSelectWhere('ListMoodleAuditLog', 'outcome', [
            ['label' => '------', 'where' => []],
            ['label' => 'ok',            'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('outcome', 'ok')]],
            ['label' => 'forbidden',     'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('outcome', 'forbidden')]],
            ['label' => 'rate-limited',  'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('outcome', 'rate_limited')]],
            ['label' => 'bad-signature', 'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('outcome', 'bad_signature')]],
            ['label' => 'error',         'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('outcome', 'error')]],
        ]);

        // Disable create + delete — audit table is append-only.
        $this->setSettings('ListMoodleAuditLog', 'btnNew', false);
        $this->setSettings('ListMoodleAuditLog', 'btnDelete', false);
        $this->setSettings('ListMoodleAuditLog', 'btnPrint', false);
    }

    private function createWebhookView(): void
    {
        $this->addView('ListMoodleWebhookLog', 'MoodleWebhookLog', 'webhook-log', 'fa-solid fa-arrow-right-arrow-left')
            ->addSearchFields(['event_type', 'ip', 'payload_hash'])
            ->addOrderBy(['received_at'], 'date', 2)
            ->addOrderBy(['event_type'], 'event-type')
            ->addOrderBy(['outcome'], 'outcome');

        $this->addFilterSelectWhere('ListMoodleWebhookLog', 'signature_ok', [
            ['label' => '------', 'where' => []],
            ['label' => 'signature-ok',  'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('signature_ok', true)]],
            ['label' => 'signature-bad', 'where' => [new \FacturaScripts\Core\DataSrc\DataBaseWhere('signature_ok', false)]],
        ]);

        $this->setSettings('ListMoodleWebhookLog', 'btnNew', false);
        $this->setSettings('ListMoodleWebhookLog', 'btnDelete', false);
        $this->setSettings('ListMoodleWebhookLog', 'btnPrint', false);
    }
}
