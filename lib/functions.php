<?php

/**
 * Helper functions and routines for ptphp.
 */

// Make sure files aren't being run directly without having gone through a config first
if ( ! isset ( $host ) || ! isset( $local_dir ) || ! isset( $timezone ) || ! isset( $endpoint ) ) {
	die( 'This file can\'t be run directly. Please check that you are calling a config file with all the necessary configuration defined.' );
}

// Prevent issues on PTV's end from timing out our script
ini_set( 'default_socket_timeout', 10 );

// Initialise global variables
$html_parsing_errors = array();
$cache_status = '';
$raw_logs = array();

// Massage configuration settings

$filename_cleaners = array( '-', '.', '\\', '/', '?', '&' );
$url = $host . $endpoint;
$local_dir .= str_replace( $filename_cleaners, '', basename( $_SERVER["SCRIPT_NAME"] ) ) . '/';

$server_name = (
	isset( $_SERVER['SERVER_NAME'] ) ?
	$_SERVER['SERVER_NAME'] :
	(
		isset( $_SERVER['SERVER_ADDR'] ) ?
		$_SERVER['SERVER_ADDR'] :
		'server'
	)
);

date_default_timezone_set( $timezone );

$local_file = '' .
	$local_dir .
	str_replace( $filename_cleaners, '', $server_name ) . '-' .
	str_replace( $filename_cleaners, '', $endpoint ) . '.html';

if ( ! is_dir( $local_dir ) ) {
	mkdir( $local_dir, 0777, true );
}

// Set defaults for constants that are not defined
if ( ! defined( 'DISABLE_CACHE' ) ) {
  define( 'DISABLE_CACHE', false );
}
if ( ! defined( 'CACHE_AGE' ) ) {
	if ( isset( $update_delay ) ) { // Backwards-compat
		define( 'CACHE_AGE', $update_delay );
	} else {
		define( 'CACHE_AGE', 5 * 60 );
	}
}
if ( ! defined( 'DEBUG_SKIP_CACHE_UPDATE' ) ) {
	if ( defined( 'ALLOW_STALE_CACHE' ) && ALLOW_STALE_CACHE ) { // Backwards-compat 1
		define( 'DEBUG_SKIP_CACHE_UPDATE', true );
	} elseif ( isset( $disable_updating ) && $disable_updating ) { // Backwards-compat 2
		define( 'DEBUG_SKIP_CACHE_UPDATE', true );
	} else {
		define( 'DEBUG_SKIP_CACHE_UPDATE', false );
	}
}
if ( ! defined( 'DEBUG_SKIP_SENDING_MESSAGES' ) ) {
	define( 'DEBUG_SKIP_SENDING_MESSAGES', false );
}
if ( ! defined( 'DEBUG_SKIP_PREVIOUSLY_SENT_CHECK' ) ) {
	define( 'DEBUG_SKIP_PREVIOUSLY_SENT_CHECK', false );
}

if ( ! defined( 'DEBUG_VERBOSE' ) ) {
	define( 'DEBUG_VERBOSE', false );
}
if ( ! defined( 'CUSTOM_SLACK_CHANNEL' ) ) {
  define( 'CUSTOM_SLACK_CHANNEL', false );
}
if ( ! defined( 'CUSTOM_SLACK_BOT_NAME' ) ) {
  define( 'CUSTOM_SLACK_BOT_NAME', false );
}
if ( ! defined( 'SILENT_MODE' ) ) {
  define( 'SILENT_MODE', false );
}

// If token is set, check allowed tokens to see if it passes
if (
	isset( $_POST['token'] ) && defined( 'SLACK_COMMAND_TOKENS' ) && in_array( $_POST['token'], SLACK_COMMAND_TOKENS )
) {
	define( 'CALLED_FROM_SLACK_COMMAND', true );
} elseif ( // backwards-compat
	isset( $_POST['token'] ) && defined( 'SLACK_COMMAND_TOKEN' ) && SLACK_COMMAND_TOKEN === $_POST['token']
) {
	define( 'CALLED_FROM_SLACK_COMMAND', true );
} elseif ( isset( $_POST['token'] ) ) {
	exit( 'Error: invalid token. Please contact <@' . MAINTAINER_USERNAME . '> for help.' );
} else {
	define( 'CALLED_FROM_SLACK_COMMAND', false );
}

// Helper functions

/**
 * Prints data using print_r wrapped by pre tags, for easy reading in the browser. Also encodes HTML entities so that
 * data displays properly (especially relevant for Slack markdown links which look like HTML tags), and disables any
 * output if SILENT_MODE is on or the script is called from a slash command.
 */
