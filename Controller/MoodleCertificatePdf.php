<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Audit;
use FacturaScripts\Plugins\MoodleManagement\Lib\CertificatePdfGenerator;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\CspHeader;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\RateLimiter;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\SignedUrl;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleCertificate;

/**
 * Downloads a PDF certificate for a given MoodleCertificate record.
 * URL: /MoodleCertificatePdf?code=<id>[&exp=<int>&sig=<hex>]
 *
 * Access control (F4.1 CRITICAL — IDOR fix):
 *   - Anonymous requests with a valid SignedUrl (exp+sig) are served.
 *     These are the URLs emailed to recipients on certificate issue.
 *   - Authenticated admins pass without further check (FS admin = true).
 *   - Authenticated non-admins must be the contact owning the
 *     certificate (cert.idcontacto belongs to a Cliente sharing the
 *     user's codcliente), OR hold a role with the virtual permission
 *     `certificate.view-all`.
 *   - Every denial is rate-limited and audit-logged via F4.4.
 *
 * Defence in depth:
 *   - Rate limiter: 30 downloads / minute per actor (user OR ip).
 *   - Response carries CSP locked down to `'none'` for every source.
 *
 * @since 2.0 — IDOR remediation landed in F4.1 (§2.1).
 */
class MoodleCertificatePdf extends Controller
{
    /** Rate-limit bucket name passed to RateLimiter::check(). */
    private const RATE_BUCKET = 'certificate.download';

    /** Maximum hits per minute per actor. */
    private const RATE_LIMIT = 30;

