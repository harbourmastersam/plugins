# Release source inspection

All Panel paths below are relative to pelican/panel tag `v1.0.0-beta38`, not unreleased main. The original clone used the former `pelican-dev/panel` URL, which GitHub redirects to `pelican/panel`. No live installation was inspected.

| Concern | Source and finding |
| --- | --- |
| Versions | `composer.json` and `composer.lock`: PHP ^8.3 / ^8.4 / ^8.5; Laravel 13.25.0; Filament 5.7.6; Livewire 4.4.0 |
| Scaffold | `app/Console/Commands/Plugin/MakePluginCommand.php` plus `Plugin.stub`, `PluginProvider.stub`, `PluginConfig.stub`; executable local generation confirmed |
| Metadata | `app/Models/Plugin.php`: ID must match directory; namespace/class, panels, panel_version, category, version, optional composer_packages; no api_version handling |
| Autoload/lifecycle | `app/Services/Helpers/PluginService.php`: src PSR-4 mapping, config loading, provider discovery in src/Providers, views namespace, enabled-plugin checks; test mode skips plugins |
| Panel IDs | `app/Providers/Filament/{Admin,App,Server}PanelProvider.php`: admin/app/server; server path and uuid_short tenant |
| Existing UI | `app/Filament/Server/Resources/Databases/DatabaseResource.php` and `Pages/ListDatabases.php` |
| Supported extension | `app/Traits/Filament/CanModifyTable.php`: register closure to amend initialized table; Filament's pushRecordActions appends without replacing defaults |
| Model and relation | `app/Models/Database.php`: server_id, database_host_id, database, username, encrypted password; belongsTo host/server. `app/Models/Server.php`: databases hasMany |
| Access and subusers | `app/Models/User.php`: canAccessTenant; can override recognizes SubuserPermission and request-scoped permission cache; owners and permitted admins receive normal access |
| Policies | `app/Policies/DatabasePolicy.php`: viewAny/view check DatabaseRead on current tenant; `app/Policies/ServerPolicy.php`, `DefaultAdminPolicies.php`; root-admin Gate in AppServiceProvider |
| Exact permission | `app/Enums/SubuserPermission.php`: DatabaseRead = database.read; DatabaseViewPassword = database.view-password is separate, not required for this viewer |
| Credentials | `app/Models/Database.php`: password encrypted cast; hidden from serialization. DatabaseHost also has encrypted password, but that is the host-management account |
| Management | `app/Services/Databases/DatabaseManagementService.php`, DeployServerDatabaseService, Hosts services; create/rotate/delete workflows remain unchanged |
| Existing API | `app/Http/Controllers/Api/Client/Servers/DatabaseController.php`, client database request classes, `routes/api-client.php`; plugin uses session web routes for CSRF-protected browser calls |
| Middleware | `bootstrap/app.php` registers web CSRF replacement; `app/Http/Middleware/PreventRequestForgery.php` exempts daemon/remote paths only; RequireTwoFactorAuthentication reused |
| Connection | `DatabaseHost::buildConnection()` authenticates as host-management user; unsuitable for selected-database SQL. Laravel MySqlConnector reused with selected Database credentials instead |
| Testing | `tests/Integration/IntegrationTestCase.php`: PHPUnit-compatible tests, DatabaseTruncation; real app exception handler rolls transactions back on HTTP errors |

Official plugin examples cloned independently from `pelican/plugins` at `9f84586016945c50e0663e2ebeb02af0992243be`: Tickets for provider/plugin registration and Subdomains for configuration conventions. Some example manifests retain api_version; the selected Panel release does not use it.

Studio inspected locally in `harbourmastersam/studio`: `src/drivers/iframe-driver.ts`, `src/drivers/base-driver.ts`, `src/drivers/mysql/mysql-driver.ts`, `src/context/schema-provider.tsx`, and `docs/embedding.md`. Result fields and operation-specific envelopes match the runtime validator. The MySQL driver starts with one transaction containing six database-scoped metadata statements. `src/app/(theme)/embed/[driver]/page-client.tsx` now requires exactly one validated `database` parameter for ordinary MySQL embeds before constructing the driver. The diagnostic probe path remains separately available and does not initialize schema discovery.

Database Viewer 0.2.0 recognizes only that exact transaction shape. `SchemaBootstrapPolicy` validates the incoming operation, count, ordering, normalized SQL structure, selected database binding, and per-statement limits. `MariaDbExecutor` then executes its own fixed statements on one request-local connection; it never executes Studio's SQL text. `DatabaseResultSerializer` produces the six ordinary result envelopes Studio consumes. The browser bridge forwards the generic transaction without rewriting it and retains the hardened origin/window/channel/request checks.
