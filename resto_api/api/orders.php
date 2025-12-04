<?php
// api/orders.php - ULTIMATE VERSION (Fixed Payment Flow & Cancellation)
require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

switch($action) {
    case 'create_order':
        createOrder($db, $input);
        break;
        
    case 'get_orders_by_role': 
        $role = $_GET['role'] ?? '';
        $userId = $_GET['user_id'] ?? 0; 
        getOrdersByRole($db, $role, $userId);
        break;
        
    case 'update_status':
        updateOrderStatus($db, $input);
        break;

    case 'cancel_order': // FITUR BARU: BATALKAN PESANAN
        cancelOrder($db, $input);
        break;

    case 'process_payment': // Khusus CS: Pisah Cash & QRIS
        processPayment($db, $input);
        break;

    case 'get_sales_chart': // Untuk Grafik Dashboard Admin
        getSalesChart($db);
        break;

    case 'get_business_report': // FITUR BARU: Laporan Detail
        getBusinessReport($db, $input);
        break;

    default:
        sendResponse(false, "Invalid action: $action", null, 400);
}

// ==========================================
// 1. BUAT ORDER (WAITER)
// ==========================================
function createOrder($db, $input) {
    if (empty($input['items']) || empty($input['table_id'])) {
        sendResponse(false, "Pilih Meja dan Menu terlebih dahulu!", null, 400);
    }
    
    try {
        $db->beginTransaction();
        
        // A. Cek Stok & Hitung Total
        $totalAmount = 0;
        foreach ($input['items'] as $item) {
            $stmt = $db->prepare("SELECT price, stock, name FROM menu_items WHERE id = ? FOR UPDATE");
            $stmt->execute([$item['id']]);
            $menu = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$menu || $menu['stock'] < $item['quantity']) {
                throw new Exception("Stok '{$menu['name']}' habis/kurang!");
            }
            
            // Hitung harga (handle diskon jika ada logika diskon di masa depan)
            $totalAmount += ($menu['price'] * $item['quantity']);
            
            // Potong Stok
            $db->prepare("UPDATE menu_items SET stock = stock - ? WHERE id = ?")
               ->execute([$item['quantity'], $item['id']]);
        }

        // B. Buat Header Order
        $orderNo = "ORD-" . date("dHi") . "-" . $input['table_id']; 
        $userId = $input['user_id'] ?? 0;
        
        // Pajak 10% + Service 5% (Total 15%)
        // Total yang disimpan di database adalah GRAND TOTAL
        $grandTotal = $totalAmount + ($totalAmount * 0.15);

        $sql = "INSERT INTO orders (order_number, table_id, customer_name, waiter_id, total_amount, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $orderNo, 
            $input['table_id'], 
            $input['customer_name'] ?? 'Guest',
            $userId,
            $grandTotal
        ]);
        $orderId = $db->lastInsertId();

        // C. Masukkan Detail Item
        $sqlItem = "INSERT INTO order_items (order_id, menu_item_id, quantity, price, notes) VALUES (?, ?, ?, ?, ?)";
        $stmtItem = $db->prepare($sqlItem);
        
        foreach ($input['items'] as $item) {
            $price = $db->query("SELECT price FROM menu_items WHERE id = {$item['id']}")->fetchColumn();
            $stmtItem->execute([$orderId, $item['id'], $item['quantity'], $price, $item['notes'] ?? '']);
        }

        // D. Update Status Meja -> Occupied
        $db->prepare("UPDATE tables SET status = 'occupied', current_order_id = ? WHERE id = ?")
           ->execute([$orderId, $input['table_id']]);

        // E. Log Aktivitas & Notifikasi Chef
        if (function_exists('logActivity')) {
            logActivity($db, $userId, 'CREATE_ORDER', "Order baru $orderNo (Meja {$input['table_id']})");
        }
        if (function_exists('createNotification')) {
            createNotification($db, 'chef', 'Order Baru', "Meja {$input['table_id']} memesan makanan.");
        }

        $db->commit();
        sendResponse(true, "Pesanan Berhasil Dibuat!", ['order_id' => $orderId]);

    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, "Gagal Order: " . $e->getMessage());
    }
}

