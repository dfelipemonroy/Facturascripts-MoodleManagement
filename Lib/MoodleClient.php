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
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\IpValidator;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

class MoodleClient
{
    /**
     * Default HTTP timeout (seconds) for synchronous (UI) Moodle API calls.
     * Cron jobs may override via $timeout parameter (see Fase 7 F7.7).
     *
     * @since 2.0
     */
    public const TIMEOUT_SECONDS = 60;

    /**
     * TCP connect timeout (seconds) — separate from overall timeout.
     *
     * @since 2.0
     */
    public const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * curl POSTREDIR bitmask for 301/302/303 redirects.
     * Will be deprecated when SSRF hardening lands (Fase 7 F7.1) and
     * follow-redirects is disabled.
     *
     * @since 2.0
     */
    public const REDIRECT_METHODS_BITMASK = 7;

    /**
     * Maximum response size (bytes) the client accepts before aborting.
     * Enforced by CURLOPT_WRITEFUNCTION in Fase 7 F7.6.
     *
     * @since 2.0
     */
    public const MAX_RESPONSE_BYTES = 20 * 1024 * 1024;

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

        // F7.1 — SSRF gate. Rejects requests whose host resolves to
        // a private/loopback/link-local address (AWS metadata,
        // intranet Moodle instances misconfigured as public URL,
        // localhost dev machine reachable from prod worker).
        try {
            IpValidator::assertPublicHost($endpoint);
        } catch (\Throwable $e) {
            Tools::log()->warning('moodle-ssrf-rejected', [
                'instance' => (int) $instance->id,
                'endpoint' => $endpoint,
                'reason'   => $e->getMessage(),
            ]);
            return [
                'exception' => 'ssrf_rejected',
                'message'   => 'host_private_or_unresolvable',
            ];
        }

