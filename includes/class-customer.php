<?php
/**
 * Customer storage.
 *
 * @package WP_RapidRescue_Chat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles customer records.
 */
class WP_RapidRescue_Chat_Customer {

	/**
	 * Get customers table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'rr_customers';
	}

	/**
	 * Create or update the customer database table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = self::table();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL DEFAULT '',
			email varchar(191) NOT NULL DEFAULT '',
			site_url varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create a customer.
	 *
	 * @param string $name     Customer name.
	 * @param string $email    Customer email.
	 * @param string $site_url Customer website URL.
	 * @return int|WP_Error
	 */
	public static function create(
		$name = '',
		$email = '',
		$site_url = ''
	) {
		global $wpdb;

		$name     = sanitize_text_field( $name );
		$email    = sanitize_email( $email );
		$site_url = self::normalize_site_url( $site_url );

		$now = current_time( 'mysql', true );

		$result = $wpdb->insert(
			self::table(),
			array(
				'name'       => $name,
				'email'      => $email,
				'site_url'   => $site_url,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'customer_create_failed',
				'The customer could not be created.'
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a customer by ID.
	 *
	 * @param int $customer_id Customer ID.
	 * @return object|null
	 */
	public static function get_by_id( $customer_id ) {
		global $wpdb;

		$customer_id = absint( $customer_id );

		if ( $customer_id < 1 ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE id = %d
				LIMIT 1",
				$customer_id
			)
		);
	}

	/**
	 * Find a customer by email address.
	 *
	 * @param string $email Customer email.
	 * @return object|null
	 */
	public static function get_by_email( $email ) {
		global $wpdb;

		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return null;
		}

		$table = self::table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM {$table}
				WHERE email = %s
				LIMIT 1",
				$email
			)
		);
	}

	/**
	 * Update a customer.
	 *
	 * Only supplied non-empty values are changed.
	 *
	 * @param int    $customer_id Customer ID.
	 * @param string $name        Customer name.
	 * @param string $email       Customer email.
	 * @param string $site_url    Customer website URL.
	 * @return bool|WP_Error
	 */
	public static function update(
		$customer_id,
		$name = '',
		$email = '',
		$site_url = ''
	) {
		global $wpdb;

		$customer_id = absint( $customer_id );

		if ( $customer_id < 1 ) {
			return new WP_Error(
				'invalid_customer',
				'Invalid customer.'
			);
		}

		$customer = self::get_by_id( $customer_id );

		if ( ! $customer ) {
			return new WP_Error(
				'customer_not_found',
				'Customer not found.'
			);
		}

		$data    = array();
		$formats = array();

		$name = sanitize_text_field( $name );

		if ( '' !== $name ) {
			$data['name'] = $name;
			$formats[]    = '%s';
		}

		$email = sanitize_email( $email );

		if ( '' !== $email ) {
			$data['email'] = $email;
			$formats[]     = '%s';
		}

		$site_url = self::normalize_site_url( $site_url );

		if ( '' !== $site_url ) {
			$data['site_url'] = $site_url;
			$formats[]        = '%s';
		}

		if ( empty( $data ) ) {
			return true;
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$formats[]          = '%s';

		$result = $wpdb->update(
			self::table(),
			$data,
			array(
				'id' => $customer_id,
			),
			$formats,
			array(
				'%d',
			)
		);

		if ( false === $result ) {
			return new WP_Error(
				'customer_update_failed',
				'The customer could not be updated.'
			);
		}

		return true;
	}

	/**
	 * Extract identity information from a customer message.
	 *
	 * @param string $message Customer message.
	 * @return array
	 */
	public static function extract_identity( $message ) {
		$message = sanitize_textarea_field( $message );

		$identity = array(
			'name'     => '',
			'email'    => '',
			'site_url' => '',
		);

		if ( '' === trim( $message ) ) {
			return $identity;
		}

		/*
		 * Email address.
		 */
		if (
			preg_match(
				'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
				$message,
				$matches
			)
		) {
			$email = sanitize_email( $matches[0] );

			if ( '' !== $email ) {
				$identity['email'] = $email;
			}
		}

		/*
		 * Explicit HTTP/HTTPS URL.
		 */
		if (
			preg_match(
				'~https?://[^\s<>"\']+~i',
				$message,
				$matches
			)
		) {
			$url = self::normalize_site_url(
				rtrim(
					$matches[0],
					'.,!?;:)'
				)
			);

			if ( '' !== $url ) {
				$identity['site_url'] = $url;
			}
		} elseif (
			/*
			 * Bare domains such as:
			 * mywebsite.com
			 * www.mywebsite.com
			 */
			preg_match(
				'~(?<![@\w-])((?:www\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.[a-z]{2,}(?:/[^\s<>"\']*)?)~i',
				$message,
				$matches
			)
		) {
			$url = self::normalize_site_url(
				rtrim(
					$matches[1],
					'.,!?;:)'
				)
			);

			if ( '' !== $url ) {
				$identity['site_url'] = $url;
			}
		}

		/*
		 * Name.
		 *
		 * Only accept common explicit introductions.
		 */
		if (
			preg_match(
				'/\b(?:my\s+name\s+is|i\s+am|i\'m)\s+([A-Za-z][A-Za-z .\'-]{1,80})/i',
				$message,
				$matches
			)
		) {
			$name = trim(
				preg_replace(
					'/\s+/',
					' ',
					$matches[1]
				)
			);

			$name = preg_replace(
				'/[.,!?;:]+$/',
				'',
				$name
			);

			/*
			 * Stop common conversational continuations from becoming
			 * part of the customer's name.
			 */
			$name = preg_split(
				'/\b(?:and|but|because|since|from|with|who|that|i)\b/i',
				$name
			);

			$name = isset( $name[0] )
				? trim( $name[0] )
				: '';

			$name = sanitize_text_field( $name );

			if ( '' !== $name ) {
				$identity['name'] = $name;
			}
		}

		return $identity;
	}

	/**
	 * Find or create a customer using confirmed identity information.
	 *
	 * Email is required before a customer record is created.
	 *
	 * @param array $identity Identity information.
	 * @return int|null|WP_Error
	 */
	public static function find_or_create_from_identity( $identity ) {
		if ( ! is_array( $identity ) ) {
			return null;
		}

		$name = isset( $identity['name'] )
			? sanitize_text_field( $identity['name'] )
			: '';

		$email = isset( $identity['email'] )
			? sanitize_email( $identity['email'] )
			: '';

		$site_url = isset( $identity['site_url'] )
			? self::normalize_site_url( $identity['site_url'] )
			: '';

		/*
		 * Do not create a customer from a name or website alone.
		 */
		if ( '' === $email ) {
			return null;
		}

		$customer = self::get_by_email( $email );

		if ( $customer ) {
			$updated = self::update(
				$customer->id,
				$name,
				$email,
				$site_url
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			return (int) $customer->id;
		}

		return self::create(
			$name,
			$email,
			$site_url
		);
	}

	/**
	 * Normalize a customer website URL.
	 *
	 * @param string $url Website URL.
	 * @return string
	 */
	private static function normalize_site_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		/*
		 * Add HTTPS to bare domains.
		 */
		if (
			! preg_match(
				'~^[a-z][a-z0-9+\-.]*://~i',
				$url
			)
		) {
			$url = 'https://' . $url;
		}

		return esc_url_raw( $url );
	}
} 