# MoodleManagement — Brainstorming: Funcionalidades Pendientes

> **Versión**: 2.0 — Actualizado: 2026-04-17 (F11.3)
>
> Este documento contiene **solo lo que falta implementar** y es **técnicamente viable usando únicamente Moodle CORE** (sin plugins custom ni modificaciones al core de Moodle).
>
> Para ver qué ya está implementado, consultar el [README.md](../README.md).

---

## Mapa v2.0 A → J (consolidado)

> `@since 2.0 — V2.0-ACTION-PLAN F11.3 · §9.3`
>
> Cada letra corresponde a un bloque temático de v2.0. Las secciones A-I
> recogen las funcionalidades de producto añadidas durante el desarrollo
> del plan; la J registra el trabajo de auditoría y remediación que
> cierra la versión.

| Ref.   | Título                                               | Estado       | Entregables clave                                                                 | Referencia                                    |
|--------|------------------------------------------------------|--------------|-----------------------------------------------------------------------------------|-----------------------------------------------|
| v2.0-A | Dashboard avanzado con KPIs de retención             | ✅ Completa  | `MoodleDashboard` loadAdvancedKpis + charts ingresos/top clientes                 | README §Dashboard                             |
| v2.0-B | Notificaciones por email de expiración               | ✅ Completa  | `Cron::expiryCheck::sendExpiryNotification`, col. `expiry_notified_at`             | BRAINSTORMING §v2.0-B                         |
| v2.0-C | Certificados PDF                                     | ✅ Completa  | `Lib/CertificatePdfGenerator`, `Controller/MoodleCertificatePdf`                  | BRAINSTORMING §v2.0-C                         |
| v2.0-D | Wizard importación con preview y mapeo               | ✅ Completa  | `Controller/MoodleImportWizard` 4 pasos + `Lib/MoodleImportMapping`                | BRAINSTORMING §v2.0-D                         |
| v2.0-E | Plantillas de certificado configurables              | ✅ Completa  | Tabla `moodle_certificate_templates`, selector en `EditMoodleCertificate`          | BRAINSTORMING §v2.0-E                         |
| v2.0-F | Emails de expiración configurables + reenvío manual  | ✅ Completa  | `Lib/ExpiryNotifier` con días escalonados + reenvío manual                        | BRAINSTORMING §v2.0-F                         |
| v2.0-G | Selector de rango en dashboard                       | ✅ Completa  | 3m/6m/12m/YTD con persistencia query-string                                       | BRAINSTORMING §v2.0-G                         |
| v2.0-H | Wizard con memoria (cookie)                          | ✅ Completa  | `loadPrefs/savePrefs` + cookie 90 días                                            | BRAINSTORMING §v2.0-H                         |
| v2.0-I | Exportar resultados del wizard a CSV                 | ✅ Completa  | `exportCsvAction` con BOM + separador `;` compatible Excel                        | BRAINSTORMING §v2.0-I                         |
| v2.0-J | Auditoría integral + remediación (Fases 0–12)        | 🚧 En curso  | 166 tareas del plan; 138/166 cerradas al cierre de F11.3                          | `docs/V2.0-ACTION-PLAN.md`                   |

> **Nota**: la remediación v2.0-J se ejecuta como un plan en 13 fases
> en paralelo al trabajo de producto A–I. El detalle por finding está
> en `docs/V2.0-ACTION-PLAN.md` y el tracking granular en
> `docs/V2.0-TASK-CHECKLIST.md`.

## Estado Actual — Resumen de lo Implementado (v2.0)

| Sección Original | Estado | Notas |
|---|---|---|
| 1. Gestión de Instancias | **COMPLETA** | CRUD, health check, multi-entorno, test conexión, onboarding config |
| 2. Sincronización de Usuarios | **COMPLETA** | Import, sync bidireccional, cohorts, mapeo custom fields |
| 3. Sincronización de Cursos | **COMPLETA** | Import, productos, categorías, duplicar, imágenes, duración |
| 3-ANEXO. Contenido del Curso | **COMPLETA (Fase 1)** | Show/hide/move/duplicate/delete módulos y secciones, stealth, indent, group mode, acciones masivas |
| 4. Matrículas (Enrolments) | **COMPLETA** | Manual, self, meta, estados, vinculación a documentos, expiración con renovación |
| 5. Roles y Permisos | **COMPLETA** | 8 roles estándar, mapeo por instancia, import |
| 7. Automatización | **COMPLETA** | Workers (factura, presupuesto, pedido, contacto update/delete, badges, onboarding), cron (health, sync, reconciliación, cleanup, expiración+renovación) |
| 10. Integración WS | **COMPLETA** | MoodleClient con 65+ métodos, manejo de errores |
| 18. Modelos de Datos (parcial) | **COMPLETA** | 8 tablas: instances, user_map, course_map, enrolments, role_map, cohorts, course_categories, certificates |

