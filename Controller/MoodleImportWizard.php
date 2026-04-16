<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2025 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 *
 * Multi-step wizard for importing Moodle users into FacturaScripts with preview
 * and field mapping. The wizard persists state between steps using the native PHP
 * session under a plugin-specific namespace. Each step is a plain HTTP POST that
 * returns to the same controller URL.
 *
 * Steps:
 *   1) Configure   — select Moodle instance and import mode (and target client).
 *   2) Preview     — fetch users from Moodle, show a table with checkboxes.
 *   3) Mapping     — choose which Moodle fields map to which Contacto fields.
 *   4) Execute     — perform the import and show a summary of results.
 */

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Cliente;
use FacturaScripts\Core\Model\Contacto;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\MoodleClient;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleUserMap;

/**
 * @since 2.0 — Fase 2 F2.6 hardens the `mm_wizard_type` cookie with
 *              HttpOnly/Secure/SameSite flags.
 */
class MoodleImportWizard extends Controller
{
    const SESSION_KEY = 'moodle_import_wizard';
    const PREFS_COOKIE = 'moodle_wizard_prefs';
    const PREFS_COOKIE_TTL = 7776000; // 90 days

    /** @var int Current step number (1..4). */
    public $step = 1;

    /** @var array Wizard state: instance, mode, client, users, selected, mapping, result. */
    public $state = [];

    /** @var array Instances available for step 1. */
    public $instances = [];

    /** @var array Default field mapping (Moodle field => Contacto field). */
    public $defaultMapping = [];

    /** @var array Last-used preferences (instance, mode, mapping) from cookie. */
    public $prefs = [];

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'moodle';
        $data['title'] = 'import-wizard';
        $data['icon'] = 'fa-solid fa-wand-magic-sparkles';
        $data['showonmenu'] = false;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->defaultMapping = [
            'email' => 'email',
            'firstname' => 'nombre',
            'lastname' => 'apellidos',
            'phone1' => 'telefono1',
            'phone2' => 'telefono2',
            'idnumber' => 'cifnif',
            'city' => 'ciudad',
            'country' => 'codpais',
            'address' => 'direccion',
        ];

        $this->loadInstances();
        $this->loadState();
        $this->loadPrefs();

        // GET action (querystring) takes priority for the CSV download
        if ($this->request->get('action') === 'export-csv') {
            $this->exportCsvAction();
            return;
        }

        $action = $this->request->request->get('action', '');

        switch ($action) {
            case 'reset':
                $this->resetState();
                $this->step = 1;
                return;

            case 'go-to-step-2':
                $this->processStep1();
                return;

            case 'go-to-step-3':
                $this->processStep2();
                return;

            case 'go-to-step-4':
                $this->processStep3();
                return;

            case 'back-to-step-1':
                $this->step = 1;
                return;

            case 'back-to-step-2':
                $this->step = 2;
                return;

            case 'back-to-step-3':
                $this->step = 3;
                return;
        }