function preint( $data ) {

	if ( SILENT_MODE || CALLED_FROM_SLACK_COMMAND ) {
		return;
	}
	
	echo '<pre>';
	echo htmlentities( print_r( $data, true ) );
	echo '</pre>';

}

/**
 * Decides whether to echo debugging data to the browser/console or not - by default this always happens but can be
 * switched off with the SILENT_MODE contstant. It never happens when called from a slash command, because otherwise
 * that text output would go straight back to Slack.
 */
function vecho( $data ) {

	if ( SILENT_MODE || CALLED_FROM_SLACK_COMMAND ) {
		return;
	}

	echo $data;

}

/**
 * Attempts to find a match in one string, and if not, tries again in a second string.
 * Useful for sending disruption info from one source to check it, and trying a second source if the first source's
 * information could not be successfully parsed.
 */
function preg_match_double( $regex, $string1 = '', $string2 = '' ) {

	$matches = [];

	if ( $string1 ) {
		preg_match( $regex, $string1, $matches );
	}

	if ( ! count( $matches ) && $string2 ) {
		preg_match( $regex, $string2, $matches );
	}

	return $matches;

}

/**
 * Gets a cached file, or fetches and caches it.
 */
function get_cached_file( $filename, $url, $data_type = '', $age_override = false ) {

	global $cache_status, $raw_logs;
	$local_cache_status = '';
	$date_format = 'd/m g:i:s a';
	$basename = pathinfo( $filename, PATHINFO_FILENAME );
	$cache_age = $age_override ? $age_override : CACHE_AGE;

	$data_type_readable = (
		$data_type ? (
			' for ' . str_replace( '-', ' ', $data_type ) . ' ' . (
				'disruption-info' === $data_type ?
				$basename . ' ' :
				''
			)
		) : ''
	);

	if (
		! DISABLE_CACHE &&
		file_exists( $filename ) &&
		( DEBUG_SKIP_CACHE_UPDATE || filemtime( $filename ) > ( time() - $cache_age ) )
	) {

		$local_cache_status .= '' .
			'Using cache' . $data_type_readable . ' ' .
			'(max age of ' . $cache_age . ' seconds); ';
		$raw = file_get_contents( $filename );

		if ( ! strlen( $raw ) ) {
			$local_cache_status .= 'No data available in local cache.<br />' . "\n";
		}

		$last_updated = filemtime( $filename );

	} else {

		if ( file_exists( $filename ) ) {
			$local_cache_status .= '' .
				'Cache' . $data_type_readable . ' ' .
				'last updated at ' . date( $date_format, filemtime( $filename ) ) . ', ' .
				'updating now... ';
		} else {
			$local_cache_status .= 'Looking' . $data_type_readable . ' for the first time... ' . "\n";
		}

		$raw = file_get_contents( $url );

		if ( strlen( $raw ) ) { // Only save cache if we have data
			file_put_contents( $filename, $raw );
		} else {
			$local_cache_status .= 'No data received from server.<br />' . "\n";
		}

		$last_updated = time();

	}

	if ( $raw ) {
		$local_cache_status .= '' .
			'updated at ' . date( $date_format, $last_updated ) . ', ' .
			'it is now ' . date( $date_format ) . '.<br />' . "\n";
	}

	vecho( $local_cache_status );

	$cache_status .= $local_cache_status;
	$raw_logs[] = array( 'filename' => $filename, 'raw' => $raw );

	return $raw;

}

/**
 * Parses the HTML in a document and returns a valid SimpleXML object.
 * Buffers any HTML markup errors and stores them for output later.
 */