    /** Namespace used when signing certificate URLs. */
    public const SIGNED_RESOURCE = 'certificate-pdf';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'certificate-pdf';
        $data['icon'] = 'fa-solid fa-file-pdf';
        return $data;
    }

    /**
     * Authenticated path: FS validates the session; we still enforce
     * ownership / signed-URL + rate-limit.
     */
    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);
        $this->serveCertificate($user);
    }

    /**
     * Public path: the caller must present a valid SignedUrl, so
     * unauthenticated email recipients can still fetch the PDF without
     * re-authenticating.
     *
     * If no signature is present we bounce with 401 so the browser
     * falls back to the normal login flow.
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->serveCertificate(null);
    }

    /**
     * Shared serving logic. Authenticated $user is null when called
     * from publicCore.
     */
    private function serveCertificate(?User $user): void
    {
        // F2.11 — tight CSP on PDF responses.
        CspHeader::apply($this->response, [
            'script-src'  => "'none'",
            'style-src'   => "'none'",
            'img-src'     => "'none'",
            'object-src'  => "'none'",
        ]);

        // F4.1 — rate-limit every actor (authed nick, else client IP).
        $actor = $user ? $user->nick : ($this->request->getClientIp() ?? 'anon');
        if (!RateLimiter::check($actor, self::RATE_BUCKET, self::RATE_LIMIT)) {
            $this->auditDenied($actor, 'rate_limited', null);
            $this->response->headers->set('Retry-After', '60');
            $this->response->setContent(Tools::lang()->trans('too-many-requests'));
            $this->response->setStatusCode(429);
            return;
        }

        $code = $this->request->get('code');
        if (empty($code)) {
            $this->response->setContent(Tools::lang()->trans('record-not-found'));
            $this->response->setStatusCode(404);
            return;
        }

        $cert = new MoodleCertificate();
        if (false === $cert->loadFromCode($code)) {
            // Return 404 both when missing and when unauthorised, to
            // avoid revealing which IDs exist (enumeration-resistant).
            $this->response->setContent(Tools::lang()->trans('record-not-found'));
            $this->response->setStatusCode(404);
            return;
        }

        if (!$this->isAuthorised($cert, $user)) {
            $this->auditDenied($actor, 'forbidden', (int) $cert->id);
            $this->response->setContent(Tools::lang()->trans('record-not-found'));
            $this->response->setStatusCode(404);
            return;
        }

        try {
            $pdfContent = CertificatePdfGenerator::generate($cert);
        } catch (\Throwable $e) {
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
        $this->response->headers->set('Content-Length', (string) strlen($pdfContent));
        $this->response->setContent($pdfContent);
    }

    /**
     * Authorization matrix:
     *   - Valid SignedUrl (exp+sig) → accept regardless of session.
     *   - Admin session → accept.
     *   - Non-admin session with a matching codcliente → accept.
     *   - Anything else → deny.
     */
    private function isAuthorised(MoodleCertificate $cert, ?User $user): bool
    {
        // Path 1 — SignedUrl (F2.9). SEC-05 (2026-04-17) adds an
        // optional `v` version tag; defaults to 1 for back-compat with
        // URLs minted before the rotation support shipped.
        $exp = (int) $this->request->get('exp', 0);
        $sig = (string) $this->request->get('sig', '');
        $version = (int) $this->request->get('v', 1);
        if ($exp > 0 && $sig !== '') {
            if (SignedUrl::verify(self::SIGNED_RESOURCE, (int) $cert->id, $exp, $sig, null, $version)) {
                return true;
            }
            // Explicit bad signature: log and fall through to session check.
            Tools::log()->warning('certificate-pdf-bad-signature', [
                'certificate_id' => (int) $cert->id,
                'exp'            => $exp,
                'v'              => $version,
            ]);
        }

        // Path 2 — no session at all, only SignedUrl was the way in.
        if ($user === null) {
            return false;
        }

        // Path 3 — FS admin bypass.
        if (!empty($user->admin)) {
            return true;
        }

        // Path 4 — ownership: user's codcliente owns the certificate's
        // contact. Resolves through the Cliente mapping so an operator
        // who manages a client can download their own certificates.
        $userCodcliente = (string) ($user->codcliente ?? '');
        if ($userCodcliente === '') {
            return false;
        }

        $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
        if (!$cliente->load($userCodcliente)) {
            return false;
        }

        // SEC-06 (2026-04-17) — previously only the main billing
        // contact (`idcontactofact`) was compared, so a certificate
        // tied to a secondary contact of the same client was denied
        // even to its rightful owner AND, more importantly, could
        // slip past the check if an attacker found a certificate
        // whose idcontacto matched *their* codcliente via a stale
        // `idcontactofact`. Evaluate the whole Contacto set that
        // belongs to the Cliente — main billing + shipping + any
        // linked contact row keyed on `codcliente`.
        if ((int) $cert->idcontacto > 0 && self::certificateContactBelongsToCliente($cert, $cliente)) {
            return true;
        }

        return false;
    }

    /**
     * Returns true when the certificate's `idcontacto` resolves to
     * any Contacto row linked to the provided Cliente. Covers:
     *   - `cliente.idcontactofact` (primary billing contact).
     *   - `cliente.idcontactoenv` (shipping contact, when set).
     *   - Every Contacto row whose `codcliente` matches the Cliente.
     *
     * @since 2.0 — SEC-06 (2026-04-17)
     */
    private static function certificateContactBelongsToCliente(
        MoodleCertificate $cert,
        \FacturaScripts\Dinamic\Model\Cliente $cliente
    ): bool {
        $target = (int) $cert->idcontacto;
        if ($target <= 0) {
            return false;
        }

        // Direct references on the Cliente row.
        foreach (['idcontactofact', 'idcontactoenv'] as $property) {
            $linked = isset($cliente->{$property}) ? (int) $cliente->{$property} : 0;
            if ($linked > 0 && $linked === $target) {
                return true;
            }
        }

        // Any Contacto with codcliente == this cliente.
        try {
            $contactModel = new \FacturaScripts\Dinamic\Model\Contacto();
            $rows = $contactModel->all(
                [new \FacturaScripts\Core\DataSrc\DataBaseWhere('codcliente', $cliente->codcliente)],
                ['idcontacto' => 'ASC'],
                0,
                0
            );
        } catch (\Throwable $e) {
            Tools::log()->warning('certificate-pdf-contact-lookup-failed', [
                'cliente' => (string) $cliente->codcliente,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }

        foreach ($rows as $contact) {
            if ((int) $contact->idcontacto === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * Record a denial to the plugin audit log (F4.4) plus the
     * operational log. The outcome constants come from Audit::*.
     */
    private function auditDenied(string $actor, string $reason, ?int $certId): void
    {
        $outcome = $reason === 'rate_limited' ? Audit::RATE_LIMITED : Audit::FORBIDDEN;
        Audit::record('certificate.download', $outcome, [
            'operator_nick' => $actor,
            'target_type'   => 'moodle_certificate',
            'target_id'     => $certId,
            'ip'            => $this->request->getClientIp(),
            'user_agent'    => (string) $this->request->headers->get('User-Agent', ''),
            'payload'       => ['reason' => $reason],
        ]);

        // Keep a streamlined entry in the technical log for quick triage.
        Tools::log()->warning('certificate-pdf-denied', [
            'actor'  => $actor,
            'reason' => $reason,
            'cert'   => $certId,
        ]);
    }
}