### Lo que NO se puede implementar con Moodle CORE

| Funcionalidad | Razón |
|---|---|
| Crear módulos (actividades) con configuración completa | No hay WS core para `add_moduleinfo()`. `core_courseformat_create_module` solo funciona con `FEATURE_QUICKCREATE` (solo `mod_subsection` en Moodle 4.5) |
| Renombrar/editar configuración de un módulo existente | No hay WS core para `update_moduleinfo()` |
| Editar nombre/summary de una sección | No hay WS core expuesto para `course_update_section()` |
| Monitoreo de espacio en disco de Moodle | Requiere WS custom |
| Gestión remota de plugins (`core_admin_set_plugin_state`) | Requiere capability `moodle/site:config` que normalmente no se asigna a usuarios WS |
| Webhooks/callbacks desde Moodle hacia FS | Moodle no tiene sistema de webhooks nativo; requiere plugin custom |
| Token rotation automático | Moodle no expone WS para renovar tokens |
| Encriptación de tokens en DB | FacturaScripts no tiene infraestructura de encriptación de campos |

---

## ~~PENDIENTE 1: REPORTES ACADÉMICOS~~ — COMPLETADO

### Implementado
- Tab "Progreso Académico" en `EditMoodleUserMap` con cursos, completion %, calificaciones y último acceso (consulta en tiempo real via API)
- Reportes financieros-académicos: matrículas sin facturar, matrículas por estado, matrículas por curso
- Dashboard Moodle con estadísticas globales (total matrículas, por mes, por método, top cursos)
- Tab "Formación Moodle" en `EditCliente` con resumen de contactos matriculados, total facturado y desglose por cursos

### Funciones WS utilizadas
```
core_completion_get_activities_completion_status  → Completion por actividad
core_completion_get_course_completion_status       → Completion del curso
gradereport_user_get_grade_items                  → Calificaciones del usuario
```

---

## ~~PENDIENTE 2: CERTIFICADOS Y BADGES~~ — COMPLETADO

### Implementado
- Modelo `MoodleCertificate` con tabla `moodle_certificates` para persistir badges en FS
- Sync de badges por usuario (`core_badges_get_user_badges`)
- Sync masivo de badges para todos los usuarios mapeados
- Sync automático al guardar un mapeo de usuario (Worker)
- Listado global de badges con filtros por contacto e instancia
- Vista detalle de badge con datos de Moodle (nombre, curso, fecha, hash, URLs)

### Funciones WS utilizadas
```
core_badges_get_user_badges  → Badges de un usuario
```

### Restricciones
- Moodle NO tiene WS core para crear badges — solo para leerlos
- FS solo puede leer los badges emitidos, no emitirlos

---

## ~~PENDIENTE 3: GESTIÓN DE GRUPOS~~ — COMPLETADO

### Implementado
- Tab "Grupos" en `EditMoodleCourseMap` con listado en tiempo real desde Moodle
- Crear y eliminar grupos en un curso directamente desde FS
- Ver miembros de cada grupo con conteo
- Agregar y eliminar miembros de grupo

### Funciones WS utilizadas
```
core_group_get_course_groups    → Listar grupos de un curso
core_group_create_groups        → Crear grupos
core_group_update_groups        → Actualizar grupos
core_group_delete_groups        → Eliminar grupos
core_group_get_group_members    → Miembros de un grupo
core_group_add_group_members    → Agregar miembros a grupo
core_group_delete_group_members → Eliminar miembros de grupo
```

---

## ~~PENDIENTE 4: MENSAJERÍA~~ — COMPLETADO

### Implementado
- Chat bidireccional estilo WhatsApp (Tab "Chat" en EditMoodleUserMap) con auto-refresh cada 5s
- Historial de conversación en tiempo real via API
- Envío de mensajes individuales (modal) y masivos (por curso)
- Selección de destinatarios: todos los matriculados o específicos
- `service_userid` almacenado en `moodle_instances` para identificar al remitente
- Hub de Conversaciones (ListMoodleConversation): lista central de todas las conversaciones abiertas
  - Semáforo visual, badges de no leídos, filtros (todos/no leídos/leídos)
  - Botón "Nueva Conversación" con buscador de usuarios
  - Auto-refresh cada 10 segundos
