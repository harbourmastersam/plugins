# User Attribute Mapper

User Attribute Mapper adds an Okta-style, provider-neutral profile mapping layer to Pelican. It captures raw attributes from the **existing** Socialite login, then maps a claim path to a stable attribute key registered at runtime by the attribute's owning plugin. The mapper contains no Authentik, Generic OIDC Providers, or User Creatable Servers storage logic.

## Installation and setup

Install it as a normal Pelican plugin and run Pelican's plugin migrations. In the admin panel open **Attribute Mappings**, create a mapping, and select:

1. a currently registered and enabled provider;
2. a source type and either a dot-separated claim path such as `pelican_limits.cpu` or a static text value;
3. a currently registered, identity-writable target;
4. `preserve` (the safe default) or `clear` for a missing claim.

Mappings store provider and target **IDs**, never PHP callables/classes. Each mapping belongs to one identity provider because providers may expose different claim schemas. A missing provider or target does not delete the row, and new mappings can only select enabled providers and targets registered during the current process.

Legacy rows whose provider is `*` remain in the database until an administrator explicitly removes them from the mapping page. They are not shown as a provider choice and are not executed during login; the editor never copies or silently reinterprets them.

## Claim and type semantics

Nested arrays/objects use literal dot-separated paths. Absent, `null`, `false`, `0`, and `""` are distinct. An absent claim preserves the existing value by default. `clear` only works when the owner supplied a clearer; otherwise it also preserves the value.

Supported types are string, integer, float, boolean, array, and object. Conversion is deliberately strict: `"800"` is an integer and `"true"`/`"false"` are booleans, while `"hello"` cannot become integer `0`. The owner supplies Laravel validation rules after conversion. Invalid input, unavailable adapters, and writer failures are logged without values and do not block login.

## Mapping sources

Every mapping explicitly uses one of two source types:

- **Identity Provider Claim** reads a claim or dot-separated path from the authenticating provider.
- **Static Value** supplies the same configured text on every successful login through the mapping's selected provider. The target type still controls conversion, validation, and writing.

Sources remain provider-scoped. For example, `Static true` configured for **Staff** runs only for a Staff login; it does not run for **Port** or any other provider. Claim mappings such as `preferred_username` → `pelican.username`, `email` → `pelican.email`, and `sub` → `pelican.external_id` continue to work unchanged.

### Marking an IdP-managed user as externally managed

For an Authentik provider, configure `Static Value` with source value `true` and target `pelican.is_managed_externally`. At login, the existing `AttributeValueConverter` converts the text `"true"` to boolean `true` before target validation and writing. Authentik does not need to emit a redundant `pelican_managed` claim.

Static values are stored as mapper configuration in the database and are visible to administrators. **Do not use them for passwords, tokens, API secrets, private keys, credentials, or other secrets.** The target registry remains the security boundary: static sources cannot bypass target registration, `writableFromIdentity`, target types, validation, privileged/sensitive metadata, or writer callbacks.

## Security model

The runtime registry is the security boundary. Administrators cannot enter model classes, columns, methods, or expressions. Attributes default to not identity-writable; their owner must provide a writer and opt in. `sensitive` and `privileged` metadata is available to UIs, and raw values, tokens, authorization codes, refresh tokens, and secrets are never logged or persisted. The mapper neither decodes tokens nor performs a second user-info/token request.

The mapper itself explicitly registers the safe Pelican profile fields `username`,
`email`, `external_id`, `language`, `timezone`, and `is_managed_externally` as
identity-writable. User `id` and `uuid` are available read-only. This is an
allowlist: authentication secrets, administrator state, roles, permissions, and
arbitrary model columns are never exposed.

External ID does not automatically imply that a user is externally managed. To
enable that Pelican behavior, an administrator must explicitly map a boolean
source claim to `pelican.is_managed_externally`. For example, an identity provider
might supply:

```json
{
    "preferred_username": "sam@greyharbour.net",
    "email": "sam@greyharbour.net",
    "sub": "ffa82db64a2e474addde9d6d8c021a56deb55015399eff99c982f6",
    "pelican_managed": true
}
```

The corresponding mappings can be `preferred_username` → `pelican.username`,
`email` → `pelican.email`, `sub` → `pelican.external_id`, and `pelican_managed` →
`pelican.is_managed_externally`. The `pelican_managed` claim name is only an
example; the implementation is provider-neutral. A present `false` value
explicitly disables externally managed status, while an absent claim follows the
mapping's configured missing-claim behavior.

## OAuth compatibility and internals

`CaptureOAuthClaims` runs only on Pelican's `auth.oauth.callback` route. `OAuthProviderResolver` is the sole adapter to Pelican's internal `App\Extensions\OAuth\OAuthService`. At callback time it resolves the provider that Pelican/Socialite already configured, decorates that exact instance, calls `user()` exactly once, captures `getRaw()` when available, and returns the same Socialite user. Mapping occurs only on Laravel's successful `Login` event.

Generic OIDC Providers registers its dynamic schemas with that same OAuth service, so it needs no special integration and newly created providers work by ID. If Pelican changes its callback route, OAuth registry, Socialite manager caching, or login event, update only the middleware/resolver integration. Providers that do not expose raw attributes are safely skipped.

## Plugin integrations

**Plugin authors and bridge maintainers:** read [Integrating Plugins with User Attribute Mapper](docs/PLUGIN-INTEGRATIONS.md). It defines the supported native-first architecture, standalone bridge fallback, stable-key and storage-ownership contracts, optional dependency pattern, security allowlisting, compatibility policy, and testing requirements. Do not add plugin-specific compatibility code to mapper core.

Official User Creatable Servers remains unchanged and does not advertise mapper attributes. To expose its resource limits, install and enable the separate `user-attribute-mapper-ucs` bridge alongside both plugins. The bridge supplies `user-creatable-servers.cpu`, `.memory`, `.disk`, and `.server_limit` while UCS retains authoritative storage.

### Verify the installed UCS bridge runtime

After enabling User Attribute Mapper, official UCS, and the UCS bridge, run:

```console
php artisan optimize:clear
php artisan p:user-attribute-mapper:inspect --require-ucs
```

`--require-ucs` validates the combined runtime integration—mapper plus bridge plus official UCS—not native registration by UCS. It requires the six writable Pelican attributes and four bridge-provided UCS attributes (ten writable definitions total). Use `php artisan plugin:list`, where available, to independently confirm all three plugins are enabled and loadable. Restart long-lived workers/PHP-FPM after replacing installed plugin files; `optimize:clear` does not restart them.

## Troubleshooting and manual regression test

1. Verify the provider is enabled in Pelican and the mapping provider ID matches exactly.
2. Verify the target is not labelled unavailable and the mapping is enabled.
3. Sign in through the provider; do not test by calling the callback without a completed Socialite flow.
4. Inspect application logs for mapping ID, user ID, provider, source path, target key, and result. Values are intentionally omitted.
5. For UCS, verify `UserResourceLimits::where('user_id', $id)->first()` contains `800`, `16384`, `100000`, and `5`. This proves callback capture → successful login → mapper → owner adapter → database write, rather than configuration alone.

Capture unavailable means the Socialite user did not safely expose raw attributes; the mapper will not fetch again or clear anything. Mapping errors are isolated so authentication continues.
