<?php
/**
 * Standalone logic tests for Abibitumi Chat — stubs just enough of
 * WordPress to exercise the pure algorithmic pieces.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', '/tmp/' );
define( 'ABCHAT_VERSION', '1.0.0' );
define( 'ABCHAT_AGENT_CAP', 'abchat_agent' );
define( 'ABCHAT_DIR', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );

$__options = array();

// --- Minimal WP stubs --------------------------------------------------- //
function __( $s, $d = null ) { return $s; }
function get_bloginfo( $k = '' ) { return 'Abibitumi'; }
function get_option( $k, $default = false ) { global $__options; return array_key_exists( $k, $__options ) ? $__options[ $k ] : $default; }
function update_option( $k, $v, $a = null ) { global $__options; $__options[ $k ] = $v; return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function apply_filters( $tag, $value ) { return $value; }
function do_action() {}
function wp_strip_all_tags( $s ) { return trim( strip_tags( $s ) ); }
function wp_json_encode( $v, $opts = 0 ) { return json_encode( $v, $opts ); }
function current_time( $type ) { return ( 'timestamp' === $type ) ? time() : date( 'Y-m-d H:i:s' ); }
function wp_date( $fmt, $ts = null ) { return date( $fmt, $ts ?: time() ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^a-zA-Z0-9._-]/', '', $s ); }
function wp_kses_post( $s ) { return $s; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
function home_url( $p = '' ) { return 'https://abibitumi.com' . $p; }

// Stub ABChat_DB so the chatbot's respond() can run without a database.
class ABChat_DB {
	public static $messages = array();
	public static function add_message( $d ) { self::$messages[] = $d; return count( self::$messages ); }
	public static function update_conversation( $id, $d ) {}
}

require __DIR__ . '/../includes/class-abchat-settings.php';
require __DIR__ . '/../includes/class-abchat-chatbot.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}

echo "== Settings defaults ==\n";
$defaults = ABChat_Settings::defaults();
ok( $defaults['enabled'] === 1, 'widget enabled by default' );
ok( count( $defaults['bot_flows'] ) === 3, 'three starter bot flows' );
ok( $defaults['primary_color'] === '#0b7d3e', 'brand green default' );

echo "== Office hours ==\n";
// Disabled => always open.
ABChat_Settings::update( array( 'office_hours_enabled' => 0 ) );
ok( ABChat_Settings::is_within_office_hours() === true, 'always open when disabled' );

// Enabled with every day closed => closed.
$closed = array();
foreach ( array( 'mon','tue','wed','thu','fri','sat','sun' ) as $d ) {
	$closed[ $d ] = array( 'open' => 0, 'from' => '09:00', 'to' => '17:00' );
}
ABChat_Settings::update( array( 'office_hours_enabled' => 1, 'office_hours' => $closed ) );
ok( ABChat_Settings::is_within_office_hours() === false, 'closed when all days off' );

// Enabled, today open 00:00-23:59 => open.
$today = strtolower( date( 'D' ) ); $today = substr( $today, 0, 3 );
$open  = $closed;
$open[ $today ] = array( 'open' => 1, 'from' => '00:00', 'to' => '23:59' );
ABChat_Settings::update( array( 'office_hours_enabled' => 1, 'office_hours' => $open ) );
ok( ABChat_Settings::is_within_office_hours() === true, 'open when today is 00:00-23:59' );

echo "== Chatbot keyword matching ==\n";
ABChat_Settings::update( ABChat_Settings::defaults() ); // reset flows
$bot = new ABChat_Chatbot();

$r = $bot->respond( 1, 'How much does membership cost?' );
ok( strpos( $r['reply'], 'membership' ) !== false, 'matches pricing flow on "membership/cost"' );
ok( $r['handoff'] === false, 'pricing match does not hand off' );
ok( count( $r['quickReplies'] ) === 3, 'offers quick replies after a match' );

$r = $bot->respond( 1, 'I want to learn Twi language classes' );
ok( strpos( strtolower( $r['reply'] ), 'live and self-paced' ) !== false, 'matches courses flow on "twi/language/class"' );

$r = $bot->respond( 1, 'can I talk to a human please' );
ok( $r['handoff'] === true, 'human keyword triggers hand-off' );

$r = $bot->respond( 1, 'xyzzy nonsense qwerty' );
ok( $r['handoff'] === true, 'unmatched free text routes to a human' );

// Explicit flow id (quick-reply click).
$r = $bot->respond( 1, '', 'courses' );
ok( strpos( strtolower( $r['reply'] ), 'live and self-paced' ) !== false, 'explicit flow_id returns that flow answer' );

echo "== VAPID key generation ==\n";
require __DIR__ . '/../includes/class-abchat-notifications.php';
$keys = ABChat_Notifications::vapid_keys();
ok( ! empty( $keys['publicKey'] ) && strlen( $keys['publicKey'] ) > 80, 'generates a P-256 public key' );
ok( ! empty( $keys['privateKey'] ), 'generates a private key' );
$keys2 = ABChat_Notifications::vapid_keys();
ok( $keys['publicKey'] === $keys2['publicKey'], 'VAPID key is stable across calls' );

echo "== Site presets & import/export ==\n";
require __DIR__ . '/../includes/class-abchat-presets.php';
$avail = ABChat_Presets::available();
ok( count( $avail ) === 3, 'three site presets are bundled' );
ok( isset( $avail['repatriatetoghana'] ), 'repatriatetoghana preset present' );

ABChat_Settings::update( ABChat_Settings::defaults() );
ok( ABChat_Presets::apply( 'repatriatetoghana' ) === true, 'apply repatriatetoghana preset succeeds' );
ok( ABChat_Settings::get( 'brand_name' ) === 'Repatriate to Ghana', 'brand name switched by preset' );
ok( ABChat_Settings::get( 'primary_color' ) === '#006b3f', 'primary colour switched by preset' );
$flows = ABChat_Settings::get( 'bot_flows' );
$has_visa = false;
foreach ( (array) $flows as $f ) { if ( 'visa' === $f['id'] ) { $has_visa = true; } }
ok( $has_visa, 'repatriation bot flows loaded (visa flow present)' );

ABChat_Settings::update( ABChat_Settings::defaults() );
ok( ABChat_Presets::apply( 'decadeofourrepatriation' ) === true, 'apply decadeofourrepatriation preset succeeds' );
ok( ABChat_Settings::get( 'bot_name' ) === 'Sankofa Bot', 'decade preset sets bot name' );

ok( ABChat_Presets::apply( 'does-not-exist' ) === false, 'unknown preset returns false' );

// Meta keys must never leak into settings.
ABChat_Presets::apply( 'abibitumi' );
ok( ABChat_Settings::get( '_label', 'MISSING' ) === 'MISSING', 'underscore meta keys stripped on import' );

// Round-trip export → import.
$json = ABChat_Presets::export();
$decoded = json_decode( $json, true );
ok( is_array( $decoded ) && isset( $decoded['brand_name'] ), 'export produces valid settings JSON' );
ABChat_Settings::update( array( 'brand_name' => 'Changed' ) );
ABChat_Presets::import( $decoded );
ok( ABChat_Settings::get( 'brand_name' ) === 'Abibitumi', 'import restores exported brand name' );

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
