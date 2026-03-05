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

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

class EditMoodleUserMap extends EditController
{
    public function getModelClassName(): string
    {
        return 'MoodleUserMap';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-user-mapping';
        $data['icon'] = 'fa-solid fa-users-between-lines';
        return $data;
    }

    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'sync-to-moodle':
                $this->syncToMoodleAction();
                return true;

            case 'sync-from-moodle':
                $this->syncFromMoodleAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function syncToMoodleAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        $contact = $model->getContacto();
        if (empty($contact->idcontacto)) {
            Tools::log()->warning('contact-not-found');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        $userData = MoodleClient::contactToMoodleUser($contact);

        if (empty($model->moodle_userid)) {
            if (empty($contact->email)) {
                Tools::log()->warning('field-can-not-be-null', ['%fieldName%' => 'email']);
                return;
            }

            // Try to find existing user in Moodle by email first
            $existing = MoodleClient::getUsersByField($instance, 'email', [$contact->email]);
            if (!isset($existing['exception']) && !empty($existing) && isset($existing[0]['id'])) {
                // Link to existing Moodle user
                $model->moodle_userid = $existing[0]['id'];
                $model->moodle_username = $existing[0]['username'] ?? '';
            } else {
                // Create new user in Moodle
                $userData['username'] = MoodleClient::generateUsername($contact);
                $userData['createpassword'] = 1;
                $result = MoodleClient::createUser($instance, $userData);

                if (isset($result['exception'])) {
                    $model->last_error = $result['message'] ?? $result['exception'];
                    $model->save();
                    Tools::log()->error('sync-failed', ['%message%' => $model->last_error]);
                    return;
                }

                if (is_array($result) && isset($result[0]['id'])) {
                    $model->moodle_userid = $result[0]['id'];
                    $model->moodle_username = $result[0]['username'] ?? $userData['username'];
                }
            }
        } else {
            // Update existing user
            $result = MoodleClient::updateUser($instance, $model->moodle_userid, $userData);

            if (isset($result['exception'])) {
                $model->last_error = $result['message'] ?? $result['exception'];
                $model->save();
                Tools::log()->error('sync-failed', ['%message%' => $model->last_error]);
                return;
            }
        }

        $model->last_sync = date('Y-m-d H:i:s');
        $model->last_error = '';
        $model->save();
        Tools::log()->notice('user-synced');
    }

    private function syncFromMoodleAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        if (empty($model->moodle_userid)) {
            Tools::log()->warning('moodle-userid-required');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token)) {
            Tools::log()->warning('moodle-instance-not-configured');
            return;
        }

        $result = MoodleClient::getUsersByField($instance, 'id', [$model->moodle_userid]);

        if (isset($result['exception'])) {
            $model->last_error = $result['message'] ?? $result['exception'];
            $model->save();
            Tools::log()->error('sync-failed', ['%message%' => $model->last_error]);
            return;
        }

        if (empty($result) || !is_array($result) || !isset($result[0])) {
            $model->last_error = 'User not found in Moodle';
            $model->save();
            Tools::log()->warning('moodle-user-not-found');
            return;
        }

        $moodleUser = $result[0];
        $contact = $model->getContacto();

        MoodleClient::moodleUserToContact($contact, $moodleUser);
        $contact->save();

        $model->moodle_username = $moodleUser['username'] ?? '';
        $model->last_sync = date('Y-m-d H:i:s');
        $model->last_error = '';
        $model->save();
        Tools::log()->notice('user-synced');
    }
}
