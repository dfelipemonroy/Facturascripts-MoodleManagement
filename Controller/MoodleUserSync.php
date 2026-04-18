<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
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

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\ListController;
use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\AssetManager;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class MoodleUserSync extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-user-sync';
        $data['icon'] = 'fa-solid fa-arrows-rotate';
        return $data;
    }

    protected function createViews()
    {
        $route = Tools::config('route', '');
        AssetManager::addJs($route . '/Plugins/MoodleManagement/Assets/JS/ImportModal.js?v=' . Tools::date());

        // Tab 1: Mapped users
        $this->addView('ListMoodleUserMap', 'MoodleUserMap', 'mapped-users', 'fa-solid fa-link')
            ->addSearchFields(['moodle_username'])
            ->addOrderBy(['last_sync'], 'last-sync', 2)
            ->addOrderBy(['moodle_username'], 'moodle-username');

        $instances = [];
        $instanceModel = new MoodleInstance();
        foreach ($instanceModel->all([], ['name' => 'ASC'], 0, 0) as $inst) {
            $instances[] = ['code' => $inst->id, 'description' => $inst->name];
        }
        $this->addFilterSelect('ListMoodleUserMap', 'idinstance', 'moodle-instance', 'idinstance', $instances);

        // Add action buttons
        $this->addButton('ListMoodleUserMap', [
            'action' => 'sync-all-to-moodle',
            'icon' => 'fa-solid fa-arrow-right',
            'label' => 'sync-to-moodle',
            'type' => 'action',
            'color' => 'info',
        ]);

        $this->addButton('ListMoodleUserMap', [
            'action' => 'sync-all-from-moodle',
            'icon' => 'fa-solid fa-arrow-left',
            'label' => 'sync-from-moodle',
            'type' => 'action',
            'color' => 'info',
        ]);

        $this->addButton('ListMoodleUserMap', [
            'action' => 'import-from-moodle',
            'icon' => 'fa-solid fa-download',
            'label' => 'import-from-moodle',
            'type' => 'modal',
            'color' => 'warning',
        ]);
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'sync-all-to-moodle':
                $this->syncAllToMoodle();
                return true;

            case 'sync-all-from-moodle':
                $this->syncAllFromMoodle();
                return true;

            case 'import-from-moodle':
                $this->importFromMoodle();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function syncAllToMoodle(): void
    {
        $mapModel = new MoodleUserMap();
        $maps = $mapModel->all(
            [new DataBaseWhere('sync_direction', 'moodle_to_fs', '!=')],
            [],
            0,
            0
        );

        $synced = 0;
        $errors = 0;

        foreach ($maps as $map) {
            $contact = $map->getContacto();
            $instance = $map->getInstance();

            if (empty($contact->idcontacto) || empty($instance->id) || empty($instance->token)) {
                continue;
            }

            $userData = MoodleClient::contactToMoodleUser($contact);

            if (empty($map->moodle_userid)) {
                if (empty($contact->email)) {
                    $errors++;
                    continue;
                }

                // Try to find existing user in Moodle by email first
                $existing = MoodleClient::getUsersByField($instance, 'email', [$contact->email]);
                if (!isset($existing['exception']) && !empty($existing) && isset($existing[0]['id'])) {
                    $map->moodle_userid = $existing[0]['id'];
                    $map->moodle_username = $existing[0]['username'] ?? '';
                } else {
                    // F10.5 — route through UsernameGenerator so the
                    // per-instance strategy (name_based | random_alias)
                    // is honoured here, not only in background workers.
                    $userData['username'] = \FacturaScripts\Plugins\MoodleManagement\Lib\Moodle\UsernameGenerator::unique($contact, $instance);
                    $userData['createpassword'] = 1;
                    $result = MoodleClient::createUser($instance, $userData);

                    if (isset($result['exception'])) {
                        $map->last_error = $result['message'] ?? $result['exception'];
                        $map->save();
                        $errors++;
                        continue;
                    }

                    if (is_array($result) && isset($result[0]['id'])) {
                        $map->moodle_userid = $result[0]['id'];
                        $map->moodle_username = $result[0]['username'] ?? $userData['username'];
                    }
                }
            } else {
                $result = MoodleClient::updateUser($instance, $map->moodle_userid, $userData);

                if (isset($result['exception'])) {
                    $map->last_error = $result['message'] ?? $result['exception'];
                    $map->save();
                    $errors++;
                    continue;
                }
            }

            $map->last_sync = date('Y-m-d H:i:s');
            $map->last_error = '';
            $map->save();
            $synced++;
        }

        Tools::log()->notice('sync-completed', ['%synced%' => $synced, '%errors%' => $errors]);
    }

    private function syncAllFromMoodle(): void
    {
        $mapModel = new MoodleUserMap();
        $maps = $mapModel->all(
            [
                new DataBaseWhere('sync_direction', 'fs_to_moodle', '!='),
                new DataBaseWhere('moodle_userid', 0, '>'),
            ],
            [],
            0,
            0
        );

        $synced = 0;
        $errors = 0;

        // Group maps by instance for efficiency
        $byInstance = [];
        foreach ($maps as $map) {
            $byInstance[$map->idinstance][] = $map;
        }

        foreach ($byInstance as $idinstance => $instanceMaps) {
            $instance = new MoodleInstance();
            if (false === $instance->loadFromCode($idinstance) || empty($instance->token)) {
                continue;
            }

            // Index maps by moodle_userid for quick lookup
            $mapsByMoodleId = [];
            foreach ($instanceMaps as $map) {
                $mapsByMoodleId[$map->moodle_userid] = $map;
            }

            // Batch API calls (50 users per request)
            $moodleIds = array_keys($mapsByMoodleId);
            $batches = array_chunk($moodleIds, 50);

            foreach ($batches as $batchIds) {
                $result = MoodleClient::getUsersByField($instance, 'id', $batchIds);

                if (isset($result['exception'])) {
                    foreach ($batchIds as $failedId) {
                        if (isset($mapsByMoodleId[$failedId])) {
                            $mapsByMoodleId[$failedId]->last_error = $result['message'] ?? $result['exception'];
                            $mapsByMoodleId[$failedId]->save();
                        }
                    }
                    $errors += count($batchIds);
                    continue;
                }

                // Index Moodle users by ID
                $moodleUsers = [];
                foreach ($result as $user) {
                    $moodleUsers[$user['id']] = $user;
                }

                foreach ($batchIds as $moodleId) {
                    $map = $mapsByMoodleId[$moodleId];

                    if (!isset($moodleUsers[$moodleId])) {
                        $map->last_error = 'User not found in Moodle';
                        $map->save();
                        $errors++;
                        continue;
                    }

                    $contact = $map->getContacto();
                    MoodleClient::moodleUserToContact($contact, $moodleUsers[$moodleId]);
                    $contact->save();

                    $map->moodle_username = $moodleUsers[$moodleId]['username'] ?? '';
                    $map->last_sync = date('Y-m-d H:i:s');
                    $map->last_error = '';
                    $map->save();
                    $synced++;
                }
            }
        }

        Tools::log()->notice('sync-completed', ['%synced%' => $synced, '%errors%' => $errors]);
    }

    private function importFromMoodle(): void
    {
        // Read modal form values
        $importMode = $this->request->request->get('import_mode', '');
        $codcliente = $this->request->request->get('codcliente', '');
        $idinstance = $this->request->request->get('idinstance', '');

        if (empty($importMode)) {
            Tools::log()->warning('import-mode-required');
            return;
        }

        // Validate: if assign mode, client is required
        if ($importMode === 'assign_to_existing_client' && empty($codcliente)) {
            Tools::log()->warning('client-required-for-assign');
            return;
        }

        // Validate selected client exists
        $existingClient = null;
        if ($importMode === 'assign_to_existing_client') {
            $existingClient = new Cliente();
            if (false === $existingClient->loadFromCode($codcliente)) {
                Tools::log()->warning('client-not-found');
                return;
            }
        }

        // Load Moodle instance
        if (empty($idinstance)) {
            $instanceModel = new MoodleInstance();
            $instances = $instanceModel->all(
                [new DataBaseWhere('status', 'active')],
                [],
                0,
                1
            );
            if (empty($instances)) {
                Tools::log()->warning('no-active-moodle-instance');
                return;
            }
            $instance = $instances[0];
        } else {
            $instance = new MoodleInstance();
            if (false === $instance->loadFromCode($idinstance)) {
                Tools::log()->warning('moodle-instance-not-found');
                return;
            }
        }

        // Get all Moodle users by auth type
        $users = [];
        foreach (['manual', 'email', 'ldap', 'oauth2'] as $authType) {
            $result = MoodleClient::getUsers($instance, [['key' => 'auth', 'value' => $authType]]);
            if (isset($result['exception'])) {
                continue;
            }
            foreach ($result['users'] ?? [] as $user) {
                $users[$user['id']] = $user;
            }
        }

        if (empty($users)) {
            Tools::log()->warning('import-failed', ['%message%' => 'No users found or connection error']);
            return;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($users as $moodleUser) {
            if (empty($moodleUser['email']) || ($moodleUser['deleted'] ?? false)) {
                continue;
            }

            $email = $moodleUser['email'];
            if (false === \filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email, '@localhost')) {
                $skipped++;
                continue;
            }

            // Check if mapping already exists
            $mapModel = new MoodleUserMap();
            $where = [
                new DataBaseWhere('idinstance', $instance->id),
                new DataBaseWhere('moodle_userid', $moodleUser['id']),
            ];
            if ($mapModel->loadFromCode('', $where)) {
                $skipped++;
                continue;
            }

            // Check if contact with same email exists
            $contact = new \FacturaScripts\Core\Model\Contacto();
            $contactWhere = [new DataBaseWhere('email', $email)];
            $contactExists = $contact->loadFromCode('', $contactWhere);

            if (false === $contactExists) {
                if ($importMode === 'create_client_per_user') {
                    // Create client first — saveInsert() auto-creates a linked contact
                    $cliente = new Cliente();
                    $fullName = trim(($moodleUser['firstname'] ?? '') . ' ' . ($moodleUser['lastname'] ?? ''));
                    $cliente->nombre = $fullName ?: $moodleUser['username'] ?? 'Moodle User';
                    $cliente->razonsocial = $cliente->nombre;
                    $cliente->cifnif = $moodleUser['idnumber'] ?? '';
                    $cliente->email = $email;
                    if (!empty($moodleUser['phone1'])) {
                        $cliente->telefono1 = $moodleUser['phone1'];
                    }
                    if (false === $cliente->save()) {
                        $username = $moodleUser['username'] ?? '?';
                        Tools::log()->warning('user-sync-failed', [
                            '%name%' => "$username ($email)",
                            '%message%' => 'Failed to create client',
                        ]);
                        $skipped++;
                        continue;
                    }

                    // Use the auto-created contact and enrich with Moodle data
                    $contact = $cliente->getDefaultAddress();
                    MoodleClient::moodleUserToContact($contact, $moodleUser);
                    $contact->codcliente = $cliente->codcliente;
                    $contact->save();
                } elseif ($importMode === 'assign_to_existing_client') {
                    // Create contact linked to existing client
                    $contact = new \FacturaScripts\Core\Model\Contacto();
                    MoodleClient::moodleUserToContact($contact, $moodleUser);
                    $contact->codcliente = $existingClient->codcliente;
                    if (false === $contact->save()) {
                        $username = $moodleUser['username'] ?? '?';
                        Tools::log()->warning('user-sync-failed', [
                            '%name%' => "$username ($email)",
                            '%message%' => Tools::lang()->trans('email') . ": $email",
                        ]);
                        $skipped++;
                        continue;
                    }
                }
            } else {
                // Contact exists — link to client if not already linked
                if (empty($contact->codcliente)) {
                    if ($importMode === 'create_client_per_user') {
                        $cliente = new Cliente();
                        $fullName = trim(($contact->nombre ?? '') . ' ' . ($contact->apellidos ?? ''));
                        $cliente->nombre = $fullName ?: 'Moodle User';
                        $cliente->razonsocial = $cliente->nombre;
                        $cliente->cifnif = $contact->cifnif ?? '';
                        $cliente->email = $contact->email;
                        if ($cliente->save()) {
                            // Client auto-creates a contact, but we already have one
                            // Link our existing contact to the new client
                            $contact->codcliente = $cliente->codcliente;
                            $contact->save();
                        }
                    } elseif ($importMode === 'assign_to_existing_client') {
                        $contact->codcliente = $existingClient->codcliente;
                        $contact->save();
                    }
                }
            }

            // Create mapping
            $map = new MoodleUserMap();
            $map->idcontacto = $contact->idcontacto;
            $map->idinstance = $instance->id;
            $map->moodle_userid = $moodleUser['id'];
            $map->moodle_username = $moodleUser['username'] ?? '';
            $map->sync_direction = 'moodle_to_fs';
            $map->last_sync = date('Y-m-d H:i:s');
            $map->save();
            $imported++;
        }

        Tools::log()->notice('import-completed', ['%imported%' => $imported, '%skipped%' => $skipped]);
    }
}
