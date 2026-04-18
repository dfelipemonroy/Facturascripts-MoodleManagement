<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use FacturaScripts\Plugins\MoodleManagement\Lib\View\JsonForScript;

class MoodleDashboard extends Controller
{
    /**
     * Cache key for the rendered dashboard payload.
     *
     * F10.9 — the dashboard runs 7 aggregate SQL queries on every
     * page load. The admin polls this screen throughout the day and
     * external monitors hit it once per minute; caching the result
     * for 60 s cuts DB load by ~90% without masking real-time
     * changes for more than a minute.
     *
     * @since 2.0 — F10.9 · §6.22
     */
    private const DASH_CACHE_KEY = 'mm_dashboard_payload_v1';

    /** TTL in seconds for the dashboard cache. */
    private const DASH_CACHE_TTL = 60;

    /** @var array */
    public $dashboardData = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'moodle-dashboard';
        $data['icon'] = 'fa-solid fa-chart-pie';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);
        $this->setTemplate('MoodleDashboard');

        // F10.9 — cache hit short-circuit. Explicit refresh via
        // ?refresh=1 re-populates the cache so admins can force a
        // fresh view after a bulk import or sync.
        $refresh = $this->request->query->getBoolean('refresh', false);
        $cache = Tools::cache();
        $cached = $refresh ? null : $cache->get(self::DASH_CACHE_KEY);
        if (is_array($cached)) {
            $this->dashboardData = $cached;
            return;
        }
        $this->loadDashboardData();
        $cache->set(self::DASH_CACHE_KEY, $this->dashboardData, self::DASH_CACHE_TTL);
    }

    private function loadDashboardData(): void
    {
        $db = new DataBase();

        // Status counts
        $sql = "SELECT status, COUNT(*) as total FROM moodle_enrolments GROUP BY status";
        $statusCounts = ['pending' => 0, 'enrolled' => 0, 'suspended' => 0, 'unenrolled' => 0];
        foreach ($db->select($sql) as $row) {
            $statusCounts[$row['status']] = (int)$row['total'];
        }
        $this->dashboardData['statusCounts'] = $statusCounts;
        $this->dashboardData['totalEnrolments'] = array_sum($statusCounts);

        // Unbilled count
        $sql = "SELECT COUNT(*) as total FROM moodle_enrolments WHERE status = 'enrolled' AND idfactura IS NULL";
        $result = $db->select($sql);
        $this->dashboardData['unbilledCount'] = !empty($result) ? (int)$result[0]['total'] : 0;

        // Active instances
        $sql = "SELECT COUNT(*) as total FROM moodle_instances WHERE status = 'active'";
        $result = $db->select($sql);
        $this->dashboardData['activeInstances'] = !empty($result) ? (int)$result[0]['total'] : 0;

        // Total mapped users
        $sql = "SELECT COUNT(*) as total FROM moodle_user_map";
        $result = $db->select($sql);
        $this->dashboardData['totalUsers'] = !empty($result) ? (int)$result[0]['total'] : 0;

        // Total courses
        $sql = "SELECT COUNT(*) as total FROM moodle_course_map";
        $result = $db->select($sql);
        $this->dashboardData['totalCourses'] = !empty($result) ? (int)$result[0]['total'] : 0;

        // Enrolments by month (last 6 months)
        $months = [];
        $monthCounts = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = date('Y-m', strtotime("-{$i} months"));
            $months[] = date('M Y', strtotime("-{$i} months"));
            $monthCounts[$date] = 0;
        }
        $sixMonthsAgo = date('Y-m-01', strtotime('-5 months'));
        $dateExpr = strtolower(FS_DB_TYPE) === 'postgresql'
            ? "TO_CHAR(enrolment_date, 'YYYY-MM')"
            : "DATE_FORMAT(enrolment_date, '%Y-%m')";
        $sql = "SELECT {$dateExpr} as month, COUNT(*) as total"
            . " FROM moodle_enrolments WHERE enrolment_date >= " . $db->var2str($sixMonthsAgo)
            . " GROUP BY {$dateExpr} ORDER BY month";
        foreach ($db->select($sql) as $row) {
            if (isset($monthCounts[$row['month']])) {
                $monthCounts[$row['month']] = (int)$row['total'];
            }
        }
        $this->dashboardData['monthLabels'] = array_values($months);
        $this->dashboardData['monthData'] = array_values($monthCounts);

        // Top 5 courses by enrolments
        $sql = "SELECT e.moodle_courseid, COALESCE(c.shortname, CONCAT('ID:', e.moodle_courseid)) as course_name,"
            . " COUNT(*) as total FROM moodle_enrolments e"
            . " LEFT JOIN moodle_course_map c ON e.idcourse_map = c.id"
            . " GROUP BY e.moodle_courseid, c.shortname"
            . " ORDER BY total DESC LIMIT 5";
        $topCourses = [];
        $topCourseCounts = [];
        foreach ($db->select($sql) as $row) {
            $topCourses[] = $row['course_name'];
            $topCourseCounts[] = (int)$row['total'];
        }
        $this->dashboardData['topCourseLabels'] = $topCourses;
        $this->dashboardData['topCourseData'] = $topCourseCounts;

        // Enrolments by method
        $sql = "SELECT enrolment_method, COUNT(*) as total FROM moodle_enrolments GROUP BY enrolment_method ORDER BY total DESC";
        $methodLabels = [];
        $methodData = [];
        foreach ($db->select($sql) as $row) {
            $methodLabels[] = Tools::trans($row['enrolment_method']);
            $methodData[] = (int)$row['total'];
        }
        $this->dashboardData['methodLabels'] = $methodLabels;
        $this->dashboardData['methodData'] = $methodData;

        // FE-01 (2026-04-17) — pre-serialise every array that lands
        // inside a <script> block so the template never interpolates
        // untrusted upstream strings with the unsafe `|json_encode|raw`
        // pipeline. `JsonForScript::encode` hex-escapes `<`, `'`, `"`
        // and `&` so a course name containing `</script>` (or smart
        // quotes, or ampersands) can no longer close the tag.
        $this->dashboardData['monthLabelsJson'] = JsonForScript::encode($this->dashboardData['monthLabels']);
        $this->dashboardData['monthDataJson']   = JsonForScript::encode($this->dashboardData['monthData']);
        $this->dashboardData['topCourseLabelsJson'] = JsonForScript::encode($this->dashboardData['topCourseLabels']);
        $this->dashboardData['topCourseDataJson']   = JsonForScript::encode($this->dashboardData['topCourseData']);
        $this->dashboardData['methodLabelsJson'] = JsonForScript::encode($this->dashboardData['methodLabels']);
        $this->dashboardData['methodDataJson']   = JsonForScript::encode($this->dashboardData['methodData']);
    }
}