        // F7.13 — always force JSON output, regardless of whatever
        // the caller may have passed in $params.
        $postData = array_merge($params, [
            'wstoken'            => $instance->token,
            'wsfunction'         => $function,
            'moodlewsrestformat' => 'json',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            // F7.1 — do not follow redirects automatically. A hostile
            // Moodle (or MITM) could 302 to an internal address. The
            // WS endpoint answers directly with 200; a 3xx response
            // is treated as an error.
            CURLOPT_FOLLOWLOCATION => false,
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

        // some Moodle WS functions return "null" or empty string on success
        if ($response === '' || $response === 'null') {
            return [];
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'exception' => 'json_error',
                'message' => 'Invalid JSON response from Moodle',
            ];
        }

        // some WS functions (e.g. core_courseformat_update_course) return a
        // JSON-encoded string, which results in a double-encoded response;
        // detect and decode the inner layer
        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);
            return is_array($inner) ? $inner : [];
        }

        return $decoded ?? [];
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
        $instance->service_userid = $siteInfo['userid'] ?? 0;
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

    // ---- Course methods ----

    /**
     * Get all courses, or specific courses by ID.
     */
    public static function getCourses(MoodleInstance $instance, array $courseIds = []): array
    {
        $params = [];
        foreach ($courseIds as $i => $id) {
            $params["options[ids][$i]"] = $id;
        }
        return self::callApi($instance, 'core_course_get_courses', $params);
    }

    /**
     * Get courses by field (id, shortname, idnumber, category).
     */
    public static function getCoursesByField(MoodleInstance $instance, string $field = '', string $value = ''): array
    {
        $params = [];
        if (!empty($field)) {
            $params['field'] = $field;
            $params['value'] = $value;
        }
        return self::callApi($instance, 'core_course_get_courses_by_field', $params);
    }

    /**
     * Search courses by text.
     */
    public static function searchCourses(MoodleInstance $instance, string $query, int $page = 0, int $perPage = 50): array
    {
        return self::callApi($instance, 'core_course_search_courses', [
            'criterianame' => 'search',
            'criteriavalue' => $query,
            'page' => $page,
            'perpage' => $perPage,
        ]);
    }

    /**
     * Create a course in Moodle.
     */
    public static function createCourse(MoodleInstance $instance, array $courseData): array
    {
        $params = [];
        foreach ($courseData as $key => $value) {
            $params["courses[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_course_create_courses', $params);
    }

    /**
     * Update a course in Moodle.
     */
    public static function updateCourse(MoodleInstance $instance, int $courseId, array $courseData): array
    {
        $params = ["courses[0][id]" => $courseId];
        foreach ($courseData as $key => $value) {
            $params["courses[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_course_update_courses', $params);
    }

    /**
     * Delete courses from Moodle.
     */
    public static function deleteCourses(MoodleInstance $instance, array $courseIds): array
    {
        $params = [];
        foreach ($courseIds as $i => $id) {
            $params["courseids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_course_delete_courses', $params);
    }

    /**
     * Duplicate a course in Moodle.
     */
    public static function duplicateCourse(MoodleInstance $instance, int $courseId, string $fullname, string $shortname, int $categoryId, bool $visible = true): array
    {
        return self::callApi($instance, 'core_course_duplicate_course', [
            'courseid' => $courseId,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'categoryid' => $categoryId,
            'visible' => $visible ? 1 : 0,
        ]);
    }

    /**
     * Get course contents (sections and modules).
     */
    public static function getCourseContents(MoodleInstance $instance, int $courseId): array
    {
        return self::callApi($instance, 'core_course_get_contents', [
            'courseid' => $courseId,
        ]);
    }

    // ---- Course Category methods ----

    /**
     * Get course categories from Moodle.
     */
    public static function getCategories(MoodleInstance $instance, array $criteria = [], bool $addSubcategories = true): array
    {
        $params = ['addsubcategories' => $addSubcategories ? 1 : 0];
        foreach ($criteria as $i => $c) {
            $params["criteria[$i][key]"] = $c['key'];
            $params["criteria[$i][value]"] = $c['value'];
        }
        return self::callApi($instance, 'core_course_get_categories', $params);
    }

    /**
     * Create a category in Moodle.
     */
    public static function createCategory(MoodleInstance $instance, array $categoryData): array
    {
        $params = [];
        foreach ($categoryData as $key => $value) {
            $params["categories[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_course_create_categories', $params);
    }

    /**
     * Update a category in Moodle.
     */
    public static function updateCategory(MoodleInstance $instance, int $categoryId, array $categoryData): array
    {
        $params = ["categories[0][id]" => $categoryId];
        foreach ($categoryData as $key => $value) {
            $params["categories[0][$key]"] = $value;
        }
        return self::callApi($instance, 'core_course_update_categories', $params);
    }

    /**
     * Delete categories from Moodle.
     */
    public static function deleteCategories(MoodleInstance $instance, array $categoryIds): array
    {
        $params = [];
        foreach ($categoryIds as $i => $id) {
            $params["categories[$i][id]"] = $id;
            $params["categories[$i][newparent]"] = 0;
        }
        return self::callApi($instance, 'core_course_delete_categories', $params);
    }

    // ---- Course field mapping methods ----

    /**
     * Apply Moodle course data to a MoodleCourseMap model.
     */
    public static function moodleCourseToMap(\FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap $map, array $moodleCourse): void
    {
        $map->moodle_courseid = $moodleCourse['id'] ?? $map->moodle_courseid;
        $map->shortname = $moodleCourse['shortname'] ?? '';
        $map->fullname = $moodleCourse['fullname'] ?? '';
        $map->summary = strip_tags($moodleCourse['summary'] ?? '');
        $map->moodle_categoryid = $moodleCourse['categoryid'] ?? 0;
        $map->format = $moodleCourse['format'] ?? 'topics';
        $map->startdate = $moodleCourse['startdate'] ?? 0;
        $map->enddate = $moodleCourse['enddate'] ?? 0;
        $map->visible = (bool)($moodleCourse['visible'] ?? true);
        $map->enrolled_count = $moodleCourse['enrolledusercount'] ?? $map->enrolled_count ?? 0;
    }

    /**
     * Build Moodle course data array from a MoodleCourseMap model.
     */
    public static function mapToMoodleCourse(\FacturaScripts\Plugins\MoodleManagement\Model\MoodleCourseMap $map): array
    {
        return [
            'fullname' => $map->fullname,
            'shortname' => $map->shortname,
            'summary' => $map->summary,
            'categoryid' => $map->moodle_categoryid ?: 1,
            'format' => $map->format ?: 'topics',
            'startdate' => $map->startdate ?: 0,
            'enddate' => $map->enddate ?: 0,
            'visible' => $map->visible ? 1 : 0,
        ];
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

    /**
     * Get course overview files from Moodle.
     * Uses getCoursesByField which returns overviewfiles (getCourses does not).
     */
    public static function getOverviewFiles(MoodleInstance $instance, int $courseId): array
    {
        $result = self::getCoursesByField($instance, 'id', (string)$courseId);
        $courses = $result['courses'] ?? [];
        if (!empty($courses[0]['overviewfiles'])) {
            return $courses[0]['overviewfiles'];
        }
        return [];
    }

    /**
     * MIME types accepted by downloadFile(). `image/svg+xml` is
     * deliberately excluded: SVG can carry <script>/<foreignObject>
     * and would be XSS-inert only if sanitised, which we don't do
     * for file attachments. F3.9 + §3.12 of the audit.
     *
     * @since 2.0
     */
    private const DOWNLOAD_MIME_ALLOWLIST = [
        'image/png',
        'image/jpeg',
        'image/jpg',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    /**
     * Download a file from Moodle (appending WS token to URL).
     * Returns the local filename on success, or empty string on failure.
     *
     * @since 2.0 — hardened with MIME allowlist (F3.9).
     * Fase 7 F7.2 will move the token out of the URL into an
     * Authorization header.
     */
    public static function downloadFile(MoodleInstance $instance, string $fileUrl): string
    {
        $separator = strpos($fileUrl, '?') !== false ? '&' : '?';
        $url = $fileUrl . $separator . 'token=' . $instance->token;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($content === false || $httpCode !== 200) {
            return '';
        }

        // F3.9 — strict MIME allowlist. Strip charset / boundary
        // suffix before comparing: `image/png; charset=binary` ->
        // `image/png`.
        $normalisedCt = strtolower(trim(strtok((string) $contentType, ';')));
        if (!in_array($normalisedCt, self::DOWNLOAD_MIME_ALLOWLIST, true)) {
            Tools::log()->warning('moodle-download-bad-mime', [
                'url_host' => parse_url($fileUrl, PHP_URL_HOST),
                'received_content_type' => $normalisedCt,
            ]);
            return '';
        }

        $urlPath = parse_url($fileUrl, PHP_URL_PATH);
        $filename = basename((string) $urlPath);
        if (empty($filename)) {
            $filename = 'moodle_course_image.jpg';
        }

        $folder = Tools::folder('MyFiles');
        $localPath = $folder . '/' . $filename;
        file_put_contents($localPath, $content);

        return $filename;
    }

    // ── Enrolment methods ────────────────────────────────────────────────

    /**
     * Enrol users into courses via manual enrolment plugin.
     *
     * @param MoodleInstance $instance
     * @param array $enrolments Each element: ['userid'=>int, 'courseid'=>int, 'roleid'=>int, 'timestart'=>int, 'timeend'=>int, 'suspend'=>int]
     * @return array Empty on success, or error array
     */
    public static function enrolUsers(MoodleInstance $instance, array $enrolments): array
    {
        $params = [];
        foreach ($enrolments as $i => $enrol) {
            $params["enrolments[$i][roleid]"] = $enrol['roleid'] ?? 5;
            $params["enrolments[$i][userid]"] = $enrol['userid'];
            $params["enrolments[$i][courseid]"] = $enrol['courseid'];
            if (isset($enrol['timestart'])) {
                $params["enrolments[$i][timestart]"] = $enrol['timestart'];
            }
            if (isset($enrol['timeend'])) {
                $params["enrolments[$i][timeend]"] = $enrol['timeend'];
            }
            if (isset($enrol['suspend'])) {
                $params["enrolments[$i][suspend]"] = $enrol['suspend'];
            }
        }
        return self::callApi($instance, 'enrol_manual_enrol_users', $params);
    }

    /**
     * Unenrol users from courses via manual enrolment plugin.
     *
     * @param MoodleInstance $instance
     * @param array $enrolments Each element: ['userid'=>int, 'courseid'=>int]
     * @return array Empty on success, or error array
     */
    public static function unenrolUsers(MoodleInstance $instance, array $enrolments): array
    {
        $params = [];
        foreach ($enrolments as $i => $enrol) {
            $params["enrolments[$i][userid]"] = $enrol['userid'];
            $params["enrolments[$i][courseid]"] = $enrol['courseid'];
        }
        return self::callApi($instance, 'enrol_manual_unenrol_users', $params);
    }

    /**
     * Get users enrolled in a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param bool $onlyActive If true, filter to only active enrolments
     * @return array List of enrolled users or error array
     */
    public static function getEnrolledUsers(MoodleInstance $instance, int $courseId, bool $onlyActive = true): array
    {
        $params = ['courseid' => $courseId];
        if ($onlyActive) {
            $params['options[0][name]'] = 'onlyactive';
            $params['options[0][value]'] = '1';
        }
        return self::callApi($instance, 'core_enrol_get_enrolled_users', $params);
    }

    /**
     * Get courses a user is enrolled in.
     *
     * @param MoodleInstance $instance
     * @param int $userId Moodle user ID
     * @return array List of courses or error array
     */
    public static function getUserCourses(MoodleInstance $instance, int $userId): array
    {
        return self::callApi($instance, 'core_enrol_get_users_courses', [
            'userid' => $userId,
        ]);
    }

    /**
     * Get enrolment methods available for a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @return array List of enrolment method instances or error array
     */
    public static function getCourseEnrolmentMethods(MoodleInstance $instance, int $courseId): array
    {
        return self::callApi($instance, 'core_enrol_get_course_enrolment_methods', [
            'courseid' => $courseId,
        ]);
    }

    /**
     * Self-enrol the authenticated user in a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param string $password Enrolment key (if required)
     * @param int $instanceId Enrolment instance ID (0 = first available self-enrolment)
     * @return array Result with 'status' key or error array
     */
    public static function selfEnrolUser(MoodleInstance $instance, int $courseId, string $password = '', int $instanceId = 0): array
    {
        $params = ['courseid' => $courseId];
        if (!empty($password)) {
            $params['password'] = $password;
        }
        if ($instanceId > 0) {
            $params['instanceid'] = $instanceId;
        }
        return self::callApi($instance, 'enrol_self_enrol_user', $params);
    }

    /**
     * Get self-enrolment instance info (e.g. whether password is required).
     *
     * @param MoodleInstance $instance
     * @param int $instanceId The enrolment instance ID
     * @return array Instance info or error array
     */
    public static function getSelfEnrolmentInfo(MoodleInstance $instance, int $instanceId): array
    {
        return self::callApi($instance, 'enrol_self_get_instance_info', [
            'instanceid' => $instanceId,
        ]);
    }

    /**
     * Add meta enrolment instances (link courses so enrolments propagate).
     *
     * @param MoodleInstance $instance
     * @param int $courseId The course to add meta enrolment to
     * @param array $linkedCourseIds Array of course IDs to link from
     * @return array Result or error array
     */
    public static function addMetaEnrolInstances(MoodleInstance $instance, int $courseId, array $linkedCourseIds): array
    {
        $params = [];
        foreach ($linkedCourseIds as $i => $linkedId) {
            $params["instances[$i][metacourseid]"] = $courseId;
            $params["instances[$i][courseid]"] = $linkedId;
        }
        return self::callApi($instance, 'enrol_meta_add_instances', $params);
    }

    /**
     * Delete meta enrolment instances.
     *
     * @param MoodleInstance $instance
     * @param array $instanceIds Array of enrolment instance IDs to delete
     * @return array Result or error array
     */
    public static function deleteMetaEnrolInstances(MoodleInstance $instance, array $instanceIds): array
    {
        $params = [];
        foreach ($instanceIds as $i => $id) {
            $params["instanceids[$i]"] = $id;
        }
        return self::callApi($instance, 'enrol_meta_delete_instances', $params);
    }

    /**
     * Get potential users to enrol in a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param string $search Search string to filter users
     * @param int $page Page number (0-based)
     * @param int $perPage Results per page
     * @return array List of potential users or error array
     */
    public static function getPotentialUsers(MoodleInstance $instance, int $courseId, string $search = '', int $page = 0, int $perPage = 25): array
    {
        return self::callApi($instance, 'core_enrol_get_potential_users', [
            'courseid' => $courseId,
            'search' => $search,
            'searchanywhere' => 1,
            'page' => $page,
            'perpage' => $perPage,
        ]);
    }

    // ── Role assignment methods ───────────────────────────────────────────

    /**
     * Assign roles to users in Moodle.
     *
     * @param MoodleInstance $instance
     * @param array $assignments Each element: ['userid'=>int, 'roleid'=>int, 'contextid'=>int] or ['userid'=>int, 'roleid'=>int, 'contextlevel'=>string, 'instanceid'=>int]
     * @return array Empty on success, or error array
     */
    public static function assignRoles(MoodleInstance $instance, array $assignments): array
    {
        $params = [];
        foreach ($assignments as $i => $assignment) {
            $params["assignments[$i][roleid]"] = $assignment['roleid'];
            $params["assignments[$i][userid]"] = $assignment['userid'];
            if (isset($assignment['contextid'])) {
                $params["assignments[$i][contextid]"] = $assignment['contextid'];
            } else {
                $params["assignments[$i][contextlevel]"] = $assignment['contextlevel'] ?? 'course';
                $params["assignments[$i][instanceid]"] = $assignment['instanceid'] ?? 0;
            }
        }
        return self::callApi($instance, 'core_role_assign_roles', $params);
    }

    /**
     * Unassign roles from users in Moodle.
     *
     * @param MoodleInstance $instance
     * @param array $unassignments Each element: ['userid'=>int, 'roleid'=>int, 'contextid'=>int] or ['userid'=>int, 'roleid'=>int, 'contextlevel'=>string, 'instanceid'=>int]
     * @return array Empty on success, or error array
     */
    public static function unassignRoles(MoodleInstance $instance, array $unassignments): array
    {
        $params = [];
        foreach ($unassignments as $i => $unassignment) {
            $params["unassignments[$i][roleid]"] = $unassignment['roleid'];
            $params["unassignments[$i][userid]"] = $unassignment['userid'];
            if (isset($unassignment['contextid'])) {
                $params["unassignments[$i][contextid]"] = $unassignment['contextid'];
            } else {
                $params["unassignments[$i][contextlevel]"] = $unassignment['contextlevel'] ?? 'course';
                $params["unassignments[$i][instanceid]"] = $unassignment['instanceid'] ?? 0;
            }
        }
        return self::callApi($instance, 'core_role_unassign_roles', $params);
    }

    /**
     * Search users for enrolment in a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param string $search Search term
     * @param bool $searchAnywhere Search anywhere in fields (not just start)
     * @param int $page Page number (0-based)
     * @param int $perPage Results per page
     * @return array List of matching users or error array
     */
    public static function searchEnrolUsers(MoodleInstance $instance, int $courseId, string $search = '', bool $searchAnywhere = true, int $page = 0, int $perPage = 25): array
    {
        return self::callApi($instance, 'core_enrol_search_users', [
            'courseid' => $courseId,
            'search' => $search,
            'searchanywhere' => $searchAnywhere ? 1 : 0,
            'page' => $page,
            'perpage' => $perPage,
        ]);
    }

    // ── Course module & section management ──────────────────────────────

    /**
     * Get detailed information about a course module by its cmid.
     *
     * @param MoodleInstance $instance
     * @param int $cmid Course module ID
     * @return array Module details or error array
     */
    public static function getCourseModule(MoodleInstance $instance, int $cmid): array
    {
        return self::callApi($instance, 'core_course_get_course_module', [
            'cmid' => $cmid,
        ]);
    }

    /**
     * Get course module by module type name and instance ID.
     *
     * @param MoodleInstance $instance
     * @param string $modname Module name (e.g. 'forum', 'assign')
     * @param int $instanceId Module instance ID
     * @return array Module details or error array
     */
    public static function getCourseModuleByInstance(MoodleInstance $instance, string $modname, int $instanceId): array
    {
        return self::callApi($instance, 'core_course_get_course_module_by_instance', [
            'module' => $modname,
            'instance' => $instanceId,
        ]);
    }

    /**
     * Get available activity types for a course (activity chooser).
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @return array Content items or error array
     */
    public static function getCourseContentItems(MoodleInstance $instance, int $courseId): array
    {
        return self::callApi($instance, 'core_course_get_course_content_items', [
            'courseid' => $courseId,
        ]);
    }

    /**
     * Execute a course editor action via core_courseformat_update_course.
     * This is the unified API for all course editing operations (Moodle 4.0+).
     *
     * @param MoodleInstance $instance
     * @param string $action Action name (e.g. 'cm_show', 'cm_hide', 'cm_move', 'section_add')
     * @param int $courseId Course ID
     * @param array $ids Affected IDs (cmids for cm_* actions, section ids for section_* actions)
     * @param int|null $targetSectionId Target section ID (for move operations)
     * @param int|null $targetCmId Target cm ID (for positioning)
     * @return array State updates JSON or error array
     */
    public static function updateCourseAction(MoodleInstance $instance, string $action, int $courseId, array $ids = [], ?int $targetSectionId = null, ?int $targetCmId = null): array
    {
        $params = [
            'action' => $action,
            'courseid' => $courseId,
        ];
        foreach ($ids as $i => $id) {
            $params["ids[$i]"] = (int)$id;
        }
        if ($targetSectionId !== null) {
            $params['targetsectionid'] = $targetSectionId;
        }
        if ($targetCmId !== null) {
            $params['targetcmid'] = $targetCmId;
        }
        return self::callApi($instance, 'core_courseformat_update_course', $params);
    }

    /**
     * Delete course modules by their cmids.
     *
     * @param MoodleInstance $instance
     * @param array $cmids Array of course module IDs to delete
     * @return array Empty on success, or error array
     */
    public static function deleteModules(MoodleInstance $instance, array $cmids): array
    {
        $params = [];
        foreach ($cmids as $i => $cmid) {
            $params["cmids[$i]"] = (int)$cmid;
        }
        return self::callApi($instance, 'core_course_delete_modules', $params);
    }

    /**
     * Show course modules (make visible).
     */
    public static function showModules(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_show', $courseId, $cmids);
    }

    /**
     * Hide course modules.
     */
    public static function hideModules(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_hide', $courseId, $cmids);
    }

    /**
     * Make modules available but not shown on course page (stealth).
     */
    public static function stealthModules(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_stealth', $courseId, $cmids);
    }

    /**
     * Move course modules to a target section or position.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param array $cmids Module IDs to move
     * @param int $targetSectionId Destination section ID
     * @param int|null $targetCmId Optional: place before this cm
     */
    public static function moveModules(MoodleInstance $instance, int $courseId, array $cmids, int $targetSectionId, ?int $targetCmId = null): array
    {
        return self::updateCourseAction($instance, 'cm_move', $courseId, $cmids, $targetSectionId, $targetCmId);
    }

    /**
     * Duplicate course modules.
     */
    public static function duplicateModules(MoodleInstance $instance, int $courseId, array $cmids, ?int $targetSectionId = null, ?int $targetCmId = null): array
    {
        return self::updateCourseAction($instance, 'cm_duplicate', $courseId, $cmids, $targetSectionId, $targetCmId);
    }

    /**
     * Add a new section to a course.
     *
     * @param MoodleInstance $instance
     * @param int $courseId
     * @param int|null $afterSectionId Insert after this section (null = at end)
     */
    public static function addSection(MoodleInstance $instance, int $courseId, ?int $afterSectionId = null): array
    {
        return self::updateCourseAction($instance, 'section_add', $courseId, [], $afterSectionId);
    }

    /**
     * Show course sections.
     */
    public static function showSections(MoodleInstance $instance, int $courseId, array $sectionIds): array
    {
        return self::updateCourseAction($instance, 'section_show', $courseId, $sectionIds);
    }

    /**
     * Hide course sections.
     */
    public static function hideSections(MoodleInstance $instance, int $courseId, array $sectionIds): array
    {
        return self::updateCourseAction($instance, 'section_hide', $courseId, $sectionIds);
    }

    /**
     * Delete course sections.
     */
    public static function deleteSections(MoodleInstance $instance, int $courseId, array $sectionIds): array
    {
        return self::updateCourseAction($instance, 'section_delete', $courseId, $sectionIds);
    }

    /**
     * Move sections after a target section.
     */
    public static function moveSectionAfter(MoodleInstance $instance, int $courseId, array $sectionIds, int $afterSectionId): array
    {
        return self::updateCourseAction($instance, 'section_move_after', $courseId, $sectionIds, $afterSectionId);
    }

    // ── Module indent ──

    /**
     * Indent modules to the right.
     */
    public static function indentRight(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_moveright', $courseId, $cmids);
    }

    /**
     * Indent modules to the left.
     */
    public static function indentLeft(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_moveleft', $courseId, $cmids);
    }

    // ── Module group mode ──

    /**
     * Set modules to no-groups mode.
     */
    public static function setNoGroups(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_nogroups', $courseId, $cmids);
    }

    /**
     * Set modules to visible-groups mode.
     */
    public static function setVisibleGroups(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_visiblegroups', $courseId, $cmids);
    }

    /**
     * Set modules to separate-groups mode.
     */
    public static function setSeparateGroups(MoodleInstance $instance, int $courseId, array $cmids): array
    {
        return self::updateCourseAction($instance, 'cm_separategroups', $courseId, $cmids);
    }

    // ========== Academic Progress / Completion / Grades ==========

    /**
     * Get course completion status for a user.
     */
    public static function getCourseCompletionStatus(MoodleInstance $instance, int $courseId, int $userId): array
    {
        return self::callApi($instance, 'core_completion_get_course_completion_status', [
            'courseid' => $courseId,
            'userid' => $userId,
        ]);
    }

    /**
     * Get activities completion status for a user in a course.
     */
    public static function getActivitiesCompletionStatus(MoodleInstance $instance, int $courseId, int $userId): array
    {
        return self::callApi($instance, 'core_completion_get_activities_completion_status', [
            'courseid' => $courseId,
            'userid' => $userId,
        ]);
    }

    /**
     * Get grade items for a user (all courses or specific course).
     */
    public static function getUserGradeItems(MoodleInstance $instance, int $userId, int $courseId = 0): array
    {
        $params = ['userid' => $userId];
        if ($courseId > 0) {
            $params['courseid'] = $courseId;
        }
        return self::callApi($instance, 'gradereport_user_get_grade_items', $params);
    }

    /**
     * Get academic progress summary for a user across all enrolled courses.
     * Returns array of courses with completion %, grade, and status.
     */
    public static function getAcademicProgress(MoodleInstance $instance, int $userId): array
    {
        $courses = self::getUserCourses($instance, $userId);
        if (isset($courses['exception']) || empty($courses)) {
            return $courses;
        }

        $progress = [];
        foreach ($courses as $course) {
            $courseId = $course['id'] ?? 0;
            if (empty($courseId)) {
                continue;
            }

            $entry = [
                'courseid' => $courseId,
                'fullname' => $course['fullname'] ?? '',
                'shortname' => $course['shortname'] ?? '',
                'completion_percentage' => 0,
                'completed_activities' => 0,
                'total_activities' => 0,
                'course_completed' => false,
                'grade' => null,
                'grade_max' => null,
                'last_access' => $course['lastaccess'] ?? 0,
            ];

            // Get activity completion
            $activities = self::getActivitiesCompletionStatus($instance, $courseId, $userId);
            if (!isset($activities['exception']) && isset($activities['statuses'])) {
                $total = count($activities['statuses']);
                $completed = 0;
                foreach ($activities['statuses'] as $status) {
                    if (($status['state'] ?? 0) > 0) {
                        $completed++;
                    }
                }
                $entry['total_activities'] = $total;
                $entry['completed_activities'] = $completed;
                $entry['completion_percentage'] = $total > 0 ? round(($completed / $total) * 100) : 0;
            }

            // Get course completion status
            $completion = self::getCourseCompletionStatus($instance, $courseId, $userId);
            if (!isset($completion['exception']) && isset($completion['completionstatus'])) {
                $completions = $completion['completionstatus']['completions'] ?? [];
                $allComplete = !empty($completions);
                foreach ($completions as $c) {
                    if (empty($c['complete'])) {
                        $allComplete = false;
                        break;
                    }
                }
                $entry['course_completed'] = $allComplete;
            }

            // Get grade
            $grades = self::getUserGradeItems($instance, $userId, $courseId);
            if (!isset($grades['exception']) && isset($grades['usergrades'])) {
                foreach ($grades['usergrades'] as $userGrade) {
                    $gradeItems = $userGrade['gradeitems'] ?? [];
                    foreach ($gradeItems as $item) {
                        if (($item['itemtype'] ?? '') === 'course') {
                            $entry['grade'] = $item['gradeformatted'] ?? $item['graderaw'] ?? null;
                            $entry['grade_max'] = $item['grademax'] ?? null;
                            break;
                        }
                    }
                }
            }

            $progress[] = $entry;
        }

        return $progress;
    }

    // ========== Badges / Certificates ==========

    /**
     * Get badges issued to a user.
     * WS: core_badges_get_user_badges
     */
    public static function getUserBadges(MoodleInstance $instance, int $userId, int $courseId = 0): array
    {
        $params = ['userid' => $userId];
        if ($courseId > 0) {
            $params['courseid'] = $courseId;
        }
        return self::callApi($instance, 'core_badges_get_user_badges', $params);
    }

    // ── Group Management ──

    public static function getCourseGroups(MoodleInstance $instance, int $courseId): array
    {
        return self::callApi($instance, 'core_group_get_course_groups', [
            'courseid' => $courseId,
        ]);
    }

    public static function createGroups(MoodleInstance $instance, array $groups): array
    {
        $params = [];
        foreach ($groups as $i => $group) {
            foreach ($group as $key => $value) {
                $params["groups[$i][$key]"] = $value;
            }
        }
        return self::callApi($instance, 'core_group_create_groups', $params);
    }

    public static function updateGroups(MoodleInstance $instance, array $groups): array
    {
        $params = [];
        foreach ($groups as $i => $group) {
            foreach ($group as $key => $value) {
                $params["groups[$i][$key]"] = $value;
            }
        }
        return self::callApi($instance, 'core_group_update_groups', $params);
    }

    public static function deleteGroups(MoodleInstance $instance, array $groupIds): array
    {
        $params = [];
        foreach ($groupIds as $i => $id) {
            $params["groupids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_group_delete_groups', $params);
    }

    public static function getGroupMembers(MoodleInstance $instance, array $groupIds): array
    {
        $params = [];
        foreach ($groupIds as $i => $id) {
            $params["groupids[$i]"] = $id;
        }
        return self::callApi($instance, 'core_group_get_group_members', $params);
    }

    public static function addGroupMembers(MoodleInstance $instance, array $members): array
    {
        $params = [];
        foreach ($members as $i => $member) {
            $params["members[$i][groupid]"] = $member['groupid'];
            $params["members[$i][userid]"] = $member['userid'];
        }
        return self::callApi($instance, 'core_group_add_group_members', $params);
    }

    public static function deleteGroupMembers(MoodleInstance $instance, array $members): array
    {
        $params = [];
        foreach ($members as $i => $member) {
            $params["members[$i][groupid]"] = $member['groupid'];
            $params["members[$i][userid]"] = $member['userid'];
        }
        return self::callApi($instance, 'core_group_delete_group_members', $params);
    }

    // ── Messaging ──

    /**
     * Send instant messages to Moodle users.
     *
     * @param MoodleInstance $instance
     * @param array $messages Array of ['touserid' => int, 'text' => string, 'textformat' => int (1=HTML)]
     * @return array
     */
    public static function sendInstantMessages(MoodleInstance $instance, array $messages): array
    {
        $params = [];
        foreach ($messages as $i => $msg) {
            $params["messages[$i][touserid]"] = $msg['touserid'];
            $params["messages[$i][text]"] = $msg['text'];
            $params["messages[$i][textformat]"] = $msg['textformat'] ?? 1;
        }
        return self::callApi($instance, 'core_message_send_instant_messages', $params);
    }

    /**
     * Get the conversation between two users.
     * Returns conversation object with id, members, messages, etc.
     */
    public static function getConversationBetweenUsers(MoodleInstance $instance, int $userid1, int $userid2, bool $includeMessages = true, int $limitMessages = 50): array
    {
        $params = [
            'userid' => $userid1,
            'otheruserid' => $userid2,
            'includecontactrequests' => 0,
            'includeprivacyinfo' => 0,
        ];

        $result = self::callApi($instance, 'core_message_get_conversation_between_users', $params);

        if (isset($result['exception']) || !isset($result['id'])) {
            return $result;
        }

        if ($includeMessages) {
            $messages = self::getConversationMessages($instance, (int)$result['id'], $userid1, 0, $limitMessages, true);
            if (!isset($messages['exception'])) {
                $result['messages'] = $messages['messages'] ?? [];
            }
        }

        return $result;
    }

    /**
     * Get messages from a conversation.
     */
    public static function getConversationMessages(MoodleInstance $instance, int $conversationId, int $currentUserId, int $limitFrom = 0, int $limitNum = 50, bool $newest = true): array
    {
        return self::callApi($instance, 'core_message_get_conversation_messages', [
            'currentuserid' => $currentUserId,
            'convid' => $conversationId,
            'limitfrom' => $limitFrom,
            'limitnum' => $limitNum,
            'newest' => $newest ? 1 : 0,
        ]);
    }

    /**
     * Send a message within an existing conversation.
     */
    public static function sendMessageToConversation(MoodleInstance $instance, int $conversationId, string $text, int $textFormat = 1): array
    {
        $params = [
            'conversationid' => $conversationId,
            'messages[0][text]' => $text,
            'messages[0][textformat]' => $textFormat,
        ];
        return self::callApi($instance, 'core_message_send_messages_to_conversation', $params);
    }

    /**
     * Get conversations for a user (typically the WS service user).
     * Returns conversations with members, last message, and unread count.
     */
    public static function getConversations(MoodleInstance $instance, int $userid, int $type = 1, int $limitFrom = 0, int $limitNum = 50): array
    {
        return self::callApi($instance, 'core_message_get_conversations', [
            'userid' => $userid,
            'type' => $type,
            'limitfrom' => $limitFrom,
            'limitnum' => $limitNum,
        ]);
    }

    /**
     * Get unread conversation count for a user.
     */
    public static function markAllConversationMessagesAsRead(MoodleInstance $instance, int $userid, int $conversationid): array
    {
        return self::callApi($instance, 'core_message_mark_all_conversation_messages_as_read', [
            'userid' => $userid,
            'conversationid' => $conversationid,
        ]);
    }

    public static function getUnreadConversationsCount(MoodleInstance $instance, int $userid): int
    {
        // This WS function returns a bare integer, not a JSON object.
        // callApi returns it as-is (int) due to json_decode behavior.
        $result = self::callApi($instance, 'core_message_get_unread_conversations_count', [
            'useridto' => $userid,
        ]);

        if (is_array($result) && isset($result['exception'])) {
            return 0;
        }

        return is_numeric($result) ? (int)$result : 0;
    }

    // ── Notes ────────────────────────────────────────────────────────────

    /**
     * Create notes on user profiles in Moodle.
     * @param array $notes Each note: ['userid' => int, 'courseid' => int, 'text' => string, 'publishstate' => 'personal'|'course'|'site']
     */
    public static function createNotes(MoodleInstance $instance, array $notes): array
    {
        $params = [];
        foreach ($notes as $i => $note) {
            $params["notes[$i][userid]"] = $note['userid'];
            $params["notes[$i][courseid]"] = $note['courseid'] ?? 1;
            $params["notes[$i][publishstate]"] = $note['publishstate'] ?? 'site';
            $params["notes[$i][text]"] = $note['text'];
        }
        return self::callApi($instance, 'core_notes_create_notes', $params);
    }

    /**
     * Get notes for a user in a course (or all courses).
     */
    public static function getNotes(MoodleInstance $instance, int $courseid, int $userid = 0): array
    {
        $params = ['courseid' => $courseid];
        if ($userid > 0) {
            $params['userid'] = $userid;
        }
        return self::callApi($instance, 'core_notes_get_course_notes', $params);
    }

    /**
     * Delete notes by IDs.
     * @param int[] $noteIds
     */
    public static function deleteNotes(MoodleInstance $instance, array $noteIds): array
    {
        $params = [];
        foreach ($noteIds as $i => $id) {
            $params["notes[$i]"] = $id;
        }
        return self::callApi($instance, 'core_notes_delete_notes', $params);
    }

    // ── Calendar ─────────────────────────────────────────────────────────

    /**
     * Create calendar events in Moodle.
     * @param array $events Each event: ['name' => string, 'description' => string, 'courseid' => int, 'userid' => int, 'timestart' => int, 'timeduration' => int, 'eventtype' => 'user'|'course'|'site']
     */
    public static function createCalendarEvents(MoodleInstance $instance, array $events): array
    {
        $params = [];
        foreach ($events as $i => $event) {
            foreach ($event as $key => $value) {
                $params["events[$i][$key]"] = $value;
            }
        }
        return self::callApi($instance, 'core_calendar_create_calendar_events', $params);
    }

    /**
     * Get calendar events for a period.
     */
    public static function getCalendarEvents(MoodleInstance $instance, array $options = []): array
    {
        $params = [];
        if (!empty($options['eventids'])) {
            foreach ($options['eventids'] as $i => $id) {
                $params["options[eventids][$i]"] = $id;
            }
        }
        if (isset($options['timestart'])) {
            $params['options[timestart]'] = $options['timestart'];
        }
        if (isset($options['timeend'])) {
            $params['options[timeend]'] = $options['timeend'];
        }
        if (isset($options['userevents'])) {
            $params['options[userevents]'] = $options['userevents'] ? 1 : 0;
        }
        if (isset($options['siteevents'])) {
            $params['options[siteevents]'] = $options['siteevents'] ? 1 : 0;
        }
        return self::callApi($instance, 'core_calendar_get_calendar_events', $params);
    }

    /**
     * Delete calendar events.
     * @param array $events Each: ['eventid' => int, 'repeat' => 0|1]
     */
    public static function deleteCalendarEvents(MoodleInstance $instance, array $events): array
    {
        $params = [];
        foreach ($events as $i => $event) {
            $params["events[$i][eventid]"] = $event['eventid'];
            $params["events[$i][repeat]"] = $event['repeat'] ?? 0;
        }
        return self::callApi($instance, 'core_calendar_delete_calendar_events', $params);
    }
}
