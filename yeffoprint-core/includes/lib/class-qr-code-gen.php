<?php
/**
 * QR Code generator library — PHP port of Project Nayuki's reference
 * implementation (MIT License).
 *
 * https://www.nayuki.io/page/qr-code-generator-library
 * Ported line-for-line from the upstream Python port (qrcodegen.py,
 * PyPI package "qrcodegen") rather than re-derived from the ISO/IEC
 * 18004 spec by hand — a QR encoder is exactly the kind of code where
 * a subtle bug (wrong Reed–Solomon generator, an off-by-one in module
 * placement) produces something that looks right but doesn't scan, so
 * this stays a faithful port of a well-tested reference rather than an
 * independent reimplementation. Cross-checked module-for-module
 * against the original Python library for a range of inputs before
 * this shipped — see class-qr-code-gen.php's usage in
 * class-qr-controller.php for how YeffoPrint uses it (the "generate a
 * QR code for a label field" feature).
 *
 * Original copyright notice (preserved per the MIT License's
 * attribution requirement):
 *
 * Copyright (c) Project Nayuki. (MIT License)
 * https://www.nayuki.io/page/qr-code-generator-library
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 * - The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 * - The Software is provided "as is", without warranty of any kind, express or
 *   implied, including but not limited to the warranties of merchantability,
 *   fitness for a particular purpose and noninfringement. In no event shall the
 *   authors or copyright holders be liable for any claim, damages or other
 *   liability, whether in an action of contract, tort or otherwise, arising from,
 *   out of or in connection with the Software or the use or other dealings in the
 *   Software.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_QrCodeGen_Data_Too_Long_Exception extends \Exception {}

/** Error correction level constants — higher tolerates more print damage/dirt at the cost of data capacity. */
final class YeffoPrint_QrCodeGen_Ecc {
	public const LOW      = 0; // ~7% of codewords can be restored.
	public const MEDIUM   = 1; // ~15%.
	public const QUARTILE = 2; // ~25%.
	public const HIGH     = 3; // ~30%.

	/** @return int The 2-bit format value used in the format-info codeword — NOT the same ordering as the ordinal above. */
	public static function format_bits( int $ecl ): int {
		return [ self::LOW => 1, self::MEDIUM => 0, self::QUARTILE => 3, self::HIGH => 2 ][ $ecl ];
	}
}

/** Segment encoding modes. */
final class YeffoPrint_QrCodeGen_Mode {
	public const NUMERIC      = 'NUMERIC';
	public const ALPHANUMERIC = 'ALPHANUMERIC';
	public const BYTE         = 'BYTE';
	public const KANJI        = 'KANJI';
	public const ECI          = 'ECI';

	private const MODE_BITS = [
		self::NUMERIC      => 0x1,
		self::ALPHANUMERIC => 0x2,
		self::BYTE         => 0x4,
		self::KANJI        => 0x8,
		self::ECI          => 0x7,
	];

	/** [versions 1-9, 10-26, 27-40] character-count field bit widths, per mode. */
	private const CHAR_COUNT_BITS = [
		self::NUMERIC      => [ 10, 12, 14 ],
		self::ALPHANUMERIC => [ 9, 11, 13 ],
		self::BYTE         => [ 8, 16, 16 ],
		self::KANJI        => [ 8, 10, 12 ],
		self::ECI          => [ 0, 0, 0 ],
	];

	public static function mode_bits( string $mode ): int {
		return self::MODE_BITS[ $mode ];
	}

	public static function num_char_count_bits( string $mode, int $version ): int {
		return self::CHAR_COUNT_BITS[ $mode ][ intdiv( $version + 7, 17 ) ];
	}
}

/** An appendable sequence of bits (0/1 ints). */
final class YeffoPrint_QrCodeGen_BitBuffer {
	/** @var int[] */
	public array $bits = [];

	public function append_bits( int $val, int $n ): void {
		if ( $n < 0 || ( $n < 63 && ( $val >> $n ) !== 0 ) ) {
			throw new \InvalidArgumentException( 'Value out of range' );
		}
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			$this->bits[] = ( $val >> $i ) & 1;
		}
	}

	public function extend( array $other_bits ): void {
		foreach ( $other_bits as $b ) {
			$this->bits[] = $b;
		}
	}

	public function count(): int {
		return count( $this->bits );
	}
}

