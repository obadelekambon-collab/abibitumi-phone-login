<?php
/**
 * Plugin Name:       Abibitumi Chat
 * Plugin URI:        https://abibitumi.com/
 * Description:       Self-hosted live chat, chatbots, ticketing, and visitor tracking — a full Tidio replacement for WordPress/BuddyBoss. Web first, PWA ready.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Abibitumi
 * Author URI:        https://abibitumi.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abibitumi-chat
 * Domain Path:       /languages
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ABCHAT_VERSION', '1.0.0' );
define( 'ABCHAT_FILE', __FILE__ );
define( 'ABCHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABCHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'ABCHAT_BASENAME', plugin_basename( __FILE__ ) );

// Capability required to operate the agent dashboard.
if ( ! defined( 'ABCHAT_AGENT_CAP' ) ) {
	define( 'ABCHAT_AGENT_CAP', 'abchat_agent' );
}

require_once ABCHAT_DIR . 'includes/class-abchat-db.php';
require_once ABCHAT_DIR . 'includes/class-abchat-settings.php';
require_once ABCHAT_DIR . 'includes/class-abchat-activator.php';
require_once ABCHAT_DIR . 'includes/class-abchat-rest.php';
require_once ABCHAT_DIR . 'includes/class-abchat-chatbot.php';
require_once ABCHAT_DIR . 'includes/class-abchat-notifications.php';
require_once ABCHAT_DIR . 'includes/class-abchat-widget.php';
require_once ABCHAT_DIR . 'includes/class-abchat-admin.php';
require_once ABCHAT_DIR . 'includes/class-abchat-pwa.php';
require_once ABCHAT_DIR . 'includes/class-abchat-plugin.php';

register_activation_hook( __FILE__, array( 'ABChat_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ABChat_Activator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 */
function abchat() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new ABChat_Plugin();
	}
	return $instance;
}

add_action( 'plugins_loaded', 'abchat' );
