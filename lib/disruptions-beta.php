<?php

/**
 * PTV Disruptions BETA
 * Scrapes data on current generic line disruptions and posts to Slack.
 *
 * @version 0.0.6
 */

require( __DIR__ . '/functions.php' );
require( __DIR__ . '/functions-disruptions.php' );

// Set options that may not have been set
// NOTE: some of these may not have been implemented yet and are here for future use
if ( ! defined( 'POST_UPDATES' ) ) {
	define( 'POST_UPDATES', true );
}
if ( ! defined( 'POST_GOOD_SERVICE' ) ) {
	define( 'POST_GOOD_SERVICE', true );
}
if ( ! defined( 'INCLUDE_DEBUG_DATA' ) ) {
	define( 'INCLUDE_DEBUG_DATA', false );
}

// Get current line data from main API
$xml = get_cached_xml( $local_file, $url, 'live-status' );

// Get a simple array of train line names (will be in title case)
$lines = array();
$_lines = $xml->xpath( htmlAttribute( 'class', 'titleHolder' ) );
$things_that_arent_train_lines = array( 'Tram', 'Bus', 'V/Line' );
foreach ( $_lines as $line ) {
	$line = (string) $line;
	if ( ! empty ( $line ) && ! in_array( $line, $things_that_arent_train_lines ) ) {
		$lines[] = $line;
	}
}

// Ensure there are no duplicates; this sometimes happens when two alerts are active
$lines = array_unique( $lines );

// If config hasn't specified which lines to check, we're going to assume all of them
if ( ! isset( $lines_to_check ) ) {
	$lines_to_check = $lines;
}

// Get an array of train line disruptions, with line names as the array keys (in title case)

$disruptions = array();
$line_disruptions_xml = $xml->xpath( htmlAttribute( 'class', 'LineInfo' ) );
$line_status = array();

$default_disruption_structure = array (
	'status' => '',
	'info_text' => '',
	'info_id' => 0,
	'info_link' => '',
	'info_link_summary' => '',
	'source' => 'metro',
	'parsed' => array (
		'service_status' => '',
		'delay_type' => '',
		'delay_duration' => '',
		'diversion' => '',
		'reason' =>  '',
		'line_part' =>  '',
		'specific_service' => '',
		'alteration_details' => '',
		'increased_journey_time' => '',
		'directions' => array (
			'inbound' => '',
			'outbound' => '',
		),
	),
);