// ==========================================
// 2. AMBIL ORDER (LOGIKA STATUS DIPERBAIKI)
// ==========================================
function getOrdersByRole($db, $role, $userId) {
    $sql = "SELECT o.*, t.table_number 
            FROM orders o 
            LEFT JOIN tables t ON o.table_id = t.id ";
    
    if ($role == 'chef') {
        // Chef hanya melihat yang Pending (Baru) atau Cooking (Sedang Masak)
        $sql .= "WHERE o.status IN ('pending', 'cooking')";
    } 
    else if ($role == 'waiter') {
        // Waiter melihat Ready (Siap Antar) atau Served (Sudah diantar, untuk dipantau)
        $sql .= "WHERE o.status IN ('ready', 'served')";
    } 
    else if ($role == 'cs') {
        // PERBAIKAN: CS harus melihat 'served' (sudah makan/diantar) agar bisa diproses bayar
        // Payment_pending = Tamu minta bill
        // Served = Tamu masih makan (bisa langsung bayar juga)
        // Completed = Riwayat hari ini
        $sql .= "WHERE o.status IN ('served', 'payment_pending', 'completed') AND DATE(o.created_at) = CURDATE()";
    } 
    else if ($role == 'admin' || $role == 'manager') {
        // Admin lihat semua yang aktif hari ini + yang belum lunas
        $sql .= "WHERE o.status != 'cancelled'";
    } 
    
    $sql .= " ORDER BY o.created_at DESC"; // Order terbaru diatas

    $stmt = $db->prepare($sql);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil Detail Menu untuk setiap order
    foreach ($orders as &$order) {
        $si = $db->prepare("SELECT oi.quantity, oi.notes, m.name, m.image_url, oi.price 
                            FROM order_items oi 
                            JOIN menu_items m ON oi.menu_item_id = m.id 
                            WHERE oi.order_id = ?");
        $si->execute([$order['id']]);
        $order['items'] = $si->fetchAll(PDO::FETCH_ASSOC);
    }

    sendResponse(true, "Success", $orders);
}

// ==========================================
// 3. UPDATE STATUS & NOTIFIKASI
// ==========================================
function updateOrderStatus($db, $input) {
    if (empty($input['order_id']) || empty($input['status'])) {
        sendResponse(false, "Data tidak lengkap");
    }

    $oid = $input['order_id'];
    $newStatus = $input['status'];
    $uid = $input['user_id'] ?? 0;

    $stmt = $db->prepare("UPDATE orders SET status = ? WHERE id = ?");
    if ($stmt->execute([$newStatus, $oid])) {
        
        $msg = "Status diperbarui";
        $notifRole = '';
        $notifMsg = '';

        if ($newStatus == 'cooking') {
            $msg = "Mulai memasak...";
        } 
        else if ($newStatus == 'ready') {
            $msg = "Makanan Siap Saji!";
            $notifRole = 'waiter';
            $notifMsg = "Order #$oid siap diantar!";
        }
        else if ($newStatus == 'served') {
            $msg = "Makanan telah diantar.";
        }
        else if ($newStatus == 'payment_pending') {
            $msg = "Permintaan Bill dikirim ke Kasir.";
            $notifRole = 'cs';
            $notifMsg = "Order #$oid meminta bill/pembayaran.";
        }

        // Log & Notif
        if (function_exists('logActivity')) logActivity($db, $uid, 'UPDATE_STATUS', "Order #$oid -> $newStatus");
        if ($notifRole && function_exists('createNotification')) createNotification($db, $notifRole, 'Update Status', $notifMsg);
        
        sendResponse(true, $msg);
    } else {
        sendResponse(false, "Gagal update status");
    }
}

// ==========================================
// 4. BATALKAN PESANAN (FITUR BARU)
// ==========================================
function cancelOrder($db, $input) {
    $oid = $input['order_id'];
    $uid = $input['user_id'] ?? 0;
    $reason = $input['reason'] ?? 'Dibatalkan User';

    // Cek status dulu. Jika sudah 'cooking', tidak bisa batal sembarangan (harus manager)
    $chk = $db->prepare("SELECT status, table_id FROM orders WHERE id = ?");
    $chk->execute([$oid]);
    $order = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$order) sendResponse(false, "Order tidak ditemukan");
    
    // Validasi: Kalau sudah dimasak/selesai, tidak bisa batal via waiter biasa
    if (in_array($order['status'], ['ready', 'served', 'completed'])) {
        sendResponse(false, "Order sudah diproses/selesai, tidak bisa dibatalkan!");
    }

    try {
        $db->beginTransaction();

        // 1. Update Status
        $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$oid]);

        // 2. Balikkan Stok (PENTING!)
        $items = $db->prepare("SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?");
        $items->execute([$oid]);
        while ($row = $items->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare("UPDATE menu_items SET stock = stock + ? WHERE id = ?")
               ->execute([$row['quantity'], $row['menu_item_id']]);
        }

        // 3. Set Meja jadi Available lagi
        $db->prepare("UPDATE tables SET status = 'available', current_order_id = NULL WHERE id = ?")
           ->execute([$order['table_id']]);

        // 4. Log
        if (function_exists('logActivity')) logActivity($db, $uid, 'CANCEL_ORDER', "Order #$oid dibatalkan: $reason");

        $db->commit();
        sendResponse(true, "Pesanan berhasil dibatalkan dan stok dikembalikan.");

    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, "Gagal membatalkan: " . $e->getMessage());
    }
}

