<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use FacturaScripts\Core\Base\DataBase;

class ClienteTrainingHelper
{
    /**
     * Chunk size when the contact IDs list exceeds this threshold.
     * Large IN(...) lists bloat the SQL payload, and MySQL default
     * max_allowed_packet is 16MB — each id is up to 10 bytes so at
     * 500k ids we'd blow past it. Chunking also reduces the query
     * plan cost on optimiser-friendly databases.
     *
     * @since 2.0 F3.10
     */
    private const CHUNK_SIZE = 500;

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

        // F3.10 — defence in depth: cast each value and drop anything
        // that is not a strictly positive integer so no malicious
        // path can reach the IN() splice below.
        $contactIds = [];
        foreach ($contactRows as $r) {
            $id = (int) ($r['idcontacto'] ?? 0);
            if ($id > 0) {
                $contactIds[] = $id;
            }
        }
        $contactIds = array_values(array_unique($contactIds));
        $data['totalContacts'] = count($contactIds);

        if (empty($contactIds)) {
            return $data;
        }

        // F3.10 — chunk large lists.
        $chunks = array_chunk($contactIds, self::CHUNK_SIZE);

        $enrolledContacts = 0;
        $statusCounts = [];
        $totalInvoiced = 0.0;
        $courses = [];

        $concatExpr = strtolower(FS_DB_TYPE) === 'postgresql'
            ? "COALESCE(c.shortname, 'ID:' || e.moodle_courseid::text)"
            : "COALESCE(c.shortname, CONCAT('ID:', e.moodle_courseid))";

        foreach ($chunks as $chunk) {
            $inList = implode(',', $chunk); // safe: every element is an int.

            // contacts with at least one enrolment
            $sql = "SELECT COUNT(DISTINCT idcontacto) as total"
                . " FROM moodle_enrolments WHERE idcontacto IN ($inList)";
            $result = $db->select($sql);
            $enrolledContacts += !empty($result) ? (int) $result[0]['total'] : 0;

            // enrolment status counts
            $sql = "SELECT status, COUNT(*) as total"
                . " FROM moodle_enrolments WHERE idcontacto IN ($inList) GROUP BY status";
            foreach ($db->select($sql) as $row) {
                $status = (string) $row['status'];
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + (int) $row['total'];
            }

            // total invoiced
            $sql = "SELECT COALESCE(SUM(f.total), 0) as total"
                . " FROM facturascli f"
                . " INNER JOIN moodle_enrolments e ON e.idfactura = f.idfactura"
                . " WHERE e.idcontacto IN ($inList)";
            $result = $db->select($sql);
            $totalInvoiced += !empty($result) ? (float) $result[0]['total'] : 0.0;

            // course breakdown
            $sql = "SELECT $concatExpr as course_name,"
                . " e.status, COUNT(*) as total"
                . " FROM moodle_enrolments e"
                . " LEFT JOIN moodle_course_map c ON e.idcourse_map = c.id"
                . " WHERE e.idcontacto IN ($inList)"
                . " GROUP BY course_name, e.status"
                . " ORDER BY course_name, e.status";
            foreach ($db->select($sql) as $row) {
                $name = $row['course_name'];
                if (!isset($courses[$name])) {
                    $courses[$name] = ['name' => $name, 'enrolled' => 0, 'pending' => 0, 'suspended' => 0, 'unenrolled' => 0, 'total' => 0];
                }
                $s = (string) $row['status'];
                if (isset($courses[$name][$s])) {
                    $courses[$name][$s] += (int) $row['total'];
                }
                $courses[$name]['total'] += (int) $row['total'];
            }
        }

        // enrolledContacts counted per-chunk is an over-estimate if a
        // contact appears in several chunks — but our chunks are
        // disjoint (array_chunk over unique IDs), so the sum is exact.
        $data['enrolledContacts'] = $enrolledContacts;

        foreach (['enrolled', 'pending', 'suspended', 'unenrolled'] as $s) {
            $data[$s . 'Count'] = $statusCounts[$s] ?? 0;
        }
        $data['totalEnrolments'] = array_sum([
            $data['enrolledCount'],
            $data['pendingCount'],
            $data['suspendedCount'],
            $data['unenrolledCount'],
        ]);

        $data['totalInvoiced'] = round($totalInvoiced, 2);
        $data['courseBreakdown'] = array_values($courses);

        return $data;
    }
}
