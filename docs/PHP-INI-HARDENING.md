# php.ini Hardening Guide — MoodleManagement v2.0

`@since 2.0 — V2.0-ACTION-PLAN F10.7 · §6.20`

Recommended `php.ini` settings for a production FacturaScripts host
that runs the MoodleManagement plugin. Each value is the most
restrictive safe default; loosen only after reviewing the rationale.

## 1. Runtime limits

| Directive            | Value     | Why it matters                                                                                                              |
| -------------------- | --------- | --------------------------------------------------------------------------------------------------------------------------- |
| `max_execution_time` | `60`      | Long enough for PDF generation + large invoice sync; short enough to kill the rare Moodle WS hang.                          |
| `memory_limit`       | `256M`    | Covers user-sync batches of 500 contacts + the (larger-than-ideal) MoodleClient god class until Fase 8 lands its split.     |
| `post_max_size`      | `32M`     | Ceiling for CSV/ZIP uploads in the import wizard.                                                                           |
| `upload_max_filesize`| `32M`     | Must be ≤ `post_max_size`.                                                                                                  |
| `max_input_vars`     | `3000`    | Certificate editor posts large payloads via JSON encoded into form fields; raise if editing 20+ templates at once.          |
| `default_socket_timeout` | `10`  | cURL uses its own timeout (10s hard, 5s connect — see MoodleClient::CURL_TIMEOUT), but the default socket applies elsewhere.|

## 2. Error reporting

| Directive           | Value       | Notes |
| ------------------- | ----------- | ----- |
| `display_errors`    | `Off`       | Prevents stack traces leaking credentials or Moodle tokens to end users. |
| `display_startup_errors` | `Off`  | Same. |
| `log_errors`        | `On`        | FS Tools::log() already captures most — OS log is belt-and-braces. |
| `error_log`         | `/var/log/php/error.log` | Must be writable to the PHP-FPM user only. |

In staging set `display_errors = Off` anyway; the FS `VisorErrores`
plugin is the supported way to inspect failures through the UI.

## 3. Session + cookie security

| Directive                          | Value    | Why |
| ---------------------------------- | -------- | --- |
| `session.cookie_httponly`          | `1`      | Blocks JS from reading session cookies — mitigates XSS impact. |
| `session.cookie_secure`            | `1`      | Session cookie only over HTTPS. **Requires HTTPS at the edge.** |
| `session.cookie_samesite`          | `Lax`    | Modern default; blocks most CSRF without breaking OAuth callbacks. |
| `session.use_strict_mode`          | `1`      | Rejects uninitialised session IDs. |
| `session.use_only_cookies`         | `1`      | Session ID must not travel in the URL. |

F2.6 landed the cookie-side of this for the `mm_wizard_type`
cookie. `session.*` above covers the native PHP session.

## 4. File system

| Directive             | Value                                   | Why |
| --------------------- | --------------------------------------- | --- |
| `open_basedir`        | `/var/www/facturascripts:/tmp`          | Restricts file access. Certificate PDF generation may need `/tmp`; the plugin does not write anywhere else. |
| `allow_url_fopen`     | `Off`                                   | SSRF defence — forces `file_get_contents` over HTTP to go through cURL, which is guarded by `IpValidator`. |
| `allow_url_include`   | `Off`                                   | Always. |
| `sys_temp_dir`        | `/var/www/facturascripts/tmp`           | Out of the shared `/tmp` so other tenants on a shared host can't read intermediate PDFs. |

## 5. Dangerous functions

`disable_functions` should include at minimum:

```
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,assert,eval
```

The plugin does not call any of these. Disabling them limits the
blast radius of a future RCE in FS core or another plugin.

## 6. Random number generator

The `Lib/Moodle/UsernameGenerator::randomAlias()` helper (F10.5)
uses `random_bytes()`. On most hosts this pulls from
`/dev/urandom` through PHP's Sodium fallback. Verify at install
time with:

```bash
php -r 'var_dump(bin2hex(random_bytes(8)));'
```

If the command errors, the CSPRNG is misconfigured and random
aliases fall back to `sha1(microtime)` (documented in the
generator). Fix the host before enabling `random_alias`.

## 7. OPcache

Enable OPcache in production:

```ini
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1     ; dev
opcache.validate_timestamps = 0     ; prod (requires deploy-time reset)
opcache.revalidate_freq = 60
```

Disabling `validate_timestamps` in production doubles request
throughput for a busy Moodle site but requires `opcache_reset()`
on every deploy. The plugin does not call `opcache_reset()` itself
— add it to your post-deploy hook.

## 8. Verification script

Drop this into your playbook to audit the running config:

```bash
#!/usr/bin/env bash
set -euo pipefail
php -r '
$want = [
  "display_errors"            => "",
  "allow_url_fopen"           => "",
  "allow_url_include"         => "",
  "session.cookie_httponly"   => "1",
  "session.cookie_secure"     => "1",
  "session.cookie_samesite"   => "Lax",
];
foreach ($want as $k => $v) {
  $actual = (string) ini_get($k);
  if ($actual !== $v) {
    echo "FAIL $k = \"$actual\" (expected \"$v\")\n";
    exit(1);
  }
}
echo "php.ini hardening: OK\n";'
```

Run from CI against the container image.