foreach ( $line_disruptions_xml as $line ) {

	// Line name & status
	$line_name = (string) $line->xpath( htmlAttribute( 'class', 'titleHolder' ) )[0];
	$line_status_text = (string) $line->xpath( htmlAttribute( 'class', 'bubbleType' ) )[0];

	// Should we continue?
	if ( ! in_array( $line_name, $lines_to_check ) ) {
		continue;
	}

	// More info
	$more_info_id = (int) $line->attributes()['data-id'];
	$more_info_data = $xml->xpath( htmlAttribute( 'id', 'article-' . $more_info_id ) );
	$more_info_text = isset( $more_info_data[0] ) ? (string) $more_info_data[0] : '';
	if ( ! $more_info_text && isset( $more_info_data[0]->a ) ) {
		$more_info_text = (string) $more_info_data[0]->a;
	}
	$more_info_text = clean_string( $more_info_text );

	// Link
	$more_info_link = isset( $more_info_data[0]->a ) ? (string) $more_info_data[0]->a->attributes()->href : '';
	if ( $more_info_link ) {
		$more_info_link = $host . $more_info_link;
	}

	// Link summary
	$more_info_link_summary = '';
	if ( $more_info_link && $more_info_id ) {
		$local_link_summary = $local_dir . $more_info_id . '.html';
		$xml_link_summary = get_cached_xml( $local_link_summary, $more_info_link, 'disruption-info', 24 * 60 * 60 );
		$article = $xml_link_summary ? $xml_link_summary->xpath( htmlAttribute( 'class', 'article' ) ) : '';
		$more_info_link_summary = isset( $article[0]->p[1] ) ? strip_tags( (string) $article[0]->p[1] ) : '';
		$more_info_link_summary = preg_replace( '/ on the \w* line/i', '', $more_info_link_summary ); // Remove line mention to save space
		$more_info_link_summary = clean_string( $more_info_link_summary );
	}

	// Service status
	$service_status = str_replace( array ( 'minor', 'major', 'service' ), '', strtolower( $line_status_text ) );
	$service_status = trim( $service_status );
	$service_status = str_replace( ' ', '-', $service_status ); // Replace any leftover spaces with dashes

	// Delay type
	$delay_type = str_replace(
		array ( 'delays', 'good service', 'diversion', 'planned works' ), // Statuses to ignore for delay type
		'',
		strtolower( $line_status_text )
	);
	$delay_type = trim( $delay_type );

	// Delay duration
	$delay_duration = get_delay_duration( $more_info_text );

	// Diversion details
	if ( 'diversion' === $service_status ) {
		preg_match( '/,(.*)/', $more_info_text, $matches );
		$diversion = isset( $matches[1] ) ? $matches[1] : '';
		$diversion = str_replace( $lines, '', $diversion ); // Remove mention of any train lines
		$diversion = trim( $diversion, ' ,.' ); // Remove commas and spaces between line mentions
		$diversion = preg_replace( '/^and/', '', $diversion ); // Clean up
		$diversion = trim( $diversion );
	} else {
		$diversion = false;
	}

	// Adjustments for planned works (if there's no current delays)
	if ( 'planned-works' === $service_status && ! $delay_type && ! $delay_duration ) {
		$delay_type = get_planned_works_type( $more_info_link_summary, $more_info_text );
		$delay_duration = get_planned_works_duration( $more_info_link_summary, $more_info_text );
	}

	// Put it all together
	$disruptions[ $line_name ][] = array_replace_recursive( $default_disruption_structure, array (
		'status' => $line_status_text,
		'info_text' => $more_info_text,
		'info_id' => $more_info_id,
		'info_link' => $more_info_link,
		'info_link_summary' => $more_info_link_summary,
		'source' => 'ptv',
		'parsed' => array (
			'service_status' => $service_status,
			'delay_type' => $delay_type,
			'delay_duration' => $delay_duration,
			'diversion' => $diversion,
			'reason' => get_disruption_reason( $more_info_link_summary, $more_info_text ),
			'line_part' => get_disruption_line_part( $more_info_link_summary, $more_info_text ),
			'increased_journey_time' => get_increased_journey_time( $more_info_link_summary, $more_info_text ),
			'directions' => array (
				'inbound' => is_disruption_inbound( $more_info_text ),
				'outbound' => is_disruption_outbound( $more_info_text, $line_name ),
			),
		),
	));

	// Store overall line status
	$line_status[ $line_name ] = $service_status;

} // Foreach line_disruptions


// Now it's time to post status message(s) to Slack, if necessary!

$message_ids = array();
$message_ids_and_text = array();
$payload = array( 'attachments' => array() );

