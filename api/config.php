<?php
// config.php - keep this file outside webroot if possible, and never commit secret to git.
// Example values — replace with secure ones and permissions 600.

define('REPORT_SECRET', 'replace_with_a_strong_random_secret_at_least_32_chars'); // e.g. bin2hex(random_bytes(32))

// mail settings (example placeholders)
define('MAIL_FROM', 'no-reply@yourdomain.com');