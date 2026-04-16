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
            Tools::log()->error('certificate-pdf-error', ['%error%' => $e->getMessage()]);
            $this->response->setContent('Error: ' . $e->getMessage());
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