foreach ( $lines_to_check as $line ) {
	foreach ( $disruptions[ $line ] as $disruption ) {

		// Check whether we need to skip this disruption
		if (
			'good' === $disruption['parsed']['service_status'] || // Good service? No need to post
			! $disruption['info_id'] || // No disruption ID? We can't easily track it, so can't post it
			file_exists( $local_dir . $disruption['info_id'] . '.disruption' ) // Disruption already posted
		) {
			continue;
		}

		// Check whether we have processed this disruption under another line this session, and add this line if so
		if ( in_array( $disruption['info_id'], $message_ids ) ) {
			foreach ( $payload['attachments'] as &$_attachment ) {
				if ( $disruption['info_id'] == $_attachment['_message_id'] ) {
					if ( false === strpos( $_attachment['author_name'], $line ) ) {
						$_attachment['author_name'] .= ', ' . $line;
					}
					continue 2; // We've found a match, so skip the rest & continue our outer foreach loop
				}
			}
		}

		// Each disruption is sent as an attachment

		$attachment = array( 'text' => '' );
		$fields = array();

		if ( 'delays' === $disruption['parsed']['service_status'] ) {

			// ------------ GENERAL DELAYS ------------ //

			$clock_time = time();

			// Attempt to add on half of the estimated duration to our clock emoji time
			// We do half for two reasons: the clock is rounded to the nearest half hour, and the duration is 'up to',
			// so we don't want an 'up to 15 min' delay at 7pm to look like 7:30pm, but it's probably ok a little later
			if ( $disruption['parsed']['delay_duration'] ) {
				$clock_time += $disruption['parsed']['delay_duration'] * 60 / 2;
			}

			$attachment['text'] = get_clock_emoji( $clock_time ) . ' ';

			if ( $disruption['parsed']['delay_type'] ) {
				$attachment['text'] .= ucfirst( $disruption['parsed']['delay_type'] ) . ' delays';
			}

			if ( $disruption['parsed']['line_part'] ) {
				$attachment['text'] .= ' ' . $disruption['parsed']['line_part'] . ',';
			}

			if ( $disruption['parsed']['delay_duration'] ) {
				$attachment['text'] .= ' up to ' . $disruption['parsed']['delay_duration'] . ' minutes';
			}

			if ( $disruption['parsed']['directions'] ) {

				if (
					$disruption['parsed']['directions']['inbound'] &&
					$disruption['parsed']['directions']['outbound']
				) {
					$attachment['text'] .= ' in both directions';
				} elseif ( $disruption['parsed']['directions']['inbound'] ) {
					$attachment['text'] .= ' citybound';
				} elseif ( $disruption['parsed']['directions']['outbound'] ) {
					$attachment['text'] .= ' outbound';
				}

			}

			if ( $disruption['parsed']['reason'] ) {
				$attachment['text'] .= ', due to ' . $disruption['parsed']['reason'];
			}

		} elseif ( 'planned-works' === $disruption['parsed']['service_status'] ) {

			// ------------ PLANNED WORKS ------------ //

			$attachment['title'] = ':construction: Planned Works';

			if ( $disruption['parsed']['delay_type'] ) {
				$attachment['text'] .= '' .
					'*What:* ' .
					str_replace( '-', ' ', emojify( $disruption['parsed']['delay_type'] ) ) .
					(
						$disruption['parsed']['increased_journey_time'] ?
						' (add +' . $disruption['parsed']['increased_journey_time'] . ' minutes to journey times)' :
						''
					) . "\n";
			}

			if ( $disruption['parsed']['line_part'] ) {
				$attachment['text'] .= '*Where:* ' . $disruption['parsed']['line_part'] . "\n";
			}

			if ( $disruption['parsed']['delay_duration'] ) {
				$attachment['text'] .= '*When:* ' . $disruption['parsed']['delay_duration'] . "\n";
			}

			if ( $disruption['parsed']['reason'] ) {
				$_reason = $disruption['parsed']['reason'];

				// Add some emojis to the end of the reason if we can
				if (
					false !== stripos( $_reason, 'freeway' ) ||
					false !== stripos( $_reason, 'citylink' ) ||
					false !== stripos( $_reason, 'tulla' ) ||
					false !== stripos( $_reason, 'widening' )
				) {
					$_reason .= ' :motorway:';
				} elseif (
					false !== stripos( $_reason, 'works' ) ||
					false !== stripos( $_reason, 'maintenance' )
				) {
					$_reason .= ' :hammer_and_wrench:';
				} elseif (
					false !== stripos( $_reason, 'building' ) ||
					false !== stripos( $_reason, 'construction' ) ||
					false !== stripos( $_reason, 'facilities' ) ||
					false !== stripos( $_reason, 'structure' )
				) {
					$_reason .= ' :building_construction:';
				}

				$attachment['text'] .= '*Why:* ' . $_reason . "\n";
			}

		} else {

			// ------------ UNKNOWN DISRUPTION TYPE ------------ //
		
			// We don't recognise this disruption type yet, so let's post the message verbatim
			$attachment['text'] = $disruption['info_text'];

		} // Service status type / else

		// If there's a link available, add it as the last field
		if ( $disruption['info_link'] ) {
			$fields[] = array(
				'value' => '*_<' . $disruption['info_link'] . '|Find out more>_*',
			);
		}

		// Set the attachment colour
		// - Green for good service
		// - Yellow for minor delays
		// - Red for major delays
		// - Blue for planned works
		// - The default (a faded grey) for anything not matched

		if ( 'good' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = 'good';
		} elseif ( 'planned-works' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = '#439FE0'; // A nice blue
		} elseif ( $disruption['parsed']['delay_type'] ) {
			if ( 'minor' === $disruption['parsed']['delay_type'] ) {
				$attachment['color'] = 'warning';
			} elseif ( 'major' === $disruption['parsed']['delay_type'] ) {
				$attachment['color'] = 'danger';
			}

		}

		// Add the affected train lines as the 'author'
		$author_name = $line;
		foreach ( $lines as $_line ) {
			if ( false !== stripos( $disruption['info_text'], $_line ) && false === stripos( $author_name, $_line ) ) {
				$author_name .= ', ' . $_line;
			}
		}
		$attachment['author_name'] = $author_name;
		$attachment['author_icon'] = PUBLIC_URL . '/icons/train-line.png';

		// Set plain text fallback for notifications etc.
		$attachment['fallback'] = (
			'planned-works' === $disruption['parsed']['service_status'] ?
			$disruption['info_text'] :
			$attachment['author_name'] . ': ' .
			( $disruption['info_text'] ? $disruption['info_text'] : $attachment['text'] )
		);

		// Attempt to emojify our text
		if ( $attachment['text'] ) {
			$attachment['text'] = emojify( $attachment['text'] );
		}

		// Make sure markdown is enabled
		$attachment['mrkdwn_in'] = array( 'text', 'fields' );

		// Store our message ID to assist in avoiding duplicates / adding other lines
		$attachment['_message_id'] = $disruption['info_id'];
		$message_ids[] = $disruption['info_id'];
		$message_ids_and_text[] = array( 'id' => $disruption['info_id'], 'text' => $disruption['info_text'] );

		// Add debug information directly to the message if requested
		if ( INCLUDE_DEBUG_DATA ) {

			$fields[] = array(
				'title' => 'Original Text',
				'value' => '_' . $disruption['info_text'] . '_'
			);

			if ( $disruption['info_link_summary'] ) {
				$fields[] = array(
					'title' => 'Original Summary',
					'value' => '_' . $disruption['info_link_summary'] . '_'
				);
			}

			$attachment['footer'] = 'Posted by ' . $disruption['source'];

		}

		// Finally, bring it all together
		if ( count( $fields ) ) { $attachment['fields'] = $fields; }
		$payload['attachments'][] = $attachment;

	} // Foreach line disruption
} // Foreach lines_to_check

