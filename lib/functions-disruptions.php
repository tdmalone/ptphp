<?php

/**
 * Helper functions and routines for ptphp disruptions.
 */

/**
 * Searches through a main and a backup text for the reason for a disruption.
 */
function get_disruption_reason( $primary_text, $secondary_text = '' ) {

	$reason = '';

	// Add a backup comma since we'll be specifically matching before it below
	$primary_text = trim( $primary_text ) . ',';
	$secondary_text = trim( $secondary_text ) . ',';

	if ( $primary_text ) {
		preg_match( '/(?:due to|while we recover from)(.*?)[,|\.]/i', $primary_text, $matches );
		$reason = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $reason && $secondary_text ) {
		preg_match( '/(?:due to|while we recover from)(.*?)[,|\.]/i', $secondary_text, $matches );
		$reason = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $reason && $primary_text ) {
		preg_match( '/while (.*?) take place (.*?)[,|\.]/i', $primary_text, $matches );
		$reason = isset( $matches[1] ) && isset( $matches[2] ) ? trim( $matches[1] . ' ' . $matches[2] ) : '';
	}

	if ( ! $reason && $secondary_text ) {
		preg_match( '/while (.*?) take place (.*?)[,|\.]/i', $secondary_text, $matches );
		$reason = isset( $matches[1] ) && isset( $matches[2] ) ? trim( $matches[1] . ' ' . $matches[2] ) : '';
	}

	if ( ! $reason && $primary_text ) {
		preg_match( '/after (?:an )?(earlier .*?)[,|\.]/i', $primary_text, $matches );
		$reason = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	// If the word and is included in the reason.. we probably need to cut off what's after it, like the comma above
	// This might be tough though.. it has failed twice now, so currently commented out
	if ( preg_match( '/\band\b/i', $reason ) ) {
		//$reason = substr( $reason, 0, strpos( $reason, ' and' ) );
	}

	// If the word 'due to' is included in the reason.. we probably need to cut off what's after it, and rely on a
	// shorter reason. We probably don't really need the end result to be 'due to this due to something else'.
	if ( preg_match( '/\bdue to\b/i', $reason ) ) {
		$reason = substr( $reason, 0, strpos( $reason, ' due to' ) );
	}

	$reason = preg_replace( '/^(an |a )/', '', $reason );

	return trim( $reason, ' .' );

} // Function get_disruption_reason

/**
 * Attempts to return an integer being the number of minutes a service has been delayed.
 */
function get_delay_duration( $text ) {
	preg_match( '/ (\d+) min/', $text, $matches ); // Find something refering to minutes
	$delay_duration = isset( $matches[1] ) ? (int) $matches[1] : '';
	return $delay_duration;
}

function get_cancellation_details( $text ) {
	preg_match( '/(.*? (will not run today|has been cancelled).*)/i', $text, $matches );
	$cancellation = isset( $matches[1] ) ? $matches[1] : '';
	$cancellation = preg_replace( array( '/ today\b/i', '/ tonight\b/i', '/ service\b/i' ), '', $cancellation );
	$cancellation = trim( $cancellation, ' .' );
	return $cancellation;
}

function get_reinstation_details( $text ) {
	preg_match( '/(.*? (will now run|will run now|now resuming))/i', $text, $matches );
	$reinstation = isset( $matches[1] ) ? $matches[1] : '';
	$reinstation = preg_replace( array( '/ today\b/i', '/ tonight\b/i', '/ service\b/i' ), '', $reinstation );
	$reinstation = trim( $reinstation, ' .' );
	return $reinstation;
}

function get_alteration_details( $text ) {

	// Add a backup 'due' to the end of the text since we're matching before it if it is there
	$text = $text . ' due';

	preg_match(
		'/(.*? (has been altered to|has been altered and|will originate at|will originate from|will terminate at|will be substituted by|will run direct) (.*?)(\.| due))/i',
		$text, $matches
	);
	$alteration = isset( $matches[2] ) && isset( $matches[3] ) ? $matches[2] . ' ' . $matches[3] : '';

	// Cleanups
	$alteration = preg_replace( array( '/ today\b/i', '/ tonight\b/i', '/ service\b/i' ), '', $alteration );
	$alteration = trim( $alteration, ' .' );

	return $alteration;
}

function get_specific_service( $text ) {

	preg_match( '/(.*?) \b(has been|will originate|will terminate|will not run|will be|will run|will now run|is currently being|is being)\b .*/i', $text, $matches );
	$specific_service = isset( $matches[1] ) ? $matches[1] : '';

	$specific_service = preg_replace( array( '/^the\b/i', '/ service\b/i' ), '', $specific_service );
	$specific_service = trim( $specific_service );

	// Make sure we didn't catch some other long string - one way to check this is by looking for a comma, but this
	// is obviously far from perfect.
	if ( false !== strpos( $specific_service, ',' ) ) {
		$specific_service = '';
	}

	return $specific_service;

}

/**
 * Searches through a main and a backup text for the duration of planned works.
 */
function get_planned_works_duration( $primary_text, $secondary_text = '' ) {

	$works_duration = '';

	// Find something refering to a length of time

	if ( $primary_text ) {
		preg_match( '/ ((from|on|after) ([^,]*) (to|until) (.*))/', $primary_text, $matches );
		$works_duration = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $works_duration && $secondary_text ) {
		preg_match( '/ ((from|on|after) ([^,].*) (to|until) (.*))/', $secondary_text, $matches );
		$works_duration = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	// Make sure we didn't catch some text that speaks about disruptions '(on) a line due (to)'
	if ( false !== strpos( $works_duration, 'due to' ) ) {
		$works_duration = '';
	}

	if ( ! $works_duration && $primary_text ) {
		preg_match( '/ (through to .*?)\./', $primary_text, $matches );
		$works_duration = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $works_duration && $secondary_text ) {
		preg_match( '/ (through to .*?\.)/', $secondary_text, $matches );
		$works_duration = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	// An alternative might be to try to capture times being referred to with 'between'
	if ( ! $works_duration && $primary_text ) {
		preg_match( '/(between (\d.*) and (\d.*)),/i', $primary_text, $matches );
		$works_duration = isset( $matches[1] ) ? trim( lcfirst( $matches[1] ) ) : '';
	}

	// Another alternative might be just a starting time, assuming the end time is the end of the day
	// This should match 'after 8:15pm' as well as 'after 8pm', 'after 11.15pm', etc.
	if ( ! $works_duration && $primary_text ) {
		preg_match(
			'/((tonight |today |this evening |this morning )?after \d\d?\w?\w?:?\.?\d?\d?\w?\w?( tonight| today| this)?)/i',
			$primary_text,
			$matches
		);
		$works_duration = isset( $matches[1] ) ? trim( lcfirst( $matches[1] ) ) : '';
	}

	// Cleanups
	$works_duration = preg_replace( '/\buntil\b/', 'to', $works_duration );
	$works_duration = preg_replace( '/^(on )/', '', $works_duration ); // Remove 'on' from the start

	// Add a comma to separate what is usually a long string

	$works_duration = preg_replace(
		'/^(after|from) (.*) from (.*) to (.*)$/', '$1 $2, from $3 to $4',
		$works_duration,
		-1,
		$count
	);
	
	// If we didn't add a comma in the first try (and the string doesn't start with 'through to'), try this way instead
	if ( ! $count && 'through to' !== substr( $works_duration, 0, 10 ) ) {
		$works_duration = preg_replace( '/^(.*) to (.*)$/', '$1, to $2', $works_duration );
	}

	// Cut us off at the end of a sentence (a full stop with a space after it)
	$works_duration = preg_replace( '/(.*?)\b\.\s.*/', '$1', $works_duration );

	return $works_duration;

} // Function get_planned_works_duration

/**
 * Searches through a main and a backup text for the type of planned works.
 * NOTE that the backup text works a bit differently in this function than it does in others.
 */
function get_planned_works_type( $primary_text, $secondary_text = '' ) {

	global $lines;
	$delay_type = '';

	if ( $primary_text ) {
		preg_match( '/(?:due to|while) .*?, (.*?) (between|from|on|after)/i', $primary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $delay_type && $secondary_text ) {
		preg_match( '/(?:due to|while) .*?, (.*?) (between|from|on|after)/i', $secondary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $delay_type && $primary_text ) {
		preg_match( '/(.*?) (from|on|after)/i', $primary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $delay_type && $secondary_text ) {
		preg_match( '/(.*?) (from|on|after)/i', $secondary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $delay_type && $primary_text ) {
		preg_match( '/there will be (.*?) (between|from|on|after)/i', $primary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	if ( ! $delay_type && $secondary_text ) {
		preg_match( '/there will be (.*?) (between|from|on|after)/i', $secondary_text, $matches );
		$delay_type = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	// Cleanups
	$delay_type = str_replace( $lines, '', $delay_type ); // Remove mention of any train lines
	$delay_type = trim( $delay_type, ' ,.' ); // Remove commas and spaces between line mentions
	$delay_type = preg_replace( '/^and/', '', $delay_type ); // Clean up
	$delay_type = trim( $delay_type, ' ,.:' ); // Remove extra chars again
	$delay_type = strtolower( $delay_type ); // To lowercase
	$delay_type = str_replace( // Back to uppercase for the terms we know get mentioned in here sometimes
		array( 'flinders street', 'flinders st', 'city loop' ),
		array( 'Flinders Street', 'Flinders St', 'City Loop' ),
		$delay_type
	);
	$delay_type = str_replace( ' ', '-', $delay_type ); // Replace any leftover spaces with dashes
	$delay_type = preg_replace( // Standardise language
		array( '/buses-will-replace-trains.*/', '/.*timetable-alterations/' ),
		array( 'buses-replacing-trains', 'timetable-alterations' ),
		$delay_type
	);

	// If there's a comma, there must've been a long reason, so we'll have to drop that part of the string
	if ( false !== strpos( $delay_type, ',' ) ) {
		$delay_type = substr( $delay_type, strpos( $delay_type, ',') + 2 );
	}

	return $delay_type;

} // Function get_planned_works_type

/**
 * Searches through a main and a backup text for the part of a line that is affected by a disruption.
 */
function get_disruption_line_part( $primary_text, $secondary_text = '' ) {

	$line_part = '';

	$matches = preg_match_double(
		'/(between ([a-z\s]*) and ([a-z\s]*?))(,| from| on| after| through to| due to| tonight| today)/i',
		$primary_text,
		$secondary_text
	);

	$line_part = isset( $matches[1] ) ? trim( $matches[1] ) : '';

	if ( ! $line_part ) {
		$matches = preg_match_double( '/(in the (.*) area)/i', $primary_text, $secondary_text );
		$line_part = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	// If we still have nothing, let's try just matching 'between x and x' without requiring words after it
	// It could, after all, be at the end of the sentence

	if ( ! $line_part ) {
		$matches = preg_match_double( '/(between ([a-z\s]*) and ([a-z\s]*\b))/i', $primary_text, $secondary_text );
		$line_part = isset( $matches[1] ) ? trim( $matches[1] ) : '';
	}

	return $line_part;

} // Function get_disruption_line_part

/**
 * Attempts to return an integer being the number of minutes journey times will be increased by during planned works.
 */
function get_increased_journey_time( $primary_text, $secondary_text = '' ) {

	$increased_journey_time = '';

	// Add a backup comma since we'll be testing for it below
	$primary_text = trim( $primary_text ) . ',';
	$secondary_text = trim( $secondary_text ) . ',';

	if ( $primary_text ) {
		preg_match( '/increase (?:.*?) journey (?:.*?) (\d+) min(?:.*?),/i', $primary_text, $matches );
		$increased_journey_time = isset( $matches[1] ) ? (int) $matches[1] : '';
	}

	if ( ! $increased_journey_time && $secondary_text ) {
		preg_match( '/increase (?:.*?) journey (?:.*?) (\d+) min(?:.*?),/i', $secondary_text, $matches );
		$increased_journey_time = isset( $matches[1] ) ? (int) $matches[1] : '';
	}

	return $increased_journey_time;

}

/**
 * Returns whether a disruption affects inbound services.
 * Note that false doesn't necessarily mean it doesn't, just that the information can't be determined.
 *
 * Use in conjunction with is_disruption_outbound()
 */
function is_disruption_inbound( $text ) {

	if ( false !== stripos( $text, 'both directions' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'to city' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'to the city' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'to flinders st' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'to parliament' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'citybound' ) ) {
		return true;
	}  else {
		return false;
	}

} // Function is_disruption_inbound

/**
 * Returns whether a disruption affects outbound services.
 * Note that false doesn't necessarily mean it doesn't, just that the information can't be determined.
 *
 * Use in conjunction with is_disruption_inbound()
 */
function is_disruption_outbound( $text, $line_name ) {

	if ( false !== stripos( $text, 'both directions' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'to ' . $line_name ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'flinders street to' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'flinders st to' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'parliament to' ) ) {
		return true;
	} elseif ( false !== stripos( $text, 'outbound' ) ) {
		return true;
	} else {
		return false;
	}

} // Function is_disruption_outbound

/**
 * Attempts to return whether disruption text is actually a return to good service.
 */
function is_good_service( $text ) {

	if ( preg_match( '/(are now resuming|delays are now over|has now moved)/i', $text ) ) {
		return true;
	} else {
		return false;
	}

}

// The end!
