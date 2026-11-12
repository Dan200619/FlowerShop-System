<?php

function sendAuthenticationPin(
    mysqli $conn,
    string $accountType,
    string $purpose,
    string $email,
    ?int $accountId,
    string &$error
): bool {
    $sender = getenv('PASSWORD_RESET_FROM_EMAIL');
    if (!is_string($sender) || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        $sender = (string) ini_get('sendmail_from');
    }
    if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
        $error = 'Email verification is not configured. Please contact support.';
        return false;
    }

    $sessionHash = hash('sha256', session_id());
    $emailHash = hash('sha256', strtolower($email));
    $requestIp = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (mysqli_query($conn, 'DELETE FROM tblauthpins WHERE CreatedAt < CURRENT_TIMESTAMP - INTERVAL 1 DAY') === false) {
        error_log('Unable to remove old authentication PINs: ' . mysqli_error($conn));
    }
    $rateLimit = mysqli_prepare(
        $conn,
        'SELECT
            (SELECT COUNT(*) FROM tblauthpins WHERE SessionHash = ? AND EmailHash = ? AND CreatedAt >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR),
            (SELECT COUNT(*) FROM tblauthpins WHERE RequestIP = ? AND CreatedAt >= CURRENT_TIMESTAMP - INTERVAL 1 HOUR)'
    );
    if ($rateLimit === false) {
        error_log('Unable to prepare authentication PIN rate limit: ' . mysqli_error($conn));
        $error = 'Unable to start email verification right now.';
        return false;
    }
    mysqli_stmt_bind_param($rateLimit, 'sss', $sessionHash, $emailHash, $requestIp);
    if (!mysqli_stmt_execute($rateLimit)) {
        error_log('Unable to check authentication PIN rate limit: ' . mysqli_stmt_error($rateLimit));
        mysqli_stmt_close($rateLimit);
        $error = 'Unable to start email verification right now.';
        return false;
    }
    mysqli_stmt_bind_result($rateLimit, $sessionRequests, $ipRequests);
    $rateFetch = mysqli_stmt_fetch($rateLimit);
    mysqli_stmt_close($rateLimit);
    if ($rateFetch !== true) {
        error_log('Unable to fetch authentication PIN rate limit.');
        $error = 'Unable to start email verification right now.';
        return false;
    }
    if ((int) $sessionRequests >= 5 || (int) $ipRequests >= 30) {
        $error = 'Too many verification PIN requests. Please try again later.';
        return false;
    }

    $delete = mysqli_prepare(
        $conn,
        'DELETE FROM tblauthpins WHERE SessionHash = ? AND AccountType = ? AND Purpose = ?'
    );
    if ($delete === false) {
        error_log('Unable to prepare authentication PIN cleanup: ' . mysqli_error($conn));
        $error = 'Unable to start email verification right now.';
        return false;
    }
    mysqli_stmt_bind_param($delete, 'sss', $sessionHash, $accountType, $purpose);
    if (!mysqli_stmt_execute($delete)) {
        error_log('Unable to clear previous authentication PIN: ' . mysqli_stmt_error($delete));
        mysqli_stmt_close($delete);
        $error = 'Unable to start email verification right now.';
        return false;
    }
    mysqli_stmt_close($delete);

    $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pinHash = password_hash($pin, PASSWORD_DEFAULT);
    $insert = mysqli_prepare(
        $conn,
        'INSERT INTO tblauthpins (AccountType, Purpose, SessionHash, EmailHash, AccountID, PinHash, ExpiresAt, RequestIP)
         VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL 10 MINUTE, ?)'
    );
    if ($insert === false) {
        error_log('Unable to prepare authentication PIN request: ' . mysqli_error($conn));
        $error = 'Unable to start email verification right now.';
        return false;
    }
    mysqli_stmt_bind_param($insert, 'ssssis' . 's', $accountType, $purpose, $sessionHash, $emailHash, $accountId, $pinHash, $requestIp);
    if (!mysqli_stmt_execute($insert)) {
        error_log('Unable to save authentication PIN: ' . mysqli_stmt_error($insert));
        mysqli_stmt_close($insert);
        $error = 'Unable to start email verification right now.';
        return false;
    }
    $pinId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($insert);

    $subject = 'Your email verification PIN';
    $message = "Your email verification PIN is {$pin}.\n\n"
        . "It expires in 10 minutes. If you did not request this code, you can ignore this email.";
    $headers = "From: {$sender}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    if (!@mail($email, $subject, $message, $headers)) {
        error_log('Unable to send an authentication PIN email.');
        $invalidate = mysqli_prepare($conn, 'DELETE FROM tblauthpins WHERE PinID = ?');
        if ($invalidate !== false) {
            mysqli_stmt_bind_param($invalidate, 'i', $pinId);
            mysqli_stmt_execute($invalidate);
            mysqli_stmt_close($invalidate);
        }
        $error = 'Unable to send the verification PIN. Check the email settings and try again.';
        return false;
    }
    return true;
}

