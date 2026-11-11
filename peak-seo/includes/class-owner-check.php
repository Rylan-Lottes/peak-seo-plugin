<?php
/**
 * Decides which proposals only the site owner may approve.
 *
 * Some facts only the owner can vouch for: prices, phone numbers, opening
 * hours, service areas, guarantees and the services offered. When new text
 * ADDS one of these, the proposal is marked "needs_owner" and can only be
 * approved by a logged-in user, never through the API. The service can also
 * mark any proposal this way itself.
 *
 * A fact does not count as new when it is already in the old text, or when
 * the owner has listed it under Peak SEO → Settings → Approved facts
 * (service areas, services, phone numbers, hours). Prices and guarantees are
 * always the owner's call.
 *
 * This is a simple, deliberately cautious word check, not a full review.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Owner_Check {

	const OPT_FACTS = 'peak_seo_facts';
	const FACT_KEYS = array( 'service_areas', 'services', 'phones', 'hours' );

	const US_STATES = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY|DC';

	const STATE_NAMES = array( 'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia', 'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland', 'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey', 'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina', 'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming' );

	const AREA_CUES = '(?:serving|we serve|service areas?:?|areas we serve:?|proudly serves?|located in|based in|covering(?: all of)?)';

	const SERVICE_CUES = '(?:now offer(?:ing|s)?|we (?:also |now )?offer|new services?:?|now available:?|introducing|we (?:also |now )?(?:install|repair|provide|specialize in))';

	/* ------------------------------------------------------------------ */
	/* Approved facts (owner-controlled)                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * The owner's approved facts: { service_areas: [], services: [], phones: [], hours: [] }.
	 */
	public static function approved_facts() {
		$raw = get_option( self::OPT_FACTS, array() );
		return self::clean_facts( is_array( $raw ) ? $raw : array() );
	}

	public static function clean_facts( $facts ) {
		$out = array();
		foreach ( self::FACT_KEYS as $k ) {
			$list = isset( $facts[ $k ] ) ? $facts[ $k ] : array();
			if ( is_string( $list ) ) {
				$list = preg_split( '/\r\n|\r|\n/', $list );
			}
			$clean = array();
			foreach ( (array) $list as $item ) {
				$item = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $item ) ) );
				if ( '' !== $item && strlen( $item ) <= 200 ) {
					$clean[] = $item;
				}
			}
			$out[ $k ] = array_values( array_unique( array_slice( $clean, 0, 100 ) ) );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The check                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Owner-only facts the new text adds.
	 *
	 * @param string[]   $old   Current texts (HTML is ignored, link targets count).
	 * @param string[]   $new   Proposed texts.
	 * @param array|null $facts Approved facts; null reads them from Settings.
	 * @return string[] e.g. [ 'phone number: 256-555-0199', 'service area: Decatur', 'price' ].
	 */
	public static function check( $old, $new, $facts = null ) {
		$facts    = self::clean_facts( null === $facts ? self::approved_facts() : $facts );
		$old_text = self::plain( implode( "\n", (array) $old ) );
		$new_text = self::plain( implode( "\n", (array) $new ) );
		$found    = array();

		// Prices and guarantees: always the owner's call when new.
		foreach ( array( 'price' => self::price_rules(), 'guarantee' => self::guarantee_rules() ) as $label => $rules ) {
			if ( array_diff_key( self::matches( $rules, $new_text ), self::matches( $rules, $old_text ) ) ) {
				$found[] = $label;
			}
		}

		// Phone numbers.
		$approved_phones = array();
		foreach ( $facts['phones'] as $p ) {
			$approved_phones[ self::phone_key( $p ) ] = true;
		}
		$new_phones = array_diff_key( self::phones( $new_text ), self::phones( $old_text ), $approved_phones );
		foreach ( $new_phones as $shown ) {
			$found[] = 'phone number: ' . $shown;
		}

		// Hours.
		$skip_labels = ! empty( $facts['hours'] );
		$hours       = array_diff_key( self::hours( $new_text, $skip_labels ), self::hours( $old_text, $skip_labels ) );
		foreach ( $hours as $key => $shown ) {
			if ( ! self::hours_approved( $key, $facts['hours'] ) ) {
				$found[] = 'hours: ' . $shown;
			}
		}

		// Service areas.
		$old_areas = self::areas( $old_text );
		foreach ( self::areas( $new_text ) as $key => $shown ) {
			if ( isset( $old_areas[ $key ] ) || self::area_approved( $key, $facts['service_areas'] ) ) {
				continue;
			}
			$found[] = 'service area: ' . $shown;
		}

		// Services.
		$old_services = self::services( $old_text );
		foreach ( self::services( $new_text ) as $key => $phrase ) {
			if ( isset( $old_services[ $key ] ) || self::service_approved( $phrase, $facts['services'] ) ) {
				continue;
			}
			$found[] = 'new service: ' . $phrase;
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Old and new texts for a proposal, before it is stored.
	 */
	public static function check_proposal( $kind, $target, $clean ) {
		$old = array();
		$new = array();
		if ( 'create_page' === $kind ) {
			$new = array( $clean['title'], $clean['content'] );
		} elseif ( 'update_seo' === $kind ) {
			$seo = Peak_SEO_Fields::get( $target );
			foreach ( array( 'seo_title', 'seo_description' ) as $k ) {
				if ( isset( $clean[ $k ] ) ) {
					$old[] = $seo[ $k ];
					$new[] = $clean[ $k ];
				}
			}
		} else {
			$post = get_post( $target );
			$map  = array(
				'title'   => 'post_title',
				'content' => 'post_content',
				'excerpt' => 'post_excerpt',
			);
			foreach ( $map as $k => $field ) {
				if ( isset( $clean[ $k ] ) ) {
					$old[] = $post ? $post->$field : '';
					$new[] = $clean[ $k ];
				}
			}
			if ( ! empty( $clean['elementor'] ) ) {
				foreach ( $clean['elementor'] as $e ) {
					$old[] = $e['before'];
					$new[] = $e['text'];
				}
			}
		}
		return self::check( $old, $new );
	}

	/* ------------------------------------------------------------------ */
	/* Finders. Each returns [ normalized key => text to show ].           */
	/* ------------------------------------------------------------------ */

	private static function price_rules() {
		return array(
			'/[$€£]\s?\d[\d,]*(?:\.\d{1,2})?/u',
			'/\b\d[\d,]*(?:\.\d{1,2})?\s?(?:dollars|usd)\b/i',
			'/\b\d{1,3}\s?%\s?off\b/i',
			'/\b(?:free (?:estimates?|quotes?|inspections?)|no charge|flat[- ]rate|discount|coupon)\b/i',
		);
	}

	private static function guarantee_rules() {
		return array( '/\b(?:guarantee[ds]?|warrant(?:y|ies|ied)|money[- ]back|satisfaction|risk[- ]free|lifetime)\b/i' );
	}

	private static function phones( $text ) {
		$out = array();
		$res = array(
			'/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/',
			'/\btel:\+?[\d\s().-]{7,}/i',
		);
		foreach ( $res as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[0] as $hit ) {
					$out[ self::phone_key( $hit ) ] = trim( preg_replace( '/^tel:/i', '', $hit ) );
				}
			}
		}
		return $out;
	}

	private static function phone_key( $s ) {
		return substr( preg_replace( '/\D/', '', $s ), -10 );
	}

	/**
	 * @param bool $skip_labels Ignore bare labels like "business hours" (used
	 *                          when the owner has approved hours).
	 */
	private static function hours( $text, $skip_labels ) {
		$day = '(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(?:day|sday|nesday|rsday|urday)?';
		$res = array(
			'/\b\d{1,2}(?::\d{2})?\s?(?:am|pm|a\.m\.|p\.m\.)/i',
			'/\b24\s?\/\s?7\b|\b24 hours\b|\bopen (?:daily|weekends|late|now)\b/i',
			'/\b' . $day . '\s?(?:-|–|to|through)\s?' . $day . '\b/i',
		);
		if ( ! $skip_labels ) {
			$res[] = '/\b(?:business|opening|office) hours\b|\bhours of operation\b/i';
		}
		$out = array();
		foreach ( $res as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[0] as $hit ) {
					$out[ self::hours_key( $hit ) ] = trim( $hit );
				}
			}
		}
		return $out;
	}

	private static function hours_key( $s ) {
		$s = strtolower( str_replace( array( '–', '.' ), array( '-', '' ), $s ) );
		return preg_replace( '/\s+/', '', $s );
	}

	private static function hours_approved( $key, $approved ) {
		foreach ( $approved as $line ) {
			if ( '' !== $key && false !== strpos( self::hours_key( $line ), $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Places named in the text: "City, ST", "X County", and lists after cue
	 * words ("serving Madison and Decatur"). A cue with no recognizable place
	 * ("serving the whole valley") is returned as the cue itself.
	 */
	private static function areas( $text ) {
		$out  = array();
		$name = '[A-Z][a-z]+(?:\s[A-Z][a-z]+)*';

		if ( preg_match_all( '/\b(' . $name . '),\s?(?:' . self::US_STATES . ')\b/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$out[ self::area_key( $hit[1] ) ] = trim( $hit[0] );
			}
		}
		if ( preg_match_all( '/\b' . $name . ' County\b/', $text, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$out[ self::area_key( $hit ) ] = $hit;
			}
		}
		$cs = '(?-i:' . $name . ')'; // Names stay case-sensitive inside the /i cue rule.
		if ( preg_match_all( '/\b(' . self::AREA_CUES . ')\s+(?:the\s+)?(' . $cs . '(?:\s*(?:,|and|&|or)\s*' . $cs . ')*)?/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$list = isset( $hit[2] ) ? trim( $hit[2] ) : '';
				$got  = false;
				foreach ( preg_split( '/\s*(?:,|\band\b|&|\bor\b)\s*/', $list ) as $place ) {
					$place = trim( preg_replace( '/,?\s?\b(?:' . self::US_STATES . ')$/', '', $place ) );
					$key   = self::area_key( $place );
					// The /i flag lets the list grab ordinary words; keep only capitalized names.
					if ( '' === $key || in_array( $key, self::STATE_NAMES, true ) || ! preg_match( '/^[A-Z]/', $place ) ) {
						continue;
					}
					$out[ $key ] = $place;
					$got         = true;
				}
				if ( ! $got ) {
					$cue                  = strtolower( trim( $hit[1] ) );
					$out[ 'cue:' . $cue ] = '"' . $cue . '" (no place named)';
				}
			}
		}
		return $out;
	}

	private static function area_key( $s ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', $s ) ) );
	}

	/**
	 * "Plumbing Huntsville" (a title-case run caught by the City, ST rule) is
	 * approved by "Huntsville, AL": the approved city only has to end the name.
	 */
	private static function area_approved( $key, $approved ) {
		if ( 0 === strpos( $key, 'cue:' ) ) {
			return false;
		}
		foreach ( $approved as $a ) {
			$a = self::area_key( preg_replace( '/,\s?(?:' . self::US_STATES . ')$/i', '', $a ) );
			if ( '' !== $a && ( $key === $a || substr( $key, -strlen( ' ' . $a ) ) === ' ' . $a ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Phrases introducing a service: "we now offer tankless water heater installation".
	 */
	private static function services( $text ) {
		$out = array();
		if ( preg_match_all( '/\b(' . self::SERVICE_CUES . ')\s*([^.!?\n]{0,80})/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$phrase                           = trim( $hit[1] . ' ' . $hit[2] );
				$out[ self::area_key( $phrase ) ] = $phrase;
			}
		}
		return $out;
	}

	/**
	 * A phrase is approved when every main word of an approved service is in
	 * it ("we repair water heaters" matches "Water heater repair").
	 */
	private static function service_approved( $phrase, $approved ) {
		$have = self::words( $phrase );
		foreach ( $approved as $service ) {
			$need = self::words( $service );
			if ( $need && ! array_diff( $need, $have ) ) {
				return true;
			}
		}
		return false;
	}

	private static function words( $s ) {
		$stop = array( 'and', 'the', 'for', 'our', 'your', 'with', 'all', 'now', 'also', 'new', 'offer', 'offers', 'offering', 'introducing', 'available', 'services', 'service', 'provide', 'specialize' );
		$out  = array();
		foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $s ) ) as $w ) {
			if ( strlen( $w ) < 3 || in_array( $w, $stop, true ) ) {
				continue;
			}
			$out[] = preg_replace( '/ies$/', 'y', preg_replace( '/(?<=[a-z]{3})s$/', '', $w ) );
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Visible text plus link targets (so a new "tel:" link counts).
	 */
	private static function plain( $html ) {
		$html  = (string) $html;
		$links = preg_match_all( '/\bhref\s*=\s*["\']([^"\']+)/i', $html, $m ) ? ' ' . implode( ' ', $m[1] ) : '';
		$text  = html_entity_decode( wp_strip_all_tags( str_replace( '>', '> ', $html ) ), ENT_QUOTES, 'UTF-8' );
		return preg_replace( '/\s+/u', ' ', $text . $links );
	}

	private static function matches( $patterns, $text ) {
		$set = array();
		foreach ( $patterns as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[0] as $hit ) {
					$set[ strtolower( preg_replace( '/\s+/', ' ', trim( $hit ) ) ) ] = true;
				}
			}
		}
		return $set;
	}
}