function parse_html( $raw, $filename = '' ) {

	global $html_parsing_errors;

	if ( ! $raw ) {
		return false;
	}

	// HT to:
	// - http://php.net/manual/en/domdocument.loadhtml.php
	// - http://php.net/manual/en/function.simplexml-import-dom.php
	// - http://stackoverflow.com/questions/3577641/how-do-you-parse-and-process-html-xml-in-php
	// - http://stackoverflow.com/questions/6635849/can-simplexml-be-used-to-rifle-through-html
	// - http://stackoverflow.com/a/2867601/1982136

	$document = new DOMDocument();
	ob_start();
	$document->loadHTML( $raw ); $html_parse_line = __LINE__;
	$local_html_parsing_errors = ob_get_clean();
	$xml = simplexml_import_dom( $document );

	// Clean up HTML parsing errors

	if ( $local_html_parsing_errors ) {

		$local_html_parsing_errors = explode( '<br />', $local_html_parsing_errors );
		$local_html_parsing_errors = array_map( 'trim', $local_html_parsing_errors );
		$local_html_parsing_errors = array_map( 'strip_tags', $local_html_parsing_errors );
		$local_html_parsing_errors = array_map( function( $item ) use ( $html_parse_line, $filename) {

			$cleansed_line = str_replace(
				array (
					'Warning: ',
					'DOMDocument::loadHTML(): ',
					' in Entity',
					' in ' . __FILE__ . ' on line ' . $html_parse_line,
				),
				'',
				$item
			);

			if ( $cleansed_line && $filename ) {
				$cleansed_line .= ' (' . basename( $filename ) . ')';
			}

			return $cleansed_line;

		} , $local_html_parsing_errors );

		$local_html_parsing_errors = array_filter( $local_html_parsing_errors ); // Remove any blank lines
		$html_parsing_errors = array_merge( $html_parsing_errors, $local_html_parsing_errors );

	}

	return $xml;

} // Function parse_html

/**
 * Combines the get_cached_file() and parse_html() functions, with the added advantage of
 * better error reporting because the filename is known.
 */
function get_cached_xml( $filename, $url, $data_type = '', $age_override = false ) {

	$raw = get_cached_file( $filename, $url, $data_type, $age_override );
	$xml = parse_html( $raw, $filename );

	return $xml;

}

/**
 * Combines get_cached_file() and parse_json() for shortness.
 */
function get_cached_json( $filename, $url, $data_type = '', $age_override = false ) {

	$raw = get_cached_file( $filename, $url, $data_type, $age_override );
	$json = json_decode( $raw );

	return $json;

}

/**
 * Returns a 'semi-intelligent' clock icon that is as close as possible to the current time
 * (or another provided timestamp).
 */
function get_clock_emoji( $time = '' ) {

	if ( '' === $time ) {
		$time = time();
	}

	$hour = date( 'g', $time );
	$minute = date( 'i', $time );

	if ( $minute < 15 ) {
		$minute = '';
	} elseif ( $minute >= 15 && $minute < 45 ) {
		$minute = '30';
	} else {
		$minute = '';
		$hour++;
	}

	if ( 13 === $hour ) {
		$hour = '1';
	}

	return ':clock' . $hour . $minute . ':';

} // Function get_clock_emoji

/**
 * Cleans a variety of information from a string that we don't need.
 */
function clean_string( $string ) {

	// Remove extra commas in dates
	$string = preg_replace( '/\b(\w.*?),( \d.*? \w.*?)\b/', '$1$2', $string );

	// Replace dots in times with colons, partly because it looks better but mainly to prevent us accidentally
	// assuming they're the end of a sentence. This should match times like 12.59pm, 1.00pm and 3.40
	$string = preg_replace( '/\b(\d\d?)\.(\d\d\w?\w?)\b/', '$1:$2', $string );

	// In any dates, refer to yesterday, today and tomorrow if possible
	$_dateformat = 'l j F';
	$string = str_replace(
		array( date( $_dateformat, time() - 86400 ), date( $_dateformat ), date( $_dateformat, time() + 86400 ) ),
		array( 'yesterday', 'today', 'tomorrow' ),
		$string
	);

	// Clean up relative day replacements a little
	$string = str_replace(
		array( 'on yesterday', 'on today', 'on tomorrow' ),
		array( 'yesterday', 'today', 'tomorrow' ),
		$string
	);
	$string = str_replace(
		array( 'last service today' ),
		array( 'last service tonight' ),
		$string
	);

	// Shorten the remaining day and month names
	$string = str_replace(
		array(
			'January', 'February', 'August', 'September', 'October', 'November', 'December',
			'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
		),
		array(
			'Jan', 'Feb', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec',
			'Mon', 'Tues', 'Wed', 'Thurs', 'Fri', 'Sat', 'Sun',
		),
		$string
	);

	// Shorten or remove common words and phrases
	$string = preg_replace(
		array(
			'/\bFlinders Street\b/i',
			'/\btrain services\b/i',
			'/\brail replacement bus\b/i',
			'/\b(.(?<!rail ))\blines\b/i', // to match eg. 'Belgrave & Lilydale lines' but not 'rail lines'
			'/\bline\b/i',
			'/\bfor stations\b/i', '/\bstations\b/i', '/\bstation\b/i',
			'/\b' . date( 'Y' ) . '\b/', // Current year
			'/\b' . date( 'Y', time() + ( 60 * 60 * 24 * 365 ) ) . '\b/', // Next year
			'/please listen for announcements\./i',
			'/please speak with metro staff\./i',
			'/please listen for announcements or speak with metro staff\./i',
			'/please listen for announcements as some services may be altered at short notice\./i',
			'/please listen for announcements as we recover the timetable\./i',
		),
		array(
			'Flinders St',
			'trains',
			'bus',
		),
		$string
	);

	// Remove extra spaces before punctation marks (including double spaces)
	$string = str_replace( array( ' ,', ' .', ' :', '  ' ), array( ',', '.', ':', ' ' ), $string );

	// Replace non-breaking spaces
	// HT: http://php.net/manual/en/function.trim.php#98812)
	$string = str_replace( chr( 0xC2 ) . chr( 0xA0 ), ' ', $string );

	// Trim excess characters
	$string = trim( $string, ' ,.:' . "\n" );

	return $string;

}