- Icono de chat en navbar con badge de no leídos (refresh cada 30s)
- Marcado automático como leídos al abrir un chat

### Funciones WS utilizadas
```
core_message_send_instant_messages                      → Enviar mensaje instantáneo
core_message_get_conversation_between_users             → Obtener conversación entre dos usuarios
core_message_get_conversation_messages                  → Leer mensajes de conversación
core_message_send_messages_to_conversation              → Enviar a conversación existente
core_message_get_conversations                          → Listar conversaciones (hub)
core_message_get_unread_conversations_count             → Contar no leídos (badge navbar)
core_message_mark_all_conversation_messages_as_read     → Marcar como leídos
```

### Restricciones
- El remitente es siempre el usuario de servicio WS
- Solo se leen conversaciones donde participa el usuario WS (no espionaje)

---

## ~~PENDIENTE 5: CALENDARIO~~ — COMPLETADO

### Implementado
- Tab "Calendario" en `EditMoodleUserMap` con listado de eventos del usuario
- Crear eventos de tipo usuario directamente desde FS
- Eliminar eventos desde FS
- Visualización con nombre, descripción, fecha y tipo

### Funciones WS utilizadas
```
core_calendar_get_calendar_events      → Leer eventos
core_calendar_create_calendar_events   → Crear eventos
core_calendar_delete_calendar_events   → Eliminar eventos
```

---

## ~~PENDIENTE 6: FACTURACIÓN AVANZADA~~ — COMPLETADO

### Implementado
- Campo `duracion_dias` en `moodle_course_map` para definir duración de acceso tras matrícula (0 = ilimitado)
- `EnrolmentWorker` calcula `timeend = timestart + duracion_dias * 86400` al matricular
- `expiryCheck` en Cron genera automáticamente un `PresupuestoCliente` de renovación cuando una matrícula está por expirar (7 días) y el curso tiene duración configurada
- Presupuesto vinculado a la matrícula (campo `idpresupuesto` en enrolment)
- Flujo completo: presupuesto → aprobación → factura → EnrolmentWorker renueva

### Archivos modificados
- `Table/moodle_course_map.xml` — campo `duracion_dias`
- `Model/MoodleCourseMap.php` — propiedad + clear
- `XMLView/EditMoodleCourseMap.xml` — campo editable en precios
- `Worker/EnrolmentWorker.php` — cálculo de timeend
- `Cron.php` — método `generateRenewalEstimate()` en expiryCheck

### Pendiente para futuras versiones
- Paquetes/Bundles de cursos (ProductoKit)
- Facturación por consumo (horas conectado, actividades completadas)

---

## ~~PENDIENTE 7: FLUJOS COMERCIALES AUTOMATIZADOS~~ — COMPLETADO (parcial)

### 7.1 Onboarding Completo — COMPLETADO
- Campos de configuración en `moodle_instances`: `onboarding_enabled`, `onboarding_course_id`, `onboarding_cohort_id`, `onboarding_welcome_message`
- `OnboardingWorker` se dispara en `Model.MoodleUserMap.Insert` y ejecuta:
  1. Matrícula automática en curso de bienvenida
  2. Asignación al cohort por defecto
  3. Envío de mensaje de bienvenida personalizado (variables: %name%, %username%, %site%)
  4. Creación de nota de onboarding en Moodle
- Grupo "Onboarding" en XMLView de instancias con checkbox de activación

### 7.2 Renovación Automática — COMPLETADO
- Implementado en PENDIENTE 6: `expiryCheck` genera presupuesto de renovación automáticamente

### Pendiente para futuras versiones
- Cross-selling basado en Completion (campo `next_course_id` en course_map + polling de completion)

---

## ~~PENDIENTE 8: NOTAS Y ANOTACIONES~~ — COMPLETADO

### Implementado
- Tab "Notas" en `EditMoodleUserMap` con historial completo de notas del usuario
- Crear notas de tipo sitio o personal directamente desde FS
- Eliminar notas desde FS
- Notas ordenadas por fecha de creación (más reciente primero)
- Uso automático en Onboarding: nota creada al mapear un nuevo usuario

### Funciones WS utilizadas
```
core_notes_create_notes       → Crear notas en perfil de usuario
core_notes_get_course_notes   → Leer notas de un curso/usuario
core_notes_delete_notes       → Eliminar notas
```

