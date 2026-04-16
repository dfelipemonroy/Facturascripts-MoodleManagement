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

use FacturaScripts\Core\Lib\Email\NewMail;
use FacturaScripts\Core\Lib\Email\TextBlock;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleEnrolment;

/**
 * Sends the expiry-notification email for a single enrolment.
 * Extracted from Cron so it can be reused by the manual "resend" button
 * on EditMoodleEnrolment without instantiating a CronClass.
 */
class ExpiryNotifier
{
    /**
     * Sends the expiry email. Returns true if delivered, false if it was
     * skipped (already notified, no contact email, send failed…).
     *
     * @param MoodleEnrolment $enrolment
     * @param int $daysLeft whole days remaining before timeend (used in the mail body)
     * @param int $threshold which configured threshold this email corresponds to
     * @param bool $force ignore the "already notified for this threshold" check
     * @param string|null $logChannel optional Tools::log() channel for outcome logs
     */
    public static function send(
        MoodleEnrolment $enrolment,
        int $daysLeft,
        int $threshold,
        bool $force = false,
        ?string $logChannel = null
    ): bool {
        $log = $logChannel ? Tools::log($logChannel) : Tools::log();

        if (!$force) {
            $notified = $enrolment->getNotifiedThresholds();
            if (in_array($threshold, $notified, true)) {
                return false;
            }
        }

        $contacto = $enrolment->getContacto();
        if (empty($contacto->email)) {
            return false;
        }

        $courseMap = $enrolment->getCourseMap();
        $courseName = $courseMap ? $courseMap->fullname : 'ID ' . $enrolment->moodle_courseid;

        try {
            $mail = new NewMail();
            $mail->title = Tools::lang()->trans('expiry-email-subject', ['%course%' => $courseName]);
            $mail->to($contacto->email, trim($contacto->nombre . ' ' . $contacto->apellidos));

            $greeting = Tools::lang()->trans('expiry-email-greeting', [
                '%name%' => $contacto->nombre,
            ]);
            $body = Tools::lang()->trans('expiry-email-body', [
                '%course%' => $courseName,
                '%days%' => $daysLeft,
                '%date%' => date('Y-m-d', $enrolment->timeend),
            ]);
            $renewalNote = '';
            if (!empty($enrolment->idpresupuesto)) {
                $renewalNote = Tools::lang()->trans('expiry-email-renewal-note');
            }
            $closing = Tools::lang()->trans('expiry-email-closing');

            $mail->addMainBlock(new TextBlock($greeting, 'h4'));
            $mail->addMainBlock(new TextBlock($body));
            if (!empty($renewalNote)) {
                $mail->addMainBlock(new TextBlock($renewalNote));
            }
            $mail->addMainBlock(new TextBlock($closing));

            if ($mail->send()) {
                $enrolment->markThresholdNotified($threshold);
                $enrolment->save();
                $log->notice('expiry-email-sent', [
                    '%email%' => $contacto->email,
                    '%course%' => $courseName,
                    '%days%' => $threshold,
                ]);
                return true;
            }

            $log->warning('expiry-email-failed', [
                '%email%' => $contacto->email,
            ]);
            return false;
        } catch (\Throwable $e) {
            $log->warning('expiry-email-failed', [
                '%email%' => $contacto->email,
                '%error%' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
