<?php
/**
 * PRODUCTION CONFIGURATION TEMPLATE
 * Rename this file to db_config.local.php and it will be ignored by Git.
 */

// If you can't use Environment Variables on your host, 
// you can manually set these here:

/*
putenv("DB_HOST=your_host");
putenv("DB_USER=your_user");
putenv("DB_PASSWORD=your_password");
putenv("DB_NAME=your_db_name");
putenv("DB_PORT=3306");
*/

// VAPID Public Key for PWA
define('VAPID_PUBLIC_KEY', 'BFNreAfmhTobh-au7OPtT700AJ8lg4AdxOdtgzECQdbPFbGbBRPyhUh_IgB1bNy0fR8kQd8lAi07FaCQpYjTwMo');

// Socket.IO Server URL (Change this to your Render/Railway URL after deployment)
define('SOCKET_URL', 'http://localhost:3000'); 
?>
