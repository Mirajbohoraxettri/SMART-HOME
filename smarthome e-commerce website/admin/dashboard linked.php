<?php
// ============================================================
//  SMARTTECH ADMIN  |  dashboard.php
//  Requires: db.php in the same directory
// ============================================================

session_start();
require_once 'db.php';

// ── Auth guard (uncomment once login.php is ready) ────────────
// requireAdmin();

// ── Active page & inputs ──────────────────────────────────────
$page         = isset($_GET['page'])   ? preg_replace('/[^a-z_]/', '', $_GET['page']) : 'dashboard';
$search       = trim($_GET['search']   ?? '');
$statusFilter = trim($_GET['status']   ?? '');

// ── Flash toast ───────────────────────────────────────────────
$toast = getToast();

// ============================================================
//  POST ACTION HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {

        case 'add_product':
            if (!empty($_POST['name']) && !empty($_POST['category_id']) && !empty($_POST['price'])) {
                addProduct($conn, [
                    'category_id' => $_POST['category_id'],
                    'name'        => $_POST['name'],
                    'brand'       => $_POST['brand']        ?? '',
                    'description' => $_POST['description']  ?? '',
                    'price'       => $_POST['price'],
                    'stock'       => $_POST['stock']        ?? 0,
                    'status'      => $_POST['status']       ?? 'active',
                ]);
                setToast('Product added successfully!');
            } else {
                setToast('Please fill in all required fields.', 'error');
            }
            header('Location: dashboard.php?page=products'); exit;

        case 'delete_product':
            if (!empty($_POST['product_id'])) {
                deleteProduct($conn, (int)$_POST['product_id']);
                setToast('Product deleted.');
            }
            header('Location: dashboard.php?page=products'); exit;

        case 'update_order_status':
            if (!empty($_POST['order_id']) && !empty($_POST['status'])) {
                $ok = updateOrderStatus($conn, (int)$_POST['order_id'], $_POST['status']);
                setToast($ok ? 'Order status updated.' : 'Invalid status.', $ok ? 'success' : 'error');
            }
            header('Location: dashboard.php?page=orders'); exit;

        case 'add_category':
            if (!empty($_POST['cat_name'])) {
                addCategory($conn, trim($_POST['cat_name']), trim($_POST['cat_image'] ?? ''));
                setToast('Category added!');
            } else {
                setToast('Category name is required.', 'error');
            }
            header('Location: dashboard.php?page=categories'); exit;

        case 'delete_category':
            if (!empty($_POST['cat_id'])) {
                deleteCategory($conn, (int)$_POST['cat_id']);
                setToast('Category deleted.');
            }
            header('Location: dashboard.php?page=categories'); exit;

        case 'delete_user':
            if (!empty($_POST['user_id'])) {
                $ok = deleteUser($conn, (int)$_POST['user_id']);
                setToast($ok ? 'User removed.' : 'Cannot delete admin users.', $ok ? 'success' : 'error');
            }
            header('Location: dashboard.php?page=users'); exit;

        case 'delete_review':
            if (!empty($_POST['review_id'])) {
                deleteReview($conn, (int)$_POST['review_id']);
                setToast('Review removed.');
            }
            header('Location: dashboard.php?page=reviews'); exit;
    }
}

// ============================================================
//  LOAD PAGE DATA
// ============================================================
$stats          = [];
$recentOrders   = [];
$monthlyRev     = [];
$statusBreakdown= [];
$orders         = [];
$products       = [];
$categories     = [];
$users          = [];
$reviews        = [];
$payments       = [];

switch ($page) {
    case 'dashboard':
        $stats           = getStats($conn);
        $recentOrders    = getRecentOrders($conn, 7);
        $monthlyRev      = getMonthlyRevenue($conn);
        $statusBreakdown = getOrderStatusBreakdown($conn);
        break;
    case 'orders':
        $orders     = getOrders($conn, $statusFilter, $search);
        break;
    case 'products':
        $products   = getProducts($conn, $search);
        $categories = getCategories($conn);
        break;
    case 'categories':
        $categories = getCategories($conn);
        break;
    case 'users':
        $users      = getUsers($conn, $search);
        break;
    case 'reviews':
        $reviews    = getReviews($conn, $search);
        break;
    case 'payments':
        $payments   = getPayments($conn, $search);
        break;
}

