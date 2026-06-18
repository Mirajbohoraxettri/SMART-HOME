<?php
// ============================================================
//  SMARTTECH  |  login.php  — Login & Register
// ============================================================
session_start();
require_once 'connection.php';

// Already logged in → redirect
if (isLoggedIn()) redirect('index.php');

$tab      = $_GET['tab']      ?? 'login';
$redirect = $_GET['redirect'] ?? 'index.php';
$errors   = [];
$success  = '';

// ── Handle LOGIN ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password']  ?? '';

    if (!$email || !$pass) {
        $errors[] = 'Please fill in all fields.';
    } else {
        $user = db_row($conn, "SELECT * FROM USERS WHERE Email=? LIMIT 1", 's', [$email]);
        if ($user && password_verify($pass, $user['Password'])) {
            $_SESSION['user_id']      = $user['UserID'];
            $_SESSION['user_name']    = $user['FullName'];
            $_SESSION['user_email']   = $user['Email'];
            $_SESSION['user_role']    = $user['Role'];
            $_SESSION['user_address'] = $user['Address'] ?? '';
            $_SESSION['toast']        = 'Welcome back, ' . $user['FullName'] . '!';
            // Admin → dashboard, else storefront
            redirect($user['Role'] === 'admin' ? 'dashboard.php' : $redirect);
        } else {
            $errors[] = 'Invalid email or password.';
        }
    }
}

// ── Handle REGISTER ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_register'])) {
    $name    = trim($_POST['full_name']  ?? '');
    $email   = trim($_POST['email']      ?? '');
    $phone   = trim($_POST['phone']      ?? '');
    $address = trim($_POST['address']    ?? '');
    $pass    = $_POST['password']        ?? '';
    $confirm = $_POST['confirm_password']?? '';
    $tab     = 'register';

    if (!$name || !$email || !$pass)     $errors[] = 'Name, email, and password are required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if (strlen($pass) < 6)               $errors[] = 'Password must be at least 6 characters.';
    if ($pass !== $confirm)              $errors[] = 'Passwords do not match.';
    if (empty($errors)) {
        $exists = db_value($conn, "SELECT UserID FROM USERS WHERE Email=?", 's', [$email]);
        if ($exists) {
            $errors[] = 'An account with this email already exists.';
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            db_run($conn,
                "INSERT INTO USERS (FullName,Email,Password,Phone,Address,Role) VALUES (?,?,?,?,?,'customer')",
                'sssss', [$name, $email, $hash, $phone, $address]
            );
            $userId = $conn->insert_id;
            $_SESSION['user_id']      = $userId;
            $_SESSION['user_name']    = $name;
            $_SESSION['user_email']   = $email;
            $_SESSION['user_role']    = 'customer';
            $_SESSION['user_address'] = $address;
            $_SESSION['toast']        = "Welcome, $name! Account created successfully.";
            redirect($redirect);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $tab === 'register' ? 'Register' : 'Login' ?> — SmartHome</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--white:#fff;--bg:#f5f5f5;--border:#e8e8e8;
      --accent:#e67e22;--accent2:#f39c12;--dark:#1a1a2e;
      --text:#2c2c2c;--muted:#888;--light:#f9f9f9;
      --ok:#27ae60;--err:#e74c3c;--radius:10px;--nav-h:60px}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);
     font-size:14px;min-height:100vh;display:flex;flex-direction:column}
a{color:inherit;text-decoration:none}
input::placeholder{color:var(--muted)}

/* NAVBAR */
.navbar{background:var(--white);border-bottom:1px solid var(--border);height:var(--nav-h);
        box-shadow:0 1px 8px rgba(0,0,0,.06)}
.nav-inner{max-width:1200px;margin:0 auto;height:100%;display:flex;align-items:center;gap:24px;padding:0 20px}
.logo{font-size:22px;font-weight:300;color:var(--dark)}.logo span{color:var(--accent);font-weight:700}
.nav-spacer{flex:1}

/* AUTH PAGE */
.auth-wrap{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}
.auth-card{background:var(--white);border:1px solid var(--border);border-radius:16px;
           width:100%;max-width:460px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
.auth-hero{background:linear-gradient(135deg,var(--dark),#0f3460);
           padding:32px;text-align:center;color:#fff}
.auth-hero .logo-big{font-size:28px;font-weight:300;margin-bottom:6px}
.auth-hero .logo-big span{color:var(--accent);font-weight:700}
.auth-hero p{color:#aab;font-size:13px}

/* TABS */
.tab-bar{display:flex;border-bottom:1px solid var(--border)}
.tab-btn{flex:1;padding:14px;background:none;border:none;cursor:pointer;font-size:14px;
         font-weight:600;color:var(--muted);border-bottom:3px solid transparent;
         transition:all .15s;text-align:center}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent)}
.tab-btn:hover:not(.active){color:var(--dark)}

/* FORM */
.auth-body{padding:28px}
.form-group{margin-bottom:16px}
.form-label{display:block;font-size:12px;font-weight:600;color:var(--muted);
            text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.form-input{width:100%;border:1px solid var(--border);border-radius:8px;
            padding:11px 14px;font-size:14px;outline:none;background:var(--bg);
            color:var(--text);transition:border-color .15s}
.form-input:focus{border-color:var(--accent);background:var(--white)}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.input-wrap{position:relative}
.input-wrap .ico{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted)}
.input-wrap .form-input{padding-left:36px}
.pw-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);
           background:none;border:none;cursor:pointer;color:var(--muted);font-size:14px}

/* ALERTS */
.alert{padding:10px 14px;border-radius:8px;margin-bottom:16px;font-size:13px}
.alert-err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.alert ul{list-style:none;display:flex;flex-direction:column;gap:4px}
.alert ul li::before{content:"• "}

/* BUTTONS */
.btn-submit{width:100%;background:var(--accent);color:var(--white);border:none;
            border-radius:8px;padding:12px;font-size:14px;font-weight:600;
            cursor:pointer;transition:background .15s;margin-top:4px}
.btn-submit:hover{background:var(--accent2)}
.divider{text-align:center;color:var(--muted);font-size:12px;margin:16px 0;
         position:relative}
.divider::before,.divider::after{content:'';position:absolute;top:50%;
  width:42%;height:1px;background:var(--border)}
.divider::before{left:0}.divider::after{right:0}
.btn-google{width:100%;background:var(--white);color:var(--text);border:1px solid var(--border);
            border-radius:8px;padding:11px;font-size:13px;font-weight:500;cursor:pointer;
            display:flex;align-items:center;justify-content:center;gap:10px;transition:background .15s}
.btn-google:hover{background:var(--bg)}
.form-footer{text-align:center;margin-top:16px;font-size:13px;color:var(--muted)}
.form-footer a{color:var(--accent);font-weight:600}
.forgot{text-align:right;margin-top:-10px;margin-bottom:14px;font-size:12px}
.forgot a{color:var(--accent)}

/* STRENGTH METER */
.strength-bar{height:4px;background:var(--border);border-radius:2px;margin-top:6px;overflow:hidden}
.strength-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;width:0}
.strength-text{font-size:11px;color:var(--muted);margin-top:3px}

