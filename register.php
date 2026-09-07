<?php
require __DIR__ . '/includes/bootstrap.php';

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf'], $_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        $errors[] = 'Your session expired, please try again.';
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // bnetaccount create is space-delimited on the console, so the email
    // and password can't contain whitespace or characters that could be
    // interpreted as a second command. bnet_accounts.email is varchar(320)
    // but we cap it well under that; an @ is required since that's the
    // column TrinityCore actually stores this in.
    if (!preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+$/', $email) || strlen($email) > 64) {
        $errors[] = 'Enter a valid-looking email (must contain @), up to 64 characters.';
    }
    if (!preg_match('/^[A-Za-z0-9!#$%^&*_-]{8,32}$/', $password)) {
        $errors[] = 'Password must be 8-32 characters (letters, numbers, ! # $ % ^ & * _ - ), no spaces.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $response = sendSoapCommand("bnetaccount create {$email} {$password}", $config);

            if (stripos($response, 'created') !== false) {
                $success = "Account \"{$email}\" was created. You can log in with it now.";
            } elseif (stripos($response, 'already exist') !== false) {
                $errors[] = 'That email is already registered.';
            } elseif ($response === '') {
                $success = "Account \"{$email}\" was created.";
            } else {
                $errors[] = "Server says: " . htmlspecialchars($response);
            }
        } catch (SoapFault $e) {
            $errors[] = 'Could not reach the server. Is the worldserver running with SOAP enabled? (' . htmlspecialchars($e->getMessage()) . ')';
        }
    }
}

$_SESSION['csrf'] = bin2hex(random_bytes(16));

$activePage = 'register';
$pageTitle = 'Register';
require __DIR__ . '/includes/header.php';
?>

<div class="panel form-card">
  <h2>Create an Account</h2>

  <?php if (!empty($errors)): ?>
    <div class="msg error">
      <ul>
        <?php foreach ($errors as $e): ?>
          <li><?= $e ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="msg success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf'] ?>">

    <label for="email">Email</label>
    <input type="text" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="you@example.com" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <label for="confirm">Confirm password</label>
    <input type="password" id="confirm" name="confirm" required>

    <button type="submit">Create Account</button>
  </form>

  <p class="hint">No spaces in email or password.</p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
