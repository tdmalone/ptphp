<?php

/**
 * PTV Next 5 BETA
 * Scrapes data on the next 5 trains and posts to Slack.
 *
 * @version 0.0.7.1.1
 */

require( __DIR__ . '/functions.php' );

// First up, do we have Slack slash command arguments to deal with?

if ( CALLED_FROM_SLACK_COMMAND ) {

	// In case we have no arguments, check whether we have saved arguments for this user
	$user_arg_cache_filename = $local_dir . $_POST['user_name'] . '.next5args';
	if ( ! $_POST['text'] && file_exists( $user_arg_cache_filename ) ) {
		$_POST['text'] = file_get_contents( $user_arg_cache_filename );
	}

	$args = explode( ' ', $_POST['text'] );

	// Accept parameters in any order
	if ( count( $args ) ) {
		$_2 = substr( $args[0], 0, 2 );
		if ( 'in' === $_2 || 'ou' === $_2 || 'ci' === $_2 || 'ho' === $_2 || 'wo' === $_2 ) {
			$args['direction'] = isset( $args[0] ) ? $args[0] : '';
			$args['station_name'] = isset( $args[1] ) ? $args[1] : '';
		} else {
			$args['station_name'] = isset( $args[0] ) ? $args[0] : '';
			$args['direction'] = isset( $args[1] ) ? $args[1] : '';
		}
	}

	if ( isset( $args['station_name'] ) && $args['station_name'] ) {
		switch ( substr( $args['station_name'], 0, 3 ) ) {
			case 'bla': $stop_id = 1023; break; // Blackburn
			case 'box': $stop_id = 1026; break; // Box Hill
			case 'bur': $stop_id = 1030; break; // Burnley
			case 'cam': $stop_id = 1032; break; // Camberwell
			case 'can': $stop_id = 1033; break; // Canterbury
			case 'cab': $stop_id = 22319; break; // Canterbury bus
			case 'cro': $stop_id = 1048; break; // Croydon
			case 'crb': $stop_id = 18756; break; // Croydon bus
			case 'elt': $stop_id = 1062; break; // Eltham
			case 'fla': $stop_id = 1068; break; // Flagstaff
			case 'fli': $stop_id = 1071; break; // Flinders Street
			case 'gle': $stop_id = 1080; break; // Glenferrie
			case 'hea': $stop_id = 1091; break; // Heatherdale
			case 'jol': $stop_id = 1104; break; // Jolimont
			case 'jor': $stop_id = 1105; break; // Jordanville
			case 'mel': $stop_id = 1120; break; // Melbourne Central
			case 'mit': $stop_id = 1128; break; // Mitcham
			case 'moo': $stop_id = 1133; break; // Mooroolbark
			case 'nar': $stop_id = 1139; break; // Narre Warren
			case 'nor': $stop_id = 1144; break; // North Melbourne
			case 'nun': $stop_id = 1148; break; // Nunawading
			case 'par': $stop_id = 1155; break; // Parliament
			case 'res': $stop_id = 1161; break; // Reservoir
			case 'ric': $stop_id = 1162; break; // Richmond
			case 'rin': $stop_id = 1163; break; // Ringwood
			case 'rut': $stop_id = 1171; break; // Ruthven
			case 'sou': $stop_id = 1181; break; // Southern Cross
			case 'spr': $stop_id = 1183; break; // Springvale
			case 'sur': $stop_id = 1189; break; // Surrey Hills
			default:
				if (
					is_numeric( $args['station_name'] ) &&
					(int) $args['station_name'] > 1000 &&
					(int) $args['station_name'] < 100000
				) {
					$stop_id = (int) $args['station_name'];
				} else {
					exit(
						'Oh, I don\'t understand the station or stop name _' . $args['station_name'] . '_, ' .
						'sorry! :cry: ' . "\n" . 
						'I\'ve only been prefilled with a few common stop names for now, so please ask ' .
						'<@' . MAINTAINER_USERNAME . '> if your choice isn\'t available yet. Alternatively, if you ' .
						'know the stop ID (eg. 1033 or 22319), you can use that too (works for :train2:, :train: ' .
						'and :bus: stops). :thumbsup:' . "\n" .
						'*PS:* You can usually shorten :train2: station names - just ensure you include at least the ' .
						'first 3-4 characters.'
					);
				}
			break;
		}
	}

	if ( isset( $args['direction'] ) && $args['direction'] ) {

		// Allow pre-existing direction commands to work with the newer four character line name directions
		$valid_two_character_directions = array( 'in', 'ci', 'wo', 'ou', 'ho' );
		if ( in_array( substr( $args['direction'], 0, 2 ), $valid_two_character_directions ) ) {
			$args['direction'] = substr( $args['direction'], 0, 2 );
		}

		switch ( substr( $args['direction'], 0, 4 ) ) {

			// Inbound/City/Work/Outbound/Home
			case 'in': case 'ci': case 'wo':	$one_direction = 'inbound'; break;
			case 'ou': case 'ho':				$one_direction = 'outbound'; break;

			// Valid line names
			case 'alam': case 'belg': case 'crai': case 'cran': case 'fran':
			case 'glen': case 'hurs': case 'lily': case 'pake': case 'sand':
			case 'sout': case 'sunb': case 'upfi': case 'werr': case 'will':
			$one_direction = $args['direction']; break;

			default:
				exit(
					'I don\'t understand the direction _' . $args['direction'] . '_ sorry! :sweat_smile: I can give ' .
					'you _inbound_, or _outbound_ - or just leave off the direction to get both. I also respond to ' .
					'_in_, _out_, _city_, _work_ and _home_, or even a particular line name, eg. _lilydale_ or ' .
					'_frankston_.' . "\n" .
					'If you were trying to type a :train2: station name with a _space_ and you got this error, ' .
					'just leave the space out. Thanks! :nerd_face:'
				);
			break;
		}
	} else {
		unset( $one_direction ); // Ensure the default (no arg) is not overridden by config
	}

	// If we got here, the user's commands were successful, so save them for next time
	if ( $_POST['text'] ) {
		file_put_contents( $user_arg_cache_filename, $_POST['text'] );
	}

} // If called from slack command

