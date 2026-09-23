# User Attribute Mapper - UCS Bridge

This headless Pelican plugin exposes resource limits from the official **User Creatable Servers** (UCS) plugin to **User Attribute Mapper**. Neither upstream plugin is modified: UCS remains the authoritative data owner, while this bridge supplies the definitions connecting their public behavior.

## Requirements

- User Attribute Mapper
- the official User Creatable Servers plugin

The bridge remains dormant if either integration is unavailable. Enable all three plugins to register:

| Target key | Label | Nullable | Clear supported |
| --- | --- | --- | --- |
| `user-creatable-servers.cpu` | CPU Limit | no | no |
| `user-creatable-servers.memory` | Memory Limit | no | no |
| `user-creatable-servers.disk` | Disk Limit | no | no |
| `user-creatable-servers.server_limit` | Server Limit | yes | yes |

All four are identity-writable integers with a minimum of zero. Values are read from and written directly to UCS's `user_resource_limits` row; the bridge creates no duplicate attribute storage.

## Mapping example

Map the IdP claim `pelican_limits.cpu` to `user-creatable-servers.cpu`. A complete claim payload can be:

```json
{
    "pelican_limits": {
        "cpu": 800,
        "memory": 16384,
        "disk": 100000,
        "server_limit": 5
    }
}
```

Create equivalent mappings for the other three paths. After authentication, UCS's authoritative `UserResourceLimits` row contains those limits.

## Compatibility and upgrades

The bridge targets the official Pelican UCS plugin and uses capability detection for its explicit reviewed set: `cpu`, `memory`, `disk`, and `server_limit`. It does not require one exact runtime version. It was developed against official UCS **1.1.1**.

New UCS fillable/model fields are **not** automatically exposed. New identity-writable fields require a bridge review and release. If a supported field disappears, the bridge omits it; its mapper row remains stored and becomes available again when the same stable key returns.

If official UCS later provides native mapper support with these same keys, update UCS, remove this bridge, and keep the existing mappings. Do not enable native and bridge registration simultaneously.

See [Integrating Plugins with User Attribute Mapper](../user-attribute-mapper/docs/PLUGIN-INTEGRATIONS.md) for the architecture, security rules, and contribution standard.
