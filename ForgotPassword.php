<?php
session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function passwordResetRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(static function (Throwable $exception): void {
    error_log('Unhandled password recovery failure: ' . $exception->getMessage());
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable. Please try again later.']);
});

function passwordResetString(array $data, string $key): string
{
    return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    passwordResetRespond(405, ['error' => 'Use a password recovery form to continue.']);
}

$requestData = json_decode(file_get_contents('php://input'), true);
if (!is_array($requestData)) {
    passwordResetRespond(400, ['error' => 'The recovery request was not valid.']);
}

$accountType = passwordResetString($requestData, 'account_type');
if (!in_array($accountType, ['staff', 'customer'], true)) {
    passwordResetRespond(400, ['error' => 'The recovery request was not valid.']);
}

$csrfSessionKey = $accountType === 'customer' ? 'customer_csrf_token' : 'password_reset_csrf';
$submittedCsrf = passwordResetString($requestData, 'csrf_token');
$sessionCsrf = $_SESSION[$csrfSessionKey] ?? '';
if (!is_string($sessionCsrf) || $sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    passwordResetRespond(403, ['error' => 'Your session has expired. Refresh the page and try again.']);
}

require_once __DIR__ . '/Connection.php';

$action = passwordResetString($requestData, 'action');
$email = strtolower(passwordResetString($requestData, 'email'));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    passwordResetRespond(400, ['error' => 'Enter a valid email address.']);
}

