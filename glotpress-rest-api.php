<?php
/**
 * Plugin Name: GlotPress REST API Extension
 * Description: Adds REST API endpoints for GlotPress project management
 * Version: 1.0.0
 * Author: Multisite Ultimate
 *
 * Install this file in wp-content/mu-plugins/ on your GlotPress/Traduttore server.
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Create a temporary file (compatible with REST API context).
 *
 * wp_tempnam() is defined in wp-admin/includes/file.php which may not be loaded
 * during REST API requests. This function provides a fallback.
 *
 * @param string $prefix Prefix for the temp file name.
 * @return string Path to the temporary file.
 */
function glotpress_rest_tempnam($prefix = '') {
	// Try to use wp_tempnam if available
	if (function_exists('wp_tempnam')) {
		return wp_tempnam($prefix);
	}

	// Load the file that defines wp_tempnam
	if (file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if (function_exists('wp_tempnam')) {
			return wp_tempnam($prefix);
		}
	}

	// Fallback to PHP's native tempnam
	$temp_dir = sys_get_temp_dir();
	return tempnam($temp_dir, $prefix);
}

/**
 * Register REST API routes for GlotPress project management.
 */
add_action('rest_api_init', function () {
	register_rest_route('glotpress/v1', '/projects', [
		'methods'             => 'POST',
		'callback'            => 'glotpress_rest_create_project',
		'permission_callback' => 'glotpress_rest_can_manage_projects',
		'args'                => [
			'name' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'Project display name',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'Project slug (URL-safe identifier)',
				'sanitize_callback' => 'sanitize_title',
			],
			'description' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'description'       => 'Project description',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'parent_project_id' => [
				'required'          => false,
				'type'              => 'integer',
				'default'           => 0,
				'description'       => 'Parent project ID (0 for top-level)',
				'sanitize_callback' => 'absint',
			],
			'source_url_template' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'description'       => 'Source URL template for linking to repository',
				'sanitize_callback' => 'esc_url_raw',
			],
		],
	]);

	register_rest_route('glotpress/v1', '/projects/(?P<project_path>.+)', [
		'methods'             => 'GET',
		'callback'            => 'glotpress_rest_get_project',
		'permission_callback' => '__return_true',
		'args'                => [
			'project_path' => [
				'required'          => true,
				'type'              => 'string',
				'description'       => 'Project path (e.g., ultimatemultisite/addon-slug)',
			],
		],
	]);

	// Import originals (POT file)
	register_rest_route('glotpress/v1', '/projects/(?P<project_path>.+)/originals', [
		'methods'             => 'POST',
		'callback'            => 'glotpress_rest_import_originals',
		'permission_callback' => 'glotpress_rest_can_manage_projects',
		'args'                => [
			'project_path' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'Project path (e.g., ultimatemultisite/addon-slug)',
			],
			'pot_content' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'POT file content',
			],
		],
	]);

	// Import translations (PO file)
	register_rest_route('glotpress/v1', '/projects/(?P<project_path>.+)/translations/(?P<locale>[a-z_]+)', [
		'methods'             => 'POST',
		'callback'            => 'glotpress_rest_import_translations',
		'permission_callback' => 'glotpress_rest_can_manage_projects',
		'args'                => [
			'project_path' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'Project path (e.g., ultimatemultisite/addon-slug)',
			],
			'locale' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'Locale code (e.g., de_DE, fr_FR)',
			],
			'po_content' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'PO file content',
			],
		],
	]);

	// Get translation sets for a project
	register_rest_route('glotpress/v1', '/projects/(?P<project_path>.+)/translation-sets', [
		'methods'             => 'GET',
		'callback'            => 'glotpress_rest_get_translation_sets',
		'permission_callback' => '__return_true',
		'args'                => [
			'project_path' => [
				'required'    => true,
				'type'        => 'string',
				'description' => 'Project path (e.g., ultimatemultisite/addon-slug)',
			],
		],
	]);
});

/**
 * Check if the current user can manage GlotPress projects.
 *
 * @return bool|WP_Error True if user can manage, WP_Error otherwise.
 */
function glotpress_rest_can_manage_projects() {
	// Check if GlotPress is active
	if (!class_exists('GP_Project')) {
		return new WP_Error(
			'glotpress_not_active',
			'GlotPress is not active on this site.',
			['status' => 500]
		);
	}

	// Require authentication
	if (!is_user_logged_in()) {
		return new WP_Error(
			'rest_not_logged_in',
			'You must be logged in to create projects.',
			['status' => 401]
		);
	}

	// Check GlotPress permissions
	$user = wp_get_current_user();

	// Allow administrators
	if (current_user_can('manage_options')) {
		return true;
	}

	// Check GlotPress-specific permissions
	if (function_exists('GP') && method_exists(GP(), 'admin_user')) {
		if (GP()->admin_user()->can('admin')) {
			return true;
		}
	}

	return new WP_Error(
		'rest_forbidden',
		'You do not have permission to create GlotPress projects.',
		['status' => 403]
	);
}

