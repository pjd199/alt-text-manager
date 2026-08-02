=== Alt Text Manager ===
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Find, audit, and AI-generate alt text for images used across your site, powered by the WordPress 7 AI Client.

== Description ==

Alt Text Manager gives you three things:

1. **Image Library** — every image in your Media Library with its current alt text, editable inline, and a note on whether it's actually used anywhere on the site.
2. **Missing Alt Text** — just the images that ARE used on the site (post/page content, featured images, SEO/social images, site logo & icon) but currently have no alt text, so you're not wasting effort on unused uploads.
3. **AI generation** — using the WordPress 7 AI Client (Settings → Connectors), generate alt text one image at a time or in a batch across everything that's missing it. New uploads can be handled automatically too.

== Requirements ==

* WordPress 7.0 or later (for the built-in AI Client). The plugin still works for manual auditing on earlier versions — AI features simply won't appear.
* At least one AI provider connected under Settings → Connectors (e.g. the official Anthropic, Google or OpenAI provider plugins) for AI generation to function.

== Settings ==

Under Alt Text → Settings you can:

* Choose "Let WordPress choose the model" or supply a preferred model order (e.g. `claude-sonnet-4-6, gpt-5.1`) — WordPress core handles falling back automatically if a preferred model isn't configured.
* Edit the system instruction sent to the model.
* Turn automatic generation for new uploads on/off.
* Choose which post types and which locations (content, featured image, SEO image, branding) count as "used on the site".

== Notes on the AI Client integration ==

This plugin was built against the WordPress 7.0 AI Client (`wp_ai_client_prompt()`, `with_file()`, `using_model_preference()`, `is_supported_for_text_generation()`). As this is a very new core API, double-check these method names against the version of WordPress core you're running if you hit fatal errors after a core update, since the surface may still evolve in point releases.
