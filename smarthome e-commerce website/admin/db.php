<?php
// ============================================================
//  SMARTTECH_DB  |  db.php
//  Central database connection — include this in every page:
//  require_once 'db.php';
// ============================================================

// ── Configuration ─────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_USER',    'root');         // change to your MySQL username
define('DB_PASS',    '');             // change to your MySQL password
define('DB_NAME',    'SMARTTECH_DB');
define('DB_PORT',    3306);
define('DB_CHARSET', 'utf8mb4');

// ── Connect ───────────────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

// ── Error handling ─────────────────────────────────────────────
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode([
        'error'   => true,
        'message' => 'Database connection failed: ' . $conn->connect_error
    ]));
}

$conn->set_charset(DB_CHARSET);

// Optional: set strict SQL mode
$conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE'");


// ============================================================
//  HELPER FUNCTIONS
// ============================================================

/**
 * Safe query with prepared statement
 * Usage: db_query($conn, "SELECT * FROM USERS WHERE UserID = ?", "i", [$id])
 *
 * @param mysqli  $conn
 * @param string  $sql      SQL with ? placeholders
 * @param string  $types    Bind types: i=int, d=double, s=string, b=blob
 * @param array   $params   Values to bind
 * @return mysqli_result|bool
 */
function db_query(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("DB prepare failed: " . $conn->error . " | SQL: $sql");
        return false;
    }
    if ($types && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Fetch all rows as associative array
 */
function db_fetch_all(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $result = db_query($conn, $sql, $types, $params);
    if (!$result || !($result instanceof mysqli_result)) return [];
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Fetch single row as associative array
 */
function db_fetch_one(mysqli $conn, string $sql, string $types = '', array $params = []): ?array {
    $result = db_query($conn, $sql, $types, $params);
    if (!$result || !($result instanceof mysqli_result)) return null;
    $row = $result->fetch_assoc();
    return $row ?: null;
}

/**
 * Fetch a single scalar value
 */
function db_fetch_value(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $row = db_fetch_one($conn, $sql, $types, $params);
    return $row ? array_values($row)[0] : null;
}

/**
 * Execute INSERT / UPDATE / DELETE with prepared statement
 * Returns affected rows on success, false on failure
 */
function db_execute(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("DB prepare failed: " . $conn->error . " | SQL: $sql");
        return false;
    }
    if ($types && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $ok ? $affected : false;
}

/**
 * Get last inserted ID
 */
function db_last_id(mysqli $conn): int {
    return (int) $conn->insert_id;
}


// ============================================================
//  DASHBOARD QUERIES
// ============================================================

function getStats(mysqli $conn): array {
    return [
        'total_users'    => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM USERS WHERE Role='customer'"),
        'total_products' => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM PRODUCTS"),
        'total_orders'   => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM ORDERS"),
        'total_revenue'  => (float) db_fetch_value($conn, "SELECT COALESCE(SUM(TotalAmount),0) FROM ORDERS WHERE OrderStatus='delivered'"),
        'pending_orders' => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM ORDERS WHERE OrderStatus='pending'"),
        'low_stock'      => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM PRODUCTS WHERE StockQuantity < 5"),
        'total_reviews'  => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM REVIEWS"),
        'total_payments' => (int) db_fetch_value($conn, "SELECT COUNT(*) FROM PAYMENTS WHERE PaymentStatus='completed'"),
    ];
}

function getRecentOrders(mysqli $conn, int $limit = 7): array {
    return db_fetch_all($conn, "
        SELECT o.OrderID, u.FullName, o.TotalAmount, o.OrderStatus, o.OrderDate
        FROM ORDERS o
        JOIN USERS u ON o.UserID = u.UserID
        ORDER BY o.OrderDate DESC
        LIMIT ?
    ", 'i', [$limit]);
}

function getMonthlyRevenue(mysqli $conn): array {
    return db_fetch_all($conn, "
        SELECT
            DATE_FORMAT(OrderDate, '%b') AS month_label,
            DATE_FORMAT(OrderDate, '%Y-%m') AS month_key,
            COALESCE(SUM(TotalAmount), 0) AS total
        FROM ORDERS
        WHERE OrderStatus = 'delivered'
          AND OrderDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month_key, month_label
        ORDER BY month_key ASC
    ");
}

function getOrderStatusBreakdown(mysqli $conn): array {
    $rows = db_fetch_all($conn, "SELECT OrderStatus, COUNT(*) AS cnt FROM ORDERS GROUP BY OrderStatus");
    $out = [];
    foreach ($rows as $r) $out[$r['OrderStatus']] = (int)$r['cnt'];
    return $out;
}


// ============================================================
//  ORDERS QUERIES
// ============================================================

function getOrders(mysqli $conn, string $status = '', string $search = ''): array {
    $sql    = "SELECT o.*, u.FullName FROM ORDERS o JOIN USERS u ON o.UserID=u.UserID WHERE 1";
    $types  = '';
    $params = [];

    if ($status) {
        $sql    .= " AND o.OrderStatus = ?";
        $types  .= 's';
        $params[] = $status;
    }
    if ($search) {
        $sql    .= " AND (u.FullName LIKE ? OR CAST(o.OrderID AS CHAR) LIKE ?)";
        $types  .= 'ss';
        $like    = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY o.OrderDate DESC";
    return db_fetch_all($conn, $sql, $types, $params);
}

function updateOrderStatus(mysqli $conn, int $orderId, string $status): bool {
    $allowed = ['pending','processing','shipped','delivered','cancelled'];
    if (!in_array($status, $allowed)) return false;
    return db_execute($conn, "UPDATE ORDERS SET OrderStatus=? WHERE OrderID=?", 'si', [$status, $orderId]) !== false;
}

function getOrderItems(mysqli $conn, int $orderId): array {
    return db_fetch_all($conn, "
        SELECT oi.*, p.ProductName, p.ProductImage
        FROM ORDER_ITEMS oi
        JOIN PRODUCTS p ON oi.ProductID = p.ProductID
        WHERE oi.OrderID = ?
    ", 'i', [$orderId]);
}


// ============================================================
//  PRODUCTS QUERIES
// ============================================================

function getProducts(mysqli $conn, string $search = ''): array {
    $sql    = "SELECT p.*, c.CategoryName FROM PRODUCTS p JOIN CATEGORIES c ON p.CategoryID=c.CategoryID WHERE 1";
    $types  = '';
    $params = [];
    if ($search) {
        $sql    .= " AND (p.ProductName LIKE ? OR p.Brand LIKE ?)";
        $types  .= 'ss';
        $like    = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY p.CreatedAt DESC";
    return db_fetch_all($conn, $sql, $types, $params);
}

function addProduct(mysqli $conn, array $data): int {
    db_execute($conn,
        "INSERT INTO PRODUCTS (CategoryID, ProductName, Description, Brand, Price, StockQuantity, ProductImage, Status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
        'isssdiss',
        [
            (int)$data['category_id'],
            $data['name'],
            $data['description'] ?? '',
            $data['brand']       ?? '',
            (float)$data['price'],
            (int)($data['stock'] ?? 0),
            $data['image']       ?? '',
            in_array($data['status'] ?? '', ['active','inactive']) ? $data['status'] : 'active',
        ]
    );
    return db_last_id($conn);
}

function deleteProduct(mysqli $conn, int $id): bool {
    return db_execute($conn, "DELETE FROM PRODUCTS WHERE ProductID=?", 'i', [$id]) !== false;
}

function updateProductStock(mysqli $conn, int $id, int $qty): bool {
    return db_execute($conn, "UPDATE PRODUCTS SET StockQuantity=? WHERE ProductID=?", 'ii', [$qty, $id]) !== false;
}


// ============================================================
//  CATEGORIES QUERIES
// ============================================================

function getCategories(mysqli $conn): array {
    return db_fetch_all($conn, "
        SELECT c.*, COUNT(p.ProductID) AS ProductCount
        FROM CATEGORIES c
        LEFT JOIN PRODUCTS p ON c.CategoryID = p.CategoryID
        GROUP BY c.CategoryID
        ORDER BY c.CategoryName
    ");
}

function addCategory(mysqli $conn, string $name, string $image = ''): int {
    db_execute($conn,
        "INSERT INTO CATEGORIES (CategoryName, CategoryImage) VALUES (?, ?)",
        'ss', [$name, $image]
    );
    return db_last_id($conn);
}

function deleteCategory(mysqli $conn, int $id): bool {
    return db_execute($conn, "DELETE FROM CATEGORIES WHERE CategoryID=?", 'i', [$id]) !== false;
}


// ============================================================
//  USERS QUERIES
// ============================================================

function getUsers(mysqli $conn, string $search = ''): array {
    $sql    = "SELECT * FROM USERS WHERE 1";
    $types  = '';
    $params = [];
    if ($search) {
        $sql    .= " AND (FullName LIKE ? OR Email LIKE ?)";
        $types  .= 'ss';
        $like    = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY CreatedAt DESC";
    return db_fetch_all($conn, $sql, $types, $params);
}

function getUserById(mysqli $conn, int $id): ?array {
    return db_fetch_one($conn, "SELECT * FROM USERS WHERE UserID=?", 'i', [$id]);
}

function deleteUser(mysqli $conn, int $id): bool {
    return db_execute($conn, "DELETE FROM USERS WHERE UserID=? AND Role='customer'", 'i', [$id]) !== false;
}

function getUserOrderCount(mysqli $conn, int $userId): int {
    return (int) db_fetch_value($conn, "SELECT COUNT(*) FROM ORDERS WHERE UserID=?", 'i', [$userId]);
}


// ============================================================
//  REVIEWS QUERIES
// ============================================================

function getReviews(mysqli $conn, string $search = ''): array {
    $sql    = "SELECT r.*, p.ProductName, u.FullName FROM REVIEWS r
               JOIN PRODUCTS p ON r.ProductID=p.ProductID
               JOIN USERS u ON r.UserID=u.UserID WHERE 1";
    $types  = '';
    $params = [];
    if ($search) {
        $sql    .= " AND (p.ProductName LIKE ? OR u.FullName LIKE ?)";
        $types  .= 'ss';
        $like    = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY r.ReviewDate DESC";
    return db_fetch_all($conn, $sql, $types, $params);
}

function deleteReview(mysqli $conn, int $id): bool {
    return db_execute($conn, "DELETE FROM REVIEWS WHERE ReviewID=?", 'i', [$id]) !== false;
}


// ============================================================
//  PAYMENTS QUERIES
// ============================================================

function getPayments(mysqli $conn, string $search = ''): array {
    $sql    = "SELECT pay.*, o.TotalAmount, o.OrderStatus, u.FullName
               FROM PAYMENTS pay
               JOIN ORDERS o ON pay.OrderID=o.OrderID
               JOIN USERS u ON o.UserID=u.UserID WHERE 1";
    $types  = '';
    $params = [];
    if ($search) {
        $sql    .= " AND (u.FullName LIKE ? OR CAST(pay.OrderID AS CHAR) LIKE ?)";
        $types  .= 'ss';
        $like    = "%$search%";
        $params[] = $like;
        $params[] = $like;
    }
    $sql .= " ORDER BY pay.PaymentDate DESC";
    return db_fetch_all($conn, $sql, $types, $params);
}

function getPaymentByOrder(mysqli $conn, int $orderId): ?array {
    return db_fetch_one($conn, "SELECT * FROM PAYMENTS WHERE OrderID=?", 'i', [$orderId]);
}


// ============================================================
//  AUTH HELPERS
// ============================================================

/**
 * Verify login credentials
 * Returns user array on success, null on failure
 */
function loginUser(mysqli $conn, string $email, string $password): ?array {
    $user = db_fetch_one($conn,
        "SELECT * FROM USERS WHERE Email=? AND Role='admin' LIMIT 1",
        's', [$email]
    );
    if ($user && password_verify($password, $user['Password'])) {
        return $user;
    }
    return null;
}

/**
 * Check if admin session is active — redirect to login if not
 */
function requireAdmin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Flash message helpers (uses PHP session)
 */
function setToast(string $msg, string $type = 'success'): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['toast'] = ['msg' => $msg, 'type' => $type];
}

function getToast(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['toast'])) {
        $t = $_SESSION['toast'];
        unset($_SESSION['toast']);
        return $t;
    }
    return null;
}