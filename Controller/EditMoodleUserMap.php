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
use FacturaScripts\Plugins\MoodleManagement\Lib\Audit;
use FacturaScripts\Plugins\MoodleManagement\Lib\BadgeSyncHelper;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\RateLimiter;

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

    /** @var array Notes from Moodle for this user */
    public $userNotes = [];

    /** @var string */
    public $userNotesError = '';

    /** @var array Calendar events for this user */
    public $calendarEvents = [];

    /** @var string */
    public $calendarEventsError = '';

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

        $this->addHtmlView('UserNotes', 'Tab/UserNotes', 'MoodleUserMap', 'moodle-notes', 'fa-solid fa-sticky-note');

        $this->addHtmlView('UserCalendar', 'Tab/UserCalendar', 'MoodleUserMap', 'moodle-calendar', 'fa-solid fa-calendar-days');
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

            case 'UserNotes':
                $this->loadUserNotes();
                $view->count = count($this->userNotes);
                break;

            case 'UserCalendar':
                $this->loadCalendarEvents();
                $view->count = count($this->calendarEvents);
                break;

            default:
                parent::loadData($viewName, $view);
                break;
        }
    }

    /**
     * Actions that mutate Moodle state or send messages on behalf of
     * the mapped user. F4.2 requires every one of them to pass
     * {@see canManageCurrentUserMap()} before running, so a low-
     * privileged operator cannot flip state on a UserMap belonging
     * to a client they do not manage.
     *
     * The AJAX-only `load-chat-messages` endpoint is NOT in this
     * list because it is read-only.
     *
     * @since 2.0 F4.2
     */
    private const GUARDED_ACTIONS = [
        'sync-to-moodle',
        'sync-from-moodle',
        'sync-badges',
        'send-message',
        'send-chat-message',
        'enrol-batch',
        'unenrol-batch',
        'suspend-batch',
        'create-note',
        'delete-note',
        'create-calendar-event',
        'delete-calendar-event',
    ];

    /**
     * Per-action rate limits (hits allowed per 60 s window, per actor).
     * See F4.3 · §4.17. Hitting the cap returns 429 with Retry-After.
     *
     * Tuning rationale:
     *   - sync-to-moodle / sync-from-moodle are expensive (WS round-trip
     *     + potential user-create). Low limit blocks abuse.
     *   - send-message hits Moodle messaging API per target — strict.
     *   - send-chat-message is the operator live-chat, higher limit.
     *   - Note and calendar actions are cheap and hand-driven, relaxed.
     *
     * @since 2.0 F4.3
     * @var array<string, int>
     */
    private const ACTION_RATE_LIMITS = [
        'sync-to-moodle'        => 3,    // = 3 per minute ≈ 180/hour.
        'sync-from-moodle'      => 3,
        'sync-badges'           => 5,
        'send-message'          => 10,
        'send-chat-message'     => 30,
        'enrol-batch'           => 5,
        'unenrol-batch'         => 5,
        'suspend-batch'         => 5,
        'create-note'           => 20,
        'delete-note'           => 20,
        'create-calendar-event' => 20,
        'delete-calendar-event' => 20,
    ];

    protected function execPreviousAction($action)
    {
        // F4.2 — guard mutating actions against horizontal IDOR.
        if (in_array($action, self::GUARDED_ACTIONS, true) && !$this->canManageCurrentUserMap()) {
            $actor = (string) ($this->user->nick ?? 'unknown');
            Audit::record('usermap.' . $action, Audit::FORBIDDEN, [
                'operator_nick' => $actor,
                'target_type'   => 'moodle_user_map',
                'target_id'     => (int) $this->request->get('code'),
                'ip'            => $this->request->getClientIp(),
                'user_agent'    => (string) $this->request->headers->get('User-Agent', ''),
            ]);
            Tools::log()->warning('usermap-action-forbidden', [
                'action' => $action,
                'actor'  => $actor,
            ]);
            $this->response->setStatusCode(403);
            $this->toolBox()->i18nLog()->warning('not-allowed-modify');
            return false;
        }

        // F4.3 — rate-limit sensitive actions.
        if (isset(self::ACTION_RATE_LIMITS[$action])) {
            $limit = self::ACTION_RATE_LIMITS[$action];
            $actor = (string) ($this->user->nick ?? $this->request->getClientIp() ?? 'anon');
            $bucket = 'usermap.' . $action;
            if (!RateLimiter::check($actor, $bucket, $limit)) {
                Audit::record('usermap.' . $action, Audit::RATE_LIMITED, [
                    'operator_nick' => $actor,
                    'target_type'   => 'moodle_user_map',
                    'target_id'     => (int) $this->request->get('code'),
                    'ip'            => $this->request->getClientIp(),
                    'payload'       => ['limit_per_minute' => $limit],
                ]);
                Tools::log()->warning('usermap-action-rate-limited', [
                    'action' => $action,
                    'actor'  => $actor,
                    'limit'  => $limit,
                ]);
                $this->response->headers->set('Retry-After', '60');
                $this->response->setStatusCode(429);
                $this->toolBox()->i18nLog()->warning('too-many-requests');
                return false;
            }
        }

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

            case 'create-note':
                $this->createNoteAction();
                return true;

            case 'delete-note':
                $this->deleteNoteAction();
                return true;

            case 'create-calendar-event':
                $this->createCalendarEventAction();
                return true;

            case 'delete-calendar-event':
                $this->deleteCalendarEventAction();
                return true;
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Authorization helper for the guarded actions above.
     *
     * Access rules (ordered, first match wins):
     *   1. FS admin flag -> allow.
     *   2. Same codcliente as the UserMap's contact -> allow.
     *   3. Virtual permission `moodle.manage-all-usermaps` declared
     *      on one of the user's roles -> allow.
     *   4. Else deny.
     *
     * @since 2.0 F4.2 · §2.4
     */
    protected function canManageCurrentUserMap(): bool
    {
        $user = $this->user ?? null;
        if ($user === null) {
            return false;
        }

        // Admin bypass.
        if (!empty($user->admin)) {
            return true;
        }

        $model = $this->getModel();
        if (empty($model) || empty($model->idcontacto)) {
            return false;
        }

        // Virtual role permission — admins can opt role-holders into
        // cross-client management without flipping the admin flag.
        if (method_exists($user, 'can') && $user->can('moodle.manage-all-usermaps')) {
            return true;
        }

        $userCodcliente = (string) ($user->codcliente ?? '');
        if ($userCodcliente === '') {
            return false;
        }

        $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
        if (!$cliente->load($userCodcliente)) {
            return false;
        }

        // Primary contact match.
        if ((int) $cliente->idcontactofact === (int) $model->idcontacto) {
            return true;
        }

        // Secondary: any contact of that Cliente.
        $contacto = new \FacturaScripts\Dinamic\Model\Contacto();
        if ($contacto->loadFromCode($model->idcontacto)
            && (string) $contacto->codcliente === $userCodcliente
        ) {
            return true;
        }

        return false;
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

    private function loadUserNotes(): void
    {
        $model = $this->getModel();
        if (empty($model->moodle_userid) || empty($model->idinstance)) {
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            $this->userNotesError = Tools::lang()->trans('instance-not-active');
            return;
        }

        // Get notes from site-level course (courseid=1 is SITE in Moodle)
        $result = MoodleClient::getNotes($instance, 1, (int)$model->moodle_userid);
        if (isset($result['exception'])) {
            $this->userNotesError = $result['message'] ?? $result['exception'];
            return;
        }

        // The API returns: { sitenotes: [...], coursenotes: [...], personalnotes: [...] }
        $allNotes = [];
        foreach (['sitenotes', 'coursenotes', 'personalnotes'] as $type) {
            if (!empty($result[$type]) && is_array($result[$type])) {
                foreach ($result[$type] as $note) {
                    $note['_type'] = $type;
                    $allNotes[] = $note;
                }
            }
        }

        // Sort by creation date descending
        usort($allNotes, function ($a, $b) {
            return ($b['created'] ?? 0) - ($a['created'] ?? 0);
        });

        $this->userNotes = $allNotes;
    }

    private function createNoteAction(): void
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

        $text = trim($this->request->request->get('note_text', ''));
        if (empty($text)) {
            Tools::log()->warning('note-text-required');
            return;
        }

        $publishState = $this->request->request->get('note_publish_state', 'site');
        if (!in_array($publishState, ['personal', 'course', 'site'])) {
            $publishState = 'site';
        }

        $result = MoodleClient::createNotes($instance, [[
            'userid' => (int)$model->moodle_userid,
            'courseid' => 1,
            'publishstate' => $publishState,
            'text' => $text,
        ]]);

        if (isset($result['exception'])) {
            Tools::log()->error('note-create-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice('note-created');
    }

    private function deleteNoteAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return;
        }

        $noteId = (int)$this->request->request->get('note_id', 0);
        if ($noteId <= 0) {
            Tools::log()->warning('note-id-required');
            return;
        }

        $result = MoodleClient::deleteNotes($instance, [$noteId]);
        if (isset($result['exception'])) {
            Tools::log()->error('note-delete-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice('note-deleted');
    }

    private function loadCalendarEvents(): void
    {
        $model = $this->getModel();
        if (empty($model->moodle_userid) || empty($model->idinstance)) {
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            $this->calendarEventsError = Tools::lang()->trans('instance-not-active');
            return;
        }

        $result = MoodleClient::getCalendarEvents($instance, [
            'userevents' => true,
            'siteevents' => false,
            'timestart' => 0,
            'timeend' => time() + (365 * 86400),
        ]);

        if (isset($result['exception'])) {
            $this->calendarEventsError = $result['message'] ?? $result['exception'];
            return;
        }

        $events = $result['events'] ?? [];

        // Filter events for this user
        $userId = (int)$model->moodle_userid;
        $filtered = array_filter($events, function ($event) use ($userId) {
            return (int)($event['userid'] ?? 0) === $userId;
        });

        // Sort by timestart descending
        usort($filtered, function ($a, $b) {
            return ($b['timestart'] ?? 0) - ($a['timestart'] ?? 0);
        });

        $this->calendarEvents = array_values($filtered);
    }

    private function createCalendarEventAction(): void
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

        $name = trim($this->request->request->get('event_name', ''));
        if (empty($name)) {
            Tools::log()->warning('event-name-required');
            return;
        }

        $dateStr = $this->request->request->get('event_date', '');
        if (empty($dateStr)) {
            Tools::log()->warning('event-date-required');
            return;
        }

        $timestart = strtotime($dateStr);
        if ($timestart === false) {
            Tools::log()->warning('event-date-required');
            return;
        }

        $description = trim($this->request->request->get('event_description', ''));

        $result = MoodleClient::createCalendarEvents($instance, [[
            'name' => $name,
            'description' => $description,
            'userid' => (int)$model->moodle_userid,
            'timestart' => $timestart,
            'timeduration' => 0,
            'eventtype' => 'user',
        ]]);

        if (isset($result['exception'])) {
            Tools::log()->error('calendar-event-create-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice('calendar-event-created');
    }

    private function deleteCalendarEventAction(): void
    {
        $model = $this->getModel();
        if (false === $model->loadFromCode($this->request->get('code'))) {
            Tools::log()->warning('record-not-found');
            return;
        }

        $instance = $model->getInstance();
        if (empty($instance->id) || empty($instance->token) || $instance->status !== 'active') {
            Tools::log()->warning('instance-not-active');
            return;
        }

        $eventId = (int)$this->request->request->get('event_id', 0);
        if ($eventId <= 0) {
            return;
        }

        $result = MoodleClient::deleteCalendarEvents($instance, [['eventid' => $eventId, 'repeat' => 0]]);
        if (isset($result['exception'])) {
            Tools::log()->error('calendar-event-delete-failed', ['%error%' => $result['message'] ?? $result['exception']]);
            return;
        }

        Tools::log()->notice('calendar-event-deleted');
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
