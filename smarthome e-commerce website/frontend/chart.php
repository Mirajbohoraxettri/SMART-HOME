<?php
// ============================================================
//  SMARTTECH  |  cart.php  — Shopping Cart
// ============================================================
session_start();
require_once 'connection.php';

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];

// ── Cart actions ────────────────────────────────────────────────
// Remove item
if (isset($_GET['remove'])) {
    unset($_SESSION['cart'][(int)$_GET['remove']]);
    redirect('cart.php');
}
// Clear all
if (isset($_GET['clear'])) {
    $_SESSION['cart'] = [];
    redirect('cart.php');
}
// Update quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    foreach ($_POST['qty'] as $pid => $qty) {
        $pid = (int)$pid; $qty = (int)$qty;
        if ($qty > 0) $_SESSION['cart'][$pid] = $qty;
        else unset($_SESSION['cart'][$pid]);
    }
    $_SESSION['toast'] = 'Cart updated!';
    redirect('cart.php');
}
// Proceed to checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    if (!isLoggedIn()) {
        redirect('login.php?redirect=cart.php');
    }
    // Save order to DB
    if (!empty($_SESSION['cart'])) {
        $ids  = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
        $rows = db_rows($conn, "SELECT ProductID,Price,StockQuantity FROM PRODUCTS WHERE ProductID IN ($ids)");
        $total = 0; $orderItems = [];
        foreach ($rows as $row) {
            $qty   = $_SESSION['cart'][$row['ProductID']] ?? 1;
            $qty   = min($qty, $row['StockQuantity']); // cap at stock
            if ($qty > 0) {
                $total += $row['Price'] * $qty;
                $orderItems[] = ['pid' => $row['ProductID'], 'qty' => $qty, 'price' => $row['Price']];
            }
        }
        if ($total > 0) {
            $userId  = $_SESSION['user_id'];
            $address = $_SESSION['user_address'] ?? 'To be confirmed';
            $orderId = db_run($conn,
                "INSERT INTO ORDERS (UserID,TotalAmount,OrderStatus,ShippingAddress) VALUES (?,?,'pending',?)",
                'ids', [$userId, $total, $address]
            );
            foreach ($orderItems as $item) {
                db_run($conn,
                    "INSERT INTO ORDER_ITEMS (OrderID,ProductID,Quantity,Price) VALUES (?,?,?,?)",
                    'iiid', [$orderId, $item['pid'], $item['qty'], $item['price']]
                );
                db_run($conn,
                    "UPDATE PRODUCTS SET StockQuantity=StockQuantity-? WHERE ProductID=?",
                    'ii', [$item['qty'], $item['pid']]
                );
            }
            db_run($conn,
                "INSERT INTO PAYMENTS (OrderID,PaymentMethod,PaymentStatus) VALUES (?,'cash_on_delivery','pending')",
                'i', [$orderId]
            );
            $_SESSION['cart'] = [];
            $_SESSION['toast'] = "Order #$orderId placed successfully!";
            redirect('index.php');
        }
    }
}

