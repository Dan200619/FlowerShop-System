<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$loginError = '';
$loginSuccess = false;
$loginOtpPending = isset($_SESSION['pending_staff_signin']) && is_array($_SESSION['pending_staff_signin']);
if (!isset($_SESSION['password_reset_csrf']) || !is_string($_SESSION['password_reset_csrf'])) {
    $_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/Connection.php';
    require_once __DIR__ . '/ActivityLog.php';
    require_once __DIR__ . '/AuthPin.php';

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'login';
    $submittedCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hash_equals($_SESSION['password_reset_csrf'], $submittedCsrf)) {
        $loginError = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'cancel_login_pin') {
        unset($_SESSION['pending_staff_signin']);
        $loginOtpPending = false;
    } elseif ($action === 'verify_login_pin') {
        $pendingLogin = $_SESSION['pending_staff_signin'] ?? null;
        $submittedPin = isset($_POST['pin']) && is_string($_POST['pin']) ? trim($_POST['pin']) : '';
        if (!is_array($pendingLogin) || !isset($pendingLogin['user_id'], $pendingLogin['email'])) {
            $loginError = 'Your sign-in verification expired. Please sign in again.';
            unset($_SESSION['pending_staff_signin']);
            $loginOtpPending = false;
        } elseif (!preg_match('/^\d{6}$/', $submittedPin)) {
            $loginError = 'Enter the 6-digit verification PIN.';
        } elseif (verifyAuthenticationPin(
            $conn,
            'staff',
            'signin',
            (string) $pendingLogin['email'],
            $submittedPin,
            (int) $pendingLogin['user_id'],
            $loginError
        )) {
            $statement = mysqli_prepare(
                $conn,
                "SELECT UserID, Firstname, Lastname, Username, Role, `Access`
                 FROM tblusers WHERE UserID = ? AND Email = ? AND Role IN ('Admin', 'Staff') LIMIT 1"
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare the verified login query.');
            }
            $pendingUserId = (int) $pendingLogin['user_id'];
            $pendingEmail = (string) $pendingLogin['email'];
            mysqli_stmt_bind_param($statement, 'is', $pendingUserId, $pendingEmail);
            if (!mysqli_stmt_execute($statement)) {
                mysqli_stmt_close($statement);
                throw new RuntimeException('Unable to execute the verified login query.');
            }
            mysqli_stmt_bind_result($statement, $userId, $firstName, $lastName, $storedUsername, $role, $access);
            $userFound = mysqli_stmt_fetch($statement);
            mysqli_stmt_close($statement);
            if (!$userFound || $access !== 'Enabled') {
                unset($_SESSION['pending_staff_signin']);
                $loginOtpPending = false;
                $loginError = 'This account cannot sign in. Please contact an administrator.';
            } else {
                unset($_SESSION['pending_staff_signin']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $storedUsername;
                $_SESSION['full_name'] = trim($firstName . ' ' . $lastName);
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;
                $_SESSION['role'] = $role;
                $_SESSION['access'] = $access;
                recordActivity($conn, 'login', 'Signed in successfully.', 'user', (int) $userId);
                $loginSuccess = true;
                $loginOtpPending = false;
            }
        }
    } else {
        unset($_SESSION['pending_staff_signin']);
        $loginOtpPending = false;
        $username = isset($_POST['username']) && is_string($_POST['username'])
            ? trim($_POST['username'])
            : '';
        $submittedPassword = isset($_POST['password']) && is_string($_POST['password'])
            ? $_POST['password']
            : '';

        if ($username === '') {
            $loginError = 'Please enter your username.';
        } elseif ($submittedPassword === '') {
            $loginError = 'Please enter your password.';
        } else {
        $statement = mysqli_prepare(
            $conn,
            'SELECT UserID, Firstname, Lastname, Username, Email, Password, Role, `Access`
             FROM tblusers
             WHERE Username = ?
             LIMIT 1'
        );

        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the login query.');
        }

        mysqli_stmt_bind_param($statement, 's', $username);

        if (!mysqli_stmt_execute($statement)) {
            mysqli_stmt_close($statement);
            throw new RuntimeException('Unable to execute the login query.');
        }

        mysqli_stmt_bind_result(
            $statement,
            $userId,
            $firstName,
            $lastName,
            $storedUsername,
            $email,
            $storedPassword,
            $role,
            $access
        );

        $userFound = mysqli_stmt_fetch($statement);
        mysqli_stmt_close($statement);

        $storedPassword = (string) $storedPassword;
        $passwordInfo = password_get_info($storedPassword);
        $passwordIsHashed = $passwordInfo['algo'] !== null
            && $passwordInfo['algo'] !== false
            && $passwordInfo['algo'] !== 0;
        $passwordMatches = $userFound && (
            $passwordIsHashed
                ? password_verify($submittedPassword, $storedPassword)
                : hash_equals($storedPassword, $submittedPassword)
        );

        if ($passwordMatches) {
            if ($access !== 'Enabled') {
                $loginError = 'This account cannot sign in. Please contact an administrator.';
            } elseif (!in_array($role, ['Admin', 'Staff'], true) || !filter_var((string) $email, FILTER_VALIDATE_EMAIL)) {
                $loginError = 'A valid email address is required for sign-in verification. Contact an administrator.';
            } elseif (sendAuthenticationPin($conn, 'staff', 'signin', (string) $email, (int) $userId, $loginError)) {
                $_SESSION['pending_staff_signin'] = [
                    'user_id' => (int) $userId,
                    'email' => (string) $email,
                ];
                $loginOtpPending = true;
                $loginError = '';
            }
        } elseif (!$userFound) {
            $loginError = 'Username not found.';
        } else {
            $loginError = 'Incorrect password.';
        }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($loginSuccess): ?>
        <meta http-equiv="refresh" content="1.5;url=index.php">
    <?php endif; ?>
    <link rel="stylesheet" type="text/css" href="bootstrap-5.3.8/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="loginform.css">
    <link rel="stylesheet" type="text/css" href="password-reset.css">
    <script type="text/javascript" src="bootstrap-5.3.8/js/bootstrap.min.js"></script>
    <title>Login</title>
</head>
<body>
    <main class="container-fluid min-vh-100 d-flex align-items-center justify-content-center login-main">
        <div class="row justify-content-center w-100 g-0">
            <div class="col-12 d-flex justify-content-center p-0">
                <div class="row g-0 bg-white overflow-hidden login-card">
                    <section class="col-md-6 p-4 p-lg-5 position-relative login-form-panel">
                        <div class="mb-4">
                            <h1 class="h2 login-heading mb-2">Sign in to Continue</h1>
                            <p class="login-description mb-0">Access store operations, orders, inventory, and team management.</p>
                        </div>

                        <?php if ($loginOtpPending): ?>
                            <p class="small text-secondary">Enter the 6-digit PIN sent to <?= htmlspecialchars((string) $_SESSION['pending_staff_signin']['email'], ENT_QUOTES, 'UTF-8') ?>.</p>
                            <form action="LoginForm.php" method="post">
                                <input type="hidden" name="action" value="verify_login_pin">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['password_reset_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-3">
                                    <label for="login-pin" class="form-label">Email verification PIN</label>
                                    <input type="text" class="form-control" id="login-pin" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                                </div>
                                <button type="submit" class="btn w-100 login-submit">Verify and sign in</button>
                            </form>
                            <form action="LoginForm.php" method="post" class="text-center mt-3">
                                <input type="hidden" name="action" value="cancel_login_pin">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['password_reset_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-link btn-sm">Back to sign in</button>
                            </form>
                        <?php else: ?>
                        <form action="LoginForm.php" method="post">
                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['password_reset_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" autocomplete="username" placeholder="Enter your username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <div class="login-password-wrap">
                                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                                    <button class="login-password-toggle" type="button" id="toggle-password" aria-controls="password" aria-pressed="false">Show</button>
                                </div>
                            </div>
                            <p class="text-center mt-3 mb-0 small">
                                Need the customer store?
                                <a href="CustomerShop.php" class="fw-semibold">Browse the shop</a>
                            </p>
                            <button type="submit" class="btn w-100 login-submit">Sign in</button>

                            <div class="d-flex justify-content-center align-items-center mt-4">
                                <button type="button" class="login-forgot-link text-decoration-none small fw-semibold border-0 bg-transparent" id="open-password-reset">Forgot password?</button>
                            </div>
                        </form>
                        <?php endif; ?>
                        <?php if ($loginError !== ''): ?>
                            <div class="alert alert-danger mt-3 mb-0" role="alert">
                                <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($loginSuccess): ?>
                            <div class="position-absolute top-0 start-0 w-100 h-100 bg-white bg-opacity-75 d-flex align-items-center justify-content-center" role="status">
                                <div class="bg-white text-dark rounded-3 shadow-sm px-4 py-3 text-center">
                                    Login successful!
                                </div>
                            </div>
                            <script>
                                window.setTimeout(function () {
                                    window.location.href = 'index.php';
                                }, 1500);
                            </script>
                        <?php endif; ?>
                    </section>
                    <section class="col-md-6 text-white p-4 p-lg-5 d-flex flex-column justify-content-center position-relative overflow-hidden login-welcome-panel">
                        <div class="login-welcome-content">
                            <p class="login-welcome-kicker text-uppercase fw-semibold mb-3">Store operations</p>
                            <h2 class="display-5 login-welcome-title mb-4">Welcome back!</h2>
                            <p class="login-welcome-copy mb-0">Manage orders, inventory, and customer experiences with the same floral care your shop is known for.</p>
                        </div>
                        <p class="login-panel-note mb-0">Fresh arrangements. Smooth operations.</p>
                    </section>
                </div>
            </div>
        </div>
    </main>
    <dialog class="password-reset-dialog p-0 border-0 rounded-3 shadow" id="password-reset-dialog" aria-labelledby="password-reset-title">
        <form class="password-reset-form p-4" data-password-reset-form data-account-type="staff" data-csrf-token="<?= htmlspecialchars($_SESSION['password_reset_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <h2 class="h4 mb-1" id="password-reset-title">Reset your password</h2>
                    <p class="text-secondary small mb-0">Admin and staff accounts</p>
                </div>
                <button class="btn-close" type="button" data-password-reset-close aria-label="Close"></button>
            </div>
            <div data-reset-step="request">
                <p class="small text-secondary">Enter the email address registered to your account. If it matches, we will send a recovery PIN.</p>
                <div class="mb-3">
                    <label class="form-label" for="staff-reset-email">Email address</label>
                    <input class="form-control" id="staff-reset-email" name="email" type="email" maxlength="254" autocomplete="email" required>
                </div>
                <button class="btn w-100 login-submit" type="button" data-password-reset-send>Send PIN</button>
            </div>
            <div data-reset-step="verify" hidden>
                <div class="mb-3">
                    <label class="form-label" for="staff-reset-pin">6-digit recovery PIN</label>
                    <input class="form-control" id="staff-reset-pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="staff-reset-password">New password</label>
                    <input class="form-control" id="staff-reset-password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="staff-reset-confirm-password">Confirm new password</label>
                    <input class="form-control" id="staff-reset-confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-secondary flex-fill" type="button" data-password-reset-back>Back</button>
                    <button class="btn login-submit flex-fill mt-0" type="submit">Reset password</button>
                </div>
            </div>
            <p class="small mt-3 mb-0" data-password-reset-message role="status" aria-live="polite"></p>
        </form>
    </dialog>
    <script src="password-reset.js"></script>
    <script>
        const passwordInput = document.getElementById("password");
        const passwordToggle = document.getElementById("toggle-password");

        passwordToggle.addEventListener("click", function () {
            const shouldShowPassword = passwordInput.type === "password";
            passwordInput.type = shouldShowPassword ? "text" : "password";
            passwordToggle.textContent = shouldShowPassword ? "Hide" : "Show";
            passwordToggle.setAttribute("aria-pressed", String(shouldShowPassword));
        });
    </script>
</body>
</html>