function verifyAuthenticationPin(
    mysqli $conn,
    string $accountType,
    string $purpose,
    string $email,
    string $pin,
    ?int $accountId,
    string &$error
): bool {
    $sessionHash = hash('sha256', session_id());
    $emailHash = hash('sha256', strtolower($email));
    if (!mysqli_begin_transaction($conn)) {
        error_log('Unable to start authentication PIN verification transaction: ' . mysqli_error($conn));
        $error = 'Unable to verify the PIN right now.';
        return false;
    }
    $statement = mysqli_prepare(
        $conn,
        'SELECT PinID, AccountID, PinHash, Attempts
         FROM tblauthpins
         WHERE SessionHash = ? AND AccountType = ? AND Purpose = ? AND EmailHash = ?
           AND ExpiresAt > CURRENT_TIMESTAMP AND Attempts < 5
         ORDER BY PinID DESC LIMIT 1 FOR UPDATE'
    );
    if ($statement === false) {
        mysqli_rollback($conn);
        error_log('Unable to prepare authentication PIN verification: ' . mysqli_error($conn));
        $error = 'Unable to verify the PIN right now.';
        return false;
    }
    mysqli_stmt_bind_param($statement, 'ssss', $sessionHash, $accountType, $purpose, $emailHash);
    if (!mysqli_stmt_execute($statement)) {
        error_log('Unable to run authentication PIN verification: ' . mysqli_stmt_error($statement));
        mysqli_stmt_close($statement);
        mysqli_rollback($conn);
        $error = 'Unable to verify the PIN right now.';
        return false;
    }
    mysqli_stmt_bind_result($statement, $pinId, $storedAccountId, $storedPinHash, $attempts);
    $found = mysqli_stmt_fetch($statement);
    mysqli_stmt_close($statement);

    if (!$found || ($accountId !== null && (int) $storedAccountId !== $accountId)) {
        mysqli_rollback($conn);
        $error = 'Invalid or expired PIN. Request a new PIN and try again.';
        return false;
    }
    if (!password_verify($pin, (string) $storedPinHash)) {
        $attemptUpdate = mysqli_prepare($conn, 'UPDATE tblauthpins SET Attempts = Attempts + 1 WHERE PinID = ?');
        if ($attemptUpdate === false) {
            mysqli_rollback($conn);
            error_log('Unable to prepare authentication PIN attempt update: ' . mysqli_error($conn));
            $error = 'Unable to verify the PIN right now.';
            return false;
        }
        mysqli_stmt_bind_param($attemptUpdate, 'i', $pinId);
        if (!mysqli_stmt_execute($attemptUpdate)) {
            error_log('Unable to update authentication PIN attempts: ' . mysqli_stmt_error($attemptUpdate));
            mysqli_stmt_close($attemptUpdate);
            mysqli_rollback($conn);
            $error = 'Unable to verify the PIN right now.';
            return false;
        }
        mysqli_stmt_close($attemptUpdate);
        if (!mysqli_commit($conn)) {
            error_log('Unable to commit authentication PIN attempt: ' . mysqli_error($conn));
            mysqli_rollback($conn);
            $error = 'Unable to verify the PIN right now.';
            return false;
        }
        $error = 'Invalid or expired PIN. Request a new PIN and try again.';
        return false;
    }

    $delete = mysqli_prepare($conn, 'DELETE FROM tblauthpins WHERE PinID = ?');
    if ($delete === false) {
        mysqli_rollback($conn);
        error_log('Unable to prepare verified authentication PIN cleanup: ' . mysqli_error($conn));
        $error = 'Unable to finish email verification right now.';
        return false;
    }
    mysqli_stmt_bind_param($delete, 'i', $pinId);
    if (!mysqli_stmt_execute($delete)) {
        error_log('Unable to clear verified authentication PIN: ' . mysqli_stmt_error($delete));
        mysqli_stmt_close($delete);
        mysqli_rollback($conn);
        $error = 'Unable to finish email verification right now.';
        return false;
    }
    mysqli_stmt_close($delete);
    if (!mysqli_commit($conn)) {
        error_log('Unable to commit verified authentication PIN: ' . mysqli_error($conn));
        mysqli_rollback($conn);
        $error = 'Unable to finish email verification right now.';
        return false;
    }
    return true;
}
