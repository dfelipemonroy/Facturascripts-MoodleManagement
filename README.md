# MoodleManagement v1.0

Plugin para FacturaScripts que permite gestionar plataformas Moodle directamente desde el ERP. Conecta tu sistema de facturación con tu LMS mediante la API REST de Moodle.

*[English version below](#english--inglés)*

## Funcionalidades

### Gestión de Instancias Moodle
- Conexión a múltiples instancias de Moodle simultáneamente
- Test de conexión y monitoreo de estado (activa, mantenimiento, inactiva)
- Visualización de información del sitio (versión, release, funciones WS disponibles)
- Soporte para entornos: producción, staging, desarrollo

### Sincronización de Usuarios
- Importación masiva de usuarios desde Moodle a FacturaScripts
- Mapeo bidireccional usuario Moodle ↔ contacto FacturaScripts
- Creación automática de clientes/contactos al importar
- Sincronización individual y masiva

### Gestión de Cursos
- Importación y sincronización de cursos desde Moodle
- Vinculación de cursos Moodle con productos de FacturaScripts
- Creación automática de productos y familias por curso
- Duplicación de cursos directamente desde FS
- Gestión de categorías de cursos (importar, crear, sincronizar)
- Sincronización de imágenes de portada entre producto y curso

### Contenido del Curso (Actividades y Secciones)
- Visualización en tiempo real del contenido del curso vía API
- Gestión de secciones: crear, ocultar/mostrar, mover, eliminar
- Gestión de actividades: ocultar/mostrar, duplicar, mover entre secciones, eliminar
- Modo stealth (solo enlace) para actividades
- Indentación de actividades (derecha/izquierda)
- Cambio de modo de grupo (sin grupos, grupos separados, grupos visibles)
- Acciones masivas con selección múltiple
- Modal de detalle de módulo con información completa

### Matrículas (Enrolments)
- Gestión de matrículas por curso e instancia
- Soporte para métodos: manual, auto-matrícula, pago, cohort, meta-enlace
- Estados de matrícula: pendiente, matriculado, suspendido, desmatriculado
- Vinculación con documentos comerciales (facturas, pedidos, presupuestos)
- Meta-matrículas entre cursos

### Cohorts
- Importación de cohorts desde Moodle
- Sincronización de miembros
- Gestión de membresía (agregar/eliminar miembros)

### Roles
- Mapeo de los 8 roles estándar de Moodle por instancia
- Importación automática de roles estándar

### Automatización de Procesos
- **Matrícula automática**: al pagar una factura con productos vinculados a cursos, el alumno se matricula automáticamente en Moodle
- **Pre-matrícula**: al crear presupuestos o pedidos, se generan matrículas pendientes que se activan al facturar
- **Sincronización de contactos**: al modificar un contacto en FS, los datos se sincronizan automáticamente con Moodle
- **Suspensión automática**: al eliminar un contacto en FS, se suspende su cuenta y matrículas en Moodle
- **Health check** (cada hora): monitoreo automático del estado de todas las instancias Moodle
- **Sincronización incremental** (cada 6 horas): sincronización de usuarios y cursos con resolución de conflictos
- **Reconciliación** (diaria): verificación de integridad de mapeos de usuarios y matrículas contra Moodle
- **Limpieza** (diaria): eliminación automática de mapeos huérfanos (contactos eliminados)
- **Control de expiración** (cada 6 horas): detección de matrículas por vencer (7 días) y expiración automática

## Requisitos

- FacturaScripts >= 2025.6
- Moodle >= 4.0 (recomendado 4.5+)
- PHP >= 8.0 con extensión cURL

## Instalación

1. Copie la carpeta `MoodleManagement` en el directorio `Plugins` de FacturaScripts
2. Active el plugin desde **Admin > Plugins**
3. Configure al menos una instancia Moodle desde **Gestión Moodle > Instancias Moodle**

## Configuración del Servicio Web en Moodle

Para que el plugin funcione correctamente, debe crear un servicio web en Moodle con las funciones necesarias y generar un token de acceso.

### Paso 1: Habilitar Web Services en Moodle

1. Vaya a **Administración del sitio > General > Funciones avanzadas**
2. Marque **Habilitar servicios web** (`enablewebservices`)
3. Guarde los cambios

### Paso 2: Habilitar el protocolo REST

1. Vaya a **Administración del sitio > Servidor > Servicios web > Gestionar protocolos**
2. Habilite el protocolo **REST**

### Paso 3: Crear un usuario de servicio (recomendado)

1. Vaya a **Administración del sitio > Usuarios > Cuentas > Agregar un nuevo usuario**
2. Cree un usuario dedicado (ej: `ws_facturascripts`)
3. Asigne el rol **Manager** a nivel de sistema:
   - **Administración del sitio > Usuarios > Permisos > Asignar roles globales**
   - Seleccione **Manager** y agregue el usuario creado

### Paso 4: Crear el servicio web externo

1. Vaya a **Administración del sitio > Servidor > Servicios web > Servicios externos**
2. Haga clic en **Agregar**
3. Configure:
   - **Nombre**: `FacturaScripts Integration`
   - **Nombre corto**: `facturascripts`
   - **Habilitado**: Sí
   - **Usuarios autorizados**: Sí (solo usuarios específicos)
4. Guarde
5. En la lista de servicios, haga clic en **Usuarios autorizados** y agregue el usuario de servicio creado en el Paso 3

### Paso 5: Agregar las funciones WS al servicio

1. En la lista de servicios, haga clic en **Funciones** junto al servicio `FacturaScripts Integration`
2. Agregue las siguientes funciones:

#### Funciones esenciales (conexión e info)
| Función | Descripción |
|---------|-------------|
| `core_webservice_get_site_info` | Test de conexión e información del sitio |

#### Gestión de usuarios
| Función | Descripción |
|---------|-------------|
| `core_user_get_users` | Buscar usuarios |
| `core_user_get_users_by_field` | Obtener usuario por campo |
| `core_user_create_users` | Crear usuarios |
| `core_user_update_users` | Actualizar usuarios |
| `core_user_delete_users` | Eliminar usuarios |

#### Gestión de cohorts
| Función | Descripción |
|---------|-------------|
| `core_cohort_get_cohorts` | Obtener cohorts |
| `core_cohort_search_cohorts` | Buscar cohorts |
| `core_cohort_create_cohorts` | Crear cohorts |
| `core_cohort_update_cohorts` | Actualizar cohorts |
| `core_cohort_delete_cohorts` | Eliminar cohorts |
| `core_cohort_add_cohort_members` | Agregar miembros a cohort |
| `core_cohort_delete_cohort_members` | Eliminar miembros de cohort |
| `core_cohort_get_cohort_members` | Obtener miembros de cohort |

#### Gestión de cursos
| Función | Descripción |
|---------|-------------|
| `core_course_get_courses` | Obtener cursos |
| `core_course_get_courses_by_field` | Obtener curso por campo |
| `core_course_search_courses` | Buscar cursos |
| `core_course_create_courses` | Crear cursos |
| `core_course_update_courses` | Actualizar cursos |
| `core_course_delete_courses` | Eliminar cursos |
| `core_course_duplicate_course` | Duplicar curso |
| `core_course_get_contents` | Obtener contenido del curso |
| `core_course_get_categories` | Obtener categorías |
| `core_course_create_categories` | Crear categorías |
| `core_course_update_categories` | Actualizar categorías |
| `core_course_delete_categories` | Eliminar categorías |

#### Contenido del curso (actividades y secciones)
| Función | Descripción |
|---------|-------------|
| `core_course_get_course_module` | Detalle de un módulo por cmid |
| `core_course_get_course_module_by_instance` | Detalle de módulo por tipo+instancia |
| `core_course_get_course_content_items` | Tipos de actividad disponibles |
| `core_courseformat_update_course` | Acciones del editor de curso (mostrar/ocultar/mover/duplicar/eliminar módulos y secciones, indentación, modo de grupo) |
| `core_course_delete_modules` | Eliminar módulos |

#### Matrículas
| Función | Descripción |
|---------|-------------|
| `enrol_manual_enrol_users` | Matricular usuarios manualmente |
| `enrol_manual_unenrol_users` | Desmatricular usuarios |
| `core_enrol_get_enrolled_users` | Obtener matriculados en un curso |
| `core_enrol_get_users_courses` | Obtener cursos de un usuario |
| `core_enrol_get_course_enrolment_methods` | Métodos de matrícula de un curso |
| `core_enrol_get_potential_users` | Usuarios potenciales para matricular |
| `core_enrol_search_users` | Buscar usuarios para matrícula |
| `enrol_self_enrol_user` | Auto-matrícula |
| `enrol_self_get_instance_info` | Info de auto-matrícula |
| `enrol_meta_add_instances` | Agregar meta-matrículas |
| `enrol_meta_delete_instances` | Eliminar meta-matrículas |

#### Roles
| Función | Descripción |
|---------|-------------|
| `core_role_assign_roles` | Asignar roles |
| `core_role_unassign_roles` | Desasignar roles |

### Paso 6: Crear el token

1. Vaya a **Administración del sitio > Servidor > Servicios web > Gestionar tokens**
2. Haga clic en **Crear token**
3. Seleccione el **usuario** de servicio (ej: `ws_facturascripts`)
4. Seleccione el **servicio**: `FacturaScripts Integration`
5. Guarde y copie el token generado

### Paso 7: Configurar en FacturaScripts

1. En FacturaScripts, vaya a **Gestión Moodle > Instancias Moodle**
2. Haga clic en **+ Nuevo**
3. Complete:
   - **Nombre del sitio**: Nombre descriptivo
   - **URL**: URL completa de su Moodle (ej: `https://miacademia.com/moodle`)
   - **Token**: Pegue el token generado en el Paso 6
4. Guarde y haga clic en **Probar Conexión** para verificar

## Idiomas soportados

Español (ES, AR, CL, CO, CR, DO, EC, GT, MX, PA, PE, UY), Inglés, Francés, Portugués (PT, BR), Italiano, Alemán, Catalán, Gallego, Euskera, Valenciano, Polaco y Checo.

## Desarrollo a medida

Si necesita una integración personalizada con su plataforma LMS o funcionalidades adicionales para su organización, no dude en contactarnos:

- **Email**: dfelipe.monroyc@gmail.com
- **Web**: [diegomonroydev.com](https://diegomonroydev.com)

## Licencia

LGPL v3 - GNU Lesser General Public License

---

# ENGLISH / INGLÉS

---

# MoodleManagement v1.0

FacturaScripts plugin for managing Moodle platforms directly from your ERP. Connect your billing system with your LMS through the Moodle REST API.

*[Versión en español arriba](#moodlemanagement-v10)*

## Features

### Moodle Instance Management
- Connect to multiple Moodle instances simultaneously
- Connection testing and status monitoring (active, maintenance, inactive)
- Site information display (version, release, available WS functions)
- Environment support: production, staging, development

### User Synchronization
- Bulk user import from Moodle to FacturaScripts
- Bidirectional mapping Moodle user ↔ FacturaScripts contact
- Automatic client/contact creation on import
- Individual and bulk synchronization

### Course Management
- Course import and synchronization from Moodle
- Link Moodle courses with FacturaScripts products
- Automatic product and family creation per course
- Course duplication directly from FS
- Course category management (import, create, sync)
- Cover image synchronization between product and course

### Course Content (Activities and Sections)
- Real-time course content display via API
- Section management: create, show/hide, move, delete
- Activity management: show/hide, duplicate, move between sections, delete
- Stealth mode (link only) for activities
- Activity indentation (right/left)
- Group mode switching (no groups, separate groups, visible groups)
- Bulk actions with multiple selection
- Module detail modal with full information

### Enrolments
- Enrolment management by course and instance
- Method support: manual, self-enrolment, payment, cohort, meta-link
- Enrolment statuses: pending, enrolled, suspended, unenrolled
- Link to commercial documents (invoices, orders, estimates)
- Meta-enrolments between courses

### Cohorts
- Cohort import from Moodle
- Member synchronization
- Membership management (add/remove members)

### Roles
- Mapping of the 8 standard Moodle roles per instance
- Automatic standard role import

### Process Automation
- **Automatic enrolment**: when an invoice with course-linked products is paid, the student is automatically enrolled in Moodle
- **Pre-enrolment**: when creating quotes or orders, pending enrolments are generated and activated upon invoicing
- **Contact synchronization**: when a contact is modified in FS, data is automatically synced to Moodle
- **Automatic suspension**: when a contact is deleted in FS, their Moodle account and enrolments are suspended
- **Health check** (hourly): automatic monitoring of all Moodle instance statuses
- **Incremental sync** (every 6 hours): user and course synchronization with conflict resolution
- **Reconciliation** (daily): integrity verification of user and enrolment mappings against Moodle
- **Cleanup** (daily): automatic removal of orphaned mappings (deleted contacts)
- **Expiry control** (every 6 hours): detection of expiring enrolments (7 days) and automatic expiration

## Requirements

- FacturaScripts >= 2025.6
- Moodle >= 4.0 (4.5+ recommended)
- PHP >= 8.0 with cURL extension

## Installation

1. Copy the `MoodleManagement` folder into the FacturaScripts `Plugins` directory
2. Enable the plugin from **Admin > Plugins**
3. Configure at least one Moodle instance from **Moodle Management > Moodle Instances**

## Moodle Web Service Configuration

For the plugin to work correctly, you must create a web service in Moodle with the required functions and generate an access token.

### Step 1: Enable Web Services in Moodle

1. Go to **Site administration > General > Advanced features**
2. Check **Enable web services** (`enablewebservices`)
3. Save changes

### Step 2: Enable the REST protocol

1. Go to **Site administration > Server > Web services > Manage protocols**
2. Enable the **REST** protocol

### Step 3: Create a service user (recommended)

1. Go to **Site administration > Users > Accounts > Add a new user**
2. Create a dedicated user (e.g., `ws_facturascripts`)
3. Assign the **Manager** role at system level:
   - **Site administration > Users > Permissions > Assign system roles**
   - Select **Manager** and add the created user

### Step 4: Create the external web service

1. Go to **Site administration > Server > Web services > External services**
2. Click **Add**
3. Configure:
   - **Name**: `FacturaScripts Integration`
   - **Short name**: `facturascripts`
   - **Enabled**: Yes
   - **Authorised users only**: Yes
4. Save
5. In the service list, click **Authorised users** and add the service user created in Step 3

### Step 5: Add WS functions to the service

1. In the service list, click **Functions** next to the `FacturaScripts Integration` service
2. Add the following functions:

#### Essential functions (connection and info)
| Function | Description |
|----------|-------------|
| `core_webservice_get_site_info` | Connection test and site information |

#### User management
| Function | Description |
|----------|-------------|
| `core_user_get_users` | Search users |
| `core_user_get_users_by_field` | Get user by field |
| `core_user_create_users` | Create users |
| `core_user_update_users` | Update users |
| `core_user_delete_users` | Delete users |

#### Cohort management
| Function | Description |
|----------|-------------|
| `core_cohort_get_cohorts` | Get cohorts |
| `core_cohort_search_cohorts` | Search cohorts |
| `core_cohort_create_cohorts` | Create cohorts |
| `core_cohort_update_cohorts` | Update cohorts |
| `core_cohort_delete_cohorts` | Delete cohorts |
| `core_cohort_add_cohort_members` | Add cohort members |
| `core_cohort_delete_cohort_members` | Remove cohort members |
| `core_cohort_get_cohort_members` | Get cohort members |

#### Course management
| Function | Description |
|----------|-------------|
| `core_course_get_courses` | Get courses |
| `core_course_get_courses_by_field` | Get course by field |
| `core_course_search_courses` | Search courses |
| `core_course_create_courses` | Create courses |
| `core_course_update_courses` | Update courses |
| `core_course_delete_courses` | Delete courses |
| `core_course_duplicate_course` | Duplicate course |
| `core_course_get_contents` | Get course content |
| `core_course_get_categories` | Get categories |
| `core_course_create_categories` | Create categories |
| `core_course_update_categories` | Update categories |
| `core_course_delete_categories` | Delete categories |

#### Course content (activities and sections)
| Function | Description |
|----------|-------------|
| `core_course_get_course_module` | Module detail by cmid |
| `core_course_get_course_module_by_instance` | Module detail by type+instance |
| `core_course_get_course_content_items` | Available activity types |
| `core_courseformat_update_course` | Course editor actions (show/hide/move/duplicate/delete modules and sections, indentation, group mode) |
| `core_course_delete_modules` | Delete modules |

#### Enrolments
| Function | Description |
|----------|-------------|
| `enrol_manual_enrol_users` | Manually enrol users |
| `enrol_manual_unenrol_users` | Unenrol users |
| `core_enrol_get_enrolled_users` | Get enrolled users in a course |
| `core_enrol_get_users_courses` | Get a user's courses |
| `core_enrol_get_course_enrolment_methods` | Course enrolment methods |
| `core_enrol_get_potential_users` | Potential users for enrolment |
| `core_enrol_search_users` | Search users for enrolment |
| `enrol_self_enrol_user` | Self-enrolment |
| `enrol_self_get_instance_info` | Self-enrolment info |
| `enrol_meta_add_instances` | Add meta-enrolments |
| `enrol_meta_delete_instances` | Remove meta-enrolments |

#### Roles
| Function | Description |
|----------|-------------|
| `core_role_assign_roles` | Assign roles |
| `core_role_unassign_roles` | Unassign roles |

### Step 6: Create the token

1. Go to **Site administration > Server > Web services > Manage tokens**
2. Click **Create token**
3. Select the **service user** (e.g., `ws_facturascripts`)
4. Select the **service**: `FacturaScripts Integration`
5. Save and copy the generated token

### Step 7: Configure in FacturaScripts

1. In FacturaScripts, go to **Moodle Management > Moodle Instances**
2. Click **+ New**
3. Fill in:
   - **Site name**: A descriptive name
   - **URL**: Full Moodle URL (e.g., `https://myacademy.com/moodle`)
   - **Token**: Paste the token generated in Step 6
4. Save and click **Test Connection** to verify

## Supported Languages

Spanish (ES, AR, CL, CO, CR, DO, EC, GT, MX, PA, PE, UY), English, French, Portuguese (PT, BR), Italian, German, Catalan, Galician, Basque, Valencian, Polish, and Czech.

## Custom Development

If you need a custom integration with your LMS platform or additional features for your organization, feel free to contact us:

- **Email**: dfelipe.monroyc@gmail.com
- **Web**: [diegomonroydev.com](https://diegomonroydev.com)

## License

LGPL v3 - GNU Lesser General Public License