function htmlAttribute( $attr, $value ) {

	// For use within xpath, eg. `$xml->xpath( htmlAttribute( 'class', 'name-of-html-class' ) );`

	// HT to:
	// - http://stackoverflow.com/a/9133579/1982136
	// - http://stackoverflow.com/a/8219091/1982136

	return './/*[contains(concat(" ", normalize-space(@' . $attr . '), " "), " ' . $value . ' ")]';

}

function emojify( $text, $blacklist = array() ) {

	$terms = array (
		'ill passenger', 'ill customer',
		'unruly passenger', 'unruly customer',
		'police', 'ambulance', 'fire', 'CFA', 'MFB', 'vehicle',
		'buses', 'bus', 'trains', 'train',
		'railway tracks', 'tracks', 'track', 'trackworks',
		'signal', 'signals', 'signalling',
		'lightning', 'heat', 'rain',
		'person', 'trespasser', 'trespassers', 'announcements',
		'flood', 'flooded',
		'operational issues$', 'both directions$', 'citybound$',
		'CBD area$',
	);

	$emojis = array (
		'ill passenger :mask:', 'ill passenger :mask:',
		':rage: passenger', ':rage: passenger',
		':oncoming_police_car:', ':ambulance:', ':fire:', ':fire_engine:', ':fire_engine:', ':car:',
		':bus:', ':bus:', ':steam_locomotive:', ':steam_locomotive:',
		':railway_track:', ':railway_track:', ':railway_track:', ':railway_track: works',
		':traffic_light:', ':traffic_light:', ':traffic_light:',
		':lightning:', ':hotsprings:', ':umbrella_with_rain_drops:',
		':walking:', ':runner:', ':runner: :runner:', ':loudspeaker:',
		'flood :umbrella_with_rain_drops:', 'flooded :umbrella_with_rain_drops:',
		'operational issues :thinking_face:', 'both directions :left_right_arrow:', 'citybound :cityscape:',
		'CBD area :cityscape:',
	);

	// TODO: enable a blacklist of terms not to replace
	//$terms = array_remove_items_in( $blacklist, $terms );

	// Turn terms into regexp patterns, ensuring case-insensitivy and whole word search
	$terms = array_map( function( $item ) {
		return '/\b' . $item . '\b/i';
	}, $terms );

	// Do the replacements!
	$text = preg_replace( $terms, $emojis, $text );

	// Remove commas before emojis as it looks a little weird
	$text = str_replace( ', :', ' :', $text );

	// Fix double replacements if they happened (eg. bus -> :bus: -> ::bus::)
	$text = str_replace( '::', ':', $text );

	return $text;

}

function get_minutes_to_go( $time_realtime_utc ) {
	if ( $time_realtime_utc ) {
		$minutes_to_go = round( ( strtotime( $time_realtime_utc ) - time() ) / 60 );
		$minutes_to_go = $minutes_to_go - 1; // Add a protection minute due to late reporting
	} else {
		$minutes_to_go = false;
	}
	return $minutes_to_go;
}

function get_stopping_pattern( $num_skipped, $stops_term = 'stations' ) {
	if ( 0 == $num_skipped ) {
		$stopping_pattern = 'all-' . $stops_term;
	} elseif ( 1 == $num_skipped || 2 == $num_skipped ) {
		$stopping_pattern = 'limited-express';
	} elseif ( $num_skipped > 2 ) {
		$stopping_pattern = 'express';
	} else {
		$stopping_pattern = '';
	}
	return $stopping_pattern;
}

/**
 * Can we shorten the line or destination name to fit long ones in?
 * This is particularly useful for connecting trains which have less space, and bus/tram line/stop names which can be
 * quite long.
 */
