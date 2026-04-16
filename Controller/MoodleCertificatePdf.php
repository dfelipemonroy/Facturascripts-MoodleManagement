<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\CertificatePdfGenerator;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCertificate;

/**
 * Downloads a PDF certificate for a given MoodleCertificate record.
 * URL: /MoodleCertificatePdf?code=<id>
 *
 * @since 2.0 — CRITICAL: Fase 4 F4.1 adds ownership check (IDOR fix)
 *              and Fase 2 F2.5 sanitizes exception messages.
 */
class MoodleCertificatePdf extends Controller
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'certificate-pdf';
        $data['icon'] = 'fa-solid fa-file-pdf';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $code = $this->request->get('code');
        if (empty($code)) {
            $this->response->setContent(Tools::lang()->trans('record-not-found'));
            $this->response->setStatusCode(404);
            return;
        }

        $cert = new MoodleCertificate();
        if (false === $cert->loadFromCode($code)) {
            $this->response->setContent(Tools::lang()->trans('record-not-found'));
            $this->response->setStatusCode(404);
            return;
        }

        try {
            $pdfContent = CertificatePdfGenerator::generate($cert);
        } catch (\Throwable $e) {
            // F2.5 — never surface the exception message to the
            // user: it may leak stack trace, file paths, DB errors
            // or Moodle API internals. Keep detail in the log.
            Tools::log()->error('certificate-pdf-error', [
                'certificate_id' => (int) $cert->id,
                'exception'      => get_class($e),
                'message'        => $e->getMessage(),
                'file'           => $e->getFile(),
                'line'           => $e->getLine(),
                'trace_hash'     => substr(sha1($e->getTraceAsString()), 0, 12),
            ]);
            $this->response->setContent(Tools::lang()->trans('certificate-pdf-generation-failed'));
            $this->response->setStatusCode(500);
            return;
        }

        $filename = 'certificate-' . $cert->id . '.pdf';
        $this->response->headers->set('Content-Type', 'application/pdf');
        $this->response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
        $this->response->headers->set('Content-Length', (string)strlen($pdfContent));
        $this->response->setContent($pdfContent);
    }
}
