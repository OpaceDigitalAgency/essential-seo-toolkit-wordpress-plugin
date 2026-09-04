<?php
/**
 * Plugin Name:       Opace Essential SEO Toolkit & SEO Audit Tool
 * Plugin URI:        https://opace.agency/tools/browser/
 * Description:       A private on-page SEO audit inside the WordPress editor, with accessibility and Web Vitals checks and page-aware links to saved SEO tools.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Opace Digital Agency
 * Author URI:        https://opace.agency/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       opace-essential-seo-toolkit
 * Domain Path:       /languages
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPACE_ESEOT_VERSION', '2.0.0' );
define( 'OPACE_ESEOT_FILE', __FILE__ );
define( 'OPACE_ESEOT_PATH', plugin_dir_path( __FILE__ ) );
define( 'OPACE_ESEOT_URL', plugin_dir_url( __FILE__ ) );

require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-link-resolver.php';
require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-defaults.php';
require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-settings.php';
require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-audit.php';
require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-audit-page.php';
require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-plugin.php';

// Stored summaries and the list-table, admin-bar and dashboard surfaces.
if ( file_exists( OPACE_ESEOT_PATH . 'includes/class-opace-eseot-audit-store.php' ) ) {
	require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-audit-store.php';
}
if ( file_exists( OPACE_ESEOT_PATH . 'includes/class-opace-eseot-integrations.php' ) ) {
	require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-integrations.php';
}
if ( file_exists( OPACE_ESEOT_PATH . 'includes/class-opace-eseot-help.php' ) ) {
	require_once OPACE_ESEOT_PATH . 'includes/class-opace-eseot-help.php';
}

register_activation_hook( __FILE__, array( 'Opace_ESEOT_Plugin', 'activate' ) );

Opace_ESEOT_Plugin::instance();
