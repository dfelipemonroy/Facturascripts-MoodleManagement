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

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class MoodleClient
{
    /**
     * Call a Moodle Web Service function via REST API.
     *
     * @param MoodleInstance $instance The Moodle instance to call
     * @param string $function The WS function name (e.g. core_webservice_get_site_info)
     * @param array $params Additional parameters for the function
     * @return array The decoded JSON response, or an error array
     */
    public static function callApi(MoodleInstance $instance, string $function, array $params = []): array
    {
        $endpoint = rtrim($instance->url, '/') . '/webservice/rest/server.php';

        $postData = array_merge([
            'wstoken' => $instance->token,
            'wsfunction' => $function,
            'moodlewsrestformat' => 'json',
        ], $params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR => 7,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'exception' => 'curl_error',
                'message' => $error,
            ];
        }

        if ($httpCode !== 200) {
            return [
                'exception' => 'http_error',
                'message' => 'HTTP ' . $httpCode,
            ];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            return [
                'exception' => 'json_error',
                'message' => 'Invalid JSON response from Moodle',
            ];
        }

        return $decoded;
    }

    /**
     * Test connection to a Moodle instance by calling core_webservice_get_site_info.
     * Returns the full site info on success, or an error array with 'exception' key.
     */
    public static function testConnection(MoodleInstance $instance): array
    {
        return self::callApi($instance, 'core_webservice_get_site_info');
    }

    /**
     * Apply site info data to the instance model.
     */
    public static function applySiteInfo(MoodleInstance $instance, array $siteInfo): void
    {
        $instance->moodle_version = $siteInfo['version'] ?? '';
        $instance->moodle_release = $siteInfo['release'] ?? '';
        $instance->site_name = $siteInfo['sitename'] ?? '';
        $instance->lang = $siteInfo['lang'] ?? '';
        $instance->service_username = $siteInfo['username'] ?? '';
        $instance->available_functions = isset($siteInfo['functions']) ? count($siteInfo['functions']) : 0;
        $instance->last_check = date('Y-m-d H:i:s');
        $instance->last_error = '';
        $instance->status = 'active';
    }

    /**
     * Apply error info to the instance model.
     */
    public static function applyError(MoodleInstance $instance, array $result): void
    {
        $instance->status = 'unreachable';
        $instance->last_error = $result['message'] ?? $result['exception'] ?? 'Unknown error';
        $instance->last_check = date('Y-m-d H:i:s');
    }

    /**
     * Get users from Moodle by search criteria.
     * @param array $criteria e.g. [['key' => 'email', 'value' => 'user@example.com']]
     */
    public static function getUsers(MoodleInstance $instance, array $criteria = []): array
    {
        $params = [];
        foreach ($criteria as $i => $criterion) {
            $params["criteria[$i][key]"] = $criterion['key'];
            $params["criteria[$i][value]"] = $criterion['value'];
        }
        return self::callApi($instance, 'core_user_get_users', $params);
    }

    /**
     * Get users by a specific field (id, email, username, idnumber).
     */
    public static function getUsersByField(MoodleInstance $instance, string $field, array $values): array
    {
        $params = ['field' => $field];
        foreach ($values as $i => $value) {
            $params["values[$i]"] = $value;
        }
        return self::callApi($instance, 'core_user_get_users_by_field', $params);
    }

    /**
     * Create a user in Moodle. Returns the result with the new user ID.
     */
    public static function createUser(MoodleInstance $instance, array $userData): array
    {
        $params = [];
        foreach ($userData as $key => $value) {
            $params["users[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_user_create_users', $params);
    }

    /**
     * Update an existing user in Moodle.
     */
    public static function updateUser(MoodleInstance $instance, int $moodleUserId, array $userData): array
    {
        $params = ["users[0][id]" => $moodleUserId];
        foreach ($userData as $key => $value) {
            $params["users[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_user_update_users', $params);
    }

    /**
     * Suspend or unsuspend a user in Moodle.
     */
    public static function suspendUser(MoodleInstance $instance, int $moodleUserId, bool $suspend = true): array
    {
        return self::updateUser($instance, $moodleUserId, ['suspended' => $suspend ? 1 : 0]);
    }

    /**
     * Delete users from Moodle.
     */
    public static function deleteUsers(MoodleInstance $instance, array $userIds): array
    {
        $params = [];
        foreach ($userIds as $i => $id) {
            $params["userids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_user_delete_users', $params);
    }

    // ---- Cohort methods ----

    /**
     * Get cohorts from Moodle.
     */
    public static function getCohorts(MoodleInstance $instance, array $cohortIds = []): array
    {
        $params = [];
        foreach ($cohortIds as $i => $id) {
            $params["cohortids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_cohort_get_cohorts', $params);
    }

    /**
     * Search cohorts in Moodle.
     */
    public static function searchCohorts(MoodleInstance $instance, string $query, int $contextId = 1): array
    {
        return self::callApi($instance, 'core_cohort_search_cohorts', [
            'query' => $query,
            'context' => ['contextid' => $contextId],
        ]);
    }

    /**
     * Create cohorts in Moodle.
     */
    public static function createCohort(MoodleInstance $instance, array $cohortData): array
    {
        $params = [];
        foreach ($cohortData as $key => $value) {
            $params["cohorts[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_cohort_create_cohorts', $params);
    }

    /**
     * Update cohorts in Moodle.
     */
    public static function updateCohort(MoodleInstance $instance, int $cohortId, array $cohortData): array
    {
        $params = ["cohorts[0][id]" => $cohortId];
        foreach ($cohortData as $key => $value) {
            $params["cohorts[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_cohort_update_cohorts', $params);
    }

    /**
     * Delete cohorts in Moodle.
     */
    public static function deleteCohorts(MoodleInstance $instance, array $cohortIds): array
    {
        $params = [];
        foreach ($cohortIds as $i => $id) {
            $params["cohortids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_cohort_delete_cohorts', $params);
    }

    /**
     * Add members to a cohort.
     */
    public static function addCohortMembers(MoodleInstance $instance, int $cohortId, array $userIds): array
    {
        $params = [];
        foreach ($userIds as $i => $userId) {
            $params["members[$i][cohorttype][type]"] = 'id';
            $params["members[$i][cohorttype][value]"] = $cohortId;
            $params["members[$i][usertype][type]"] = 'id';
            $params["members[$i][usertype][value]"] = $userId;
        }
        return self::callApi($instance, 'core_cohort_add_cohort_members', $params);
    }

    /**
     * Remove members from a cohort.
     */
    public static function deleteCohortMembers(MoodleInstance $instance, int $cohortId, array $userIds): array
    {
        $params = [];
        foreach ($userIds as $i => $userId) {
            $params["members[$i][cohortid]"] = $cohortId;
            $params["members[$i][userid]"] = $userId;
        }
        return self::callApi($instance, 'core_cohort_delete_cohort_members', $params);
    }

    /**
     * Get members of cohorts.
     */
    public static function getCohortMembers(MoodleInstance $instance, array $cohortIds): array
    {
        $params = [];
        foreach ($cohortIds as $i => $id) {
            $params["cohortids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_cohort_get_cohort_members', $params);
    }

    /**
     * Generate a Moodle username from a FS contact.
     * Uses email (before @) as base, falls back to nombre.apellidos.
     */
    public static function generateUsername(Contacto $contact): string
    {
        if (!empty($contact->email)) {
            // Use the part before @ as username
            $parts = explode('@', $contact->email);
            $username = strtolower(trim($parts[0]));
            // Remove invalid chars for Moodle username (only lowercase alphanumeric, -, _, .)
            $username = preg_replace('/[^a-z0-9\-_.]/', '', $username);
            if (!empty($username)) {
                return $username;
            }
        }

        // Fallback: nombre.apellidos
        $base = strtolower(trim(($contact->nombre ?? '') . '.' . ($contact->apellidos ?? '')));
        $base = preg_replace('/[^a-z0-9\-_.]/', '', str_replace(' ', '.', $base));
        return !empty($base) ? $base : 'user' . time();
    }

    // ---- Field mapping methods ----

    /**
     * Map FS contact fields to Moodle user data array for create/update.
     * Includes custom fields mapping if provided.
     */
    public static function contactToMoodleUser(Contacto $contact, array $customFieldsMap = []): array
    {
        $data = [
            'email' => $contact->email,
            'firstname' => $contact->nombre ?: 'Sin nombre',
            'lastname' => $contact->apellidos ?: $contact->nombre ?: 'Sin apellido',
            'firstnamephonetic' => '',
            'lastnamephonetic' => '',
            'middlename' => '',
            'alternatename' => '',
        ];

        if (!empty($contact->telefono1)) {
            $data['phone1'] = $contact->telefono1;
        }
        if (!empty($contact->telefono2)) {
            $data['phone2'] = $contact->telefono2;
        }
        if (!empty($contact->ciudad)) {
            $data['city'] = $contact->ciudad;
        }
        if (!empty($contact->codpais)) {
            $data['country'] = $contact->codpais;
        }
        if (!empty($contact->langcode)) {
            $data['lang'] = $contact->langcode;
        }
        if (!empty($contact->direccion)) {
            $data['address'] = $contact->direccion;
        }

        // Map custom fields: ['moodle_shortname' => 'fs_field_name']
        foreach ($customFieldsMap as $moodleField => $fsField) {
            if (property_exists($contact, $fsField) && !empty($contact->$fsField)) {
                $data['customfields'][] = [
                    'type' => $moodleField,
                    'value' => $contact->$fsField,
                ];
            }
        }

        return $data;
    }

    /**
     * Apply Moodle user data to a FS contact.
     * Includes custom fields reverse mapping if provided.
     */
    public static function moodleUserToContact(Contacto $contact, array $moodleUser, array $customFieldsMap = []): void
    {
        if (!empty($moodleUser['email'])) {
            $contact->email = $moodleUser['email'];
        }
        if (!empty($moodleUser['firstname'])) {
            $contact->nombre = $moodleUser['firstname'];
        }
        if (!empty($moodleUser['lastname'])) {
            $contact->apellidos = $moodleUser['lastname'];
        }
        if (!empty($moodleUser['phone1'])) {
            $contact->telefono1 = $moodleUser['phone1'];
        }
        if (!empty($moodleUser['phone2'])) {
            $contact->telefono2 = $moodleUser['phone2'];
        }
        if (!empty($moodleUser['city'])) {
            $contact->ciudad = $moodleUser['city'];
        }
        if (!empty($moodleUser['country'])) {
            $contact->codpais = $moodleUser['country'];
        }
        if (!empty($moodleUser['lang'])) {
            $contact->langcode = $moodleUser['lang'];
        }
        if (!empty($moodleUser['address'])) {
            $contact->direccion = $moodleUser['address'];
        }

        // Reverse map custom fields: ['moodle_shortname' => 'fs_field_name']
        if (!empty($customFieldsMap) && !empty($moodleUser['customfields'])) {
            $reverseMap = array_flip($customFieldsMap);
            foreach ($moodleUser['customfields'] as $cf) {
                $shortname = $cf['shortname'] ?? '';
                if (isset($customFieldsMap[$shortname]) && property_exists($contact, $customFieldsMap[$shortname])) {
                    $fsField = $customFieldsMap[$shortname];
                    $contact->$fsField = $cf['value'] ?? '';
                }
            }
        }
    }

    /**
     * Determine sync winner based on priority rule and timestamps.
     * Returns 'fs', 'moodle', or 'conflict'.
     */
    public static function resolveConflict(string $priority, ?string $fsModified, ?string $moodleModified): string
    {
        switch ($priority) {
            case 'fs_wins':
                return 'fs';
            case 'moodle_wins':
                return 'moodle';
            case 'newest_wins':
                if (empty($fsModified) && empty($moodleModified)) {
                    return 'fs';
                }
                if (empty($fsModified)) {
                    return 'moodle';
                }
                if (empty($moodleModified)) {
                    return 'fs';
                }
                $fsTime = strtotime($fsModified);
                $moodleTime = is_numeric($moodleModified) ? (int)$moodleModified : strtotime($moodleModified);
                return $fsTime >= $moodleTime ? 'fs' : 'moodle';
            default:
                return 'conflict';
        }
    }
}
