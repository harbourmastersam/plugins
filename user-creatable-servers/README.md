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

## Optional User Attribute Mapper integration

When User Attribute Mapper is installed, this plugin advertises its existing CPU,
memory, disk, and server-limit fields as identity-writable attributes. The adapter
is loaded only after the mapper registry is detected and writes the authoritative
`user_resource_limits` row. User Creatable Servers remains fully functional when
the mapper is absent and contains no OAuth provider or claim parsing logic.

Configure provider selection and claim paths in User Attribute Mapper. For example,
map `pelican_limits.cpu` to `user-creatable-servers.cpu`.