// ── Revenue chart max ─────────────────────────────────────────
$maxRev = 1;
foreach ($monthlyRev as $m) if ((float)$m['total'] > $maxRev) $maxRev = (float)$m['total'];

// ── UI helpers ────────────────────────────────────────────────
function badge(string $status): string {
    $map = [
        'delivered'  => ['#22c55e','#052e16'],
        'shipped'    => ['#6c63ff','#1e1b4b'],
        'processing' => ['#f59e0b','#2d1f00'],
        'pending'    => ['#8b8fa8','#1a1d27'],
        'cancelled'  => ['#ef4444','#2d0b0b'],
        'completed'  => ['#22c55e','#052e16'],
        'failed'     => ['#ef4444','#2d0b0b'],
        'refunded'   => ['#f59e0b','#2d1f00'],
        'active'     => ['#22c55e','#052e16'],
        'inactive'   => ['#ef4444','#2d0b0b'],
        'admin'      => ['#6c63ff','#1e1b4b'],
        'customer'   => ['#8b8fa8','#1a1d27'],
    ];
    [$fg, $bg] = $map[$status] ?? ['#8b8fa8','#1a1d27'];
    $label = htmlspecialchars(ucfirst($status));
    return "<span style='background:{$bg};color:{$fg};border:1px solid {$fg}44;
            border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600'>{$label}</span>";
}

function stars(int $n): string {
    return "<span style='color:#f59e0b'>" . str_repeat('★', $n) . str_repeat('☆', 5 - $n) . "</span>";
}

