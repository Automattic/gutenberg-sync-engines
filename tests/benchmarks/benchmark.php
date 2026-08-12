<?php
/**
 * Sync-engine benchmark CLI — runs INSIDE WordPress (the engines need
 * get_post/serialize_block and a $wpdb for the ingest lock), so invoke it
 * through wp-cli's eval-file in the environment under test:
 *
 *   wp eval-file tests/benchmarks/benchmark.php \
 *       engine=intent-log scenario=mixed-newsroom \
 *       rounds=200 clients=4 paragraphs=8 seed=42 json=out.json
 *
 * (Pass options as bare `key=value` tokens — wp-cli would claim `--flags`
 * as its own parameters.)
 *
 * Compare engines by running the slugs over the same scenario/seed. The
 * intent log reports full cost AND policy-correct quality (applied /
 * escalated-for-review / lost); yjs-server reports full cost AND
 * CRDT-oracle quality (all-client convergence, lossless text, register
 * agreement) over real y-php-authored payloads. An engine that merges on
 * the client (like the retired yjs-relay) reports cost only — the server
 * cannot score quality (shown as unobservable, never faked). See README.md.
 *
 * @package gutenberg
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this through: wp eval-file benchmark.php -- <options>\n" );
	exit( 1 );
}

require_once __DIR__ . '/class-wp-sync-bench-memory-storage.php';
require_once __DIR__ . '/class-wp-sync-bench-workload.php';
require_once __DIR__ . '/class-wp-sync-bench-runner.php';

/**
 * Parses `key=value` (or `--key=value`) tokens into an options map.
 *
 * @param array $tokens Argument tokens.
 * @return array Options map.
 */
if ( ! function_exists( 'wp_sync_bench_parse_args' ) ) {
	function wp_sync_bench_parse_args( array $tokens ): array {
		$options = array();
		foreach ( $tokens as $arg ) {
			if ( preg_match( '/^-{0,2}([a-z0-9-]+)=(.*)$/', (string) $arg, $m ) ) {
				$options[ $m[1] ] = $m[2];
			}
		}
		return $options;
	}
}

// wp-cli eval-file exposes positional tokens as $args; fall back to $argv.
$wp_sync_bench_opts = wp_sync_bench_parse_args(
	isset( $args ) && is_array( $args ) ? $args : ( $argv ?? array() )
);

$engine_slug = $wp_sync_bench_opts['engine'] ?? 'intent-log';
$scenario    = $wp_sync_bench_opts['scenario'] ?? 'mixed-newsroom';
$rounds      = (int) ( $wp_sync_bench_opts['rounds'] ?? 200 );
$clients     = (int) ( $wp_sync_bench_opts['clients'] ?? 4 );
$paragraphs  = (int) ( $wp_sync_bench_opts['paragraphs'] ?? 8 );
$seed        = (int) ( $wp_sync_bench_opts['seed'] ?? 42 );
$reps        = max( 1, (int) ( $wp_sync_bench_opts['reps'] ?? 3 ) );
$warmup      = max( 0, min( (int) ( $wp_sync_bench_opts['warmup'] ?? 1 ), $reps - 1 ) );

if ( ! array_key_exists( $scenario, WP_Sync_Bench_Workload::scenarios() ) ) {
	fwrite( STDERR, "Unknown scenario: {$scenario}\n" );
	fwrite( STDERR, 'Scenarios: ' . implode( ', ', array_keys( WP_Sync_Bench_Workload::scenarios() ) ) . "\n" );
	exit( 1 );
}
// Engines are resolved through the framework's registry (the
// `wp_sync_engines` filter), so any engine registered by an active plugin —
// including third-party ones — is benchmarkable by slug. HOW each engine is
// driven is the authoring profile's job, resolved per slug through
// WP_Sync_Bench_Profiles (extensible via the
// `wp_sync_bench_authoring_profiles` filter); engines without a dedicated
// profile fall back to relay-convention opaque updates.
$wp_sync_bench_engine_slugs = ( new WP_Sync_Engine_Registry( new WP_Sync_Bench_Memory_Storage() ) )->get_engine_slugs();
if ( ! in_array( $engine_slug, $wp_sync_bench_engine_slugs, true ) ) {
	fwrite( STDERR, "Unknown engine: {$engine_slug}\n" );
	fwrite( STDERR, 'Registered engines: ' . ( $wp_sync_bench_engine_slugs ? implode( ', ', $wp_sync_bench_engine_slugs ) : '(none — is the engine plugin active?)' ) . "\n" );
	exit( 1 );
}

/**
 * Times the environment's database round-trips so the intent-log service
 * time can be decomposed: its handle_updates() holds a per-room MySQL
 * GET_LOCK for the length of the request, so each timed request includes
 * one lock/release pair of DB round-trips that lock-free engines (e.g.
 * yjs-server's ingest) do not pay.
 *
 * @return array db_rtt and lock_pair p50, in milliseconds.
 */