---

## Resumen de Viabilidad Técnica

| Pendiente | Complejidad | WS Core Disponible | Prioridad Sugerida |
|---|---|---|---|
| ~~1. Reportes Académicos~~ | ~~Media~~ | **COMPLETADO** — Progreso individual, reportes financieros, dashboard, formación por empresa | ~~Alta~~ |
| ~~2. Certificados/Badges~~ | ~~Baja~~ | **COMPLETADO** — Sync, persistencia, listado global, detalle | ~~Media~~ |
| ~~3. Gestión de Grupos~~ | ~~Media~~ | **COMPLETADO** — CRUD grupos, gestión de miembros | ~~Media~~ |
| ~~4. Mensajería~~ | ~~Baja~~ | **COMPLETADO** — Chat bidireccional, masivo, hub, navbar badge, mark-as-read | ~~Baja~~ |
| ~~5. Calendario~~ | ~~Baja~~ | **COMPLETADO** — Tab en usuario, crear/eliminar eventos | ~~Baja~~ |
| ~~6. Facturación Avanzada~~ | ~~Media~~ | **COMPLETADO** — Duración, expiración, presupuestos de renovación | ~~Alta~~ |
| ~~7. Flujos Comerciales~~ | ~~Alta~~ | **COMPLETADO (parcial)** — Onboarding completo + renovación. Pendiente: cross-selling | ~~Media~~ |
| ~~8. Notas/Anotaciones~~ | ~~Baja~~ | **COMPLETADO** — Tab en usuario, CRUD notas, uso en onboarding | ~~Baja~~ |

### Funciones WS agregadas en v1.2

Las siguientes funciones fueron agregadas al servicio WS `FacturaScripts Integration` para las funcionalidades de notas y calendario:

```
core_notes_create_notes              → Crear notas (Notas + Onboarding)
core_notes_get_course_notes          → Leer notas (Tab Notas)
core_notes_delete_notes              → Eliminar notas (Tab Notas)
core_calendar_get_calendar_events    → Leer eventos (Tab Calendario)
core_calendar_create_calendar_events → Crear eventos (Tab Calendario)
core_calendar_delete_calendar_events → Eliminar eventos (Tab Calendario)
```

> **Nota**: Todas las funcionalidades pendientes (1-8) están ahora completadas. Ver README para la lista completa de funciones WS.

---

## Novedades v2.0 — Experiencia, Automatización y Documentos

La versión 2.0 **no agrega funciones WS nuevas** (usa las ya existentes). Se enfoca en mejorar la experiencia del administrador, la comunicación con el alumno y la entrega de documentación.

### ~~v2.0-A: Dashboard Avanzado con KPIs de Retención~~ — COMPLETADO

**Objetivo**: dar al administrador una vista estratégica del negocio de formación.

#### Implementado
- Tarjetas adicionales en `MoodleDashboard`:
  - **Tasa de Retención** — % de alumnos con matrícula activa (no expirada)
  - **Tasa de Renovación** — % de matrículas expiradas que generaron presupuesto de renovación
  - **Matrículas por Expirar** — cuenta de enrolments activos con `timeend` en los próximos 7 días
  - **Tasa de Finalización** — % de matrículas con `status = completed` sobre el total
- **Gráfico de Tendencia de Ingresos** (Chart.js línea) con los últimos 6 meses de facturación derivada de cursos Moodle (join `moodle_enrolments → facturascli`)
- **Gráfico Top Clientes** (Chart.js barra horizontal) con los 5 clientes con más matrículas

#### Archivos nuevos/modificados
- `Controller/MoodleDashboard.php` — nuevo método `loadAdvancedKpis()`
- `View/MoodleDashboard.html.twig` — 4 KPIs + 2 gráficos nuevos

---

### ~~v2.0-B: Notificaciones por Email de Expiración~~ — COMPLETADO

**Objetivo**: avisar proactivamente al alumno cuando su matrícula está por expirar, complementando el presupuesto de renovación que ya se genera automáticamente.

#### Implementado
- Nueva columna `expiry_notified_at` en `moodle_enrolments` (timestamp) para evitar reenvíos
- `Cron::expiryCheck()` → método `sendExpiryNotification()` que:
  1. Carga el `Contacto` vinculado a la matrícula
  2. Si el contacto tiene email, compone un mensaje con:
     - Saludo personalizado (`%name%`)
     - Nombre del curso, días restantes, fecha de expiración
     - Nota de renovación si `idpresupuesto` está presente
     - Cierre
  3. Envía con `NewMail` + `TextBlock` (core FS)
  4. Marca `expiry_notified_at = now()` si se envió