$emailHash = hash('sha256', $email);
$requestIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if ($action === 'send_pin') {
    $sender = getenv('PASSWORD_RESET_FROM_EMAIL');
    if (!is_string($sender) || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        $sender = (string) ini_get('sendmail_from');
    }
    if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        error_log('Password recovery email is not configured; set PASSWORD_RESET_FROM_EMAIL and configure PHP mail.');
        passwordResetRespond(503, ['error' => 'Password recovery email is not configured. Please contact support.']);
    }

    if (mysqli_query($conn, 'DELETE FROM tblpasswordresets WHERE RequestedAt < CURRENT_TIMESTAMP - INTERVAL 1 DAY') === false) {
        error_log('Unable to remove expired password recovery records: ' . mysqli_error($conn));
    }

    $rateStatement = mysqli_prepare(
        $conn,
        'SELECT
            (SELECT COUNT(*) FROM tblpasswordresets
             WHERE EmailHash = ? AND RequestedAt >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR),
            (SELECT COUNT(*) FROM tblpasswordresets
             WHERE RequestIP = ? AND RequestedAt >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR)'
    );
    if ($rateStatement === false) {
        error_log('Unable to prepare password reset rate limit: ' . mysqli_error($conn));
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_bind_param($rateStatement, 'ss', $emailHash, $requestIp);
    if (!mysqli_stmt_execute($rateStatement)) {
        error_log('Unable to check password reset rate limit: ' . mysqli_stmt_error($rateStatement));
        mysqli_stmt_close($rateStatement);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_bind_result($rateStatement, $recentEmailRequests, $recentIpRequests);
    $rateFetch = mysqli_stmt_fetch($rateStatement);
    if ($rateFetch !== true) {
        error_log('Unable to fetch password reset rate limit: ' . mysqli_stmt_error($rateStatement));
        mysqli_stmt_close($rateStatement);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_close($rateStatement);
    if ((int) $recentEmailRequests >= 5 || (int) $recentIpRequests >= 30) {
        passwordResetRespond(429, ['error' => 'Too many recovery requests. Please try again later.']);
    }

    $invalidatePrevious = mysqli_prepare(
        $conn,
        'UPDATE tblpasswordresets SET PinHash = NULL, ExpiresAt = NULL
         WHERE AccountType = ? AND EmailHash = ? AND PinHash IS NOT NULL'
    );
    if ($invalidatePrevious === false) {
        error_log('Unable to prepare previous password PIN invalidation: ' . mysqli_error($conn));
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_bind_param($invalidatePrevious, 'ss', $accountType, $emailHash);
    if (!mysqli_stmt_execute($invalidatePrevious)) {
        error_log('Unable to invalidate previous password PINs: ' . mysqli_stmt_error($invalidatePrevious));
        mysqli_stmt_close($invalidatePrevious);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_close($invalidatePrevious);

    $accountId = null;
    if ($accountType === 'customer') {
        $lookup = mysqli_prepare($conn, 'SELECT CustomerID FROM tblcustomers WHERE Email = ? LIMIT 1');
        if ($lookup === false) {
            error_log('Unable to prepare customer password recovery lookup: ' . mysqli_error($conn));
            passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
        }
        mysqli_stmt_bind_param($lookup, 's', $email);
        if (!mysqli_stmt_execute($lookup)) {
            error_log('Unable to run customer password recovery lookup: ' . mysqli_stmt_error($lookup));
            mysqli_stmt_close($lookup);
            passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
        }
        mysqli_stmt_bind_result($lookup, $foundAccountId);
        if (mysqli_stmt_fetch($lookup)) {
            $accountId = (int) $foundAccountId;
        }
        mysqli_stmt_close($lookup);
    } else {
        $lookup = mysqli_prepare(
            $conn,
            "SELECT UserID FROM tblusers WHERE Email = ? AND Role IN ('Admin', 'Staff') LIMIT 2"
        );
        if ($lookup === false) {
            error_log('Unable to prepare staff password recovery lookup: ' . mysqli_error($conn));
            passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
        }
        mysqli_stmt_bind_param($lookup, 's', $email);
        if (!mysqli_stmt_execute($lookup)) {
            error_log('Unable to run staff password recovery lookup: ' . mysqli_stmt_error($lookup));
            mysqli_stmt_close($lookup);
            passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
        }
        mysqli_stmt_bind_result($lookup, $foundAccountId);
        $matchingAccounts = [];
        while (mysqli_stmt_fetch($lookup)) {
            $matchingAccounts[] = (int) $foundAccountId;
        }
        mysqli_stmt_close($lookup);
        if (count($matchingAccounts) === 1) {
            $accountId = $matchingAccounts[0];
        }
    }

    $pinHash = null;
    $pin = '';
    if ($accountId !== null) {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
    }

    $insert = mysqli_prepare(
        $conn,
        'INSERT INTO tblpasswordresets (AccountType, AccountID, EmailHash, PinHash, ExpiresAt, RequestIP)
         VALUES (?, ?, ?, ?, IF(? = 1, CURRENT_TIMESTAMP + INTERVAL 15 MINUTE, NULL), ?)'
    );
    if ($insert === false) {
        error_log('Unable to prepare password reset request: ' . mysqli_error($conn));
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    $hasPin = $accountId === null ? 0 : 1;
    mysqli_stmt_bind_param($insert, 'sissis', $accountType, $accountId, $emailHash, $pinHash, $hasPin, $requestIp);
    if (!mysqli_stmt_execute($insert)) {
        error_log('Unable to store password reset request: ' . mysqli_stmt_error($insert));
        mysqli_stmt_close($insert);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    $resetRequestId = mysqli_insert_id($conn);
    mysqli_stmt_close($insert);

    if ($accountId !== null) {
        $subject = 'Your password recovery PIN';
        $message = "Your password recovery PIN is {$pin}.\n\n"
            . "It expires in 15 minutes. If you did not request a password reset, you can ignore this email.";
        $headers = "From: {$sender}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        $emailSent = @mail($email, $subject, $message, $headers);
        if (!$emailSent) {
            error_log('Unable to send a password recovery email.');
            $invalidate = mysqli_prepare(
                $conn,
                'UPDATE tblpasswordresets SET PinHash = NULL, ExpiresAt = NULL WHERE ResetID = ?'
            );
            if ($invalidate === false) {
                error_log('Unable to prepare failed-delivery PIN invalidation: ' . mysqli_error($conn));
            } else {
                mysqli_stmt_bind_param($invalidate, 'i', $resetRequestId);
                if (!mysqli_stmt_execute($invalidate)) {
                    error_log('Unable to invalidate undelivered password PIN: ' . mysqli_stmt_error($invalidate));
                }
                mysqli_stmt_close($invalidate);
            }
            passwordResetRespond(503, ['error' => 'The recovery PIN could not be emailed. Check the mail settings and try again.']);
        }
    }

    if ($accountId !== null && (!isset($emailSent) || !$emailSent)) {
        $invalidate = mysqli_prepare(
            $conn,
            'UPDATE tblpasswordresets SET PinHash = NULL, ExpiresAt = NULL WHERE ResetID = ?'
        );
        if ($invalidate === false) {
            error_log('Unable to prepare unsent password PIN invalidation: ' . mysqli_error($conn));
        } else {
            mysqli_stmt_bind_param($invalidate, 'i', $resetRequestId);
            if (!mysqli_stmt_execute($invalidate)) {
                error_log('Unable to invalidate an unsent password PIN: ' . mysqli_stmt_error($invalidate));
            }
            mysqli_stmt_close($invalidate);
        }
    }

    passwordResetRespond(200, [
        'message' => 'If an account matches that email, a recovery PIN will be sent shortly.',
    ]);
}

if ($action !== 'reset_password') {
    passwordResetRespond(400, ['error' => 'The recovery request was not valid.']);
}

$pin = passwordResetString($requestData, 'pin');
$password = isset($requestData['new_password']) && is_string($requestData['new_password'])
    ? $requestData['new_password']
    : '';
$confirmPassword = isset($requestData['confirm_password']) && is_string($requestData['confirm_password'])
    ? $requestData['confirm_password']
    : '';
if (!preg_match('/^\d{6}$/', $pin)) {
    passwordResetRespond(400, ['error' => 'Enter the 6-digit recovery PIN.']);
}
if (strlen($password) < 8 || strlen($password) > 4096) {
    passwordResetRespond(400, ['error' => 'Use a password with at least 8 characters.']);
}
if (!hash_equals($password, $confirmPassword)) {
    passwordResetRespond(400, ['error' => 'Passwords do not match.']);
}

if (!mysqli_begin_transaction($conn)) {
    error_log('Unable to start password reset transaction: ' . mysqli_error($conn));
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}

$lookup = mysqli_prepare(
    $conn,
    'SELECT ResetID, AccountID, PinHash, Attempts
     FROM tblpasswordresets
     WHERE AccountType = ? AND EmailHash = ? AND PinHash IS NOT NULL
       AND ExpiresAt > CURRENT_TIMESTAMP AND Attempts < 5
     ORDER BY ResetID DESC
     LIMIT 1 FOR UPDATE'
);
if ($lookup === false) {
    mysqli_rollback($conn);
    error_log('Unable to prepare password PIN verification: ' . mysqli_error($conn));
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}
mysqli_stmt_bind_param($lookup, 'ss', $accountType, $emailHash);
if (!mysqli_stmt_execute($lookup)) {
    mysqli_stmt_close($lookup);
    mysqli_rollback($conn);
    error_log('Unable to run password PIN verification: ' . mysqli_error($conn));
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}
mysqli_stmt_bind_result($lookup, $resetId, $accountId, $storedPinHash, $attempts);
$resetFound = mysqli_stmt_fetch($lookup);
mysqli_stmt_close($lookup);
if (!$resetFound) {
    mysqli_rollback($conn);
    passwordResetRespond(400, ['error' => 'Invalid or expired PIN. Request a new PIN and try again.']);
}

if (!password_verify($pin, (string) $storedPinHash)) {
    $attemptStatement = mysqli_prepare(
        $conn,
        'UPDATE tblpasswordresets SET Attempts = Attempts + 1 WHERE ResetID = ?'
    );
    if ($attemptStatement === false) {
        mysqli_rollback($conn);
        error_log('Unable to prepare password PIN attempt update: ' . mysqli_error($conn));
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_bind_param($attemptStatement, 'i', $resetId);
    if (!mysqli_stmt_execute($attemptStatement)) {
        error_log('Unable to update password PIN attempts: ' . mysqli_stmt_error($attemptStatement));
        mysqli_stmt_close($attemptStatement);
        mysqli_rollback($conn);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    mysqli_stmt_close($attemptStatement);
    if (!mysqli_commit($conn)) {
        error_log('Unable to commit failed password PIN attempt: ' . mysqli_error($conn));
        mysqli_rollback($conn);
        passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
    }
    passwordResetRespond(400, ['error' => 'Invalid or expired PIN. Request a new PIN and try again.']);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$updateQuery = $accountType === 'customer'
    ? 'UPDATE tblcustomers SET PasswordHash = ? WHERE CustomerID = ? AND Email = ?'
    : 'UPDATE tblusers SET Password = ? WHERE UserID = ? AND Email = ? AND Role IN (\'Admin\', \'Staff\')';
$update = mysqli_prepare($conn, $updateQuery);
if ($update === false) {
    mysqli_rollback($conn);
    error_log('Unable to prepare password reset update: ' . mysqli_error($conn));
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}
mysqli_stmt_bind_param($update, 'sis', $passwordHash, $accountId, $email);
if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
    error_log('Unable to update password during recovery: ' . mysqli_stmt_error($update));
    mysqli_stmt_close($update);
    mysqli_rollback($conn);
    passwordResetRespond(500, ['error' => 'Unable to reset the password. Request a new PIN and try again.']);
}
mysqli_stmt_close($update);

$clear = mysqli_prepare(
    $conn,
    'DELETE FROM tblpasswordresets WHERE AccountType = ? AND AccountID = ?'
);
if ($clear === false) {
    mysqli_rollback($conn);
    error_log('Unable to prepare password reset cleanup: ' . mysqli_error($conn));
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}
mysqli_stmt_bind_param($clear, 'si', $accountType, $accountId);
if (!mysqli_stmt_execute($clear)) {
    error_log('Unable to clear completed password reset PINs: ' . mysqli_stmt_error($clear));
    mysqli_stmt_close($clear);
    mysqli_rollback($conn);
    passwordResetRespond(500, ['error' => 'Password recovery is temporarily unavailable.']);
}
mysqli_stmt_close($clear);
if (!mysqli_commit($conn)) {
    error_log('Unable to commit password reset: ' . mysqli_error($conn));
    mysqli_rollback($conn);
    passwordResetRespond(500, ['error' => 'Unable to finish resetting the password. Please try again.']);
}

passwordResetRespond(200, ['message' => 'Your password was reset. You can now sign in.']);
