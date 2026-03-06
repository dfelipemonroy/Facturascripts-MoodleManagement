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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\BadgeSyncHelper;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;

class EditMoodleUserMap extends EditController
{
    /** @var array */
    public $academicProgress = [];

    /** @var string */
    public $academicProgressError = '';

    /** @var array Conversation messages for chat tab */
    public $chatMessages = [];

    /** @var int Conversation ID (0 = no conversation yet) */
    public $chatConversationId = 0;

    /** @var string */
    public $chatError = '';

    /** @var int The WS service user ID (sender) */
    public $chatServiceUserId = 0;

    /** @var string */
    public $chatServiceUserName = '';

    /** @var string Contact full name for chat display */
    public $chatContactName = '';

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

    protected function createViews()
    {
        parent::createViews();

        $this->addListView('ListMoodleEnrolment', 'MoodleEnrolment', 'moodle-enrolments', 'fa-solid fa-user-graduate')
            ->addOrderBy(['enrolment_date'], 'enrolment-date', 2)
            ->addSearchFields(['moodle_userid', 'notes']);

        $this->addHtmlView('AcademicProgress', 'Tab/AcademicProgress', 'MoodleUserMap', 'academic-progress', 'fa-solid fa-chart-line');

        $this->addHtmlView('UserChat', 'Tab/UserChat', 'MoodleUserMap', 'chat', 'fa-solid fa-comments');

        $this->addListView('ListMoodleCertificateUser', 'MoodleCertificate', 'moodle-badges', 'fa-solid fa-award')
            ->addOrderBy(['date_issued'], 'date-issued', 2)
            ->addSearchFields(['badge_name', 'course_name']);
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ListMoodleEnrolment':
                $model = $this->getModel();
                if (!empty($model->moodle_userid) && !empty($model->idinstance)) {
                    $where = [
                        new DataBaseWhere('moodle_userid', $model->moodle_userid),
                        new DataBaseWhere('idinstance', $model->idinstance),
                    ];
                    $view->loadData('', $where);
                }
                break;

            case 'AcademicProgress':
                $this->loadAcademicProgress();
                $view->count = count($this->academicProgress);
                break;

            case 'UserChat':
                $this->loadChatConversation();
                $view->count = count($this->chatMessages);
                break;

            case 'ListMoodleCertificateUser':
                $model = $this->getModel();
                if (!empty($model->moodle_userid) && !empty($model->idinstance)) {
                    $where = [
                        new DataBaseWhere('moodle_userid', $model->moodle_userid),
                        new DataBaseWhere('idinstance', $model->idinstance),
                    ];
                    $view->loadData('', $where);
                }
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
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

            case 'sync-badges':
                $this->syncBadgesAction();
                return true;

            case 'send-message':
                $this->sendMessageAction();
                return true;

            case 'send-chat-message':
                $this->sendChatMessageAction();
                return true;

            case 'load-chat-messages':
                $this->loadChatMessagesAjax();
                return true;

            case 'enrol-batch':
            case 'unenrol-batch':
            case 'suspend-batch':
                $this->processEnrolmentBatch($action);
                return true;
        }

