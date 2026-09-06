<?php
// ============================================================
//  SMARTTECH  |  index.php  — Storefront Homepage
// ============================================================
session_start();
require_once 'connection.php';

// ── Cart init ──────────────────────────────────────────────────
if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// Add to cart POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $pid = (int)$_POST['product_id'];
    if ($pid > 0) {
        $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + 1;
        $_SESSION['toast'] = 'Product added to cart!';
    }
    redirect('index.php' . ($catFilter ? '?cat=' . (int)$_GET['cat'] : ''));
}

// ── URL params ──────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$catFilter = (int)($_GET['cat']   ?? 0);
$sort      = $_GET['sort']        ?? 'newest';
$toast     = $_SESSION['toast']   ?? '';
unset($_SESSION['toast']);

// ── Fetch categories ────────────────────────────────────────────
$categories = db_rows($conn, "
    SELECT c.*, COUNT(p.ProductID) AS cnt
    FROM CATEGORIES c
    LEFT JOIN PRODUCTS p ON c.CategoryID = p.CategoryID AND p.Status = 'active'
    GROUP BY c.CategoryID ORDER BY c.CategoryName
");

// ── Fetch products (filtered) ───────────────────────────────────
$where  = "p.Status = 'active'";
$types  = ''; $params = [];
if ($catFilter) { $where .= " AND p.CategoryID = ?"; $types .= 'i'; $params[] = $catFilter; }
if ($search) {
    $where  .= " AND (p.ProductName LIKE ? OR p.Brand LIKE ? OR p.Description LIKE ?)";
    $types  .= 'sss'; $like = "%$search%";
    $params[] = $like; $params[] = $like; $params[] = $like;
}
$orderBy = match($sort) {
    'price_asc'  => 'p.Price ASC',
    'price_desc' => 'p.Price DESC',
    'popular'    => 'avg_rating DESC',
    default      => 'p.CreatedAt DESC'
};
$products = db_rows($conn, "
    SELECT p.*, c.CategoryName,
           COALESCE(AVG(r.Rating), 0) AS avg_rating,
           COUNT(DISTINCT r.ReviewID)  AS review_count
    FROM PRODUCTS p
    JOIN CATEGORIES c ON p.CategoryID = c.CategoryID
    LEFT JOIN REVIEWS r ON p.ProductID = r.ProductID
    WHERE $where GROUP BY p.ProductID ORDER BY $orderBy
", $types, $params);

// ── Popular products ────────────────────────────────────────────
$popular = db_rows($conn, "
    SELECT p.*, c.CategoryName,
           COALESCE(AVG(r.Rating), 0) AS avg_rating,
           COUNT(DISTINCT r.ReviewID) AS review_count
    FROM PRODUCTS p
    JOIN CATEGORIES c ON p.CategoryID = c.CategoryID
    LEFT JOIN REVIEWS r ON p.ProductID = r.ProductID
    WHERE p.Status = 'active'
    GROUP BY p.ProductID
    ORDER BY review_count DESC, avg_rating DESC LIMIT 4
");

// ── Latest reviews ──────────────────────────────────────────────
$latestReviews = db_rows($conn, "
    SELECT r.*, p.ProductName, p.ProductID, u.FullName
    FROM REVIEWS r
    JOIN PRODUCTS p ON r.ProductID = p.ProductID
    JOIN USERS u ON r.UserID = u.UserID
    ORDER BY r.ReviewDate DESC LIMIT 4
");

// ── Hero stats ──────────────────────────────────────────────────
$totalProducts  = (int) db_value($conn, "SELECT COUNT(*) FROM PRODUCTS WHERE Status='active'");
$totalCustomers = (int) db_value($conn, "SELECT COUNT(*) FROM USERS WHERE Role='customer'");
$totalReviews   = (int) db_value($conn, "SELECT COUNT(*) FROM REVIEWS");
$totalBrands    = (int) db_value($conn, "SELECT COUNT(DISTINCT Brand) FROM PRODUCTS WHERE Status='active'");
$cartCount      = array_sum($_SESSION['cart']);
$catNames       = array_column($categories, 'CategoryName', 'CategoryID');
$catIcons       = ['Laptops'=>'💻','Audio'=>'🎧','Monitors'=>'🖥️','Peripherals'=>'⌨️',
                   'Accessories'=>'🖱️','Smartphones'=>'📱','Cameras'=>'📷','Gaming'=>'🎮'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartHome — Tech Store</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--white:#fff;--bg:#f5f5f5;--border:#e8e8e8;--shadow:0 2px 12px rgba(0,0,0,.08);
      --accent:#e67e22;--accent2:#f39c12;--dark:#1a1a2e;
      --text:#2c2c2c;--muted:#888;--light:#f9f9f9;
      --ok:#27ae60;--err:#e74c3c;--warn:#f39c12;--radius:10px;--nav-h:60px}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-thumb{background:#ccc;border-radius:4px}
input::placeholder{color:var(--muted)}

/* NAVBAR */
.navbar{background:var(--white);border-bottom:1px solid var(--border);height:var(--nav-h);
        position:sticky;top:0;z-index:200;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.nav-inner{max-width:1200px;margin:0 auto;height:100%;display:flex;align-items:center;gap:24px;padding:0 20px}
.logo{font-size:22px;font-weight:300;color:var(--dark)}
.logo span{color:var(--accent);font-weight:700}
.nav-links{display:flex;gap:22px;align-items:center}
.nav-links a{font-size:13px;color:var(--muted);transition:color .15s}
.nav-links a:hover,.nav-links a.active{color:var(--accent)}
.nav-spacer{flex:1}
.nav-search{display:flex;align-items:center;background:var(--bg);border:1px solid var(--border);
            border-radius:24px;padding:6px 14px;gap:8px;width:220px;transition:border-color .2s}
.nav-search:focus-within{border-color:var(--accent)}
.nav-search input{border:none;background:transparent;outline:none;font-size:13px;width:100%}
.nav-icons{display:flex;gap:14px;align-items:center}
.nav-icons a{position:relative;color:var(--muted);font-size:18px;transition:color .15s}
.nav-icons a:hover{color:var(--accent)}
.cart-badge{position:absolute;top:-6px;right:-7px;background:var(--accent);color:var(--white);
            font-size:9px;font-weight:700;border-radius:50%;width:16px;height:16px;
            display:flex;align-items:center;justify-content:center}

/* CART DRAWER */
.cart-overlay{position:fixed;inset:0;background:#0007;z-index:300;opacity:0;
              pointer-events:none;transition:opacity .25s}
.cart-overlay.open{opacity:1;pointer-events:all}
.cart-drawer{position:fixed;top:0;right:-420px;width:400px;max-width:100vw;height:100vh;
             background:var(--white);z-index:301;display:flex;flex-direction:column;
             transition:right .3s;box-shadow:-4px 0 24px rgba(0,0,0,.15)}
.cart-drawer.open{right:0}
.cart-head{padding:18px 20px;border-bottom:1px solid var(--border);
           display:flex;align-items:center;justify-content:space-between}
.cart-title{font-weight:700;font-size:16px;color:var(--dark)}
.cart-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted)}
.cart-body{flex:1;overflow-y:auto;padding:16px 20px}
.cart-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--border)}
.cart-item:last-child{border-bottom:none}
.ci-img{width:64px;height:64px;background:var(--light);border-radius:8px;
        display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.ci-img img{max-height:56px;object-fit:contain}
.ci-info{flex:1;min-width:0}
.ci-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.ci-qty{font-size:12px;color:var(--muted);margin-bottom:4px}
.ci-price{font-size:14px;font-weight:700;color:var(--accent)}
.ci-rm{background:none;border:none;color:var(--err);cursor:pointer;font-size:14px;padding:4px}
.cart-empty{text-align:center;color:var(--muted);padding:60px 20px}
.cart-empty .eico{font-size:48px;margin-bottom:12px}
.cart-foot{padding:18px 20px;border-top:1px solid var(--border)}
.cart-total-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
.cart-total-lbl{font-weight:600;color:var(--dark)}
.cart-total-val{font-size:20px;font-weight:700;color:var(--accent)}
.btn-checkout{width:100%;background:var(--dark);color:var(--white);border:none;
              border-radius:8px;padding:13px;font-size:14px;font-weight:600;
              cursor:pointer;margin-bottom:8px;transition:background .15s}
.btn-checkout:hover{background:#0f3460}
.btn-clear-cart{width:100%;background:none;border:1px solid var(--border);
                border-radius:8px;padding:9px;font-size:12px;color:var(--muted);cursor:pointer}

/* HERO */
.hero{background:linear-gradient(135deg,var(--dark) 0%,#16213e 60%,#0f3460 100%);
      color:var(--white);padding:64px 20px;text-align:center}
.hero h1{font-size:40px;font-weight:700;margin-bottom:12px;letter-spacing:-1px}
.hero h1 span{color:var(--accent)}
.hero p{color:#aab;font-size:16px;margin-bottom:28px;max-width:500px;margin-inline:auto}
.hero-search{display:flex;background:var(--white);border-radius:30px;overflow:hidden;
             max-width:460px;margin:0 auto;box-shadow:0 4px 20px rgba(0,0,0,.3)}
.hero-search input{flex:1;padding:14px 20px;border:none;outline:none;font-size:14px;color:var(--text)}
.hero-search button{background:var(--accent);border:none;padding:0 24px;
                    color:var(--white);font-size:16px;cursor:pointer;transition:background .15s}
.hero-search button:hover{background:var(--accent2)}
.hero-stats{display:flex;justify-content:center;gap:40px;margin-top:40px;flex-wrap:wrap}
.hero-stat span{display:block;font-size:26px;font-weight:700;color:var(--accent)}
.hero-stat small{color:#aab;font-size:12px}

/* PROMO BAR */
.promo-bar{background:linear-gradient(90deg,var(--accent),var(--accent2));
           color:var(--white);padding:10px;text-align:center;font-size:13px;font-weight:500}

/* WRAPPER / SECTIONS */
.wrapper{max-width:1200px;margin:0 auto;padding:0 20px}
.section-pad{padding:48px 0}
.section-hdr{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:24px}
.section-title{font-size:20px;font-weight:700;color:var(--dark)}
.section-sub{color:var(--muted);font-size:13px}
.see-all{font-size:12px;color:var(--accent);font-weight:600}
.see-all:hover{text-decoration:underline}

/* CATEGORIES */
.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:14px}
.cat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
          padding:18px 12px;text-align:center;cursor:pointer;transition:all .2s}
.cat-card:hover,.cat-card.active{border-color:var(--accent);transform:translateY(-2px);
                                  box-shadow:0 0 0 2px #e67e2222}
.cat-card.active{background:var(--accent);color:var(--white)}
.cat-icon{font-size:26px;margin-bottom:8px}
.cat-name{font-size:12px;font-weight:600;margin-bottom:2px}
.cat-count{font-size:11px;color:var(--muted)}
.cat-card.active .cat-count{color:#fff9}

/* FILTER BAR */
.filter-bar{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
            padding:12px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.filter-label{font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.filter-select{border:1px solid var(--border);border-radius:6px;padding:6px 10px;
               font-size:12px;outline:none;background:var(--bg);cursor:pointer}
.filter-select:focus{border-color:var(--accent)}
.clear-filter{font-size:12px;color:var(--err)}
.results-count{margin-left:auto;font-size:12px;color:var(--muted)}

/* PRODUCT GRID */
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:18px}
.product-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);
              overflow:hidden;transition:all .2s;cursor:pointer}
.product-card:hover{box-shadow:var(--shadow);transform:translateY(-3px)}
.product-img-wrap{position:relative;background:var(--light);aspect-ratio:4/3;
                  display:flex;align-items:center;justify-content:center;padding:12px;overflow:hidden}
.product-img-wrap img{max-height:160px;object-fit:contain;transition:transform .3s}
.product-card:hover .product-img-wrap img{transform:scale(1.06)}
.badge{position:absolute;top:10px;left:10px;font-size:10px;font-weight:700;
       padding:3px 8px;border-radius:4px;text-transform:uppercase;color:var(--white)}
.badge-green{background:var(--ok)}
.badge-orange{background:var(--warn)}
.badge-red{background:var(--err)}
.product-body{padding:14px}
.product-cat{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--accent);font-weight:700;margin-bottom:4px}
.product-name{font-size:13px;font-weight:600;color:var(--dark);margin-bottom:4px;
              line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.product-brand{font-size:11px;color:var(--muted);margin-bottom:6px}
.product-stars{display:flex;align-items:center;gap:4px;margin-bottom:8px;font-size:13px}
.review-count{font-size:11px;color:var(--muted)}
.product-price{font-size:17px;font-weight:700;color:var(--dark)}
.product-footer{display:flex;align-items:center;justify-content:space-between;
                padding:10px 14px;border-top:1px solid var(--border)}
.btn-cart{background:var(--accent);color:var(--white);border:none;border-radius:6px;
          padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s}
.btn-cart:hover{background:var(--accent2)}
.btn-wish{background:none;border:none;font-size:16px;color:var(--muted);cursor:pointer;transition:color .15s;padding:4px}
.btn-wish:hover{color:var(--err)}
.out-of-stock{font-size:11px;color:var(--err);font-weight:600}
.empty-state{grid-column:1/-1;text-align:center;padding:60px 0;color:var(--muted)}
.empty-ico{font-size:48px;margin-bottom:12px}
.btn-accent{background:var(--accent);color:var(--white);border:none;border-radius:8px;
            padding:9px 22px;font-size:13px;font-weight:600;cursor:pointer;margin-top:12px;display:inline-block}

/* POPULAR SECTION */
.popular-section{background:linear-gradient(135deg,var(--dark),#16213e);padding:48px 0}
.popular-section .section-title{color:var(--white)}
.popular-section .section-sub{color:#aab}
.dark-card{background:#1e2235;border-color:#2a2e42}
.dark-card .product-name{color:var(--white)}
.dark-card .product-cat{color:var(--accent)}
.dark-card .product-price{color:var(--white)}
.dark-footer{border-color:#2a2e42}

/* REVIEWS */
.review-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.review-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px}
.review-top{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.reviewer-avatar{width:36px;height:36px;border-radius:50%;background:var(--accent);
                 display:flex;align-items:center;justify-content:center;
                 color:var(--white);font-weight:700;font-size:14px;flex-shrink:0}
.reviewer-name{font-weight:600;font-size:13px;color:var(--dark)}
.reviewer-date{font-size:11px;color:var(--muted)}
.review-stars{font-size:14px;margin-bottom:6px}
.review-product-name{font-size:11px;color:var(--accent);font-weight:600;margin-bottom:6px}
.review-comment{font-size:13px;color:var(--muted);line-height:1.6}

/* FOOTER */
footer{background:var(--dark);color:#ccd;padding:48px 0 0}
.footer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:32px;margin-bottom:40px}
.footer-col h4{font-size:12px;font-weight:700;color:var(--white);margin-bottom:14px;
               text-transform:uppercase;letter-spacing:.6px}
.footer-col ul{list-style:none;display:flex;flex-direction:column;gap:8px}
.footer-col ul li a{font-size:12px;color:#889;transition:color .15s}
.footer-col ul li a:hover{color:var(--accent)}
.footer-col p{font-size:12px;color:#889;line-height:1.7}
.footer-logo{font-size:20px;font-weight:300;color:var(--white);margin-bottom:10px}
.footer-logo span{color:var(--accent);font-weight:700}
.footer-social{display:flex;gap:10px;margin-top:14px}
.footer-social a{width:34px;height:34px;border-radius:50%;background:#ffffff10;
                 display:flex;align-items:center;justify-content:center;font-size:15px;transition:background .15s}
.footer-social a:hover{background:var(--accent)}
.footer-bottom{border-top:1px solid #ffffff10;padding:16px 0;
               display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.footer-bottom p,.footer-bottom a{font-size:11px;color:#667}
.footer-bottom a:hover{color:var(--accent)}
.footer-links{display:flex;gap:16px;flex-wrap:wrap}

/* TOAST */
.toast-wrap{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:500;pointer-events:none}
.toast{background:var(--dark);color:var(--white);padding:10px 24px;border-radius:24px;
       font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.2);
       border-left:3px solid var(--accent);animation:su .3s ease}
@keyframes su{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

@media(max-width:768px){
  .nav-links{display:none}
  .hero h1{font-size:26px}
  .cat-grid{grid-template-columns:repeat(3,1fr)}
  .product-grid{grid-template-columns:repeat(2,1fr)}
  .footer-grid{grid-template-columns:1fr 1fr}
  .nav-search{width:160px}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="nav-inner">
    <a href="index.php" class="logo">Smart<span>home</span></a>
    <div class="nav-links">
      <a href="index.php" class="active">Products</a>
      <a href="index.php?view=about">About us</a>
      <a href="index.php?view=contact">Contacts</a>
      <?php if (isLoggedIn()): ?>
        <a href="logout.php" style="color:var(--err)">Logout</a>
      <?php else: ?>
        <a href="login.php" style="color:var(--accent)">Login</a>
      <?php endif; ?>
    </div>
    <div class="nav-spacer"></div>
    <form class="nav-search" method="GET" action="index.php">
      <span>🔍</span>
      <input type="text" name="search" value="<?= esc($search) ?>" placeholder="Search products…">
    </form>
    <div class="nav-icons">
      <a href="#" title="Wishlist">♡</a>
      <a href="#" onclick="toggleCart(event)" title="Cart">
        🛒
        <?php if ($cartCount > 0): ?>
        <span class="cart-badge"><?= $cartCount ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= isLoggedIn() ? '#' : 'login.php' ?>" title="Account">👤</a>
    </div>
  </div>
</nav>

<!-- CART DRAWER -->
<?php
$cartItems = []; $cartTotal = 0;
if (!empty($_SESSION['cart'])) {
    $ids  = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $rows = db_rows($conn, "SELECT ProductID,ProductName,Price,ProductImage FROM PRODUCTS WHERE ProductID IN ($ids)");
    foreach ($rows as $row) {
        $qty = $_SESSION['cart'][$row['ProductID']] ?? 1;
        $row['qty'] = $qty; $row['sub'] = $row['Price'] * $qty;
        $cartTotal += $row['sub']; $cartItems[] = $row;
    }
}
?>
<div class="cart-overlay" id="cartOverlay" onclick="toggleCart(event)"></div>
<div class="cart-drawer" id="cartDrawer">
  <div class="cart-head">
    <span class="cart-title">🛒 Cart (<?= $cartCount ?>)</span>
    <button class="cart-close" onclick="toggleCart(event)">✕</button>
  </div>
  <div class="cart-body">
    <?php if (empty($cartItems)): ?>
    <div class="cart-empty"><div class="eico">🛒</div><p>Your cart is empty</p></div>
    <?php else: foreach ($cartItems as $ci): ?>
    <div class="cart-item">
      <div class="ci-img"><img src="<?= thumb($ci['ProductImage'] ?? '') ?>" alt=""></div>
      <div class="ci-info">
        <div class="ci-name"><?= esc($ci['ProductName']) ?></div>
        <div class="ci-qty">Qty: <?= $ci['qty'] ?></div>
        <div class="ci-price"><?= fmt($ci['sub']) ?></div>
      </div>
      <a href="cart.php?remove=<?= $ci['ProductID'] ?>"><button class="ci-rm">✕</button></a>
    </div>
    <?php endforeach; endif; ?>
  </div>
  <?php if (!empty($cartItems)): ?>
  <div class="cart-foot">
    <div class="cart-total-row">
      <span class="cart-total-lbl">Total</span>
      <span class="cart-total-val"><?= fmt($cartTotal) ?></span>
    </div>
    <a href="cart.php"><button class="btn-checkout">View Cart / Checkout</button></a>
    <a href="cart.php?clear=1"><button class="btn-clear-cart">Clear cart</button></a>
  </div>
  <?php endif; ?>
</div>

<!-- HERO -->
<section class="hero">
  <div class="wrapper">
    <h1>The Best <span>Tech</span> Products</h1>
    <p>Laptops, audio, monitors, peripherals & more — all at unbeatable prices.</p>
    <form class="hero-search" method="GET" action="index.php">
      <input type="text" name="search" value="<?= esc($search) ?>" placeholder="Search for products…">
      <button type="submit">🔍</button>
    </form>
    <div class="hero-stats">
      <div class="hero-stat"><span><?= $totalProducts ?>+</span><small>Products</small></div>
      <div class="hero-stat"><span><?= $totalBrands ?>+</span><small>Brands</small></div>
      <div class="hero-stat"><span><?= $totalCustomers ?>+</span><small>Customers</small></div>
      <div class="hero-stat"><span><?= $totalReviews ?>+</span><small>Reviews</small></div>
    </div>
  </div>
</section>

<!-- PROMO BAR -->
<div class="promo-bar">🚀 Free shipping on orders over R$ 500 &nbsp;|&nbsp; Use code <strong>SMART10</strong> for 10% off</div>

<!-- CATEGORIES -->
<section class="section-pad">
  <div class="wrapper">
    <div class="section-hdr">
      <div>
        <div class="section-title">Product Categories</div>
        <div class="section-sub">Browse by department</div>
      </div>
    </div>
    <div class="cat-grid">
      <a href="index.php" class="cat-card <?= $catFilter===0?'active':'' ?>">
        <div class="cat-icon">🏪</div>
        <div class="cat-name">All Products</div>
        <div class="cat-count"><?= count($products) ?> items</div>
      </a>
      <?php
      $catIcons=['Laptops'=>'💻','Audio'=>'🎧','Monitors'=>'🖥️','Peripherals'=>'⌨️',
                 'Accessories'=>'🖱️','Smartphones'=>'📱','Cameras'=>'📷','Gaming'=>'🎮'];
      foreach ($categories as $c): ?>
      <a href="index.php?cat=<?= $c['CategoryID'] ?>" class="cat-card <?= $catFilter===$c['CategoryID']?'active':'' ?>">
        <div class="cat-icon"><?= $catIcons[$c['CategoryName']] ?? '📦' ?></div>
        <div class="cat-name"><?= esc($c['CategoryName']) ?></div>
        <div class="cat-count"><?= $c['cnt'] ?> items</div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ALL PRODUCTS -->
<section class="section-pad" style="padding-top:0">
  <div class="wrapper">
    <div class="section-hdr">
      <div>
        <div class="section-title">
          <?= $search ? 'Search: "'.esc($search).'"' : ($catFilter&&isset($catNames[$catFilter]) ? esc($catNames[$catFilter]) : 'All Products') ?>
        </div>
        <div class="section-sub"><?= count($products) ?> product(s) found</div>
      </div>
    </div>
    <div class="filter-bar">
      <form method="GET" id="sf" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <?php if ($catFilter): ?><input type="hidden" name="cat"    value="<?= $catFilter ?>"><?php endif; ?>
        <?php if ($search):    ?><input type="hidden" name="search" value="<?= esc($search) ?>"><?php endif; ?>
        <span class="filter-label">Sort by:</span>
        <select class="filter-select" name="sort" onchange="document.getElementById('sf').submit()">
          <option value="newest"     <?= $sort==='newest'    ?'selected':'' ?>>Newest</option>
          <option value="popular"    <?= $sort==='popular'   ?'selected':'' ?>>Most Popular</option>
          <option value="price_asc"  <?= $sort==='price_asc' ?'selected':'' ?>>Price ↑</option>
          <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Price ↓</option>
        </select>
      </form>
      <?php if ($search||$catFilter): ?><a href="index.php" class="clear-filter">✕ Clear</a><?php endif; ?>
      <span class="results-count"><?= count($products) ?> results</span>
    </div>
    <div class="product-grid">
      <?php if (empty($products)): ?>
      <div class="empty-state"><div class="empty-ico">🔍</div><p>No products found.</p><a href="index.php" class="btn-accent">Clear filters</a></div>
      <?php else: foreach ($products as $p):
        $isNew=$p['StockQuantity']>0&&strtotime($p['CreatedAt'])>strtotime('-30 days');
        $isLow=$p['StockQuantity']>0&&$p['StockQuantity']<5;
        $isOut=$p['StockQuantity']==0;
      ?>
      <div class="product-card" onclick="location.href='product.php?id=<?= $p['ProductID'] ?>'">
        <div class="product-img-wrap">
          <?php if($isOut): ?><span class="badge badge-red">Out of Stock</span>
          <?php elseif($isLow): ?><span class="badge badge-orange">Low Stock</span>
          <?php elseif($isNew): ?><span class="badge badge-green">New</span><?php endif; ?>
          <img src="<?= thumb($p['ProductImage']??'') ?>" alt="<?= esc($p['ProductName']) ?>" loading="lazy">
        </div>
        <div class="product-body">
          <div class="product-cat"><?= esc($p['CategoryName']) ?></div>
          <div class="product-name"><?= esc($p['ProductName']) ?></div>
          <div class="product-brand"><?= esc($p['Brand']??'') ?></div>
          <div class="product-stars"><?= stars((float)$p['avg_rating']) ?> <span class="review-count">(<?= $p['review_count'] ?>)</span></div>
          <div class="product-price"><?= fmt($p['Price']) ?></div>
        </div>
        <div class="product-footer" onclick="event.stopPropagation()">
          <?php if(!$isOut): ?>
          <form method="POST"><input type="hidden" name="product_id" value="<?= $p['ProductID'] ?>">
          <button type="submit" name="add_to_cart" value="1" class="btn-cart">Add to Cart</button></form>
          <?php else: ?><span class="out-of-stock">Out of Stock</span><?php endif; ?>
          <button class="btn-wish">♡</button>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</section>

<!-- POPULAR PRODUCTS -->
<section class="popular-section">
  <div class="wrapper">
    <div class="section-hdr">
      <div><div class="section-title" style="color:#fff">Popular Products</div>
      <div class="section-sub" style="color:#aab">Top-rated by our customers</div></div>
      <a href="index.php?sort=popular" class="see-all">View all →</a>
    </div>
    <div class="product-grid">
      <?php foreach ($popular as $p): ?>
      <div class="product-card dark-card" onclick="location.href='product.php?id=<?= $p['ProductID'] ?>'">
        <div class="product-img-wrap"><img src="<?= thumb($p['ProductImage']??'') ?>" alt="<?= esc($p['ProductName']) ?>" loading="lazy"></div>
        <div class="product-body">
          <div class="product-cat"><?= esc($p['CategoryName']) ?></div>
          <div class="product-name"><?= esc($p['ProductName']) ?></div>
          <div class="product-stars"><?= stars((float)$p['avg_rating']) ?> <span class="review-count" style="color:#889">(<?= $p['review_count'] ?>)</span></div>
          <div class="product-price"><?= fmt($p['Price']) ?></div>
        </div>
        <div class="product-footer dark-footer" onclick="event.stopPropagation()">
          <?php if($p['StockQuantity']>0): ?>
          <form method="POST"><input type="hidden" name="product_id" value="<?= $p['ProductID'] ?>">
          <button type="submit" name="add_to_cart" value="1" class="btn-cart">Add to Cart</button></form>
          <?php else: ?><span class="out-of-stock">Out of Stock</span><?php endif; ?>
          <button class="btn-wish">♡</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- CUSTOMER REVIEWS -->
<?php if (!empty($latestReviews)): ?>
<section class="section-pad">
  <div class="wrapper">
    <div class="section-hdr">
      <div><div class="section-title">Customer Reviews</div><div class="section-sub">What our buyers are saying</div></div>
    </div>
    <div class="review-grid">
      <?php foreach ($latestReviews as $rv): ?>
      <div class="review-card" onclick="location.href='product.php?id=<?= $rv['ProductID'] ?>'" style="cursor:pointer">
        <div class="review-top">
          <div class="reviewer-avatar"><?= strtoupper(substr($rv['FullName'],0,1)) ?></div>
          <div><div class="reviewer-name"><?= esc($rv['FullName']) ?></div>
          <div class="reviewer-date"><?= ago($rv['ReviewDate']) ?></div></div>
        </div>
        <div class="review-stars"><?= stars((int)$rv['Rating']) ?></div>
        <div class="review-product-name"><?= esc($rv['ProductName']) ?></div>
        <div class="review-comment"><?= esc(substr($rv['Comment']??'',0,110)) ?><?= strlen($rv['Comment']??'')>110?'…':'' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- FOOTER -->
<footer>
  <div class="wrapper">
    <div class="footer-grid">
      <div class="footer-col">
        <div class="footer-logo">Smart<span>home</span></div>
        <p>Your one-stop shop for the latest tech at unbeatable prices.</p>
        <div class="footer-social">
          <a href="#">📷</a><a href="#">👍</a><a href="#">📌</a><a href="#">🐦</a>
        </div>
      </div>
      <div class="footer-col"><h4>Help</h4><ul>
        <li><a href="#">Online Help</a></li><li><a href="#">FAQ</a></li>
        <li><a href="#">Shipping Policy</a></li><li><a href="#">Returns</a></li>
      </ul></div>
      <div class="footer-col"><h4>Company</h4><ul>
        <li><a href="#">About Us</a></li><li><a href="#">Contact</a></li>
        <li><a href="#">Careers</a></li><li><a href="#">Blog</a></li>
      </ul></div>
      <div class="footer-col"><h4>Categories</h4><ul>
        <?php foreach (array_slice($categories,0,5) as $c): ?>
        <li><a href="index.php?cat=<?= $c['CategoryID'] ?>"><?= esc($c['CategoryName']) ?></a></li>
        <?php endforeach; ?>
      </ul></div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> SmartHome. All rights reserved.</p>
      <div class="footer-links">
        <a href="#">Privacy</a><a href="#">Sitemap</a><a href="#">Cookies</a>
      </div>
    </div>
  </div>
</footer>

<?php if ($toast): ?>
<div class="toast-wrap"><div class="toast">✓ <?= esc($toast) ?></div></div>
<script>setTimeout(()=>document.querySelector('.toast-wrap')?.remove(),2800)</script>
<?php endif; ?>

<script>
function toggleCart(e){
  e?.preventDefault();
  document.getElementById('cartDrawer').classList.toggle('open');
  document.getElementById('cartOverlay').classList.toggle('open');
  document.body.style.overflow=document.getElementById('cartDrawer').classList.contains('open')?'hidden':'';
}
document.querySelectorAll('.btn-wish').forEach(b=>{
  b.addEventListener('click',function(e){
    e.stopPropagation();
    this.textContent=this.textContent==='♡'?'♥':'♡';
    this.style.color=this.textContent==='♥'?'var(--err)':'';
  });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
