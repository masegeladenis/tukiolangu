<?php
/**
 * SMS Configuration
 * Using messaging-service.co.tz API
 */

// SMS API Settings
define('SMS_API_URL', 'https://messaging-service.co.tz');
define('SMS_USERNAME', 'herman3');
define('SMS_PASSWORD', 'Herman@22051994');
define('SMS_SENDER_ID', 'SB Notify');

// Generate Base64 authorization
define('SMS_AUTH_TOKEN', base64_encode(SMS_USERNAME . ':' . SMS_PASSWORD));

// Test mode - set to false for production
define('SMS_TEST_MODE', false);
