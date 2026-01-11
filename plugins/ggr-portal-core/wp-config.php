<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'ggrincome_wp60' );

/** Database username */
define( 'DB_USER', 'ggrincome_wp60' );

/** Database password */
define( 'DB_PASSWORD', ')(SI9(p]K43e344]' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'gqgoeo8dxnrsluxfypqzgw1docdbi4wokhsl5f36j8mozoragahlqssyyvehyedv' );
define( 'SECURE_AUTH_KEY',  'an5807btnshe6kqfp4v4svmrkagglqu7jgpfi5bunjetathkec30ppxc30mxvxmv' );
define( 'LOGGED_IN_KEY',    'cx3nxn0wubkab4gwuerax2zqzvhuujghfugfihjydi6ipgzn2jlu06coj0qloaut' );
define( 'NONCE_KEY',        'syvuz5ptnpjtuldgagrjmxmuroietjxchlyuqff2oc4agpntx4jadbw6hla0gd3w' );
define( 'AUTH_SALT',        'knptnhbwisjb7kjiliesrxsotsrva3rnt00lgm5jjqj5n9wsoetlglqwylabslst' );
define( 'SECURE_AUTH_SALT', 'd3rroolfymstako0dktgg4zkqh2hzgfanfn77s5n1clblkfcipxdmvvzua8qqcbf' );
define( 'LOGGED_IN_SALT',   'ir9scfnk6tl7gz8d9k3y0cxpy4azrloxdl3askscdgbdt5akv1o4nuntcyjwav5n' );
define( 'NONCE_SALT',       '5wwfjhfp17ionrrpjnhrarvqa05deapzzifceaiklgiznylhg8ap58lbxlkeiveb' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wpq7_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

// HubSpot – Private App
define('GGR_HUBSPOT_PRIVATE_APP_TOKEN', 'xxxx');

//**api koppeling met HubSpot
define('GGR_HUBSPOT_PIPELINE_ID', '2984854725');

define('GGR_HUBSPOT_STAGE_REGISTER', '4087996619');
define('GGR_HUBSPOT_STAGE_CONFIRMED', '4087996620');
define('GGR_HUBSPOT_STAGE_COLLECTING', '4087996621');
define('GGR_HUBSPOT_STAGE_VALIDATING', '4087996622');
define('GGR_HUBSPOT_STAGE_SIGN_CONTRACT', '4087996623');
define('GGR_HUBSPOT_STAGE_TRANSFER_FUNDS', '4151482593'); 
define('GGR_HUBSPOT_STAGE_ACTIVE_PARTICIPANT', '4087996624');

// Strongly recommended (anders is je endpoint te makkelijk te misbruiken)
define('GGR_HUBSPOT_WEBHOOK_SECRET', 'lasbd123abda-aksbdla!212');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// IBKR – Flex Web Service token en Query ID's als constants
define( 'GGR_IBKR_FLEX_TOKEN', '928261552440963239860871' );
define( 'GGR_IBKR_FLEX_QUERY_ID', '1353202' );
define( 'GGR_IBKR_FLEX_ACCRUALS_QUERY_ID', '1359137' );

// MOLLIE API KEY
define( 'GGR_MOLLIE_API_KEY', 'test_u7sn5haP2jfyFzUrTEFa6qxMx8qrPe' );
define('GGR_MOLLIE_WEBHOOK_SECRET', 'zet-hier-een-lange-random-string');