/** One segment of character/binary data within a QR Code's payload. */
final class YeffoPrint_QrCodeGen_Segment {
	public string $mode;
	public int $num_chars;
	/** @var int[] */
	public array $bit_data;

	/** @param int[] $bit_data */
	public function __construct( string $mode, int $num_chars, array $bit_data ) {
		if ( $num_chars < 0 ) {
			throw new \InvalidArgumentException();
		}
		$this->mode      = $mode;
		$this->num_chars = $num_chars;
		$this->bit_data  = $bit_data;
	}

	/** @param int[] $data Byte values 0-255. */
	public static function make_bytes( array $data ): self {
		$bb = new YeffoPrint_QrCodeGen_BitBuffer();
		foreach ( $data as $b ) {
			$bb->append_bits( $b, 8 );
		}
		return new self( YeffoPrint_QrCodeGen_Mode::BYTE, count( $data ), $bb->bits );
	}

	public static function make_numeric( string $digits ): self {
		if ( ! self::is_numeric( $digits ) ) {
			throw new \InvalidArgumentException( 'String contains non-numeric characters' );
		}
		$bb  = new YeffoPrint_QrCodeGen_BitBuffer();
		$len = strlen( $digits );
		$i   = 0;
		while ( $i < $len ) {
			$n = min( $len - $i, 3 );
			$bb->append_bits( (int) substr( $digits, $i, $n ), $n * 3 + 1 );
			$i += $n;
		}
		return new self( YeffoPrint_QrCodeGen_Mode::NUMERIC, $len, $bb->bits );
	}

	private const ALPHANUMERIC_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

	public static function make_alphanumeric( string $text ): self {
		if ( ! self::is_alphanumeric( $text ) ) {
			throw new \InvalidArgumentException( 'String contains unencodable characters in alphanumeric mode' );
		}
		$table = array_flip( str_split( self::ALPHANUMERIC_CHARSET ) );
		$bb    = new YeffoPrint_QrCodeGen_BitBuffer();
		$len   = strlen( $text );

		for ( $i = 0; $i + 1 < $len; $i += 2 ) {
			$temp  = $table[ $text[ $i ] ] * 45;
			$temp += $table[ $text[ $i + 1 ] ];
			$bb->append_bits( $temp, 11 );
		}
		if ( $len % 2 > 0 ) {
			$bb->append_bits( $table[ $text[ $len - 1 ] ], 6 );
		}
		return new self( YeffoPrint_QrCodeGen_Mode::ALPHANUMERIC, $len, $bb->bits );
	}

	/** @return YeffoPrint_QrCodeGen_Segment[] */
	public static function make_segments( string $text ): array {
		if ( '' === $text ) {
			return [];
		}
		if ( self::is_numeric( $text ) ) {
			return [ self::make_numeric( $text ) ];
		}
		if ( self::is_alphanumeric( $text ) ) {
			return [ self::make_alphanumeric( $text ) ];
		}
		return [ self::make_bytes( array_values( unpack( 'C*', $text ) ) ) ];
	}

	public static function is_numeric( string $text ): bool {
		return 1 === preg_match( '/^[0-9]*$/', $text );
	}

	public static function is_alphanumeric( string $text ): bool {
		return 1 === preg_match( '#^[A-Z0-9 $%*+./:-]*$#', $text );
	}

	/** @param YeffoPrint_QrCodeGen_Segment[] $segs @return int|null */
	public static function get_total_bits( array $segs, int $version ) {
		$result = 0;
		foreach ( $segs as $seg ) {
			$cc_bits = YeffoPrint_QrCodeGen_Mode::num_char_count_bits( $seg->mode, $version );
			if ( $seg->num_chars >= ( 1 << $cc_bits ) ) {
				return null;
			}
			$result += 4 + $cc_bits + count( $seg->bit_data );
		}
		return $result;
	}
}

/**
 * A QR Code symbol — an immutable square grid of dark/light modules.
 * Supports versions 1-40 and all four error correction levels, per
 * the ISO/IEC 18004 Model 2 spec.
 */
final class YeffoPrint_QrCodeGen_QrCode {

	public const MIN_VERSION = 1;
	public const MAX_VERSION = 40;

	private const PENALTY_N1 = 3;
	private const PENALTY_N2 = 3;
	private const PENALTY_N3 = 40;
	private const PENALTY_N4 = 10;

