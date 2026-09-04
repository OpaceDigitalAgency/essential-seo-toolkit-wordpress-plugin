<?php
/**
 * HTTP API mock for the audit tests: routes requests by "METHOD url" to
 * canned responses through the pre_http_request filter, so no test ever
 * touches the network. Required explicitly by each audit test file.
 *
 * @package Opace_ESEOT_Tests
 */

trait Opace_ESEOT_Audit_Http_Mock {

	/**
	 * Every request seen, as "METHOD url", in order.
	 *
	 * @var string[]
	 */
	protected $eseot_http_log = array();

	/**
	 * "METHOD url" or "* url" => response array, WP_Error or Closure.
	 *
	 * @var array<string, mixed>
	 */
	protected $eseot_http_routes = array();

	/**
	 * Start intercepting HTTP.
	 */
	protected function eseot_mock_http() {
		$this->eseot_http_log    = array();
		$this->eseot_http_routes = array();
		add_filter( 'pre_http_request', array( $this, 'eseot_pre_http_request' ), 10, 3 );
	}

	/**
	 * Stop intercepting HTTP.
	 */
	protected function eseot_unmock_http() {
		remove_filter( 'pre_http_request', array( $this, 'eseot_pre_http_request' ), 10 );
	}

	/**
	 * Route a request. Method '*' matches any method.
	 *
	 * @param string $method   HEAD, GET or *.
	 * @param string $url      Exact URL.
	 * @param mixed  $response Response array, WP_Error or Closure( $args, $url ).
	 */
	protected function eseot_route( $method, $url, $response ) {
		$this->eseot_http_routes[ strtoupper( $method ) . ' ' . $url ] = $response;
	}

	/**
	 * pre_http_request callback. Unrouted requests get an HTML 404.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array|WP_Error
	 */
	public function eseot_pre_http_request( $pre, $args, $url ) {
		$method                 = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		$this->eseot_http_log[] = $method . ' ' . $url;

		foreach ( array( $method . ' ' . $url, '* ' . $url ) as $key ) {
			if ( ! isset( $this->eseot_http_routes[ $key ] ) ) {
				continue;
			}
			$response = $this->eseot_http_routes[ $key ];
			if ( $response instanceof Closure ) {
				return $response( $args, $url );
			}
			return $response;
		}

		return $this->eseot_http_response( 404, array( 'Content-Type' => 'text/html; charset=UTF-8' ), '<html>Not Found</html>' );
	}

	/**
	 * Build a WP_Http-shaped response array.
	 *
	 * @param int    $code    Status code.
	 * @param array  $headers Header name => value (any case).
	 * @param string $body    Body.
	 * @return array
	 */
	protected function eseot_http_response( $code, array $headers = array(), $body = '' ) {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => (int) $code,
				'message' => get_status_header_desc( (int) $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Number of logged requests whose "METHOD url" contains $needle ('' = all).
	 *
	 * @param string $needle Substring.
	 * @return int
	 */
	protected function eseot_http_calls( $needle = '' ) {
		if ( '' === $needle ) {
			return count( $this->eseot_http_log );
		}
		$count = 0;
		foreach ( $this->eseot_http_log as $entry ) {
			if ( false !== strpos( $entry, $needle ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Position of the first logged request containing $needle, or -1.
	 *
	 * @param string $needle Substring.
	 * @return int
	 */
	protected function eseot_http_first( $needle ) {
		foreach ( $this->eseot_http_log as $index => $entry ) {
			if ( false !== strpos( $entry, $needle ) ) {
				return $index;
			}
		}
		return -1;
	}

	/**
	 * Remove every crawl-check transient.
	 */
	protected function eseot_delete_crawl_transients() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'opace_eseot_crawl_' ) . '%' ) );
		wp_cache_flush();
	}

	/**
	 * Number of crawl-check transient rows in the options table.
	 *
	 * @return int
	 */
	protected function eseot_count_crawl_transients() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'opace_eseot_crawl_' ) . '%' ) );
	}
}
