<?php
/**
 * One kind of synced content: where it comes from, how it is stored, and how a
 * sync brings the stored copy up to date.
 *
 * Two storage kinds extend this:
 *   - AC_Post_Type_Content: one post per item, in a hidden, read-only post type
 *     (Field Reports, playbooks);
 *   - AC_Option_Content: the whole list cached in one option (communities, Site
 *     Features, summits).
 *
 * Content types are listed in the AC_Types registry by key. For a Contentful type
 * the key is its Contentful content type ID, which is how the webhook routes a
 * delivery. Each instance is built from a config array: the class's defaults(),
 * then the `ac_content_types` filter, so a site can change a type without
 * subclassing it.
 */

defined( 'ABSPATH' ) || exit;

abstract class AC_Content_Type {

	protected string $key;

	protected array $config;

	public function __construct( string $key, array $config ) {
		$this->key    = $key;
		$this->config = wp_parse_args( $config, static::base_defaults() );
	}

	/**
	 * Config keys every type carries:
	 *
	 * - class        (string)   The AC_Content_Type subclass the registry builds.
	 * - label        (string)   Plural label, for admin screens and log messages.
	 * - singular     (string)   Singular label.
	 * - source       (string)   Source ID in the AC_Types registry ('contentful', 'wp-json').
	 * - dependencies (string[]) Source types whose changes re-sync every entry of this type.
	 * - full_sync    (bool)     Whether `sync --all`, "Sync now" and dependency changes sync this
	 *                           type. Off for types with their own schedule (summits).
	 */
	public static function base_defaults(): array {
		return [
			'class'        => static::class,
			'label'        => '',
			'singular'     => '',
			'source'       => 'contentful',
			'dependencies' => [],
			'full_sync'    => true,
		];
	}

	/** This class's config, before the `ac_content_types` filter. */
	public static function defaults(): array {
		return static::base_defaults();
	}

	public function key(): string {
		return $this->key;
	}

	/** One config value, or the whole config. */
	public function config( string $name = '' ) {
		return $name === '' ? $this->config : ( $this->config[ $name ] ?? null );
	}

	public function label(): string {
		return (string) ( $this->config['label'] ?: $this->key );
	}

	public function source(): ?AC_Source {
		return AC_Types::source( (string) $this->config['source'] );
	}

	/** @return string[] */
	public function dependencies(): array {
		return array_values( array_map( 'strval', (array) $this->config['dependencies'] ) );
	}

	public function in_full_sync(): bool {
		return (bool) $this->config['full_sync'];
	}

	/** Whether the registry should build this type (enough config to work). */
	public function is_valid(): bool {
		return $this->source() !== null;
	}

	/**
	 * Hooks this type needs on every request. Called once, at `init` priority 1,
	 * for each registered type.
	 */
	public function init(): void {}

	/**
	 * Bring the stored copy up to date with the source.
	 *
	 * A dry run fetches and reports but writes nothing.
	 *
	 * @return array Result counts and messages; see the storage kind for its shape.
	 */
	abstract public function sync( bool $force = false, bool $dry_run = false ): array;

	/** A one-line state for admin screens and `wp assemble-content types`, e.g. "12 published, 1 draft". */
	abstract public function summary(): string;
}