        // No action: enter step 1 by default (or show last completed)
        $this->step = 1;
    }

    // ─────────────────────────────────────────────────────────────────────
    // User preferences (remembered across wizard runs via cookie)
    // ─────────────────────────────────────────────────────────────────────

    private function loadPrefs(): void
    {
        $this->prefs = [
            'idinstance' => 0,
            'import_mode' => '',
            'codcliente' => '',
            'auth_types' => [],
            'mapping' => [],
        ];

        $raw = (string)$this->request->cookies->get(self::PREFS_COOKIE, '');
        if ($raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }

        $this->prefs['idinstance'] = (int)($decoded['idinstance'] ?? 0);
        $this->prefs['import_mode'] = (string)($decoded['import_mode'] ?? '');
        $this->prefs['codcliente'] = (string)($decoded['codcliente'] ?? '');
        $this->prefs['auth_types'] = is_array($decoded['auth_types'] ?? null)
            ? array_values(array_filter(array_map('strval', $decoded['auth_types'])))
            : [];
        $this->prefs['mapping'] = is_array($decoded['mapping'] ?? null)
            ? array_filter($decoded['mapping'])
            : [];
    }

    private function savePrefs(): void
    {
        if (headers_sent()) {
            return;
        }
        $payload = [
            'idinstance' => (int)($this->state['idinstance'] ?? 0),
            'import_mode' => (string)($this->state['import_mode'] ?? ''),
            'codcliente' => (string)($this->state['codcliente'] ?? ''),
            'auth_types' => (array)($this->state['auth_types'] ?? []),
            'mapping' => (array)($this->state['mapping'] ?? []),
        ];
        // F2.6 — harden cookie:
        //   HttpOnly : JS cannot read the state (blocks XSS-based read)
        //   Secure   : only over HTTPS (auto-detected from request)
        //   SameSite : Lax so the cookie still ships on top-level
        //              navigations the wizard depends on, but is
        //              blocked on cross-site POST.
        @setcookie(
            self::PREFS_COOKIE,
            json_encode($payload),
            [
                'expires'  => time() + self::PREFS_COOKIE_TTL,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $this->isHttpsRequest(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Detects whether the current request is served over TLS. Honours
     * X-Forwarded-Proto when FacturaScripts is behind a reverse proxy
     * that FS itself trusts (same heuristic as Request::isSecure()).
     *
     * @since 2.0
     */
    private function isHttpsRequest(): bool
    {
        if (method_exists($this->request, 'isSecure') && $this->request->isSecure()) {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) {
            return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Session helpers
    // ─────────────────────────────────────────────────────────────────────

    private function loadState(): void
    {
        if (false === $this->startSession()) {
            return;
        }
        $this->state = $_SESSION[self::SESSION_KEY] ?? [];
    }

    private function saveState(): void
    {
        if (false === $this->startSession()) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = $this->state;
    }

    private function resetState(): void
    {
        $this->state = [];
        if ($this->startSession()) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    private function startSession(): bool
    {
        if (PHP_SESSION_ACTIVE === session_status()) {
            return true;
        }
        if (headers_sent()) {
            return false;
        }
        return @session_start();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 1 → 2: validate config and fetch users
    // ─────────────────────────────────────────────────────────────────────

    private function processStep1(): void
    {
        $idinstance = (int)$this->request->request->get('idinstance', 0);
        $importMode = (string)$this->request->request->get('import_mode', '');
        $codcliente = (string)$this->request->request->get('codcliente', '');
        $postAll = $this->request->request->all();
        $authFilter = isset($postAll['auth_types']) ? (array)$postAll['auth_types'] : [];

        if (empty($importMode)) {
            Tools::log()->warning('import-mode-required');
            $this->step = 1;
            return;
        }

        if ($importMode === 'assign_to_existing_client') {
            if (empty($codcliente)) {
                Tools::log()->warning('client-required-for-assign');
                $this->step = 1;
                return;
            }
            $cliente = new Cliente();
            if (false === $cliente->loadFromCode($codcliente)) {
                Tools::log()->warning('client-not-found');
                $this->step = 1;
                return;
            }
        }

        $instance = new MoodleInstance();
        if ($idinstance > 0) {
            if (false === $instance->loadFromCode($idinstance)) {
                Tools::log()->warning('moodle-instance-not-found');
                $this->step = 1;
                return;
            }
        } else {
            // Default to first active instance
            $list = $instance->all([new DataBaseWhere('status', 'active')], [], 0, 1);
            if (empty($list)) {
                Tools::log()->warning('no-active-moodle-instance');
                $this->step = 1;
                return;
            }
            $instance = $list[0];
        }

        // Default auth filter when none chosen
        if (empty($authFilter)) {
            $authFilter = ['manual', 'email', 'ldap', 'oauth2'];
        }

        // Fetch users
        $users = $this->fetchMoodleUsers($instance, $authFilter);
        if (empty($users)) {
            Tools::log()->warning('wizard-no-users-found');
            $this->step = 1;
            return;
        }

        // Annotate each user: existing map / existing contact
        $users = $this->annotateUsers($users, (int)$instance->id);

        $this->state = [
            'idinstance' => (int)$instance->id,
            'instance_name' => $instance->name,
            'import_mode' => $importMode,
            'codcliente' => $codcliente,
            'auth_types' => $authFilter,
            'users' => $users,
        ];
        $this->saveState();
        $this->savePrefs();
        $this->step = 2;
    }

    private function fetchMoodleUsers(MoodleInstance $instance, array $authTypes): array
    {
        $users = [];
        foreach ($authTypes as $authType) {
            $result = MoodleClient::getUsers($instance, [['key' => 'auth', 'value' => $authType]]);
            if (isset($result['exception'])) {
                continue;
            }
            foreach ($result['users'] ?? [] as $user) {
                // Skip deleted and users without valid email
                if (!empty($user['deleted']) || empty($user['email'])) {
                    continue;
                }
                if (false === filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (str_ends_with($user['email'], '@localhost')) {
                    continue;
                }
                $users[$user['id']] = [
                    'id' => (int)$user['id'],
                    'username' => $user['username'] ?? '',
                    'email' => $user['email'],
                    'firstname' => $user['firstname'] ?? '',
                    'lastname' => $user['lastname'] ?? '',
                    'phone1' => $user['phone1'] ?? '',
                    'phone2' => $user['phone2'] ?? '',
                    'idnumber' => $user['idnumber'] ?? '',
                    'city' => $user['city'] ?? '',
                    'country' => $user['country'] ?? '',
                    'address' => $user['address'] ?? '',
                    'auth' => $user['auth'] ?? '',
                ];
            }
        }
        return array_values($users);
    }

    private function annotateUsers(array $users, int $idinstance): array
    {
        foreach ($users as &$u) {
            $u['exists_mapping'] = false;
            $u['exists_contact'] = false;

            // Existing map?
            $mapModel = new MoodleUserMap();
            $mapWhere = [
                new DataBaseWhere('idinstance', $idinstance),
                new DataBaseWhere('moodle_userid', $u['id']),
            ];
            if ($mapModel->loadFromCode('', $mapWhere)) {
                $u['exists_mapping'] = true;
            }

            // Contact with same email?
            $contact = new Contacto();
            if ($contact->loadFromCode('', [new DataBaseWhere('email', $u['email'])])) {
                $u['exists_contact'] = true;
                $u['existing_contact_name'] = trim(($contact->nombre ?? '') . ' ' . ($contact->apellidos ?? ''));
            }
        }
        unset($u);
        return $users;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 2 → 3: store selection, show mapping
    // ─────────────────────────────────────────────────────────────────────

    private function processStep2(): void
    {
        $postAll = $this->request->request->all();
        $selectedIds = $postAll['selected_users'] ?? [];
        $selectedIds = array_map('intval', (array)$selectedIds);
        $selectedIds = array_values(array_unique(array_filter($selectedIds)));

        if (empty($selectedIds)) {
            Tools::log()->warning('wizard-no-users-selected');
            $this->step = 2;
            return;
        }

        $this->state['selected'] = $selectedIds;
        $this->saveState();
        $this->step = 3;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Step 3 → 4: store mapping and run import
    // ─────────────────────────────────────────────────────────────────────

    private function processStep3(): void
    {
        $postAll = $this->request->request->all();
        $mapping = $postAll['mapping'] ?? [];
        if (!is_array($mapping) || empty($mapping)) {
            $mapping = $this->defaultMapping;
        }

        // Keep only non-empty, sanitised pairs
        $clean = [];
        foreach ($mapping as $moodleField => $contactField) {
            if (empty($contactField) || $contactField === '_ignore_') {
                continue;
            }
            $clean[(string)$moodleField] = (string)$contactField;
        }

        $this->state['mapping'] = $clean;

        $result = $this->executeImport();
        $this->state['result'] = $result;
        $this->saveState();
        $this->savePrefs();
        $this->step = 4;

        Tools::log()->notice('import-completed', [
            '%imported%' => $result['imported'],
            '%skipped%' => $result['skipped'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CSV export of step-4 results
    // ─────────────────────────────────────────────────────────────────────

    private function exportCsvAction(): void
    {
        $this->setTemplate(false);

        $result = $this->state['result'] ?? null;
        if (empty($result) || empty($result['details'])) {
            $this->response->setStatusCode(404);
            $this->response->setContent(Tools::lang()->trans('wizard-no-results'));
            return;
        }

        $filename = 'moodle-import-' . date('Y-m-d-His') . '.csv';
        $this->response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $this->response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $buffer = fopen('php://temp', 'w+');
        // UTF-8 BOM so Excel opens the file correctly
        fwrite($buffer, "\xEF\xBB\xBF");

        fputcsv($buffer, [
            Tools::lang()->trans('status'),
            'moodle-username',
            Tools::lang()->trans('email'),
            Tools::lang()->trans('message'),
        ], ';');

        foreach ($result['details'] as $row) {
            fputcsv($buffer, [
                Tools::lang()->trans((string)($row['status'] ?? '')),
                (string)($row['username'] ?? ''),
                (string)($row['email'] ?? ''),
                Tools::lang()->trans((string)($row['message'] ?? '')),
            ], ';');
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        $this->response->setContent($csv);
    }

    /**
     * Runs the import against the selected users using the configured mapping.
     * Returns a summary with counts and per-user outcomes.
     */
    private function executeImport(): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        $instance = new MoodleInstance();
        if (false === $instance->loadFromCode($this->state['idinstance'])) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => 1,
                'details' => [['status' => 'error', 'message' => 'moodle-instance-not-found']],
            ];
        }

        $existingClient = null;
        if ($this->state['import_mode'] === 'assign_to_existing_client') {
            $existingClient = new Cliente();
            if (false === $existingClient->loadFromCode($this->state['codcliente'])) {
                return [
                    'imported' => 0,
                    'skipped' => 0,
                    'errors' => 1,
                    'details' => [['status' => 'error', 'message' => 'client-not-found']],
                ];
            }
        }

        $selected = array_flip($this->state['selected']);
        $mapping = $this->state['mapping'] ?? $this->defaultMapping;

        foreach ($this->state['users'] as $moodleUser) {
            if (!isset($selected[$moodleUser['id']])) {
                continue;
            }

            $email = $moodleUser['email'];

            // Skip if mapping already exists
            $mapModel = new MoodleUserMap();
            $mapWhere = [
                new DataBaseWhere('idinstance', $instance->id),
                new DataBaseWhere('moodle_userid', $moodleUser['id']),
            ];
            if ($mapModel->loadFromCode('', $mapWhere)) {
                $skipped++;
                $details[] = [
                    'status' => 'skipped',
                    'username' => $moodleUser['username'],
                    'email' => $email,
                    'message' => 'already-mapped',
                ];
                continue;
            }

            // Existing contact?
            $contact = new Contacto();
            $contactExists = $contact->loadFromCode('', [new DataBaseWhere('email', $email)]);

            if (false === $contactExists) {
                $newContact = $this->createContactFromMapping($moodleUser, $mapping);

                if ($this->state['import_mode'] === 'create_client_per_user') {
                    $cliente = new Cliente();
                    $fullName = trim(($newContact->nombre ?? '') . ' ' . ($newContact->apellidos ?? ''));
                    $cliente->nombre = $fullName ?: ($moodleUser['username'] ?: 'Moodle User');
                    $cliente->razonsocial = $cliente->nombre;
                    $cliente->cifnif = $newContact->cifnif ?? '';
                    $cliente->email = $email;
                    if (!empty($newContact->telefono1)) {
                        $cliente->telefono1 = $newContact->telefono1;
                    }
                    if (false === $cliente->save()) {
                        $errors++;
                        $details[] = [
                            'status' => 'error',
                            'username' => $moodleUser['username'],
                            'email' => $email,
                            'message' => 'client-create-failed',
                        ];
                        continue;
                    }

                    // Client auto-creates a contact; enrich it with our mapping data
                    $contact = $cliente->getDefaultAddress();
                    $this->applyMappingToContact($moodleUser, $mapping, $contact);
                    $contact->codcliente = $cliente->codcliente;
                    $contact->save();
                } else {
                    // assign_to_existing_client
                    $newContact->codcliente = $existingClient->codcliente;
                    if (false === $newContact->save()) {
                        $errors++;
                        $details[] = [
                            'status' => 'error',
                            'username' => $moodleUser['username'],
                            'email' => $email,
                            'message' => 'contact-create-failed',
                        ];
                        continue;
                    }
                    $contact = $newContact;
                }
            } else {
                // Contact exists: link it to target client if unlinked
                if (empty($contact->codcliente)) {
                    if ($this->state['import_mode'] === 'create_client_per_user') {
                        $cliente = new Cliente();
                        $fullName = trim(($contact->nombre ?? '') . ' ' . ($contact->apellidos ?? ''));
                        $cliente->nombre = $fullName ?: 'Moodle User';
                        $cliente->razonsocial = $cliente->nombre;
                        $cliente->cifnif = $contact->cifnif ?? '';
                        $cliente->email = $contact->email;
                        if ($cliente->save()) {
                            $contact->codcliente = $cliente->codcliente;
                            $contact->save();
                        }
                    } elseif ($this->state['import_mode'] === 'assign_to_existing_client') {
                        $contact->codcliente = $existingClient->codcliente;
                        $contact->save();
                    }
                }
            }

            // Create mapping
            $map = new MoodleUserMap();
            $map->idcontacto = $contact->idcontacto;
            $map->idinstance = (int)$instance->id;
            $map->moodle_userid = (int)$moodleUser['id'];
            $map->moodle_username = $moodleUser['username'] ?? '';
            $map->sync_direction = 'moodle_to_fs';
            $map->last_sync = date('Y-m-d H:i:s');
            if ($map->save()) {
                $imported++;
                $details[] = [
                    'status' => 'imported',
                    'username' => $moodleUser['username'],
                    'email' => $email,
                    'message' => 'imported-ok',
                ];
            } else {
                $errors++;
                $details[] = [
                    'status' => 'error',
                    'username' => $moodleUser['username'],
                    'email' => $email,
                    'message' => 'map-create-failed',
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    private function createContactFromMapping(array $moodleUser, array $mapping): Contacto
    {
        $contact = new Contacto();
        $this->applyMappingToContact($moodleUser, $mapping, $contact);
        return $contact;
    }

    private function applyMappingToContact(array $moodleUser, array $mapping, Contacto $contact): void
    {
        foreach ($mapping as $moodleField => $contactField) {
            if (!isset($moodleUser[$moodleField])) {
                continue;
            }
            $value = trim((string)$moodleUser[$moodleField]);
            if ($value === '') {
                continue;
            }
            // Normalise country from Moodle (ISO 3166-1 alpha-2) to codpais (alpha-3)
            if ($contactField === 'codpais' && strlen($value) === 2) {
                $value = strtoupper($value);
                $map = [
                    'AR' => 'ARG', 'BO' => 'BOL', 'BR' => 'BRA', 'CA' => 'CAN', 'CL' => 'CHL',
                    'CO' => 'COL', 'CR' => 'CRI', 'CU' => 'CUB', 'DO' => 'DOM', 'EC' => 'ECU',
                    'ES' => 'ESP', 'FR' => 'FRA', 'GB' => 'GBR', 'GT' => 'GTM', 'HN' => 'HND',
                    'IT' => 'ITA', 'MX' => 'MEX', 'NI' => 'NIC', 'PA' => 'PAN', 'PE' => 'PER',
                    'PR' => 'PRI', 'PT' => 'PRT', 'PY' => 'PRY', 'SV' => 'SLV', 'US' => 'USA',
                    'UY' => 'URY', 'VE' => 'VEN', 'DE' => 'DEU',
                ];
                $value = $map[$value] ?? $value;
            }
            $contact->{$contactField} = $value;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // View helpers
    // ─────────────────────────────────────────────────────────────────────

    private function loadInstances(): void
    {
        $this->instances = [];
        $model = new MoodleInstance();
        foreach ($model->all([new DataBaseWhere('status', 'active')], ['name' => 'ASC'], 0, 0) as $inst) {
            $this->instances[] = ['id' => $inst->id, 'name' => $inst->name];
        }
    }

    /**
     * Returns the list of Contacto fields available as mapping targets.
     */
    public function getContactFields(): array
    {
        return [
            '_ignore_' => Tools::lang()->trans('wizard-ignore-field'),
            'nombre' => 'nombre',
            'apellidos' => 'apellidos',
            'email' => 'email',
            'telefono1' => 'telefono1',
            'telefono2' => 'telefono2',
            'cifnif' => 'cifnif',
            'empresa' => 'empresa',
            'cargo' => 'cargo',
            'direccion' => 'direccion',
            'ciudad' => 'ciudad',
            'provincia' => 'provincia',
            'codpostal' => 'codpostal',
            'codpais' => 'codpais',
            'descripcion' => 'descripcion',
            'observaciones' => 'observaciones',
        ];
    }

    /**
     * Returns the list of Moodle user fields available as mapping sources.
     */
    public function getMoodleFields(): array
    {
        return [
            'email' => 'email',
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'phone1' => 'phone1',
            'phone2' => 'phone2',
            'idnumber' => 'idnumber',
            'city' => 'city',
            'country' => 'country',
            'address' => 'address',
            'department' => 'department',
            'institution' => 'institution',
            'description' => 'description',
        ];
    }
}