- Traducciones ES/EN completas: `expiry-email-subject`, `expiry-email-greeting`, `expiry-email-body`, `expiry-email-renewal-note`, `expiry-email-closing`, `expiry-email-sent`, `expiry-email-failed`

#### Archivos nuevos/modificados
- `Table/moodle_enrolments.xml` — columna `expiry_notified_at`
- `Model/MoodleEnrolment.php` — propiedad `expiry_notified_at`
- `Cron.php` — import `NewMail` + `TextBlock` + método `sendExpiryNotification()`
- `Translation/es_ES.json`, `Translation/en_EN.json`

---

### ~~v2.0-C: Certificados PDF~~ — COMPLETADO

**Objetivo**: generar desde FacturaScripts un documento PDF de certificado profesional para cada badge/certificado sincronizado desde Moodle, sin depender de plugins externos.

#### Implementado
- `Lib/CertificatePdfGenerator.php` — clase estática que genera un PDF A4 horizontal usando **Cezpdf** (incluido en FS vendor):
  - Doble borde decorativo (azul oscuro + dorado)
  - Título "CERTIFICADO" en 36pt
  - Subtítulo "Se otorga el presente certificado a"
  - Nombre del alumno centrado en 32pt bold (cargado desde `Contacto` por `idcontacto`)
  - Divisor dorado bajo el nombre
  - Texto "por haber completado satisfactoriamente el curso"
  - Nombre del curso en 22pt azul
  - Nombre del badge (en cursiva) si difiere del curso
  - Fecha de emisión y hash único de verificación
  - Nombre de la empresa emisora como firma (usando `Empresas::default()`)
  - Texto de verificación al pie
- `Controller/MoodleCertificatePdf.php` — endpoint público que recibe `?code=<id>`, carga el certificado, genera el PDF y lo devuelve con `Content-Type: application/pdf`
- Botón **Descargar PDF** en `XMLView/EditMoodleCertificate.xml` (tipo `js`) que abre `MoodleCertificatePdf?code=<id>` en nueva pestaña
- `Assets/JS/CertificatePdf.js` — función JS que lee el input `code` y abre el endpoint

#### Archivos nuevos/modificados
- `Lib/CertificatePdfGenerator.php` (nuevo)
- `Controller/MoodleCertificatePdf.php` (nuevo)
- `Controller/EditMoodleCertificate.php` — override de `createViews()` para cargar el JS
- `Assets/JS/CertificatePdf.js` (nuevo)
- `XMLView/EditMoodleCertificate.xml` — bloque `<rows>` con el botón

---

### ~~v2.0-D: Wizard de Importación con Preview y Mapeo de Campos~~ — COMPLETADO

**Objetivo**: reemplazar la importación "ciega" (modal con solo 3 campos) por un asistente de 4 pasos que permite previsualizar los usuarios de Moodle antes de importar y decidir exactamente qué campos copiar.

#### Implementado
- `Controller/MoodleImportWizard.php` — controller que extiende `Controller` (no `ListController`) con estado persistido en `$_SESSION['moodle_import_wizard']`
- **Paso 1 – Configuración**: selección de instancia, filtros por tipos de autenticación (manual/email/ldap/oauth2), modo de importación (create_client_per_user o assign_to_existing_client) con toggle de picker de cliente
- **Paso 2 – Preview**: fetch de usuarios Moodle, anotación con `exists_mapping` (gris, checkbox deshabilitado) y `exists_contact` (amarillo). Tabla con búsqueda en vivo (JS filtro por email/nombre) y botones: Seleccionar Todo / Ninguno / Solo Nuevos
- **Paso 3 – Mapeo de Campos**: 12 campos Moodle disponibles (email, firstname, lastname, phone1, phone2, idnumber, city, country, address, department, institution, description) mapeables a 14 campos Contacto. Muestra valor de muestra del primer usuario para cada campo
- **Paso 4 – Resultados**: KPIs (importados / omitidos / errores) + tabla detalle por usuario
- Normalización ISO-2 → ISO-3 para `codpais` (ES→ESP, MX→MEX, AR→ARG, etc.)
- Botón **Wizard de Importación** en `MoodleUserSync` (color success, icono `fa-wand-magic-sparkles`)
- Traducciones ES/EN completas (≈40 keys con prefijo `wizard-*`)

