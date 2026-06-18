<?php
// ============================================================
//  SMARTTECH  |  product.php  — Product Detail Page
// ============================================================
session_start();
require_once 'connection.php';


if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('index.php');

// ── Fetch product ───────────────────────────────────────────────
$product = db_row($conn, "
    SELECT p.*, c.CategoryName,
           COALESCE(AVG(r.Rating), 0) AS avg_rating,
           COUNT(DISTINCT r.ReviewID) AS review_count
    FROM PRODUCTS p
    JOIN CATEGORIES c ON p.CategoryID = c.CategoryID
    LEFT JOIN REVIEWS r ON p.ProductID = r.ProductID
    WHERE p.ProductID = ? AND p.Status = 'active'
    GROUP BY p.ProductID
", 'i', [$id]);

if (!$product) { header('Location: index.php'); exit; }

// ── Add to cart ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $_SESSION['cart'][$id] = ($_SESSION['cart'][$id] ?? 0) + $qty;
    $_SESSION['toast'] = 'Added to cart!';
    redirect("product.php?id=$id");
}

// ── Submit review ───────────────────────────────────────────────
$reviewMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $name    = trim($_POST['reviewer_name'] ?? '');
    $email   = trim($_POST['reviewer_email'] ?? '');

    if ($rating >= 1 && $rating <= 5 && $name && $email) {
        // Find or create guest user
        $user = db_row($conn, "SELECT UserID FROM USERS WHERE Email=? LIMIT 1", 's', [$email]);
        if (!$user) {
            db_run($conn,
                "INSERT INTO USERS (FullName,Email,Password,Role) VALUES (?,?,?,'customer')",
                'sss', [$name, $email, password_hash(uniqid(), PASSWORD_DEFAULT)]
            );
            $userId = $conn->insert_id;
        } else {
            $userId = $user['UserID'];
        }
        // Prevent duplicate
        $exists = db_value($conn, "SELECT ReviewID FROM REVIEWS WHERE ProductID=? AND UserID=?", 'ii', [$id, $userId]);
        if ($exists) {
            $reviewMsg = 'error:You have already reviewed this product.';
        } else {
            db_run($conn,
                "INSERT INTO REVIEWS (ProductID,UserID,Rating,Comment) VALUES (?,?,?,?)",
                'iiis', [$id, $userId, $rating, $comment]
            );
            $reviewMsg = 'success:Thank you for your review!';
            redirect("product.php?id=$id&reviewed=1");
        }
    } else {
        $reviewMsg = 'error:Please fill in all required fields.';
    }
}