// Try to determine the correct mode for the entered stop ID
// This may or may not need further tweaking
if ( $stop_id >= 10000 ) { // Bus stops might stop IDs over 10,000?
	$stop_mode = 2;
	$stop_mode_singular = 'bus';
	$stop_mode_plural = 'buses';
	$stop_mode_emoji = ':bus:';
	$stop_mode_stop_singular = 'stop';
	$stop_mode_stop_plural = 'stops';
} elseif ( $stop_id >= 2000 ) { // Tram stops might be IDs over 2,000?
	$stop_mode = 1;
	$stop_mode_singular = 'tram';
	$stop_mode_plural = 'trams';
	$stop_mode_emoji = ':train:';
	$stop_mode_stop_singular = 'stop';
	$stop_mode_stop_plural = 'stops';
} else { // Train stops all appear to be in the 1000 range
	$stop_mode = 0;
	$stop_mode_singular = 'train';
	$stop_mode_plural = 'trains';
	$stop_mode_emoji = ':train2:';
	$stop_mode_stop_singular = 'station';
	$stop_mode_stop_plural = 'stations';
}

// Add 10,000,000 to all stop_ids to get the general format 1000xxxx
$stop_id += 10000000;

// Build URL and local cache filename
$url .= '?stopId=' . $stop_id . '&limit=' . $train_count . '&mode=' . $stop_mode;
$local_file = str_replace( '.html', '-' . $stop_id . '-' . $train_count . '.json', $local_file );

// Set options that may not have been set
// NOTE: some of these may not have been implemented yet and are here for future use
if ( ! defined( 'DISABLE_CONNECTING_TRAINS' ) ) {
	define( 'DISABLE_CONNECTING_TRAINS', false );
}
if ( ! defined( 'INCLUDE_DISRUPTIONS' ) ) {
	define( 'INCLUDE_DISRUPTIONS', true );
}
if ( ! defined( 'CONDENSED_MODE' ) ) {
	define( 'CONDENSED_MODE', false );
}

// Get the trains!
$json = get_cached_json( $local_file, $url, 'next5' );
$trains = array();
$_trains = $json->values;

$stop_name = isset( $_trains[0] ) ? $_trains[0]->platform->stop->location_name : '';
$stop_suburb = isset( $_trains[0] ) ? $_trains[0]->platform->stop->suburb : '';

$i = 0;