/* FOOTER */
footer{background:var(--dark);color:#ccd;padding:20px;text-align:center}
.footer-logo{font-size:16px;font-weight:300;color:var(--white)}.footer-logo span{color:var(--accent);font-weight:700}
footer p{font-size:11px;color:#667;margin-top:4px}

@media(max-width:480px){.form-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="nav-inner">
    <a href="index.php" class="logo">Smart<span>home</span></a>
    <div class="nav-spacer"></div>
    <a href="index.php" style="font-size:13px;color:var(--muted)">← Back to store</a>
  </div>
</nav>

<!-- AUTH CARD -->
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-hero">
      <div class="logo-big">Smart<span>home</span></div>
      <p><?= $tab === 'register' ? 'Create your account and start shopping!' : 'Sign in to your account' ?></p>
    </div>

    <!-- TABS -->
    <div class="tab-bar">
      <a href="login.php?tab=login&redirect=<?= urlencode($redirect) ?>">
        <button class="tab-btn <?= $tab==='login'?'active':'' ?>">Sign In</button>
      </a>
      <a href="login.php?tab=register&redirect=<?= urlencode($redirect) ?>">
        <button class="tab-btn <?= $tab==='register'?'active':'' ?>">Create Account</button>
      </a>
    </div>

    <div class="auth-body">
      <!-- ERRORS -->
      <?php if (!empty($errors)): ?>
      <div class="alert alert-err"><ul><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <?php if ($tab === 'login'): ?>
      <!-- ── LOGIN FORM ── -->
      <form method="POST">
        <input type="hidden" name="do_login" value="1">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <div class="input-wrap">
            <span class="ico">✉</span>
            <input class="form-input" type="email" name="email"
                   value="<?= esc($_POST['email'] ?? '') ?>"
                   placeholder="your@email.com" required autofocus>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-wrap">
            <span class="ico">🔒</span>
            <input class="form-input" type="password" name="password" id="loginPw" placeholder="Enter password" required>
            <button type="button" class="pw-toggle" onclick="togglePw('loginPw',this)">👁</button>
          </div>
        </div>
        <div class="forgot"><a href="#">Forgot password?</a></div>
        <button type="submit" class="btn-submit">Sign In →</button>
      </form>

      <div class="divider">or continue with</div>
      <button class="btn-google" onclick="alert('Google login coming soon!')">
        <span style="font-size:18px">G</span> Sign in with Google
      </button>
      <div class="form-footer">
        Don't have an account?
        <a href="login.php?tab=register&redirect=<?= urlencode($redirect) ?>">Create one →</a>
      </div>

      <?php else: ?>
      <!-- ── REGISTER FORM ── -->
      <form method="POST">
        <input type="hidden" name="do_register" value="1">
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input class="form-input" type="text" name="full_name"
                   value="<?= esc($_POST['full_name'] ?? '') ?>" placeholder="John Doe" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">Phone</label>
            <input class="form-input" type="tel" name="phone"
                   value="<?= esc($_POST['phone'] ?? '') ?>" placeholder="+55 11 99999-0000">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email Address *</label>
          <div class="input-wrap">
            <span class="ico">✉</span>
            <input class="form-input" type="email" name="email"
                   value="<?= esc($_POST['email'] ?? '') ?>" placeholder="your@email.com" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Address</label>
          <input class="form-input" type="text" name="address"
                 value="<?= esc($_POST['address'] ?? '') ?>" placeholder="Street, City, State">
        </div>
        <div class="form-grid">
          <div class="form-group">
            <label class="form-label">Password *</label>
            <div class="input-wrap">
              <span class="ico">🔒</span>
              <input class="form-input" type="password" name="password" id="regPw"
                     placeholder="Min. 6 chars" required oninput="checkStrength(this.value)">
              <button type="button" class="pw-toggle" onclick="togglePw('regPw',this)">👁</button>
            </div>
            <div class="strength-bar"><div class="strength-fill" id="strengthBar"></div></div>
            <div class="strength-text" id="strengthText"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password *</label>
            <div class="input-wrap">
              <span class="ico">🔒</span>
              <input class="form-input" type="password" name="confirm_password"
                     id="regPw2" placeholder="Repeat password" required>
              <button type="button" class="pw-toggle" onclick="togglePw('regPw2',this)">👁</button>
            </div>
          </div>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:16px">
          By registering you agree to our <a href="#" style="color:var(--accent)">Terms of Service</a>
          and <a href="#" style="color:var(--accent)">Privacy Policy</a>.
        </div>
        <button type="submit" class="btn-submit">Create Account →</button>
      </form>
      <div class="form-footer">
        Already have an account?
        <a href="login.php?tab=login&redirect=<?= urlencode($redirect) ?>">Sign in →</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<footer>
  <div class="footer-logo">Smart<span>home</span></div>
  <p>© <?= date('Y') ?> SmartHome. All rights reserved.</p>
</footer>

<script>
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  inp.type  = inp.type === 'password' ? 'text' : 'password';
  btn.textContent = inp.type === 'password' ? '👁' : '🙈';
}

function checkStrength(pw) {
  const bar  = document.getElementById('strengthBar');
  const text = document.getElementById('strengthText');
  if (!bar) return;
  let score = 0;
  if (pw.length >= 6)  score++;
  if (pw.length >= 10) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const levels = [
    {w:'0%',  c:'#ccc',          t:''},
    {w:'20%', c:'var(--err)',    t:'Very Weak'},
    {w:'40%', c:'#f39c12',      t:'Weak'},
    {w:'60%', c:'#f39c12',      t:'Fair'},
    {w:'80%', c:'var(--ok)',    t:'Strong'},
    {w:'100%',c:'var(--ok)',    t:'Very Strong'},
  ];
  const l = levels[Math.min(score, 5)];
  bar.style.width = l.w; bar.style.background = l.c;
  text.textContent = l.t; text.style.color = l.c;
}
</script>
</body>
</html>
<?php $conn->close(); ?>