function fmt_money(float $n): string { return '$' . number_format($n, 2); }
function fmt_date(string $d): string { return date('M d, Y', strtotime($d)); }
function fmt_month(string $d): string { return date('M Y', strtotime($d)); }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartTech Admin — <?= ucfirst($page) ?></title>
<style>
  *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
  :root {
    --bg:#0f1117; --surface:#1a1d27; --border:#2a2e42;
    --accent:#6c63ff; --accent-l:#8b85ff; --accent-dim:#6c63ff22;
    --ok:#22c55e; --warn:#f59e0b; --err:#ef4444;
    --text:#e8e9f0; --muted:#8b8fa8; --white:#ffffff;
  }
  body { background:var(--bg); color:var(--text); font-family:'Inter',system-ui,sans-serif;
         font-size:14px; display:flex; min-height:100vh; }
  a { color:inherit; text-decoration:none; }
  button,input,select,textarea { font-family:inherit; }
  ::-webkit-scrollbar { width:4px; }
  ::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
  input::placeholder,textarea::placeholder { color:var(--muted); }

  /* Sidebar */
  .sidebar { width:220px; min-height:100vh; background:var(--surface);
             border-right:1px solid var(--border); display:flex;
             flex-direction:column; flex-shrink:0; }
  .sb-logo { padding:18px 16px; border-bottom:1px solid var(--border);
             display:flex; align-items:center; gap:10px; }
  .sb-icon { width:32px; height:32px; border-radius:8px; background:var(--accent);
             display:flex; align-items:center; justify-content:center;
             font-weight:700; font-size:16px; color:var(--white); flex-shrink:0; }
  nav { flex:1; padding:12px 8px; }
  .nav-link { display:flex; align-items:center; gap:10px; padding:9px 10px;
              border-radius:7px; color:var(--muted); font-size:13px;
              margin-bottom:2px; transition:all .15s; width:100%; border:none;
              background:transparent; cursor:pointer; text-align:left; }
  .nav-link:hover,.nav-link.active { background:var(--accent-dim); color:var(--accent-l); }
  .nav-link.active { font-weight:600; }
  .sb-footer { padding:14px; border-top:1px solid var(--border);
               display:flex; align-items:center; gap:10px; }
  .sb-avatar { width:32px; height:32px; border-radius:50%; background:var(--accent);
               display:flex; align-items:center; justify-content:center;
               font-weight:700; color:var(--white); flex-shrink:0; }

  /* Main */
  .main { flex:1; display:flex; flex-direction:column; min-width:0; }
  .topbar { height:56px; background:var(--surface); border-bottom:1px solid var(--border);
            padding:0 24px; display:flex; align-items:center; gap:14px; flex-shrink:0; }
  .topbar-title { font-weight:700; font-size:16px; color:var(--white); text-transform:capitalize; }
  .spacer { flex:1; }
  .search-wrap { position:relative; }
  .search-wrap .ico { position:absolute; left:10px; top:50%; transform:translateY(-50%);
                      color:var(--muted); pointer-events:none; }
  .search-input { background:var(--bg); border:1px solid var(--border); border-radius:8px;
                  padding:6px 12px 6px 32px; color:var(--text); font-size:13px;
                  width:210px; outline:none; }
  .search-input:focus { border-color:var(--accent); }
  .content { flex:1; padding:24px; overflow-y:auto; }

  /* Cards */
  .card { background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:20px; }
  .card+.card,.two-col+.card { margin-top:16px; }
  .card-title { font-weight:700; color:var(--white); margin-bottom:14px; }
  .section-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; }
  .section-title { font-weight:700; font-size:18px; color:var(--white); }
  .section-sub { color:var(--muted); font-size:12px; margin-top:2px; }

  /* Table */
  .table-wrap { overflow-x:auto; }
  table { border-collapse:collapse; width:100%; }
  th { text-align:left; padding:8px 12px; color:var(--muted); font-size:11px;
       font-weight:600; border-bottom:1px solid var(--border);
       text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
  td { padding:10px 12px; font-size:13px; color:var(--text);
       border-bottom:1px solid #2a2e4220; white-space:nowrap; }
  tr:last-child td { border-bottom:none; }
  .empty td { text-align:center; color:var(--muted); padding:28px 0; }

  /* Stats */
  .stats-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr));
                gap:16px; margin-bottom:24px; }
  .stat-card { background:var(--surface); border:1px solid var(--border);
               border-radius:12px; padding:18px; }
  .stat-ico { font-size:22px; margin-bottom:8px; }
  .stat-lbl { color:var(--muted); font-size:12px; margin-bottom:4px; }
  .stat-val { font-size:22px; font-weight:700; color:var(--white); }
  .stat-sub { font-size:11px; color:var(--muted); margin-top:4px; }

  /* Charts */
  .chart-grid { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:24px; }
  .bar-chart { display:flex; align-items:flex-end; gap:5px; height:110px; }
  .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; }
  .bar { width:100%; border-radius:4px 4px 0 0; min-height:4px; }
  .bar-lbl { font-size:9px; color:var(--muted); }
  .prog-row { margin-bottom:10px; }
  .prog-meta { display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; }
  .prog-track { height:6px; background:var(--border); border-radius:4px; }
  .prog-fill { height:100%; border-radius:4px; }

  /* Forms */
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
  .form-group { margin-bottom:14px; }
  .form-label { display:block; color:var(--muted); font-size:11px; font-weight:600;
                text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
  .form-input,.form-select,.form-textarea {
    width:100%; background:var(--bg); border:1px solid var(--border);
    color:var(--text); border-radius:7px; padding:8px 12px; font-size:13px; outline:none; }
  .form-input:focus,.form-select:focus,.form-textarea:focus { border-color:var(--accent); }
  .form-textarea { resize:vertical; }
  .two-col { display:grid; grid-template-columns:1fr 1fr; gap:16px; }

  /* Buttons */
  .btn { padding:8px 18px; border-radius:8px; border:none; cursor:pointer;
         font-size:13px; font-weight:600; transition:opacity .15s; }
  .btn:hover { opacity:.85; }
  .btn-primary { background:var(--accent); color:var(--white); }
  .btn-sm { padding:4px 10px; border-radius:6px; border:none; cursor:pointer;
            font-size:11px; font-weight:600; transition:opacity .15s; }
  .btn-sm:hover { opacity:.8; }
  .btn-edit { background:var(--accent-dim); color:var(--accent-l); border:1px solid #6c63ff33; }
  .btn-del  { background:#ef444422; color:var(--err); border:1px solid #ef444433; }

  /* Filters */
  .filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
  .filter-btn { padding:5px 14px; border-radius:6px; font-size:12px; cursor:pointer;
                border:1px solid var(--border); background:transparent;
                color:var(--muted); transition:all .15s; }
  .filter-btn:hover,.filter-btn.active { background:var(--accent); border-color:var(--accent);
                                          color:var(--white); font-weight:600; }

  /* Toast */
  .toast { position:fixed; bottom:24px; right:24px; padding:10px 20px;
           border-radius:10px; font-weight:600; font-size:13px;
           box-shadow:0 4px 20px #0008; z-index:999; animation:fadeIn .25s ease; }
  .toast-success { background:var(--ok); color:var(--white); }
  .toast-error   { background:var(--err); color:var(--white); }
  @keyframes fadeIn { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

  @media(max-width:768px){
    .sidebar{width:60px;}
    .sb-logo span,.nav-link span,.sb-footer .sb-info{display:none;}
    .nav-link{justify-content:center;padding:9px 0;}
    .chart-grid,.two-col,.form-grid{grid-template-columns:1fr;}
    .search-input{width:140px;}
  }
</style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────── -->
<aside class="sidebar">
  <div class="sb-logo">
    <div class="sb-icon">S</div>
    <span style="font-weight:700;font-size:15px;color:var(--white)">SmartTech</span>
  </div>
  <nav>
    <?php
    $nav = [
      ['dashboard',  '⊞',  'Dashboard'],
      ['orders',     '📦', 'Orders'],
      ['products',   '🛍️', 'Products'],
      ['categories', '🗂️', 'Categories'],
      ['users',      '👥', 'Users'],
      ['reviews',    '⭐', 'Reviews'],
      ['payments',   '💳', 'Payments'],
    ];
    foreach ($nav as [$id, $ico, $lbl]): ?>
    <a href="dashboard.php?page=<?= $id ?>" class="nav-link <?= $page===$id?'active':'' ?>">
      <span style="font-size:16px"><?= $ico ?></span>
      <span><?= $lbl ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sb-footer">
    <div class="sb-avatar">A</div>
    <div class="sb-info">
      <div style="font-weight:600;font-size:13px;color:var(--white)">Admin</div>
      <div style="font-size:11px;color:var(--muted)">Administrator</div>
    </div>
  </div>
</aside>

<!-- ── Main ─────────────────────────────────────────── -->
<div class="main">
  <header class="topbar">
    <span class="topbar-title"><?= esc($page) ?></span>
    <div class="spacer"></div>
    <form method="GET" action="dashboard.php" class="search-wrap" style="display:flex">
      <input type="hidden" name="page" value="<?= esc($page) ?>">
      <span class="ico">🔍</span>
      <input class="search-input" type="text" name="search"
             value="<?= esc($search) ?>" placeholder="Search…">
    </form>
  </header>

  <div class="content">

  <!-- ===================================================
       DASHBOARD
  =================================================== -->
  <?php if ($page === 'dashboard'): ?>

  <div class="stats-grid">
    <?php
    $cards = [
      ['💰', 'Total Revenue',   fmt_money($stats['total_revenue']), 'from delivered orders'],
      ['📦', 'Total Orders',    $stats['total_orders'],             'all time'],
      ['👥', 'Customers',       $stats['total_users'],              'registered'],
      ['🛍️','Products',        $stats['total_products'],           'in catalog'],
      ['⏳', 'Pending Orders',  $stats['pending_orders'],           'awaiting action'],
      ['⚠️', 'Low Stock',      $stats['low_stock'],                'items under 5 units'],
    ];
    foreach ($cards as [$ico,$lbl,$val,$sub]): ?>
    <div class="stat-card">
      <div class="stat-ico"><?= $ico ?></div>
      <div class="stat-lbl"><?= $lbl ?></div>
      <div class="stat-val"><?= esc((string)$val) ?></div>
      <div class="stat-sub"><?= $sub ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="chart-grid">
    <!-- Revenue bar chart -->
    <div class="card">
      <div class="card-title">Monthly Revenue</div>
      <div class="bar-chart">
        <?php if (empty($monthlyRev)): ?>
          <div style="color:var(--muted);font-size:13px">No revenue data yet.</div>
        <?php else: foreach ($monthlyRev as $i => $m):
          $h    = $maxRev > 0 ? round(($m['total'] / $maxRev) * 100) : 0;
          $last = ($i === count($monthlyRev) - 1);
        ?>
        <div class="bar-col">
          <div class="bar" style="height:<?= $h ?>%;background:<?= $last?'var(--accent)':'var(--accent-dim)' ?>"></div>
          <div class="bar-lbl"><?= esc($m['month_label']) ?></div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Order status breakdown -->
    <div class="card">
      <div class="card-title">Order Status</div>
      <?php
      $totalOrders = max((int)$stats['total_orders'], 1);
      $stColors = ['delivered'=>'var(--ok)','shipped'=>'var(--accent)',
                   'processing'=>'var(--warn)','pending'=>'var(--muted)','cancelled'=>'var(--err)'];
      foreach ($stColors as $st => $col):
        $cnt = $statusBreakdown[$st] ?? 0;
        $pct = round($cnt / $totalOrders * 100);
      ?>
      <div class="prog-row">
        <div class="prog-meta">
          <span style="color:var(--muted)"><?= ucfirst($st) ?></span>
          <span style="color:var(--white);font-weight:600"><?= $pct ?>%</span>
        </div>
        <div class="prog-track">
          <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
        </div>
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
        <tr class="empty"><td colspan="5">No orders yet.</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
        <tr>
          <td style="color:var(--accent-l);font-weight:600">#<?= $o['OrderID'] ?></td>
          <td><?= esc($o['FullName']) ?></td>
          <td><?= fmt_money($o['TotalAmount']) ?></td>
          <td><?= badge($o['OrderStatus']) ?></td>
          <td style="color:var(--muted)"><?= fmt_date($o['OrderDate']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <!-- ===================================================
       ORDERS
  =================================================== -->
  <?php elseif ($page === 'orders'): ?>

  <div class="section-hdr">
    <div>
      <div class="section-title">Orders</div>
      <div class="section-sub"><?= count($orders) ?> order(s) found</div>
    </div>
  </div>

  <div class="filters">
    <?php foreach ([''=>'All','pending'=>'Pending','processing'=>'Processing',
                    'shipped'=>'Shipped','delivered'=>'Delivered','cancelled'=>'Cancelled'] as $v=>$l): ?>
    <a href="dashboard.php?page=orders&status=<?= $v ?>&search=<?= urlencode($search) ?>"
       class="filter-btn <?= $statusFilter===$v?'active':'' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <div class="card"><div class="table-wrap"><table>
    <thead><tr>
      <th>Order ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Shipping Address</th><th>Date</th><th>Update</th>
    </tr></thead>
    <tbody>
      <?php if (empty($orders)): ?>
      <tr class="empty"><td colspan="7">No orders found.</td></tr>
      <?php else: foreach ($orders as $o): ?>
      <tr>
        <td style="color:var(--accent-l);font-weight:600">#<?= $o['OrderID'] ?></td>
        <td><?= esc($o['FullName']) ?></td>
        <td><?= fmt_money($o['TotalAmount']) ?></td>
        <td><?= badge($o['OrderStatus']) ?></td>
        <td style="color:var(--muted);max-width:160px;overflow:hidden;text-overflow:ellipsis"><?= esc($o['ShippingAddress']) ?></td>
        <td style="color:var(--muted)"><?= fmt_date($o['OrderDate']) ?></td>
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

  <!-- ===================================================
       PRODUCTS
  =================================================== -->
  <?php elseif ($page === 'products'): ?>

  <div class="section-hdr">
    <div>
      <div class="section-title">Products</div>
      <div class="section-sub"><?= count($products) ?> product(s) found</div>
    </div>
  </div>

  <div class="two-col">
    <div class="card"><div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Category</th><th>Brand</th><th>Price</th><th>Stock</th><th>Status</th><th>Del</th></tr></thead>
      <tbody>
        <?php if (empty($products)): ?>
        <tr class="empty"><td colspan="7">No products found.</td></tr>
        <?php else: foreach ($products as $p):
          $sc = $p['StockQuantity']==0?'var(--err)':($p['StockQuantity']<5?'var(--warn)':'var(--ok)');
        ?>
        <tr>
          <td style="font-weight:600"><?= esc($p['ProductName']) ?></td>
          <td style="color:var(--muted)"><?= esc($p['CategoryName']) ?></td>
          <td><?= esc($p['Brand'] ?? '') ?></td>
          <td><?= fmt_money($p['Price']) ?></td>
          <td style="color:<?= $sc ?>;font-weight:600"><?= $p['StockQuantity'] ?></td>
          <td><?= badge($p['Status']) ?></td>
          <td>
            <form method="POST" style="display:inline">
              <input type="hidden" name="action" value="delete_product">
              <input type="hidden" name="product_id" value="<?= $p['ProductID'] ?>">
              <button type="submit" class="btn-sm btn-del"
                onclick="return confirm('Delete <?= esc(addslashes($p['ProductName'])) ?>?')">Del</button>
            </form>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table></div></div>

    <!-- Add Product form -->
    <div class="card">
      <div class="card-title">Add New Product</div>
      <form method="POST">
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
            <option value="<?= $c['CategoryID'] ?>"><?= esc($c['CategoryName']) ?></option>
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
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea class="form-textarea" name="description" rows="3" placeholder="Product description…"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%">Add Product</button>
      </form>
    </div>
  </div>

  <!-- ===================================================
       CATEGORIES
  =================================================== -->
  <?php elseif ($page === 'categories'): ?>

  <div class="section-hdr">
    <div class="section-title">Categories</div>
  </div>
  <div class="two-col">
    <div class="card">
      <div class="card-title">All Categories</div>
      <div class="table-wrap"><table>
        <thead><tr><th>ID</th><th>Name</th><th>Products</th><th>Action</th></tr></thead>
        <tbody>
          <?php if (empty($categories)): ?>
          <tr class="empty"><td colspan="4">No categories yet.</td></tr>
          <?php else: foreach ($categories as $c): ?>
          <tr>
            <td style="color:var(--muted)">#<?= $c['CategoryID'] ?></td>
            <td style="font-weight:600"><?= esc($c['CategoryName']) ?></td>
            <td style="color:var(--accent-l);font-weight:600"><?= $c['ProductCount'] ?></td>
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
          <label class="form-label">Category Name *</label>
          <input class="form-input" type="text" name="cat_name" placeholder="e.g. Smartphones" required>
        </div>
        <div class="form-group">
          <label class="form-label">Image URL (optional)</label>
          <input class="form-input" type="text" name="cat_image" placeholder="https://…">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px">Add Category</button>
      </form>
    </div>
  </div>

  <!-- ===================================================
       USERS
  =================================================== -->
  <?php elseif ($page === 'users'): ?>

  <div class="section-hdr">
    <div>
      <div class="section-title">Users</div>
      <div class="section-sub"><?= count($users) ?> user(s) found</div>
    </div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Joined</th><th>Action</th></tr></thead>
    <tbody>
      <?php if (empty($users)): ?>
      <tr class="empty"><td colspan="7">No users found.</td></tr>
      <?php else: foreach ($users as $u): ?>
      <tr>
        <td style="color:var(--muted)">#<?= $u['UserID'] ?></td>
        <td style="font-weight:600"><?= esc($u['FullName']) ?></td>
        <td style="color:var(--muted)"><?= esc($u['Email']) ?></td>
        <td><?= esc($u['Phone'] ?? '—') ?></td>
        <td><?= badge($u['Role']) ?></td>
        <td style="color:var(--muted)"><?= fmt_month($u['CreatedAt']) ?></td>
        <td>
          <?php if ($u['Role'] !== 'admin'): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="<?= $u['UserID'] ?>">
            <button type="submit" class="btn-sm btn-del"
              onclick="return confirm('Remove this user?')">Remove</button>
          </form>
          <?php else: ?>
          <span style="color:var(--muted);font-size:11px">Protected</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <!-- ===================================================
       REVIEWS
  =================================================== -->
  <?php elseif ($page === 'reviews'): ?>

  <div class="section-hdr">
    <div>
      <div class="section-title">Reviews</div>
      <div class="section-sub"><?= count($reviews) ?> review(s)</div>
    </div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr><th>Product</th><th>User</th><th>Rating</th><th>Comment</th><th>Date</th><th>Action</th></tr></thead>
    <tbody>
      <?php if (empty($reviews)): ?>
      <tr class="empty"><td colspan="6">No reviews found.</td></tr>
      <?php else: foreach ($reviews as $r): ?>
      <tr>
        <td style="font-weight:600"><?= esc($r['ProductName']) ?></td>
        <td><?= esc($r['FullName']) ?></td>
        <td><?= stars((int)$r['Rating']) ?></td>
        <td style="color:var(--muted);max-width:240px;overflow:hidden;text-overflow:ellipsis"><?= esc($r['Comment'] ?? '') ?></td>
        <td style="color:var(--muted)"><?= date('M d', strtotime($r['ReviewDate'])) ?></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="delete_review">
            <input type="hidden" name="review_id" value="<?= $r['ReviewID'] ?>">
            <button type="submit" class="btn-sm btn-del"
              onclick="return confirm('Remove this review?')">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <!-- ===================================================
       PAYMENTS
  =================================================== -->
  <?php elseif ($page === 'payments'): ?>

  <div class="section-hdr">
    <div>
      <div class="section-title">Payments</div>
      <div class="section-sub"><?= count($payments) ?> transaction(s)</div>
    </div>
  </div>
  <div class="card"><div class="table-wrap"><table>
    <thead><tr>
      <th>Pay ID</th><th>Order</th><th>Customer</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th>
    </tr></thead>
    <tbody>
      <?php if (empty($payments)): ?>
      <tr class="empty"><td colspan="7">No payment records.</td></tr>
      <?php else: foreach ($payments as $p): ?>
      <tr>
        <td style="color:var(--accent-l);font-weight:600">#<?= $p['PaymentID'] ?></td>
        <td style="color:var(--muted)">#<?= $p['OrderID'] ?></td>
        <td><?= esc($p['FullName']) ?></td>
        <td><?= fmt_money($p['TotalAmount']) ?></td>
        <td><?= esc($p['PaymentMethod']) ?></td>
        <td><?= badge($p['PaymentStatus']) ?></td>
        <td style="color:var(--muted)"><?= fmt_date($p['PaymentDate']) ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>

  <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<?php if ($toast): ?>
<div class="toast toast-<?= esc($toast['type']) ?>"><?= esc($toast['msg']) ?></div>
<script>setTimeout(()=>{ const t=document.querySelector('.toast'); if(t) t.remove(); }, 2800);</script>
<?php endif; ?>

</body>
</html>
<?php $conn->close(); ?>