#### Archivos nuevos/modificados
- `Controller/MoodleImportWizard.php` (nuevo, ~430 líneas)
- `View/MoodleImportWizard.html.twig` (nuevo, stepper Bootstrap 5 + JS vanilla)
- `Controller/MoodleUserSync.php` — botón extra `link` al wizard
- `Translation/es_ES.json`, `Translation/en_EN.json`

---

### ~~v2.0-E: Plantillas de Certificado Configurables~~ — COMPLETADO

**Objetivo**: eliminar los valores hardcodeados del generador de PDF de certificado (título, colores, logo, tamaños de fuente) permitiendo al administrador crear plantillas reutilizables, globales o por instancia, con una cadena de fallback robusta.

#### Implementado
- Nueva tabla `moodle_certificate_templates` con 20 columnas:
  - `id`, `name`, `idinstance` (nullable FK CASCADE), `is_default`
  - Textos configurables: `title_text`, `subtitle_text`, `completed_text`, `verify_text`, `issuer_label`
  - Estilo: `primary_color` (#RRGGBB, borde exterior), `accent_color` (#RRGGBB, borde interior), `title_font_size`, `name_font_size`, `course_font_size`
  - `logo_path` para incluir un logotipo en el PDF
  - Flags: `show_unique_hash`, `show_date_issued`, `show_issuer`
  - `notes`, `creation_date`
- `Model/MoodleCertificateTemplate.php` con:
  - `clear()` con defaults seguros (`#0054A1` primario, `#D9B340` acento, 36/32/22 pt)
  - `findBest(?int $idinstance)` aplica la cadena: instancia+default → instancia-cualquiera → global-default → null
  - `unsetDefaults()` garantiza un único default por ámbito
  - `test()` valida regex hex `/^#[0-9A-Fa-f]{6}$/` y rangos de fuente
- `Lib/CertificatePdfGenerator::generate(MoodleCertificate $cert, ?MoodleCertificateTemplate $template = null)` ahora acepta una plantilla opcional; usa `resolveColor()` y `resolveLogoPath()` helpers y wrappea `show_*` con condicionales
- Nuevas entradas de menú: Listado y Edición de plantillas

#### Archivos nuevos/modificados
- `Table/moodle_certificate_templates.xml` (nuevo)
- `Model/MoodleCertificateTemplate.php` (nuevo, ~240 líneas)
- `Controller/ListMoodleCertificateTemplate.php` (nuevo)
- `Controller/EditMoodleCertificateTemplate.php` (nuevo)
- `XMLView/ListMoodleCertificateTemplate.xml` (nuevo)
- `XMLView/EditMoodleCertificateTemplate.xml` (nuevo, grupos: general, texts, style, elements, notes)
- `Lib/CertificatePdfGenerator.php` (modificado — signature + helpers + uso de plantilla)

---

### ~~v2.0-F: Emails de Expiración con Días Configurables y Reenvío Manual~~ — COMPLETADO

**Objetivo**: que el administrador pueda configurar **cuándo** se envía el aviso de expiración (uno o varios umbrales por instancia) y que pueda **forzar** un reenvío manual desde la ficha de matrícula.

#### Implementado
- Nueva columna `expiry_notification_days` (varchar 100) en `moodle_instances`. Acepta valores tipo `"7"`, `"15,7,3"` — varios umbrales separados por comas. Default `"7"`
- `MoodleInstance::getExpiryNotificationDays(): array` devuelve los umbrales como `int[]` ordenados descendentemente, filtrando no-dígitos; si queda vacío devuelve `[7]`
- Nueva columna `expiry_notifications_sent` (TEXT, JSON array) en `moodle_enrolments`. Sustituye el uso exclusivo de `expiry_notified_at` para permitir múltiples umbrales sin duplicados
- Nuevos métodos en `MoodleEnrolment`: `getNotifiedThresholds()`, `markThresholdNotified(int)`, `resetNotifiedThresholds()`
- Refactor de `Cron::expiryCheck()`:
  - Ahora itera `MoodleInstance::all([status=active])` y dentro los enrolments por instancia
  - Calcula `maxDays = max(thresholds)` como ventana de búsqueda
  - `pickPendingThreshold()` recorre umbrales de mayor a menor y devuelve el primero pendiente
- Nueva clase `Lib/ExpiryNotifier` (helper estático) con `send(MoodleEnrolment, int $daysLeft, int $threshold, bool $force = false, ?string $logChannel = null): bool`
  - Permite invocar desde el Cron y desde el controlador sin depender de `CronClass::$pluginName`
  - Con `$force = true` reenvía aunque el umbral ya esté notificado
- Botón **Reenviar email de expiración** en `EditMoodleEnrolment` (acción `resend-expiry-email`, confirm=true, icono `fa-envelope`):
  - Valida: registro cargado, contacto con email, `status = enrolled`, `timeend` futuro
  - Usa el mayor umbral configurado y llama a `ExpiryNotifier::send(..., force=true)`
- Grupo `expiry` en XMLView de instancia con el nuevo campo y su hint

#### Archivos nuevos/modificados
- `Table/moodle_instances.xml` — columna `expiry_notification_days`
- `Table/moodle_enrolments.xml` — columna `expiry_notifications_sent`
- `Model/MoodleInstance.php` — propiedad + clear + `getExpiryNotificationDays()`
- `Model/MoodleEnrolment.php` — propiedad + `getNotifiedThresholds()` + `markThresholdNotified()` + `resetNotifiedThresholds()`
- `Lib/ExpiryNotifier.php` (nuevo, ~110 líneas)
- `Cron.php` — refactor `expiryCheck()` + `pickPendingThreshold()` + delegación a `ExpiryNotifier`
- `Controller/EditMoodleEnrolment.php` — `resend-expiry-email` case + `resendExpiryEmailAction()`
- `XMLView/EditMoodleEnrolment.xml` — 5º botón de acción
- `XMLView/EditMoodleInstance.xml` — grupo `expiry`
- `Translation/es_ES.json`, `Translation/en_EN.json` — `expiry-notification-days`, `resend-expiry-email`, `expiry-email-resent`, `expiry-email-resend-failed`, etc. `expiry-email-sent` ahora incluye `%days%`

---

### ~~v2.0-G: Dashboard con Selector de Rango de Fechas~~ — COMPLETADO

**Objetivo**: que el administrador pueda ajustar el periodo cubierto por los gráficos de tendencia y matrículas mensuales sin depender de una ventana fija de 6 meses.

#### Implementado
- Constante `ALLOWED_RANGES = ['3m', '6m', '12m', 'ytd']` en `MoodleDashboard`
- Nuevo prop público `$range = '6m'` leído desde query string `?range=<valor>`
- Método `buildMonthBuckets(): array{startDate, labels, keys}`:
  - `'ytd'` → Jan 1 del año actual hasta el mes en curso
  - `'3m' / '6m' / '12m'` → N meses atrás hasta el mes en curso
  - Devuelve labels legibles para Chart.js y keys `"YYYY-MM"` para agrupación SQL
- Consulta de matrículas mensuales y consulta de ingresos ahora usan `$buckets['startDate']` y `$buckets['keys']`
- Botonera de 4 botones en `bodyHeaderOptions` del template Twig (botón activo `btn-primary`, resto `btn-outline-secondary`)
- Labels traducibles: `range-3m`, `range-6m`, `range-12m`, `range-ytd`, `date-range`

#### Archivos nuevos/modificados
- `Controller/MoodleDashboard.php` — prop `$range`, `ALLOWED_RANGES`, `buildMonthBuckets()`, uso en queries
- `View/MoodleDashboard.html.twig` — bloque `bodyHeaderOptions` con la botonera de 4 opciones
- `Translation/es_ES.json`, `Translation/en_EN.json` — keys `range-*` y `date-range`. Se eliminó el sufijo "(6 meses)" de `revenue-trend`

---

### ~~v2.0-H: Wizard con Memoria de Configuración (Cookie)~~ — COMPLETADO

**Objetivo**: que el wizard recuerde la última configuración usada por el administrador (instancia, modo, cliente, tipos de autenticación, mapeo de campos) y la pre-rellene en siguientes ejecuciones.

#### Implementado
- Constantes en `MoodleImportWizard`: `PREFS_COOKIE = 'moodle_wizard_prefs'`, `PREFS_COOKIE_TTL = 7776000` (90 días)
- Nueva propiedad pública `$prefs` accesible desde Twig como `fsc.prefs`
- `loadPrefs()` decodifica la cookie JSON y rellena `$this->prefs` con `idinstance`, `import_mode`, `codcliente`, `auth_types`, `mapping`
- `savePrefs()` serializa el estado actual a JSON y escribe la cookie (path='/', expira en 90 días, NO httpOnly para permitir posibles futuros accesos JS)
- `savePrefs()` se llama al final de `processStep1()` y `processStep3()` cuando son exitosos
- En Twig, el Paso 1 usa una cadena de fallback con `{% set %}`: **state > prefs > hardcoded default**
- El Paso 3 (mapeo) usa idéntica cadena: `state.mapping > fsc.prefs.mapping > fsc.defaultMapping`
- Se muestra un info alert en el Paso 1 si se cargó memoria previa (`hasMemory` flag)

#### Archivos nuevos/modificados
- `Controller/MoodleImportWizard.php` — constantes, `$prefs`, `loadPrefs()`, `savePrefs()`
- `View/MoodleImportWizard.html.twig` — `{% set %}` chain en Paso 1 y Paso 3 + alert informativo
- `Translation/es_ES.json`, `Translation/en_EN.json` — key `wizard-prefs-loaded`

---

### ~~v2.0-I: Exportar Resultado del Wizard a CSV~~ — COMPLETADO

**Objetivo**: permitir al administrador descargar los resultados del wizard (importados / omitidos / errores) como un archivo CSV listo para Excel, para archivo, auditoría o revisión posterior.

#### Implementado
- Handler GET en `MoodleImportWizard::privateCore()`: si `$this->request->get('action') === 'export-csv'` → `exportCsvAction()`
- `exportCsvAction()`:
  - Valida que exista `$this->state['result']` con `details`
  - Envía headers: `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="moodle-import-<timestamp>.csv"`
  - Escribe BOM UTF-8 (`\xEF\xBB\xBF`) para compatibilidad con Excel
  - Usa `fputcsv(..., ';')` con separador punto y coma (estándar Excel en locales ES)
  - Columnas: tipo, email, FS ID, Moodle ID, password, mensaje
- Botón **Descargar CSV** en el Paso 4 del Twig (`<a href="?action=export-csv">`, outline-secondary, junto a "Finalizar")
- Traducciones: `wizard-download-csv`, `wizard-no-results`

#### Archivos nuevos/modificados
- `Controller/MoodleImportWizard.php` — handler GET + `exportCsvAction()`
- `View/MoodleImportWizard.html.twig` — botón de descarga en Paso 4
- `Translation/es_ES.json`, `Translation/en_EN.json` — 2 keys nuevas

---

### Resumen de Refinamientos v2.0 (E–I)

| Ref. | Feature | Tablas / Modelos | Views / Controllers | UX |
|---|---|---|---|---|
| E | Plantillas de certificado configurables | `moodle_certificate_templates` (20 cols) + `MoodleCertificateTemplate` | `List/Edit` + modificación del generador PDF | Menú dedicado, cadena de fallback transparente |
| F | Días de aviso configurables + reenvío manual | Col. `expiry_notification_days` + col. `expiry_notifications_sent` (JSON) | Botón reenviar + `Lib/ExpiryNotifier` helper | Configuración por instancia, múltiples alertas escalonadas |
| G | Selector de rango en dashboard | (sin cambios de DB) | `buildMonthBuckets()` + botonera Twig | 4 opciones (3m/6m/12m/YTD), persistencia por query-string |
| H | Wizard recuerda config | (cookie, sin cambios de DB) | `loadPrefs/savePrefs` + Twig `{% set %}` | Cookie 90 días, alert informativo al cargar |
| I | Export CSV del wizard | (sin cambios de DB) | `exportCsvAction()` con BOM + `;` | Botón en Paso 4, compatible Excel |

> **Nota**: en la numeración original del requerimiento los refinamientos se etiquetaron A–F. En este documento se renombraron a E–I para no solapar con las secciones previas (v2.0-A a v2.0-D).

---

## Próximas Ideas (post-2.0)

| Idea | Razón de no estar en v2.0 | Complejidad estimada |
|---|---|---|
| Cross-selling por Completion | Requiere polling continuo de progreso y lógica de "siguiente curso" | Alta |
| Paquetes/Bundles de cursos | Depende de la infraestructura de ProductoKit de FS | Media |
| Facturación por consumo (horas, actividades) | Requiere recolección de telemetría y modelo de precios por uso | Alta |
| Informes PDF con estadísticas del alumno | Similar al certificado pero con progreso + calificaciones | Media |
| Integración con Google Classroom / Teams | Fuera del alcance (no es Moodle) | Alta |
| Quiz Builder desde FS | Requiere WS custom (`mod_quiz_add_question`) | Muy alta |

> Todas las funcionalidades v1.0–v2.0 están en producción. Este documento seguirá registrando ideas pendientes para futuras versiones.