// Go ahead, if there were attachments added

if ( count( $payload['attachments'] ) ) {

	// If there's more than one attachment, replace the first attachment fallback with a generic message
	if ( count( $payload['attachments'] ) > 1 ) {
		$payload['attachments'][0]['fallback'] = '' . 
			count( $payload['attachments'] ) . ' disruptions have been posted. See Slack for details.';
	}

	// Send payload to Slack, and print result
	send_to_slack( $payload, $message_ids_and_text );

	// Log all the output and cached files so it can be used for debugging later

	/*
	$log_dir = $local_dir . 'logs/';
	$log_file_prefix = $log_dir . date( 'Ymd-His' );

	if ( ! is_dir( $log_dir ) ) {
		mkdir( $log_dir, 0777, true );
	}

	foreach ( $raw_logs as $raw_log ) {
		$_log = fopen( $log_file_prefix . '-' . basename( $raw_log['filename'] ), 'wb' );
		fwrite( $_log, $raw_log['raw'] );
		fclose( $_log );
	}

	$_log = fopen( $log_file_prefix . '.log', 'wb' );
	fwrite( $_log, print_r( $cache_status, true ) );
	fwrite( $_log, print_r( $line_status, true ) );
	fwrite( $_log, print_r( $disruptions, true ) );
	fwrite( $_log, print_r( $html_parsing_errors, true ) );
	fwrite( $_log, print_r( $message_ids, true ) );
	fwrite( $_log, print_r( $message_ids_and_text, true ) );
	fwrite( $_log, print_r( $payload, true ) );
	fclose( $_log );
	*/

} // If attachments

// Store overall line status so we can track return to normal service (when that is implemented)
foreach ( $line_status as $line => $status ) {
	file_put_contents( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status', $status );
}

// Output debugging data now
preint( $line_status );
preint( $disruptions );
preint( $message_ids );
preint( $message_ids_and_text );
preint( $payload );

// Print any HTML parsing errors encountered earlier
if ( $html_parsing_errors ) {
	vecho( '' . 
		'The following errors were encountered processing the HTML from the scraped document. If anything ' .
		'is wrong, this might help.<br />' .
		'Otherwise, PTV may have changed HTML class names or structure.'
	);
	vecho( '<ul>' );
	foreach( $html_parsing_errors as $error ) {
		vecho( '<li>' . $error . '</li>' );
	}
	vecho( '</ul>' );
} else {
	vecho( 'No HTML parsing errors occured.' );
}

// The end!
