<?php

/**
 * PTV Disruptions ALPHA
 * Scrapes data on current generic line disruptions and posts to Slack.
 *
 * @version 0.0.7-alpha
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

// Get current line data from main API
$xml = get_cached_xml( $local_file, $url, 'live-status' );

// Get additional operator data if available
$operator_json = (
	isset( $operator_endpoints['train'] ) ?
	get_cached_json(
		str_replace( '.html', '.json', $local_file ),
		$operator_endpoints['train'],
		'live-status-operator'
	) :
	''
);

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

// Initialise our Slack payload so we're ready to add to it
$payload = array( 'attachments' => array() );
$message_ids = array();
$message_ids_and_text = array();

// Get an array of train line disruptions, with line names as the array keys (in title case)

$disruptions = array();
$line_disruptions_xml = $xml->xpath( htmlAttribute( 'class', 'LineInfo' ) );
$line_status = array();

$default_disruption_structure = array (
	'status' => '',
	'info_text' => '',
	'info_text_raw' => '',
	'info_id' => 0,
	'info_link' => '',
	'info_link_summary' => '',
	'info_link_summary_raw' => '',
	'source' => '',
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
	$more_info_text_raw = isset( $more_info_data[0] ) ? (string) $more_info_data[0] : '';
	if ( ! $more_info_text_raw && isset( $more_info_data[0]->a ) ) {
		$more_info_text_raw = (string) $more_info_data[0]->a;
	}
	$more_info_text = clean_string( $more_info_text_raw );

	// Link
	$more_info_link = isset( $more_info_data[0]->a ) ? (string) $more_info_data[0]->a->attributes()->href : '';
	if ( $more_info_link ) {
		$more_info_link = $host . $more_info_link;
	}

	// Link summary
	$more_info_link_summary_raw = '';
	$more_info_link_summary = '';
	if ( $more_info_link && $more_info_id ) {
		$local_link_summary = $local_dir . $more_info_id . '.html';
		$xml_link_summary = get_cached_xml( $local_link_summary, $more_info_link, 'disruption-info', 24 * 60 * 60 );
		$article = $xml_link_summary ? $xml_link_summary->xpath( htmlAttribute( 'class', 'article' ) ) : '';
		$more_info_link_summary_raw = isset( $article[0]->p[1] ) ? strip_tags( (string) $article[0]->p[1] ) : '';
		$more_info_link_summary = preg_replace( '/ on the \w* line/i', '', $more_info_link_summary_raw ); // Remove line mention to save space
		$more_info_link_summary = clean_string( $more_info_link_summary );
	}

	// Service status
	$service_status = str_replace( array ( 'minor', 'major', 'service' ), '', strtolower( $line_status_text ) );
	$service_status = trim( $service_status );
	$service_status = str_replace( ' ', '-', $service_status ); // Replace any leftover spaces with dashes

	// Delay type
	$delay_type = str_replace( // Ignore some statuses for delay type
		array ( 'delays', 'good service', 'diversion', 'planned works', 'part suspended', 'suspended' ),
		'',
		strtolower( $line_status_text )
	);
	$delay_type = trim( $delay_type );

	// Delay duration
	$delay_duration = get_delay_duration( $more_info_text, $more_info_link_summary );

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
		'info_text_raw' => $more_info_text_raw,
		'info_id' => $more_info_id,
		'info_link' => $more_info_link,
		'info_link_summary' => $more_info_link_summary,
		'info_link_summary_raw' => $more_info_link_summary_raw,
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

// Go through the operator's own disruptions, if available

//print_r( $operator_json );

if ( $operator_json ) {
	foreach ( $operator_json as $key => $line ) {

		// Deal with a 'general' status covering the network rather than a specific line
		if ( 'general' == $key ) {
			$_id = md5( trim( $line->body ) ); // Since there's no IDs for these messages, hash it to track it
			if ( ! file_exists( $local_dir . $_id . '.disruption' ) ) {
				$payload['attachments'][] = array (
					'title' => trim( $line->title ),
					'text' => emojify( trim( $line->body ) ),
					'author_name' => '',
					'fallback' => trim( $line->body ),
					'color' => '#439FE0', // A nice blue
					'_message_id' => $_id,
				);
				$message_ids[] = $_id;
				$message_ids_and_text[] = array( 'id' => $_id, 'text' => trim( $line->body ) );
			}
			continue;
		}

		// Sometimes temporary lines don't have names (eg. Flemington), so we'll skip if there's no name (or no alerts)
		if ( ! isset( $line->line_name ) || ! isset( $line->alerts ) ) {
			continue;
		}

		// Deal with a status that just has a single string - usually meaning good service
		if ( ! is_array ( $line->alerts ) ) {

			// Status
			if ( false !== strpos( $line->alerts, '-' ) ) {
				$status = substr( $line->alerts, 0, strpos( $line->alerts, '-' ) );
			} else {
				$status = $line->alerts;
			}
			$status = trim( ucwords( $status ) );
			$service_status = 'Good Service' === $status ? 'good' : '';

			$disruptions[ $line->line_name ][] = array_replace_recursive( $default_disruption_structure, array (
				'status' => $status,
				'info_text' => clean_string( ucfirst( str_ireplace( 'good service - ', '', $line->alerts ) ) ),
				'info_text_raw' => $line->alerts,
				'source' => 'metro',
				'parsed' => array (
					'service_status' => $service_status,
				),
			));

			continue; // Skip dealing with the status as an array, which would happen next

		}

		// Loop through each alert, and add it to our collection
		foreach ( $line->alerts as $alert ) {

			// Reset data
			$status = '';
			$service_status = '';
			$delay_type = '';
			$delay_duration = '';
			$info_text_raw = $alert->alert_text;
			$info_text = clean_string( $info_text_raw );

			// Cancellations, reinstations, and alterations
			$cancellation = get_cancellation_details( $info_text );
			$reinstation = get_reinstation_details( $info_text );
			$alteration = get_alteration_details( $info_text );
			if ( $cancellation ) {
				$status = 'Service Cancellation';
				$service_status = 'cancellation';
				$delay_type = 'minor' == $alert->alert_type || 'major' == $alert->alert_type ? $alert->alert_type : '';
			} elseif ( $reinstation ) {
				$status = 'Service Re-instation';
				$service_status = 'reinstation';
				$delay_type = 'minor' == $alert->alert_type || 'major' == $alert->alert_type ? $alert->alert_type : '';
			} elseif ( $alteration ) {
				$status = 'Service Alteration';
				$service_status = 'alteration';
				$delay_type = 'minor' == $alert->alert_type || 'major' == $alert->alert_type ? $alert->alert_type : '';
				$delay_duration = get_delay_duration( $info_text );
			}

			// Attempt to normalise data
			if ( ! $cancellation && ! $alteration && ! $reinstation ) {
				if ( 'major' === $alert->alert_type || 'minor' === $alert->alert_type ) {
					$status = ucwords( $alert->alert_type . ' delays' );
					$service_status = 'delays';
					$delay_type = $alert->alert_type;
					$delay_duration = get_delay_duration( $info_text );
				} elseif ( 'works' === $alert->alert_type ) {
					$status = 'Planned Works';
					$service_status = 'planned-works';
					$delay_type = get_planned_works_type( $info_text );
					$delay_duration = get_planned_works_duration( $info_text );
				} elseif ( is_good_service( $info_text ) ) { // Is this disruption actually a 'return to good service'?
					$status = 'Good Service';
					$service_status = 'good';
				} else {
					$status = $alert->alert_type;
					$service_status = $alert->alert_type;
				}
			}

			// Store data
			$disruptions[ $line->line_name ][] = array_replace_recursive( $default_disruption_structure, array (
				'status' => $status,
				'info_text' => $info_text,
				'info_text_raw' => $info_text_raw,
				'info_id' => 'O' . $alert->alert_id, // Prefix ID with O for operator to avoid any potential collisions
				'source' => 'metro',
				'parsed' => array (
					'service_status' => $service_status,
					'delay_type' => $delay_type,
					'delay_duration' => $delay_duration,
					'reason' => get_disruption_reason( $info_text ),
					'line_part' => get_disruption_line_part( $info_text ),
					'specific_service' => get_specific_service( $info_text ),
					'alteration_details' => $alteration,
					'increased_journey_time' => get_increased_journey_time( $info_text ),
					'directions' => array (
						'inbound' => is_disruption_inbound( $info_text ),
						'outbound' => is_disruption_outbound( $info_text, $line->line_name ),
					),
				),
			));

			// Update overall line status, as long as we're not replacing existing disruptions with 'good'
			if ( 'good' !== $service_status ) {
				$line_status[ $line->line_name ] = $service_status;
			}

		} // Foreach json alert
	} // Foreach json line
} // If json

// Now it's time to post status message(s) to Slack, if necessary!

foreach ( $lines_to_check as $line ) {
	foreach ( $disruptions[ $line ] as $disruption ) {

		// Check whether we need to skip this disruption
		if (
			'good' === $disruption['parsed']['service_status'] && // Good service? No need to post...
			( // ...as long as this is our first check of this line OR our last check reported good service too
				! file_exists( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status' ) ||
				'good' === file_get_contents( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status' ) ||
				'alteration' === file_get_contents( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status' ) ||
				'reinstation' === file_get_contents( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status' ) ||
				'cancellation' === file_get_contents( $local_dir . str_replace( ' ', '-', strtolower( $line ) ) . '.status' ) ||
				! POST_GOOD_SERVICE || // OR config says no
				'good' !== $line_status[ $line ] // OR the whole line's status is not actually good
			)
		) {
			continue;
		} elseif (
			'good' !== $disruption['parsed']['service_status'] &&
			(
				! $disruption['info_id'] || // No disruption ID? We can't easily track it, so can't post it
				(
					file_exists( $local_dir . $disruption['info_id'] . '.disruption' ) && // Disruption already posted
					! DEBUG_SKIP_PREVIOUSLY_SENT_CHECK // Debug mode for skipping this check is not on
				)
			)
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

		// Shorten the info text if it has more than one sentence in it
		// We catch the end of a sentence both with a full stop at the end of a word boundary or the end of a bracket
		// If this results in losing information, we'll catch it below and link to the provider website for more info
		$disruption_info_text_full = $disruption['info_text'];
		$disruption['info_text'] = preg_replace( '/(.*?(\b|\)))\.\s.*/', '$1', $disruption['info_text'] );

		if ( 'good' === $disruption['parsed']['service_status'] ) {

			// ------------ return to GOOD SERVICE ------------ //

			$attachment['title'] = ':green_heart: Good Service';
			$attachment['text'] = (
				$disruption['info_text'] ?
				$disruption['info_text'] :
				'Earlier disruptions have cleared, and trains are on time to 5 minutes'
			);

		} elseif ( 'cancellation' === $disruption['parsed']['service_status'] ) {

			// ------------ SERVICE CANCELLATIONS ------------ //

			$attachment['text'] = ':no_entry: ';

			if ( $disruption['parsed']['specific_service'] ) {
				$attachment['text'] .= '' .
					'The *' . $disruption['parsed']['specific_service'] . '* has been cancelled';
				if ( $disruption['parsed']['reason'] ) {
					$attachment['text'] .= ', due to ' . $disruption['parsed']['reason'];
				}
			} else {
				$attachment['text'] .= $disruption['info_text'];
			}

		} elseif ( 'reinstation' === $disruption['parsed']['service_status'] ) {

			// ------------ SERVICE REINSTATIONS ------------ //

			$attachment['text'] = ':heart_eyes: ';

			if ( $disruption['parsed']['specific_service'] ) {
				$attachment['text'] .= '' .
					'The *' . $disruption['parsed']['specific_service'] . '* is no longer cancelled :tada:';
			} else {
				$attachment['text'] .= $disruption['info_text'];
			}

		} elseif ( 'alteration' === $disruption['parsed']['service_status'] ) {

			// ------------ SERVICE ALTERATIONS ------------ //

			$attachment['text'] = ':information_source: ';

			if ( $disruption['parsed']['specific_service'] && $disruption['parsed']['alteration_details'] ) {
				$attachment['text'] .= '' .
					'The *' . $disruption['parsed']['specific_service'] . '* ' . 
					$disruption['parsed']['alteration_details'];
				if ( $disruption['parsed']['reason'] ) {
					$attachment['text'] .= ', due to ' . $disruption['parsed']['reason'];
				}
			} else {
				$attachment['text'] .= $disruption['info_text'];
			}

		} elseif (
			'suspended' === $disruption['parsed']['service_status'] ||
			'part-suspended' === $disruption['parsed']['service_status']
		) {

			// ------------ LINE SUSPENSIONS ------------ //

			if ( 'part-suspended' === $disruption['parsed']['service_status'] ) {
				$attachment['title'] = ':x: Partial Line Suspension';
			} else {
				$attachment['title'] = ':x: Line Suspended';
			}

			if ( $disruption['info_link_summary'] ) {
				$attachment['text'] = $disruption['info_link_summary'];
			} else if ( $disruption['info_text'] ) {
				$attachment['text'] = $disruption['info_text'];
			}

		} elseif ( 'delays' === $disruption['parsed']['service_status'] ) {

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
				$attachment['text'] .= ' ' . $disruption['parsed']['line_part'];
			}

			if ( $disruption['parsed']['line_part'] && $disruption['parsed']['delay_duration'] ) {
				$attachment['text'] .= ',';
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

		} elseif ( 'information' === $disruption['parsed']['service_status'] ) {

			// ------------ GENERAL SERVICE INFORMATION ------------ //
		
			$attachment['title'] = ':information_source: Service Information';

			if ( $disruption['info_link_summary'] ) {
				$attachment['text'] = $disruption['info_link_summary'];
			} else if ( $disruption['info_text'] ) {
				$attachment['text'] = $disruption['info_text'];
			}

		} else {

			// ------------ UNKNOWN DISRUPTION TYPE ------------ //
		
			// We don't recognise this disruption type yet, so let's post the message verbatim
			if ( $disruption['info_link_summary'] ) {
				$attachment['text'] = $disruption['info_link_summary'];
			} else if ( $disruption['info_text'] ) {
				$attachment['text'] = $disruption['info_text'];
			}

		} // Service status type / else

		// If there's a link available, add it as the last field
		// Otherwise, check whether the information provided by the operator is significantly longer than ours, and
		// add a link to their site in that circumstance.
		if ( $disruption['info_link'] ) {
			$fields[] = array(
				'value' => '*_<' . $disruption['info_link'] . '|Find out more>_*',
			);
		} elseif ( strlen( $disruption_info_text_full ) > strlen( $attachment['text'] ) + 20 ) {
			$fields[] = array(
				'value' => '' .
					'*_<' .
					str_replace(
						'{line}',
						str_replace( ' ', '-', strtolower( $line ) ),
						$operator_more_info_links[ $disruption['source'] ]
					) .
					'|Find out more>_*',
			);
		}

		// Set the attachment colour
		// - Green for good service
		// - Yellow for minor delays, or alterations without a minor/major status
		// - Red for major delays, or cancellations without a minor/major status
		// - Blue for planned works
		// - The default (a faded grey) for anything not matched

		if ( 'good' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = 'good';
		} elseif ( 'planned-works' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = '#439FE0'; // A nice blue
		} elseif ( 'suspended' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = '#000000';
		} elseif ( 'part-suspended' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = '#555555';
		} elseif ( $disruption['parsed']['delay_type'] ) {
			if ( 'minor' === $disruption['parsed']['delay_type'] ) {
				$attachment['color'] = 'warning';
			} elseif ( 'major' === $disruption['parsed']['delay_type'] ) {
				$attachment['color'] = 'danger';
			}
		} elseif ( 'cancellation' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = 'danger';
		} elseif ( 'reinstation' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = 'good';
		} elseif ( 'alteration' === $disruption['parsed']['service_status'] ) {
			$attachment['color'] = 'warning';
		}

		// Add the affected train line as the 'author'
		$attachment['author_name'] = $line;
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
		if ( DEBUG_VERBOSE ) {

			if ( $disruption['info_text_raw'] ) {
				$fields[] = array(
					'title' => 'Original Text',
					'value' => '_' . trim( $disruption['info_text_raw'], ' ' . chr( 0xC2 ) . chr( 0xA0 ) . "\n" ) . '_'
				);
			}

			if ( $disruption['info_link_summary_raw'] ) {
				$fields[] = array(
					'title' => 'Original Summary',
					'value' => '_' . trim( $disruption['info_link_summary_raw'], ' ' . chr( 0xC2 ) . chr( 0xA0 ) ) . '_'
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
	if ( ! DEBUG_SKIP_SENDING_MESSAGES ) {
		send_to_slack( $payload, $message_ids_and_text );
	}

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

// Store overall line status so we can track return to normal service
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