foreach ( $_trains as $train ) {

	// If we're only asking for a certain direction, determine whether we've asked for it by name or just in/outbound
	if ( ! isset( $one_direction ) || 'inbound' === $one_direction || 'outbound' === $one_direction ) {
		$direction = ( $train->platform->direction->direction_id <= 1 ? 'inbound' : 'outbound' );
	} else {
		$direction = $train->platform->direction->direction_name;
	}

	// Skip this train if we've only asked for another direction
	if (
		isset( $one_direction ) &&
		strtolower( substr( $one_direction, 0, 4 ) ) !== strtolower( substr( $direction, 0, 4 ) )
	) {
		continue;
	}

	// Iterate
	$i++;

	// Times
	$train_time_format = 'g:i A';
	$train_time_format_short = 'g:i';
	$scheduled_timestamp = strtotime( $train->time_timetable_utc );
	$scheduled_time = date( $train_time_format, $scheduled_timestamp );
	if ( $train->time_realtime_utc ) {
		$expected_timestamp = strtotime( $train->time_realtime_utc );
		$expected_time = date( $train_time_format, $expected_timestamp );
		$delayed_minutes = round( ( $expected_timestamp - $scheduled_timestamp ) / 60 ) - 1; // Add a protection minute
	} else {
		$expected_timestamp = false;
		$expected_time = false;
		$delayed_minutes = false;
	}

	// Is this a short run?

	$short_run = (
		$train->platform->direction->direction_id > 1 && // Not a city run
		$train->platform->direction->direction_name != $train->run->destination_name // Direction != Destination
	);

	// If not run from a Slack command (and connecting trains aren't disabled), attempt to get the next connectors
	// (We can't do this from a Slack command because we'll never make it in time for the 3 second cut-off)
	
	$short_run_connectors = array();

	if (
		0 === $stop_mode &&
		! CALLED_FROM_SLACK_COMMAND &&
		! DISABLE_CONNECTING_TRAINS &&
		! CONDENSED_MODE &&
		$short_run
	) {

		// What time will we arrive at the connecting location?

		$run_local = str_replace(
			array( 'stopservices', $train->platform->stop->stop_id . '-' . $train_count ),
			array( 'stoprun', $train->run->destination_id . '-' . $train->run->run_id ),
			$local_file
		);

		$run_url = '' .
			$host . '/langsing/stop-run?' .
			'runId=' . $train->run->run_id . '&' . 
			'modeId=train' . '&' . 
			'stopId=' . $train->run->destination_id;

		$run_json = get_cached_json( $run_local, $run_url, 'run' );
		$run_end = array_pop( $run_json->values );

		// Now grab the trains from that location (hopefully it's not too far away hey!)

		if ( $run_end->time_realtime_utc ) { // We have a real-time at the end of the run
			$connecting_time_from = strtotime( $run_end->time_realtime_utc );
		} elseif ( $expected_timestamp ) { // No real-time at the end of the run, so let's estimate it from our current
			$connecting_time_from = strtotime( $run_end->time_timetable_utc) + ( $expected_timestamp - $scheduled_timestamp );
		} else { // We don't have a real-time at the end OR current, so just follow the schedule
			$connecting_time_from = strtotime( $run_end->time_timetable_utc );
		}

		$connector_local = str_replace( $train->platform->stop->stop_id, $train->run->destination_id, $local_file );

		$connector_url = str_replace(
			array( $train->platform->stop->stop_id, '&limit=' . $train_count ),
			array( $train->run->destination_id, '&limit=' . 20 ), // Since we're going ahead in time, we might need a few
			$url
		);

		$connector_json = get_cached_json( $connector_local, $connector_url, 'next5-connecting' );

		$z = 0;
		$previous_destination = '';

		foreach ( $connector_json->values as $connector_train ) {

			// Continue to next if this train is a city run
			if ( $connector_train->platform->direction->direction_id <= 1 ) {
				continue;
			}

			// Continue to next if this train leaves before we'll arrive
			if (
				$connector_train->time_realtime_utc &&
				strtotime( $connector_train->time_realtime_utc ) < $connecting_time_from
			) {
				continue;
			} elseif (
				! $connector_train->time_realtime_utc &&
				strtotime( $connector_train->time_timetable_utc ) < $connecting_time_from
			) {
				continue;
			}

			// Continue to next if this train's destination matches the last one
			if ( $connector_train->run->destination_name === $previous_destination ) {
				continue;
			}

			// Continue to next if this train leaves more than 2 hours after - clearly something has gone wrong
			if ( strtotime( $connector_train->time_timetable_utc ) > time() + ( 3600 * 2 ) ) {
				continue;
			}

			$z++;
			$previous_destination = $connector_train->run->destination_name; // Update for next time

			$short_run_connectors[] = array (
				'direction' => $connector_train->platform->direction->direction_id,
				'line_name' => $connector_train->platform->direction->line->line_name_short,
				'destination' => $connector_train->run->destination_name,
				'destination_short' => get_short_name( $connector_train->run->destination_name ),
				'platform' => $connector_train->platform->platform_number,
				'minutes' => get_minutes_to_go( $connector_train->time_realtime_utc ),
				'scheduled_time' => date( $train_time_format, strtotime( $connector_train->time_timetable_utc ) ),
				'expected_time' => (
					$connector_train->time_realtime_utc ?
					date( $train_time_format, strtotime( $connector_train->time_realtime_utc ) ) :
					false
				),
				'pattern' => get_stopping_pattern( $connector_train->run->num_skipped, $stop_mode_stop_plural ),
				'connecting_time_from' => date( $train_time_format, $connecting_time_from ),
			);

			// Quit if we have more than 2 valid connecting services
			if ( $z >= 2 ) {
				break;
			}

		} // For each possible connector
	} // If short run and not slash command/connectors disabled etc.

	if ( ! count( $short_run_connectors ) ) {
		$short_run_connectors = false;
	}

	$trains[] = array (
		'direction' => $direction,
		'line_name' => (
			0 === $stop_mode ?
			$train->platform->direction->line->line_name_short :
			get_short_name( $train->platform->direction->line->line_name_short )
		),
		'line_number' => $train->platform->direction->line->line_number,
		'destination' =>  (
			0 === $stop_mode ?
			$train->run->destination_name :
			get_short_name( $train->run->destination_name )
		),
		'destination_short' => get_short_name( $train->run->destination_name ),
		'platform' => $train->platform->platform_number,
		'minutes' => get_minutes_to_go( $train->time_realtime_utc ),
		'scheduled_time' => $scheduled_time,
		'expected_time' => $expected_time,
		'scheduled_timestamp' => $scheduled_timestamp,
		'expected_timestamp' => $expected_timestamp,
		'delayed_minutes' => $delayed_minutes,
		'pattern' => get_stopping_pattern( $train->run->num_skipped ),
		'short_run' => ( 0 === $stop_mode ? $short_run : '' ),
		'short_run_connectors' => $short_run_connectors,
	);

	// If we've hit the count we wanted, break out of the rest of the loop
	if ( $i >= $train_count ) {
		break;
	}

} // Foreach trains

