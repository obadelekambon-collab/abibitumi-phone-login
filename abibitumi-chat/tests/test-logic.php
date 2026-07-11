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

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require $autoload;
}

$__options = array();
$__transients = array();

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
function sanitize_email( $s ) { return filter_var( $s, FILTER_SANITIZE_EMAIL ); }
function wp_kses_post( $s ) { return $s; }
function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); }
function home_url( $p = '' ) { return 'https://abibitumi.com' . $p; }
function current_user_can( $capability ) { return false; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $s ) ); }
function get_transient( $key ) { global $__transients; return isset( $__transients[ $key ] ) ? $__transients[ $key ] : false; }
function set_transient( $key, $value, $expiration ) { global $__transients; $__transients[ $key ] = $value; return true; }
function wp_next_scheduled( $hook ) { return false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { return true; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return isset( $response['response']['code'] ) ? $response['response']['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
function wp_remote_post( $url, $args ) { global $__remote_response, $__remote_request; $__remote_request = array( 'url' => $url, 'args' => $args ); return $__remote_response; }
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	private $status;
	public function __construct( $data = null, $status = 200 ) { $this->status = $status; }
	public function get_status() { return $this->status; }
}
class ABChat_Test_REST_Request {
	private $route;
	private $params;
	public function __construct( $route, $params = array() ) { $this->route = $route; $this->params = $params; }
	public function get_route() { return $this->route; }
	public function get_param( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}

// Stub ABChat_DB so the chatbot's respond() can run without a database.
class ABChat_DB {
	public static $messages = array();
	public static $cleanup_calls = 0;
	public static function client_ip() { return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; }
	public static function add_message( $d ) { self::$messages[] = $d; return count( self::$messages ); }
	public static function update_conversation( $id, $d ) {}
	public static function privacy_records( $email ) { return array(); }
	public static function erase_privacy_records( $email ) {
		return array( 'visitors' => 'person@example.com' === $email ? 1 : 0, 'attachments' => array() );
	}
	public static function delete_push_by_endpoint( $endpoint ) {}
	public static function cleanup_expired_data( $cutoff, $limit = 100 ) {
		self::$cleanup_calls++;
		return array( 'conversations' => 2, 'messages' => 5, 'visitors' => 1, 'attachments' => array() );
	}
}

require __DIR__ . '/../includes/class-abchat-settings.php';
require __DIR__ . '/../includes/class-abchat-chatbot.php';
require __DIR__ . '/../includes/class-abchat-gemini.php';
require __DIR__ . '/../includes/class-abchat-rest.php';
require __DIR__ . '/../includes/class-abchat-stream.php';
require __DIR__ . '/../includes/class-abchat-privacy.php';
require __DIR__ . '/../includes/class-abchat-web-push.php';
require __DIR__ . '/../includes/class-abchat-retention.php';

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
ok( $defaults['bot_ai_enabled'] === 0, 'Gemini disabled by default' );
ok( $defaults['gemini_model'] === 'gemini-2.5-flash', 'Gemini model has a portable default' );
ok( $defaults['bot_rate_limit'] === 10 && $defaults['bot_rate_window'] === 60, 'bot rate limit has portable defaults' );
ok( $defaults['session_rate_limit'] === 30 && $defaults['session_rate_window'] === 3600, 'new-session rate limit has portable defaults' );
ok( $defaults['message_rate_limit'] === 30 && $defaults['message_rate_window'] === 60, 'visitor message rate limit has portable defaults' );
ok( $defaults['conversation_rate_limit'] === 10 && $defaults['conversation_rate_window'] === 3600, 'conversation rate limit has portable defaults' );
ok( $defaults['max_message_length'] === 5000, 'message length has a portable default' );
ok( $defaults['stream_enabled'] === 0 && $defaults['stream_duration'] === 25, 'SSE transport is optional with a bounded default duration' );
ok( $defaults['retention_enabled'] === 0 && $defaults['retention_days'] === 365, 'retention is opt-in with a one-year default policy' );

echo "== PWA cache privacy ==\n";
$service_worker = file_get_contents( ABCHAT_DIR . 'assets/js/sw.js' );
ok( false === strpos( $service_worker, '.put(' ), 'service worker never caches authenticated navigation responses' );
ok( false !== strpos( $service_worker, "k.indexOf( ABCHAT_CACHE_PREFIX ) === 0" ), 'service worker deletes only its own legacy caches' );
ok( false !== strpos( $service_worker, "'Cache-Control': 'no-store'" ), 'offline response explicitly forbids storage' );

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

echo "== Gemini fallback backend ==\n";
$gemini = new ABChat_Gemini();
$rule   = array( 'reply' => 'Rule reply', 'handoff' => false );
ABChat_Settings::update( array( 'bot_ai_enabled' => 0, 'gemini_api_key' => 'test-key' ) );
ok( $gemini->filter_response( $rule, 'Question', 1 ) === $rule, 'disabled Gemini preserves rule response' );

ABChat_Settings::update( array( 'bot_ai_enabled' => 1, 'gemini_api_key' => 'test-key', 'gemini_model' => 'gemini-2.5-flash' ) );
$__remote_response = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Gemini reply' ) ) ) ) ) ) ),
);
$ai = $gemini->filter_response( $rule, 'Question', 1 );
ok( 'Gemini reply' === $ai['reply'] && false === $ai['handoff'], 'valid Gemini response overrides rule response' );
ok( false !== strpos( $__remote_request['url'], 'gemini-2.5-flash:generateContent' ), 'configured Gemini model used in request' );
ok( false === strpos( $__remote_request['url'], 'test-key' ) && 'test-key' === $__remote_request['args']['headers']['x-goog-api-key'], 'Gemini key sent in header instead of URL' );

$__remote_response = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'HANDOFF' ) ) ) ) ) ) ),
);
$ai = $gemini->filter_response( $rule, 'Question', 1 );
ok( true === $ai['handoff'] && ABChat_Settings::get( 'bot_fallback' ) === $ai['reply'], 'Gemini hand-off uses configured fallback' );

