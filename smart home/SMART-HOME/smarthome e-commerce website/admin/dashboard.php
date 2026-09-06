<?php
// ============================================================
//  SMARTTECH ADMIN PANEL  |  dashboard.php
//  With product photo upload feature
// ============================================================

session_start();

// ── DB Config ─────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'SMARTTECH_DB');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("<p style='color:red;padding:20px'>DB Connection failed: " . $conn->connect_error . "</p>");
}
$conn->set_charset('utf8mb4');

// ── Upload config ─────────────────────────────────────────────
define('UPLOAD_DIR',     'uploads/products/');
define('UPLOAD_MAX_MB',  5);
define('ALLOWED_TYPES',  ['image/jpeg','image/png','image/webp','image/gif']);
define('ALLOWED_EXT',    ['jpg','jpeg','png','webp','gif']);

// Create upload dir if needed
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

// ── Auth Guard ────────────────────────────────────────────────
// if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }

// ── Active Page ───────────────────────────────────────────────
$page = isset($_GET['page']) ? preg_replace('/[^a-z_]/', '', $_GET['page']) : 'dashboard';

// ── Toast ─────────────────────────────────────────────────────
$toast = ''; $toastType = 'success';
if (isset($_SESSION['toast'])) {
    $toast     = $_SESSION['toast'];
    $toastType = $_SESSION['toast_type'] ?? 'success';
    unset($_SESSION['toast'], $_SESSION['toast_type']);
}

// ── Image upload helper ───────────────────────────────────────
function uploadProductImage($fileKey = 'product_image') {
    if (empty($_FILES[$fileKey]['name'])) return '';

    $file     = $_FILES[$fileKey];
    $origName = $file['name'];
    $tmpPath  = $file['tmp_name'];
    $size     = $file['size'];
    $mime     = mime_content_type($tmpPath);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    // Validate
    if ($file['error'] !== UPLOAD_ERR_OK)
        return ['error' => 'Upload error: ' . $file['error']];
    if ($size > UPLOAD_MAX_MB * 1024 * 1024)
        return ['error' => 'File too large. Max ' . UPLOAD_MAX_MB . 'MB allowed.'];
    if (!in_array($mime, ALLOWED_TYPES))
        return ['error' => 'Invalid file type. Allowed: JPG, PNG, WEBP, GIF.'];
    if (!in_array($ext, ALLOWED_EXT))
        return ['error' => 'Invalid file extension.'];

    // Generate unique filename
    $newName = 'product_' . uniqid() . '_' . time() . '.' . $ext;
    $destPath = UPLOAD_DIR . $newName;

    if (!move_uploaded_file($tmpPath, $destPath))
        return ['error' => 'Failed to save file. Check folder permissions.'];

    return $destPath;
}

// ── Delete old image ──────────────────────────────────────────
function deleteProductImage($path) {
    if ($path && file_exists($path) && strpos($path, UPLOAD_DIR) === 0) {
        unlink($path);
    }
}