// ==========================================
// 5. PROSES PEMBAYARAN (CS - Split Cash/QRIS)
// ==========================================
function processPayment($db, $input) {
    if (empty($input['order_id']) || empty($input['payment_method'])) {
        sendResponse(false, "Metode pembayaran wajib dipilih!");
    }

    try {
        $db->beginTransaction();
        
        $oid = $input['order_id'];
        $userId = $input['user_id'] ?? 0;
        $method = $input['payment_method']; // 'cash', 'qris', 'debit'

        // 1. Finalize Order
        $stmt = $db->prepare("UPDATE orders SET status = 'completed', payment_status = 'paid', payment_method = ?, cashier_id = ?, payment_time = NOW() WHERE id = ?");
        $stmt->execute([$method, $userId, $oid]);

        // 2. Kosongkan Meja (Set jadi Dirty agar Waiter bersihkan)
        $qInfo = $db->prepare("SELECT table_id, total_amount, order_number FROM orders WHERE id = ?");
        $qInfo->execute([$oid]);
        $res = $qInfo->fetch(PDO::FETCH_ASSOC);
        
        if ($res) {
            $db->prepare("UPDATE tables SET status = 'dirty', current_order_id = NULL WHERE id = ?")
               ->execute([$res['table_id']]);
            
            // LOG PENTING: Pisahkan log activity berdasarkan metode bayar
            if (function_exists('logActivity')) {
                $desc = "Terima Pembayaran {$res['order_number']} via " . strtoupper($method) . " (Rp " . number_format($res['total_amount']) . ")";
                logActivity($db, $userId, 'PAYMENT', $desc);
            }
        }

        $db->commit();
        sendResponse(true, "Pembayaran Berhasil! Data masuk ke Laporan " . strtoupper($method));

    } catch (Exception $e) {
        $db->rollBack();
        sendResponse(false, "Error Payment: " . $e->getMessage());
    }
}

// ==========================================
// 6. ADMIN DASHBOARD & REPORT
// ==========================================
function getSalesChart($db) {
    // Data 7 Hari Terakhir
    $data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime($date)); 
        $daysIndo = ['Sun'=>'Min', 'Mon'=>'Sen', 'Tue'=>'Sel', 'Wed'=>'Rab', 'Thu'=>'Kam', 'Fri'=>'Jum', 'Sat'=>'Sab'];
        
        // Ambil total completed orders
        $stmt = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(created_at) = '$date' AND status = 'completed'");
        $amount = $stmt->fetchColumn();
        
        $data[] = [
            'day' => $daysIndo[$dayName],
            'amount' => (double)$amount,
            'date' => $date
        ];
    }
    sendResponse(true, "Success", $data);
}

function getBusinessReport($db, $input) {
    // Laporan Detail per Metode Pembayaran
    $startDate = $input['start_date'] ?? date('Y-m-01');
    $endDate = $input['end_date'] ?? date('Y-m-d');

    // 1. Total Revenue
    $qTotal = "SELECT COALESCE(SUM(total_amount),0) as total, COUNT(*) as count FROM orders WHERE status='completed' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'";
    $total = $db->query($qTotal)->fetch(PDO::FETCH_ASSOC);

    // 2. Split Cash vs Non-Cash
    $qMethods = "SELECT payment_method, COALESCE(SUM(total_amount),0) as total, COUNT(*) as count 
                 FROM orders 
                 WHERE status='completed' AND DATE(created_at) BETWEEN '$startDate' AND '$endDate'
                 GROUP BY payment_method";
    $methods = $db->query($qMethods)->fetchAll(PDO::FETCH_ASSOC);

    // 3. Menu Terlaris
    $qMenu = "SELECT m.name, SUM(oi.quantity) as qty, SUM(oi.quantity * oi.price) as revenue
              FROM order_items oi
              JOIN orders o ON oi.order_id = o.id
              JOIN menu_items m ON oi.menu_item_id = m.id
              WHERE o.status='completed' AND DATE(o.created_at) BETWEEN '$startDate' AND '$endDate'
              GROUP BY m.id
              ORDER BY qty DESC LIMIT 5";
    $topMenu = $db->query($qMenu)->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'period' => "$startDate s/d $endDate",
        'summary' => $total,
        'by_method' => $methods,
        'top_products' => $topMenu
    ];
    
    sendResponse(true, "Laporan Siap", $data);
}
?>