if ( ! function_exists( 'wp_sync_bench_calibrate' ) ) {
	function wp_sync_bench_calibrate(): array {
		global $wpdb;

		$db_rtt    = array();
		$lock_pair = array();
		$lock_name = $wpdb->prefix . 'sync_bench_calibration';
		for ( $i = 0; $i < 100; $i++ ) {
			$start = hrtime( true );
			$wpdb->get_var( 'SELECT 1' );
			$db_rtt[] = ( hrtime( true ) - $start ) / 1e3;

			$start = hrtime( true );
			$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$lock_pair[] = ( hrtime( true ) - $start ) / 1e3;
		}

		return array(
			'db_rtt_p50_ms'    => WP_Sync_Bench_Runner::summary( $db_rtt )['p50'],
			'lock_pair_p50_ms' => WP_Sync_Bench_Runner::summary( $lock_pair )['p50'],
		);
	}
}

// The workload is deterministic, so every repetition replays the identical
// edit sequence: counted metrics (dispositions, storage, payloads) must not
// move between reps, and timing gets independent samples. Warmup reps run
// the same load but are excluded from timing (autoload, opcache, and the
// first lock acquisition all land in rep 0).
$wp_sync_bench_workload = WP_Sync_Bench_Workload::build( $scenario, $seed, $rounds, $clients, $paragraphs );

$wp_sync_bench_series       = array(
	'service_us'   => array(),
	'read_us'      => array(),
	'idle_poll_us' => array(),
);
$wp_sync_bench_rep_means    = array();
$wp_sync_bench_fingerprints = array();
$report                     = null;
for ( $wp_sync_bench_rep = 0; $wp_sync_bench_rep < $reps; $wp_sync_bench_rep++ ) {
	$storage = new WP_Sync_Bench_Memory_Storage();
	$engine  = ( new WP_Sync_Engine_Registry( $storage ) )->get_engine( $engine_slug );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => 'Sync benchmark',
			'post_content' => $wp_sync_bench_workload['post_content'],
		)
	);

	$report = WP_Sync_Bench_Runner::run( $engine, $storage, (int) $post_id, $wp_sync_bench_workload );
	wp_delete_post( (int) $post_id, true );

	$series = array();
	foreach ( array_keys( $wp_sync_bench_series ) as $metric ) {
		$series[ $metric ] = $report[ $metric . '_series' ];
		unset( $report[ $metric . '_series' ] );
	}

	// Counted metrics must be identical across reps (deterministic workload);
	// a mismatch means engine nondeterminism and is worth surfacing.
	$wp_sync_bench_fingerprints[] = (string) wp_json_encode(
		array( $report['quality']['dispositions'], $report['storage'], $report['payload_bytes'] )
	);

	if ( $wp_sync_bench_rep >= $warmup ) {
		foreach ( $series as $metric => $samples ) {
			$wp_sync_bench_series[ $metric ] = array_merge( $wp_sync_bench_series[ $metric ], $samples );
		}
		$wp_sync_bench_rep_means[] = count( $series['service_us'] ) > 0
			? array_sum( $series['service_us'] ) / count( $series['service_us'] ) / 1000
			: 0.0;
	}
}

// Timing across measured reps: pooled percentiles + spread of rep means.
foreach ( $wp_sync_bench_series as $metric => $samples ) {
	$report[ $metric ] = WP_Sync_Bench_Runner::summary( $samples );
}

$wp_sync_bench_mean = array_sum( $wp_sync_bench_rep_means ) / count( $wp_sync_bench_rep_means );
$wp_sync_bench_var  = 0.0;
foreach ( $wp_sync_bench_rep_means as $m ) {
	$wp_sync_bench_var += ( $m - $wp_sync_bench_mean ) ** 2;
}
$wp_sync_bench_stddev = count( $wp_sync_bench_rep_means ) > 1
	? sqrt( $wp_sync_bench_var / ( count( $wp_sync_bench_rep_means ) - 1 ) )
	: 0.0;

$report['timing'] = array(
	'timer'                     => 'hrtime',
	'reps'                      => $reps,
	'warmup_reps'               => $warmup,
	'measured_reps'             => count( $wp_sync_bench_rep_means ),
	'rep_mean_ms'               => array_map(
		static function ( $m ) {
			return round( $m, 4 );
		},
		$wp_sync_bench_rep_means
	),
	'rep_mean_stddev_ms'        => round( $wp_sync_bench_stddev, 4 ),
	'deterministic_across_reps' => 1 === count( array_unique( $wp_sync_bench_fingerprints ) ),
);

$report['calibration'] = wp_sync_bench_calibrate();

