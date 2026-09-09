# Player Counter (by Boy132)

Show the amount of connected players to game servers with real-time querying capabilities.

## Setup

Make sure your server has an allocation with a public ip. Alternatively, if you use local ips you can put the public ip in the allocation alias and enable "Use allocation alias?" in the plugin settings. Using a domain as allocation alias or `0.0.0.0`/`::` as allocation ip will not work!

For each game you need to create a Game Query in the admin area.

### Minecraft

Minecraft servers will first try the query (which requires you to set `enable-query` to true and `query-port` to your server port in `server.properties`) and will fallback to ping. It is recommended to enable query.

### Palworld

For Palworld servers you need to set `RESTAPIEnabled` to `true` and `RESTAPIPort` to your server port in `PalWorldSettings.ini`. You also need to set an admin password via the `ADMIN_PASSWORD` startup variable.

## Features

- Real-time player count display for game servers
- Support for multiple game query protocols
- Link query protocols to specific eggs
- Dashboard widget showing connected players
- Dedicated players page for detailed information
- Configurable through the admin panel
- Advanced integration for Minecraft servers: Displays user helmet avatar and allows to manage whitelist & op list.

### Supported Games

- Minecraft (Java/Bedrock)
- FiveM/RedM
- Palworld
- Any game server that uses [Valve's A2S query protocol](https://developer.valvesoftware.com/wiki/Server_queries), e.g. Garry's Mod, Rust, Barotrauma, Valheim, V Rising, The Forest, Arma 3, Arma Reforger, ARK: SE (ARK: SA will _NOT_ work), Unturned, Insurgency, Insurgency: Sandstorm + many more.
- Hytale, using the [source query hytale plugin](https://www.curseforge.com/hytale/mods/source-query-a2s) (_without the plugin it will NOT work, other query plugins will also not work_)
