            <?php
// Google OAuth config probe — DELETE THIS FILE after diagnosis
require_once __DIR__ . '/../config/app.php';

echo '<pre>';
echo 'GOOGLE_CLIENT_ID     : ' . (GOOGLE_CLIENT_ID     ? substr(GOOGLE_CLIENT_ID, 0, 12) . '...' : '** EMPTY **') . "\n";
echo 'GOOGLE_CLIENT_SECRET : ' . (GOOGLE_CLIENT_SECRET ? '*** set ***'                           : '** EMPTY **') . "\n";
echo 'GOOGLE_REDIRECT_URI  : ' . GOOGLE_REDIRECT_URI . "\n";
echo 'BASE_URL             : ' . BASE_URL . "\n";
echo '.env path            : ' . dirname(__DIR__) . '/.env' . "\n";
echo '.env exists          : ' . (file_exists(dirname(__DIR__) . '/.env') ? 'YES' : 'NO') . "\n";
echo 'getenv raw           : ' . (getenv('GOOGLE_CLIENT_ID') ? 'set' : 'EMPTY') . "\n";
echo '</pre>';