$__remote_response = new WP_Error();
ok( $gemini->filter_response( $rule, 'Question', 1 ) === $rule, 'Gemini transport error preserves rule response' );
$__remote_response = array( 'response' => array( 'code' => 429 ), 'body' => '{}' );
ok( $gemini->filter_response( $rule, 'Question', 1 ) === $rule, 'Gemini API error preserves rule response' );

echo "== Bot endpoint rate limit ==\n";
$__transients = array();
ABChat_Settings::update( array( 'bot_rate_limit' => 3, 'bot_rate_window' => 60 ) );
$rest    = new ABChat_REST();
$visitor = (object) array( 'id' => 7, 'ip' => '192.0.2.10' );
ok( false === $rest->check_bot_rate_limit( $visitor ), 'first bot request allowed' );
ok( false === $rest->check_bot_rate_limit( $visitor ), 'second bot request allowed' );
ok( false === $rest->check_bot_rate_limit( $visitor ), 'request at visitor limit allowed' );
$limited = $rest->check_bot_rate_limit( $visitor );
ok( is_wp_error( $limited ) && 'abchat_bot_rate_limited' === $limited->get_error_code(), 'request above visitor limit rejected' );
ok( 429 === $limited->get_error_data()['status'] && $limited->get_error_data()['retry_after'] > 0, 'rate-limit error includes status and retry timing' );

$__transients = array();
$limited      = false;
for ( $i = 1; $i <= 10; $i++ ) {
	$rotated = (object) array( 'id' => 100 + $i, 'ip' => '192.0.2.20' );
	$result  = $rest->check_bot_rate_limit( $rotated );
	if ( is_wp_error( $result ) ) {
		$limited = $result;
		break;
	}
}
ok( 10 === $i && is_wp_error( $limited ), 'IP bucket blocks new-session visitor rotation' );

echo "== Visitor message rate limit ==\n";
$__transients = array();
ABChat_Settings::update( array( 'message_rate_limit' => 2, 'message_rate_window' => 60 ) );
$visitor = (object) array( 'id' => 8, 'ip' => '192.0.2.22' );
ok( false === $rest->check_message_rate_limit( $visitor ), 'first visitor message allowed' );
ok( false === $rest->check_message_rate_limit( $visitor ), 'visitor message at limit allowed' );
$limited = $rest->check_message_rate_limit( $visitor );
ok( is_wp_error( $limited ) && 'abchat_message_rate_limited' === $limited->get_error_code(), 'visitor message above limit rejected' );
ok( 429 === $limited->get_error_data()['status'], 'visitor message limit returns HTTP 429' );

