<?php
// api/staff.php - ULTIMATE VERSION (Attendance, Logs & Notifications)
require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

switch($action) {
    // --- MANAJEMEN STAFF (ADMIN) ---
    case 'get_all_staff':
        getAllStaff($db);
        break;
        
    case 'get_access_codes':
        getAccessCodes($db);
        break;
        
    case 'generate_code':
        if ($_SERVER['REQUEST_METHOD'] != 'POST') sendResponse(false, "Method error");
        generateCode($db, $input);
        break;
        
    case 'toggle_status':
        if ($_SERVER['REQUEST_METHOD'] != 'POST') sendResponse(false, "Method error");
        toggleStatus($db, $input);
        break;

    // --- ABSENSI & MONITORING ---
    case 'attendance': // Clock In / Out
        if ($_SERVER['REQUEST_METHOD'] != 'POST') sendResponse(false, "Method error");
        handleAttendance($db, $input);
        break;

    case 'get_activity_logs': // Untuk Admin Monitor
        getActivityLogs($db);
        break;

    // --- NOTIFIKASI ---
    case 'get_notifications':
        $role = $_GET['role'] ?? '';
        $uid = $_GET['user_id'] ?? 0;
        getNotifications($db, $role, $uid);
        break;
        
    case 'mark_notif_read':
        markNotifRead($db, $input);
        break;

    default:
        sendResponse(false, "Invalid action: $action");
}

// ==========================================
// 1. FITUR ABSENSI (CLOCK IN/OUT)
// ==========================================
function handleAttendance($db, $input) {
    if (empty($input['user_id']) || empty($input['type'])) {
        // type: 'in' atau 'out'
        sendResponse(false, "Data tidak lengkap");
    }

    $uid = $input['user_id'];
    $type = $input['type']; // 'in' = Clock In, 'out' = Clock Out
    $today = date('Y-m-d');

    try {
        if ($type == 'in') {
            // Cek apakah sudah absen masuk hari ini
            $chk = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND DATE(clock_in) = ? AND clock_out IS NULL");
            $chk->execute([$uid, $today]);
            if ($chk->rowCount() > 0) {
                sendResponse(false, "Anda sudah melakukan Clock In hari ini dan belum Clock Out.");
            }

            $stmt = $db->prepare("INSERT INTO attendance (user_id, clock_in, status) VALUES (?, NOW(), 'present')");
            $stmt->execute([$uid]);
            
            logActivity($db, $uid, 'CLOCK_IN', "Staff memulai shift kerja");
            sendResponse(true, "Berhasil Clock In. Selamat Bekerja!");

        } else if ($type == 'out') {
            // Cari record terakhir yg belum clock out
            $chk = $db->prepare("SELECT id FROM attendance WHERE user_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1");
            $chk->execute([$uid]);
            $att = $chk->fetch(PDO::FETCH_ASSOC);

            if (!$att) {
                sendResponse(false, "Anda belum melakukan Clock In.");
            }

            $stmt = $db->prepare("UPDATE attendance SET clock_out = NOW() WHERE id = ?");
            $stmt->execute([$att['id']]);

            logActivity($db, $uid, 'CLOCK_OUT', "Staff mengakhiri shift kerja");
            sendResponse(true, "Berhasil Clock Out. Terima kasih!");
        }

    } catch (Exception $e) {
        sendResponse(false, "Error Attendance: " . $e->getMessage());
    }
}

// ==========================================
// 2. FITUR MONITORING (LOG AKTIVITAS)
// ==========================================
function getActivityLogs($db) {
    // Ambil 50 aktivitas terakhir untuk Admin Dashboard
    $sql = "SELECT l.*, u.name as user_name, u.role 
            FROM activity_logs l 
            LEFT JOIN users u ON l.user_id = u.id 
            ORDER BY l.created_at DESC LIMIT 50";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format tanggal agar enak dibaca frontend
    foreach ($logs as &$log) {
        $log['time_ago'] = time_elapsed_string($log['created_at']);
    }

    sendResponse(true, "Success", $logs);
}

// Helper Time Ago
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'tahun', 'm' => 'bulan', 'w' => 'minggu',
        'd' => 'hari', 'h' => 'jam', 'i' => 'menit', 's' => 'detik',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v;
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' yang lalu' : 'baru saja';
}

// ==========================================
// 3. FITUR NOTIFIKASI
// ==========================================
function getNotifications($db, $role, $userId) {
    // Ambil notif berdasarkan Role ATAU User ID spesifik
    // Misal: Pesan untuk 'waiter' atau pesan khusus untuk user ID 5
    $sql = "SELECT * FROM notifications 
            WHERE (target_role = ? OR target_user_id = ?) 
            AND is_read = 0 
            ORDER BY created_at DESC LIMIT 20";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([$role, $userId]);
    sendResponse(true, "Success", $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function markNotifRead($db, $input) {
    if (empty($input['notif_id'])) return;
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?")->execute([$input['notif_id']]);
    sendResponse(true, "Read");
}

// ==========================================
// 4. MANAJEMEN STAFF (EXISTING)
// ==========================================
function getAllStaff($db) {
    // Tambahkan info status kehadiran terakhir (is_online)
    $query = "SELECT u.id, u.name, u.email, u.role, u.phone, u.is_active, u.created_at,
              (SELECT status FROM attendance WHERE user_id = u.id AND clock_out IS NULL ORDER BY id DESC LIMIT 1) as attendance_status
              FROM users u 
              WHERE u.role != 'admin' 
              ORDER BY u.role, u.name";
              
    $stmt = $db->prepare($query);
    $stmt->execute();
    sendResponse(true, "Success", $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getAccessCodes($db) {
    $query = "SELECT * FROM staff_access_codes WHERE is_used = 0 ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    sendResponse(true, "Success", $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function generateCode($db, $input) {
    if (empty($input['role']) || empty($input['created_by'])) {
        sendResponse(false, "Data tidak lengkap");
    }

    $prefix = strtoupper($input['role']);
    $random = rand(100, 999);
    $code = "$prefix-$random-" . date('Hi'); // Tambah jam biar unik

    try {
        $stmt = $db->prepare("INSERT INTO staff_access_codes (code, target_role, created_by) VALUES (?, ?, ?)");
        $stmt->execute([$code, $input['role'], $input['created_by']]);
        
        logActivity($db, $input['created_by'], 'GENERATE_CODE', "Membuat kode akses untuk $prefix");
        sendResponse(true, "Kode berhasil dibuat", ['code' => $code]);
    } catch (Exception $e) {
        sendResponse(false, "Gagal: " . $e->getMessage());
    }
}

function toggleStatus($db, $input) {
    if (empty($input['user_id']) || !isset($input['status'])) {
        sendResponse(false, "Data tidak lengkap");
    }

    $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
    if ($stmt->execute([$input['status'], $input['user_id']])) {
        sendResponse(true, "Status user berhasil diupdate");
    } else {
        sendResponse(false, "Gagal update");
    }
}
?>