// ── Load cart items ─────────────────────────────────────────────
$cartItems = []; $cartSubtotal = 0;
if (!empty($_SESSION['cart'])) {
    $ids  = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $rows = db_rows($conn, "
        SELECT p.ProductID, p.ProductName, p.Price, p.ProductImage, p.StockQuantity, c.CategoryName
        FROM PRODUCTS p
        JOIN CATEGORIES c ON p.CategoryID=c.CategoryID
        WHERE p.ProductID IN ($ids)
    ");
    foreach ($rows as $row) {
        $qty              = $_SESSION['cart'][$row['ProductID']] ?? 1;
        $row['qty']       = min($qty, $row['StockQuantity']); // prevent over-ordering
        $row['subtotal']  = $row['Price'] * $row['qty'];
        $cartSubtotal    += $row['subtotal'];
        $cartItems[]      = $row;
    }
}

$shipping   = $cartSubtotal >= 500 ? 0 : 49.90;
$discount   = 0;
$total      = $cartSubtotal + $shipping - $discount;
$cartCount  = array_sum($_SESSION['cart']);
$toast      = $_SESSION['toast'] ?? '';
unset($_SESSION['toast']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cart — SmartHome</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--white:#fff;--bg:#f5f5f5;--border:#e8e8e8;--shadow:0 2px 12px rgba(0,0,0,.08);
      --accent:#e67e22;--accent2:#f39c12;--dark:#1a1a2e;
      --text:#2c2c2c;--muted:#888;--light:#f9f9f9;
      --ok:#27ae60;--err:#e74c3c;--radius:10px;--nav-h:60px}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);font-size:14px}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
input::placeholder{color:var(--muted)}

/* NAVBAR */
.navbar{background:var(--white);border-bottom:1px solid var(--border);height:var(--nav-h);
        position:sticky;top:0;z-index:200;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.nav-inner{max-width:1200px;margin:0 auto;height:100%;display:flex;align-items:center;gap:24px;padding:0 20px}
.logo{font-size:22px;font-weight:300;color:var(--dark)}.logo span{color:var(--accent);font-weight:700}
.nav-links{display:flex;gap:22px}.nav-links a{font-size:13px;color:var(--muted);transition:color .15s}
.nav-links a:hover{color:var(--accent)}.nav-spacer{flex:1}
.nav-icons{display:flex;gap:14px;align-items:center}
.nav-icons a{position:relative;color:var(--muted);font-size:18px;transition:color .15s}
.nav-icons a:hover{color:var(--accent)}
.cart-badge{position:absolute;top:-6px;right:-7px;background:var(--accent);color:var(--white);
            font-size:9px;font-weight:700;border-radius:50%;width:16px;height:16px;
            display:flex;align-items:center;justify-content:center}

.wrapper{max-width:1200px;margin:0 auto;padding:0 20px}
.page-title{font-size:24px;font-weight:700;color:var(--dark);padding:24px 0 20px}

/* CART LAYOUT */
.cart-layout{display:grid;grid-template-columns:1fr 360px;gap:24px;padding-bottom:48px}

/* CART TABLE */
.cart-table-wrap{background:var(--white);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.cart-table-head{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 40px;
                 padding:12px 20px;background:var(--light);border-bottom:1px solid var(--border);
                 font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;gap:12px}
.cart-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr 40px;
          padding:16px 20px;border-bottom:1px solid var(--border);align-items:center;gap:12px}
.cart-row:last-child{border-bottom:none}
.ci-product{display:flex;align-items:center;gap:14px}
.ci-img{width:68px;height:68px;background:var(--light);border-radius:8px;overflow:hidden;
        display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ci-img img{max-height:60px;object-fit:contain}
.ci-name{font-size:13px;font-weight:600;color:var(--dark);margin-bottom:3px;
         display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ci-cat{font-size:11px;color:var(--muted)}
.ci-price{font-size:14px;font-weight:600;color:var(--dark)}
.qty-wrap{display:flex;align-items:center;border:1px solid var(--border);border-radius:6px;overflow:hidden;width:fit-content}
.qty-wrap button{background:var(--bg);border:none;padding:6px 10px;cursor:pointer;font-size:14px;transition:background .15s}
.qty-wrap button:hover{background:var(--border)}
.qty-wrap input{width:36px;text-align:center;border:none;border-left:1px solid var(--border);
                border-right:1px solid var(--border);padding:6px 0;outline:none;font-size:13px}
.ci-subtotal{font-size:14px;font-weight:700;color:var(--accent)}
.ci-remove{background:none;border:none;color:var(--err);cursor:pointer;font-size:16px;padding:4px;transition:opacity .15s}
.ci-remove:hover{opacity:.7}

/* CART ACTIONS */
.cart-actions{display:flex;align-items:center;justify-content:space-between;
              padding:14px 20px;background:var(--light);border-top:1px solid var(--border)}
.btn-update{background:var(--dark);color:var(--white);border:none;border-radius:7px;
            padding:8px 20px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s}
.btn-update:hover{background:#0f3460}
.btn-clear{background:none;border:1px solid var(--border);border-radius:7px;
           padding:8px 18px;font-size:13px;color:var(--muted);cursor:pointer}
.continue-link{font-size:13px;color:var(--accent);font-weight:600}
.continue-link:hover{text-decoration:underline}

/* EMPTY CART */
.empty-cart{text-align:center;padding:60px 24px;color:var(--muted)}
.empty-cart .eico{font-size:60px;margin-bottom:16px}
.empty-cart p{font-size:16px;margin-bottom:20px}
.btn-shop{background:var(--accent);color:var(--white);border:none;border-radius:8px;
          padding:11px 28px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}
.btn-shop:hover{background:var(--accent2)}

/* ORDER SUMMARY */
.summary-box{background:var(--white);border:1px solid var(--border);border-radius:12px;
             padding:24px;position:sticky;top:80px}
.summary-title{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:20px}
.summary-row{display:flex;justify-content:space-between;align-items:center;
             margin-bottom:12px;font-size:13px}
.summary-row span:first-child{color:var(--muted)}
.summary-row span:last-child{font-weight:600;color:var(--dark)}
.summary-divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.summary-total{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.summary-total span:first-child{font-size:15px;font-weight:700;color:var(--dark)}
.summary-total span:last-child{font-size:22px;font-weight:700;color:var(--accent)}
.free-shipping{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;
               border-radius:6px;padding:8px 12px;font-size:12px;text-align:center;margin-bottom:16px}
.promo-row{display:flex;gap:8px;margin-bottom:16px}
.promo-input{flex:1;border:1px solid var(--border);border-radius:7px;padding:9px 12px;font-size:13px;outline:none}
.promo-input:focus{border-color:var(--accent)}
.btn-promo{background:var(--bg);border:1px solid var(--border);border-radius:7px;
           padding:9px 14px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .15s}
.btn-promo:hover{background:var(--border)}
.btn-checkout{width:100%;background:var(--accent);color:var(--white);border:none;
              border-radius:8px;padding:13px;font-size:14px;font-weight:600;
              cursor:pointer;transition:background .15s;margin-bottom:10px}
.btn-checkout:hover{background:var(--accent2)}
.btn-checkout:disabled{background:#ccc;cursor:not-allowed}
.secure-note{text-align:center;font-size:11px;color:var(--muted);margin-top:8px}
.payment-icons{display:flex;justify-content:center;gap:8px;margin-top:12px;font-size:20px}

/* TOAST */
.toast-wrap{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:500;pointer-events:none}
.toast{background:var(--dark);color:var(--white);padding:10px 24px;border-radius:24px;
       font-size:13px;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.2);
       border-left:3px solid var(--accent);animation:su .3s ease}
@keyframes su{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

footer{background:var(--dark);color:#ccd;padding:28px 0;text-align:center}
.footer-logo{font-size:18px;font-weight:300;color:var(--white)}.footer-logo span{color:var(--accent);font-weight:700}
footer p{font-size:11px;color:#667;margin-top:6px}

@media(max-width:900px){
  .cart-layout{grid-template-columns:1fr}
  .summary-box{position:static}
  .cart-table-head,.cart-row{grid-template-columns:2fr 80px 1fr 60px 36px}
}
@media(max-width:600px){
  .nav-links{display:none}
  .cart-table-head{display:none}
  .cart-row{grid-template-columns:1fr;gap:8px}
  .ci-price::before{content:"Price: "}
  .ci-subtotal::before{content:"Subtotal: "}
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
      <a href="login.php" style="color:var(--accent)">Login</a>
    </div>
    <div class="nav-spacer"></div>
    <div class="nav-icons">
      <a href="index.php">🏠</a>
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
  <div class="page-title">🛒 Shopping Cart
    <?php if (!empty($cartItems)): ?>
    <span style="font-size:14px;color:var(--muted);font-weight:400">(<?= $cartCount ?> items)</span>
    <?php endif; ?>
  </div>

  <?php if (empty($cartItems)): ?>
  <!-- EMPTY STATE -->
  <div class="cart-table-wrap">
    <div class="empty-cart">
      <div class="eico">🛒</div>
      <p>Your cart is empty!</p>
      <a href="index.php"><button class="btn-shop">Continue Shopping</button></a>
    </div>
  </div>

  <?php else: ?>
  <div class="cart-layout">

    <!-- LEFT: Cart items -->
    <div>
      <form method="POST">
        <input type="hidden" name="update_cart" value="1">
        <div class="cart-table-wrap">
          <div class="cart-table-head">
            <span>Product</span><span>Price</span><span>Quantity</span><span>Subtotal</span><span></span>
          </div>

          <?php foreach ($cartItems as $ci): ?>
          <div class="cart-row">
            <!-- Product -->
            <div class="ci-product">
              <div class="ci-img">
                <img src="<?= thumb($ci['ProductImage'] ?? '') ?>" alt="<?= esc($ci['ProductName']) ?>">
              </div>
              <div>
                <a href="product.php?id=<?= $ci['ProductID'] ?>">
                  <div class="ci-name"><?= esc($ci['ProductName']) ?></div>
                </a>
                <div class="ci-cat"><?= esc($ci['CategoryName']) ?></div>
                <?php if ($ci['StockQuantity'] < 5 && $ci['StockQuantity'] > 0): ?>
                <div style="font-size:11px;color:var(--err);margin-top:2px">Only <?= $ci['StockQuantity'] ?> left!</div>
                <?php endif; ?>
              </div>
            </div>
            <!-- Price -->
            <div class="ci-price"><?= fmt($ci['Price']) ?></div>
            <!-- Quantity -->
            <div>
              <div class="qty-wrap">
                <button type="button" onclick="adjQty(this,-1)">−</button>
                <input type="number" name="qty[<?= $ci['ProductID'] ?>]"
                       value="<?= $ci['qty'] ?>" min="0" max="<?= $ci['StockQuantity'] ?>"
                       onchange="updateSubtotal(this,<?= $ci['Price'] ?>)" style="width:36px">
                <button type="button" onclick="adjQty(this,1)">+</button>
              </div>
            </div>
            <!-- Subtotal -->
            <div class="ci-subtotal" id="sub_<?= $ci['ProductID'] ?>"><?= fmt($ci['subtotal']) ?></div>
            <!-- Remove -->
            <div>
              <a href="cart.php?remove=<?= $ci['ProductID'] ?>">
                <button type="button" class="ci-remove" title="Remove">✕</button>
              </a>
            </div>
          </div>
          <?php endforeach; ?>

          <div class="cart-actions">
            <a href="index.php" class="continue-link">← Continue Shopping</a>
            <div style="display:flex;gap:10px">
              <a href="cart.php?clear=1"><button type="button" class="btn-clear">Clear Cart</button></a>
              <button type="submit" class="btn-update">Update Cart</button>
            </div>
          </div>
        </div>
      </form>
    </div>

    <!-- RIGHT: Order Summary -->
    <div>
      <div class="summary-box">
        <div class="summary-title">Order Summary</div>

        <div class="summary-row">
          <span>Subtotal (<?= $cartCount ?> items)</span>
          <span><?= fmt($cartSubtotal) ?></span>
        </div>
        <div class="summary-row">
          <span>Shipping</span>
          <span><?= $shipping > 0 ? fmt($shipping) : '<span style="color:var(--ok)">FREE</span>' ?></span>
        </div>
        <?php if ($discount > 0): ?>
        <div class="summary-row">
          <span>Discount</span>
          <span style="color:var(--ok)">−<?= fmt($discount) ?></span>
        </div>
        <?php endif; ?>

        <hr class="summary-divider">
        <div class="summary-total">
          <span>Total</span>
          <span><?= fmt($total) ?></span>
        </div>

        <?php if ($cartSubtotal < 500): ?>
        <div class="free-shipping">
          🚚 Add <?= fmt(500 - $cartSubtotal) ?> more for FREE shipping!
        </div>
        <?php else: ?>
        <div class="free-shipping">✓ You qualify for FREE shipping!</div>
        <?php endif; ?>

        <!-- Promo code -->
        <div class="promo-row">
          <input type="text" class="promo-input" placeholder="Promo code (e.g. SMART10)">
          <button type="button" class="btn-promo" onclick="alert('Promo codes coming soon!')">Apply</button>
        </div>

        <!-- Checkout -->
        <form method="POST">
          <button type="submit" name="checkout" value="1" class="btn-checkout">
            🔒 Proceed to Checkout
          </button>
        </form>

        <div class="secure-note">🔒 Secure checkout — SSL encrypted</div>
        <div class="payment-icons">💳 🏦 💵</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

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
function adjQty(btn, d) {
  const wrap = btn.closest('.qty-wrap');
  const inp  = wrap.querySelector('input');
  const max  = parseInt(inp.max) || 99;
  const newV = Math.max(0, Math.min(max, parseInt(inp.value || 1) + d));
  inp.value  = newV;
  const price = parseFloat(inp.getAttribute('data-price') || 0);
  updateSubtotal(inp, price);
}

function updateSubtotal(inp, price) {
  const qty  = parseInt(inp.value) || 0;
  const name = inp.name; // qty[ID]
  const id   = name.match(/\d+/)?.[0];
  const el   = document.getElementById('sub_' + id);
  if (el && price > 0) {
    el.textContent = 'R$ ' + (qty * price).toLocaleString('pt-BR', {minimumFractionDigits:2});
  }
}
// Attach prices
document.querySelectorAll('.qty-wrap input').forEach(inp => {
  const row   = inp.closest('.cart-row');
  const price = row?.querySelector('.ci-price')?.textContent?.replace(/[^\d,]/g,'').replace(',','.') || '0';
  inp.setAttribute('data-price', parseFloat(price) || 0);
});
</script>
</body>
</html>
<?php $conn->close(); ?>