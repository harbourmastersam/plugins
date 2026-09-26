# Integrating Plugins with User Attribute Mapper

This document defines the supported integration contract for plugins that expose user attributes to User Attribute Mapper.

## Architecture and ownership policy

```text
                         User Attribute Mapper
                                  |
                    RegisterUserAttributes event
                                  |
                +-----------------+-----------------+
                |                                   |
                v                                   v
       Native plugin support                  Bridge plugin
       maintained upstream                    maintained separately
                |                                   |
                v                                   v
       Plugin-owned storage                  Third-party plugin's
                                             authoritative storage
```

**Native support maintained by the plugin author is preferred.** The owner understands its schema, validation, security boundary, and release lifecycle. It can change the integration with the storage schema without requiring the mapper maintainer to track every plugin version. Server Lifecycle, which this repository controls, is an example of the native model.

A **standalone bridge is the fallback** when an upstream plugin does not support the mapper. Name it `user-attribute-mapper-<plugin-id>`. The bridge is the only component that knows both APIs. Official User Creatable Servers is not modified; `user-attribute-mapper-ucs` adapts it instead.

Never put plugin-specific compatibility code in mapper core (for example, `user-attribute-mapper/Integrations/UCS`). The mapper owns registration and mapping configuration, the owner plugin owns its data, and the integration owns only definitions and compatibility logic.

## Stable attribute names are a persistent contract

`AttributeMapping.target_attribute` stores the registered key. Use the data owner's namespace—not a bridge's namespace. For example, the UCS bridge registers:

- `user-creatable-servers.cpu`
- `user-creatable-servers.memory`
- `user-creatable-servers.disk`
- `user-creatable-servers.server_limit`

It must not register `user-attribute-mapper-ucs.cpu`. Their owner is `user-creatable-servers` and their group is `User Creatable Servers` because UCS owns the data.

Do not casually rename a key such as `my-plugin.some_attribute`. When a key disappears its existing mapping remains stored but unavailable. Registering that same key again makes the mapping available automatically. This stability also permits migration from a bridge to native support without recreating mappings.

## Native integration (preferred)

A plugin author should keep optional support with the owning plugin:

```text
my-plugin/
    src/
        Providers/MyPluginProvider.php
        Integrations/UserAttributeMapper/MyPluginAttributeProvider.php
```

Subscribe during the service provider's `register()` phase, before the mapper's end-of-bootstrap registration dispatch. Use strings and resolve the mapper-specific class inside the listener:

```php
$eventClass = 'HarbourmasterSam\\UserAttributeMapper\\Events\\RegisterUserAttributes';

$this->app['events']->listen($eventClass, function (object $event): void {
    $provider = 'Vendor\\MyPlugin\\Integrations\\UserAttributeMapper\\MyPluginAttributeProvider';
    (new $provider())->register($event->registry);
});
```

Registering an event string does not autoload mapper classes. The closure remains dormant without the mapper, and lazy provider resolution prevents optional mapper imports from breaking the owner plugin. Register-phase subscription also makes the mapper's existing end-of-bootstrap lifecycle independent of provider order.

A concise, deliberately reviewed definition might be:

```php
$registry->register(new UserAttributeDefinition(
    key: 'my-plugin.max_widgets',
    owner: 'my-plugin',
    label: 'Maximum Widgets',
    type: AttributeType::Integer,
    reader: fn (User $user) => UserSetting::where('user_id', $user->id)->value('max_widgets'),
    writer: fn (User $user, int $value) => UserSetting::updateOrCreate(
        ['user_id' => $user->id], ['max_widgets' => $value],
    ),
    group: 'My Plugin',
    writableFromIdentity: true,
    rules: ['required', 'integer', 'min:0'],
));
```

The plugin's existing `UserSetting` storage remains authoritative; the mapper stores only mapping configuration.

## Bridge integrations

A bridge follows the same registration lifecycle but must protect both optional boundaries:

1. subscribe by the mapper event's string name during `register()`;
2. wait until that event is dispatched;
3. verify the target model/capability exists and the target Pelican plugin is enabled, so stale installed files are insufficient;
4. only then lazily resolve the provider that imports both plugins' classes;
5. register an explicit, reviewed allowlist.

A bridge must remain dormant, without crashing Pelican, when the mapper, target plugin, or both are absent. Do not add a hard Composer dependency merely to implement an optional integration.

## Security requirements and explicit allowlists

Identity-provider writes cross a security boundary. Mass assignability is **not** an approval to expose a field. Never do this:

