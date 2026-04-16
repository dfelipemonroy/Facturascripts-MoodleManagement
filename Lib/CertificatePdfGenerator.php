<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Lib;

use Cezpdf;
use FacturaScripts\Core\DataSrc\Empresas;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Contacto;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCertificate;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCertificateTemplate;

/**
 * Generates a PDF certificate for a completed Moodle course/badge.
 * Produces a landscape A4 with an ornamental border, company logo, and signature line.
 *
 * Styling is driven by MoodleCertificateTemplate (optional). Resolution order:
 *   1. Explicit template passed to generate()
 *   2. Best match via MoodleCertificateTemplate::findBest($cert->idinstance)
 *   3. Hardcoded built-in defaults
 */
class CertificatePdfGenerator
{
    // Built-in fallback colors (RGB 0-1)
    private const DEFAULT_PRIMARY = [0.0, 0.33, 0.63];   // dark blue
    private const DEFAULT_ACCENT = [0.85, 0.70, 0.25];   // gold

    /**
     * Generates the certificate PDF and returns the binary contents.
     */
    public static function generate(MoodleCertificate $cert, ?MoodleCertificateTemplate $template = null): string
    {
        if ($template === null) {
            $template = MoodleCertificateTemplate::findBest($cert->idinstance);
        }

        $pdf = new Cezpdf('a4', 'landscape');
        $pdf->addInfo('Title', 'Certificate - ' . $cert->badge_name);
        $pdf->addInfo('Creator', 'FacturaScripts MoodleManagement');

        $pdf->selectFont('Helvetica');
        $pageWidth = 842; // A4 landscape width in points
        $pageHeight = 595;

        $primary = self::resolveColor($template ? $template->primary_color : null, self::DEFAULT_PRIMARY);
        $accent = self::resolveColor($template ? $template->accent_color : null, self::DEFAULT_ACCENT);

        $titleSize = $template && $template->title_font_size ? (int)$template->title_font_size : 36;
        $nameSize = $template && $template->name_font_size ? (int)$template->name_font_size : 32;
        $courseSize = $template && $template->course_font_size ? (int)$template->course_font_size : 22;

        // ── Outer decorative border (primary) ──
        $pdf->setStrokeColor($primary[0], $primary[1], $primary[2]);
        $pdf->setLineStyle(3);
        $pdf->rectangle(25, 25, $pageWidth - 50, $pageHeight - 50);

        // ── Inner decorative border (accent) ──
        $pdf->setStrokeColor($accent[0], $accent[1], $accent[2]);
        $pdf->setLineStyle(1);
        $pdf->rectangle(40, 40, $pageWidth - 80, $pageHeight - 80);

        // ── Optional logo (top-left of inner frame) ──
        if ($template && !empty($template->logo_path)) {
            $logoFull = self::resolveLogoPath($template->logo_path);
            if ($logoFull !== null && is_file($logoFull)) {
                try {
                    $pdf->addJpegFromFile($logoFull, 70, $pageHeight - 140, 90, 60);
                } catch (\Throwable $e) {
                    // ignore image errors — certificate still renders
                }
            }
        }

        // ── Title ──
        $pdf->setColor($primary[0], $primary[1], $primary[2]);
        $pdf->selectFont('Helvetica-Bold');
        $titleText = strtoupper(!empty($template->title_text ?? null)
            ? $template->title_text
            : Tools::lang()->trans('certificate-title'));
        $titleWidth = $pdf->getTextWidth($titleSize, $titleText);
        $pdf->addText(($pageWidth - $titleWidth) / 2, $pageHeight - 110, $titleSize, $titleText);

        // ── Subtitle ──
        $pdf->setColor(0.3, 0.3, 0.3);
        $pdf->selectFont('Helvetica-Oblique');
        $subtitle = !empty($template->subtitle_text ?? null)
            ? $template->subtitle_text
            : Tools::lang()->trans('certificate-subtitle');
        $subtitleWidth = $pdf->getTextWidth(14, $subtitle);
        $pdf->addText(($pageWidth - $subtitleWidth) / 2, $pageHeight - 145, 14, $subtitle);

        // ── Student name ──
        $studentName = self::getStudentName($cert);
        $pdf->setColor(0.0, 0.0, 0.0);
        $pdf->selectFont('Helvetica-Bold');
        $nameWidth = $pdf->getTextWidth($nameSize, $studentName);
        $pdf->addText(($pageWidth - $nameWidth) / 2, $pageHeight - 210, $nameSize, $studentName);

        // ── Divider line under name (accent) ──
        $pdf->setStrokeColor($accent[0], $accent[1], $accent[2]);
        $pdf->setLineStyle(1);
        $pdf->line(($pageWidth / 2) - 180, $pageHeight - 220, ($pageWidth / 2) + 180, $pageHeight - 220);

        // ── "has completed" text ──
        $pdf->setColor(0.3, 0.3, 0.3);
        $pdf->selectFont('Helvetica');
        $completedText = !empty($template->completed_text ?? null)
            ? $template->completed_text
            : Tools::lang()->trans('certificate-completed-text');
        $completedWidth = $pdf->getTextWidth(14, $completedText);
        $pdf->addText(($pageWidth - $completedWidth) / 2, $pageHeight - 255, 14, $completedText);

        // ── Course/Badge name ──
        $courseName = !empty($cert->course_name) ? $cert->course_name : $cert->badge_name;
        $pdf->setColor($primary[0], $primary[1], $primary[2]);
        $pdf->selectFont('Helvetica-Bold');
        $courseWidth = $pdf->getTextWidth($courseSize, $courseName);
        $pdf->addText(($pageWidth - $courseWidth) / 2, $pageHeight - 295, $courseSize, $courseName);

        // ── Badge name (if different from course) ──
        if (!empty($cert->course_name) && $cert->course_name !== $cert->badge_name) {
            $pdf->setColor(0.5, 0.5, 0.5);
            $pdf->selectFont('Helvetica-Oblique');
            $badgeText = '— ' . $cert->badge_name . ' —';
            $badgeWidth = $pdf->getTextWidth(12, $badgeText);
            $pdf->addText(($pageWidth - $badgeWidth) / 2, $pageHeight - 320, 12, $badgeText);
        }

        // ── Date issued ──
        if ($template === null || $template->show_date_issued) {
            $pdf->setColor(0.0, 0.0, 0.0);
            $pdf->selectFont('Helvetica');
            $dateLabel = Tools::lang()->trans('date-issued');
            $dateValue = !empty($cert->date_issued) ? $cert->date_issued : date('Y-m-d');
            $dateText = $dateLabel . ': ' . $dateValue;
            $pdf->addText(120, 130, 11, $dateText);
        }

        // ── Unique hash (verification) ──
        $showHash = $template === null || $template->show_unique_hash;
        if ($showHash && !empty($cert->unique_hash)) {
            $pdf->setColor(0.5, 0.5, 0.5);
            $hashText = Tools::lang()->trans('unique-hash') . ': ' . $cert->unique_hash;
            $pdf->addText(120, 115, 9, $hashText);
        }

        // ── Issuer (company) ──
        if ($template === null || $template->show_issuer) {
            $empresa = Empresas::default();
            $issuerName = $empresa ? $empresa->nombre : '';
            if (!empty($issuerName)) {
                $pdf->setColor(0.0, 0.0, 0.0);
                $pdf->selectFont('Helvetica-Bold');
                $issuerWidth = $pdf->getTextWidth(12, $issuerName);
                $pdf->addText($pageWidth - 120 - $issuerWidth, 145, 12, $issuerName);

                // signature line
                $pdf->setStrokeColor(0.0, 0.0, 0.0);
                $pdf->setLineStyle(1);
                $pdf->line($pageWidth - 280, 135, $pageWidth - 120, 135);

                $pdf->setColor(0.5, 0.5, 0.5);
                $pdf->selectFont('Helvetica');
                $issuerLabel = !empty($template->issuer_label ?? null)
                    ? $template->issuer_label
                    : Tools::lang()->trans('certificate-issuer');
                $labelWidth = $pdf->getTextWidth(9, $issuerLabel);
                $pdf->addText($pageWidth - 200 - ($labelWidth / 2), 120, 9, $issuerLabel);
            }
        }

        // ── Footer verification text ──
        if ($showHash && !empty($cert->unique_hash)) {
            $pdf->setColor(0.6, 0.6, 0.6);
            $pdf->selectFont('Helvetica');
            $verifyText = !empty($template->verify_text ?? null)
                ? $template->verify_text
                : Tools::lang()->trans('certificate-verify-text');
            $verifyWidth = $pdf->getTextWidth(8, $verifyText);
            $pdf->addText(($pageWidth - $verifyWidth) / 2, 70, 8, $verifyText);
        }

        return $pdf->ezOutput();
    }

