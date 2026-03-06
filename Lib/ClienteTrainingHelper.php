<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Base\DataBase;

class ClienteTrainingHelper
{
    public static function getTrainingData(string $codcliente): array
    {
        $db = new DataBase();
        $data = [
            'totalContacts' => 0,
            'enrolledContacts' => 0,
            'totalEnrolments' => 0,
            'enrolledCount' => 0,
            'pendingCount' => 0,
            'suspendedCount' => 0,
            'unenrolledCount' => 0,
            'totalInvoiced' => 0,
            'courseBreakdown' => [],
        ];

        $sql = "SELECT idcontacto FROM contactos WHERE codcliente = " . $db->var2str($codcliente);
        $contactRows = $db->select($sql);
        $contactIds = array_map(function ($r) { return (int)$r['idcontacto']; }, $contactRows);
        $data['totalContacts'] = count($contactIds);

        if (empty($contactIds)) {
            return $data;
        }

        $inList = implode(',', $contactIds);

        // contacts with at least one enrolment
        $sql = "SELECT COUNT(DISTINCT idcontacto) as total FROM moodle_enrolments WHERE idcontacto IN ($inList)";
        $result = $db->select($sql);
        $data['enrolledContacts'] = !empty($result) ? (int)$result[0]['total'] : 0;

        // enrolment status counts
        $sql = "SELECT status, COUNT(*) as total FROM moodle_enrolments WHERE idcontacto IN ($inList) GROUP BY status";
        foreach ($db->select($sql) as $row) {
            $key = $row['status'] . 'Count';
            if (isset($data[$key])) {
                $data[$key] = (int)$row['total'];
            }
        }
        $data['totalEnrolments'] = $data['enrolledCount'] + $data['pendingCount'] + $data['suspendedCount'] + $data['unenrolledCount'];

        // total invoiced
        $sql = "SELECT COALESCE(SUM(f.total), 0) as total"
            . " FROM facturascli f"
            . " INNER JOIN moodle_enrolments e ON e.idfactura = f.idfactura"
            . " WHERE e.idcontacto IN ($inList)";
        $result = $db->select($sql);
        $data['totalInvoiced'] = !empty($result) ? round((float)$result[0]['total'], 2) : 0;

        // course breakdown
        $concatExpr = strtolower(FS_DB_TYPE) === 'postgresql'
            ? "COALESCE(c.shortname, 'ID:' || e.moodle_courseid::text)"
            : "COALESCE(c.shortname, CONCAT('ID:', e.moodle_courseid))";
        $sql = "SELECT $concatExpr as course_name,"
            . " e.status, COUNT(*) as total"
            . " FROM moodle_enrolments e"
            . " LEFT JOIN moodle_course_map c ON e.idcourse_map = c.id"
            . " WHERE e.idcontacto IN ($inList)"
            . " GROUP BY course_name, e.status"
            . " ORDER BY course_name, e.status";
        $courses = [];
        foreach ($db->select($sql) as $row) {
            $name = $row['course_name'];
            if (!isset($courses[$name])) {
                $courses[$name] = ['name' => $name, 'enrolled' => 0, 'pending' => 0, 'suspended' => 0, 'unenrolled' => 0, 'total' => 0];
            }
            $courses[$name][$row['status']] = (int)$row['total'];
            $courses[$name]['total'] += (int)$row['total'];
        }
        $data['courseBreakdown'] = array_values($courses);

        return $data;
    }
}
