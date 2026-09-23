# Pelican Plugins

A curated list of plugins for the [Pelican Panel](https://pelican.dev).

## How to install plugins

It is highly recommended to install plugins via the [Hub](https://hub.pelican.dev/plugins/author/boy132). Simply login with Discord, connect your panel and install any plugin with a click!

### Additional setup for themes

For themes you have to install Node.js 22+. You also need Yarn, either installed directly or provided via Corepack. Example:

```bash
curl -sL https://deb.nodesource.com/setup_22.x | sudo -E bash - 
sudo apt install -y nodejs

// Yarn via corepack (recommended)
sudo corepack enable

// Install yarn globally
npm i -g yarn
```

## Plugins

- [Announcements](/announcements) - Create panel wide announcements to inform your users
- [Billing](/billing) - Allows users to purchase servers via Stripe - **Proof of Concept - Do absolutely NOT use in production!**
- [Generic OIDC Providers](/generic-oidc-providers) - Create generic OIDC providers for authentication
- [Legal Pages](/legal-pages) - Adds legal pages (Imprint, Privacy Policy, ToS) to the panel
- [MCLogs Uploader](/mclogs-uploader) - Upload console logs to mclo.gs
- [Minecraft Modrinth](/minecraft-modrinth) - Download Minecraft mods & plugins from Modrinth
- [Player Counter](/player-counter) - Show connected players count for game servers
- [Register](/register) - Enable user self-registration on all panels
- [Robo Avatars](/robo-avatars) - Adds RoboHash as avatar provider
- [Rust uMod](/rust-umod) - Download Rust plugins from uMod
- [Snowflakes](/snowflakes) - Adds CSS snowflakes to all panels
- [Subdomains](/subdomains) - Create Cloudflare subdomains for servers
- [Tawk.to Widget](/tawkto-widget) - Adds a Tawk.to live chat widget
- [Theme Customizer](/theme-customizer) - Customize panel font and colors
- [Tickets](/tickets) - Simple ticket system for user support
- [User Attribute Mapper](/user-attribute-mapper) - Map OAuth/OIDC claims to explicitly registered user attributes
- [User Attribute Mapper - UCS Bridge](/user-attribute-mapper-ucs) - Expose official UCS limits to User Attribute Mapper
- [User Creatable Servers](/user-creatable-servers) - Allow users to create their own servers

## Themes

- [Fluffy Theme](/fluffy-theme) - A super nice and super fluffy theme
- [Neobrutalism Theme](/neobrutalism-theme) - Transform your panel with thick borders, pronounced shadows, and geometric aesthetics inspired by the neobrutalism design movement
- [Nord Theme](/nord-theme) - A light and dark arctic Nord theme
- [Pterodactyl Theme](/pterodactyl-theme) - Pterodactyl like colors and font

## Language Packs

- [Pirate Language](/pirate-language) - Turns yer site's lingo into pirate talk, matey!
