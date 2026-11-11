<?php
/**
 * Decides which proposals only the site owner may approve.
 *
 * Some facts only the owner can vouch for: prices, phone numbers, opening
 * hours, service areas, guarantees and the services offered. When new text
 * ADDS one of these (it was not already in the old text), the proposal is
 * marked "needs_owner" and can only be approved by a logged-in user, never
 * through the API. The service can also mark any proposal this way itself.
 *
 * This is a simple, deliberately cautious word check, not a full review.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Peak_SEO_Owner_Check {

	const US_STATES = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY|DC';

	/**
	 * Category label => list of regular expressions. A match in the new text
	 * that is not also in the old text flags the category.
	 */
	public static function rules() {
		$day = '(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)(?:day|sday|nesday|rsday|urday)?';
		return array(
			'price'        => array(
				'/[$€£]\s?\d[\d,]*(?:\.\d{1,2})?/u',
				'/\b\d[\d,]*(?:\.\d{1,2})?\s?(?:dollars|usd)\b/i',
				'/\b\d{1,3}\s?%\s?off\b/i',
				'/\b(?:free (?:estimates?|quotes?|inspections?)|no charge|flat[- ]rate|discount|coupon)\b/i',
			),
			'phone number' => array(
				'/(?:\+?1[\s.-]?)?\(?\b\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/',
				'/\btel:[+\d]/i',
			),
			'hours'        => array(
				'/\b\d{1,2}(?::\d{2})?\s?(?:am|pm|a\.m\.|p\.m\.)/i',
				'/\b24\s?\/\s?7\b|\b24 hours\b|\bopen (?:daily|weekends|late|now)\b/i',
				'/\b' . $day . '\s?(?:-|–|to|through)\s?' . $day . '\b/i',
				'/\b(?:business|opening|office) hours\b|\bhours of operation\b/i',
			),
			'service area' => array(
				'/\b[A-Z][a-z]+(?:\s[A-Z][a-z]+)*,\s?(?:' . self::US_STATES . ')\b/',
				'/\b(?:serving|we serve|service areas?|areas we serve|proudly serves?|located in|based in)\b/i',
				'/\b[A-Z][a-z]+ County\b/',
			),
			'guarantee'    => array(
				'/\b(?:guarantee[ds]?|warrant(?:y|ies|ied)|money[- ]back|satisfaction|risk[- ]free|lifetime)\b/i',
			),
			'new service'  => array(
				'/\b(?:now offer(?:ing|s)?|we (?:also |now )?offer|new service|now available|introducing|we (?:also |now )?(?:install|repair|provide|specialize in))\b/i',
			),
		);
	}

	/**
	 * Which owner-only facts the new text adds.
	 *
	 * @param string[] $old Current texts (any HTML is ignored).
	 * @param string[] $new Proposed texts.
	 * @return string[] Category labels, e.g. [ 'phone number', 'hours' ].
	 */
	public static function check( $old, $new ) {
		$old_text = self::plain( implode( "\n", (array) $old ) );
		$new_text = self::plain( implode( "\n", (array) $new ) );
		$found    = array();
		foreach ( self::rules() as $label => $patterns ) {
			$before = self::matches( $patterns, $old_text );
			foreach ( array_keys( self::matches( $patterns, $new_text ) ) as $m ) {
				if ( ! isset( $before[ $m ] ) ) {
					$found[] = $label;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Old and new texts for a proposal, before it is stored.
	 *
	 * @param string $kind   Proposal kind.
	 * @param int    $target Target post id (0 for new pages).
	 * @param array  $clean  Cleaned changes.
	 * @return string[] Category labels.
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

	/**
	 * Visible text plus link targets (so a new "tel:" or "mailto:" link counts).
	 */
	private static function plain( $html ) {
		$html  = (string) $html;
		$links = preg_match_all( '/\bhref\s*=\s*["\']([^"\']+)/i', $html, $m ) ? ' ' . implode( ' ', $m[1] ) : '';
		$text  = html_entity_decode( wp_strip_all_tags( str_replace( '>', '> ', $html ) ), ENT_QUOTES, 'UTF-8' );
		return preg_replace( '/\s+/u', ' ', $text . $links );
	}

	/**
	 * Matches as a set, normalized so "(256) 555-0100" equals "256.555.0100".
	 */
	private static function matches( $patterns, $text ) {
		$set = array();
		foreach ( $patterns as $re ) {
			if ( preg_match_all( $re, $text, $m ) ) {
				foreach ( $m[0] as $hit ) {
					$set[ self::normalize( $hit ) ] = true;
				}
			}
		}
		return $set;
	}

	private static function normalize( $hit ) {
		$hit = strtolower( trim( $hit ) );
		$digits = preg_replace( '/\D/', '', $hit );
		if ( strlen( $digits ) >= 10 && preg_match( '/^[\d\s().+-]+$|^tel:/', $hit ) ) {
			return substr( $digits, -10 ); // Phone numbers compare by their last 10 digits.
		}
		return preg_replace( '/\s+/', ' ', $hit );
	}
}