// ── Fetch reviews ───────────────────────────────────────────────
$reviews = db_rows($conn, "
    SELECT r.*, u.FullName FROM REVIEWS r
    JOIN USERS u ON r.UserID = u.UserID
    WHERE r.ProductID = ? ORDER BY r.ReviewDate DESC
", 'i', [$id]);

// ── Related products ────────────────────────────────────────────
$related = db_rows($conn, "
    SELECT p.*, COALESCE(AVG(r.Rating),0) AS avg_rating
    FROM PRODUCTS p
    LEFT JOIN REVIEWS r ON p.ProductID=r.ProductID
    WHERE p.CategoryID = ? AND p.ProductID != ? AND p.Status='active'
    GROUP BY p.ProductID ORDER BY avg_rating DESC LIMIT 4
", 'ii', [$product['CategoryID'], $id]);

$toast     = $_SESSION['toast'] ?? '';
$cartCount = array_sum($_SESSION['cart']);
unset($_SESSION['toast']);

// Rating distribution
$ratingDist = [5=>0, 4=>0, 3=>0, 2=>0, 1=>0];
foreach ($reviews as $r) $ratingDist[(int)$r['Rating']]++;
$totalR = count($reviews) ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= esc($product['ProductName']) ?> — SmartHome</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--white:#fff;--bg:#f5f5f5;--border:#e8e8e8;--shadow:0 2px 12px rgba(0,0,0,.08);
      --accent:#e67e22;--accent2:#f39c12;--dark:#1a1a2e;
      --text:#2c2c2c;--muted:#888;--light:#f9f9f9;
      --ok:#27ae60;--err:#e74c3c;--warn:#f39c12;--radius:10px;--nav-h:60px}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
input::placeholder,textarea::placeholder{color:var(--muted)}

/* NAVBAR */
.navbar{background:var(--white);border-bottom:1px solid var(--border);height:var(--nav-h);
        position:sticky;top:0;z-index:200;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.nav-inner{max-width:1200px;margin:0 auto;height:100%;display:flex;align-items:center;gap:24px;padding:0 20px}
.logo{font-size:22px;font-weight:300;color:var(--dark)}.logo span{color:var(--accent);font-weight:700}
.nav-links{display:flex;gap:22px}.nav-links a{font-size:13px;color:var(--muted);transition:color .15s}
.nav-links a:hover{color:var(--accent)}.nav-spacer{flex:1}
.nav-search{display:flex;align-items:center;background:var(--bg);border:1px solid var(--border);
            border-radius:24px;padding:6px 14px;gap:8px;width:200px}
.nav-search input{border:none;background:transparent;outline:none;font-size:13px;width:100%}
.nav-icons{display:flex;gap:14px;align-items:center}
.nav-icons a{position:relative;color:var(--muted);font-size:18px;transition:color .15s}
.nav-icons a:hover{color:var(--accent)}
.cart-badge{position:absolute;top:-6px;right:-7px;background:var(--accent);color:var(--white);
            font-size:9px;font-weight:700;border-radius:50%;width:16px;height:16px;
            display:flex;align-items:center;justify-content:center}

.wrapper{max-width:1200px;margin:0 auto;padding:0 20px}

/* BREADCRUMB */
.breadcrumb{padding:16px 0;display:flex;gap:6px;align-items:center;font-size:12px;color:var(--muted)}
.breadcrumb a{color:var(--accent)}.breadcrumb a:hover{text-decoration:underline}

/* PRODUCT DETAIL */
.detail-layout{display:grid;grid-template-columns:1fr 1fr;gap:40px;padding:24px 0 48px}
.detail-img-box{background:var(--white);border:1px solid var(--border);border-radius:12px;
                padding:32px;display:flex;align-items:center;justify-content:center;
                aspect-ratio:1;overflow:hidden;position:relative}
.detail-img-box img{max-height:340px;object-fit:contain}
.detail-badge{position:absolute;top:16px;left:16px;font-size:11px;font-weight:700;
              padding:4px 10px;border-radius:6px;text-transform:uppercase;color:var(--white)}
.badge-green{background:var(--ok)}.badge-orange{background:var(--warn)}.badge-red{background:var(--err)}

.detail-info{}
.detail-cat{font-size:11px;text-transform:uppercase;letter-spacing:.8px;color:var(--accent);font-weight:700;margin-bottom:8px}
.detail-name{font-size:26px;font-weight:700;color:var(--dark);margin-bottom:8px;line-height:1.3}
.detail-brand{font-size:13px;color:var(--muted);margin-bottom:12px}
.detail-stars{display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:15px}
.detail-stars span{font-size:13px;color:var(--muted)}
.detail-price{font-size:34px;font-weight:700;color:var(--accent);margin-bottom:8px}
.detail-stock{font-size:13px;margin-bottom:20px;font-weight:600}
.stock-ok{color:var(--ok)}.stock-low{color:var(--warn)}.stock-out{color:var(--err)}
.detail-desc{font-size:14px;color:var(--muted);line-height:1.8;margin-bottom:24px;
             padding:16px;background:var(--light);border-radius:8px;border-left:3px solid var(--accent)}
.detail-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.qty-wrap{display:flex;align-items:center;border:1px solid var(--border);border-radius:8px;overflow:hidden}
.qty-wrap button{background:var(--bg);border:none;padding:9px 16px;cursor:pointer;
                 font-size:18px;font-weight:300;transition:background .15s}
.qty-wrap button:hover{background:var(--border)}
.qty-wrap input{width:48px;text-align:center;border:none;border-left:1px solid var(--border);
                border-right:1px solid var(--border);padding:9px 0;outline:none;font-size:14px}
.btn-add{background:var(--accent);color:var(--white);border:none;border-radius:8px;
         padding:11px 28px;font-size:14px;font-weight:600;cursor:pointer;
         flex:1;transition:background .15s;min-width:140px}
.btn-add:hover{background:var(--accent2)}
.btn-add:disabled{background:#ccc;cursor:not-allowed}
.btn-wish{background:none;border:1px solid var(--border);border-radius:8px;
          padding:11px 16px;font-size:20px;cursor:pointer;color:var(--muted);transition:all .15s}
.btn-wish:hover{border-color:var(--err);color:var(--err)}

/* RATING SUMMARY */
.rating-summary{background:var(--white);border:1px solid var(--border);border-radius:12px;
                padding:24px;display:flex;gap:32px;align-items:center;margin-bottom:28px}
.rating-big{text-align:center;flex-shrink:0}
.rating-num{font-size:52px;font-weight:700;color:var(--dark);line-height:1}
.rating-stars{font-size:20px;margin:6px 0}
.rating-total{font-size:12px;color:var(--muted)}
.rating-bars{flex:1}
.rbar-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.rbar-lbl{font-size:12px;color:var(--muted);width:36px;text-align:right}
.rbar-track{flex:1;height:8px;background:var(--border);border-radius:4px;overflow:hidden}
.rbar-fill{height:100%;background:var(--warn);border-radius:4px}
.rbar-count{font-size:12px;color:var(--muted);width:24px}

/* REVIEWS */
.reviews-wrap{margin-top:8px}
.reviews-wrap h2{font-size:18px;font-weight:700;color:var(--dark);margin-bottom:20px}
.review-list{display:flex;flex-direction:column;gap:14px;margin-bottom:32px}
.review-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px}
.review-top{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.reviewer-avatar{width:38px;height:38px;border-radius:50%;background:var(--accent);
                 display:flex;align-items:center;justify-content:center;
                 color:var(--white);font-weight:700;font-size:15px;flex-shrink:0}
.reviewer-name{font-weight:600;font-size:13px}
.reviewer-date{font-size:11px;color:var(--muted)}
.review-rating{margin-left:auto;font-size:14px}
.review-comment{font-size:13px;color:var(--muted);line-height:1.7}

/* REVIEW FORM */
.review-form{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:24px}
.review-form h3{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:11px;font-weight:600;color:var(--muted);
            text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.form-input,.form-select,.form-textarea{width:100%;border:1px solid var(--border);border-radius:7px;
  padding:9px 12px;font-size:13px;outline:none;background:var(--bg);transition:border-color .15s}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--accent)}
.form-textarea{resize:vertical}
.star-rating{display:flex;gap:4px;margin-bottom:14px}
.star-rating input{display:none}
.star-rating label{font-size:28px;color:#ddd;cursor:pointer;transition:color .15s}
.star-rating input:checked ~ label,.star-rating label:hover,.star-rating label:hover ~ label{color:var(--warn)}
.star-rating{flex-direction:row-reverse;justify-content:flex-end}
.star-rating input:checked ~ label{color:var(--warn)}
.btn-submit{background:var(--accent);color:var(--white);border:none;border-radius:8px;
            padding:10px 28px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s}
.btn-submit:hover{background:var(--accent2)}
.alert{padding:10px 16px;border-radius:8px;font-size:13px;margin-bottom:16px;font-weight:500}
.alert-ok{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}

/* RELATED */
.related-section{padding:48px 0}
.related-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px}
.product-card{background:var(--white);border:1px solid var(--border);border-radius:12px;
              overflow:hidden;transition:all .2s;cursor:pointer}
.product-card:hover{box-shadow:var(--shadow);transform:translateY(-3px)}
.product-img-wrap{background:var(--light);aspect-ratio:4/3;display:flex;
                  align-items:center;justify-content:center;padding:12px}
.product-img-wrap img{max-height:120px;object-fit:contain}
.product-body{padding:12px}
.product-cat{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--accent);font-weight:700;margin-bottom:3px}
.product-name{font-size:13px;font-weight:600;color:var(--dark);margin-bottom:6px;
              display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.product-price{font-size:15px;font-weight:700;color:var(--dark)}
.product-footer{display:flex;align-items:center;justify-content:space-between;
                padding:10px 12px;border-top:1px solid var(--border)}
.btn-cart{background:var(--accent);color:var(--white);border:none;border-radius:6px;
          padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer}
.btn-wish-sm{background:none;border:none;font-size:14px;color:var(--muted);cursor:pointer}

/* FOOTER */
footer{background:var(--dark);color:#ccd;padding:32px 0;text-align:center}
footer p{font-size:12px;color:#667;margin-top:8px}
.footer-logo{font-size:18px;font-weight:300;color:var(--white)}
.footer-logo span{color:var(--accent);font-weight:700}

/* TOAST */
.toast-wrap{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:500;pointer-events:none}
.toast{background:var(--dark);color:var(--white);padding:10px 24px;border-radius:24px;
       font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.2);
       border-left:3px solid var(--accent);animation:su .3s ease}
@keyframes su{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

@media(max-width:768px){
  .detail-layout{grid-template-columns:1fr}
  .form-grid{grid-template-columns:1fr}
  .nav-links{display:none}
  .related-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="nav-inner">
    <a href="index.php" class="logo">Smart<span>home</span></a>
    <div class="nav-links">
      <a href="index.php">Products</a>
      <a href="index.php?view=about">About us</a>
      <a href="login.php" style="color:var(--accent)">Login</a>
    </div>
    <div class="nav-spacer"></div>
    <form class="nav-search" method="GET" action="index.php">
      <span>🔍</span>
      <input type="text" name="search" placeholder="Search products…">
    </form>
    <div class="nav-icons">
      <a href="#">♡</a>
      <a href="cart.php" title="Cart">
        🛒
        <?php if ($cartCount > 0): ?>
        <span class="cart-badge"><?= $cartCount ?></span>
        <?php endif; ?>
      </a>
      <a href="login.php">👤</a>
    </div>
  </div>
</nav>

<div class="wrapper">
  <!-- BREADCRUMB -->
  <div class="breadcrumb">
    <a href="index.php">Home</a> /
    <a href="index.php?cat=<?= $product['CategoryID'] ?>"><?= esc($product['CategoryName']) ?></a> /
    <?= esc($product['ProductName']) ?>
  </div>

  <!-- PRODUCT DETAIL -->
  <div class="detail-layout">
    <!-- Image -->
    <div>
      <div class="detail-img-box">
        <?php
        $isOut = $product['StockQuantity'] == 0;
        $isLow = $product['StockQuantity'] > 0 && $product['StockQuantity'] < 5;
        $isNew = strtotime($product['CreatedAt']) > strtotime('-30 days') && !$isOut;
        if ($isOut): ?><span class="detail-badge badge-red">Out of Stock</span>
        <?php elseif ($isLow): ?><span class="detail-badge badge-orange">Low Stock</span>
        <?php elseif ($isNew): ?><span class="detail-badge badge-green">New</span>
        <?php endif; ?>
        <img src="<?= thumb($product['ProductImage'] ?? '') ?>" alt="<?= esc($product['ProductName']) ?>">
        
      </div>
    </div>

    <!-- Info -->
    <div class="detail-info">
      <div class="detail-cat"><?= esc($product['CategoryName']) ?></div>
      <h1 class="detail-name"><?= esc($product['ProductName']) ?></h1>
      <div class="detail-brand">Brand: <strong><?= esc($product['Brand'] ?? '—') ?></strong></div>
      <div class="detail-stars">
        <?= stars((float)$product['avg_rating']) ?>
        <span><?= number_format($product['avg_rating'], 1) ?> (<?= $product['review_count'] ?> reviews)</span>
      </div>
      <div class="detail-price"><?= fmt($product['Price']) ?></div>
      <div class="detail-stock">
        <?php if ($isOut): ?>
          <span class="stock-out">✗ Out of Stock</span>
        <?php elseif ($isLow): ?>
          <span class="stock-low">⚠ Only <?= $product['StockQuantity'] ?> left in stock!</span>
        <?php else: ?>
          <span class="stock-ok">✓ In Stock (<?= $product['StockQuantity'] ?> units)</span>
        <?php endif; ?>
      </div>
      <?php if ($product['Description']): ?>
      <div class="detail-desc"><?= nl2br(esc($product['Description'])) ?></div>
      <?php endif; ?>

      <!-- Add to cart -->
      <?php if (!$isOut): ?>
      <form method="POST" class="detail-actions">
        <input type="hidden" name="add_to_cart" value="1">
        <div class="qty-wrap">
          <button type="button" onclick="adjQty(-1)">−</button>
          <input type="number" name="qty" id="qtyInput" value="1" min="1" max="<?= $product['StockQuantity'] ?>">
          <button type="button" onclick="adjQty(1)">+</button>
        </div>
        <button type="submit" class="btn-add">🛒 Add to Cart</button>
        <button type="button" class="btn-wish" onclick="this.textContent=this.textContent==='♡'?'♥':'♡';this.style.color=this.textContent==='♥'?'var(--err)':''">♡</button>
      </form>
      <?php else: ?>
      <div class="detail-actions">
        <button class="btn-add" disabled>Out of Stock</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- REVIEWS SECTION -->
  <div class="reviews-wrap">
    <h2>Customer Reviews (<?= count($reviews) ?>)</h2>

    <!-- Rating Summary -->
    <?php if (!empty($reviews)): ?>
    <div class="rating-summary">
      <div class="rating-big">
        <div class="rating-num"><?= number_format($product['avg_rating'], 1) ?></div>
        <div class="rating-stars"><?= stars((float)$product['avg_rating']) ?></div>
        <div class="rating-total"><?= count($reviews) ?> reviews</div>
      </div>
      <div class="rating-bars">
        <?php for ($s = 5; $s >= 1; $s--): $pct = round($ratingDist[$s] / $totalR * 100); ?>
        <div class="rbar-row">
          <span class="rbar-lbl"><?= $s ?>★</span>
          <div class="rbar-track"><div class="rbar-fill" style="width:<?= $pct ?>%"></div></div>
          <span class="rbar-count"><?= $ratingDist[$s] ?></span>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Review List -->
    <div class="review-list">
      <?php if (empty($reviews)): ?>
      <div style="color:var(--muted);padding:20px 0;text-align:center">
        <div style="font-size:36px;margin-bottom:10px">💬</div>
        No reviews yet. Be the first to review this product!
      </div>
      <?php else: foreach ($reviews as $rv): ?>
      <div class="review-card">
        <div class="review-top">
          <div class="reviewer-avatar"><?= strtoupper(substr($rv['FullName'], 0, 1)) ?></div>
          <div>
            <div class="reviewer-name"><?= esc($rv['FullName']) ?></div>
            <div class="reviewer-date"><?= ago($rv['ReviewDate']) ?></div>
          </div>
          <div class="review-rating"><?= stars((int)$rv['Rating']) ?></div>
        </div>
        <?php if ($rv['Comment']): ?>
        <div class="review-comment"><?= esc($rv['Comment']) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Review Form -->
    <div class="review-form">
      <h3>✍ Write a Review</h3>
      <?php if ($reviewMsg): [$type,$msg] = explode(':', $reviewMsg, 2); ?>
      <div class="alert alert-<?= $type==='success'?'ok':'err' ?>"><?= esc($msg) ?></div>
      <?php endif; ?>
      <?php if (isset($_GET['reviewed'])): ?>
      <div class="alert alert-ok">✓ Your review has been submitted. Thank you!</div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="submit_review" value="1">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Your Name *</label>
            <input class="form-input" type="text" name="reviewer_name" placeholder="John Doe" required>
          </div>
          <div class="form-group">
            <label class="form-label">Email *</label>
            <input class="form-input" type="email" name="reviewer_email" placeholder="john@email.com" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Rating *</label>
          <select class="form-select" name="rating" required>
            <option value="">Select rating…</option>
            <option value="5">★★★★★ — Excellent</option>
            <option value="4">★★★★☆ — Good</option>
            <option value="3">★★★☆☆ — Average</option>
            <option value="2">★★☆☆☆ — Poor</option>
            <option value="1">★☆☆☆☆ — Terrible</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Comment</label>
          <textarea class="form-textarea" name="comment" rows="4" placeholder="Share your experience with this product…"></textarea>
        </div>
        <button type="submit" class="btn-submit">Submit Review</button>
      </form>
    </div>
  </div>
</div>

<!-- RELATED PRODUCTS -->
<?php if (!empty($related)): ?>
<section class="related-section">
  <div class="wrapper">
    <div style="font-size:18px;font-weight:700;color:var(--dark);margin-bottom:20px">Related Products</div>
    <div class="related-grid">
      <?php foreach ($related as $rp): ?>
      <div class="product-card" onclick="location.href='product.php?id=<?= $rp['ProductID'] ?>'">
        <div class="product-img-wrap">
          <img src="<?= thumb($rp['ProductImage'] ?? '') ?>" alt="<?= esc($rp['ProductName']) ?>">
        </div>
        <div class="product-body">
          <div class="product-cat"><?= esc($product['CategoryName']) ?></div>
          <div class="product-name"><?= esc($rp['ProductName']) ?></div>
          <div class="product-price"><?= fmt($rp['Price']) ?></div>
        </div>
        <div class="product-footer" onclick="event.stopPropagation()">
          <form method="POST" action="index.php">
            <input type="hidden" name="product_id" value="<?= $rp['ProductID'] ?>">
            <button type="submit" name="add_to_cart" value="1" class="btn-cart">Add</button>
          </form>
          <button class="btn-wish-sm">♡</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php endif; ?>


<!-- FOOTER -->
<footer>
  <div class="wrapper">
    <div class="footer-logo">Smart<span>home</span></div>
    <p>© <?= date('Y') ?> SmartHome. All rights reserved.</p>
  </div>
</footer>

<?php if ($toast): ?>
<div class="toast-wrap"><div class="toast">✓ <?= esc($toast) ?></div></div>
<script>setTimeout(()=>document.querySelector('.toast-wrap')?.remove(),2800)</script>
<?php endif; ?>

<script>
function adjQty(d){
  const i=document.getElementById('qtyInput');
  if(!i) return;
  i.value=Math.max(1,Math.min(parseInt(i.max)||99,parseInt(i.value||1)+d));
}
</script>
</body>
</html>
<?php $conn->close(); ?>