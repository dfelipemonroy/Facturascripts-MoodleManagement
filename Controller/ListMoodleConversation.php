<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Lib\ExtendedController\PanelController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

class ListMoodleConversation extends PanelController
{
    /** @var array */
    public $conversations = [];

    /** @var int */
    public $totalUnread = 0;

    public function getModelClassName(): string
    {
        return 'MoodleInstance';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-conversations';
        $data['icon'] = 'fa-solid fa-comments';
        $data['showonmenu'] = true;
        return $data;
    }

    protected function createViews()
    {
        $this->addHtmlView('ConversationList', 'Tab/ConversationList', 'MoodleInstance', 'moodle-conversations', 'fa-solid fa-comments');
    }

    protected function loadData($viewName, $view)
    {
        switch ($viewName) {
            case 'ConversationList':
                $this->loadConversations();
                $view->count = count($this->conversations);
                break;

            default:
                parent::loadData($viewName, $view);
        }
    }

    protected function execPreviousAction($action)
    {
        if ($action === 'get-unread-count') {
            $this->getUnreadCountAjax();
            return true;
        }
        if ($action === 'load-conversations') {
            $this->loadConversationsAjax();
            return true;
        }
        if ($action === 'get-available-users') {
            $this->getAvailableUsersAjax();
            return true;
        }
        return parent::execPreviousAction($action);
    }

    private function loadConversations(): void
    {
        $instances = new MoodleInstance();
        $activeInstances = $instances->all([
            new DataBaseWhere('status', 'active'),
        ]);

        foreach ($activeInstances as $instance) {
            if (empty($instance->service_userid) || empty($instance->token)) {
                continue;
            }

            $result = MoodleClient::getConversations(
                $instance,
                (int)$instance->service_userid,
                1, // private conversations only
                0,
                50
            );

            if (isset($result['exception']) || !isset($result['conversations'])) {
                continue;
            }

            foreach ($result['conversations'] as $conv) {
                $otherMember = null;
                foreach ($conv['members'] ?? [] as $member) {
                    if ((int)$member['id'] !== (int)$instance->service_userid) {
                        $otherMember = $member;
                        break;
                    }
                }

                if (!$otherMember) {
                    continue;
                }

                // Look up FS contact via user map
                $userMap = new MoodleUserMap();
                $maps = $userMap->all([
                    new DataBaseWhere('moodle_userid', $otherMember['id']),
                    new DataBaseWhere('idinstance', $instance->id),
                ], [], 0, 1);

                $contactName = $otherMember['fullname'];
                $userMapId = null;
                if (!empty($maps)) {
                    $map = $maps[0];
                    $userMapId = $map->id;
                    $contacto = $map->getContacto();
                    $name = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
                    if (!empty($name)) {
                        $contactName = $name;
                    }
                }

                $lastMessage = $conv['messages'][0] ?? null;
                $unread = (int)($conv['unreadcount'] ?? 0);
                $this->totalUnread += $unread;

                $this->conversations[] = [
                    'conversationId' => $conv['id'],
                    'instanceId' => $instance->id,
                    'instanceName' => $instance->name,
                    'moodleUserId' => $otherMember['id'],
                    'moodleFullname' => $otherMember['fullname'],
                    'contactName' => $contactName,
                    'userMapId' => $userMapId,
                    'unreadCount' => $unread,
                    'lastMessage' => $lastMessage ? strip_tags($lastMessage['text']) : '',
                    'lastMessageTime' => $lastMessage ? (int)$lastMessage['timecreated'] : 0,
                    'lastMessageIsMe' => $lastMessage ? ((int)$lastMessage['useridfrom'] === (int)$instance->service_userid) : false,
                ];
            }
        }

        // Sort: unread first, then by last message time desc
        usort($this->conversations, function ($a, $b) {
            if ($a['unreadCount'] > 0 && $b['unreadCount'] === 0) return -1;
            if ($a['unreadCount'] === 0 && $b['unreadCount'] > 0) return 1;
            return $b['lastMessageTime'] - $a['lastMessageTime'];
        });
    }

    private function loadConversationsAjax(): void
    {
        $this->setTemplate(false);
        $this->loadConversations();
        $this->response->setContent(json_encode([
            'conversations' => $this->conversations,
            'totalUnread' => $this->totalUnread,
        ]));
    }

    private function getAvailableUsersAjax(): void
    {
        $this->setTemplate(false);

        // Load current conversations to know which users already have one
        $this->loadConversations();
        $existingKeys = [];
        foreach ($this->conversations as $conv) {
            $existingKeys[$conv['instanceId'] . '_' . $conv['moodleUserId']] = true;
        }

        // Get all mapped users from active instances
        $instances = new MoodleInstance();
        $activeInstances = $instances->all([
            new DataBaseWhere('status', 'active'),
        ]);

        $users = [];
        $userMap = new MoodleUserMap();
        foreach ($activeInstances as $instance) {
            if (empty($instance->service_userid) || empty($instance->token)) {
                continue;
            }

            $maps = $userMap->all([
                new DataBaseWhere('idinstance', $instance->id),
            ], ['moodle_username' => 'ASC'], 0, 0);

            foreach ($maps as $map) {
                // Skip the service user itself
                if ((int)$map->moodle_userid === (int)$instance->service_userid) {
                    continue;
                }

                // Skip users who already have an open conversation
                $key = $instance->id . '_' . $map->moodle_userid;
                if (isset($existingKeys[$key])) {
                    continue;
                }

                $contacto = $map->getContacto();
                $contactName = trim($contacto->nombre . ' ' . ($contacto->apellidos ?? ''));
                if (empty($contactName)) {
                    $contactName = $map->moodle_username ?: ('User #' . $map->moodle_userid);
                }

                $users[] = [
                    'userMapId' => $map->id,
                    'moodleUserId' => $map->moodle_userid,
                    'moodleUsername' => $map->moodle_username,
                    'contactName' => $contactName,
                    'instanceId' => $instance->id,
                    'instanceName' => $instance->name,
                ];
            }
        }

        $this->response->setContent(json_encode(['users' => $users]));
    }

    private function getUnreadCountAjax(): void
    {
        $this->setTemplate(false);

        $total = 0;
        $instances = new MoodleInstance();
        $activeInstances = $instances->all([
            new DataBaseWhere('status', 'active'),
        ]);

        foreach ($activeInstances as $instance) {
            if (empty($instance->service_userid) || empty($instance->token)) {
                continue;
            }
            $total += MoodleClient::getUnreadConversationsCount($instance, (int)$instance->service_userid);
        }

        $this->response->setContent(json_encode(['unread' => $total]));
    }
}