global $wp_version, $wpdb;
$report['environment'] = array(
	'php'     => PHP_VERSION,
	'os'      => php_uname( 's' ) . ' ' . php_uname( 'm' ),
	'wp'      => (string) $wp_version,
	'db'      => $wpdb->db_server_info(),
	'opcache' => function_exists( 'opcache_get_status' )
		&& ! empty( ( opcache_get_status( false ) ?: array() )['opcache_enabled'] ),
);

$report['config'] = array(
	'engine'     => $engine_slug,
	'scenario'   => $scenario,
	'rounds'     => $rounds,
	'clients'    => $clients,
	'paragraphs' => $paragraphs,
	'seed'       => $seed,
	'reps'       => $reps,
	'warmup'     => $warmup,
);

$q = $report['quality'];
printf( "\n== %s / %s ==\n", $report['engine'], $report['scenario'] );
printf( "config: rounds=%d clients=%d paragraphs=%d seed=%d reps=%d(+%d warmup)\n", $rounds, $clients, $paragraphs, $seed, $report['timing']['measured_reps'], $warmup );
printf(
	"environment: PHP %s / WP %s / %s / %s / opcache %s\n",
	$report['environment']['php'],
	$report['environment']['wp'],
	$report['environment']['db'],
	$report['environment']['os'],
	$report['environment']['opcache'] ? 'on' : 'off'
);
printf(
	"calibration: db rtt p50=%.4f ms, lock pair p50=%.4f ms%s\n",
	$report['calibration']['db_rtt_p50_ms'],
	$report['calibration']['lock_pair_p50_ms'],
	in_array( $engine_slug, array( 'intent-log', 'de-rtc' ), true ) ? ' (each request below holds one lock pair)' : ''
);
printf( "requests: %d per rep\n", $report['requests'] );
printf(
	"service ms: p50=%.4f p90=%.4f p99=%.4f max=%.4f mean=%.4f\n",
	$report['service_us']['p50'],
	$report['service_us']['p90'],
	$report['service_us']['p99'],
	$report['service_us']['max'],
	$report['service_us']['mean']
);
printf(
	"rep means ms: %s (stddev %.4f)%s\n",
	implode( ', ', array_map( 'strval', $report['timing']['rep_mean_ms'] ) ),
	$report['timing']['rep_mean_stddev_ms'],
	$report['timing']['deterministic_across_reps'] ? '' : ' — WARNING: counted metrics varied across reps'
);
printf(
	"read ms: p50=%.4f p99=%.4f max=%.4f mean=%.4f\n",
	$report['read_us']['p50'],
	$report['read_us']['p99'],
	$report['read_us']['max'],
	$report['read_us']['mean']
);
printf(
	"idle poll ms: p50=%.4f p99=%.4f max=%.4f mean=%.4f\n",
	$report['idle_poll_us']['p50'],
	$report['idle_poll_us']['p99'],
	$report['idle_poll_us']['max'],
	$report['idle_poll_us']['mean']
);
printf(
	"payload bytes: req p50=%d max=%d / resp p50=%d max=%d\n",
	$report['payload_bytes']['request_p50'],
	$report['payload_bytes']['request_max'],
	$report['payload_bytes']['response_p50'],
	$report['payload_bytes']['response_max']
);
printf( "storage: rows=%d bytes=%d followups=%d\n", $report['storage']['rows'], $report['storage']['bytes'], $report['storage']['followups'] );
if ( $q['observable'] ) {
	printf(
		"quality: converged=%s applied=%d escalated=%d voided=%d escalation_rate=%.4f lost_work=%d\n",
		$q['converged'] ? 'yes' : 'NO',
		$q['dispositions']['applied'],
		$q['dispositions']['escalated'],
		$q['dispositions']['voided'],
		$q['escalation_rate'],
		$q['lost_work']
	);
	foreach ( $q['convergence_failures'] as $failure ) {
		printf( "  convergence failure [%s]: %s\n", $failure['check'], $failure['detail'] );
	}
} else {
	printf( "quality: NOT SERVER-OBSERVABLE (client-side CRDT merge)\n" );
}
if ( 'opaque-relay' === ( $report['profile'] ?? '' ) ) {
	printf(
		"note: engine '%s' has no dedicated authoring profile — driven with relay-convention opaque updates ('update'/'compaction'); if the engine rejects them, the dispositions/storage counts above reflect that. An engine plugin can register a profile via the wp_sync_bench_authoring_profiles filter.\n",
		$engine_slug
	);
}

if ( ! empty( $wp_sync_bench_opts['json'] ) ) {
	file_put_contents( (string) $wp_sync_bench_opts['json'], wp_json_encode( $report ) );
	printf( "wrote %s\n", $wp_sync_bench_opts['json'] );
}
echo "\n";
