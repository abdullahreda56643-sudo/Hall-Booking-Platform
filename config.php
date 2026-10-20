<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Riyadh');

define('APP_NAME', 'المنصة التفاعلية للقاعة الرقمية');
define('BASE_PATH', __DIR__);
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'hall_platform');
define('DB_USER', getenv('DB_USER') ?: 'codvida');
define('DB_PASS', getenv('DB_PASS') ?: '123456');
define('SESSION_IDLE_TIMEOUT', 1800);
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);
define('MAX_VIDEO_SIZE', 50 * 1024 * 1024);
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('UPLOAD_DIR', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');

const ROLE_ADMIN = 'admin';
const ROLE_STAFF = 'staff';
const SCHEDULE_DAYS = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس'];
const PERIODS_PER_DAY = 8;
const USER_ROLES = [ROLE_ADMIN, ROLE_STAFF];
const REPORT_STATUSES = ['draft', 'submitted', 'approved', 'rejected'];
const REPORT_STATUS_LABELS = [
    'draft' => 'مسودة',
    'submitted' => 'قيد المراجعة',
    'approved' => 'معتمد',
    'rejected' => 'مرفوض',
];
const ROLE_LABELS = [
    ROLE_ADMIN => 'أدمن',
    ROLE_STAFF => 'معلمة',
];
