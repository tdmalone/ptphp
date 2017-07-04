<?php

// -------------------------------------------------------- //
// GLOBAL - COMMON & REQUIRED SETTINGS
// -------------------------------------------------------- //

/**
 * The publically accessible URL to the directory holding this script.
 * This is used for sending icon URLs etc. to Slack.
 */
define( 'PUBLIC_URL', 'http://example.com/ptphp' );

/**
 * The username on Slack of the person who has installed and maintains this script.
 * This is sometimes used to send error messages, or to point people to someone to go to for help.
 */
define( 'MAINTAINER_USERNAME', 'your.slack.username' );

/**
 * A custom bot name in Slack for this script.
 * If this is not set, whatever you have specified to Slack for your webhook will be used.
 */
//define( 'CUSTOM_SLACK_BOT_NAME', 'ptphp' );

/**
 * Your Slack webhook URLs.
 *
 * Messages will be sent to ALL defined hooks and channels, UNLESS this file is called from a slash command, in which
 * case the response will go straight back to the user privately as an ephemeral message.
 *
 * This setting can be useful for using this script across multiple Slack organisations, as you can specify completely
 * different hooks. It can also be useful to send messages to multiple channels at once. However, if the channel(s)
 * are not defined, the default one you specified to Slack will be used.
 *
 * Prefix all channels with #. Prefix all usernames for direct messages with @. If testing, it can be useful to set
 * the channel to something like '@'.MAINTAINER_USERNAME
 *
 * Optionally, you can also specify a custom message to be appended for each hook you define. This can be useful if
 * you wish to provide specific instructions to a specific organisation or channel.
 */
define( 'SLACK_WEBHOOKS', array (
  array (
    'hook' => '',
    'channels' => array (
      '@' . MAINTAINER_USERNAME,
    ),
    //'custom_message' => '', // A custom message to add on to the end of any other messages sent
  ),
));

// -------------------------------------------------------- //
// NEXT 5 SERVICES - COMMON & REQUIRED SETTINGS
// -------------------------------------------------------- //

/**
 * If setting this up as a Slack slash command, the token that Slack gives you is required for validating it.
 * You can provide as many tokens as you wish - this makes it easier to use this same configuration for multiple
 * organisations or command integrations.
 */
define( 'SLACK_COMMAND_TOKEN', '' ); // Backwards-compatible single token specification for Slash command.
define( 'SLACK_COMMAND_TOKENS', array ( // Define multiple slash command tokens to spread across different teams.
  '',
));

/**
 * The default stop ID to return services from.
 * Get this from ptv.vic.gov.au/next5 - search for your station, and grab the last 4 digits of the 8 digit number.
 * This can be overridden when called via a slash command.
 */
$stop_id = isset( $_GET['stop'] ) ? $_GET['stop'] : 1033; // 1033 = Canterbury

/**
 * Only return a certain direction by default?
 * This will not apply when used via a slash command, as it is customisable.
 * Valid values here are 'inbound', 'outbound', or at least the first four characters of any line name.
 */
$one_direction = isset( $_GET['direction'] ) ? $_GET['direction'] : 'outbound';

/**
 * How many upcoming services to return.
 */
$train_count = 5; // Backwards-compatible specification.
define( 'HOW_MANY_SERVICES', 5 );

// -------------------------------------------------------- //
// NEXT 5 SERVICES - ADDITIONAL SETTINGS
// -------------------------------------------------------- //

/**
 * You can make messages with multiple service directions a bit prettier by setting emojis based on the direction.
 * Optionally set more than one emoji (works best for outbound), and when multiple lines are available,
 * different emojis will be selected to designate each one.
 * Some of this functionality may not be fully implemented yet.
 */
$inbound_prefix = ':cityscape:'; // Backwards-compatible specification.
$outbound_prefix = ':house:'; // Backwards-compatible specification.
define( 'CUSTOM_EMOJIS', array ( // Specify multiple options to select from.
  'inbound' => array (
    ':cityscape:',
    ':classical_building:',
    ':office:',
  ),
  'outbound' => array (
    ':house:',
    ':house_with_garden:',
    ':deciduous_tree:',
    ':derelict_house_building:',
    ':house_buildings:',
    ':national_park:',
  ),
));

/**
 * By default, you'll be alerted when a train is a short-run and won't take you to the end of the line.
 * You can then request connecting trains for the returned services by typing `/next5 [station] [direction] connecting`.
 * However, you can override this behaviour:
 * - if set to true, connecting trains for short runs will be *automatically* returned when available
 * - if false, you won't be alerted when runs are short, however you can still request connecting trains
 * - if not set, the default behaviour is to advise of short runs, but not return the connecting trains until requested
 * When run through a slash command, `true` will be ignored but can be overridden with the 'connecting' parameter
 */
