<?php
/**
 * Seeded workload generator for the sync-engine benchmark.
 *
 * A workload is a document of N paragraphs plus a list of ROUNDS. Each round
 * is a set of (client, paragraph, text) edits authored from the same
 * observed sequence, then delivered. Whether clients share a paragraph in a
 * round is what produces contention: two clients typing the SAME paragraph
 * field concurrently escalate (the second loses the one-sided-transform
 * race); typing DIFFERENT paragraphs merges clean. Scenarios pick that mix,
 * so the quality metric has a controllable escalation rate to report.
 *
 * The generator is engine-agnostic and deterministic: same seed, same
 * rounds. The runner binds each edit to real engine coordinates (syncId,
 * baseSeq) at submit time.
 *
 * @package gutenberg
 */

if ( ! class_exists( 'WP_Sync_Bench_Workload' ) ) {

	/**
	 * Generates deterministic benchmark workloads.
	 */
	class WP_Sync_Bench_Workload {
		/**
		 * The available scenarios and their descriptions.
		 *
		 * @return array<string, string> Slug => description.
		 */
		public static function scenarios(): array {
			return array(
				'solo-typing'         => 'One editor typing into one document (baseline cost, no contention).',
				'long-form'           => 'One editor in a much LARGER document (does engine cost scale with document size?).',
				'parallel-paragraphs' => 'Several editors, each in their own paragraph (clean concurrent merges).',
				'contended-paragraph' => 'Several editors typing into the SAME paragraph (high escalation).',
				'mixed-newsroom'      => 'Mostly parallel editing with occasional collisions.',
				'laggy-newsroom'      => 'Mixed newsroom where the last client reads only every 10th round (stale bases, deep transforms, catch-up reads).',
			);
		}

		/**
		 * Builds a workload.
		 *
		 * @param string $scenario   Scenario slug.
		 * @param int    $seed        Deterministic seed.
		 * @param int    $rounds      Number of edit rounds.
		 * @param int    $clients     Number of concurrent editors.
		 * @param int    $paragraphs  Document paragraph count.
		 * @return array Workload: post_content, paragraphs, clients, rounds.
		 */
		public static function build( string $scenario, int $seed, int $rounds, int $clients, int $paragraphs ): array {
			// Portable deterministic draws: crc32 of (seed, counter) — a
			// fixed 32-bit hash, so the workload never depends on PHP's rng
			// seeding or int width across versions.
			$counter = 0;
			$rand    = static function ( int $modulo ) use ( $seed, &$counter ): int {
				$hash = crc32( $seed . ':' . ( $counter++ ) ) & 0x7fffffff;
				return $modulo > 0 ? $hash % $modulo : 0;
			};

			// long-form pads every paragraph to ~600 chars, so the default 8
			// paragraphs make a ~5 KB document (raise `paragraphs` for more) —
			// against solo-typing's near-empty one, this shows whether engine
			// cost scales with document size.
			$filler = 'long-form' === $scenario
				? str_repeat( ' lorem ipsum dolor sit amet consectetur adipiscing elit sed do', 9 )
				: '';

			$content_parts = array();
			for ( $i = 0; $i < $paragraphs; $i++ ) {
				$content_parts[] = "<!-- wp:paragraph -->\n<p>Paragraph " . ( $i + 1 ) . $filler . "</p>\n<!-- /wp:paragraph -->";
			}
			$post_content = implode( "\n\n", $content_parts );

			// Which rounds each client reads on: 1 = every round (the default
			// lock-step model); k = only every k-th round (a laggy client whose
			// baseSeq falls up to k rounds behind, forcing deeper transforms
			// and bigger catch-up reads).
			$read_every = array_fill( 0, max( 1, $clients ), 1 );
			if ( 'laggy-newsroom' === $scenario && $clients > 1 ) {
				$read_every[ $clients - 1 ] = 10;
			}

			// Two operation kinds drive the two settlement paths. Concurrent
			// text inserts MERGE (the text interleaves — correct, not a
			// conflict), so contention is modelled as concurrent writes to a
			// versioned register: two editors changing the SAME block's
			// alignment from the same observed version. The later writer
			// escalates (attr-conflict) — the everyday "we both restyled
			// this block" collision.
			$round_list = array();
			for ( $r = 0; $r < $rounds; $r++ ) {
				$edits = array();

				switch ( $scenario ) {
					case 'solo-typing':
					case 'long-form':
						$edits[] = array(
							'client'    => 0,
							'paragraph' => $rand( $paragraphs ),
							'op'        => 'text',
						);
						break;

					case 'parallel-paragraphs':
						for ( $c = 0; $c < $clients; $c++ ) {
							$edits[] = array(
								'client'    => $c,
								'paragraph' => $c % $paragraphs,
								'op'        => 'text',
							);
						}
						break;

					case 'contended-paragraph':
						// Every client restyles the same block concurrently.
						$target = $rand( $paragraphs );
						for ( $c = 0; $c < $clients; $c++ ) {
							$edits[] = array(
								'client'    => $c,
								'paragraph' => $target,
								'op'        => 'attr',
							);
						}
						break;

					case 'mixed-newsroom':
					case 'laggy-newsroom':
					default:
						// ~25% of rounds collide (concurrent restyle of one
						// block); the rest is clean parallel typing.
						$collision = $rand( 100 ) < 25;
						$hotspot   = $rand( $paragraphs );
						for ( $c = 0; $c < $clients; $c++ ) {
							$edits[] = array(
								'client'    => $c,
								'paragraph' => $collision ? $hotspot : $rand( $paragraphs ),
								'op'        => $collision ? 'attr' : 'text',
							);
						}
						break;
				}

				foreach ( $edits as $index => &$edit ) {
					// A unique, delimiter-terminated token per edit: the runner's
					// convergence oracle counts these in the materialized content,
					// so no token may be a substring of another (';' terminates).
					$edit['text']  = ' r' . $r . 'c' . $edit['client'] . '.' . $index . ';';
					$edit['align'] = 0 === ( ( $r + $edit['client'] ) % 2 ) ? 'wide' : 'full';
				}
				unset( $edit );

				$round_list[] = $edits;
			}

			return array(
				'scenario'     => $scenario,
				'post_content' => $post_content,
				'paragraphs'   => $paragraphs,
				'clients'      => $clients,
				'read_every'   => $read_every,
				'rounds'       => $round_list,
			);
		}
	}
}