    /**
     * Converts a hex color "#RRGGBB" to a [R, G, B] array with components in 0-1.
     * Returns $fallback when the input is empty or malformed.
     */
    private static function resolveColor(?string $hex, array $fallback): array
    {
        if (empty($hex) || !preg_match('/^#?([0-9A-Fa-f]{6})$/', $hex, $m)) {
            return $fallback;
        }
        $h = $m[1];
        return [
            hexdec(substr($h, 0, 2)) / 255,
            hexdec(substr($h, 2, 2)) / 255,
            hexdec(substr($h, 4, 2)) / 255,
        ];
    }

    /**
     * Resolves a relative logo path against the FS root; returns null if path is empty.
     */
    private static function resolveLogoPath(string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // absolute path already
        if (is_file($path)) {
            return $path;
        }

        // relative to FS root (FS_FOLDER constant)
        if (defined('FS_FOLDER')) {
            $candidate = FS_FOLDER . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Returns the student's full name from the linked contact.
     */
    private static function getStudentName(MoodleCertificate $cert): string
    {
        if (empty($cert->idcontacto)) {
            return Tools::lang()->trans('student');
        }

        $contacto = new Contacto();
        if (false === $contacto->loadFromCode($cert->idcontacto)) {
            return Tools::lang()->trans('student');
        }

        $name = trim(($contacto->nombre ?? '') . ' ' . ($contacto->apellidos ?? ''));
        return empty($name) ? Tools::lang()->trans('student') : $name;
    }
}
