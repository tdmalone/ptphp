Hi there! I'm a PHP interface to the PTV live travel updates website. I scrape data, turn it into a common format, and then post it to Slack.

I'm currently in __BETA__.

To use me, clone me to a machine with PHP on it, copy `config.sample.php` to a new file, enter your configuration in there, and set me up in cron like this:

    */7 4-23 * * * (cd /path/to/cloned/repository; php whatever-i-called-my-config-file.php)

This would run the script with your config every 7 minutes (or so), between 4am and midnight, every day.

You can also try setting up a Slack Slash command and typing eg. `/next5` in Slack to get the next 5 trains to leave from your stops. Additional arguments are supported - `inbound`, `outbound`, some variations on those, and some station names. This service is also in __BETA__!