	// Indexed [ecl][version], version 0 unused (kept for direct index parity with the reference tables).
	private const ECC_CODEWORDS_PER_BLOCK = [
		self::LOW      => [ -1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		self::MEDIUM   => [ -1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28 ],
		self::QUARTILE => [ -1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
		self::HIGH     => [ -1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30 ],
	];

	private const NUM_ERROR_CORRECTION_BLOCKS = [
		self::LOW      => [ -1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25 ],
		self::MEDIUM   => [ -1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49 ],
		self::QUARTILE => [ -1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68 ],
		self::HIGH     => [ -1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81 ],
	];

	private const LOW      = YeffoPrint_QrCodeGen_Ecc::LOW;
	private const MEDIUM   = YeffoPrint_QrCodeGen_Ecc::MEDIUM;
	private const QUARTILE = YeffoPrint_QrCodeGen_Ecc::QUARTILE;
	private const HIGH     = YeffoPrint_QrCodeGen_Ecc::HIGH;

	public int $version;
	public int $size;
	public int $errcorlvl;
	public int $mask;

	/** @var bool[][] */
	private array $modules;
	/** @var bool[][] */
	private array $is_function;

	/**
	 * High-level entry point: encode a Unicode text string, automatically
	 * choosing the smallest version and best segment mode(s).
	 */
	public static function encode_text( string $text, int $ecl ): self {
		$segs = YeffoPrint_QrCodeGen_Segment::make_segments( $text );
		return self::encode_segments( $segs, $ecl );
	}

	/**
	 * @param YeffoPrint_QrCodeGen_Segment[] $segs
	 * @throws YeffoPrint_QrCodeGen_Data_Too_Long_Exception
	 */
	public static function encode_segments( array $segs, int $ecl, int $minversion = 1, int $maxversion = 40, int $mask = -1, bool $boostecl = true ): self {
		if ( ! ( self::MIN_VERSION <= $minversion && $minversion <= $maxversion && $maxversion <= self::MAX_VERSION ) || ! ( -1 <= $mask && $mask <= 7 ) ) {
			throw new \InvalidArgumentException( 'Invalid value' );
		}

		$version         = $minversion;
		$datausedbits    = null;
		$datacapacitybits = 0;

		for ( $version = $minversion; $version <= $maxversion; $version++ ) {
			$datacapacitybits = self::get_num_data_codewords( $version, $ecl ) * 8;
			$datausedbits     = YeffoPrint_QrCodeGen_Segment::get_total_bits( $segs, $version );
			if ( null !== $datausedbits && $datausedbits <= $datacapacitybits ) {
				break;
			}
			if ( $version >= $maxversion ) {
				$msg = null !== $datausedbits
					? "Data length = {$datausedbits} bits, Max capacity = {$datacapacitybits} bits"
					: 'Segment too long';
				throw new YeffoPrint_QrCodeGen_Data_Too_Long_Exception( $msg );
			}
		}

		foreach ( [ self::MEDIUM, self::QUARTILE, self::HIGH ] as $newecl ) {
			if ( $boostecl && $datausedbits <= self::get_num_data_codewords( $version, $newecl ) * 8 ) {
				$ecl = $newecl;
			}
		}

		$bb = new YeffoPrint_QrCodeGen_BitBuffer();
		foreach ( $segs as $seg ) {
			$bb->append_bits( YeffoPrint_QrCodeGen_Mode::mode_bits( $seg->mode ), 4 );
			$bb->append_bits( $seg->num_chars, YeffoPrint_QrCodeGen_Mode::num_char_count_bits( $seg->mode, $version ) );
			$bb->extend( $seg->bit_data );
		}

		$datacapacitybits = self::get_num_data_codewords( $version, $ecl ) * 8;

		$bb->append_bits( 0, min( 4, $datacapacitybits - $bb->count() ) );
		$pad = ( 8 - ( $bb->count() % 8 ) ) % 8; // PHP's % keeps the dividend's sign; this mirrors Python's -len(bb) % 8.
		$bb->append_bits( 0, $pad );

		$toggle = true;
		while ( $bb->count() < $datacapacitybits ) {
			$bb->append_bits( $toggle ? 0xEC : 0x11, 8 );
			$toggle = ! $toggle;
		}

		$num_bytes      = intdiv( $bb->count(), 8 );
		$datacodewords  = array_fill( 0, $num_bytes, 0 );
		foreach ( $bb->bits as $i => $bit ) {
			$datacodewords[ $i >> 3 ] |= $bit << ( 7 - ( $i & 7 ) );
		}

		return new self( $version, $ecl, $datacodewords, $mask );
	}

	/** @param int[] $datacodewords Byte values 0-255. */
	public function __construct( int $version, int $errcorlvl, array $datacodewords, int $msk ) {
		if ( $version < self::MIN_VERSION || $version > self::MAX_VERSION ) {
			throw new \InvalidArgumentException( 'Version value out of range' );
		}
		if ( $msk < -1 || $msk > 7 ) {
			throw new \InvalidArgumentException( 'Mask value out of range' );
		}

		$this->version   = $version;
		$this->size      = $version * 4 + 17;
		$this->errcorlvl = $errcorlvl;

		$this->modules     = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );
		$this->is_function = array_fill( 0, $this->size, array_fill( 0, $this->size, false ) );

		$this->draw_function_patterns();
		$allcodewords = $this->add_ecc_and_interleave( $datacodewords );
		$this->draw_codewords( $allcodewords );

		if ( -1 === $msk ) {
			$minpenalty = PHP_INT_MAX;
			for ( $i = 0; $i < 8; $i++ ) {
				$this->apply_mask( $i );
				$this->draw_format_bits( $i );
				$penalty = $this->get_penalty_score();
				if ( $penalty < $minpenalty ) {
					$msk        = $i;
					$minpenalty = $penalty;
				}
				$this->apply_mask( $i ); // Undo (XOR is its own inverse).
			}
		}

		$this->mask = $msk;
		$this->apply_mask( $msk );
		$this->draw_format_bits( $msk );
	}

	public function get_module( int $x, int $y ): bool {
		return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[ $y ][ $x ];
	}

	// ---- Function pattern drawing ----

	private function draw_function_patterns(): void {
		for ( $i = 0; $i < $this->size; $i++ ) {
			$this->set_function_module( 6, $i, 0 === $i % 2 );
			$this->set_function_module( $i, 6, 0 === $i % 2 );
		}

		$this->draw_finder_pattern( 3, 3 );
		$this->draw_finder_pattern( $this->size - 4, 3 );
		$this->draw_finder_pattern( 3, $this->size - 4 );

		$alignpatpos = $this->get_alignment_pattern_positions();
		$numalign    = count( $alignpatpos );
		$skips       = [ [ 0, 0 ], [ 0, $numalign - 1 ], [ $numalign - 1, 0 ] ];

		for ( $i = 0; $i < $numalign; $i++ ) {
			for ( $j = 0; $j < $numalign; $j++ ) {
				$is_skip = false;
				foreach ( $skips as $skip ) {
					if ( $skip[0] === $i && $skip[1] === $j ) {
						$is_skip = true;
						break;
					}
				}
				if ( ! $is_skip ) {
					$this->draw_alignment_pattern( $alignpatpos[ $i ], $alignpatpos[ $j ] );
				}
			}
		}

		$this->draw_format_bits( 0 );
		$this->draw_version();
	}

	private function draw_format_bits( int $mask ): void {
		$data = YeffoPrint_QrCodeGen_Ecc::format_bits( $this->errcorlvl ) << 3 | $mask;
		$rem  = $data;
		for ( $i = 0; $i < 10; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
		}
		$bits = ( $data << 10 | $rem ) ^ 0x5412;

		for ( $i = 0; $i <= 5; $i++ ) {
			$this->set_function_module( 8, $i, self::get_bit( $bits, $i ) );
		}
		$this->set_function_module( 8, 7, self::get_bit( $bits, 6 ) );
		$this->set_function_module( 8, 8, self::get_bit( $bits, 7 ) );
		$this->set_function_module( 7, 8, self::get_bit( $bits, 8 ) );
		for ( $i = 9; $i <= 14; $i++ ) {
			$this->set_function_module( 14 - $i, 8, self::get_bit( $bits, $i ) );
		}

		for ( $i = 0; $i <= 7; $i++ ) {
			$this->set_function_module( $this->size - 1 - $i, 8, self::get_bit( $bits, $i ) );
		}
		for ( $i = 8; $i <= 14; $i++ ) {
			$this->set_function_module( 8, $this->size - 15 + $i, self::get_bit( $bits, $i ) );
		}
		$this->set_function_module( 8, $this->size - 8, true );
	}

	private function draw_version(): void {
		if ( $this->version < 7 ) {
			return;
		}

		$rem = $this->version;
		for ( $i = 0; $i < 12; $i++ ) {
			$rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
		}
		$bits = $this->version << 12 | $rem;

		for ( $i = 0; $i < 18; $i++ ) {
			$bit = self::get_bit( $bits, $i );
			$a   = $this->size - 11 + $i % 3;
			$b   = intdiv( $i, 3 );
			$this->set_function_module( $a, $b, $bit );
			$this->set_function_module( $b, $a, $bit );
		}
	}

	private function draw_finder_pattern( int $x, int $y ): void {
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$xx = $x + $dx;
				$yy = $y + $dy;
				if ( $xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size ) {
					$dist = max( abs( $dx ), abs( $dy ) );
					$this->set_function_module( $xx, $yy, 2 !== $dist && 4 !== $dist );
				}
			}
		}
	}

	private function draw_alignment_pattern( int $x, int $y ): void {
		for ( $dy = -2; $dy <= 2; $dy++ ) {
			for ( $dx = -2; $dx <= 2; $dx++ ) {
				$this->set_function_module( $x + $dx, $y + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
			}
		}
	}

	private function set_function_module( int $x, int $y, bool $isdark ): void {
		$this->modules[ $y ][ $x ]     = $isdark;
		$this->is_function[ $y ][ $x ] = true;
	}

	// ---- Codewords and masking ----

	/**
	 * @param int[] $data
	 * @return int[]
	 */
	private function add_ecc_and_interleave( array $data ): array {
		$version = $this->version;

		$numblocks     = self::NUM_ERROR_CORRECTION_BLOCKS[ $this->errcorlvl ][ $version ];
		$blockecclen   = self::ECC_CODEWORDS_PER_BLOCK[ $this->errcorlvl ][ $version ];
		$rawcodewords  = intdiv( self::get_num_raw_data_modules( $version ), 8 );
		$numshortblocks = $numblocks - $rawcodewords % $numblocks;
		$shortblocklen = intdiv( $rawcodewords, $numblocks );

		$blocks = [];
		$rsdiv  = self::reed_solomon_compute_divisor( $blockecclen );
		$k      = 0;

		for ( $i = 0; $i < $numblocks; $i++ ) {
			$len = $shortblocklen - $blockecclen + ( $i < $numshortblocks ? 0 : 1 );
			$dat = array_slice( $data, $k, $len );
			$k  += count( $dat );
			$ecc = self::reed_solomon_compute_remainder( $dat, $rsdiv );
			if ( $i < $numshortblocks ) {
				$dat[] = 0;
			}
			$blocks[] = array_merge( $dat, $ecc );
		}

		$result       = [];
		$longest_block = count( $blocks[0] );
		for ( $i = 0; $i < $longest_block; $i++ ) {
			foreach ( $blocks as $j => $blk ) {
				if ( $i !== $shortblocklen - $blockecclen || $j >= $numshortblocks ) {
					$result[] = $blk[ $i ];
				}
			}
		}

		return $result;
	}

	/** @param int[] $data */
	private function draw_codewords( array $data ): void {
		$i         = 0;
		$total_bits = count( $data ) * 8;

		// $right_raw is the loop-control variable and must stay untouched
		// by the body: Python's `for right in range(size-1, 0, -2)`
		// iterates a pre-materialized sequence, so its body reassigning
		// `right` (the `if right <= 6: right -= 1` adjustment) has no
		// effect on which value comes next. A PHP C-style `for` re-reads
		// the *live* loop variable on every step, so reusing the same
		// variable for both roles silently corrupted the step size
		// (effectively -3 instead of -2) whenever that adjustment fired —
		// found via a byte-for-byte cross-check against the reference
		// Python implementation, which caught it immediately (module
		// count 550 vs the correct 600 for a version-2 symbol).
		for ( $right_raw = $this->size - 1; $right_raw >= 1; $right_raw -= 2 ) {
			$right = $right_raw <= 6 ? $right_raw - 1 : $right_raw;
			for ( $vert = 0; $vert < $this->size; $vert++ ) {
				for ( $j = 0; $j < 2; $j++ ) {
					$x       = $right - $j;
					$upward  = 0 === ( ( $right + 1 ) & 2 );
					$y       = $upward ? ( $this->size - 1 - $vert ) : $vert;
					if ( ! $this->is_function[ $y ][ $x ] && $i < $total_bits ) {
						$this->modules[ $y ][ $x ] = self::get_bit( $data[ $i >> 3 ], 7 - ( $i & 7 ) );
						$i++;
					}
				}
			}
		}
	}

	private function apply_mask( int $mask ): void {
		if ( $mask < 0 || $mask > 7 ) {
			throw new \InvalidArgumentException( 'Mask value out of range' );
		}
		for ( $y = 0; $y < $this->size; $y++ ) {
			for ( $x = 0; $x < $this->size; $x++ ) {
				if ( ! $this->is_function[ $y ][ $x ] && 0 === self::mask_function( $mask, $x, $y ) ) {
					$this->modules[ $y ][ $x ] = ! $this->modules[ $y ][ $x ];
				}
			}
		}
	}

	private static function mask_function( int $mask, int $x, int $y ): int {
		switch ( $mask ) {
			case 0: return ( $x + $y ) % 2;
			case 1: return $y % 2;
			case 2: return $x % 3;
			case 3: return ( $x + $y ) % 3;
			case 4: return ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
			case 5: return ( $x * $y ) % 2 + ( $x * $y ) % 3;
			case 6: return ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
			case 7: return ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
		}
		throw new \InvalidArgumentException( 'Mask value out of range' );
	}

	private function get_penalty_score(): int {
		$result  = 0;
		$size    = $this->size;
		$modules = $this->modules;

		for ( $y = 0; $y < $size; $y++ ) {
			$runcolor   = false;
			$runx       = 0;
			$runhistory = array_fill( 0, 7, 0 );
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $modules[ $y ][ $x ] === $runcolor ) {
					$runx++;
					if ( 5 === $runx ) {
						$result += self::PENALTY_N1;
					} elseif ( $runx > 5 ) {
						$result += 1;
					}
				} else {
					$this->finder_penalty_add_history( $runx, $runhistory );
					if ( ! $runcolor ) {
						$result += $this->finder_penalty_count_patterns( $runhistory ) * self::PENALTY_N3;
					}
					$runcolor = $modules[ $y ][ $x ];
					$runx     = 1;
				}
			}
			$result += $this->finder_penalty_terminate_and_count( $runcolor, $runx, $runhistory ) * self::PENALTY_N3;
		}

		for ( $x = 0; $x < $size; $x++ ) {
			$runcolor   = false;
			$runy       = 0;
			$runhistory = array_fill( 0, 7, 0 );
			for ( $y = 0; $y < $size; $y++ ) {
				if ( $modules[ $y ][ $x ] === $runcolor ) {
					$runy++;
					if ( 5 === $runy ) {
						$result += self::PENALTY_N1;
					} elseif ( $runy > 5 ) {
						$result += 1;
					}
				} else {
					$this->finder_penalty_add_history( $runy, $runhistory );
					if ( ! $runcolor ) {
						$result += $this->finder_penalty_count_patterns( $runhistory ) * self::PENALTY_N3;
					}
					$runcolor = $modules[ $y ][ $x ];
					$runy     = 1;
				}
			}
			$result += $this->finder_penalty_terminate_and_count( $runcolor, $runy, $runhistory ) * self::PENALTY_N3;
		}

		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				if ( $modules[ $y ][ $x ] === $modules[ $y ][ $x + 1 ] && $modules[ $y ][ $x ] === $modules[ $y + 1 ][ $x ] && $modules[ $y ][ $x ] === $modules[ $y + 1 ][ $x + 1 ] ) {
					$result += self::PENALTY_N2;
				}
			}
		}

		$dark = 0;
		foreach ( $modules as $row ) {
			foreach ( $row as $cell ) {
				if ( $cell ) {
					$dark++;
				}
			}
		}
		$total = $size * $size;
		$k     = intdiv( abs( $dark * 20 - $total * 10 ) + $total - 1, $total ) - 1;
		$result += $k * self::PENALTY_N4;

		return $result;
	}

	// ---- Static tables/helpers ----

	/** @return int[] */
	private function get_alignment_pattern_positions(): array {
		$ver = $this->version;
		if ( 1 === $ver ) {
			return [];
		}

		$numalign = intdiv( $ver, 7 ) + 2;
		$step     = 32 === $ver ? 26 : intdiv( $ver * 4 + $numalign * 2 + 1, $numalign * 2 - 2 ) * 2;

		$result = [];
		for ( $i = 0; $i < $numalign - 1; $i++ ) {
			$result[] = $this->size - 7 - $i * $step;
		}
		$result[] = 6;

		return array_reverse( $result );
	}

	private static function get_num_raw_data_modules( int $ver ): int {
		if ( $ver < self::MIN_VERSION || $ver > self::MAX_VERSION ) {
			throw new \InvalidArgumentException( 'Version number out of range' );
		}
		$result = ( 16 * $ver + 128 ) * $ver + 64;
		if ( $ver >= 2 ) {
			$numalign = intdiv( $ver, 7 ) + 2;
			$result  -= ( 25 * $numalign - 10 ) * $numalign - 55;
			if ( $ver >= 7 ) {
				$result -= 36;
			}
		}
		return $result;
	}

	private static function get_num_data_codewords( int $ver, int $ecl ): int {
		return intdiv( self::get_num_raw_data_modules( $ver ), 8 )
			- self::ECC_CODEWORDS_PER_BLOCK[ $ecl ][ $ver ] * self::NUM_ERROR_CORRECTION_BLOCKS[ $ecl ][ $ver ];
	}

	/** @return int[] */
	private static function reed_solomon_compute_divisor( int $degree ): array {
		if ( $degree < 1 || $degree > 255 ) {
			throw new \InvalidArgumentException( 'Degree out of range' );
		}

		$result = array_fill( 0, $degree - 1, 0 );
		$result[] = 1;

		$root = 1;
		for ( $i = 0; $i < $degree; $i++ ) {
			for ( $j = 0; $j < $degree; $j++ ) {
				$result[ $j ] = self::reed_solomon_multiply( $result[ $j ], $root );
				if ( $j + 1 < $degree ) {
					$result[ $j ] ^= $result[ $j + 1 ];
				}
			}
			$root = self::reed_solomon_multiply( $root, 0x02 );
		}

		return $result;
	}

	/**
	 * @param int[] $data
	 * @param int[] $divisor
	 * @return int[]
	 */
	private static function reed_solomon_compute_remainder( array $data, array $divisor ): array {
		$result = array_fill( 0, count( $divisor ), 0 );

		foreach ( $data as $b ) {
			$factor = $b ^ array_shift( $result );
			$result[] = 0;
			foreach ( $divisor as $i => $coef ) {
				$result[ $i ] ^= self::reed_solomon_multiply( $coef, $factor );
			}
		}

		return $result;
	}

	private static function reed_solomon_multiply( int $x, int $y ): int {
		if ( $x >> 8 !== 0 || $y >> 8 !== 0 ) {
			throw new \InvalidArgumentException( 'Byte out of range' );
		}
		$z = 0;
		for ( $i = 7; $i >= 0; $i-- ) {
			$z  = ( $z << 1 ) ^ ( ( $z >> 7 ) * 0x11D );
			$z ^= ( ( $y >> $i ) & 1 ) * $x;
		}
		return $z;
	}

	/** @param int[] $runhistory Exactly 7 entries, most-recent-first (index 0 = current). */
	private function finder_penalty_count_patterns( array $runhistory ): int {
		$n    = $runhistory[1];
		$core = $n > 0 && $runhistory[2] === $n && $runhistory[4] === $n && $runhistory[5] === $n && $runhistory[3] === $n * 3;
		return ( $core && $runhistory[0] >= $n * 4 && $runhistory[6] >= $n ? 1 : 0 )
			+ ( $core && $runhistory[6] >= $n * 4 && $runhistory[0] >= $n ? 1 : 0 );
	}

	/** @param int[] $runhistory */
	private function finder_penalty_terminate_and_count( bool $currentruncolor, int $currentrunlength, array $runhistory ): int {
		if ( $currentruncolor ) {
			$this->finder_penalty_add_history( $currentrunlength, $runhistory );
			$currentrunlength = 0;
		}
		$currentrunlength += $this->size;
		$this->finder_penalty_add_history( $currentrunlength, $runhistory );
		return $this->finder_penalty_count_patterns( $runhistory );
	}

	/** @param int[] $runhistory Mutated in place via the by-reference param — mirrors the Python original's deque.appendleft() on a shared mutable object. */
	private function finder_penalty_add_history( int $currentrunlength, array &$runhistory ): void {
		if ( 0 === $runhistory[0] ) {
			$currentrunlength += $this->size;
		}
		array_pop( $runhistory );
		array_unshift( $runhistory, $currentrunlength );
	}

	private static function get_bit( int $x, int $i ): bool {
		return 0 !== ( ( $x >> $i ) & 1 );
	}
}