//$connecting_trains = true;

/**
 * In future, potentially relevant disruptions will be alluded to and you'll be able to request more details with
 * the command `/next5 [station] [direction] disruptions`.
 * However, you'll be able to override this behaviour:
 * - if set to true, relevant disruptions will be *automatically* returned when available
 * - if false, you won't be alerted when there are disruptions, however you can still request them
 * - if not set, the default behaviour is to advise of disruptions but not return them until requested
 * When run through a slash command, `true` will be ignored but can be overridden with the 'connecting' parameter
 */
//$include_disruptions = true;

/**
 * Just want short messages? You can disable all the detail and only get the number of minutes remaining to the next
 * services (or the scheduled time if real-times aren't available). Turning this option on will also override both the
 * $connecting_trains and $include_disruptions options by setting them to false. This option affects slash commands too.
 */
//$condensed_mode = true;

// -------------------------------------------------------- //
// GLOBAL - ADVANCED SETTINGS
// -------------------------------------------------------- //

/**
 * Default endpoint for this API service - backwards compatible specification.
 */
$host = 'https://www.ptv.vic.gov.au';
$endpoint = '/langsing/stop-services';

/**
 * List of available endpoints for this service.
 * Endpoints are assumed to return data in JSON format; if this is not the case you can specify a type of html or xml.
 * If any endpoint is missing or inaccessible, functionality relying on that endpoint will not work.
 * Note that use of some endpoints may not be fully implemented yet.
 */
define( 'API_ENDPOINTS', array (
  'next5'          => array ( 'url' => 'https://www.ptv.vic.gov.au/langsing/stop-services' ),
  'disruptions'    => array ( 'url' => 'https://www.ptv.vic.gov.au/live-travel-updates', 'type' => 'html' ),
  'operator-train' => array ( 'url' => 'http://metrotrains.com.au/api?op=get_healthboard_alerts' ),
  'operator-tram'  => array ( 'url' => 'http://yarratrams.com.au/base/tramTrackerController/TramInfoAjaxRequest' ),
));

/**
 * The location of the local cache. This is used to save disruption info, next services, and user settings.
 * It may be used for other things in the future.
 * This directory MUST be writable by the user that runs this script.
 */
$local_dir = './cache/'; // Backwards-compatible specification (requires a trailing slash).
define( 'LOCAL_CACHE_DIR', './cache' );

/**
 * Set the timezone that will be used to calculate and display service times.
 * See http://php.net/manual/en/timezones.php for valid timezone strings.
 */
$timezone = 'Australia/Melbourne'; // Backwards-compatible specification.
define( 'LOCAL_TIMEZONE', 'Australia/Melbourne' );

/**
 * The cache refresh delay, in seconds.
 * This is the default refresh and is mainly used for next services. Longer refreshes may apply to data that is
 * less likely to change often.
 */
define( 'CACHE_AGE', 1 * 60 );

/**
 * You can completely disable the cache if you like by setting this to true.
 * This is not recommended as it will result in longer loading times and more requests being sent to the APIs.
 */
//define( 'DISABLE_CACHE', true );

/**
 * You can allow the cache to hold stale data - i.e. data that has passed the refresh delay. This completely disables
 * the cache from updating (where appropriate cached data exists), and is useful for testing.
 * This has no effect if the cache is disabled above, and has no effect on data that has never been cached.
//define( 'ALLOW_STALE_CACHE', true );

/**
 * By default this script will output debugging data to wherever it is called from (eg. the browser, console, cron).
 * You can disable that and force silent mode here.
 */
//define( 'SILENT_MODE', true );

// -------------------------------------------------------- //
// LOADER
// -------------------------------------------------------- //

/**
 * Custom logic for determining which lib to load if this was run as a slash command.
 * Loads next5-beta, unless invoked by the maintainer in a direct message (or run directly).
 */
if ( isset( $_POST['token'] ) && in_array( $_POST['token'], SLACK_COMMAND_TOKENS ) ) {
  if ( MAINTAINER_USERNAME === $_POST['user_name'] && 'directmessage' === $_POST['channel_name'] ) {
    require( './lib/next5-alpha.php' );
    echo "\n" . '_next5-alpha_';
  } else {
    require( './lib/next5-beta.php' );
    echo "\n" .
      '_The next5 command is in beta - if you run into any issues, please see <@' . MAINTAINER_USERNAME . '>._';
  }
} else {
  require( './lib/next5-alpha.php' );
}

// -------------------------------------------------------- //

// The end!