// Debug data
preint( $trains );

// Format response and send to Slack
if ( count( $trains ) ) {

	$payload['attachments'] = array();
	$short_run_included = false;
	$next_train_times = '';
	$first_fallback_time = '';

	foreach ( $trains as $key => $train ) {

		// Colour based on amount of delay
		if ( $train['delayed_minutes'] >= 10 ) {
			$color = 'danger';
		} elseif ( $train['delayed_minutes'] >= 5 ) {
			$color = 'warning';
		} elseif ( false !== $train['delayed_minutes'] ) {
			$color = 'good';
		} else {
			$color = false;
		}

		// Show delay in footer text
		if ( $train['delayed_minutes'] > 0 ) {
			$footer = $train['delayed_minutes'] . ' minute' . ( 1 != $train['delayed_minutes'] ? 's' : '' ) . ' late';
		} elseif ( false !== $train['delayed_minutes'] ) {
			$footer = 'On time';
		} else {
			$footer = 'Scheduled';
		}

		// Destination
		if ( 0 === $stop_mode ) {
			$_pattern = ucfirst( str_replace( '-', ' ', $train['pattern'] ) );
			$destination = $_pattern . ' to *' . $train['destination'] . '*';
			$fallback_destination = $_pattern . ' to ' . $train['destination'];
		} else {
			$destination = '*' . $train['line_number'] . '* to ' . $train['destination'];
			$fallback_destination = $train['line_number'] . ' to ' . $train['destination'];
		}

		// Warn of short run if called via command (as we can't give the full output of the connecting service there)
		// Or, we'll also warn if it's short run but we couldn't find connecting trains (perhaps there are disruptions)
		if ( $train['short_run'] ) {
			if ( CALLED_FROM_SLACK_COMMAND || DISABLE_CONNECTING_TRAINS ) {
				$short_run_included = true;
				$destination .= '^';
			} elseif ( ! $train['short_run_connectors'] ) {
				$short_run_included = true;
				$destination .= '^';
			}
		}

		// Prepend an emoji to the destination
		if ( 0 === $stop_mode ) {
			if ( 'inbound' === $train['direction'] ) {
				$destination = ( isset( $inbound_prefix ) ? $inbound_prefix . ' ' . $destination : $destination );
			} elseif ( 'outbound' === $train['direction'] ) {
				$destination = ( isset( $outbound_prefix ) ? $outbound_prefix . ' ' . $destination : $destination );
			} else {
				$destination = $stop_mode_emoji . ' ' . $destination;
			}
		} else {
			$destination = $stop_mode_emoji . ' ' . $destination;
		}

		// Time
		if ( $train['minutes'] > 0 ) {
			$time = 'in *' . $train['minutes'] . ' minute' . ( 1 != $train['minutes'] ? 's' : '' ) . '* ';
			$time .= '(' . date( $train_time_format_short, time() + ( $train['minutes'] * 60 ) ) .  ')';
			$fallback_time = 'in ' . $train['minutes'] . ' minute' . ( 1 != $train['minutes'] ? 's' : '' );
		} elseif ( false !== $train['minutes'] ) {
			$time = '*NOW*';
			$fallback_time = 'NOW';
		} else { // Non real-time train
			$time = '*' . $train['scheduled_time'] . '*';
			$fallback_time = 'at ' . $train['scheduled_time'];
		}

		// Put the times together in a sentence for condensed mode
		if ( CONDENSED_MODE ) {
			if ( count( $trains ) > 1 && count( $trains ) - 1 === $key ) {
				$next_train_times .= ' and ';
			} elseif ( 0 !== $key ) {
				$next_train_times .= ', ';
			}
			$next_train_times .= $fallback_time;
			if ( ! $first_fallback_time ) { // Save the first fallback time for the condensed mode fallback text
				$first_fallback_time = $fallback_time;
			}
		}

		// Platform
		if ( 0 === $stop_mode ) {
			$time .= ' from ' . ( $train['platform'] ? 'platform ' . $train['platform'] : 'unknown platform' );
		}

		// Put fields together - as separate fields for trains, or together for trams/buses (due to longer text)
		if ( 0 === $stop_mode ) {
			$fields = array (
				array (
					'value' => $destination,
					'short' => true,
				),
				array (
					'value' => $time,
					'short' => true,
				),
			);
		} else {
			$fields = array (
				array (
					'value' => $destination . ' - ' . $time,
					'short' => false,
				),
			);
		}

		// Do we have a connector train?
		if ( $train['short_run_connectors'] ) {
			foreach ( $train['short_run_connectors'] as $connector ) {
				$fields[] = array (
					'value' => '' .
						'_' .
						ucfirst( str_replace( '-', ' ', $connector['pattern'] ) ) . ' ' .
						'to *' . $connector['destination_short'] . '*: ' .
						'connect at ' . $train['destination_short'] . ' ' .
						(
							$connector['minutes'] > 0 ? (
								'in ' . $connector['minutes'] . ' minutes ' .
								'(' . date( $train_time_format_short, time() + ( $connector['minutes'] * 60 ) ) . ')'
							) :
							'at ' . $connector['scheduled_time']
						) . ' ' . (
							0 === $stop_mode ?
							'from ' . (
								$connector['platform'] ?
								'platform ' . $connector['platform'] :
								'unknown platform'
							) :
							''
						) . '_',
					'short' => false,
				);
			}
		}

		// Fallback text for clients that can't display the attachment
		$fallback = $fallback_destination . ' ' . $fallback_time;

		// Is this the first attachment? Set the pretext and modify the fallback.
		// The purpose of this (rather than just using standard message text) is to give us to-the-point fallback text
		// that will display in notifications.
		$pretext = '';
		if ( ! CONDENSED_MODE && ! count( $payload['attachments'] ) ) {
			$pretext = '' .
				'As of *' . date( $train_time_format ) . '*, ' .
				'the next ' . ( isset( $one_direction ) ? $train['direction'] . ' ' : '' ) .
				( $train_count != 1 ? $stop_mode_plural : $stop_mode_singular ) . ' to leave ' .
				'*' . $stop_name . '* ' . ( 0 !== $stop_mode ? '(' . $stop_suburb . ') ' : '' ) .
				( $train_count != 1 ? 'are' : 'is' ) . ':' . "\n";
			$fallback = '' . 
				'The next ' . ( isset( $one_direction ) ? $train['direction'] . ' ' : '' ) .
				$stop_mode_singular . ' leaves ' . $stop_name . ' ' . $fallback_time . '. ' .
				'Open Slack for more.';
		}

		// Add a new attachment for this service - unless we're in condensed mode of course!
		if ( ! CONDENSED_MODE ) {
			$payload['attachments'][] = array (
				'color' => $color,
				'mrkdwn_in' => ['fields', 'pretext'],
				'footer' => $footer,
				'ts' => $train['scheduled_timestamp'],
				'fields' => $fields,
				'fallback' => $fallback,
				'pretext' => $pretext, // Used in the first attachment
			);
		}

	} // Foreach trains

	// Now, if we ARE in condensed mode, put the times together in a sentence for our sole attachment
	// We also include fallback text here (for notifications) similar to what we include in normal mode
	if ( CONDENSED_MODE ) {
		$payload['attachments'][] = array (
			'pretext' => '' .
				'As of *' . date( $train_time_format ) . '*, ' .
				'the next ' . ( isset( $one_direction ) ? $one_direction . ' ' : '' ) .
				( $train_count != 1 ? $stop_mode_plural . ' leave' : $stop_mode_singular . ' leaves' ) . ' ' .
				'*' . $stop_name . '* ' . ( 0 !== $stop_mode ? '(' . $stop_suburb . ') ' : '' ) .
				$next_train_times . '.',
			'fallback' => '' . 
				'The next ' . $stop_mode_singular . ' leaves ' . $stop_name . ' ' . $first_fallback_time . '. ' .
				'Open Slack for more.',
			'mrkdwn_in' => ['pretext'],
		);
	}

	// Quick footer to explain the '^' in slash command responses
	if ( $short_run_included ) {
		if (
			( CALLED_FROM_SLACK_COMMAND || DISABLE_CONNECTING_TRAINS ) &&
			! CONDENSED_MODE
		) {
			$payload['attachments'][] = array (
				'pretext' => "\n" .
					'^ These trains stop short of the end of the line. You may need to change trains to ' .
					'continue your journey.',
			);
		} else {
			$payload['attachments'][] = array (
				'pretext' => "\n" .
					'^ These trains stop short of the end of the line. You may need to change trains to ' .
					'continue your journey. Connections cannot be determined at this time, so please listen ' .
					'for announcements.',
			);
		}
	}

	// For debugging purposes
	if ( ! CALLED_FROM_SLACK_COMMAND ) {
		preint( $payload );
	}

	// Advise the user that we can now save their arguments
	/*
	if ( CALLED_FROM_SLACK_COMMAND && $_POST['text'] ) {
		$payload['attachments'][] = array (
			'pretext' => "\n" .
				':information_source: I now remember your most recent custom command. If you enter ' . 
				'`' . $_POST['command'] . '` again, I\'ll assume you mean `' . $_POST['command'] . ' ' .
				$_POST['text'] . '` :slightly_smiling_face:',
			'mrkdwn_in' => array( 'pretext' ),
		);
	}
	*/

	// Send it off!
	send_to_slack( $payload );

} else {

	// No services!

	if ( CALLED_FROM_SLACK_COMMAND ) {

		$payload['text'] = '' .
			'Sorry - I can\'t find any ' . $stop_mode_emoji . ' services from that ' . $stop_mode_stop_singular . '! ' .
			':frowning: The :ptv: server may be down, the ' . $stop_mode_stop_singular . ' may not exist, or another ' .
			'error may have occurred.' . "\n\n" .
			'Please try again later.';

	} else {

		$payload['text'] = '' .
			'Heads up - I can\'t find any ' . $stop_mode_emoji . ' services at the moment. The :ptv: server may ' .
			'be down, or another error may have occurred.' . "\n\n" .
			'You might just have to look outside instead! :sunglasses:';

	}

	send_to_slack( $payload );

} // If trains & else

// The end!