/**
 * Create a new GlotPress project via REST API.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function glotpress_rest_create_project(WP_REST_Request $request) {
	$name              = $request->get_param('name');
	$slug              = $request->get_param('slug');
	$description       = $request->get_param('description');
	$parent_project_id = $request->get_param('parent_project_id');
	$source_url        = $request->get_param('source_url_template');

	// Check if project already exists
	$existing = GP::$project->by_path(
		$parent_project_id > 0
			? GP::$project->get($parent_project_id)->path . '/' . $slug
			: $slug
	);

	if ($existing) {
		return new WP_Error(
			'project_exists',
			'A project with this slug already exists.',
			['status' => 409, 'project' => glotpress_rest_format_project($existing)]
		);
	}

	// Create the project
	$project_data = [
		'name'                => $name,
		'slug'                => $slug,
		'description'         => $description,
		'parent_project_id'   => $parent_project_id,
		'source_url_template' => $source_url,
		'active'              => 1,
	];

	$project = GP::$project->create_and_select($project_data);

	if (!$project || is_wp_error($project)) {
		return new WP_Error(
			'project_creation_failed',
			'Failed to create the GlotPress project.',
			['status' => 500]
		);
	}

	return new WP_REST_Response([
		'success' => true,
		'message' => 'Project created successfully.',
		'project' => glotpress_rest_format_project($project),
	], 201);
}

/**
 * Get a GlotPress project by path.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function glotpress_rest_get_project(WP_REST_Request $request) {
	$project_path = $request->get_param('project_path');

	// Remove leading/trailing slashes
	$project_path = trim($project_path, '/');

	$project = GP::$project->by_path($project_path);

	if (!$project) {
		return new WP_Error(
			'project_not_found',
			'Project not found.',
			['status' => 404]
		);
	}

	return new WP_REST_Response([
		'success' => true,
		'project' => glotpress_rest_format_project($project),
	], 200);
}

/**
 * Format a GlotPress project for REST API response.
 *
 * @param GP_Project $project The project object.
 * @return array Formatted project data.
 */
function glotpress_rest_format_project($project) {
	return [
		'id'                  => (int) $project->id,
		'name'                => $project->name,
		'slug'                => $project->slug,
		'path'                => $project->path,
		'description'         => $project->description,
		'parent_project_id'   => (int) $project->parent_project_id,
		'source_url_template' => $project->source_url_template,
		'active'              => (bool) $project->active,
		'url'                 => gp_url_project($project),
	];
}

