# User Attribute Mapper

User Attribute Mapper adds an Okta-style, provider-neutral profile mapping layer to Pelican. It captures raw attributes from the **existing** Socialite login, then maps a claim path to a stable attribute key registered at runtime by the attribute's owning plugin. The mapper contains no Authentik, Generic OIDC Providers, or User Creatable Servers storage logic.

## Installation and setup

Install it as a normal Pelican plugin and run Pelican's plugin migrations. In the admin panel open **Attribute Mappings**, create a mapping, and select:

1. a currently registered provider or `*`;
2. a dot-separated source path such as `pelican_limits.cpu`;
3. a currently registered, identity-writable target;
4. `preserve` (the safe default) or `clear` for a missing claim.

Mappings store provider and target **IDs**, never PHP callables/classes. A missing provider or target does not delete the row: it is displayed as unavailable and skipped until it returns. New mappings can only select targets registered during the current process.

## Claim and type semantics

Nested arrays/objects use literal dot-separated paths. Absent, `null`, `false`, `0`, and `""` are distinct. An absent claim preserves the existing value by default. `clear` only works when the owner supplied a clearer; otherwise it also preserves the value.

Supported types are string, integer, float, boolean, array, and object. Conversion is deliberately strict: `"800"` is an integer and `"true"`/`"false"` are booleans, while `"hello"` cannot become integer `0`. The owner supplies Laravel validation rules after conversion. Invalid input, unavailable adapters, and writer failures are logged without values and do not block login.

## Security model

The runtime registry is the security boundary. Administrators cannot enter model classes, columns, methods, or expressions. Attributes default to not identity-writable; their owner must provide a writer and opt in. `sensitive` and `privileged` metadata is available to UIs, and raw values, tokens, authorization codes, refresh tokens, and secrets are never logged or persisted. The mapper neither decodes tokens nor performs a second user-info/token request.

The mapper itself explicitly registers the safe Pelican profile fields `username`,
`email`, `external_id`, `language`, and `timezone` as identity-writable. User `id`
and `uuid` are available read-only. This is an allowlist: authentication secrets,
administrator state, roles, permissions, and arbitrary model columns are never
exposed.

## OAuth compatibility and internals

`CaptureOAuthClaims` runs only on Pelican's `auth.oauth.callback` route. `OAuthProviderResolver` is the sole adapter to Pelican's internal `App\Extensions\OAuth\OAuthService`. At callback time it resolves the provider that Pelican/Socialite already configured, decorates that exact instance, calls `user()` exactly once, captures `getRaw()` when available, and returns the same Socialite user. Mapping occurs only on Laravel's successful `Login` event.

Generic OIDC Providers registers its dynamic schemas with that same OAuth service, so it needs no special integration and newly created providers work by ID. If Pelican changes its callback route, OAuth registry, Socialite manager caching, or login event, update only the middleware/resolver integration. Providers that do not expose raw attributes are safely skipped.

## Registering an attribute from another plugin

Pelican discovers the service providers under each enabled plugin's `src/Providers` directory. Their `register()` methods run before provider `boot()` methods; Laravel's application `booted` callbacks then run after every provider has booted. User Attribute Mapper owns attribute discovery at that final point: it dispatches `Boy132\UserAttributeMapper\Events\RegisterUserAttributes` once with its singleton registry.

### Verify the installed runtime

Run the inspection command in the installed Pelican directory after enabling both
plugins. Unlike a unit test, this resolves the registry from the running
application container and therefore inspects the installed plugin copies and the
listeners that Pelican actually loaded:

```console
php artisan p:user-attribute-mapper:inspect --require-ucs
```

The command exits unsuccessfully unless the five writable Pelican attributes and
all four writable User Creatable Servers attributes are present. It also prints
the registry object ID and the number of listeners installed for the extension
event. Use `php artisan plugin:list` (or inspect Pelican's Plugin model on releases
without that command) to independently confirm that both plugins are enabled and
loadable.

After replacing an installed plugin copy, clear Laravel's generated caches with
`php artisan optimize:clear`. Restart any long-lived Octane workers and the PHP-FPM
service used by the Panel so that OPcache and persistent processes cannot retain
the previous provider code. The exact service name is distribution-specific; do
not assume that running `optimize:clear` restarts PHP workers.

Keep integration optional: do not add a Composer dependency. Subscribe by string
during the owning plugin provider's `register()` method. Registering the event name
does not autoload mapper code, and the integration closure remains dormant when the
mapper is disabled. Register-phase subscription is important: it guarantees that
the listener exists before the mapper's end-of-bootstrap dispatch regardless of
plugin order.

```php
$eventClass = 'Boy132\\UserAttributeMapper\\Events\\RegisterUserAttributes';
$this->app['events']->listen($eventClass, function (object $event): void {
    // Resolve the mapper-specific class only if the event is actually dispatched.
    (new MyPluginUserAttributeProvider())->register($event->registry);
});
```

The lazily loaded provider may register `user_settings.max_widgets` as follows:

```php
$registry->register(new UserAttributeDefinition(
    key: 'my-plugin.max_widgets',
    owner: 'my-plugin',
    label: 'Maximum Widgets',
    type: AttributeType::Integer,
    reader: fn (User $user) => UserSetting::whereBelongsTo($user)->value('max_widgets'),
    writer: fn (User $user, int $value) => UserSetting::updateOrCreate(
        ['user_id' => $user->id], ['max_widgets' => $value],
    ),
    group: 'My Plugin',
    writableFromIdentity: true,
    rules: ['required', 'integer', 'min:0', 'max:100'],
));
```

An administrator can then map `entitlements.max_widgets` to `my-plugin.max_widgets`. The reader/writer always use My Plugin's authoritative storage. If My Plugin is disabled or removed, the mapping remains unavailable; reinstalling and registering the same key reactivates it automatically.

## User Creatable Servers / Authentik example

User Creatable Servers optionally advertises four adapters backed by its existing `user_resource_limits` row: `user-creatable-servers.cpu`, `.memory`, `.disk`, and `.server_limit`. It remains fully usable without this mapper.

Given raw claims:

```json
{"pelican_limits":{"cpu":800,"memory":16384,"disk":100000,"server_limit":5}}
```

create four mappings from each `pelican_limits.*` path to its corresponding UCS key. The provider ID may be any Pelican-native or Generic OIDC provider (including an Authentik-backed provider); no provider name is hard-coded. After the callback and successful Login event, the UCS adapter creates/updates the authoritative row.

## Troubleshooting and manual regression test

1. Verify the provider is enabled in Pelican and the mapping provider ID matches (or use `*`).
2. Verify the target is not labelled unavailable and the mapping is enabled.
3. Sign in through the provider; do not test by calling the callback without a completed Socialite flow.
4. Inspect application logs for mapping ID, user ID, provider, source path, target key, and result. Values are intentionally omitted.
5. For UCS, verify `UserResourceLimits::where('user_id', $id)->first()` contains `800`, `16384`, `100000`, and `5`. This proves callback capture → successful login → mapper → owner adapter → database write, rather than configuration alone.

Capture unavailable means the Socialite user did not safely expose raw attributes; the mapper will not fetch again or clear anything. Mapping errors are isolated so authentication continues.