function get_short_name( $name ) {

	$name = str_replace(
		array (
			'Ferntree Gully',
			'Mooroolbark',
			'Upper Ferntree Gully',
			'Melbourne Central',
			'Flinders Street',
			'North Melbourne',
			'Southern Cross',
			'Camberwell',
			'Canterbury',
			'Glenferrie',
			'Heatherdale',
			'Nunawading',
			'Parliament',
			'Springvale',
			'Shopping Centre',
			'Railway Station',
			'Junction',
			'Terminus',
			'Interchange',
			'Street',
			'Road',
			'Highway',
		),
		array (
			'F\'tree Gully',
			'M\'bark',
			'Upper FTG',
			'Melb Central',
			'Flinders St',
			'Nth Melb',
			'Sthn Cross',
			'C\'well',
			'C\'bury',
			'G\'ferrie',
			'H\'dale',
			'N\'wading',
			'P\'ment',
			'S\'vale',
			'Shops',
			'Station',
			'Junc.',
			'Term.',
			'Int\'change',
			'St',
			'Rd',
			'Hwy',
		),
		$name
	);

	return $name;

}

function send_to_slack( $payload, $message_ids_and_text = array(), $deprecated = 0 ) {

	global $local_dir;

	// If called from a Slack command, echo the output and return
	if ( CALLED_FROM_SLACK_COMMAND ) {

		if ( ! isset( $_POST['_mode'] ) ) {

			echo ( isset( $payload['text'] ) ? $payload['text'] : '' ) . "\n";

			// Loop through each of the attachments to ensure we echo all the output appropriately
			foreach ( $payload['attachments'] as $attachment ) {
				$z = 0;
				if ( isset( $attachment['pretext'] ) && $attachment['pretext'] ) {
					echo $attachment['pretext'];
				}
				if ( isset( $attachment['text'] ) && $attachment['text'] ) {
					echo $attachment['text'];
				}
				if ( isset( $attachment['fields'] ) ) {
					foreach ( $attachment['fields'] as $field ) {
						if ( 0 !== $z ) {
							echo ' - ';
						}
						echo $field['value'];
						$z++;
					}
				}
				echo "\n";
			}

		} elseif ( 'use_response_url' === $_POST['_mode'] ) {

			$result = do_curl( $payload, array( 'hook' => $_POST['response_url'] ) );

		} // No mode, or mode use_response_url

		return;

	} // If called from slack command

	foreach ( SLACK_WEBHOOKS as $webhook ) {
		foreach ( $webhook['channels'] as $channel ) {

			$payload['channel'] = $channel;
			$result = do_curl( $payload, $webhook );

			// If the messages were sent - and we have disruption IDs sent through - store them in the cache
			// so we don't send the same messages again.
			if ( 'ok' === $result && count( $message_ids_and_text ) ) {
				foreach ( $message_ids_and_text as $message ) {
					if ( is_array( $message ) && $message['id'] ) { // New way: an array of arrays
						file_put_contents( $local_dir . $message['id'] . '.disruption', $message['text'] );
					} elseif ( ! is_array( $message ) && $message ) { // Old way: an array of IDs
						touch( $local_dir . $message . '.disruption' );
					}
				}
				if ( $deprecated ) { // The _older_ way was to just send through one ID at a time
					touch( $local_dir . $deprecated . '.disruption' );
				}
			}

			// Follow up with an optional custom message, if set
			if ( isset( $webhook['custom_message'] ) ) {

				$custom_payload = array (
					'channel' => $channel,
					'text' => $webhook['custom_message'],
				);

				do_curl( $custom_payload, $webhook );

			}
			
		} // For each channel
	} // For each web hook
} // Function send_to_slack

function do_curl( $payload, $webhook ) {

	if ( defined( 'CUSTOM_SLACK_BOT_NAME' ) && false !== CUSTOM_SLACK_BOT_NAME ) {
		$payload['username'] = CUSTOM_SLACK_BOT_NAME;
	}

	if ( defined( 'CUSTOM_SLACK_BOT_EMOJI' ) && false !== CUSTOM_SLACK_BOT_EMOJI ) {
		$payload['icon_emoji'] = CUSTOM_SLACK_BOT_EMOJI;
	}

	$params = 'payload=' . urlencode( json_encode( $payload ) );

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $webhook['hook'] );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $params );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	$result = curl_exec( $ch );

	preint( $result );

	if ( false === $result ) {
		preint( curl_error( $ch ) );
	}

	curl_close( $ch );

	return $result;

}

// The end!