/**
 * Import originals (POT file) into a GlotPress project.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function glotpress_rest_import_originals(WP_REST_Request $request) {
	$project_path = trim($request->get_param('project_path'), '/');
	$pot_content  = $request->get_param('pot_content');

	// Get the project
	$project = GP::$project->by_path($project_path);

	if (!$project) {
		return new WP_Error(
			'project_not_found',
			'Project not found.',
			['status' => 404]
		);
	}

	// Create a temporary file for the POT content
	$temp_file = glotpress_rest_tempnam('glotpress_pot_');
	file_put_contents($temp_file, $pot_content);

	// Get the PO format handler
	$format = gp_array_get(GP::$formats, 'po');

	if (!$format) {
		unlink($temp_file);
		return new WP_Error(
			'format_not_found',
			'PO format handler not found.',
			['status' => 500]
		);
	}

	// Parse the POT file
	$originals = $format->read_originals_from_file($temp_file, $project);
	unlink($temp_file);

	if (!$originals) {
		return new WP_Error(
			'parse_error',
			'Failed to parse POT file.',
			['status' => 400]
		);
	}

	// Import the originals
	list($originals_added, $originals_existing, $originals_fuzzied, $originals_obsoleted, $originals_error) = GP::$original->import_for_project($project, $originals);

	return new WP_REST_Response([
		'success'  => true,
		'message'  => 'Originals imported successfully.',
		'stats'    => [
			'added'    => $originals_added,
			'existing' => $originals_existing,
			'fuzzied'  => $originals_fuzzied,
			'obsoleted'=> $originals_obsoleted,
			'errors'   => $originals_error,
		],
	], 200);
}

/**
 * Import translations (PO file) into a GlotPress project.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function glotpress_rest_import_translations(WP_REST_Request $request) {
	$project_path = trim($request->get_param('project_path'), '/');
	$locale_code  = $request->get_param('locale');
	$po_content   = $request->get_param('po_content');

	// Get the project
	$project = GP::$project->by_path($project_path);

	if (!$project) {
		return new WP_Error(
			'project_not_found',
			'Project not found.',
			['status' => 404]
		);
	}

	// Check if this locale is known to be unsupported by GlotPress
	$unsupported = glotpress_rest_get_unsupported_locales();
	$base_locale = explode('_', $locale_code)[0];
	if (in_array($locale_code, $unsupported, true) || in_array($base_locale, $unsupported, true)) {
		// Return a 200 with skipped status instead of an error
		return new WP_REST_Response([
			'success' => true,
			'skipped' => true,
			'message' => 'Locale not supported by GlotPress: ' . $locale_code,
			'locale'  => $locale_code,
		], 200);
	}

	// Parse locale code (e.g., de_DE -> de, de_DE or pt_BR -> pt, br)
	$locale_parts = glotpress_rest_parse_locale($locale_code);

	if (!$locale_parts) {
		return new WP_Error(
			'invalid_locale',
			'Invalid locale code: ' . $locale_code,
			['status' => 400]
		);
	}

	// Get or create the translation set
	$translation_set = GP::$translation_set->by_project_id_slug_and_locale(
		$project->id,
		$locale_parts['slug'],
		$locale_parts['locale']
	);

	if (!$translation_set) {
		// Create the translation set
		$locale_obj = GP_Locales::by_slug($locale_parts['locale']);

		if (!$locale_obj) {
			return new WP_Error(
				'unknown_locale',
				'Unknown locale: ' . $locale_parts['locale'],
				['status' => 400]
			);
		}

		$set_data = [
			'name'       => $locale_obj->english_name,
			'slug'       => $locale_parts['slug'],
			'project_id' => $project->id,
			'locale'     => $locale_parts['locale'],
		];

		$translation_set = GP::$translation_set->create_and_select($set_data);

		if (!$translation_set) {
			return new WP_Error(
				'set_creation_failed',
				'Failed to create translation set.',
				['status' => 500]
			);
		}
	}

	// Create a temporary file for the PO content
	$temp_file = glotpress_rest_tempnam('glotpress_po_');
	file_put_contents($temp_file, $po_content);

	// Get the PO format handler
	$format = gp_array_get(GP::$formats, 'po');

	if (!$format) {
		unlink($temp_file);
		return new WP_Error(
			'format_not_found',
			'PO format handler not found.',
			['status' => 500]
		);
	}

	// Parse the PO file
	$translations = $format->read_translations_from_file($temp_file, $project);
	unlink($temp_file);

	if (!$translations) {
		return new WP_Error(
			'parse_error',
			'Failed to parse PO file.',
			['status' => 400]
		);
	}

	// Import the translations
	list($translations_added, $translations_updated, $translations_fuzzied, $translations_error) = $translation_set->import($translations);

	return new WP_REST_Response([
		'success'         => true,
		'message'         => 'Translations imported successfully.',
		'locale'          => $locale_code,
		'translation_set' => [
			'id'     => (int) $translation_set->id,
			'name'   => $translation_set->name,
			'slug'   => $translation_set->slug,
			'locale' => $translation_set->locale,
		],
		'stats'           => [
			'added'   => $translations_added,
			'updated' => $translations_updated,
			'fuzzied' => $translations_fuzzied,
			'errors'  => $translations_error,
		],
	], 200);
}

/**
 * Get translation sets for a project.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function glotpress_rest_get_translation_sets(WP_REST_Request $request) {
	$project_path = trim($request->get_param('project_path'), '/');

	// Get the project
	$project = GP::$project->by_path($project_path);

	if (!$project) {
		return new WP_Error(
			'project_not_found',
			'Project not found.',
			['status' => 404]
		);
	}

	// Get all translation sets for this project
	$translation_sets = GP::$translation_set->by_project_id($project->id);

	$sets = [];
	foreach ($translation_sets as $set) {
		$sets[] = [
			'id'                    => (int) $set->id,
			'name'                  => $set->name,
			'slug'                  => $set->slug,
			'locale'                => $set->locale,
			'current_count'         => (int) $set->current_count(),
			'untranslated_count'    => (int) $set->untranslated_count(),
			'waiting_count'         => (int) $set->waiting_count(),
			'fuzzy_count'           => (int) $set->fuzzy_count(),
			'percent_translated'    => $set->percent_translated(),
		];
	}

	return new WP_REST_Response([
		'success'          => true,
		'project'          => glotpress_rest_format_project($project),
		'translation_sets' => $sets,
	], 200);
}

/**
 * WordPress to GlotPress locale mapping for codes that don't match.
 * Verified against https://translate.wordpress.org/
 *
 * @return array Mapping of WordPress locale codes to GlotPress slugs.
 */
