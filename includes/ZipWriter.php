<?php
/**
 * Minimal streaming ZIP writer: stored entries with data descriptors, so nothing is buffered
 * in memory or on disk. Without ZIP64 an archive must stay under 4 GB; the caller enforces this.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class ZipWriter {

	const MAX_BYTES = 4294000000;

	/** @var callable */
	private $out;
	private int $offset  = 0;
	private array $index = [];
	private array $current = [];
	private ?\HashContext $crc = null;

	public function __construct( callable $out ) {
		$this->out = $out;
	}

	public function begin_entry( string $name, int $mtime ): void {
		$this->current = [
			'name'   => $name,
			'offset' => $this->offset,
			'size'   => 0,
			'time'   => self::dos_time( $mtime ),
			'date'   => self::dos_date( $mtime ),
		];
		$this->crc     = hash_init( 'crc32b' );

		$this->write(
			pack(
				'VvvvvvVVVvv',
				0x04034b50,
				20,
				0x0808, // Bit 3: sizes follow in a data descriptor. Bit 11: UTF-8 name.
				0,      // Stored.
				$this->current['time'],
				$this->current['date'],
				0,
				0,
				0,
				strlen( $name ),
				0
			) . $name
		);
	}

	public function write_chunk( string $bytes ): void {
		hash_update( $this->crc, $bytes );
		$this->current['size'] += strlen( $bytes );
		$this->write( $bytes );
	}

	public function end_entry(): void {
		$crc = (int) hexdec( hash_final( $this->crc ) );
		$this->write( pack( 'VVVV', 0x08074b50, $crc, $this->current['size'], $this->current['size'] ) );

		$this->current['crc'] = $crc;
		$this->index[]        = $this->current;
	}

	public function finish(): void {
		$start = $this->offset;
		foreach ( $this->index as $e ) {
			$this->write(
				pack(
					'VvvvvvvVVVvvvvvVV',
					0x02014b50,
					20,
					20,
					0x0808,
					0,
					$e['time'],
					$e['date'],
					$e['crc'],
					$e['size'],
					$e['size'],
					strlen( $e['name'] ),
					0,
					0,
					0,
					0,
					0,
					$e['offset']
				) . $e['name']
			);
		}
		$size = $this->offset - $start;
		$this->write( pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $this->index ), count( $this->index ), $size, $start, 0 ) );
	}

	private function write( string $bytes ): void {
		$this->offset += strlen( $bytes );
		( $this->out )( $bytes );
	}

	private static function dos_time( int $ts ): int {
		$d = getdate( $ts );

		return ( $d['hours'] << 11 ) | ( $d['minutes'] << 5 ) | ( $d['seconds'] >> 1 );
	}

	private static function dos_date( int $ts ): int {
		$d = getdate( $ts );

		return ( max( 0, $d['year'] - 1980 ) << 9 ) | ( $d['mon'] << 5 ) | $d['mday'];
	}
}
