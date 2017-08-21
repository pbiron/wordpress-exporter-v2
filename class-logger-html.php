<?php

require_once dirname( __FILE__ ) . '/class-logger.php';

class WP_Exporter_Logger_HTML extends WP_Exporter_Logger {
	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed $level
	 * @param string $message
	 * @param array $context
	 * @return null
	 */
	public function log( $level, $message, array $context = array() ) {
		switch ( $level ) {
			case 'emergency':
			case 'alert':
			case 'critical':
				echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
				echo esc_html( $message );
				echo '</p>';
				break;

			case 'error':
			case 'warning':
			case 'notice':
			case 'info':
				echo '<p>' . esc_html( $message ) . '</p>';
				break;

			case 'debug':
				if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
					echo '<p class="debug">' . esc_html( $message ) . '</p>';
				}
				break;
		}
	}
}
