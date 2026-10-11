<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin GitHub REST API client. Every real request goes through request()
 * so the hourly budget (section 12.6 of the plan) is tracked in one place.
 */
class LAGR_GitHub_Client {

	const API_BASE     = 'https://api.github.com';
	const BUDGET_OPTION = '_gh_api_budget';

	private $token;

	public function __construct() {
		$this->token = LAGR_Settings::get( 'github_token' );
	}

	private function headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'llm-agent-github-repos-plugin',
		);
		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	// ---------------------------------------------------------------- budget

	public function budget_remaining() {
		$threshold = (int) LAGR_Settings::get( 'gh_budget_threshold', 4500 );
		$state     = get_option( self::BUDGET_OPTION, array() );
		$hour      = gmdate( 'Y-m-d\TH' );

		if ( empty( $state['hour'] ) || $state['hour'] !== $hour ) {
			$state = array(
				'hour' => $hour,
				'used' => 0,
			);
			update_option( self::BUDGET_OPTION, $state, false );
		}
		return max( 0, $threshold - (int) $state['used'] );
	}

	public function budget_exhausted() {
		return $this->budget_remaining() <= 0;
	}

	private function track_request() {
		$hour  = gmdate( 'Y-m-d\TH' );
		$state = get_option( self::BUDGET_OPTION, array() );
		if ( empty( $state['hour'] ) || $state['hour'] !== $hour ) {
			$state = array(
				'hour' => $hour,
				'used' => 0,
			);
		}
		$state['used'] = (int) $state['used'] + 1;
		update_option( self::BUDGET_OPTION, $state, false );
	}

	// --------------------------------------------------------------- request

	/**
	 * @param string $path   Path relative to API_BASE, or a full search query string.
	 * @param array  $args   Extra args for wp_remote_get (query params etc.).
	 * @param bool   $retry  Internal flag, used for the single 403 backoff retry.
	 * @return array{code:int, body:mixed, headers:array}
	 */
	private function request( $path, $args = array(), $retry = true ) {
		$this->track_request();

		$url = ( 0 === strpos( $path, 'http' ) ) ? $path : self::API_BASE . $path;
		if ( ! empty( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}

		// Allow a caller to ask for a non-JSON representation (e.g. the README
		// endpoint rendered to HTML via application/vnd.github.html).
		$headers = $this->headers();
		if ( ! empty( $args['accept'] ) ) {
			$headers['Accept'] = $args['accept'];
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 0,
				'body'    => null,
				'headers' => array(),
				'error'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		// Secondary rate limit — back off once and retry, same as the bot did.
		if ( 403 === $code && $retry ) {
			sleep( 10 );
			return $this->request( $path, $args, false );
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$body     = json_decode( $body_raw, true );

		return array(
			'code'    => $code,
			'body'    => null !== $body ? $body : $body_raw,
			'headers' => wp_remote_retrieve_headers( $response ),
		);
	}

	// ---------------------------------------------------------- search / test

	public function search_repositories( $query, $sort = 'updated', $per_page = 100 ) {
		$result = $this->request(
			'/search/repositories',
			array(
				'query' => array(
					'q'        => $query,
					'sort'     => $sort,
					'order'    => 'desc',
					'per_page' => min( 100, max( 1, (int) $per_page ) ),
				),
			)
		);

		if ( 200 !== $result['code'] || ! is_array( $result['body'] ) ) {
			return array(
				'ok'    => false,
				'items' => array(),
				'error' => $result['body']['message'] ?? ( $result['error'] ?? 'HTTP ' . $result['code'] ),
			);
		}

		return array(
			'ok'          => true,
			'items'       => $result['body']['items'] ?? array(),
			'total_count' => $result['body']['total_count'] ?? 0,
		);
	}

	/** Used for the "validate on save" feature — same call, friendly message. */
	public function test_search( $query ) {
		if ( empty( $query ) ) {
			return array(
				'ok'      => false,
				'message' => 'Пустой запрос — заполните топики или сырой запрос.',
			);
		}
		$result = $this->search_repositories( $query, 'updated', 1 );
		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => 'Ошибка запроса: ' . $result['error'],
			);
		}
		return array(
			'ok'      => true,
			'message' => sprintf( 'нашлось %d репозиториев (запрос: %s)', $result['total_count'], $query ),
		);
	}

	// -------------------------------------------------------------- repo data

	public function get_repo( $full_name ) {
		$result = $this->request( '/repos/' . $full_name );
		return ( 200 === $result['code'] ) ? $result['body'] : null;
	}

	public function get_readme( $full_name ) {
		$result = $this->request( '/repos/' . $full_name . '/readme' );
		if ( 200 !== $result['code'] || empty( $result['body']['content'] ) ) {
			return '';
		}
		$decoded = base64_decode( str_replace( "\n", '', $result['body']['content'] ) );
		return false !== $decoded ? $decoded : '';
	}

	/**
	 * The README rendered to HTML by GitHub itself (application/vnd.github.html).
	 *
	 * GitHub resolves relative links/images to absolute URLs and renders fenced
	 * code, inline code, tables, task lists and reference-style links correctly —
	 * everything the plugin's minimal fallback parser cannot. Returns '' when the
	 * repo has no README or the request failed, so the caller can fall back.
	 */
	public function get_readme_html( $full_name ) {
		$result = $this->request(
			'/repos/' . $full_name . '/readme',
			array( 'accept' => 'application/vnd.github.html' )
		);
		if ( 200 !== $result['code'] || ! is_string( $result['body'] ) ) {
			return '';
		}
		return $result['body'];
	}

	/** Number of contributors, via the Link header trick (per_page=1, last page = count). */
	public function get_contributors_count( $full_name ) {
		$result = $this->request( '/repos/' . $full_name . '/contributors', array( 'query' => array( 'per_page' => 1, 'anon' => 'true' ) ) );
		if ( 200 !== $result['code'] ) {
			return null;
		}
		$link = $result['headers']['link'] ?? '';
		if ( $link && preg_match( '/[&?]page=(\d+)>;\s*rel="last"/', $link, $m ) ) {
			return (int) $m[1];
		}
		return is_array( $result['body'] ) ? count( $result['body'] ) : null;
	}

	public function get_latest_release( $full_name ) {
		$result = $this->request( '/repos/' . $full_name . '/releases/latest' );
		if ( 200 !== $result['code'] || ! is_array( $result['body'] ) ) {
			return null;
		}
		return array(
			'tag'  => $result['body']['tag_name'] ?? '',
			'date' => $result['body']['published_at'] ?? '',
		);
	}

	public function get_languages( $full_name ) {
		$result = $this->request( '/repos/' . $full_name . '/languages' );
		return ( 200 === $result['code'] && is_array( $result['body'] ) ) ? $result['body'] : array();
	}

	/** Top-level file/folder names only — not the full codebase. */
	public function get_top_level_contents( $full_name ) {
		$result = $this->request( '/repos/' . $full_name . '/contents/' );
		if ( 200 !== $result['code'] || ! is_array( $result['body'] ) ) {
			return array();
		}
		return array_map(
			function ( $item ) {
				return array(
					'name' => $item['name'] ?? '',
					'type' => $item['type'] ?? '',
				);
			},
			$result['body']
		);
	}
}
