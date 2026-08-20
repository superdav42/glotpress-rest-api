=== GlotPress REST API Extension ===
Contributors: superdav42
Tags: glotpress, rest-api, translation, localization, i18n
Requires at least: 5.6
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds REST API endpoints for GlotPress project management, enabling programmatic access to translation workflows.

== Description ==

GlotPress REST API Extension adds a comprehensive REST API to GlotPress, the translation management system. This plugin enables developers to programmatically manage translation projects, import originals (POT files), and import translations (PO files).

= Features =

* **Create Projects** - Programmatically create new GlotPress projects via REST API
* **Get Project Info** - Retrieve project details by path
* **Import Originals** - Upload POT file content to import source strings
* **Import Translations** - Upload PO file content to import translations for specific locales
* **Get Translation Sets** - Retrieve all translation sets and their statistics for a project

= REST API Endpoints =

* `POST /wp-json/glotpress/v1/projects` - Create a new project
* `GET /wp-json/glotpress/v1/projects/{path}` - Get project by path
* `POST /wp-json/glotpress/v1/projects/{path}/originals` - Import originals (POT)
* `POST /wp-json/glotpress/v1/projects/{path}/translations/{locale}` - Import translations (PO)
* `GET /wp-json/glotpress/v1/projects/{path}/translation-sets` - Get translation sets

= Requirements =

* WordPress 5.6 or higher
* GlotPress plugin installed and activated
* PHP 7.4 or higher

= Authentication =

Write operations (creating projects, importing) require authentication. The plugin supports:
* WordPress application passwords
* Cookie authentication (for logged-in users)
* Any authentication method compatible with the WordPress REST API

Users must have `manage_options` capability or GlotPress admin permissions.

== Installation ==

1. Upload the `glotpress-rest-api` folder to the `/wp-content/plugins/` directory
2. Alternatively, upload to `/wp-content/mu-plugins/` for must-use installation
3. Activate the plugin through the 'Plugins' menu in WordPress (if not using mu-plugins)
4. Ensure GlotPress is installed and activated

== Frequently Asked Questions ==

= Does this plugin require GlotPress? =

Yes, this plugin is an extension for GlotPress and requires GlotPress to be installed and activated.

= How do I authenticate API requests? =

You can use WordPress application passwords, cookie authentication, or any REST API authentication method. Create an application password in your WordPress user profile under Users > Your Profile > Application Passwords.

= What locale formats are supported? =

The plugin supports standard WordPress locale codes (e.g., `de_DE`, `fr_FR`, `pt_BR`) and automatically maps them to GlotPress locale slugs.

= Are there any unsupported locales? =

Some locales that don't exist in GlotPress are automatically skipped: Hmong (hmn), Samoan (sm), Sesotho (st), and Chichewa (ny).

== Changelog ==

= 1.0.1 =
Version 1.0.1 - Released on 2026-08-19
- Improved: WordPress compatibility metadata now reflects testing through WordPress 7.1.

= 1.0.0 =
* Initial release
* REST API endpoints for project creation
* Originals (POT) import endpoint
* Translations (PO) import endpoint
* Translation sets retrieval endpoint
* Comprehensive locale mapping for WordPress to GlotPress

== Upgrade Notice ==

= 1.0.0 =
Initial release of the GlotPress REST API Extension.