function glotpress_rest_get_locale_mapping() {
	return [
		// Filipino/Tagalog
		'fil'    => 'tl',
		'fil_PH' => 'tl',
		// Odia/Oriya
		'or'     => 'ory',
		'or_IN'  => 'ory',
		// Kyrgyz - GlotPress uses 'kir' not 'ky'
		'ky'     => 'kir',
		'ky_KY'  => 'kir',
		// Maltese - GlotPress uses 'mlt' not 'mt'
		'mt'     => 'mlt',
		'mt_MT'  => 'mlt',
		// Zulu - GlotPress uses 'zul' not 'zu'
		'zu'     => 'zul',
		'zu_ZA'  => 'zul',
		// Tigrinya
		'ti'     => 'tir',
		'ti_ER'  => 'tir',
		'ti_ET'  => 'tir',
		// Sindhi
		'sd'     => 'snd',
		'sd_PK'  => 'snd',
		// Azerbaijani South
		'azb'    => 'az',
		// Igbo
		'ig'     => 'ibo',
		// Maori
		'mi'     => 'mri',
		// Shona
		'sn'     => 'sna',
		// Xhosa
		'xh'     => 'xho',
		// Yoruba
		'yo'     => 'yor',
		// Hausa
		'ha'     => 'hau',
		// Kurdish (map to Sorani)
		'ku'     => 'ckb',
		'ku_TR'  => 'kmr',
		// Kinyarwanda
		'rw'     => 'kin',
		'rw_RW'  => 'kin',
	];
}

/**
 * Get list of locale codes that are not supported by GlotPress.
 * These will be skipped during import.
 * Verified against https://translate.wordpress.org/
 *
 * @return array List of unsupported locale codes.
 */
function glotpress_rest_get_unsupported_locales() {
	return [
		'hmn',    // Hmong - not in GlotPress
		'sm',     // Samoan - not in GlotPress
		'st',     // Sesotho - not in GlotPress
		'ny',     // Chichewa - not in GlotPress
	];
}

/**
 * Parse a locale code into GlotPress locale and slug.
 *
 * Handles formats like:
 * - de_DE -> locale: de, slug: default
 * - pt_BR -> locale: pt, slug: br (or locale: pt-br, slug: default)
 * - fr_FR -> locale: fr, slug: default
 *
 * @param string $locale_code The locale code (e.g., de_DE, pt_BR).
 * @return array|false Array with 'locale' and 'slug' keys, or false if invalid.
 */
function glotpress_rest_parse_locale($locale_code) {
	// Check if this locale is known to be unsupported
	$unsupported = glotpress_rest_get_unsupported_locales();
	$base_locale = explode('_', $locale_code)[0];
	if (in_array($locale_code, $unsupported, true) || in_array($base_locale, $unsupported, true)) {
		return false;
	}

	// Check our custom mapping first
	$mapping = glotpress_rest_get_locale_mapping();
	if (isset($mapping[$locale_code])) {
		$locale_obj = GP_Locales::by_slug($mapping[$locale_code]);
		if ($locale_obj) {
			return [
				'locale' => $locale_obj->slug,
				'slug'   => 'default',
			];
		}
	}

	// Try the base locale in our mapping
	if (isset($mapping[$base_locale])) {
		$locale_obj = GP_Locales::by_slug($mapping[$base_locale]);
		if ($locale_obj) {
			return [
				'locale' => $locale_obj->slug,
				'slug'   => 'default',
			];
		}
	}

	// Try to find an exact match in GlotPress locales by wp_locale
	$locale_obj = GP_Locales::by_field('wp_locale', $locale_code);

	if ($locale_obj) {
		return [
			'locale' => $locale_obj->slug,
			'slug'   => 'default',
		];
	}

	// Try with just the language code
	$parts = explode('_', $locale_code);
	$lang  = strtolower($parts[0]);

	$locale_obj = GP_Locales::by_slug($lang);

	if ($locale_obj) {
		// If there's a country code, use it as the slug variant
		if (isset($parts[1])) {
			return [
				'locale' => $locale_obj->slug,
				'slug'   => strtolower($parts[1]),
			];
		}

		return [
			'locale' => $locale_obj->slug,
			'slug'   => 'default',
		];
	}

	// Try combined slug (e.g., pt-br)
	if (isset($parts[1])) {
		$combined = $lang . '-' . strtolower($parts[1]);
		$locale_obj = GP_Locales::by_slug($combined);

		if ($locale_obj) {
			return [
				'locale' => $locale_obj->slug,
				'slug'   => 'default',
			];
		}
	}

	return false;
}