echo "== Conversation creation rate limit ==\n";
$__transients = array();
ABChat_Settings::update( array( 'conversation_rate_limit' => 2, 'conversation_rate_window' => 60 ) );
$visitor = (object) array( 'id' => 9, 'ip' => '192.0.2.23' );
ok( false === $rest->check_conversation_rate_limit( $visitor ), 'first conversation allowed' );
ok( false === $rest->check_conversation_rate_limit( $visitor ), 'conversation at limit allowed' );
$limited = $rest->check_conversation_rate_limit( $visitor );
ok( is_wp_error( $limited ) && 'abchat_conversation_rate_limited' === $limited->get_error_code(), 'conversation above limit rejected' );
ok( 429 === $limited->get_error_data()['status'], 'conversation limit returns HTTP 429' );

echo "== New visitor session rate limit ==\n";
$__transients             = array();
$_SERVER['REMOTE_ADDR']   = '192.0.2.30';
ABChat_Settings::update( array( 'session_rate_limit' => 2, 'session_rate_window' => 60 ) );
ok( false === $rest->check_session_rate_limit(), 'first new visitor session allowed' );
ok( false === $rest->check_session_rate_limit(), 'new visitor session at IP limit allowed' );
$limited = $rest->check_session_rate_limit();
ok( is_wp_error( $limited ) && 'abchat_session_rate_limited' === $limited->get_error_code(), 'new visitor session above IP limit rejected' );
ok( 429 === $limited->get_error_data()['status'] && $limited->get_error_data()['retry_after'] > 0, 'session limit includes REST status and retry timing' );

echo "== SSE authorization guards ==\n";
ABChat_Settings::update( array( 'stream_enabled' => 1 ) );
$stream_transport = new ABChat_Stream();
$request          = new ABChat_Test_REST_Request( ABChat_Stream::VISITOR_ROUTE );
ok( false === $stream_transport->serve( false, new WP_REST_Response( null, 401 ), $request, null ), 'failed visitor authentication is not intercepted as a stream' );
$request = new ABChat_Test_REST_Request( ABChat_Stream::AGENT_ROUTE );
ok( false === $stream_transport->serve( false, new WP_REST_Response( null, 200 ), $request, null ), 'agent stream rechecks operator capability' );

echo "== WordPress privacy integration ==\n";
$privacy   = new ABChat_Privacy();
$exporters = $privacy->register_exporter( array() );
$erasers   = $privacy->register_eraser( array() );
ok( isset( $exporters['abibitumi-chat']['callback'] ), 'personal-data exporter registered' );
ok( isset( $erasers['abibitumi-chat']['callback'] ), 'personal-data eraser registered' );
$export = $privacy->export( 'person@example.com' );
ok( true === $export['done'] && array() === $export['data'], 'privacy exporter completes cleanly without matching records' );
$erased = $privacy->erase( 'person@example.com' );
ok( true === $erased['items_removed'] && true === $erased['done'], 'privacy eraser reports removed records' );

echo "== Web Push adapter ==\n";
$dependency_loaded = class_exists( '\\Minishlink\\WebPush\\WebPush' );
ok( $dependency_loaded === ABChat_Web_Push::is_available(), 'Web Push adapter availability follows Composer dependency' );

echo "== Data retention ==\n";
ABChat_DB::$cleanup_calls = 0;
ABChat_Settings::update( array( 'retention_enabled' => 0 ) );
$cleanup = ABChat_Retention::run();
ok( 0 === ABChat_DB::$cleanup_calls && 0 === $cleanup['conversations'], 'disabled retention never deletes data' );
ABChat_Settings::update( array( 'retention_enabled' => 1, 'retention_days' => 30, 'retention_batch' => 50 ) );
$cleanup = ABChat_Retention::run();
ok( 1 === ABChat_DB::$cleanup_calls && 2 === $cleanup['conversations'], 'enabled retention runs one bounded cleanup batch' );
ok( 5 === $cleanup['messages'] && 1 === $cleanup['visitors'] && isset( $cleanup['ran_at'] ), 'retention records cleanup counts and run time' );

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
ok( ! isset( $decoded['gemini_api_key'] ), 'export omits Gemini API key' );
ABChat_Settings::update( array( 'brand_name' => 'Changed' ) );
ABChat_Presets::import( $decoded );
ok( ABChat_Settings::get( 'brand_name' ) === 'Abibitumi', 'import restores exported brand name' );

echo "\n== RESULT: $pass passed, $fail failed ==\n";
exit( $fail ? 1 : 0 );