        return parent::execPreviousAction($action);
    }

    private function loadAcademicProgress(): void
    {
        $model = $this->getModel();
        if (empty($model->moodle_userid) || empty($model->idinstance)) {
            $this->academicProgressError = Tools::lang()->trans('moodle-userid-required');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            $this->academicProgressError = Tools::lang()->trans('instance-not-active');
            return;
        }

        $result = MoodleClient::getAcademicProgress($instance, $model->moodle_userid);
        if (isset($result['exception'])) {
            $this->academicProgressError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->academicProgress = $result;
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

    private function syncBadgesAction(): void
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
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return;
        }

        $synced = BadgeSyncHelper::syncUserBadges($instance, $model->moodle_userid, $model->idcontacto);
        if ($synced < 0) {
            Tools::log()->error('sync-failed', ['%message%' => 'API error']);
            return;
        }

        Tools::log()->notice('badges-synced', ['%count%' => $synced]);
    }

    private function sendMessageAction(): void
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
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return;
        }

        $text = trim($this->request->request->get('message_text', ''));
        if (empty($text)) {
            Tools::log()->warning('message-text-required');
            return;
        }

        $result = MoodleClient::sendInstantMessages($instance, [
            ['touserid' => (int)$model->moodle_userid, 'text' => $text],
        ]);

        if (isset($result['exception'])) {
            Tools::log()->error('message-send-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        if (isset($result[0]['errormessage']) && !empty($result[0]['errormessage'])) {
            Tools::log()->error('message-send-failed', ['%error%' => $result[0]['errormessage']]);
            return;
        }

        Tools::log()->notice('message-sent');
    }

    private function loadChatConversation(): void
    {
        $model = $this->getModel();
        if (empty($model->moodle_userid) || empty($model->idinstance)) {
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            $this->chatError = Tools::lang()->trans('instance-not-active');
            return;
        }

        $serviceUserId = (int)$instance->service_userid;
        if (empty($serviceUserId)) {
            $this->chatError = Tools::lang()->trans('service-userid-not-configured');
            return;
        }

        $this->chatServiceUserId = $serviceUserId;
        $this->chatServiceUserName = $instance->service_username ?: 'Support';

        // Get contact name for display
        $contacto = $model->getContacto();
        $this->chatContactName = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
        if (empty($this->chatContactName)) {
            $this->chatContactName = $model->moodle_username ?: ('User #' . $model->moodle_userid);
        }

        $result = MoodleClient::getConversationBetweenUsers(
            $instance,
            $serviceUserId,
            (int)$model->moodle_userid,
            true,
            100
        );

        if (isset($result['exception'])) {
            $errorcode = strtolower($result['errorcode'] ?? '');
            $message = strtolower($result['message'] ?? '');
            // No conversation yet — show empty chat ready for first message
            if (str_contains($errorcode, 'conversationdoesntexist')
                || str_contains($errorcode, 'conversation')
                || str_contains($message, 'conversation')
                || str_contains($message, 'conversación')
            ) {
                $this->chatMessages = [];
                return;
            }
            $this->chatError = $result['message'] ?? $result['exception'];
            return;
        }

        $this->chatConversationId = (int)($result['id'] ?? 0);

        // Mark messages as read in Moodle
        if ($this->chatConversationId > 0) {
            MoodleClient::markAllConversationMessagesAsRead($instance, $serviceUserId, $this->chatConversationId);
        }

        $messages = $result['messages'] ?? [];
        // Reverse so oldest first (API returns newest first)
        $this->chatMessages = array_reverse($messages);
    }

    private function loadChatMessagesAjax(): void
    {
        $this->setTemplate(false);

        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            $this->response->setContent(json_encode(['error' => 'record-not-found']));
            return;
        }

        if (empty($model->moodle_userid) || empty($model->idinstance)) {
            $this->response->setContent(json_encode(['error' => 'moodle-userid-required']));
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            $this->response->setContent(json_encode(['error' => 'instance-not-active']));
            return;
        }

        $serviceUserId = (int)$instance->service_userid;
        if (empty($serviceUserId)) {
            $this->response->setContent(json_encode(['error' => 'service-userid-not-configured']));
            return;
        }

        $result = MoodleClient::getConversationBetweenUsers($instance, $serviceUserId, (int)$model->moodle_userid, true, 100);

        if (isset($result['exception'])) {
            $errorcode = strtolower($result['errorcode'] ?? '');
            $message = strtolower($result['message'] ?? '');
            if (str_contains($errorcode, 'conversation') || str_contains($message, 'conversation') || str_contains($message, 'conversación')) {
                $this->response->setContent(json_encode(['messages' => [], 'serviceUserId' => $serviceUserId]));
                return;
            }
            $this->response->setContent(json_encode(['error' => $result['message'] ?? $result['exception']]));
            return;
        }

        // Mark messages as read in Moodle
        $conversationId = (int)($result['id'] ?? 0);
        if ($conversationId > 0) {
            MoodleClient::markAllConversationMessagesAsRead($instance, $serviceUserId, $conversationId);
        }

        // Get contact name
        $contacto = $model->getContacto();
        $contactName = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
        if (empty($contactName)) {
            $contactName = $model->moodle_username ?: ('User #' . $model->moodle_userid);
        }

        $messages = array_reverse($result['messages'] ?? []);
        $this->response->setContent(json_encode([
            'messages' => $messages,
            'serviceUserId' => $serviceUserId,
            'contactName' => $contactName,
        ]));
    }

    private function sendChatMessageAction(): void
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
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return;
        }

        $text = trim($this->request->request->get('chat_message', ''));
        if (empty($text)) {
            Tools::log()->warning('message-text-required');
            return;
        }

        // Send via instant messages (creates conversation if needed)
        $result = MoodleClient::sendInstantMessages($instance, [
            ['touserid' => (int)$model->moodle_userid, 'text' => $text],
        ]);

        // API-level error (invalid token, function not found, etc.)
        if (isset($result['exception'])) {
            Tools::log()->error('message-send-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        // Per-message error (user not found, messaging disabled, privacy block, etc.)
        if (isset($result[0]['errormessage']) && !empty($result[0]['errormessage'])) {
            Tools::log()->error('message-send-failed', ['%error%' => $result[0]['errormessage']]);
            return;
        }

        Tools::log()->notice('message-sent');
    }

    private function processEnrolmentBatch(string $action): void
    {
        $codes = $this->request->request->getArray('codes');
        if (empty($codes)) {
            Tools::log()->warning('no-records-selected');
            return;
        }

        $enrolment = new \FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment();
        $success = 0;
        $errors = 0;

        foreach ($codes as $code) {
            if (!$enrolment->loadFromCode($code)) {
                continue;
            }

            $result = match ($action) {
                'enrol-batch' => $enrolment->enrol(),
                'unenrol-batch' => $enrolment->unenrol(),
                'suspend-batch' => $enrolment->suspend(),
                default => false,
            };

            $result ? $success++ : $errors++;
        }

        if ($success > 0) {
            Tools::log()->notice('batch-action-success', ['%count%' => $success]);
        }
        if ($errors > 0) {
            Tools::log()->warning('batch-action-errors', ['%count%' => $errors]);
        }
    }
}
