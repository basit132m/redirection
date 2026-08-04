<?php
/**
 * Standalone simulation of the chain-flattening algorithm in
 * NSPVault_Redirects_DB::record_move(). It mirrors the exact 5 SQL steps
 * against an in-memory store so the invariants can be verified without a
 * full WordPress environment.
 *
 * Run: php tests/flatten-simulation.php
 */

/**
 * In-memory redirect store keyed by source path. Value is the target path.
 * This is functionally identical to the UNIQUE(source_path) table + the
 * ordered operations performed by record_move().
 */
class RedirectStore {
	/** @var array<string,string> source => target */
	public $rows = array();

	private function normalize( $p ) {
		$p = trim( (string) $p );
		return '/' . ltrim( $p, '/' );
	}

	public function record_move( $old, $new ) {
		$old = $this->normalize( $old );
		$new = $this->normalize( $new );
		if ( $old === $new ) {
			return;
		}

		// 1) Re-point everything currently targeting $old to $new (flatten chains).
		foreach ( $this->rows as $src => $tgt ) {
			if ( $tgt === $old ) {
				$this->rows[ $src ] = $new;
			}
		}

		// 2) $new is now live; it must not be a source.
		unset( $this->rows[ $new ] );

		// 3) Upsert old -> new.
		$this->rows[ $old ] = $new;

		// 5) Safety net: no self-referential rows.
		foreach ( $this->rows as $src => $tgt ) {
			if ( $src === $tgt ) {
				unset( $this->rows[ $src ] );
			}
		}
	}

	/** Follow the chain to prove hop count. Returns [final, hops]. */
	public function resolve( $path ) {
		$path  = $this->normalize( $path );
		$hops  = 0;
		$seen  = array();
		while ( isset( $this->rows[ $path ] ) ) {
			if ( isset( $seen[ $path ] ) ) {
				return array( $path, PHP_INT_MAX ); // loop detected
			}
			$seen[ $path ] = true;
			$path          = $this->rows[ $path ];
			$hops++;
		}
		return array( $path, $hops );
	}
}

$failures = 0;
$tests    = 0;

function check( $label, $condition ) {
	global $failures, $tests;
	$tests++;
	if ( $condition ) {
		echo "  PASS  $label\n";
	} else {
		$failures++;
		echo "  FAIL  $label\n";
	}
}

echo "Scenario 1: the exact case from the request (rename twice)\n";
$s = new RedirectStore();
$base = '/roms/pokemon-lets-go-eevee-rom/';
$v1   = '/roms/pokemon-lets-go-eevee-rom-1/';
$v2   = '/roms/pokemon-lets-go-eevee-rom-2/';

$s->record_move( $base, $v1 );        // base -> -1
list( $end, $hops ) = $s->resolve( $base );
check( 'original -> -1 in one hop', $end === $v1 && 1 === $hops );

$s->record_move( $v1, $v2 );          // -1 -> -2, and base must jump straight to -2
list( $e1, $h1 ) = $s->resolve( $base );
list( $e2, $h2 ) = $s->resolve( $v1 );
check( 'original -> -2 directly (single hop, no chain)', $e1 === $v2 && 1 === $h1 );
check( '-1 -> -2 directly (single hop)', $e2 === $v2 && 1 === $h2 );
check( '-2 is live (not a source)', ! isset( $s->rows[ $v2 ] ) );

echo "\nScenario 2: five renames, every historical URL stays one hop\n";
$s = new RedirectStore();
$urls = array();
for ( $i = 0; $i <= 5; $i++ ) {
	$urls[ $i ] = "/roms/game-$i/";
}
for ( $i = 1; $i <= 5; $i++ ) {
	$s->record_move( $urls[ $i - 1 ], $urls[ $i ] );
}
$latest = $urls[5];
$ok     = true;
for ( $i = 0; $i < 5; $i++ ) {
	list( $end, $hops ) = $s->resolve( $urls[ $i ] );
	if ( $end !== $latest || 1 !== $hops ) {
		$ok = false;
	}
}
check( 'all 5 historical URLs resolve to newest in exactly one hop', $ok );
check( 'newest URL is not a source', ! isset( $s->rows[ $latest ] ) );

echo "\nScenario 3: renaming back to a URL that was previously retired\n";
$s = new RedirectStore();
$a = '/roms/a/';
$b = '/roms/b/';
$s->record_move( $a, $b ); // a -> b
$s->record_move( $b, $a ); // rename back to a; a must become live again
list( $end, $hops ) = $s->resolve( $b );
check( 'b -> a in one hop', $end === $a && 1 === $hops );
check( 'a is live again (no a -> b left, no loop)', ! isset( $s->rows[ $a ] ) );
list( , $ah ) = $s->resolve( $a );
check( 'no redirect loop between a and b', $ah !== PHP_INT_MAX );

echo "\nScenario 4: two different posts do not interfere\n";
$s = new RedirectStore();
$s->record_move( '/roms/x/', '/roms/x-1/' );
$s->record_move( '/roms/y/', '/roms/y-1/' );
$s->record_move( '/roms/x-1/', '/roms/x-2/' );
list( $xe, $xh ) = $s->resolve( '/roms/x/' );
list( $ye, $yh ) = $s->resolve( '/roms/y/' );
check( 'x original -> x-2 (one hop)', $xe === '/roms/x-2/' && 1 === $xh );
check( 'y original -> y-1 unaffected (one hop)', $ye === '/roms/y-1/' && 1 === $yh );

echo "\n----------------------------------------\n";
echo sprintf( "%d checks, %d failures\n", $tests, $failures );
exit( $failures > 0 ? 1 : 0 );
