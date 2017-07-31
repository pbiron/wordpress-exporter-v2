<?php
/**
 * @link https://core.trac.wordpress.org/attachment/ticket/22435/export.5.diff
 * pvb: reformatted to match PHP Coding Standards and a few other conventions *I*
 * 		prefer
 */

class WP_Map_Iterator extends IteratorIterator {
	/**
	 * Constructor.
	 *
	 * @param Traversable $iterator
	 * @param callable $callback
	 */
	function __construct( $iterator, $callback ) {
		$this->callback = $callback;

		parent::__construct( $iterator );
	}

	/**
	 * Get the current value.
	 *
	 * @return mixed The current value.
	 */
	function current() {
		$original_current = parent::current();

		return call_user_func( $this->callback, $original_current );
	}
}

class WP_IDs_Iterator implements Iterator {
	/**
	 * Size of chunks to get from database.
	 *
	 * @var int
	 */
	protected $chunk_size = 100;

	/**
	 * IDs to iterate over.
	 *
	 * @var array
	 */
	protected $ids = array();

	/**
	 * IDs remaining to be iterated over.
	 *
	 * @var array
	 */
	protected $ids_left = array();

	/**
	 * ???
	 * @var array
	 */
	protected $results = array();

	/**
	 * ???
	 *
	 * @var callable
	 */
	protected $callable;

	/**
	 * Index of current item.
	 *
	 * @var int
	 */
	protected $index_in_results;

	/**
	 * Key of current item.
	 *
	 * @var int
	 */
	protected $key;

	/**
	 * Constructor.
	 *
	 * @param array $ids IDs to iterate over.
	 * @param callable $callable
	 * @param int $chunk_size
	 */
	public function __construct( $ids, $callable, $chunk_size = null ) {
		if ( is_callable( $callable ) ) {
			$this->callable = $callable;
		}
		else {
			// @todo better error reporting
			return WP_Error( 'not_callable', __( "not callable " ) );
		}

		$this->ids = $this->ids_left = $ids;
		if ( !is_null( $chunk_size ) ) {
			$this->chunk_size = $chunk_size;
		}
	}

	/**
	 * Get the current element.
	 *
	 * @return mixed
	 */
	public function current() {
		return $this->results[$this->index_in_results];
	}

	/**
	 * Get the key of current element.
	 *
	 * @return int
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * Move forward to the next element.
	 */
	public function next() {
		$this->index_in_results++;
		$this->key++;
	}

	/**
	 * Rewind the Iterator to the first element.
	 */
	public function rewind() {
		$this->results = array();
		$this->key = 0;
		$this->index_in_results = 0;
		$this->ids_left = $this->ids;
	}

	/**
	 * Check if current position is valid.
	 */
	public function valid() {
		if ( isset( $this->results[$this->index_in_results] ) ) {
			return true;
		}

		if ( empty( $this->ids_left ) ) {
			return false;
		}

		$has_more = $this->load_next_from_db();
		if ( ! $has_more ) {
			return false;
		}

		$this->index_in_results = 0;

		return true;
	}

	/**
	 * Load the next chunk from the database.
	 *
	 * @throws WP_Iterator_Exception
	 * @return bool True on success, false on error.
	 */
	protected function load_next_from_db() {
		global $wpdb;

		$next_chunk = array_splice( $this->ids_left, 0, $this->chunk_size );
		$this->results = array_map( $this->callable, $next_chunk );
		if ( ! $this->results ) {
			if ( $wpdb->last_error ) {
				throw new WP_Iterator_Exception( 'Database error: ' . $wpdb->last_error );
			}
			else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds a SQL condition in the form "post_id IN (1, 2, 3, 4)"
	 *
	 * @param string $column_name The name of the table column from the IN condition
	 * @param array $values Array of values in which the column value should be
	 * @param string $format Optional printf format specifier for the elements of the array. Defaults to %s.
	 * @return string The IN condition, with escaped values. If there are no values, the return value is an empty string.
	 *
	 * Note: the patch in trac 22435 has this in wpdb but it doesn't belong there
	 * 		 @link https://core.trac.wordpress.org/ticket/22435#comment:30
	 */
	function build_IN_condition( $column_name, $values, $format = '%s' ) {
		global $wpdb;

		if ( !is_array( $values ) || empty( $values ) ) {
			return '';
		}

		$formats = implode( ', ', array_fill( 0, count( $values ), $format ) );

		return $wpdb->prepare( "$column_name IN ($formats)", $values );
	}
}

class WP_Iterator_Exception extends Exception {}