```php
foreach ($model->getFillable() as $field) {
    registerEverything($field);
}
```

For every exposed attribute, deliberately review and specify its stable key, owner, label, type, nullability, `writableFromIdentity`, reader, writer, optional clearer, rules, description, and group. Sensitive or privileged fields require a deliberate security design before they can be identity writable.

Capability detection may filter an explicit allowlist; it must not generate the allowlist. For example:

```php
$supported = ['cpu', 'memory', 'disk', 'server_limit'];
$available = (new UserResourceLimits())->getFillable();

foreach ($supported as $field) {
    if (!in_array($field, $available, true)) {
        continue;
    }
    registerExplicitDefinition($field);
}
```

If a later version adds `backup_limit`, it is not exposed until a bridge change reviews it.

## Types, validation, and clearing

The mapper supports `string`, `integer`, `float`, `boolean`, `array`, and `object`. Supply strict Laravel rules such as `['required', 'integer', 'min:0']` or, where null is valid, `['nullable', 'integer', 'min:0']`. Do not defer validation to database errors.

`missing_claim_behavior = preserve` does nothing when the source claim is absent. `missing_claim_behavior = clear` invokes the definition's clearer only when one exists. Supply a clearer only when removal or reset is semantically valid and supported by storage. A non-nullable database field should not receive a generic clearer.

## Storage ownership

Neither mapper nor bridge may duplicate owner data. A definition's reader, writer, and clearer operate on the owner plugin's authoritative storage. The mapper persists only mapping configuration.

For UCS, storage remains `Boy132\\UserCreatableServers\\Models\\UserResourceLimits` in `user_resource_limits`. The bridge supplies metadata, type and validation, plus closures that access that row. Creating a missing row initializes required UCS columns safely; updating one attribute must not overwrite unrelated values.

## When the upstream plugin changes

Prefer feature/capability detection over an exact runtime version lock:

- known fields remain supported while their compatibility assumptions hold;
- newly added fields are not automatically exposed;
- a renamed or removed field is not registered, rather than receiving an arbitrary write;
- its existing mappings remain stored and unavailable;
- support restored under the same stable key automatically recovers those mappings.

Review upstream model and migration changes on every bridge update. Explicitly review any proposed new identity-writable field, its semantics, defaults, validation, and clear behavior.

## Reference bridge: User Creatable Servers

[`user-attribute-mapper-ucs/`](../../user-attribute-mapper-ucs/) is the reference implementation for future bridge PRs. It demonstrates optional dependency handling, string-based event subscription, lazy provider loading, plugin availability checks, explicit allowlisting, authoritative plugin storage, stable plugin-owned keys, strict type validation, safe clear semantics, and upstream capability checks.

Its four reviewed definitions map directly to UCS storage. CPU, memory, and disk are non-nullable and cannot be cleared; nullable `server_limit` can be cleared. Official UCS and mapper core contain no knowledge of each other.

If official UCS later implements native support using the same keys, administrators should:

1. update UCS;
2. disable or remove `user-attribute-mapper-ucs` to avoid duplicate registration;
3. retain existing `AttributeMapping` rows.

The stable keys allow native support to pick up those mappings without recreation.

## Contribution workflow

1. **Preferred:** submit optional native mapper support to the plugin that owns the data.
2. If upstream declines native support, submit a standalone bridge here named `user-attribute-mapper-<plugin-id>` (for example, `user-attribute-mapper-ucs` or `user-attribute-mapper-some-plugin`).
3. Never submit plugin-specific compatibility code to `user-attribute-mapper` itself.

A bridge PR must document ownership, keys, supported upstream capabilities, storage behavior, and upgrade strategy. It must test exact registered keys and metadata; validation failures; reader, writer, missing-row defaults, preservation of unrelated fields, and valid clearing; absent/disabled optional dependencies where practical; and equivalent final registration across plugin load orders. It must also prove that an unreviewed future fillable field cannot become registered automatically.

## Idempotence and fallback chains

By default the mapper normalises the current reader value and incoming value to the declared type and skips an equal write. Writers with intentional side effects can set `compareBeforeWrite: false` on `UserAttributeDefinition`; that writer will always run. Clearers are likewise skipped when the reader already returns `null` under the default comparison policy.

Multiple mappings for one definition are a priority/ID ordered fallback chain. The first present candidate wins, and an invalid present candidate stops the chain. The primary candidate alone controls what happens after every claim candidate is absent. Preview uses the same resolution, fixed safe string transformations, conversion, and rules without invoking integration callbacks. Static configuration, transformation arguments, and preview values are never logged.
