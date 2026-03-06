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

class MoodleDashboard extends Controller
{
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
        $this->loadDashboardData();
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
    }
}