// ============================================================
//  POST ACTION HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Add Product (with image upload) ──────────────────────
    if ($action === 'add_product') {
        $name   = $conn->real_escape_string(trim($_POST['name']        ?? ''));
        $catid  = (int)($_POST['category_id'] ?? 0);
        $brand  = $conn->real_escape_string(trim($_POST['brand']       ?? ''));
        $desc   = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $price  = (float)($_POST['price']  ?? 0);
        $stock  = (int)($_POST['stock']    ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $imgUrl = $conn->real_escape_string(trim($_POST['image_url']   ?? ''));

        if (!$name || !$catid || $price <= 0) {
            $_SESSION['toast']      = 'Please fill in Name, Category and Price.';
            $_SESSION['toast_type'] = 'error';
            header('Location: dashboard.php?page=products'); exit;
        }

        // Handle image: uploaded file takes priority over URL
        $imagePath = '';
        if (!empty($_FILES['product_image']['name'])) {
            $upload = uploadProductImage('product_image');
            if (is_array($upload) && isset($upload['error'])) {
                $_SESSION['toast']      = $upload['error'];
                $_SESSION['toast_type'] = 'error';
                header('Location: dashboard.php?page=products'); exit;
            }
            $imagePath = $conn->real_escape_string($upload);
        } elseif ($imgUrl) {
            $imagePath = $imgUrl; // use external URL
        }

        $conn->query("INSERT INTO PRODUCTS
            (CategoryID,ProductName,Description,Brand,Price,StockQuantity,ProductImage,Status)
            VALUES ($catid,'$name','$desc','$brand',$price,$stock,'$imagePath','$status')");

        $_SESSION['toast']      = 'Product added successfully!';
        $_SESSION['toast_type'] = 'success';
        header('Location: dashboard.php?page=products'); exit;
    }

    // ── Update Product Image ──────────────────────────────────
    if ($action === 'update_product_image') {
        $pid = (int)($_POST['product_id'] ?? 0);
        if (!$pid) { header('Location: dashboard.php?page=products'); exit; }

        // Get old image
        $old = $conn->query("SELECT ProductImage FROM PRODUCTS WHERE ProductID=$pid")->fetch_assoc();
        $oldImg = $old['ProductImage'] ?? '';

        $newImg = '';
        if (!empty($_FILES['product_image']['name'])) {
            $upload = uploadProductImage('product_image');
            if (is_array($upload) && isset($upload['error'])) {
                $_SESSION['toast']      = $upload['error'];
                $_SESSION['toast_type'] = 'error';
                header('Location: dashboard.php?page=products'); exit;
            }
            $newImg = $conn->real_escape_string($upload);
            deleteProductImage($oldImg);
        } elseif (!empty($_POST['image_url'])) {
            $newImg = $conn->real_escape_string(trim($_POST['image_url']));
        }

        if ($newImg) {
            $conn->query("UPDATE PRODUCTS SET ProductImage='$newImg' WHERE ProductID=$pid");
            $_SESSION['toast'] = 'Product image updated!';
        } else {
            $_SESSION['toast']      = 'No image provided.';
            $_SESSION['toast_type'] = 'error';
        }
        header('Location: dashboard.php?page=products'); exit;
    }

    // ── Delete Product ────────────────────────────────────────
    if ($action === 'delete_product' && isset($_POST['product_id'])) {
        $id  = (int)$_POST['product_id'];
        $row = $conn->query("SELECT ProductImage FROM PRODUCTS WHERE ProductID=$id")->fetch_assoc();
        deleteProductImage($row['ProductImage'] ?? '');
        $conn->query("DELETE FROM PRODUCTS WHERE ProductID=$id");
        $_SESSION['toast'] = 'Product deleted.';
        header('Location: dashboard.php?page=products'); exit;
    }

    // ── Update Order Status ───────────────────────────────────
    if ($action === 'update_order_status' && isset($_POST['order_id'])) {
        $id      = (int)$_POST['order_id'];
        $status  = $conn->real_escape_string($_POST['status'] ?? 'pending');
        $allowed = ['pending','processing','shipped','delivered','cancelled'];
        if (in_array($status, $allowed)) {
            $conn->query("UPDATE ORDERS SET OrderStatus='$status' WHERE OrderID=$id");
            $_SESSION['toast'] = 'Order status updated.';
        }
        header('Location: dashboard.php?page=orders'); exit;
    }

    // ── Delete Review ─────────────────────────────────────────
    if ($action === 'delete_review' && isset($_POST['review_id'])) {
        $id = (int)$_POST['review_id'];
        $conn->query("DELETE FROM REVIEWS WHERE ReviewID=$id");
        $_SESSION['toast'] = 'Review removed.';
        header('Location: dashboard.php?page=reviews'); exit;
    }

    // ── Add Category ──────────────────────────────────────────
    if ($action === 'add_category') {
        $name = $conn->real_escape_string(trim($_POST['cat_name'] ?? ''));
        if ($name) {
            $conn->query("INSERT INTO CATEGORIES (CategoryName) VALUES ('$name')");
            $_SESSION['toast'] = 'Category added!';
        }
        header('Location: dashboard.php?page=categories'); exit;
    }

    // ── Delete User ───────────────────────────────────────────
    if ($action === 'delete_user' && isset($_POST['user_id'])) {
        $id = (int)$_POST['user_id'];
        $conn->query("DELETE FROM USERS WHERE UserID=$id AND Role='customer'");
        $_SESSION['toast'] = 'User removed.';
        header('Location: dashboard.php?page=users'); exit;
    }
}

// ============================================================
//  DATA QUERIES
// ============================================================
function getDashboardStats($conn) {
    $stats = [];
    $r = $conn->query("SELECT COUNT(*) c FROM USERS WHERE Role='customer'");
    $stats['users'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM PRODUCTS");
    $stats['products'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM ORDERS");
    $stats['orders'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COALESCE(SUM(TotalAmount),0) total FROM ORDERS WHERE OrderStatus='delivered'");
    $stats['revenue'] = '$' . number_format($r->fetch_assoc()['total'], 2);
    $r = $conn->query("SELECT COUNT(*) c FROM PRODUCTS WHERE StockQuantity < 5");
    $stats['low_stock'] = $r->fetch_assoc()['c'];
    $r = $conn->query("SELECT COUNT(*) c FROM ORDERS WHERE OrderStatus='pending'");
    $stats['pending_orders'] = $r->fetch_assoc()['c'];
    return $stats;
}
function getRecentOrders($conn, $limit = 7) {
    $res = $conn->query("SELECT o.OrderID,u.FullName,o.TotalAmount,o.OrderStatus,o.OrderDate
        FROM ORDERS o JOIN USERS u ON o.UserID=u.UserID ORDER BY o.OrderDate DESC LIMIT $limit");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getMonthlyRevenue($conn) {
    $res = $conn->query("SELECT DATE_FORMAT(OrderDate,'%b') mo,DATE_FORMAT(OrderDate,'%Y-%m') ym,
        COALESCE(SUM(TotalAmount),0) total FROM ORDERS WHERE OrderStatus='delivered'
        AND OrderDate >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY ym,mo ORDER BY ym ASC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getAllOrders($conn, $status = '', $search = '') {
    $where = '1';
    if ($status) $where .= " AND o.OrderStatus='" . $conn->real_escape_string($status) . "'";
    if ($search) { $s = $conn->real_escape_string($search);
        $where .= " AND (u.FullName LIKE '%$s%' OR o.OrderID LIKE '%$s%')"; }
    $res = $conn->query("SELECT o.*,u.FullName FROM ORDERS o JOIN USERS u ON o.UserID=u.UserID
        WHERE $where ORDER BY o.OrderDate DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getAllProducts($conn, $search = '') {
    $where = '1';
    if ($search) { $s = $conn->real_escape_string($search);
        $where .= " AND (p.ProductName LIKE '%$s%' OR p.Brand LIKE '%$s%')"; }
    $res = $conn->query("SELECT p.*,c.CategoryName FROM PRODUCTS p
        JOIN CATEGORIES c ON p.CategoryID=c.CategoryID WHERE $where ORDER BY p.CreatedAt DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getCategories($conn) {
    $res = $conn->query("SELECT c.*,COUNT(p.ProductID) AS ProductCount FROM CATEGORIES c
        LEFT JOIN PRODUCTS p ON c.CategoryID=p.CategoryID GROUP BY c.CategoryID ORDER BY c.CategoryName");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getAllUsers($conn, $search = '') {
    $where = '1';
    if ($search) { $s = $conn->real_escape_string($search);
        $where .= " AND (FullName LIKE '%$s%' OR Email LIKE '%$s%')"; }
    $res = $conn->query("SELECT * FROM USERS WHERE $where ORDER BY CreatedAt DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getAllReviews($conn, $search = '') {
    $where = '1';
    if ($search) { $s = $conn->real_escape_string($search);
        $where .= " AND (p.ProductName LIKE '%$s%' OR u.FullName LIKE '%$s%')"; }
    $res = $conn->query("SELECT r.*,p.ProductName,u.FullName FROM REVIEWS r
        JOIN PRODUCTS p ON r.ProductID=p.ProductID JOIN USERS u ON r.UserID=u.UserID
        WHERE $where ORDER BY r.ReviewDate DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}
function getAllPayments($conn, $search = '') {
    $where = '1';
    if ($search) { $s = $conn->real_escape_string($search);
        $where .= " AND (u.FullName LIKE '%$s%' OR o.OrderID LIKE '%$s%')"; }
    $res = $conn->query("SELECT pay.*,o.TotalAmount,o.OrderStatus,u.FullName FROM PAYMENTS pay
        JOIN ORDERS o ON pay.OrderID=o.OrderID JOIN USERS u ON o.UserID=u.UserID
        WHERE $where ORDER BY pay.PaymentDate DESC");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

// ── Helpers ───────────────────────────────────────────────────
function badge($status) {
    $colors = [
        'delivered'=>['#22c55e','#052e16'],'shipped'=>['#6c63ff','#1e1b4b'],
        'processing'=>['#f59e0b','#2d1f00'],'pending'=>['#8b8fa8','#1a1d27'],
        'cancelled'=>['#ef4444','#2d0b0b'],'completed'=>['#22c55e','#052e16'],
        'failed'=>['#ef4444','#2d0b0b'],'refunded'=>['#f59e0b','#2d1f00'],
        'active'=>['#22c55e','#052e16'],'inactive'=>['#ef4444','#2d0b0b'],
        'admin'=>['#6c63ff','#1e1b4b'],'customer'=>['#8b8fa8','#1a1d27'],
    ];
    [$fg,$bg] = $colors[$status] ?? ['#8b8fa8','#1a1d27'];
    return "<span style='background:{$bg};color:{$fg};border:1px solid {$fg}44;border-radius:20px;
            padding:2px 10px;font-size:11px;font-weight:600;text-transform:capitalize'>$status</span>";
}
function stars($n) {
    return "<span style='color:#f59e0b'>" . str_repeat('★',$n) . str_repeat('☆',5-$n) . "</span>";
}
function productThumb($img, $size=48) {
    $isUrl = $img && (str_starts_with($img,'http')||str_starts_with($img,'uploads/'));
    $src   = $isUrl ? htmlspecialchars($img)
                    : 'https://placehold.co/'.$size.'x'.$size.'/1a1d27/444?text=No+Img';
    return "<img src='$src' alt='product'
            style='width:{$size}px;height:{$size}px;object-fit:cover;border-radius:6px;
                   border:1px solid #2a2e42;background:#1a1d27'
            onerror=\"this.src='https://placehold.co/{$size}x{$size}/1a1d27/444?text=No+Img'\">";
}

// ── Gather data ───────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$stats        = ($page==='dashboard') ? getDashboardStats($conn) : [];
$recentOrders = ($page==='dashboard') ? getRecentOrders($conn)   : [];
$monthlyRev   = ($page==='dashboard') ? getMonthlyRevenue($conn) : [];
$orders       = ($page==='orders')    ? getAllOrders($conn,$statusFilter,$search) : [];
$products     = ($page==='products')  ? getAllProducts($conn,$search) : [];
$categories   = in_array($page,['categories','products']) ? getCategories($conn) : [];
$users        = ($page==='users')     ? getAllUsers($conn,$search)    : [];
$reviews      = ($page==='reviews')   ? getAllReviews($conn,$search)  : [];
$payments     = ($page==='payments')  ? getAllPayments($conn,$search) : [];

$maxRev = 1;
foreach ($monthlyRev as $m) if ($m['total'] > $maxRev) $maxRev = $m['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartTech Admin</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#0f1117;--surface:#1a1d27;--border:#2a2e42;--accent:#6c63ff;
      --accent-light:#8b85ff;--accent-dim:#6c63ff22;--success:#22c55e;
      --warning:#f59e0b;--danger:#ef4444;--text:#e8e9f0;--muted:#8b8fa8;--white:#ffffff}
body{background:var(--bg);color:var(--text);font-family:'Inter',system-ui,sans-serif;
     font-size:14px;display:flex;min-height:100vh}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font-family:inherit}
::-webkit-scrollbar{width:4px}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px}
input::placeholder,textarea::placeholder{color:var(--muted)}

/* SIDEBAR */
.sidebar{width:220px;min-height:100vh;background:var(--surface);
         border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.sidebar-logo{padding:18px 16px;border-bottom:1px solid var(--border);
              display:flex;align-items:center;gap:10px}
.logo-icon{width:32px;height:32px;border-radius:8px;background:var(--accent);
           display:flex;align-items:center;justify-content:center;
           font-weight:700;font-size:16px;color:var(--white);flex-shrink:0}
.logo-name{font-weight:700;font-size:15px;color:var(--white)}
nav{flex:1;padding:12px 8px}
.nav-link{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:7px;
          color:var(--muted);font-size:13px;margin-bottom:2px;transition:all .15s;
          border:none;background:transparent;width:100%;cursor:pointer}
.nav-link:hover{background:var(--accent-dim);color:var(--accent-light)}
.nav-link.active{background:var(--accent-dim);color:var(--accent-light);font-weight:600}
.nav-icon{font-size:16px}
.sidebar-footer{padding:16px;border-top:1px solid var(--border)}
.admin-avatar{width:32px;height:32px;border-radius:50%;background:var(--accent);
              display:flex;align-items:center;justify-content:center;
              font-weight:700;color:var(--white);flex-shrink:0}

/* MAIN */
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{height:56px;background:var(--surface);border-bottom:1px solid var(--border);
        padding:0 24px;display:flex;align-items:center;gap:14px;flex-shrink:0}
.topbar-title{font-weight:700;font-size:16px;color:var(--white);text-transform:capitalize}
.spacer{flex:1}
.search-wrap{position:relative}
.search-wrap span{position:absolute;left:10px;top:50%;transform:translateY(-50%);
                  color:var(--muted);pointer-events:none}
.search-input{background:var(--bg);border:1px solid var(--border);border-radius:8px;
              padding:6px 12px 6px 32px;color:var(--text);font-size:13px;width:210px;outline:none}
.search-input:focus{border-color:var(--accent)}
.content{flex:1;padding:24px;overflow-y:auto}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px}
.card+.card,.two-col+.card{margin-top:16px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
.section-title{font-weight:700;font-size:18px;color:var(--white)}
.section-sub{color:var(--muted);font-size:12px;margin-top:2px}
.card-title{font-weight:700;color:var(--white);margin-bottom:14px}

/* TABLE */
table{border-collapse:collapse;width:100%}
th{text-align:left;padding:8px 12px;color:var(--muted);font-size:11px;font-weight:600;
   border-bottom:1px solid var(--border);text-transform:uppercase;letter-spacing:.5px;white-space:nowrap}
td{padding:10px 12px;font-size:13px;color:var(--text);border-bottom:1px solid #2a2e4220;white-space:nowrap}
tr:last-child td{border-bottom:none}
.table-wrap{overflow-x:auto}
.empty-row td{text-align:center;color:var(--muted);padding:28px 0}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:18px}
.stat-icon{font-size:22px;margin-bottom:8px}
.stat-label{color:var(--muted);font-size:12px;margin-bottom:4px}
.stat-value{font-size:22px;font-weight:700;color:var(--white)}

/* CHARTS */
.chart-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:24px}
.bar-chart{display:flex;align-items:flex-end;gap:5px;height:110px}
.bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px}
.bar{width:100%;border-radius:4px 4px 0 0;min-height:4px}
.bar-label{font-size:9px;color:var(--muted)}
.progress-row{margin-bottom:10px}
.progress-meta{display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px}
.progress-track{height:6px;background:var(--border);border-radius:4px}
.progress-fill{height:100%;border-radius:4px}

/* FORMS */
.form-group{margin-bottom:14px}
.form-label{display:block;color:var(--muted);font-size:11px;font-weight:600;
            text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
.form-input,.form-select,.form-textarea{width:100%;background:var(--bg);border:1px solid var(--border);
  color:var(--text);border-radius:7px;padding:8px 12px;font-size:13px;outline:none}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--accent)}
.form-textarea{resize:vertical}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px}

/* ── IMAGE UPLOAD WIDGET ───────────────────── */
.img-upload-zone{border:2px dashed var(--border);border-radius:10px;padding:20px;
                 text-align:center;cursor:pointer;transition:all .2s;position:relative;
                 background:var(--bg)}
.img-upload-zone:hover,.img-upload-zone.dragover{border-color:var(--accent);background:var(--accent-dim)}
.img-upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.img-upload-zone .upload-icon{font-size:28px;margin-bottom:6px}
.img-upload-zone p{font-size:12px;color:var(--muted);margin-bottom:4px}
.img-upload-zone small{font-size:10px;color:var(--border)}
.img-preview-wrap{position:relative;display:inline-block;margin-top:10px}
.img-preview{width:120px;height:120px;object-fit:cover;border-radius:8px;
             border:2px solid var(--accent);display:block}
.img-preview-remove{position:absolute;top:-6px;right:-6px;background:var(--danger);
                    color:var(--white);border:none;border-radius:50%;width:20px;height:20px;
                    cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;
                    font-weight:700;line-height:1}
.img-tabs{display:flex;gap:0;margin-bottom:12px;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.img-tab{flex:1;padding:7px;font-size:12px;font-weight:600;border:none;cursor:pointer;
         background:transparent;color:var(--muted);transition:all .15s}
.img-tab.active{background:var(--accent);color:var(--white)}
.img-tab-panel{display:none}
.img-tab-panel.active{display:block}
.url-preview{width:100%;height:80px;object-fit:contain;border-radius:8px;
             border:1px solid var(--border);margin-top:8px;background:var(--bg);display:none}

/* PRODUCT IMAGE in TABLE */
.product-img-cell{display:flex;align-items:center;gap:10px}
.product-img-thumb{width:44px;height:44px;object-fit:cover;border-radius:6px;
                   border:1px solid var(--border);flex-shrink:0;background:var(--bg)}
.product-name-cell{font-weight:600;max-width:160px;overflow:hidden;text-overflow:ellipsis}

/* UPDATE IMAGE MODAL */
.modal-overlay{position:fixed;inset:0;background:#0009;z-index:400;
               display:none;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--surface);border:1px solid var(--border);border-radius:14px;
           padding:24px;width:420px;max-width:95vw;animation:fadeIn .2s ease}
.modal-title{font-weight:700;font-size:16px;color:var(--white);margin-bottom:18px;
             display:flex;align-items:center;justify-content:space-between}
.modal-close{background:none;border:none;color:var(--muted);font-size:18px;cursor:pointer}

/* BUTTONS */
.btn{padding:8px 18px;border-radius:8px;border:none;cursor:pointer;
     font-size:13px;font-weight:600;transition:opacity .15s}
.btn:hover{opacity:.85}
.btn-primary{background:var(--accent);color:var(--white)}
.btn-sm{padding:4px 10px;border-radius:6px;border:none;cursor:pointer;font-size:11px;font-weight:600}
.btn-edit{background:var(--accent-dim);color:var(--accent-light);border:1px solid #6c63ff33}
.btn-del{background:#ef444422;color:var(--danger);border:1px solid #ef444433}
.btn-img{background:#ffffff0f;color:var(--text);border:1px solid var(--border)}

/* FILTERS */
.filters{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.filter-btn{padding:5px 14px;border-radius:6px;font-size:12px;cursor:pointer;
            border:1px solid var(--border);background:transparent;color:var(--muted);
            text-decoration:none;transition:all .15s}
.filter-btn:hover,.filter-btn.active{background:var(--accent);border-color:var(--accent);
                                      color:var(--white);font-weight:600}

/* TOAST */
.toast{position:fixed;bottom:24px;right:24px;padding:10px 20px;border-radius:10px;
       font-weight:600;font-size:13px;box-shadow:0 4px 20px #0008;z-index:999;animation:fadeIn .25s ease}
.toast-success{background:var(--success);color:var(--white)}
.toast-error{background:var(--danger);color:var(--white)}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

@media(max-width:768px){
  .sidebar{width:60px}
  .logo-name,.nav-link span,.admin-name{display:none}
  .nav-link{justify-content:center;padding:9px 0}
  .chart-grid,.two-col,.form-grid{grid-template-columns:1fr}
  .search-input{width:140px}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">S</div>
    <span class="logo-name">SmartTech</span>
  </div>
  <nav>
    <?php
    $navItems=[
      ['dashboard','⊞','Dashboard'],['orders','📦','Orders'],
      ['products','🛍️','Products'],['categories','🗂️','Categories'],
      ['users','👥','Users'],['reviews','⭐','Reviews'],['payments','💳','Payments'],
    ];
    foreach ($navItems as [$id,$icon,$label]): ?>
    <a href="dashboard.php?page=<?= $id ?>" class="nav-link <?= $page===$id?'active':'' ?>">
      <span class="nav-icon"><?= $icon ?></span><span><?= $label ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-footer">
    <div style="display:flex;align-items:center;gap:10px">
      <div class="admin-avatar">A</div>
      <div>
        <div style="font-weight:600;font-size:13px;color:var(--white)" class="admin-name">Admin</div>
        <div style="font-size:11px;color:var(--muted)" class="admin-name">Administrator</div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <header class="topbar">
    <span class="topbar-title"><?= htmlspecialchars($page) ?></span>
    <div class="spacer"></div>
    <form method="GET" action="dashboard.php" class="search-wrap" style="display:flex;align-items:center">
      <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
      <span>🔍</span>
      <input class="search-input" type="text" name="search"
             value="<?= htmlspecialchars($search) ?>" placeholder="Search…">
    </form>
  </header>

  <div class="content">

  <?php // ── DASHBOARD ────────────────────────────────────────────── ?>
  <?php if ($page === 'dashboard'): ?>

  <div class="stats-grid">
    <?php $cards=[
      ['💰','Total Revenue',$stats['revenue'],'from delivered orders'],
      ['📦','Total Orders',$stats['orders'],'all time'],
      ['👥','Customers',$stats['users'],'registered'],
      ['🛍️','Products',$stats['products'],'in catalog'],
      ['⏳','Pending Orders',$stats['pending_orders'],'awaiting action'],
      ['⚠️','Low Stock',$stats['low_stock'],'items below 5 units'],
    ];
    foreach ($cards as [$icon,$label,$val,$sub]): ?>
    <div class="stat-card">
      <div class="stat-icon"><?= $icon ?></div>
      <div class="stat-label"><?= $label ?></div>
      <div class="stat-value"><?= htmlspecialchars((string)$val) ?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:4px"><?= $sub ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="chart-grid">
    <div class="card">
      <div class="card-title">Monthly Revenue</div>
      <div class="bar-chart">
        <?php if (empty($monthlyRev)): ?>
        <div style="color:var(--muted);font-size:13px">No revenue data yet.</div>
        <?php else: foreach ($monthlyRev as $i=>$m):
          $h=$maxRev>0?round(($m['total']/$maxRev)*100):0;
          $isLast=($i===count($monthlyRev)-1); ?>
        <div class="bar-wrap">
          <div class="bar" style="height:<?= $h ?>%;background:<?= $isLast?'var(--accent)':'var(--accent-dim)' ?>"></div>
          <div class="bar-label"><?= htmlspecialchars($m['mo']) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-title">Order Status</div>
      <?php
      $statusBreakdown=[];
      $res=$conn->query("SELECT OrderStatus,COUNT(*) c FROM ORDERS GROUP BY OrderStatus");
      $total=max($stats['orders'],1);
      while($row=$res->fetch_assoc()) $statusBreakdown[$row['OrderStatus']]=$row['c'];
      $statusColors=['delivered'=>'var(--success)','shipped'=>'var(--accent)',
                     'processing'=>'var(--warning)','pending'=>'var(--muted)','cancelled'=>'var(--danger)'];
      foreach ($statusColors as $st=>$color):
        $cnt=$statusBreakdown[$st]??0; $pct=round($cnt/$total*100); ?>
      <div class="progress-row">
        <div class="progress-meta">
          <span style="color:var(--muted)"><?= ucfirst($st) ?></span>
          <span style="color:var(--white);font-weight:600"><?= $pct ?>%</span>
        </div>
        <div class="progress-track"><div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <div class="card-title" style="margin:0">Recent Orders</div>
      <a href="dashboard.php?page=orders" style="font-size:12px;color:var(--accent)">View all →</a>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Order ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php if (empty($recentOrders)): ?>
        <tr class="empty-row"><td colspan="5">No orders yet.</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
        <tr>
          <td style="color:var(--accent-light);font-weight:600">#<?= $o['OrderID'] ?></td>
          <td><?= htmlspecialchars($o['FullName']) ?></td>
          <td>$<?= number_format($o['TotalAmount'],2) ?></td>
          <td><?= badge($o['OrderStatus']) ?></td>
          <td style="color:var(--muted)"><?= date('M d, Y',strtotime($o['OrderDate'])) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <?php // ── ORDERS ─────────────────────────────────────────────────── ?>
  <?php elseif ($page === 'orders'): ?>

  <div class="section-header">
    <div><div class="section-title">Orders</div><div class="section-sub"><?= count($orders) ?> order(s)</div></div>
  </div>
  <div class="filters">
    <?php foreach ([''=>'All','pending'=>'Pending','processing'=>'Processing','shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $val=>$lbl): ?>
    <a href="dashboard.php?page=orders&status=<?= $val ?>&search=<?= urlencode($search) ?>"
       class="filter-btn <?= $statusFilter===$val?'active':'' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Order ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th><th>Update Status</th></tr></thead>
    <tbody>
      <?php if (empty($orders)): ?>
      <tr class="empty-row"><td colspan="6">No orders found.</td></tr>
      <?php else: foreach ($orders as $o): ?>
      <tr>
        <td style="color:var(--accent-light);font-weight:600">#<?= $o['OrderID'] ?></td>
        <td><?= htmlspecialchars($o['FullName']) ?></td>
        <td>$<?= number_format($o['TotalAmount'],2) ?></td>
        <td><?= badge($o['OrderStatus']) ?></td>
        <td style="color:var(--muted)"><?= date('M d, Y',strtotime($o['OrderDate'])) ?></td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="action" value="update_order_status">
            <input type="hidden" name="order_id" value="<?= $o['OrderID'] ?>">
            <select name="status" class="form-select" style="width:130px;padding:4px 8px;font-size:12px">
              <?php foreach (['pending','processing','shipped','delivered','cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= $o['OrderStatus']===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-sm btn-edit">Save</button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <?php // ── PRODUCTS (with image upload) ─────────────────────────── ?>
  <?php elseif ($page === 'products'): ?>

  <div class="section-header">
    <div><div class="section-title">Products</div><div class="section-sub"><?= count($products) ?> product(s)</div></div>
  </div>

  <div class="two-col">

    <!-- Product List -->
    <div class="card" style="overflow:hidden">
      <div class="table-wrap"><table>
        <thead><tr>
          <th>Photo</th><th>Name</th><th>Category</th><th>Price</th>
          <th>Stock</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php if (empty($products)): ?>
          <tr class="empty-row"><td colspan="7">No products found.</td></tr>
          <?php else: foreach ($products as $p):
            $sc=$p['StockQuantity']==0?'var(--danger)':($p['StockQuantity']<5?'var(--warning)':'var(--success)'); ?>
          <tr>
            <td><?= productThumb($p['ProductImage']??'', 44) ?></td>
            <td style="font-weight:600;max-width:140px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['ProductName']) ?></td>
            <td style="color:var(--muted)"><?= htmlspecialchars($p['CategoryName']) ?></td>
            <td>$<?= number_format($p['Price'],2) ?></td>
            <td style="color:<?= $sc ?>;font-weight:600"><?= $p['StockQuantity'] ?></td>
            <td><?= badge($p['Status']) ?></td>
            <td style="display:flex;gap:4px;align-items:center">
              <!-- Edit Image button -->
              <button type="button" class="btn-sm btn-img"
                onclick="openImgModal(<?= $p['ProductID'] ?>,'<?= htmlspecialchars(addslashes($p['ProductName'])) ?>','<?= htmlspecialchars(addslashes($p['ProductImage']??'')) ?>')"
                title="Update Image">🖼</button>
              <!-- Delete -->
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_product">
                <input type="hidden" name="product_id" value="<?= $p['ProductID'] ?>">
                <button type="submit" class="btn-sm btn-del"
                  onclick="return confirm('Delete <?= htmlspecialchars(addslashes($p['ProductName'])) ?>?')">Del</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>

    <!-- Add Product Form with Image Upload -->
    <div class="card">
      <div class="card-title">Add New Product</div>
      <form method="POST" enctype="multipart/form-data" action="dashboard.php?page=products">
        <input type="hidden" name="action" value="add_product">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Product Name *</label>
            <input class="form-input" type="text" name="name" placeholder="e.g. UltraBook Pro" required>
          </div>
          <div class="form-group">
            <label class="form-label">Brand</label>
            <input class="form-input" type="text" name="brand" placeholder="e.g. TechCore">
          </div>
          <div class="form-group">
            <label class="form-label">Price ($) *</label>
            <input class="form-input" type="number" name="price" step="0.01" min="0" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label class="form-label">Stock Quantity</label>
            <input class="form-input" type="number" name="stock" min="0" value="0">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Category *</label>
          <select class="form-select" name="category_id" required>
            <option value="">Select category…</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= $c['CategoryID'] ?>"><?= htmlspecialchars($c['CategoryName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        <!-- ── IMAGE UPLOAD WIDGET ── -->
        <div class="form-group">
          <label class="form-label">Product Photo</label>
          <div class="img-tabs" id="addImgTabs">
            <button type="button" class="img-tab active" onclick="switchTab('add','upload')">⬆ Upload File</button>
            <button type="button" class="img-tab"        onclick="switchTab('add','url')">🔗 Image URL</button>
          </div>

          <!-- Upload panel -->
          <div class="img-tab-panel active" id="add-upload-panel">
            <div class="img-upload-zone" id="addDropZone"
                 ondragover="event.preventDefault();this.classList.add('dragover')"
                 ondragleave="this.classList.remove('dragover')"
                 ondrop="handleDrop(event,'addPreview','addDropZone')">
              <input type="file" name="product_image" id="addFileInput" accept="image/*"
                     onchange="previewImage(this,'addPreview','addDropZone')">
              <div class="upload-icon">📷</div>
              <p>Drag & drop or <strong style="color:var(--accent)">click to browse</strong></p>
              <small>JPG, PNG, WEBP, GIF — Max <?= UPLOAD_MAX_MB ?>MB</small>
              <div class="img-preview-wrap" id="addPreviewWrap" style="display:none;margin-top:12px">
                <img id="addPreview" class="img-preview" src="" alt="Preview">
                <button type="button" class="img-preview-remove"
                        onclick="clearImage('addFileInput','addPreview','addPreviewWrap','addDropZone')">✕</button>
              </div>
            </div>
          </div>

          <!-- URL panel -->
          <div class="img-tab-panel" id="add-url-panel">
            <input class="form-input" type="url" name="image_url" id="addUrlInput"
                   placeholder="https://example.com/product.jpg"
                   oninput="previewUrl(this,'addUrlPreview')">
            <img id="addUrlPreview" class="url-preview" src="" alt="URL Preview">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea class="form-textarea" name="description" rows="3" placeholder="Product description…"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Add Product</button>
      </form>
    </div>
  </div>

  <!-- ── UPDATE IMAGE MODAL ── -->
  <div class="modal-overlay" id="imgModal">
    <div class="modal-box">
      <div class="modal-title">
        <span>🖼 Update Product Image</span>
        <button class="modal-close" onclick="closeImgModal()">✕</button>
      </div>
      <div id="modalProductName" style="font-size:13px;color:var(--muted);margin-bottom:16px"></div>

      <!-- Current image -->
      <div id="modalCurrentImg" style="margin-bottom:16px;text-align:center"></div>

      <form method="POST" enctype="multipart/form-data" action="dashboard.php?page=products">
        <input type="hidden" name="action" value="update_product_image">
        <input type="hidden" name="product_id" id="modalProductId">

        <div class="img-tabs">
          <button type="button" class="img-tab active" onclick="switchTab('modal','upload')">⬆ Upload File</button>
          <button type="button" class="img-tab"        onclick="switchTab('modal','url')">🔗 Image URL</button>
        </div>

        <!-- Upload -->
        <div class="img-tab-panel active" id="modal-upload-panel">
          <div class="img-upload-zone" id="modalDropZone"
               ondragover="event.preventDefault();this.classList.add('dragover')"
               ondragleave="this.classList.remove('dragover')"
               ondrop="handleDrop(event,'modalPreview','modalDropZone')">
            <input type="file" name="product_image" id="modalFileInput" accept="image/*"
                   onchange="previewImage(this,'modalPreview','modalDropZone')">
            <div class="upload-icon">📷</div>
            <p>Drag & drop or <strong style="color:var(--accent)">click to browse</strong></p>
            <small>JPG, PNG, WEBP, GIF — Max <?= UPLOAD_MAX_MB ?>MB</small>
            <div class="img-preview-wrap" id="modalPreviewWrap" style="display:none;margin-top:12px">
              <img id="modalPreview" class="img-preview" src="" alt="Preview">
              <button type="button" class="img-preview-remove"
                      onclick="clearImage('modalFileInput','modalPreview','modalPreviewWrap','modalDropZone')">✕</button>
            </div>
          </div>
        </div>

        <!-- URL -->
        <div class="img-tab-panel" id="modal-url-panel">
          <input class="form-input" type="url" name="image_url" id="modalUrlInput"
                 placeholder="https://example.com/product.jpg"
                 oninput="previewUrl(this,'modalUrlPreview')">
          <img id="modalUrlPreview" class="url-preview" src="" alt="URL Preview">
        </div>

        <div style="display:flex;gap:10px;margin-top:16px">
          <button type="button" onclick="closeImgModal()"
                  style="flex:1;padding:9px;background:var(--bg);border:1px solid var(--border);
                         color:var(--muted);border-radius:8px;cursor:pointer;font-size:13px">Cancel</button>
          <button type="submit" class="btn btn-primary" style="flex:1">Save Image</button>
        </div>
      </form>
    </div>
  </div>

  <?php // ── CATEGORIES ──────────────────────────────────────────── ?>
  <?php elseif ($page === 'categories'): ?>

  <div class="section-header"><div class="section-title">Categories</div></div>
  <div class="two-col">
    <div class="card">
      <div class="card-title">All Categories</div>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Name</th><th>Products</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (empty($categories)): ?>
          <tr class="empty-row"><td colspan="4">No categories yet.</td></tr>
          <?php else: foreach ($categories as $c): ?>
          <tr>
            <td style="color:var(--muted)">#<?= $c['CategoryID'] ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($c['CategoryName']) ?></td>
            <td style="color:var(--accent-light);font-weight:600"><?= $c['ProductCount'] ?></td>
            <td>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="cat_id" value="<?= $c['CategoryID'] ?>">
                <button type="submit" class="btn-sm btn-del"
                        onclick="return confirm('Delete this category?')">Del</button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div>
    <div class="card">
      <div class="card-title">Add Category</div>
      <form method="POST">
        <input type="hidden" name="action" value="add_category">
        <div class="form-group">
          <label class="form-label">Category Name</label>
          <input class="form-input" type="text" name="cat_name" placeholder="e.g. Smartphones" required>
        </div>
        <div class="form-group">
          <label class="form-label">Image URL (optional)</label>
          <input class="form-input" type="text" name="cat_image" placeholder="https://…">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px">Add Category</button>
      </form>
    </div>
  </div>

  <?php // ── USERS ───────────────────────────────────────────────── ?>
  <?php elseif ($page === 'users'): ?>

  <div class="section-header">
    <div><div class="section-title">Users</div><div class="section-sub"><?= count($users) ?> user(s)</div></div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Joined</th><th>Action</th></tr></thead>
    <tbody>
      <?php if (empty($users)): ?>
      <tr class="empty-row"><td colspan="7">No users found.</td></tr>
      <?php else: foreach ($users as $u): ?>
      <tr>
        <td style="color:var(--muted)">#<?= $u['UserID'] ?></td>
        <td style="font-weight:600"><?= htmlspecialchars($u['FullName']) ?></td>
        <td style="color:var(--muted)"><?= htmlspecialchars($u['Email']) ?></td>
        <td><?= htmlspecialchars($u['Phone']??'—') ?></td>
        <td><?= badge($u['Role']) ?></td>
        <td style="color:var(--muted)"><?= date('M Y',strtotime($u['CreatedAt'])) ?></td>
        <td>
          <?php if ($u['Role']!=='admin'): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
            <button type="submit" class="btn-sm btn-del" onclick="return confirm('Remove this user?')">Remove</button>
          </form>
          <?php else: ?><span style="color:var(--muted);font-size:11px">Protected</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <?php // ── REVIEWS ─────────────────────────────────────────────── ?>
  <?php elseif ($page === 'reviews'): ?>

  <div class="section-header">
    <div><div class="section-title">Reviews</div><div class="section-sub"><?= count($reviews) ?> review(s)</div></div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Product</th><th>User</th><th>Rating</th><th>Comment</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
      <?php if (empty($reviews)): ?>
      <tr class="empty-row"><td colspan="6">No reviews found.</td></tr>
      <?php else: foreach ($reviews as $r): ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars($r['ProductName']) ?></td>
        <td><?= htmlspecialchars($r['FullName']) ?></td>
        <td><?= stars($r['Rating']) ?></td>
        <td style="color:var(--muted);max-width:260px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($r['Comment']??'') ?></td>
        <td style="color:var(--muted)"><?= date('M d',strtotime($r['ReviewDate'])) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="delete_review">
            <input type="hidden" name="review_id" value="<?= $r['ReviewID'] ?>">
            <button type="submit" class="btn-sm btn-del" onclick="return confirm('Remove this review?')">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <?php // ── PAYMENTS ────────────────────────────────────────────── ?>
  <?php elseif ($page === 'payments'): ?>

  <div class="section-header">
    <div><div class="section-title">Payments</div><div class="section-sub"><?= count($payments) ?> transaction(s)</div></div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Pay ID</th><th>Order</th><th>Customer</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
      <?php if (empty($payments)): ?>
      <tr class="empty-row"><td colspan="7">No payment records.</td></tr>
      <?php else: foreach ($payments as $p): ?>
      <tr>
        <td style="color:var(--accent-light);font-weight:600">#<?= $p['PaymentID'] ?></td>
        <td style="color:var(--muted)">#<?= $p['OrderID'] ?></td>
        <td><?= htmlspecialchars($p['FullName']) ?></td>
        <td>$<?= number_format($p['TotalAmount'],2) ?></td>
        <td><?= htmlspecialchars($p['PaymentMethod']) ?></td>
        <td><?= badge($p['PaymentStatus']) ?></td>
        <td style="color:var(--muted)"><?= date('M d, Y',strtotime($p['PaymentDate'])) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <?php endif; ?>
  </div><!-- /content -->
</div><!-- /main -->

<?php if ($toast): ?>
<div class="toast toast-<?= htmlspecialchars($toastType) ?>"><?= htmlspecialchars($toast) ?></div>
<script>setTimeout(()=>document.querySelector('.toast')?.remove(),2800)</script>
<?php endif; ?>

<script>
// ── Image tab switcher ─────────────────────────────────────────
function switchTab(prefix, tab) {
  document.querySelectorAll(`#${prefix === 'add' ? 'addImgTabs' : '.modal-box'} .img-tab`).forEach(b => b.classList.remove('active'));
  document.querySelectorAll(`[id^="${prefix}-"][id$="-panel"]`).forEach(p => p.classList.remove('active'));
  event.target.classList.add('active');
  document.getElementById(`${prefix}-${tab}-panel`).classList.add('active');
}

// ── File preview ───────────────────────────────────────────────
function previewImage(input, previewId, zoneId) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > <?= UPLOAD_MAX_MB ?> * 1024 * 1024) {
    alert('File too large! Max <?= UPLOAD_MAX_MB ?>MB allowed.');
    input.value = ''; return;
  }
  const reader = new FileReader();
  reader.onload = e => {
    const preview = document.getElementById(previewId);
    const wrap    = document.getElementById(previewId + 'Wrap');
    const zone    = document.getElementById(zoneId);
    preview.src   = e.target.result;
    wrap.style.display  = 'inline-block';
    // Hide upload prompt text inside zone
    zone.querySelectorAll('.upload-icon,p,small').forEach(el => el.style.display = 'none');
  };
  reader.readAsDataURL(file);
}

// ── Drag & drop ────────────────────────────────────────────────
function handleDrop(event, previewId, zoneId) {
  event.preventDefault();
  const zone  = document.getElementById(zoneId);
  zone.classList.remove('dragover');
  const inputId = zoneId === 'addDropZone' ? 'addFileInput' : 'modalFileInput';
  const input   = document.getElementById(inputId);
  const dt      = event.dataTransfer;
  if (dt.files.length) {
    const fileList = new DataTransfer();
    fileList.items.add(dt.files[0]);
    input.files = fileList.files;
    previewImage(input, previewId, zoneId);
  }
}

// ── Clear image ────────────────────────────────────────────────
function clearImage(inputId, previewId, wrapId, zoneId) {
  const input = document.getElementById(inputId);
  input.value = '';
  document.getElementById(wrapId).style.display = 'none';
  const zone = document.getElementById(zoneId);
  zone.querySelectorAll('.upload-icon,p,small').forEach(el => el.style.display = '');
}

// ── URL preview ────────────────────────────────────────────────
function previewUrl(input, previewId) {
  const img = document.getElementById(previewId);
  const url = input.value.trim();
  if (url) {
    img.src   = url;
    img.style.display = 'block';
    img.onerror = () => { img.style.display = 'none'; };
  } else {
    img.style.display = 'none';
  }
}

// ── Update image modal ─────────────────────────────────────────
function openImgModal(id, name, currentImg) {
  document.getElementById('modalProductId').value = id;
  document.getElementById('modalProductName').textContent = 'Product: ' + name;

  const cur = document.getElementById('modalCurrentImg');
  if (currentImg && currentImg !== '') {
    cur.innerHTML = `<div style="margin-bottom:8px;font-size:11px;color:var(--muted);text-align:center">Current image:</div>
      <img src="${currentImg}" style="max-height:80px;max-width:180px;object-fit:contain;
      border-radius:8px;border:1px solid var(--border);display:block;margin:0 auto"
      onerror="this.parentElement.style.display='none'">`;
  } else {
    cur.innerHTML = '<div style="font-size:12px;color:var(--muted);text-align:center">No image currently set.</div>';
  }

  // Reset modal state
  clearImage('modalFileInput','modalPreview','modalPreviewWrap','modalDropZone');
  document.getElementById('modalUrlInput').value = '';
  document.getElementById('modalUrlPreview').style.display = 'none';

  document.getElementById('imgModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeImgModal() {
  document.getElementById('imgModal').classList.remove('open');
  document.body.style.overflow = '';
}

// Close modal on overlay click
document.getElementById('imgModal')?.addEventListener('click', function(e) {
  if (e.target === this) closeImgModal();
});
</script>
</body>
</html>
<?php $conn->close(); ?>