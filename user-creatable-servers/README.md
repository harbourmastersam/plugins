# User Creatable Servers (by Boy132)

Allow users to create their own servers within defined resource limits set by administrators.

## Setup

Add the deployment tag (`user_creatable_servers` by default) to the nodes that should be used for creating servers.

## Features

- Users can create servers without admin intervention
- Configurable resource limits per user (CPU, RAM, disk, etc.)
- Admin management of user resource allocations
- Resource usage overview widget for users
- Integration with existing server management

## OAuth/OIDC resource-limit synchronization

USC can synchronize limits from any OAuth/OIDC provider registered with Pelican's
OAuth service. This includes Pelican's built-in providers and providers registered
dynamically by another plugin. The separately installed Generic OIDC Providers
plugin is supported automatically; USC does not depend on it or replace its OIDC
implementation.

Enable synchronization and explicitly select the authoritative provider IDs:

```env
UCS_OAUTH_SYNC_ENABLED=true
UCS_OAUTH_SYNC_PROVIDERS=authentik,corporate_oidc
UCS_OAUTH_SYNC_CLAIM=pelican_limits
```

Provider IDs are comma-separated and surrounding whitespace is ignored. Use `*`
to select every provider currently registered with Pelican. The default claim is
`pelican_limits`; dot notation such as `entitlements.pelican_limits` is also
supported.

The provider's normal Socialite user must expose the claim in its raw user
attributes. USC observes those attributes without changing the OAuth user or
authentication flow. A typical claim is:

```json
{
  "pelican_limits": {
    "cpu": 400,
    "memory": 8192,
    "disk": 50000,
    "server_limit": 3
  }
}
```

Selection is authoritative: after a selected provider returns inspectable raw
attributes, a missing claim removes existing limits, as does an invalid claim.
If raw attributes cannot be inspected, login still succeeds and existing limits
are left unchanged. Logins through non-selected providers never alter limits.
Public providers such as GitHub and Steam generally do not return custom
application claims unless their response has been customized, so select them only
when that authoritative behavior is intended.

Existing installations remain compatible. When their corresponding new variable
is not set, `UCS_OIDC_SYNC_ENABLED`, `UCS_OIDC_SYNC_PROVIDER`, and
`UCS_OIDC_SYNC_CLAIM` remain fallbacks. The legacy default selects `authentik`.

### Manual verification matrix

1. **Authentik, valid claim:** select `authentik`, log in with the example claim,
   and verify OAuth succeeds and the limits are created or updated.
2. **Authentik, removed claim:** log in through selected Authentik with an
   inspectable response lacking the claim; verify login succeeds and existing
   limits are removed.
3. **Generic OIDC:** install and configure the Generic OIDC Providers plugin,
   select its ID (for example `corporate_oidc`), and verify its normal OIDC/JWT
   settings and login behavior are preserved while the returned claim is synced.
4. **Unrelated GitHub login:** with only `authentik,corporate_oidc` selected, log
   in through GitHub and verify existing limits are unchanged.
5. **All-provider mode:** set `UCS_OAUTH_SYNC_PROVIDERS=*` and verify each provider
   registered with Pelican is eligible without adding its ID to USC.
