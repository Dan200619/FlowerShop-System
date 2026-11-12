<?php
session_start();

require_once __DIR__ . '/Connection.php';
require_once __DIR__ . '/AuthPin.php';

$automaticReceiptResult = mysqli_query(
    $conn,
    "UPDATE tblcustomerorders
     SET Status = 'Received', PaymentStatus = 'Paid'
     WHERE FulfillmentType = 'delivery'
       AND Status = 'Delivered'
       AND DeliveredAt <= CURRENT_TIMESTAMP - INTERVAL 24 HOUR"
);
$automaticReceiptError = '';
if ($automaticReceiptResult === false) {
    error_log('Unable to automatically confirm delivered customer orders: ' . mysqli_error($conn));
    $automaticReceiptError = 'Automatic order receipt updates are temporarily unavailable.';
}

function customerEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function customerPostString(string $name): string
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

function customerRedirect(string $fragment = ''): void
{
    header('Location: CustomerShop.php' . ($fragment !== '' ? '#' . rawurlencode($fragment) : ''));
    exit;
}

function customerRatingUploads(): array
{
    $uploads = $_FILES['review_images'] ?? null;
    if (!is_array($uploads) || !isset($uploads['name'], $uploads['tmp_name'], $uploads['error'], $uploads['size'])) {
        return [];
    }
    if (!is_array($uploads['name'])) {
        return [$uploads];
    }
    $files = [];
    foreach ($uploads['name'] as $index => $name) {
        $files[] = [
            'name' => $name,
            'tmp_name' => $uploads['tmp_name'][$index] ?? '',
            'error' => $uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $uploads['size'][$index] ?? 0,
        ];
    }
    return array_values(array_filter(
        $files,
        static fn (array $file): bool => (int) $file['error'] !== UPLOAD_ERR_NO_FILE
    ));
}

function customerRatingImageExtension(string $temporaryPath): ?string
{
    $imageInfo = @getimagesize($temporaryPath);
    if (!is_array($imageInfo) || !isset($imageInfo['mime'])) {
        return null;
    }
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $extension = $extensions[$imageInfo['mime']] ?? null;
    if ($extension === null || !function_exists('finfo_open')) {
        return null;
    }
    $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($fileInfo === false) {
        return null;
    }
    $detectedMime = finfo_file($fileInfo, $temporaryPath);
    finfo_close($fileInfo);
    return $detectedMime === $imageInfo['mime'] ? $extension : null;
}

if (!isset($_SESSION['customer_csrf_token']) || !is_string($_SESSION['customer_csrf_token'])) {
    $_SESSION['customer_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['customer_csrf_token'];
$customerOrderToken = $_SESSION['customer_order_token'] ?? '';
if (!is_string($customerOrderToken) || $customerOrderToken === '') {
    $customerOrderToken = bin2hex(random_bytes(32));
    $_SESSION['customer_order_token'] = $customerOrderToken;
}
$customerId = isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : 0;
$customerAccessDisabled = false;
if ($customerId > 0) {
    $customerAccessStatement = mysqli_prepare($conn, 'SELECT `Access` FROM tblcustomers WHERE CustomerID = ?');
    if ($customerAccessStatement === false) {
        error_log('Unable to prepare customer access validation: ' . mysqli_error($conn));
        http_response_code(500);
        exit('Unable to validate your customer account right now.');
    }
    if (
        !mysqli_stmt_bind_param($customerAccessStatement, 'i', $customerId)
        || !mysqli_stmt_execute($customerAccessStatement)
        || !mysqli_stmt_bind_result($customerAccessStatement, $sessionCustomerAccess)
    ) {
        error_log('Unable to validate customer access: ' . mysqli_stmt_error($customerAccessStatement));
        mysqli_stmt_close($customerAccessStatement);
        http_response_code(500);
        exit('Unable to validate your customer account right now.');
    }
    $customerAccessFound = mysqli_stmt_fetch($customerAccessStatement);
    if ($customerAccessFound === false && mysqli_stmt_errno($customerAccessStatement) !== 0) {
        error_log('Unable to fetch customer access: ' . mysqli_stmt_error($customerAccessStatement));
        mysqli_stmt_close($customerAccessStatement);
        http_response_code(500);
        exit('Unable to validate your customer account right now.');
    }
    mysqli_stmt_close($customerAccessStatement);
    if (!$customerAccessFound || $sessionCustomerAccess !== 'Enabled') {
        unset($_SESSION['customer_id'], $_SESSION['customer_name'], $_SESSION['customer_email']);
        $customerId = 0;
        $customerAccessDisabled = true;
    }
}
if (isset($_GET['customer_order_updates'])) {
    if ($customerId < 1) {
        http_response_code(401);
        exit;
    }
    if ($automaticReceiptError !== '') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $automaticReceiptError]);
        exit;
    }
    $updatesStatement = mysqli_prepare(
        $conn,
        'SELECT OrderID, Status, OrderDate, FulfillmentType, FulfillmentDate, FulfillmentTime, TotalAmount
         FROM tblcustomerorders
         WHERE CustomerID = ?
         ORDER BY OrderDate DESC, OrderID DESC
         LIMIT 30'
    );
    if ($updatesStatement === false) {
        error_log('Unable to prepare customer order notification updates: ' . mysqli_error($conn));
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to load your order updates.']);
        exit;
    }
    if (
        !mysqli_stmt_bind_param($updatesStatement, 'i', $customerId)
        || !mysqli_stmt_execute($updatesStatement)
        || !mysqli_stmt_bind_result(
            $updatesStatement,
            $updatesOrderId,
            $updatesStatus,
            $updatesOrderDate,
            $updatesFulfillmentType,
            $updatesFulfillmentDate,
            $updatesFulfillmentTime,
            $updatesTotalAmount
        )
    ) {
        error_log('Unable to load customer order notification updates: ' . mysqli_stmt_error($updatesStatement));
        mysqli_stmt_close($updatesStatement);
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to load your order updates.']);
        exit;
    }
    $orderUpdates = [];
    while (true) {
        $updateFetch = mysqli_stmt_fetch($updatesStatement);
        if ($updateFetch === null || ($updateFetch === false && mysqli_stmt_errno($updatesStatement) === 0)) {
            break;
        }
        if ($updateFetch === false) {
            error_log('Unable to fetch customer order notification updates: ' . mysqli_stmt_error($updatesStatement));
            mysqli_stmt_close($updatesStatement);
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unable to load your order updates.']);
            exit;
        }
        $orderUpdates[] = [
            'order_id' => (int) $updatesOrderId,
            'status' => (string) $updatesStatus,
            'order_date' => (string) $updatesOrderDate,
            'fulfillment_type' => (string) $updatesFulfillmentType,
            'fulfillment_date' => (string) $updatesFulfillmentDate,
            'fulfillment_time' => (string) $updatesFulfillmentTime,
            'total_amount' => (string) $updatesTotalAmount,
        ];
    }
    mysqli_stmt_close($updatesStatement);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(['orders' => $orderUpdates], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$customerError = $customerAccessDisabled
    ? 'Your customer account is disabled. Please contact the shop for assistance.'
    : $automaticReceiptError;
$customerSuccess = '';
$customerAuthStep = isset($_SESSION['pending_customer_signup']) && is_array($_SESSION['pending_customer_signup'])
    ? 'signup'
    : (isset($_SESSION['pending_customer_signin']) && is_array($_SESSION['pending_customer_signin']) ? 'signin' : '');
$restoreCart = [];
$cartRestoreFromServer = false;
if (isset($_SESSION['customer_cart_after_order']) && is_array($_SESSION['customer_cart_after_order'])) {
    $restoreCart = $_SESSION['customer_cart_after_order'];
    $cartRestoreFromServer = true;
    unset($_SESSION['customer_cart_after_order']);
}
$showCustomerOrdersPage = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = customerPostString('csrf_token');
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }

    $action = customerPostString('action');
    if ($action === 'logout') {
        unset(
            $_SESSION['customer_id'],
            $_SESSION['customer_name'],
            $_SESSION['customer_email'],
            $_SESSION['pending_customer_signin'],
            $_SESSION['pending_customer_signup']
        );
        customerRedirect();
    }

    if ($action === 'cancel_customer_auth_pin') {
        unset($_SESSION['pending_customer_signin'], $_SESSION['pending_customer_signup']);
        $customerAuthStep = '';
    } elseif ($action === 'verify_customer_signin_pin') {
        $pendingLogin = $_SESSION['pending_customer_signin'] ?? null;
        $pin = customerPostString('pin');
        if (!is_array($pendingLogin) || !isset($pendingLogin['customer_id'], $pendingLogin['email'])) {
            unset($_SESSION['pending_customer_signin']);
            $customerAuthStep = '';
            $customerError = 'Your sign-in verification expired. Please sign in again.';
        } elseif (!preg_match('/^\d{6}$/', $pin)) {
            $customerError = 'Enter the 6-digit verification PIN.';
        } elseif (verifyAuthenticationPin(
            $conn,
            'customer',
            'signin',
            (string) $pendingLogin['email'],
            $pin,
            (int) $pendingLogin['customer_id'],
            $customerError
        )) {
            $verifyLogin = mysqli_prepare(
                $conn,
                "SELECT FirstName, LastName, `Access` FROM tblcustomers WHERE CustomerID = ? AND Email = ? LIMIT 1"
            );
            if ($verifyLogin === false) {
                error_log('Unable to prepare verified customer login: ' . mysqli_error($conn));
                unset($_SESSION['pending_customer_signin']);
                $customerAuthStep = '';
                $customerError = 'Unable to sign in right now. Please try again.';
            } else {
                $pendingCustomerId = (int) $pendingLogin['customer_id'];
                $pendingCustomerEmail = (string) $pendingLogin['email'];
                mysqli_stmt_bind_param($verifyLogin, 'is', $pendingCustomerId, $pendingCustomerEmail);
                if (
                    !mysqli_stmt_execute($verifyLogin)
                    || !mysqli_stmt_bind_result($verifyLogin, $verifiedFirstName, $verifiedLastName, $verifiedAccess)
                ) {
                    error_log('Unable to execute verified customer login: ' . mysqli_stmt_error($verifyLogin));
                    mysqli_stmt_close($verifyLogin);
                    unset($_SESSION['pending_customer_signin']);
                    $customerAuthStep = '';
                    $customerError = 'Unable to sign in right now. Please try again.';
                } elseif (mysqli_stmt_fetch($verifyLogin) && $verifiedAccess === 'Enabled') {
                    mysqli_stmt_close($verifyLogin);
                    unset($_SESSION['pending_customer_signin']);
                    session_regenerate_id(true);
                    $_SESSION['customer_id'] = $pendingCustomerId;
                    $_SESSION['customer_name'] = $verifiedFirstName . ' ' . $verifiedLastName;
                    $_SESSION['customer_email'] = $pendingCustomerEmail;
                    customerRedirect();
                } else {
                    mysqli_stmt_close($verifyLogin);
                    unset($_SESSION['pending_customer_signin']);
                    $customerAuthStep = '';
                    $customerError = 'The email or password is incorrect.';
                }
            }
        }
    } elseif ($action === 'verify_customer_signup_pin') {
        $pendingSignup = $_SESSION['pending_customer_signup'] ?? null;
        $pin = customerPostString('pin');
        if (!is_array($pendingSignup) || !isset($pendingSignup['email'], $pendingSignup['password_hash'])) {
            unset($_SESSION['pending_customer_signup']);
            $customerAuthStep = '';
            $customerError = 'Your account verification expired. Please create the account again.';
        } elseif (!preg_match('/^\d{6}$/', $pin)) {
            $customerError = 'Enter the 6-digit verification PIN.';
        } elseif (verifyAuthenticationPin(
            $conn,
            'customer',
            'signup',
            (string) $pendingSignup['email'],
            $pin,
            null,
            $customerError
        )) {
            $createCustomer = mysqli_prepare(
                $conn,
                'INSERT INTO tblcustomers (FirstName, LastName, Email, Phone, PasswordHash)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if ($createCustomer === false) {
                error_log('Unable to prepare verified customer signup: ' . mysqli_error($conn));
                unset($_SESSION['pending_customer_signup']);
                $customerAuthStep = '';
                $customerError = 'Unable to create your account right now. Please try again.';
            } else {
                mysqli_stmt_bind_param(
                    $createCustomer,
                    'sssss',
                    $pendingSignup['first_name'],
                    $pendingSignup['last_name'],
                    $pendingSignup['email'],
                    $pendingSignup['phone'],
                    $pendingSignup['password_hash']
                );
                if (mysqli_stmt_execute($createCustomer)) {
                    $newCustomerId = (int) mysqli_insert_id($conn);
                    mysqli_stmt_close($createCustomer);
                    unset($_SESSION['pending_customer_signup']);
                    session_regenerate_id(true);
                    $_SESSION['customer_id'] = $newCustomerId;
                    $_SESSION['customer_name'] = $pendingSignup['first_name'] . ' ' . $pendingSignup['last_name'];
                    $_SESSION['customer_email'] = $pendingSignup['email'];
                    customerRedirect();
                }
                $duplicateEmail = mysqli_stmt_errno($createCustomer) === 1062;
                if (!$duplicateEmail) {
                    error_log('Unable to create verified customer account: ' . mysqli_stmt_error($createCustomer));
                }
                mysqli_stmt_close($createCustomer);
                unset($_SESSION['pending_customer_signup']);
                $customerAuthStep = '';
                $customerError = $duplicateEmail
                    ? 'An account already exists for that email address. Please sign in.'
                    : 'Unable to create your account right now. Please try again.';
            }
        }
    } elseif ($action === 'rate_product') {
        if ($customerId < 1) {
            http_response_code(401);
            exit('Sign in to rate a product.');
        }
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $orderItemId = filter_var($_POST['order_item_id'] ?? null, FILTER_VALIDATE_INT);
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $ratingReview = customerPostString('review');
        $ratingUploads = customerRatingUploads();
        $ratingUploadExtensions = [];
        $ratingError = '';
        if (
            $orderId === false
            || $orderId < 1
            || $orderItemId === false
            || $orderItemId < 1
            || $rating === false
            || $rating < 1
            || $rating > 5
            || mb_strlen($ratingReview) > 255
        ) {
            $ratingError = 'Choose a valid order item and a rating from 1 to 5 stars. Reviews can be up to 255 characters.';
        } elseif (count($ratingUploads) > 3) {
            $ratingError = 'You can upload a maximum of 3 images per product review.';
        } else {
            foreach ($ratingUploads as $upload) {
                if (!isset($upload['error'], $upload['size'], $upload['tmp_name']) || (int) $upload['error'] !== UPLOAD_ERR_OK) {
                    $ratingError = 'One of the selected review images could not be uploaded.';
                    break;
                }
                if ((int) $upload['size'] < 1 || (int) $upload['size'] > 5 * 1024 * 1024) {
                    $ratingError = 'Each review image must be 5 MB or smaller.';
                    break;
                }
                $extension = customerRatingImageExtension((string) $upload['tmp_name']);
                if ($extension === null) {
                    $ratingError = 'Upload review images as JPEG, PNG, GIF, or WebP files.';
                    break;
                }
                $ratingUploadExtensions[] = $extension;
            }
        }
        if ($ratingError !== '') {
            $showCustomerOrdersPage = true;
        } elseif (!mysqli_begin_transaction($conn)) {
            error_log('Unable to start product rating transaction: ' . mysqli_error($conn));
            $ratingError = 'Unable to save your rating right now. Please try again.';
        } else {
            $newRatingImagePaths = [];
            $ratingOrderStatus = '';
            $ratingOrderLookup = mysqli_prepare(
                $conn,
                'SELECT Status FROM tblcustomerorders WHERE OrderID = ? AND CustomerID = ? FOR UPDATE'
            );
            if ($ratingOrderLookup === false) {
                error_log('Unable to prepare product rating order lookup: ' . mysqli_error($conn));
                $ratingError = 'Unable to save your rating right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($ratingOrderLookup, 'ii', $orderId, $customerId)
                    || !mysqli_stmt_execute($ratingOrderLookup)
                    || !mysqli_stmt_bind_result($ratingOrderLookup, $ratingOrderStatus)
                ) {
                    error_log('Unable to load customer order for product rating: ' . mysqli_stmt_error($ratingOrderLookup));
                    $ratingError = 'Unable to save your rating right now. Please try again.';
                } elseif (!mysqli_stmt_fetch($ratingOrderLookup)) {
                    $ratingError = 'That order could not be found.';
                }
                mysqli_stmt_close($ratingOrderLookup);
            }

            if (
                $ratingError === ''
                && !in_array(strtolower((string) $ratingOrderStatus), ['received', 'completed'], true)
            ) {
                $ratingError = 'You can rate products after your order has been received or completed.';
            }

            $ratingProductId = 0;
            if ($ratingError === '') {
                $ratingItemLookup = mysqli_prepare(
                    $conn,
                    "SELECT ProductID FROM tblcustomerorderitems
                     WHERE OrderID = ? AND OrderItemID = ? AND ItemType = 'catalog' AND ProductID IS NOT NULL
                     FOR UPDATE"
                );
                if ($ratingItemLookup === false) {
                    error_log('Unable to prepare product rating item lookup: ' . mysqli_error($conn));
                    $ratingError = 'Unable to save your rating right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param($ratingItemLookup, 'ii', $orderId, $orderItemId)
                        || !mysqli_stmt_execute($ratingItemLookup)
                        || !mysqli_stmt_bind_result($ratingItemLookup, $ratingProductId)
                    ) {
                        error_log('Unable to validate order item for product rating: ' . mysqli_stmt_error($ratingItemLookup));
                        $ratingError = 'Unable to save your rating right now. Please try again.';
                    } elseif (!mysqli_stmt_fetch($ratingItemLookup)) {
                        $ratingError = 'That product is not part of the selected order.';
                    }
                    mysqli_stmt_close($ratingItemLookup);
                }
            }

            if ($ratingError === '' && $ratingUploads !== []) {
                $existingImageCount = 0;
                $existingImageLookup = mysqli_prepare(
                    $conn,
                    'SELECT COUNT(*) FROM tblproductratingimages images
                     INNER JOIN tblproductratings ratings ON ratings.RatingID = images.RatingID
                     WHERE ratings.OrderItemID = ?'
                );
                if ($existingImageLookup === false) {
                    error_log('Unable to prepare product review image count: ' . mysqli_error($conn));
                    $ratingError = 'Unable to save your review images right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param($existingImageLookup, 'i', $orderItemId)
                        || !mysqli_stmt_execute($existingImageLookup)
                        || !mysqli_stmt_bind_result($existingImageLookup, $existingImageCount)
                        || !mysqli_stmt_fetch($existingImageLookup)
                    ) {
                        error_log('Unable to count existing product review images: ' . mysqli_stmt_error($existingImageLookup));
                        $ratingError = 'Unable to save your review images right now. Please try again.';
                    }
                    mysqli_stmt_close($existingImageLookup);
                }
                if ($ratingError === '' && $existingImageCount + count($ratingUploads) > 3) {
                    $ratingError = 'A review can have up to 3 images total. Select fewer images to continue.';
                }
            }

            if ($ratingError === '') {
                $saveRating = mysqli_prepare(
                    $conn,
                    'INSERT INTO tblproductratings (OrderID, OrderItemID, CustomerID, ProductID, Rating, Review)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE Rating = VALUES(Rating), Review = VALUES(Review), UpdatedAt = CURRENT_TIMESTAMP'
                );
                if ($saveRating === false) {
                    error_log('Unable to prepare customer product rating save: ' . mysqli_error($conn));
                    $ratingError = 'Unable to save your rating right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param($saveRating, 'iiiiis', $orderId, $orderItemId, $customerId, $ratingProductId, $rating, $ratingReview)
                        || !mysqli_stmt_execute($saveRating)
                    ) {
                        error_log('Unable to save customer product rating: ' . mysqli_stmt_error($saveRating));
                        $ratingError = 'Unable to save your rating right now. Please try again.';
                    }
                    mysqli_stmt_close($saveRating);
                }
            }

            if ($ratingError === '' && $ratingUploads !== []) {
                $ratingId = 0;
                $ratingIdLookup = mysqli_prepare($conn, 'SELECT RatingID FROM tblproductratings WHERE OrderItemID = ?');
                if ($ratingIdLookup === false) {
                    error_log('Unable to prepare product rating image lookup: ' . mysqli_error($conn));
                    $ratingError = 'Unable to save your review images right now. Please try again.';
                } elseif (
                    !mysqli_stmt_bind_param($ratingIdLookup, 'i', $orderItemId)
                    || !mysqli_stmt_execute($ratingIdLookup)
                    || !mysqli_stmt_bind_result($ratingIdLookup, $ratingId)
                    || !mysqli_stmt_fetch($ratingIdLookup)
                ) {
                    error_log('Unable to find product rating for its images: ' . mysqli_stmt_error($ratingIdLookup));
                    $ratingError = 'Unable to save your review images right now. Please try again.';
                }
                if ($ratingIdLookup !== false) {
                    mysqli_stmt_close($ratingIdLookup);
                }

                $imageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reviews';
                if ($ratingError === '' && !is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true) && !is_dir($imageDirectory)) {
                    error_log('Unable to create product review image directory.');
                    $ratingError = 'Unable to save your review images right now. Please try again.';
                }
                if ($ratingError === '') {
                    $saveRatingImage = mysqli_prepare(
                        $conn,
                        'INSERT INTO tblproductratingimages (RatingID, ImagePath) VALUES (?, ?)'
                    );
                    if ($saveRatingImage === false) {
                        error_log('Unable to prepare product rating image save: ' . mysqli_error($conn));
                        $ratingError = 'Unable to save your review images right now. Please try again.';
                    } else {
                        foreach ($ratingUploads as $index => $upload) {
                            $fileName = bin2hex(random_bytes(16)) . '.' . $ratingUploadExtensions[$index];
                            $relativePath = 'uploads/reviews/' . $fileName;
                            $absolutePath = $imageDirectory . DIRECTORY_SEPARATOR . $fileName;
                            if (!move_uploaded_file((string) $upload['tmp_name'], $absolutePath)) {
                                error_log('Unable to move uploaded product review image.');
                                $ratingError = 'Unable to save one of your review images.';
                                break;
                            }
                            $newRatingImagePaths[] = $absolutePath;
                            if (
                                !mysqli_stmt_bind_param($saveRatingImage, 'is', $ratingId, $relativePath)
                                || !mysqli_stmt_execute($saveRatingImage)
                            ) {
                                error_log('Unable to save product review image path: ' . mysqli_stmt_error($saveRatingImage));
                                $ratingError = 'Unable to save your review images right now. Please try again.';
                                break;
                            }
                        }
                        mysqli_stmt_close($saveRatingImage);
                    }
                }
            }

            if ($ratingError === '') {
                if (mysqli_commit($conn)) {
                    $_SESSION['customer_order_notice'] = 'Your product rating has been saved.';
                    customerRedirect('my-orders');
                }
                error_log('Unable to commit customer product rating: ' . mysqli_error($conn));
                $ratingError = 'Unable to save your rating right now. Please try again.';
            }
            mysqli_rollback($conn);
            foreach ($newRatingImagePaths as $newRatingImagePath) {
                if (is_file($newRatingImagePath) && !unlink($newRatingImagePath)) {
                    error_log('Unable to remove an uncommitted product review image.');
                }
            }
        }
        $customerError = $ratingError;
        $showCustomerOrdersPage = true;
    } elseif ($action === 'confirm_order_received') {
        if ($customerId < 1) {
            http_response_code(401);
            exit('Sign in to confirm order receipt.');
        }
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        if ($orderId === false || $orderId < 1) {
            $customerError = 'Choose a valid order to confirm.';
        } else {
            $confirmReceived = mysqli_prepare(
                $conn,
                "UPDATE tblcustomerorders
                 SET Status = 'Received', PaymentStatus = 'Paid'
                 WHERE OrderID = ? AND CustomerID = ? AND FulfillmentType = 'delivery' AND Status = 'Delivered'"
            );
            if ($confirmReceived === false) {
                error_log('Unable to prepare customer order receipt confirmation: ' . mysqli_error($conn));
                $customerError = 'Unable to confirm receipt right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($confirmReceived, 'ii', $orderId, $customerId)
                    || !mysqli_stmt_execute($confirmReceived)
                ) {
                    error_log('Unable to save customer order receipt confirmation: ' . mysqli_stmt_error($confirmReceived));
                    $customerError = 'Unable to confirm receipt right now. Please try again.';
                } elseif (mysqli_stmt_affected_rows($confirmReceived) !== 1) {
                    $customerError = 'This order is not ready for receipt confirmation.';
                } else {
                    $_SESSION['customer_order_notice'] = 'Order #' . $orderId . ' marked received. The shop has been notified.';
                    mysqli_stmt_close($confirmReceived);
                    customerRedirect('my-orders');
                }
                mysqli_stmt_close($confirmReceived);
            }
        }
        $showCustomerOrdersPage = true;
    } elseif ($action === 'cancel_order') {
        if ($customerId < 1) {
            http_response_code(401);
            exit('Sign in to cancel an order.');
        }
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $cancelError = '';
        if ($orderId === false || $orderId < 1) {
            $cancelError = 'Choose a valid order to cancel.';
        } elseif (!mysqli_begin_transaction($conn)) {
            error_log('Unable to start customer order cancellation transaction: ' . mysqli_error($conn));
            $cancelError = 'Unable to cancel your order right now. Please try again.';
        } else {
            $orderStatus = '';
            $orderLookup = mysqli_prepare(
                $conn,
                'SELECT Status FROM tblcustomerorders WHERE OrderID = ? AND CustomerID = ? FOR UPDATE'
            );
            if ($orderLookup === false) {
                error_log('Unable to prepare customer order cancellation lookup: ' . mysqli_error($conn));
                $cancelError = 'Unable to cancel your order right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($orderLookup, 'ii', $orderId, $customerId)
                    || !mysqli_stmt_execute($orderLookup)
                    || !mysqli_stmt_bind_result($orderLookup, $orderStatus)
                ) {
                    error_log('Unable to load order for customer cancellation: ' . mysqli_stmt_error($orderLookup));
                    $cancelError = 'Unable to cancel your order right now. Please try again.';
                } elseif (!mysqli_stmt_fetch($orderLookup)) {
                    $cancelError = 'That order could not be found.';
                }
                mysqli_stmt_close($orderLookup);
            }

            if ($cancelError === '' && !in_array($orderStatus, ['Pending review', 'Pending'], true)) {
                $cancelError = 'You can only cancel an order that is still awaiting approval.';
            }

            if ($cancelError === '') {
                $cancelItems = mysqli_prepare(
                    $conn,
                    'SELECT ProductID, Quantity, ItemType, CustomComponents
                     FROM tblcustomerorderitems WHERE OrderID = ? FOR UPDATE'
                );
                if ($cancelItems === false) {
                    error_log('Unable to prepare order stock release for customer cancellation: ' . mysqli_error($conn));
                    $cancelError = 'Unable to cancel your order right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param($cancelItems, 'i', $orderId)
                        || !mysqli_stmt_execute($cancelItems)
                        || !mysqli_stmt_store_result($cancelItems)
                        || !mysqli_stmt_bind_result($cancelItems, $cancelProductId, $cancelQuantity, $cancelItemType, $cancelComponentsJson)
                    ) {
                        error_log('Unable to load order items for customer cancellation: ' . mysqli_stmt_error($cancelItems));
                        $cancelError = 'Unable to release this order reservation right now.';
                    } else {
                        while (true) {
                            $itemFetch = mysqli_stmt_fetch($cancelItems);
                            if ($itemFetch === null || ($itemFetch === false && mysqli_stmt_errno($cancelItems) === 0)) {
                                break;
                            }
                            if ($itemFetch === false) {
                                error_log('Unable to fetch order items for customer cancellation: ' . mysqli_stmt_error($cancelItems));
                                $cancelError = 'Unable to release this order reservation right now.';
                                break;
                            }
                            $quantityToRestore = (int) $cancelQuantity;
                            $reservedProductStock = null;
                            if ($cancelItemType === 'catalog' && $cancelProductId !== null) {
                                $productStockLookup = mysqli_prepare($conn, 'SELECT ProductStock FROM tblproducts WHERE ProductID = ? FOR UPDATE');
                                if ($productStockLookup === false) {
                                    error_log('Unable to prepare stock lookup for customer cancellation: ' . mysqli_error($conn));
                                    $cancelError = 'Unable to release reserved product stock.';
                                    break;
                                }
                                if (
                                    !mysqli_stmt_bind_param($productStockLookup, 'i', $cancelProductId)
                                    || !mysqli_stmt_execute($productStockLookup)
                                    || !mysqli_stmt_bind_result($productStockLookup, $reservedProductStock)
                                ) {
                                    error_log('Unable to read reserved stock for customer cancellation: ' . mysqli_stmt_error($productStockLookup));
                                    $cancelError = 'Unable to release reserved product stock.';
                                    mysqli_stmt_close($productStockLookup);
                                    break;
                                }
                                $productExists = mysqli_stmt_fetch($productStockLookup);
                                mysqli_stmt_close($productStockLookup);
                                if ($productExists !== true) {
                                    $cancelError = 'A reserved product could not be found.';
                                    break;
                                }
                                if ($reservedProductStock !== null) {
                                    $restoreProduct = mysqli_prepare(
                                        $conn,
                                        'UPDATE tblproducts SET ProductStock = ProductStock + ? WHERE ProductID = ?'
                                    );
                                    if ($restoreProduct === false) {
                                        error_log('Unable to prepare product stock release for customer cancellation: ' . mysqli_error($conn));
                                        $cancelError = 'Unable to release reserved product stock.';
                                        break;
                                    }
                                    mysqli_stmt_bind_param($restoreProduct, 'ii', $quantityToRestore, $cancelProductId);
                                    if (!mysqli_stmt_execute($restoreProduct) || mysqli_stmt_affected_rows($restoreProduct) !== 1) {
                                        error_log('Unable to release product stock for customer cancellation: ' . mysqli_stmt_error($restoreProduct));
                                        $cancelError = 'Unable to release reserved product stock.';
                                    }
                                    mysqli_stmt_close($restoreProduct);
                                    if ($cancelError !== '') {
                                        break;
                                    }
                                }
                            } elseif ($cancelItemType === 'custom') {
                                $savedComponents = json_decode((string) $cancelComponentsJson, true);
                                if (!is_array($savedComponents) || !isset($savedComponents['components']) || !is_array($savedComponents['components'])) {
                                    $cancelError = 'The customized order reservation details are invalid; stock was not released.';
                                    break;
                                }
                                foreach ($savedComponents['components'] as $savedComponent) {
                                    if (
                                        !is_array($savedComponent)
                                        || !isset($savedComponent['item_id'], $savedComponent['quantity'])
                                        || !is_numeric($savedComponent['item_id'])
                                        || !is_numeric($savedComponent['quantity'])
                                        || (int) $savedComponent['item_id'] < 1
                                        || (int) $savedComponent['quantity'] < 1
                                    ) {
                                        $cancelError = 'The customized order reservation details are invalid; stock was not released.';
                                        break 2;
                                    }
                                    $componentId = (int) $savedComponent['item_id'];
                                    $componentQuantity = (int) $savedComponent['quantity'] * $quantityToRestore;
                                    $restoreComponent = mysqli_prepare(
                                        $conn,
                                        'UPDATE tblinventory SET Stock = Stock + ? WHERE ItemID = ?'
                                    );
                                    if ($restoreComponent === false) {
                                        error_log('Unable to prepare component stock release for customer cancellation: ' . mysqli_error($conn));
                                        $cancelError = 'Unable to release reserved bouquet stock.';
                                        break 2;
                                    }
                                    mysqli_stmt_bind_param($restoreComponent, 'ii', $componentQuantity, $componentId);
                                    if (!mysqli_stmt_execute($restoreComponent) || mysqli_stmt_affected_rows($restoreComponent) !== 1) {
                                        error_log('Unable to release component stock for customer cancellation: ' . mysqli_stmt_error($restoreComponent));
                                        $cancelError = 'Unable to release reserved bouquet stock.';
                                    }
                                    mysqli_stmt_close($restoreComponent);
                                    if ($cancelError !== '') {
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    mysqli_stmt_close($cancelItems);
                }
            }

            if ($cancelError === '') {
                $cancelUpdate = mysqli_prepare(
                    $conn,
                    "UPDATE tblcustomerorders
                     SET Status = 'Cancelled'
                     WHERE OrderID = ? AND CustomerID = ? AND Status IN ('Pending review', 'Pending')"
                );
                if ($cancelUpdate === false) {
                    error_log('Unable to prepare customer order cancellation update: ' . mysqli_error($conn));
                    $cancelError = 'Unable to cancel your order right now. Please try again.';
                } else {
                    mysqli_stmt_bind_param($cancelUpdate, 'ii', $orderId, $customerId);
                    if (!mysqli_stmt_execute($cancelUpdate) || mysqli_stmt_affected_rows($cancelUpdate) !== 1) {
                        error_log('Unable to cancel customer order: ' . mysqli_stmt_error($cancelUpdate));
                        $cancelError = 'This order can no longer be cancelled.';
                    }
                    mysqli_stmt_close($cancelUpdate);
                }
            }

            if ($cancelError === '') {
                if (mysqli_commit($conn)) {
                    $_SESSION['customer_order_notice'] = 'Order #' . $orderId . ' was cancelled.';
                    customerRedirect('my-orders');
                }
                error_log('Unable to commit customer order cancellation: ' . mysqli_error($conn));
                $cancelError = 'Unable to finish cancelling your order. Please try again.';
            }
            mysqli_rollback($conn);
        }
        $customerError = $cancelError;
        $showCustomerOrdersPage = true;
    } elseif ($action === 'signup' || $action === 'login') {
        unset($_SESSION['pending_customer_signin'], $_SESSION['pending_customer_signup']);
        $customerAuthStep = '';
        $email = mb_strtolower(customerPostString('email'));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190 || $password === '') {
            $customerError = 'Enter a valid email address and password.';
        } elseif ($action === 'signup') {
            $firstName = customerPostString('first_name');
            $lastName = customerPostString('last_name');
            $phone = customerPostString('phone');
            if (
                $firstName === '' || mb_strlen($firstName) > 80
                || $lastName === '' || mb_strlen($lastName) > 80
                || $phone === '' || mb_strlen($phone) > 30
                || strlen($password) < 8
            ) {
                $customerError = 'Enter your name and phone number, and use a password with at least 8 characters.';
            } else {
                $existingCustomer = mysqli_prepare($conn, 'SELECT CustomerID FROM tblcustomers WHERE Email = ? LIMIT 1');
                if ($existingCustomer === false) {
                    error_log('Unable to prepare customer signup email lookup: ' . mysqli_error($conn));
                    $customerError = 'Unable to create your account right now. Please try again.';
                } else {
                    mysqli_stmt_bind_param($existingCustomer, 's', $email);
                    if (!mysqli_stmt_execute($existingCustomer)) {
                        error_log('Unable to check customer signup email: ' . mysqli_stmt_error($existingCustomer));
                        mysqli_stmt_close($existingCustomer);
                        $customerError = 'Unable to create your account right now. Please try again.';
                    } else {
                        mysqli_stmt_bind_result($existingCustomer, $existingCustomerId);
                        $emailAlreadyUsed = mysqli_stmt_fetch($existingCustomer);
                        mysqli_stmt_close($existingCustomer);
                        if ($emailAlreadyUsed) {
                            $customerError = 'An account already exists for that email address. Please sign in.';
                        } elseif (sendAuthenticationPin($conn, 'customer', 'signup', $email, null, $customerError)) {
                            unset($_SESSION['pending_customer_signin']);
                            $_SESSION['pending_customer_signup'] = [
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $email,
                                'phone' => $phone,
                                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            ];
                            $customerAuthStep = 'signup';
                            $customerSuccess = 'A verification PIN was sent to your email. Enter it below to create your account.';
                        }
                    }
                }
            }
        } else {
            $statement = mysqli_prepare(
                $conn,
                'SELECT CustomerID, FirstName, LastName, PasswordHash, `Access`
                 FROM tblcustomers WHERE Email = ? LIMIT 1'
            );
            if ($statement === false) {
                error_log('Unable to prepare customer login: ' . mysqli_error($conn));
                $customerError = 'Unable to sign in right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($statement, 's', $email)
                    || !mysqli_stmt_execute($statement)
                    || !mysqli_stmt_bind_result($statement, $foundCustomerId, $foundFirstName, $foundLastName, $passwordHash, $foundCustomerAccess)
                ) {
                    error_log('Unable to execute customer login lookup: ' . mysqli_stmt_error($statement));
                    mysqli_stmt_close($statement);
                    $customerError = 'Unable to sign in right now. Please try again.';
                } elseif (mysqli_stmt_fetch($statement) && password_verify($password, (string) $passwordHash)) {
                    mysqli_stmt_close($statement);
                    if ($foundCustomerAccess !== 'Enabled') {
                        $customerError = 'The email or password is incorrect.';
                    } elseif (sendAuthenticationPin(
                        $conn,
                        'customer',
                        'signin',
                        $email,
                        (int) $foundCustomerId,
                        $customerError
                    )) {
                        unset($_SESSION['pending_customer_signup']);
                        $_SESSION['pending_customer_signin'] = [
                            'customer_id' => (int) $foundCustomerId,
                            'email' => $email,
                        ];
                        $customerAuthStep = 'signin';
                        $customerSuccess = 'A verification PIN was sent to your email. Enter it below to sign in.';
                    }
                } else {
                    mysqli_stmt_close($statement);
                    $customerError = 'The email or password is incorrect.';
                }
            }
        }
    } elseif ($action === 'place_order') {
        if ($customerId < 1) {
            http_response_code(401);
            exit('Sign in to place your order.');
        }
        $submittedOrderToken = customerPostString('order_token');
        if (!hash_equals($customerOrderToken, $submittedOrderToken)) {
            http_response_code(403);
            exit('This checkout request has already been submitted. Refresh the page before trying again.');
        }
        unset($_SESSION['customer_order_token']);

        $cartJson = customerPostString('cart');
        $cart = json_decode($cartJson, true);
        if (is_array($cart)) {
            $restoreCart = $cart;
            $cartRestoreFromServer = true;
            $cart = array_values(array_filter(
                $cart,
                static fn ($cartItem): bool => !is_array($cartItem)
                    || !array_key_exists('selected', $cartItem)
                    || $cartItem['selected'] === true
            ));
        }
        $fulfillmentType = customerPostString('fulfillment_type');
        $paymentMethod = customerPostString('payment_method');
        $allowedPayments = ['cash', 'gcash', 'maribank', 'gotyme', 'landbank'];
        $error = '';
        $items = [];
        $totalCents = 0;

        if (!is_array($cart) || $cart === [] || count($cart) > 50) {
            $error = 'Select at least one item to purchase.';
        } elseif (!in_array($fulfillmentType, ['pickup', 'delivery'], true)) {
            $error = 'Choose pickup or delivery.';
        } elseif (!in_array($paymentMethod, $allowedPayments, true)) {
            $error = 'Choose a valid payment method.';
        }

        $date = customerPostString('fulfillment_date');
        $time = customerPostString('fulfillment_time');
        $fulfillmentDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (
            $error === ''
            && (
                $fulfillmentDate === false
                || $fulfillmentDate->format('Y-m-d') !== $date
                || $fulfillmentDate < new DateTimeImmutable('today')
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)
            )
        ) {
            $error = 'Choose a valid pickup or delivery date and time.';
        }

        $profileFirstName = '';
        $profileLastName = '';
        $profilePhone = '';
        $profileStatement = mysqli_prepare($conn, 'SELECT FirstName, LastName, Phone FROM tblcustomers WHERE CustomerID = ?');
        if ($profileStatement === false) {
            error_log('Unable to prepare customer profile lookup: ' . mysqli_error($conn));
            $error = 'Unable to validate your account right now. Please try again.';
        } else {
            if (
                !mysqli_stmt_bind_param($profileStatement, 'i', $customerId)
                || !mysqli_stmt_execute($profileStatement)
                || !mysqli_stmt_bind_result($profileStatement, $profileFirstName, $profileLastName, $profilePhone)
            ) {
                error_log('Unable to read customer profile: ' . mysqli_stmt_error($profileStatement));
                $error = 'Unable to validate your account right now. Please try again.';
            } elseif (!mysqli_stmt_fetch($profileStatement)) {
                $error = 'Your customer account could not be found. Please sign in again.';
            }
            mysqli_stmt_close($profileStatement);
        }

        $recipientName = $fulfillmentType === 'delivery' ? customerPostString('recipient_name') : trim($profileFirstName . ' ' . $profileLastName);
        $recipientPhone = $fulfillmentType === 'delivery' ? customerPostString('recipient_phone') : (string) $profilePhone;
        $address = customerPostString('delivery_address');
        $barangay = customerPostString('delivery_barangay');
        $city = customerPostString('delivery_city');
        $province = customerPostString('delivery_province');
        $postalCode = customerPostString('delivery_postal_code');
        $instructions = customerPostString('delivery_instructions');
        if (
            $error === ''
            && (
                $recipientName === '' || mb_strlen($recipientName) > 150
                || $recipientPhone === '' || mb_strlen($recipientPhone) > 30
                || ($fulfillmentType === 'delivery' && (
                    $address === '' || mb_strlen($address) > 200
                    || $barangay === '' || mb_strlen($barangay) > 100
                    || $city === '' || mb_strlen($city) > 100
                    || $province === '' || mb_strlen($province) > 100
                    || mb_strlen($postalCode) > 20 || mb_strlen($instructions) > 500
                ))
            )
        ) {
            $error = 'Complete all required recipient and delivery details.';
        }

        if ($error === '') {
            $productName = '';
            $productPrice = 0.0;
            $productDiscountPercent = 0.0;
            $productStock = null;
            $category = '';
            $componentName = '';
            $componentPrice = 0.0;
            $componentStock = 0;
            foreach ($cart as $cartItem) {
                if (
                    !is_array($cartItem)
                    || !isset($cartItem['product_id'], $cartItem['quantity'])
                    || !is_int($cartItem['product_id'])
                    || !is_int($cartItem['quantity'])
                    || $cartItem['quantity'] < 1 || $cartItem['quantity'] > 99
                ) {
                    $error = 'Your cart contains an invalid item or quantity.';
                    break;
                }
                if ($cartItem['product_id'] > 0 && !isset($cartItem['custom'])) {
                    $items[] = ['product_id' => $cartItem['product_id'], 'quantity' => $cartItem['quantity']];
                } elseif ($cartItem['product_id'] === 0 && isset($cartItem['custom']) && is_array($cartItem['custom'])) {
                    $components = $cartItem['custom']['components'] ?? null;
                    $name = isset($cartItem['custom']['name']) && is_string($cartItem['custom']['name'])
                        ? trim($cartItem['custom']['name']) : '';
                    $customType = $cartItem['custom']['type'] ?? 'bouquet';
                    if (
                        $name === '' || mb_strlen($name) > ($customType === 'addon' ? 92 : 50)
                        || !in_array($customType, ['bouquet', 'addon'], true)
                        || !is_array($components) || $components === [] || count($components) > 50
                    ) {
                        $error = 'Your customized bouquet is invalid.';
                        break;
                    }
                    $seen = [];
                    foreach ($components as $component) {
                        if (
                            !is_array($component)
                            || !isset($component['item_id'], $component['quantity'])
                            || !is_int($component['item_id']) || $component['item_id'] < 1
                            || !is_int($component['quantity']) || $component['quantity'] < 1 || $component['quantity'] > 99
                            || isset($seen[$component['item_id']])
                        ) {
                            $error = 'Your customized bouquet contains an invalid component.';
                            break 2;
                        }
                        $seen[$component['item_id']] = true;
                    }
                    $items[] = [
                        'product_id' => 0, 'quantity' => $cartItem['quantity'], 'name' => $name,
                        'components' => $components, 'type' => $customType,
                    ];
                } else {
                    $error = 'Your cart contains an invalid product.';
                    break;
                }
            }
        }

        if ($error === '' && mysqli_begin_transaction($conn)) {
            $catalogLookup = mysqli_prepare($conn, 'SELECT ProductName, Price, ProductDiscountPercent, ProductStock FROM tblproducts WHERE ProductID = ? FOR UPDATE');
            $inventoryLookup = mysqli_prepare($conn, 'SELECT ItemName, Category, Price, Stock FROM tblinventory WHERE ItemID = ? FOR UPDATE');
            $orderLines = [];
            if ($catalogLookup === false || $inventoryLookup === false) {
                error_log('Unable to prepare customer order lookups: ' . mysqli_error($conn));
                $error = 'Unable to validate your items right now. Please try again.';
            }

            foreach ($items as $item) {
                if ($error !== '') {
                    break;
                }
                $quantity = $item['quantity'];
                if ($item['product_id'] > 0) {
                    mysqli_stmt_bind_param($catalogLookup, 'i', $item['product_id']);
                    if (!mysqli_stmt_execute($catalogLookup)) {
                        $error = 'Unable to validate a product in your cart.';
                        break;
                    }
                    mysqli_stmt_bind_result($catalogLookup, $productName, $productPrice, $productDiscountPercent, $productStock);
                    if (!mysqli_stmt_fetch($catalogLookup)) {
                        $error = 'A product in your cart is no longer available.';
                        mysqli_stmt_free_result($catalogLookup);
                        break;
                    }
                    mysqli_stmt_free_result($catalogLookup);
                    if ($productStock !== null && (int) $productStock < $quantity) {
                        $error = 'There is not enough stock for ' . $productName . '.';
                        break;
                    }
                    $unitCents = (int) round((float) $productPrice * 100 * (1 - (float) $productDiscountPercent / 100));
                    $lineCents = $unitCents * $quantity;
                    $orderLines[] = [
                        'product_id' => $item['product_id'], 'name' => (string) $productName,
                        'type' => 'catalog', 'components' => null, 'quantity' => $quantity,
                        'unit_price' => $unitCents / 100, 'line_total' => $lineCents / 100,
                        'tracked_stock' => $productStock !== null,
                    ];
                } else {
                    $categoryCounts = [];
                    $componentNames = [];
                    $componentCostCents = 0;
                    $normalized = [];
                    foreach ($item['components'] as $component) {
                        $category = '';
                        $componentName = '';
                        $componentPrice = 0.0;
                        $componentStock = 0;
                        mysqli_stmt_bind_param($inventoryLookup, 'i', $component['item_id']);
                        if (!mysqli_stmt_execute($inventoryLookup)) {
                            $error = 'Unable to validate your bouquet components.';
                            break 2;
                        }
                        mysqli_stmt_bind_result($inventoryLookup, $componentName, $category, $componentPrice, $componentStock);
                        if (!mysqli_stmt_fetch($inventoryLookup)) {
                            $error = 'A bouquet component is no longer available.';
                            mysqli_stmt_free_result($inventoryLookup);
                            break 2;
                        }
                        mysqli_stmt_free_result($inventoryLookup);
                        if (!in_array($category, ['Flower', 'Wrapper', 'Ribbon', 'Addons'], true)) {
                            $error = 'A selected component cannot be used in a bouquet.';
                            break 2;
                        }
                        $required = $component['quantity'] * $quantity;
                        if ((int) $componentStock < $required) {
                            $error = 'There is not enough stock for ' . $componentName . '.';
                            break 2;
                        }
                        $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
                        $componentCostCents += (int) round((float) $componentPrice * 100) * $component['quantity'];
                        $componentNames[] = $componentName . ' x ' . $component['quantity'];
                        $normalized[] = [
                            'item_id' => $component['item_id'], 'name' => (string) $componentName,
                            'category' => (string) $category, 'quantity' => $component['quantity'],
                        ];
                    }
                    $isAddonOnly = $item['type'] === 'addon'
                        && ($categoryCounts['Addons'] ?? 0) === count($normalized)
                        && array_diff(array_keys($categoryCounts), ['Addons']) === [];
                    $isBouquet = $item['type'] === 'bouquet'
                        && ($categoryCounts['Flower'] ?? 0) >= 1
                        && ($categoryCounts['Wrapper'] ?? 0) >= 1
                        && ($categoryCounts['Wrapper'] ?? 0) <= 2
                        && ($categoryCounts['Ribbon'] ?? 0) === 1
                        && array_diff(array_keys($categoryCounts), ['Flower', 'Wrapper', 'Ribbon', 'Addons']) === [];
                    if (!$isAddonOnly && !$isBouquet) {
                        $error = $item['type'] === 'addon'
                            ? 'An add-on order can only contain in-stock add-ons.'
                            : 'A bouquet needs flowers, one or two wrappers, and exactly one ribbon.';
                        break;
                    }
                    $componentsJson = json_encode(
                        ['components' => $normalized, 'component_names' => $componentNames],
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                    if (!is_string($componentsJson)) {
                        $error = 'Unable to save your bouquet details.';
                        break;
                    }
                    $lineCents = $componentCostCents * $quantity;
                    $orderLines[] = [
                        'product_id' => null,
                        'name' => ($isAddonOnly ? 'Add-on: ' : 'Custom: ') . $item['name'],
                        'type' => 'custom', 'components' => $componentsJson, 'quantity' => $quantity,
                        'unit_price' => $componentCostCents / 100, 'line_total' => $lineCents / 100,
                        'tracked_stock' => false,
                    ];
                }
                if ($lineCents < 1 || $totalCents > 9999999999 - $lineCents) {
                    $error = 'The total order amount is invalid.';
                    break;
                }
                $totalCents += $lineCents;
            }

            if ($error === '') {
                foreach ($orderLines as $line) {
                    if ($line['type'] === 'catalog' && $line['tracked_stock']) {
                        $decrement = mysqli_prepare(
                            $conn,
                            'UPDATE tblproducts SET ProductStock = ProductStock - ?
                             WHERE ProductID = ? AND ProductStock >= ?'
                        );
                        if ($decrement === false) {
                            error_log('Unable to prepare customer product stock update: ' . mysqli_error($conn));
                            $error = 'Unable to reserve a product in your order.';
                            break;
                        }
                        $productId = (int) $line['product_id'];
                        $quantity = (int) $line['quantity'];
                        mysqli_stmt_bind_param($decrement, 'iii', $quantity, $productId, $quantity);
                        if (!mysqli_stmt_execute($decrement) || mysqli_stmt_affected_rows($decrement) !== 1) {
                            $error = 'A catalog product just sold out. Refresh your cart and try again.';
                        }
                        mysqli_stmt_close($decrement);
                    } elseif ($line['type'] === 'custom') {
                        $components = json_decode((string) $line['components'], true)['components'] ?? [];
                        foreach ($components as $component) {
                            $required = (int) $component['quantity'] * (int) $line['quantity'];
                            $itemId = (int) $component['item_id'];
                            $decrement = mysqli_prepare(
                                $conn,
                                'UPDATE tblinventory SET Stock = Stock - ? WHERE ItemID = ? AND Stock >= ?'
                            );
                            if ($decrement === false) {
                                error_log('Unable to prepare customer component stock update: ' . mysqli_error($conn));
                                $error = 'Unable to reserve a bouquet component.';
                                break 2;
                            }
                            mysqli_stmt_bind_param($decrement, 'iii', $required, $itemId, $required);
                            if (!mysqli_stmt_execute($decrement) || mysqli_stmt_affected_rows($decrement) !== 1) {
                                $error = 'A bouquet component just sold out. Refresh your cart and try again.';
                            }
                            mysqli_stmt_close($decrement);
                            if ($error !== '') {
                                break 2;
                            }
                        }
                    }
                    if ($error !== '') {
                        break;
                    }
                }
            }

            if ($error === '') {
                $orderStatement = mysqli_prepare(
                    $conn,
                    "INSERT INTO tblcustomerorders (
                        CustomerID, FulfillmentType, FulfillmentDate, FulfillmentTime,
                        RecipientName, RecipientPhone, DeliveryAddress, DeliveryBarangay,
                        DeliveryCity, DeliveryProvince, DeliveryPostalCode, DeliveryInstructions,
                        PaymentMethod, Status, TotalAmount
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending review', ?)"
                );
                $orderTime = $time . ':00';
                $totalAmount = $totalCents / 100;
                $addressValue = $fulfillmentType === 'delivery' ? $address : null;
                $barangayValue = $fulfillmentType === 'delivery' ? $barangay : null;
                $cityValue = $fulfillmentType === 'delivery' ? $city : null;
                $provinceValue = $fulfillmentType === 'delivery' ? $province : null;
                $postalValue = $fulfillmentType === 'delivery' ? $postalCode : null;
                $instructionsValue = $fulfillmentType === 'delivery' ? $instructions : null;
                if ($orderStatement === false) {
                    error_log('Unable to prepare customer order insert: ' . mysqli_error($conn));
                    $error = 'Unable to save your order. Please try again.';
                } else {
                    mysqli_stmt_bind_param(
                        $orderStatement,
                        'issssssssssssd',
                        $customerId, $fulfillmentType, $date, $orderTime, $recipientName,
                        $recipientPhone, $addressValue, $barangayValue, $cityValue, $provinceValue,
                        $postalValue, $instructionsValue, $paymentMethod, $totalAmount
                    );
                    if (!mysqli_stmt_execute($orderStatement)) {
                        error_log('Unable to create customer order: ' . mysqli_stmt_error($orderStatement));
                        $error = 'Unable to save your order. Please try again.';
                    } else {
                        $orderId = (int) mysqli_insert_id($conn);
                    }
                    mysqli_stmt_close($orderStatement);
                }
                if ($error === '') {
                    $lineStatement = mysqli_prepare(
                        $conn,
                        'INSERT INTO tblcustomerorderitems
                            (OrderID, ProductID, ProductName, ItemType, CustomComponents, Quantity, UnitPrice, LineTotal)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if ($lineStatement === false) {
                        error_log('Unable to prepare customer order item insert: ' . mysqli_error($conn));
                        $error = 'Unable to save your order items. Please try again.';
                    } else {
                        foreach ($orderLines as $line) {
                            $productId = $line['product_id'];
                            $name = $line['name'];
                            $type = $line['type'];
                            $components = $line['components'];
                            $quantity = $line['quantity'];
                            $unitPrice = $line['unit_price'];
                            $lineTotal = $line['line_total'];
                            mysqli_stmt_bind_param(
                                $lineStatement,
                                'iisssidd',
                                $orderId, $productId, $name, $type, $components, $quantity, $unitPrice, $lineTotal
                            );
                            if (!mysqli_stmt_execute($lineStatement)) {
                                error_log('Unable to save customer order item: ' . mysqli_stmt_error($lineStatement));
                                $error = 'Unable to save your order items. Please try again.';
                                break;
                            }
                        }
                        mysqli_stmt_close($lineStatement);
                    }
                }
            }

            if ($error === '') {
                if (mysqli_commit($conn)) {
                    $_SESSION['customer_cart_after_order'] = array_values(array_filter(
                        $restoreCart,
                        static fn ($cartItem): bool => is_array($cartItem)
                            && array_key_exists('selected', $cartItem)
                            && $cartItem['selected'] === false
                    ));
                    $_SESSION['customer_order_notice'] = 'Order #' . $orderId . ' placed successfully. Total: ₱' . number_format($totalCents / 100, 2) . '.';
                    customerRedirect('my-orders');
                }
                error_log('Unable to commit customer order: ' . mysqli_error($conn));
                $error = 'Unable to complete your order right now. Please try again.';
            }
            mysqli_rollback($conn);
        } elseif ($error === '') {
            error_log('Unable to start customer order transaction: ' . mysqli_error($conn));
            $error = 'Unable to start checkout right now. Please try again.';
        }
        $_SESSION['customer_order_token'] = bin2hex(random_bytes(32));
        $customerError = $error;
    } else {
        http_response_code(400);
        exit('Unknown customer action.');
    }
}

if (isset($_SESSION['customer_order_notice']) && is_string($_SESSION['customer_order_notice'])) {
    $customerSuccess = $_SESSION['customer_order_notice'];
    unset($_SESSION['customer_order_notice']);
}

$customerId = isset($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : 0;
$customerName = isset($_SESSION['customer_name']) && is_string($_SESSION['customer_name']) ? $_SESSION['customer_name'] : '';
$products = [];
$inventory = [];
$productReviews = [];
$productReviewsError = '';
$discountedProduct = null;
$bestSellerProduct = null;
$featuredProducts = [];
$catalogError = '';
$productResult = mysqli_query(
    $conn,
    'SELECT products.ProductID, products.ProductName, products.Description, products.Price, products.ProductDiscountPercent,
            products.ProductCategories, products.ProductStock, products.Featured,
            COALESCE(ratings.AverageRating, 0) AS AverageRating, COALESCE(ratings.RatingCount, 0) AS RatingCount
     FROM tblproducts AS products
     LEFT JOIN (
         SELECT ProductID, AVG(Rating) AS AverageRating, COUNT(*) AS RatingCount
         FROM tblproductratings
         GROUP BY ProductID
     ) AS ratings ON ratings.ProductID = products.ProductID
     ORDER BY products.ProductID DESC'
);
if ($productResult === false) {
    error_log('Unable to load customer catalog: ' . mysqli_error($conn));
    $catalogError = 'The catalog is temporarily unavailable.';
} else {
    while ($product = mysqli_fetch_assoc($productResult)) {
        $product['OriginalPrice'] = (float) $product['Price'];
        $product['DiscountPercent'] = (float) $product['ProductDiscountPercent'];
        $product['Price'] = round(
            (float) $product['OriginalPrice'] * (1 - (float) $product['DiscountPercent'] / 100),
            2
        );
        $decodedCategories = json_decode((string) $product['ProductCategories'], true);
        $allowedCategories = ["Valentine's Day", "Mother's Day", 'Birthday', 'Anniversary', 'Christmas', 'Graduation', 'Get Well Soon', 'Just Because'];
        $product['Categories'] = is_array($decodedCategories)
            ? array_values(array_filter(
                $decodedCategories,
                static fn ($category): bool => is_string($category) && in_array($category, $allowedCategories, true)
            ))
            : [];
        $product['image'] = '';
        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $extension) {
            $relativePath = 'uploads/products/' . (int) $product['ProductID'] . '.' . $extension;
            if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
                $product['image'] = $relativePath;
                break;
            }
        }
        if ((int) $product['Featured'] === 1) {
            $featuredProducts[] = $product;
        }
        if (
            (float) $product['DiscountPercent'] > 0
            && ($discountedProduct === null || (float) $product['DiscountPercent'] > (float) $discountedProduct['DiscountPercent'])
        ) {
            $discountedProduct = $product;
        }
        $products[] = $product;
    }
    mysqli_free_result($productResult);
}
$productIds = array_map(static fn (array $product): int => (int) $product['ProductID'], $products);
if ($productIds !== []) {
    $productReviewIndexes = [];
    $productReviewsResult = mysqli_query(
        $conn,
        'SELECT ratings.RatingID, ratings.ProductID, ratings.Rating, ratings.Review, ratings.CreatedAt,
                customers.FirstName, customers.LastName
         FROM tblproductratings AS ratings
         INNER JOIN tblcustomers AS customers ON customers.CustomerID = ratings.CustomerID
         WHERE ratings.ProductID IN (' . implode(',', $productIds) . ')
         ORDER BY ratings.CreatedAt DESC, ratings.RatingID DESC'
    );
    if ($productReviewsResult === false) {
        error_log('Unable to load customer product reviews: ' . mysqli_error($conn));
        $productReviewsError = 'Customer reviews are temporarily unavailable.';
    } else {
        while ($review = mysqli_fetch_assoc($productReviewsResult)) {
            $productId = (int) $review['ProductID'];
            if (!isset($productReviews[$productId])) {
                $productReviews[$productId] = [];
            }
            $productReviewIndexes[(int) $review['RatingID']] = [
                'product_id' => $productId,
                'review_index' => count($productReviews[$productId]),
            ];
            $productReviews[$productId][] = [
                'rating' => (int) $review['Rating'],
                'review' => (string) $review['Review'],
                'reviewer' => trim((string) $review['FirstName'] . ' ' . (string) $review['LastName']),
                'date' => date('M j, Y', strtotime((string) $review['CreatedAt'])),
                'created_at' => (string) $review['CreatedAt'],
                'images' => [],
            ];
        }
        mysqli_free_result($productReviewsResult);
        if ($productReviewIndexes !== []) {
            $reviewImagesResult = mysqli_query(
                $conn,
                'SELECT images.RatingID, images.ImagePath
                 FROM tblproductratingimages AS images
                 INNER JOIN tblproductratings AS ratings ON ratings.RatingID = images.RatingID
                 WHERE ratings.ProductID IN (' . implode(',', $productIds) . ')
                 ORDER BY images.RatingImageID ASC'
            );
            if ($reviewImagesResult === false) {
                error_log('Unable to load customer product review images: ' . mysqli_error($conn));
                $productReviewsError = 'Customer review images are temporarily unavailable.';
            } else {
                while ($image = mysqli_fetch_assoc($reviewImagesResult)) {
                    $ratingId = (int) $image['RatingID'];
                    if (!isset($productReviewIndexes[$ratingId])) {
                        continue;
                    }
                    $reviewIndex = $productReviewIndexes[$ratingId];
                    $productReviews[$reviewIndex['product_id']][$reviewIndex['review_index']]['images'][] = (string) $image['ImagePath'];
                }
                mysqli_free_result($reviewImagesResult);
            }
        }
    }
}
$bestSellerResult = mysqli_query(
    $conn,
    "SELECT ProductID, SUM(Quantity) AS UnitsSold
     FROM (
         SELECT sd.ProductID, sd.Quantity
         FROM tblsaledetails sd
         INNER JOIN tblsales s ON s.SaleID = sd.SaleID
         WHERE sd.ProductID > 0 AND s.Status IN ('Completed', 'Delivered')
         UNION ALL
         SELECT oi.ProductID, oi.Quantity
         FROM tblcustomerorderitems oi
         INNER JOIN tblcustomerorders o ON o.OrderID = oi.OrderID
         WHERE oi.ItemType = 'catalog' AND oi.ProductID IS NOT NULL AND o.Status IN ('Delivered', 'Received')
     ) AS completed_product_sales
     GROUP BY ProductID
     ORDER BY UnitsSold DESC, ProductID DESC
     LIMIT 1"
);
if ($bestSellerResult === false) {
    error_log('Unable to determine customer shop best seller: ' . mysqli_error($conn));
    $catalogError = 'The featured collection is temporarily unavailable.';
} else {
    $bestSellerRow = mysqli_fetch_assoc($bestSellerResult);
    mysqli_free_result($bestSellerResult);
    if ($bestSellerRow !== null) {
        foreach ($products as $product) {
            if ((int) $product['ProductID'] === (int) $bestSellerRow['ProductID']) {
                $bestSellerProduct = $product;
                break;
            }
        }
    }
}
$bestSellerProductId = $bestSellerProduct !== null ? (int) $bestSellerProduct['ProductID'] : 0;
usort($products, static function (array $left, array $right) use ($bestSellerProductId): int {
    $leftRank = (int) $left['Featured'] === 1
        ? 0
        : (($bestSellerProductId > 0 && (int) $left['ProductID'] === $bestSellerProductId)
            ? 1
            : ((float) $left['DiscountPercent'] > 0 ? 2 : 3));
    $rightRank = (int) $right['Featured'] === 1
        ? 0
        : (($bestSellerProductId > 0 && (int) $right['ProductID'] === $bestSellerProductId)
            ? 1
            : ((float) $right['DiscountPercent'] > 0 ? 2 : 3));
    $rankOrder = $leftRank <=> $rightRank;
    if ($rankOrder !== 0) {
        return $rankOrder;
    }
    if ($leftRank === 2) {
        $discountOrder = (float) $right['DiscountPercent'] <=> (float) $left['DiscountPercent'];
        if ($discountOrder !== 0) {
            return $discountOrder;
        }
    }
    return (int) $right['ProductID'] <=> (int) $left['ProductID'];
});
$catalogFilters = ['all' => 'All products'];
if (array_filter($products, static fn (array $product): bool => (int) $product['Featured'] === 1) !== []) {
    $catalogFilters['featured'] = 'Featured';
}
if ($bestSellerProductId > 0) {
    $catalogFilters['bestseller'] = 'Best seller';
}
if (array_filter($products, static fn (array $product): bool => (float) $product['DiscountPercent'] > 0) !== []) {
    $catalogFilters['discounted'] = 'Discounted';
}
$catalogEventOptions = [];
foreach ($products as $product) {
    foreach ($product['Categories'] as $eventCategory) {
        $catalogEventOptions[$eventCategory] = $eventCategory;
    }
}
$heroSlides = [
    [
        'type' => 'discount',
        'label' => 'Special offer',
        'product' => $discountedProduct,
        'emptyCopy' => 'No discounted arrangements are available right now. Check back soon for a special offer.',
    ],
    [
        'type' => 'bestseller',
        'label' => 'Customer favorite',
        'product' => $bestSellerProduct,
        'emptyCopy' => 'Our best seller will appear here after the first completed product sale.',
    ],
    [
        'type' => 'custom',
        'label' => 'Made by you',
        'product' => null,
        'emptyCopy' => 'Choose your favorite flowers, wrappers, and ribbon to create a bouquet that feels like you.',
    ],
];
$heroSlides[] = [
    'type' => 'featured',
    'label' => 'Featured by our florist',
    'product' => null,
    'products' => array_slice($featuredProducts, 0, 3),
    'emptyCopy' => 'A featured arrangement is coming soon. Check back for our florist’s pick.',
];
$inventoryResult = mysqli_query(
    $conn,
    "SELECT ItemID, ItemName, Category, Color, Price, Stock
     FROM tblinventory
     WHERE Category IN ('Flower', 'Wrapper', 'Ribbon', 'Addons') AND Stock > 0
     ORDER BY Category, ItemName"
);
if ($inventoryResult === false) {
    error_log('Unable to load customer customization inventory: ' . mysqli_error($conn));
    $catalogError = 'Customization options are temporarily unavailable.';
} else {
    while ($component = mysqli_fetch_assoc($inventoryResult)) {
        $component['image'] = '';
        foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
            $relativePath = 'uploads/inventory/' . (int) $component['ItemID'] . '.' . $extension;
            if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
                $component['image'] = $relativePath;
                break;
            }
        }
        $inventory[] = $component;
    }
    mysqli_free_result($inventoryResult);
}
$customerOrders = [];
$orderHistoryError = '';
if ($customerId > 0) {
    $orderHistoryStatement = mysqli_prepare(
        $conn,
        'SELECT recent.OrderID, recent.OrderDate, recent.FulfillmentType, recent.FulfillmentDate,
                recent.FulfillmentTime, recent.PaymentMethod, recent.PaymentStatus, recent.Status,
                recent.TotalAmount, items.OrderItemID, items.ProductID, items.ProductName, items.Quantity,
                items.LineTotal, ratings.Rating, ratings.Review
         FROM (
             SELECT OrderID, OrderDate, FulfillmentType, FulfillmentDate, FulfillmentTime,
                    PaymentMethod, PaymentStatus, Status, TotalAmount
             FROM tblcustomerorders
             WHERE CustomerID = ?
             ORDER BY OrderDate DESC, OrderID DESC
             LIMIT 30
         ) AS recent
         LEFT JOIN tblcustomerorderitems AS items ON items.OrderID = recent.OrderID
         LEFT JOIN tblproductratings AS ratings ON ratings.OrderItemID = items.OrderItemID
         ORDER BY recent.OrderDate DESC, recent.OrderID DESC, items.OrderItemID ASC'
    );
    if ($orderHistoryStatement === false) {
        error_log('Unable to prepare customer order history: ' . mysqli_error($conn));
        $orderHistoryError = 'Your order history is temporarily unavailable.';
    } else {
        $historyCustomerId = $customerId;
        if (
            !mysqli_stmt_bind_param($orderHistoryStatement, 'i', $historyCustomerId)
            || !mysqli_stmt_execute($orderHistoryStatement)
            || !mysqli_stmt_bind_result(
                $orderHistoryStatement,
                $historyOrderId,
                $historyOrderDate,
                $historyFulfillmentType,
                $historyFulfillmentDate,
                $historyFulfillmentTime,
                $historyPaymentMethod,
                $historyPaymentStatus,
                $historyStatus,
                $historyTotalAmount,
                $historyOrderItemId,
                $historyProductId,
                $historyItemName,
                $historyItemQuantity,
                $historyItemTotal,
                $historyItemRating,
                $historyItemReview
            )
        ) {
            error_log('Unable to load customer order history: ' . mysqli_stmt_error($orderHistoryStatement));
            $orderHistoryError = 'Your order history is temporarily unavailable.';
        } else {
            while (true) {
                $fetchResult = mysqli_stmt_fetch($orderHistoryStatement);
                if ($fetchResult === null || ($fetchResult === false && mysqli_stmt_errno($orderHistoryStatement) === 0)) {
                    break;
                }
                if ($fetchResult === false) {
                    error_log('Unable to fetch customer order history: ' . mysqli_stmt_error($orderHistoryStatement));
                    $customerOrders = [];
                    $orderHistoryError = 'Your order history is temporarily unavailable.';
                    break;
                }
                $orderId = (int) $historyOrderId;
                if (!isset($customerOrders[$orderId])) {
                    $customerOrders[$orderId] = [
                        'OrderID' => $orderId,
                        'OrderDate' => (string) $historyOrderDate,
                        'FulfillmentType' => (string) $historyFulfillmentType,
                        'FulfillmentDate' => (string) $historyFulfillmentDate,
                        'FulfillmentTime' => (string) $historyFulfillmentTime,
                        'PaymentMethod' => (string) $historyPaymentMethod,
                        'PaymentStatus' => (string) $historyPaymentStatus,
                        'Status' => (string) $historyStatus,
                        'TotalAmount' => (string) $historyTotalAmount,
                        'items' => [],
                    ];
                }
                if ($historyItemName !== null) {
                    $customerOrders[$orderId]['items'][] = [
                        'order_item_id' => (int) $historyOrderItemId,
                        'product_id' => $historyProductId !== null ? (int) $historyProductId : null,
                        'name' => (string) $historyItemName,
                        'quantity' => (int) $historyItemQuantity,
                        'total' => (string) $historyItemTotal,
                        'rating' => $historyItemRating !== null ? (int) $historyItemRating : null,
                        'review' => (string) ($historyItemReview ?? ''),
                        'images' => [],
                    ];
                }
            }
        }
        mysqli_stmt_close($orderHistoryStatement);
        if ($orderHistoryError === '' && $customerOrders !== []) {
            $ratingImagesStatement = mysqli_prepare(
                $conn,
                'SELECT items.OrderID, ratings.OrderItemID, images.ImagePath
                 FROM tblproductratingimages images
                 INNER JOIN tblproductratings ratings ON ratings.RatingID = images.RatingID
                 INNER JOIN tblcustomerorderitems items ON items.OrderItemID = ratings.OrderItemID
                 INNER JOIN (
                     SELECT OrderID
                     FROM tblcustomerorders
                     WHERE CustomerID = ?
                     ORDER BY OrderDate DESC, OrderID DESC
                     LIMIT 30
                 ) recent ON recent.OrderID = items.OrderID
                 ORDER BY items.OrderID DESC, images.RatingImageID ASC'
            );
            if ($ratingImagesStatement === false) {
                error_log('Unable to prepare customer product review images: ' . mysqli_error($conn));
                $orderHistoryError = 'Your review images are temporarily unavailable.';
            } else {
                if (
                    !mysqli_stmt_bind_param($ratingImagesStatement, 'i', $historyCustomerId)
                    || !mysqli_stmt_execute($ratingImagesStatement)
                    || !mysqli_stmt_bind_result($ratingImagesStatement, $imageOrderId, $imageOrderItemId, $imagePath)
                ) {
                    error_log('Unable to load customer product review images: ' . mysqli_stmt_error($ratingImagesStatement));
                    $orderHistoryError = 'Your review images are temporarily unavailable.';
                } else {
                    while (true) {
                        $imageFetch = mysqli_stmt_fetch($ratingImagesStatement);
                        if ($imageFetch === null || ($imageFetch === false && mysqli_stmt_errno($ratingImagesStatement) === 0)) {
                            break;
                        }
                        if ($imageFetch === false) {
                            error_log('Unable to fetch customer product review images: ' . mysqli_stmt_error($ratingImagesStatement));
                            $orderHistoryError = 'Your review images are temporarily unavailable.';
                            break;
                        }
                        if (isset($customerOrders[(int) $imageOrderId])) {
                            foreach ($customerOrders[(int) $imageOrderId]['items'] as &$orderItem) {
                                if ($orderItem['order_item_id'] === (int) $imageOrderItemId) {
                                    $orderItem['images'][] = (string) $imagePath;
                                    break;
                                }
                            }
                            unset($orderItem);
                        }
                    }
                }
                mysqli_stmt_close($ratingImagesStatement);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Flower Shop | Customer Store</title>
    <link rel="stylesheet" href="bootstrap-5.3.8/css/bootstrap.min.css">
    <link rel="stylesheet" href="password-reset.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;1,500&display=swap" rel="stylesheet">
    <style>
        :root { --admin-blue: #ad456e; --admin-blue-dark: #762943; --admin-blue-soft: #f7dce7; --admin-canvas: #fbf3f5; --admin-border: #e7c5d1; --admin-text: #35232c; }
        body { min-height: 100vh; background: var(--admin-canvas); color: var(--admin-text); font-family: "DM Sans", "Segoe UI", sans-serif; }
        .store-nav { background: rgba(255, 252, 249, .97); border-bottom: 1px solid var(--admin-border); box-shadow: 0 .3rem 1rem rgba(66, 31, 46, .12); }
        .brand { color: var(--admin-blue); font-size: 1.35rem; font-weight: 700; letter-spacing: -.03em; }
        .brand:hover { color: var(--admin-blue-dark); }
        .store-nav-inner { min-height: 68px; flex-wrap: wrap; }
        .store-nav-links, .store-nav-actions { display: flex; align-items: center; gap: .65rem; }
        .store-nav-link { position: relative; display: inline-flex; width: 52px; height: 52px; align-items: center; justify-content: center; padding: .45rem; border: 1px solid transparent; border-radius: .65rem; background: transparent; color: #543c47; font-family: inherit; text-decoration: none; cursor: pointer; transition: color .18s ease, background-color .18s ease, border-color .18s ease, transform .18s ease; }
        .store-nav-link:hover, .store-nav-link:focus-visible { color: var(--admin-blue-dark); background: var(--admin-blue-soft); border-color: #d8aeba; outline: none; }
        .store-nav-link:hover svg { transform: scale(1.08); }
        .store-nav-link:focus-visible { box-shadow: 0 0 0 .2rem rgba(36, 90, 155, .15); }
        .store-nav-link svg { display: block; width: 32px; height: 32px; flex: 0 0 32px; align-self: center; margin: auto; fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; vector-effect: non-scaling-stroke; transition: transform .18s ease; }
        .store-nav-link .nav-count { position: absolute; top: 0; right: 0; min-width: 18px; height: 18px; padding: 0 .25rem; border: 2px solid #fff; border-radius: 999px; background: var(--admin-blue); color: #fff; font-size: .65rem; line-height: 14px; text-align: center; }
        .store-nav-link .nav-count:empty { display: none; }
        .store-search-wrap { position: relative; z-index: 1; width: min(100% - 2rem, 720px); margin: 1rem auto 0; }
        .store-search-bar { display: flex; align-items: center; gap: .75rem; min-height: 58px; padding: .45rem .55rem .45rem 1.1rem; border: 1px solid #d9b5c1; border-radius: 999px; background: #fffdfa; box-shadow: 0 .7rem 1.5rem rgba(66, 31, 46, .14); }
        .store-search-bar svg { width: 22px; height: 22px; flex: 0 0 22px; fill: none; stroke: #58404a; stroke-width: 1.8; stroke-linecap: round; }
        .store-search-bar input { width: 100%; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--admin-text); font: inherit; }
        .store-search-bar input::placeholder { color: #705c64; }
        .store-search-button { min-height: 44px; padding: .55rem 1.25rem; border: 0; border-radius: 999px; background: var(--admin-blue); color: #fff; font: inherit; font-weight: 600; }
        .store-main { padding-bottom: 1.5rem !important; }
        .store-footer { margin-top: 3rem; padding: 3rem 0 0; border-top: 1px solid #dfb8c6; background-color: #f3e0e7; background-image: repeating-linear-gradient(135deg, rgba(118, 41, 67, .035) 0, rgba(118, 41, 67, .035) 1px, transparent 1px, transparent 10px); }
        .store-footer-section { min-width: 0; }
        .store-footer-title { margin: 0 0 .85rem; color: var(--admin-text); font-size: .9rem; font-weight: 600; }
        .store-footer-brand { color: var(--admin-text); font-family: "Playfair Display", Georgia, serif; font-size: 1.35rem; font-weight: 600; letter-spacing: -.04em; text-decoration: none; }
        .store-footer-brand:hover { color: var(--admin-blue-dark); }
        .store-footer-copy, .store-footer-contact { margin: .4rem 0 0; color: #59444c; font-size: .78rem; line-height: 1.6; }
        .store-footer-links { display: flex; flex-direction: column; align-items: flex-start; gap: .55rem; }
        .store-footer-links a, .store-footer-links button, .store-footer-contact a, .store-footer-link { padding: 0; border: 0; background: none; color: #59444c; font: inherit; font-size: .8rem; text-decoration: none; cursor: pointer; }
        .store-footer-links a:hover, .store-footer-links button:hover, .store-footer-contact a:hover, .store-footer-link:hover { color: var(--admin-blue-dark); }
        .store-footer-bottom { margin: 0; padding: 1rem 0; border-top: 1px solid #d8b2bf; color: #59444c; font-size: .75rem; }
        .customize-floating-button { position: fixed; z-index: 1020; left: 8.5rem; bottom: max(1.25rem, env(safe-area-inset-bottom)); display: inline-flex; width: 84px; min-width: 84px; height: 84px; align-items: center; justify-content: center; margin: 0; padding: 1rem; border: 1px solid #bfd7f5; border-radius: 1rem; background: #fff; color: var(--admin-blue); box-shadow: 0 .5rem 1.5rem rgba(24, 56, 91, .2); }
        .customize-floating-button[hidden] { display: none; }
        .customize-floating-button svg { width: 40px; height: 40px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .customer-cart-page { min-height: calc(100vh - 68px); padding: 2rem 0 4rem; }
        .cart-page-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
        .cart-page-header h1 { margin: 0; color: var(--admin-blue); font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 600; }
        .cart-page-layout { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(320px, .85fr); align-items: start; gap: 1.25rem; }
        .cart-page-card { padding: 1.25rem; border: 1px solid var(--admin-border); border-radius: .8rem; background: #fff; box-shadow: 0 .25rem 1rem rgba(36, 90, 155, .06); }
        .cart-page-card h2 { margin: 0 0 1rem; color: var(--admin-blue); font-size: 1.15rem; font-weight: 600; }
        .customer-order-card { padding: 1.25rem; border: 1px solid var(--admin-border); border-radius: .8rem; background: #fff; box-shadow: 0 .25rem 1rem rgba(36, 90, 155, .06); }
        .customer-order-status { display: inline-block; padding: .35rem .7rem; border-radius: 999px; background: var(--admin-blue-soft); color: var(--admin-blue-dark); font-size: .85rem; font-weight: 700; }
        .customer-order-item { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-bottom: 1px solid var(--admin-border); }
        .customer-order-item:last-child { border-bottom: 0; }
        .cart-dialog-items { max-height: 48vh; overflow-y: auto; }
        .cart-line-item { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: .7rem; padding: .9rem; border: 1px solid var(--admin-border); border-radius: .65rem; background: #f8fbff; }
        .cart-line-item:last-child { margin-bottom: 0; }
        .cart-addons { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .65rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--admin-border); }
        .cart-addon-card { display: flex; min-width: 0; flex-direction: column; gap: .45rem; padding: .75rem; border: 1px solid var(--admin-border); border-radius: .6rem; background: #fff; }
        .cart-addon-card strong { overflow: hidden; color: var(--admin-text); text-overflow: ellipsis; white-space: nowrap; }
        .customer-addons-dialog { width: min(92vw, 760px); max-height: 85vh; padding: 1.25rem; border: 1px solid var(--admin-border); border-radius: .85rem; color: var(--admin-text); box-shadow: 0 1rem 3rem rgba(24, 56, 91, .2); }
        .customer-addons-dialog::backdrop { background: rgba(24, 42, 62, .55); backdrop-filter: blur(2px); }
        .customer-addons-dialog-content { display: flex; max-height: calc(85vh - 2.5rem); flex-direction: column; gap: 1rem; overflow-y: auto; }
        .customer-addons-dialog-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .customer-addons-dialog-header h2 { margin: 0; color: var(--admin-blue); font-size: 1.3rem; font-weight: 600; }
        .customer-addons-dialog .cart-addons { margin-top: 0; padding-top: 0; border-top: 0; }
        .cart-checkout-form { display: flex; flex-direction: column; gap: 1rem; }
        .cart-checkout-form .delivery-details-fields { max-height: 32vh; overflow-y: auto; }
        .cart-fulfillment-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
        .cart-fulfillment-option { display: flex; align-items: center; gap: .5rem; min-height: 52px; margin: 0; padding: .75rem; border: 1px solid #d5e2f1; border-radius: .55rem; background: #f8fbff; }
        .cart-fulfillment-option:has(input:checked) { border-color: var(--admin-blue); background: var(--admin-blue-soft); }
        .cart-total-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1rem; border-top: 1px solid var(--admin-border); font-weight: 700; }
        .customer-customize-dialog { width: min(96vw, 1160px); height: min(86vh, 820px); max-height: 94vh; padding: 1.5rem; border: 1px solid var(--admin-border); border-radius: .85rem; color: var(--admin-text); box-shadow: 0 1rem 3rem rgba(24, 56, 91, .2); }
        .customer-customize-dialog::backdrop { background: rgba(24, 42, 62, .55); backdrop-filter: blur(2px); }
        .customer-customize-form { display: flex; height: 100%; flex-direction: column; gap: 1rem; }
        .customer-customize-header { display: flex; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: 1rem; }
        .customer-customize-header h2 { margin: 0; color: var(--admin-blue); font-size: 1.4rem; }
        .customer-customize-close { display: inline-flex; width: 38px; height: 38px; align-items: center; justify-content: center; border: 1px solid var(--admin-border); border-radius: .55rem; background: #fff; color: #536b85; font-size: 1.4rem; }
        .customer-customize-close:hover { background: var(--admin-blue-soft); color: var(--admin-blue); }
        .customer-component-builder { display: grid; grid-template-columns: minmax(0, 1fr) minmax(270px, 320px); gap: 1rem; flex: 1 1 auto; min-height: 0; overflow: hidden; padding: 1rem; border: 1px solid var(--admin-border); border-radius: .75rem; background: #f8fbff; }
        .customer-component-picker { display: flex; min-width: 0; min-height: 0; flex-direction: column; }
        .customer-category-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .85rem; }
        .customer-category-tab { min-height: 40px; border: 1px solid #bfd7f5; border-radius: .5rem; background: #fff; color: var(--admin-blue); font-weight: 600; padding: .4rem .75rem; }
        .customer-category-tab.active, .customer-category-tab:hover { background: var(--admin-blue); color: #fff; }
        .customer-category-panel[hidden] { display: none; }
        .customer-category-panel:not([hidden]) { display: flex; min-height: 0; flex: 1 1 auto; flex-direction: column; }
        .customer-component-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); grid-auto-rows: 116px; gap: .65rem; min-height: 0; overflow-y: auto; padding: .15rem; }
        .customer-component-card { position: relative; display: grid; grid-template-columns: 72px minmax(0, 1fr); align-items: center; gap: .65rem; height: 116px; min-width: 0; padding: .5rem; border: 1px solid #d7e3f1; border-radius: .65rem; background: #fff; cursor: pointer; transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease; }
        .customer-component-card:hover { border-color: #8ab8ed; box-shadow: 0 .2rem .6rem rgba(52,112,185,.12); }
        .customer-component-card.selected { border-color: var(--admin-blue); background: #eaf3ff; box-shadow: 0 0 0 2px rgba(36,90,155,.14); }
        .customer-component-card input { position: absolute; top: .5rem; right: .5rem; width: 1rem; height: 1rem; margin: 0; accent-color: var(--admin-blue); }
        .customer-component-card img, .customer-component-placeholder { display: flex; width: 72px; height: 72px; align-items: center; justify-content: center; border-radius: .45rem; background: #eaf3ff; color: #6c829c; object-fit: cover; }
        .customer-component-copy { display: flex; min-width: 0; flex-direction: column; gap: .15rem; padding-right: .75rem; color: #53749b; font-size: .75rem; }
        .customer-component-copy strong { overflow: hidden; color: #26364a; font-size: .84rem; text-overflow: ellipsis; white-space: nowrap; }
        .customer-selected-items { display: flex; min-height: 0; flex-direction: column; gap: .7rem; overflow: hidden; padding: .9rem; border: 1px solid #d7e3f1; border-radius: .65rem; background: #fff; }
        .customer-selected-list { display: flex; flex: 1 1 auto; min-height: 0; flex-direction: column; gap: .5rem; overflow-y: auto; }
        .customer-selected-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .4rem; padding: .6rem; border-radius: .45rem; background: #f1f6fd; font-size: .82rem; }
        .customer-selected-row strong { display: block; overflow-wrap: anywhere; }
        .customer-selected-row small { color: #687b91; }
        .customer-selected-controls { display: flex; align-items: center; gap: .3rem; }
        .customer-selected-controls button { width: 27px; height: 27px; border: 1px solid #b8cce3; border-radius: .35rem; background: #fff; color: var(--admin-blue); }
        .customer-selected-controls button:last-child { margin-left: .2rem; border-color: #edc5ca; color: #b02a37; }
        .customer-selected-controls output { min-width: 24px; text-align: center; }
        .customer-customize-footer { display: flex; flex: 0 0 auto; align-items: center; justify-content: space-between; gap: 1rem; }
        .customer-customize-footer .form-control { max-width: 280px; }
        @media (max-width: 850px) {
            .customer-customize-dialog { height: min(90vh, 860px); }
            .customer-component-builder { grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(230px, 1.2fr) minmax(150px, .8fr); overflow-y: auto; }
        }
        @media (max-width: 576px) {
            .customer-cart-page { padding: 1rem 0 2rem; }
            .cart-page-layout { grid-template-columns: minmax(0, 1fr); gap: .75rem; }
            .cart-page-card { padding: 1rem; }
            .cart-dialog-items { max-height: none; }
            .cart-checkout-form .delivery-details-fields { max-height: none; }
            .customer-customize-dialog { position: fixed; inset: 0; width: 100%; height: 100vh; height: 100dvh; max-width: none; max-height: 100vh; max-height: 100dvh; margin: 0; overflow-x: hidden; overflow-y: auto; padding: .75rem .75rem calc(.75rem + env(safe-area-inset-bottom)); border: 0; border-radius: 0; }
            .customer-customize-form { min-height: 100%; height: auto; gap: .75rem; }
            .customer-customize-header { position: sticky; z-index: 3; top: -.75rem; margin: -.75rem -.75rem 0; padding: .75rem; border-bottom: 1px solid var(--admin-border); background: rgba(255, 255, 255, .97); }
            .customer-customize-header h2 { font-size: 1.2rem; }
            .customer-component-builder { display: flex; flex: 0 0 auto; flex-direction: column; gap: .85rem; overflow: visible; padding: .75rem; }
            .customer-component-picker { min-height: auto; }
            .customer-category-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .45rem; margin-bottom: .75rem; }
            .customer-category-tab { min-height: 44px; }
            .customer-category-panel:not([hidden]) { min-height: auto; flex: 0 0 auto; }
            .customer-component-grid { grid-template-columns: minmax(0, 1fr); grid-auto-rows: 96px; gap: .55rem; max-height: 30vh; max-height: 30dvh; overflow-y: auto; overscroll-behavior: contain; padding: .15rem; }
            .customer-component-card { grid-template-columns: 68px minmax(0, 1fr); height: 96px; gap: .65rem; padding: .6rem; }
            .customer-component-card img, .customer-component-placeholder { width: 68px; height: 68px; }
            .customer-component-copy { gap: .2rem; padding-right: 1.25rem; font-size: .75rem; }
            .customer-component-copy strong { font-size: .85rem; }
            .customer-selected-items { flex: 0 0 auto; min-height: 185px; max-height: 30vh; max-height: 30dvh; padding: .75rem; }
            .customer-selected-list { min-height: 48px; }
            .customer-customize-footer { position: sticky; z-index: 3; bottom: calc(-.75rem - env(safe-area-inset-bottom)); align-items: stretch; flex-direction: column; gap: .65rem; margin: 0 -.75rem calc(-.75rem - env(safe-area-inset-bottom)); padding: .75rem .75rem calc(.75rem + env(safe-area-inset-bottom)); border-top: 1px solid var(--admin-border); background: rgba(255, 255, 255, .98); }
            .customize-floating-button { left: 6rem; bottom: max(.75rem, env(safe-area-inset-bottom)); width: 68px; min-width: 68px; height: 68px; padding: .75rem; }
            .customize-floating-button svg { width: 32px; height: 32px; }
            .customer-customize-footer .form-control { max-width: none; }
        }
        .hero { background-color: #fff7fa; background-image: radial-gradient(circle at 1px 1px, rgba(118, 41, 67, .055) 1px, transparent 1.5px), linear-gradient(135deg, rgba(255, 255, 255, .75), rgba(247, 220, 231, .6)); background-size: 14px 14px, auto; border: 1px solid #dfb8c6; border-radius: 1rem; box-shadow: 0 .9rem 2rem rgba(66, 31, 46, .12); }
        .store-hero-slide { min-height: 27rem; padding: .5rem 2.5rem 2.25rem; }
        .store-hero-carousel .carousel-indicators { bottom: 0; margin-bottom: 0; }
        .store-hero-carousel .carousel-indicators [data-bs-target] { width: 1.8rem; height: .25rem; border: 0; border-radius: 1rem; background-color: var(--admin-blue); }
        .store-hero-carousel .carousel-control-prev, .store-hero-carousel .carousel-control-next { width: 2.25rem; opacity: .7; }
        .store-hero-carousel .carousel-control-prev { left: -.5rem; }
        .store-hero-carousel .carousel-control-next { right: -.5rem; }
        .store-hero-carousel .carousel-control-prev-icon, .store-hero-carousel .carousel-control-next-icon { filter: invert(37%) sepia(23%) saturate(1170%) hue-rotate(285deg) brightness(88%) contrast(86%); }
        .store-carousel-price { display: flex; align-items: baseline; gap: .75rem; margin-top: 1rem; color: var(--admin-blue-dark); font-size: 1.25rem; }
        .store-carousel-price del { color: #806f76; font-size: .95rem; }
        .hero h1, .section-title { color: var(--admin-text); font-family: "Playfair Display", Georgia, serif; font-weight: 500; letter-spacing: -.045em; }
        .hero .text-secondary, .text-secondary { color: #624c55 !important; }
        .product-card.card { background-color: #fffafd; background-image: linear-gradient(145deg, rgba(255, 255, 255, .92), rgba(249, 232, 238, .62)); border: 1px solid #e3c0cd !important; border-radius: .85rem; overflow: hidden; box-shadow: 0 .35rem 1rem rgba(66, 31, 46, .1); transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
        .product-card:hover { transform: translateY(-4px); border-color: #bd7c92 !important; box-shadow: 0 .85rem 1.6rem rgba(66, 31, 46, .17); }
        .product-card[tabindex] { cursor: pointer; }
        .product-card[tabindex]:focus-visible { outline: 3px solid var(--admin-blue); outline-offset: 3px; }
        #product-details-modal .modal-dialog { height: calc(100vh - 2rem); max-height: 46rem; }
        #product-details-modal .modal-content { height: 100%; min-height: 0; }
        #product-details-modal .modal-body { display: flex; min-height: 0; flex-direction: column; overflow: hidden; }
        .product-details-summary { flex: 0 0 auto; max-height: none; overflow: visible; }
        .product-details-review-section { display: flex; min-height: 0; flex: 1 1 0; flex-direction: column; }
        .product-details-image { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; border-radius: .65rem; background: #fff0f5; }
        .product-details-description { white-space: pre-line; }
        .product-details-reviews { display: grid; min-height: 0; flex: 1 1 0; align-content: start; gap: .75rem; overflow-y: auto; overscroll-behavior: contain; }
        .product-details-review { padding: .85rem; border: 1px solid var(--admin-border); border-radius: .65rem; background: #fff; }
        .product-details-review-stars { color: #b54f75; letter-spacing: .08em; }
        .product-details-review-images a { width: 4.5rem; height: 4.5rem; }
        @supports (height: 100dvh) {
            #product-details-modal .modal-dialog { height: min(46rem, calc(100dvh - 2rem)); }
        }
        @media (max-width: 576px) {
            #product-details-modal .modal-dialog { width: calc(100% - 1rem); height: calc(100vh - 1rem); margin: .5rem auto; }
            #product-details-modal .modal-header, #product-details-modal .modal-footer { padding: .75rem; }
            #product-details-modal .modal-body { padding: .75rem; }
            #product-details-modal .modal-title { font-size: 1.1rem; overflow-wrap: anywhere; }
            #product-details-modal .modal-footer { gap: .5rem; }
            #product-details-modal .modal-footer .btn { flex: 1 1 auto; }
            .product-details-summary { row-gap: .75rem !important; }
            .product-details-image { display: block; width: min(100%, 16rem, 35vh); margin: 0 auto; }
            .product-details-summary .col-md-5,
            .product-details-summary .col-md-7 { width: 100%; }
            .product-details-review { padding: .65rem; }
        }
        @supports (height: 100dvh) {
            @media (max-width: 576px) {
                #product-details-modal .modal-dialog { height: calc(100dvh - 1rem); }
                .product-details-image { width: min(100%, 16rem, 35dvh); }
            }
        }
        .catalog-filter-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; overflow: visible; margin: 0 0 1.25rem; padding: 0 0 .35rem; border-bottom: 1px solid #f1dce5; }
        .catalog-filter-button { flex: 0 0 auto; padding: .65rem .9rem; border: 0; border-bottom: 2px solid transparent; background: transparent; color: #806f76; font: inherit; font-size: .9rem; font-weight: 600; white-space: nowrap; cursor: pointer; }
        .catalog-filter-button:hover, .catalog-filter-button:focus-visible { color: var(--admin-blue-dark); }
        .catalog-filter-button.active { border-bottom-color: var(--admin-blue); color: var(--admin-blue-dark); }
        .catalog-events-dropdown { position: relative; flex: 0 0 auto; }
        .catalog-events-dropdown > summary { list-style: none; }
        .catalog-events-dropdown[open] > summary { border-bottom-color: var(--admin-blue); color: var(--admin-blue-dark); }
        .catalog-events-dropdown > summary::-webkit-details-marker { display: none; }
        .catalog-events-dropdown > summary { position: relative; min-width: 7.25rem; text-align: left; }
        .catalog-events-menu { position: absolute; z-index: 20; top: calc(100% + .35rem); left: 0; display: grid; min-width: 220px; gap: .35rem; padding: .75rem; border: 1px solid #f1dce5; border-radius: .7rem; background: #fff; box-shadow: 0 .65rem 1.5rem rgba(86, 49, 68, .15); }
        .catalog-events-menu[hidden] { display: none; }
        .catalog-event-option { display: flex; align-items: center; gap: .55rem; margin: 0; padding: .35rem .4rem; border-radius: .4rem; color: var(--admin-text); cursor: pointer; }
        .catalog-event-option:hover { background: #fff7fa; }
        .catalog-event-option input { margin: 0; accent-color: var(--admin-blue); }
        .catalog-events-clear { justify-self: start; padding: .35rem .4rem; border: 0; background: transparent; color: var(--admin-blue-dark); font: inherit; font-size: .82rem; font-weight: 600; cursor: pointer; }
        .catalog-rating-option { width: 100%; padding: .45rem .55rem; border: 0; border-radius: .4rem; background: transparent; color: var(--admin-text); font: inherit; text-align: left; cursor: pointer; }
        .catalog-rating-option:hover, .catalog-rating-option[aria-pressed="true"] { background: #fff7fa; color: var(--admin-blue-dark); }
        .product-rating-summary { margin: 0 0 .75rem; color: #624c55; font-size: .88rem; }
        .product-rating-stars { display: flex; align-items: center; gap: .25rem; }
        .product-rating-stars label { color: #b54f75; cursor: pointer; font-size: 1.35rem; line-height: 1; }
        .product-rating-stars input { accent-color: #b54f75; }
        .customer-order-rating { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .5rem 0; border-bottom: 1px solid var(--admin-border); }
        .customer-order-rating:last-child { border-bottom: 0; }
        .customer-order-rating .product-rating-stars label { font-size: 1.1rem; }
        .customer-rating-review { min-height: 14rem; resize: vertical; }
        .customer-review-image-list { display: flex; flex-wrap: wrap; gap: .5rem; }
        .customer-review-image-list a { display: block; width: 5rem; height: 5rem; overflow: hidden; border: 1px solid var(--admin-border); border-radius: .5rem; background: #fff7fa; }
        .customer-review-image-list img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .customer-rating-fieldset { margin: 0; padding: 0; border: 0; }
        .customer-rating-fieldset legend { float: none; width: auto; margin-bottom: .35rem; font-size: 1rem; }
        .customer-review-stars { display: flex; align-items: center; gap: .55rem; }
        .customer-review-stars label { color: #b7aab0; cursor: pointer; font-size: 2rem; line-height: 1; }
        .customer-review-stars[data-rating="1"] label:nth-child(-n+1) span,
        .customer-review-stars[data-rating="2"] label:nth-child(-n+2) span,
        .customer-review-stars[data-rating="3"] label:nth-child(-n+3) span,
        .customer-review-stars[data-rating="4"] label:nth-child(-n+4) span,
        .customer-review-stars[data-rating="5"] label:nth-child(-n+5) span { color: var(--admin-blue); }
        .customer-review-stars input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; }
        .customer-review-stars input:focus-visible + span { outline: 2px solid var(--admin-blue); outline-offset: 3px; border-radius: .2rem; }
        .catalog-item[hidden] { display: none !important; }
        .catalog-no-matches { padding: 1rem; border: 1px solid var(--admin-border); border-radius: .65rem; background: #fffdfa; color: #624c55; }
        .catalog-pagination .page-link { color: var(--admin-blue-dark); }
        .catalog-pagination .page-item.active .page-link { border-color: var(--admin-blue); background: var(--admin-blue); color: #fff; }
        .catalog-pagination .page-item.disabled .page-link { color: #806f76; }
        .catalog-pagination .page-item.active.disabled .page-link { color: #fff; opacity: 1; }
        .product-image { width: 100%; aspect-ratio: 1 / 1; height: auto; object-fit: cover; background: #fff0f5; }
        .placeholder-image { display: flex; width: 100%; aspect-ratio: 1 / 1; height: auto; flex-direction: column; justify-content: center; gap: .45rem; padding: 1rem; background: radial-gradient(circle at 50% 38%, #fff 0, #fff 10%, transparent 10.5%), linear-gradient(145deg,#fde4ed,#fffafd); color: #806f76; text-align: center; }
        .placeholder-image::before { color: var(--admin-blue); content: "✿"; font-size: 2.75rem; line-height: 1; }
        .product-card .card-body { padding: 1.15rem; }
        .product-card h3 { color: var(--admin-text); font-family: "Playfair Display", Georgia, serif; font-weight: 500; }
        .product-card .fs-5 { color: var(--admin-blue-dark); }
        .btn-rose { background: var(--admin-blue); border-color: var(--admin-blue); color: #fff; box-shadow: 0 .2rem .45rem rgba(112, 39, 68, .18); }
        .btn-rose:hover, .btn-rose:focus { background: var(--admin-blue-dark); border-color: var(--admin-blue-dark); color: #fff; }
        .btn-outline-secondary { border-color: #bfd7f5; color: var(--admin-blue); }
        .btn-outline-secondary:hover { background: var(--admin-blue-soft); border-color: #bfd7f5; color: var(--admin-blue-dark); }
        .cart-panel, .card { background: #fff; border: 1px solid var(--admin-border) !important; border-radius: .75rem; box-shadow: 0 .25rem 1rem rgba(36, 90, 155, .06); }
        .form-label { color: #344d69; font-weight: 600; }
        .form-control, .form-select { border-color: #d5e2f1; }
        .form-control:focus, .form-select:focus, .form-check-input:focus { border-color: #76a4d8; box-shadow: 0 0 0 .2rem rgba(36, 90, 155, .15); }
        .form-check-input:checked { background-color: var(--admin-blue); border-color: var(--admin-blue); }
        .nav-tabs { border-color: var(--admin-border); }
        .nav-tabs .nav-link { color: #5d7189; }
        .nav-tabs .nav-link.active { color: var(--admin-blue); border-color: var(--admin-border) var(--admin-border) #fff; font-weight: 600; }
        .modal-content { border: 1px solid var(--admin-border); border-radius: .75rem; box-shadow: 0 1rem 3rem rgba(52, 28, 39, .24); }
        .modal-header { border-bottom-color: var(--admin-border); }
        .modal-title { color: var(--admin-blue); font-weight: 600; }
        #signout-modal .modal-dialog { max-width: 320px; }
        #signout-modal .modal-content { overflow: hidden; border: 0; border-radius: .85rem; box-shadow: 0 1.25rem 3rem rgba(24, 56, 91, .24); }
        #signout-modal .modal-header { padding: .9rem 1rem .4rem; border: 0; }
        #signout-modal .modal-title { color: #26364a; font-size: 1rem; }
        #signout-modal .modal-body { padding: 0 1rem .65rem; color: #687b91; font-size: .9rem; line-height: 1.45; }
        #signout-modal .modal-footer { gap: .5rem; padding: .6rem 1rem .9rem; border: 0; }
        #signout-modal .modal-footer > * { margin: 0; }
        #signout-modal .modal-footer .btn { min-width: 88px; padding: .45rem .75rem; border-radius: .55rem; font-size: .875rem; font-weight: 600; }
        #signout-modal .btn-signout { background: #c45b43; border-color: #c45b43; color: #fff; }
        #signout-modal .btn-signout:hover, #signout-modal .btn-signout:focus-visible { background: #a94733; border-color: #a94733; color: #fff; }
        .table { --bs-table-bg: #fff; --bs-table-striped-bg: #f7faff; --bs-table-border-color: var(--admin-border); }
        .table thead th { background: #f7faff; color: #48627f; font-size: .85rem; font-weight: 600; }
        html { scroll-behavior: smooth; scroll-padding-top: 105px; }
        #catalog { scroll-margin-top: 105px; }
        @media (max-width: 767.98px) {
            .store-nav-inner { padding-top: .45rem; }
            .store-nav-links { order: 3; width: 100%; justify-content: space-between; padding: .35rem 0 .45rem; border-top: 1px solid var(--admin-border); }
            .store-nav-actions { margin-left: auto; }
            .store-nav-link { width: 48px; height: 48px; padding: .35rem; }
            .store-hero-slide { min-height: 0; padding: .25rem 1.5rem 2.25rem; }
            .store-hero-carousel .hero-photo { height: 17rem; }
        }
        @media (max-width: 850px) {
            .cart-page-layout { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 400px) {
            .store-nav-link { width: 44px; height: 44px; padding: .3rem; }
            .store-nav-link svg { width: 29px; height: 29px; flex-basis: 29px; }
            .brand { font-size: 1.15rem; }
            .store-search-button { padding-right: 1rem; padding-left: 1rem; }
            .store-hero-slide { padding-right: 1rem; padding-left: 1rem; }
            .store-hero-carousel .hero-photo { height: 14rem; }
        }
        .store-nav { border-bottom-color: #f4e3ea; box-shadow: 0 .25rem 1rem rgba(86, 49, 68, .05); }
        .brand, .store-footer-brand, .store-footer-title, .cart-page-header h1, .cart-page-card h2,
        .customer-addons-dialog-header h2, .customer-customize-header h2 { color: var(--admin-blue-dark); }
        .brand { font-family: "Playfair Display", Georgia, serif; font-weight: 600; }
        .store-nav-link { color: #806f76; border-radius: .8rem; }
        .store-nav-link:hover, .store-nav-link:focus-visible { border-color: #f1dce5; }
        .store-nav-link:focus-visible { box-shadow: 0 0 0 .2rem rgba(181, 79, 117, .15); }
        .store-nav-link .nav-count { background: var(--admin-blue); }
        .store-search-bar { border-color: #f1dce5; box-shadow: 0 .6rem 1.5rem rgba(86, 49, 68, .1); }
        .store-search-bar svg { stroke: #927780; }
        .store-search-bar input { color: var(--admin-text); }
        .store-search-bar input::placeholder { color: #9b888f; }
        .store-search-button { background: var(--admin-blue); }
        .store-main { padding-top: 2rem !important; }
        .hero { padding: clamp(1.5rem, 4vw, 3rem) !important; }
        .hero .store-eyebrow, .section-eyebrow { color: var(--admin-blue); font-size: .7rem; font-weight: 700; letter-spacing: .17em; text-transform: uppercase; }
        .hero h1 { max-width: 38rem; margin-bottom: 1rem !important; font-size: clamp(2.8rem, 5vw, 4.2rem); line-height: 1.08; }
        .hero h1 em { color: var(--admin-blue); font-weight: 500; }
        .hero .lead { max-width: 34rem; color: #806f76 !important; font-size: .98rem; line-height: 1.85; }
        .hero-photo-wrap { position: relative; max-width: 30rem; margin-inline: auto; }
        .hero-photo { display: block; width: 100%; aspect-ratio: 1 / 1; height: auto; border-radius: .8rem; background: #fde4ed; object-fit: cover; }
        .hero-photo-collage { min-height: 25rem; isolation: isolate; }
        .hero-photo-main { position: absolute; z-index: 1; top: 1rem; right: 4%; width: 78%; aspect-ratio: 1 / 1; margin: 0; padding: .55rem; border: 1px solid rgba(255, 255, 255, .8); border-radius: 1.2rem; background: rgba(255, 255, 255, .72); box-shadow: 0 1.4rem 3rem rgba(86, 49, 68, .16); transform: rotate(2deg); transition: transform .25s ease, box-shadow .25s ease; }
        .hero-photo-main .hero-photo { width: 100%; height: 100%; border-radius: .8rem; }
        .hero-photo-collage::before { position: absolute; z-index: -2; top: 1.8rem; right: 1%; width: 77%; aspect-ratio: 1 / 1; border-radius: 1.5rem; background: #f7dbe5; content: ""; transform: rotate(-5deg); }
        .hero-collage-orbit { position: absolute; z-index: -1; top: .2rem; left: 1%; width: 9rem; height: 9rem; border: 1px solid rgba(181, 79, 117, .25); border-radius: 50%; }
        .hero-photo-tile { position: absolute; z-index: 2; overflow: hidden; margin: 0; padding: .3rem; border: 1px solid rgba(255, 255, 255, .9); background: #fff; box-shadow: 0 .7rem 1.6rem rgba(86, 49, 68, .18); transition: transform .25s ease, box-shadow .25s ease; }
        .hero-photo-tile img { display: block; width: 100%; height: 100%; border-radius: .55rem; object-fit: cover; }
        .hero-photo-tile-one { top: 2.6rem; left: 0; width: 7.25rem; aspect-ratio: 1 / 1; border-radius: .8rem; transform: rotate(-9deg); }
        .hero-photo-tile-two { right: 0; bottom: 1.45rem; width: 6.4rem; aspect-ratio: 1 / 1; border-radius: .8rem; transform: rotate(7deg); }
        .hero-photo-main:hover, .hero-photo-main:focus-within { z-index: 3; transform: translateY(-.45rem) rotate(0) scale(1.025); box-shadow: 0 1.6rem 3rem rgba(86, 49, 68, .23); }
        .hero-photo-tile-one:hover, .hero-photo-tile-one:focus-within { z-index: 4; transform: translateY(-.55rem) rotate(-4deg) scale(1.08); box-shadow: 0 1rem 2rem rgba(86, 49, 68, .24); }
        .hero-photo-tile-two:hover, .hero-photo-tile-two:focus-within { z-index: 4; transform: translateY(-.55rem) rotate(3deg) scale(1.08); box-shadow: 0 1rem 2rem rgba(86, 49, 68, .24); }
        .hero-photo-caption { position: absolute; z-index: 3; bottom: .8rem; left: .15rem; display: flex; min-width: 12rem; flex-direction: column; gap: .15rem; padding: .75rem 1rem; border: 1px solid rgba(255, 255, 255, .8); border-radius: .7rem; background: rgba(255, 255, 255, .94); color: #806f76; font-size: .72rem; box-shadow: 0 .55rem 1.5rem rgba(86, 49, 68, .13); backdrop-filter: blur(8px); }
        .hero-photo-caption span { color: var(--admin-blue); font-size: .62rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .hero-photo-caption strong { color: var(--admin-text); font-size: .82rem; }
        .featured-deck { position: relative; width: 100%; max-width: 27rem; min-height: 25rem; margin: 0 auto; }
        .featured-deck-card { position: absolute; top: .8rem; display: flex; width: 44%; flex-direction: column; gap: .5rem; padding: .6rem; border: 1px solid rgba(255, 255, 255, .9); border-radius: .9rem; background: #fff; box-shadow: 0 .9rem 1.8rem rgba(86, 49, 68, .17); color: inherit; text-decoration: none; transition: transform .22s ease, box-shadow .22s ease; }
        .featured-deck-card img { display: block; width: 100%; aspect-ratio: 1 / 1; border-radius: .55rem; background: #fde4ed; object-fit: cover; }
        .featured-deck-placeholder { display: grid; width: 100%; aspect-ratio: 1 / 1; place-items: center; border-radius: .55rem; background: #fde4ed; color: var(--admin-blue); font-size: 2rem; }
        .featured-deck-details { display: flex; min-height: 2.8rem; flex-direction: column; justify-content: center; gap: .15rem; padding: 0 .15rem; }
        .featured-deck-details strong { display: -webkit-box; overflow: hidden; color: var(--admin-text); font-size: .9rem; line-height: 1.25; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; }
        .featured-deck-details span { color: var(--admin-blue-dark); font-size: .82rem; font-weight: 700; }
        .featured-deck-card-1 { z-index: 1; left: 0; transform: translateY(.55rem) rotate(-7deg); }
        .featured-deck-card-2 { z-index: 2; left: 28%; transform: translateY(0) rotate(0); }
        .featured-deck-card-3 { z-index: 3; left: 56%; transform: translateY(.55rem) rotate(7deg); }
        .featured-deck-card-1:hover, .featured-deck-card-1:focus-visible { z-index: 5; transform: translateY(-.5rem) rotate(-3deg) scale(1.035); }
        .featured-deck-card-2:hover, .featured-deck-card-2:focus-visible { z-index: 5; transform: translateY(-.5rem) rotate(0) scale(1.035); }
        .featured-deck-card-3:hover, .featured-deck-card-3:focus-visible { z-index: 5; transform: translateY(-.5rem) rotate(3deg) scale(1.035); }
        .featured-deck-count-1 .featured-deck-card-1 { left: 28%; width: 44%; }
        .featured-deck-count-2 .featured-deck-card-1 { left: 0; }
        .featured-deck-count-2 .featured-deck-card-2 { left: 45%; width: 50%; }
        .featured-deck-card:focus-visible { outline: 3px solid var(--admin-blue); outline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) {
            .hero-photo-main, .hero-photo-tile, .featured-deck-card { transition: none; }
        }
        .hero-flower-stamp { position: absolute; z-index: 4; top: .1rem; right: -.1rem; display: grid; width: 4.2rem; height: 4.2rem; place-items: center; border: 4px solid #fff7fa; border-radius: 50%; background: #f6d1de; color: #773c55; font-family: Georgia, serif; font-size: 1.65rem; box-shadow: 0 .4rem 1rem rgba(86, 49, 68, .12); transform: rotate(10deg); }
        .store-promise-strip { margin: -1.8rem 0 3rem; padding: 1rem 0; border-block: 1px solid #f5dce5; color: #806f76; font-size: .82rem; }
        .store-promise-strip span::first-letter { color: var(--admin-blue); }
        .section-title { font-size: clamp(2rem, 4vw, 2.8rem); }
        .store-footer-brand span { color: var(--admin-blue); }
        .store-footer-links a, .store-footer-links button, .store-footer-contact a, .store-footer-link { color: #806f76; }
        .customer-order-card, .cart-page-card { border-color: #f3e2e9; box-shadow: 0 .25rem 1rem rgba(86, 49, 68, .05); }
        .customer-order-status { background: #fde4ed; color: #913b5d; }
        #orders-page .btn-outline-primary {
            --bs-btn-color: var(--admin-blue-dark);
            --bs-btn-border-color: #df91ab;
            --bs-btn-hover-color: #fff;
            --bs-btn-hover-bg: var(--admin-blue);
            --bs-btn-hover-border-color: var(--admin-blue);
            --bs-btn-focus-shadow-rgb: 181, 79, 117;
            --bs-btn-active-color: #fff;
            --bs-btn-active-bg: var(--admin-blue-dark);
            --bs-btn-active-border-color: var(--admin-blue-dark);
        }
        .cart-line-item, .cart-fulfillment-option, .customer-component-builder { border-color: #f1dce5; background: #fff9fb; }
        .cart-fulfillment-option:has(input:checked), .customer-category-tab.active, .customer-category-tab:hover { border-color: var(--admin-blue); background: var(--admin-blue-soft); color: var(--admin-blue-dark); }
        .customer-category-tab { border-color: #efd3df; color: var(--admin-blue-dark); }
        .customer-component-card { border-color: #f1dce5; }
        .customer-component-card:hover { border-color: #df91ab; box-shadow: 0 .2rem .6rem rgba(181, 79, 117, .12); }
        .customer-component-card.selected { border-color: var(--admin-blue); background: #fff0f5; box-shadow: 0 0 0 2px rgba(181, 79, 117, .14); }
        .customer-component-card input, .form-check-input:checked { accent-color: var(--admin-blue); background-color: var(--admin-blue); border-color: var(--admin-blue); }
        .customer-component-card img, .customer-component-placeholder { background: #fff0f5; color: #927780; }
        .customize-floating-button { right: 2.75rem; left: auto; width: 68px; min-width: 68px; height: 68px; border-color: #f1dce5; border-radius: .85rem; color: var(--admin-blue-dark); }
        .customize-floating-button svg { width: 32px; height: 32px; }
        @media (min-width: 768px) {
            .customize-floating-button { right: clamp(4.5rem, 8vw, 8rem); }
        }
        .form-label { color: var(--admin-text); }
        .form-control, .form-select { border-color: #edd5df; }
        .form-control:focus, .form-select:focus, .form-check-input:focus { border-color: #df91ab; box-shadow: 0 0 0 .2rem rgba(181, 79, 117, .15); }
        .nav-tabs .nav-link { color: #806f76; }
        .nav-tabs .nav-link.active { color: var(--admin-blue-dark); }
        .table { --bs-table-striped-bg: #fff7fa; }
        .table thead th { background: #fff7fa; color: #69515a; }
        @media (max-width: 767.98px) {
            .hero-photo-collage { min-height: 22rem; }
            .hero-photo-main { top: .8rem; right: 5%; width: 78%; }
            .hero-photo-tile-one { top: 2.5rem; width: 6rem; }
            .hero-photo-tile-two { bottom: 1rem; width: 5.25rem; }
            .hero-photo-caption { bottom: .45rem; left: .15rem; min-width: 10rem; padding: .6rem .75rem; }
            .hero-flower-stamp { top: .15rem; right: .1rem; width: 3.6rem; height: 3.6rem; }
            .store-promise-strip { margin-top: -1.8rem; margin-bottom: 2.25rem; }
        }
        @media (max-width: 400px) {
            .hero-photo-collage { min-height: 19rem; }
            .hero-photo-main { width: 80%; }
            .hero-photo-tile-one { top: 2rem; width: 5rem; }
            .hero-photo-tile-two { width: 4.4rem; }
            .hero-flower-stamp { right: .1rem; width: 3.2rem; height: 3.2rem; }
            .customize-floating-button { right: 1.25rem; left: auto; width: 60px; min-width: 60px; height: 60px; border-radius: .75rem; }
        }
        .store-nav { border-bottom-color: var(--admin-border); box-shadow: 0 .3rem 1rem rgba(66, 31, 46, .12); }
        .store-nav-link { color: #543c47; }
        .store-nav-link:hover, .store-nav-link:focus-visible { border-color: #d8aeba; }
        .store-nav-link:focus-visible { box-shadow: 0 0 0 .2rem rgba(164, 61, 101, .2); }
        .store-search-bar { border-color: #d9b5c1; box-shadow: 0 .7rem 1.5rem rgba(66, 31, 46, .14); }
        .store-search-bar svg { stroke: #58404a; }
        .store-search-bar input::placeholder { color: #705c64; }
        .hero .lead, .hero-photo-caption, .store-promise-strip, .store-footer-copy,
        .store-footer-contact, .store-footer-links a, .store-footer-links button,
        .store-footer-contact a, .store-footer-link, .store-footer-bottom,
        .catalog-filter-button, .store-carousel-price del, .nav-tabs .nav-link { color: #624c55; }
        .hero { border-color: #dfb8c6; box-shadow: 0 .9rem 2rem rgba(66, 31, 46, .12); }
        .store-footer { border-top: 1px solid #dfb8c6; background-color: #f3e0e7; background-image: repeating-linear-gradient(135deg, rgba(118, 41, 67, .035) 0, rgba(118, 41, 67, .035) 1px, transparent 1px, transparent 10px); }
        .store-footer-bottom, .store-promise-strip { border-color: #d8b2bf; }
        .cart-page-card, .customer-order-card, .cart-panel, .card:not(.product-card) { background-color: #fffdfa; box-shadow: 0 .35rem 1rem rgba(66, 31, 46, .1); }
        .cart-fulfillment-option, .cart-line-item, .customer-component-builder { border-color: #dfc0ca; background-color: #fff9f5; }
        .form-control, .form-select { border-color: #d9b5c1; }
        .form-control:focus, .form-select:focus, .form-check-input:focus { border-color: #a43d65; box-shadow: 0 0 0 .2rem rgba(164, 61, 101, .18); }
        .btn-outline-secondary { border-color: #bc8195; color: var(--admin-blue-dark); }
        .btn-outline-secondary:hover { border-color: var(--admin-blue-dark); }
        .customize-floating-button { border-color: #d9b5c1; box-shadow: 0 .5rem 1.5rem rgba(66, 31, 46, .2); }
    </style>
</head>
<body>
<nav class="navbar store-nav sticky-top">
    <div class="container store-nav-inner d-flex align-items-center justify-content-between">
        <a class="navbar-brand brand m-0" href="#home" aria-label="Petal and Bloom home">Petal &amp; Bloom</a>
        <div class="store-nav-links" aria-label="Store navigation">
            <a class="store-nav-link" href="#catalog" data-open-home title="Browse available flowers" aria-label="Home: browse available flowers">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7"></path><path d="M5 9v11h14V9M9 20v-7h6v7"></path></svg>
            </a>
            <button class="store-nav-link" type="button" data-open-cart title="View your shopping cart" aria-label="Shopping cart, 0 items">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 8h14l1 13H4L5 8Z"></path><path d="M9 9V6a3 3 0 0 1 6 0v3"></path></svg>
                <span class="nav-count" id="nav-cart-count" aria-hidden="true"></span>
            </button>
            <?php if ($customerId > 0): ?>
                <a class="store-nav-link" href="#my-orders" data-open-orders title="View your recent orders" aria-label="Orders">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v17H5z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg>
                </a>
            <?php else: ?>
                <button class="store-nav-link" type="button" data-bs-toggle="modal" data-bs-target="#account-modal" data-account-tab="signin" title="Sign in to view your orders" aria-label="Orders; sign in required">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v17H5z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg>
                </button>
            <?php endif; ?>
            <?php if ($customerId > 0): ?>
                <a class="store-nav-link" href="#notifications" data-open-notifications title="View order notifications" aria-label="Notifications: view order notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    <span class="nav-count" id="nav-notification-count" aria-hidden="true"></span>
                </a>
            <?php else: ?>
                <button class="store-nav-link" type="button" data-bs-toggle="modal" data-bs-target="#account-modal" data-account-tab="signin" title="Sign in to view order updates" aria-label="Notifications: sign in to view order updates">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                </button>
            <?php endif; ?>
        </div>
        <div class="store-nav-actions">
            <?php if ($customerId > 0): ?>
                <a class="store-nav-link" href="#my-orders" data-open-orders title="Your customer account" aria-label="Your customer account">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 21v-1a7 7 0 0 1 14 0v1"></path></svg>
                </a>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#signout-modal">Sign out</button>
            <?php else: ?>
                <button class="store-nav-link" type="button" data-bs-toggle="modal" data-bs-target="#account-modal" data-account-tab="signin" title="Sign in to your account" aria-label="Account">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 21v-1a7 7 0 0 1 14 0v1"></path></svg>
                </button>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div class="store-search-wrap" id="store-search-wrap">
    <form class="store-search-bar" id="store-search-form" role="search" aria-label="Search the flower collection">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 4.5 4.5"></path></svg>
        <input id="store-search-input" type="search" aria-label="Search flowers" placeholder="Search flowers and arrangements..." autocomplete="off">
        <button class="store-search-button" type="submit">Search</button>
    </form>
</div>
<main class="container py-4 py-lg-5 store-main" id="store-main">
    <section class="hero p-4 p-lg-5 mb-5" id="home">
        <div id="home-feature-carousel" class="carousel slide store-hero-carousel" data-bs-ride="carousel" data-bs-interval="7000" data-bs-pause="hover">
            <div class="carousel-indicators">
                <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                    <button type="button" data-bs-target="#home-feature-carousel" data-bs-slide-to="<?= $slideIndex ?>" class="<?= $slideIndex === 0 ? 'active' : '' ?>" <?= $slideIndex === 0 ? 'aria-current="true"' : '' ?> aria-label="Show <?= customerEscape($slide['label']) ?>"></button>
                <?php endforeach; ?>
            </div>
            <div class="carousel-inner">
                <?php foreach ($heroSlides as $slideIndex => $slide): ?>
                    <?php
                    $slideProduct = $slide['product'];
                    $slideProducts = $slide['products'] ?? [];
                    if ($slide['type'] === 'discount') {
                        $slideTitle = $slideProduct === null ? 'A little joy, for less.' : 'A little joy, now on sale.';
                    } elseif ($slide['type'] === 'bestseller') {
                        $slideTitle = $slideProduct === null ? 'Loved by our customers.' : $slideProduct['ProductName'];
                    } elseif ($slide['type'] === 'custom') {
                        $slideTitle = 'Create your own bouquet.';
                    } elseif ($slide['type'] === 'featured' && $slideProducts !== []) {
                        $slideTitle = 'Featured arrangements.';
                    } else {
                        $slideTitle = $slideProduct === null ? 'A florist’s favorite is coming soon.' : $slideProduct['ProductName'];
                    }
                    $slideCopy = $slide['type'] === 'featured' && $slideProducts !== []
                        ? 'Handpicked by our florist. Explore the latest featured arrangements.'
                        : ($slideProduct !== null
                        ? ($slide['type'] === 'discount'
                            ? 'Enjoy ' . rtrim(rtrim(number_format((float) $slideProduct['DiscountPercent'], 2), '0'), '.') . '% off this arrangement.'
                            : ($slide['type'] === 'bestseller'
                                ? 'A customer favorite, thoughtfully arranged and ready to brighten someone’s day.'
                                : 'Handpicked by our florist. Discover this featured arrangement.'))
                        : $slide['emptyCopy']);
                    $slideImage = $slideProduct !== null && $slideProduct['image'] !== ''
                        ? $slideProduct['image']
                        : ($slide['type'] === 'custom'
                            ? 'assets/create-your-own-bouquet.jpg'
                            : 'https://images.unsplash.com/photo-1490750967868-88aa4486c946?auto=format&fit=crop&w=1400&q=85');
                    $supportingImages = array_values(array_filter(
                        $products,
                        static fn (array $product): bool => $product['image'] !== ''
                            && ($slideProduct === null || (int) $product['ProductID'] !== (int) $slideProduct['ProductID'])
                    ));
                    $supportingProductOne = $supportingImages[$slideIndex % max(1, count($supportingImages))] ?? null;
                    $supportingProductTwo = $supportingImages[($slideIndex + 1) % max(1, count($supportingImages))] ?? null;
                    $slideOutOfStock = $slideProduct !== null && $slideProduct['ProductStock'] !== null && (int) $slideProduct['ProductStock'] < 1;
                    ?>
                    <div class="carousel-item <?= $slideIndex === 0 ? 'active' : '' ?>">
                        <div class="row align-items-center g-4 store-hero-slide">
                            <div class="col-lg-7">
                                <p class="store-eyebrow mb-2"><?= customerEscape($slide['label']) ?></p>
                                <h1 class="mb-3"><?= customerEscape($slideTitle) ?></h1>
                                <p class="lead mb-0"><?= customerEscape($slideCopy) ?></p>
                                <?php if ($slideProduct !== null): ?>
                                    <div class="store-carousel-price">
                                        <strong>₱<?= number_format((float) $slideProduct['Price'], 2) ?></strong>
                                        <?php if ((float) $slideProduct['DiscountPercent'] > 0): ?>
                                            <del>₱<?= number_format((float) $slideProduct['OriginalPrice'], 2) ?></del>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-rose mt-4 add-product" type="button" data-product-id="<?= (int) $slideProduct['ProductID'] ?>" data-product-name="<?= customerEscape($slideProduct['ProductName']) ?>" <?= $slideOutOfStock ? 'disabled' : '' ?>>
                                        <?= $slideOutOfStock ? 'Sold out' : 'Add to bag' ?> <span aria-hidden="true">↗</span>
                                    </button>
                                <?php elseif ($slide['type'] === 'custom'): ?>
                                    <button class="btn btn-rose mt-4" type="button" data-open-carousel-customizer>Create your bouquet <span aria-hidden="true">↗</span></button>
                                <?php elseif ($slide['type'] === 'featured' && $slideProducts !== []): ?>
                                    <a href="#catalog" class="btn btn-rose mt-4">View featured product <span aria-hidden="true">↗</span></a>
                                <?php else: ?>
                                    <a href="#catalog" class="btn btn-rose mt-4">Browse the collection <span aria-hidden="true">↗</span></a>
                                <?php endif; ?>
                            </div>
                            <div class="col-lg-5">
                                <?php if ($slide['type'] === 'featured' && $slideProducts !== []): ?>
                                    <div class="featured-deck featured-deck-count-<?= count($slideProducts) ?>" aria-label="Featured products">
                                        <?php foreach ($slideProducts as $featuredIndex => $featuredProduct): ?>
                                            <a class="featured-deck-card featured-deck-card-<?= $featuredIndex + 1 ?>" href="#catalog-product-<?= (int) $featuredProduct['ProductID'] ?>" aria-label="View <?= customerEscape($featuredProduct['ProductName']) ?>">
                                                <?php if ($featuredProduct['image'] !== ''): ?>
                                                    <img src="<?= customerEscape($featuredProduct['image']) ?>" alt="<?= customerEscape($featuredProduct['ProductName']) ?>">
                                                <?php else: ?>
                                                    <div class="featured-deck-placeholder" aria-hidden="true">✿</div>
                                                <?php endif; ?>
                                                <div class="featured-deck-details">
                                                    <strong><?= customerEscape($featuredProduct['ProductName']) ?></strong>
                                                    <span>₱<?= number_format((float) $featuredProduct['Price'], 2) ?></span>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                <div class="hero-photo-wrap hero-photo-collage">
                                    <span class="hero-collage-orbit" aria-hidden="true"></span>
                                    <figure class="hero-photo-main">
                                        <img class="hero-photo" src="<?= customerEscape($slideImage) ?>" alt="<?= $slideProduct !== null ? customerEscape($slideProduct['ProductName']) : customerEscape($slide['label']) ?>">
                                    </figure>
                                    <?php if ($supportingProductOne !== null): ?>
                                        <figure class="hero-photo-tile hero-photo-tile-one">
                                            <img src="<?= customerEscape($supportingProductOne['image']) ?>" alt="<?= customerEscape($supportingProductOne['ProductName']) ?>">
                                        </figure>
                                    <?php endif; ?>
                                    <?php if ($supportingProductTwo !== null): ?>
                                        <figure class="hero-photo-tile hero-photo-tile-two">
                                            <img src="<?= customerEscape($supportingProductTwo['image']) ?>" alt="<?= customerEscape($supportingProductTwo['ProductName']) ?>">
                                        </figure>
                                    <?php endif; ?>
                                    <div class="hero-photo-caption">
                                        <span><?= customerEscape($slide['label']) ?></span>
                                        <strong><?= $slideProduct !== null ? customerEscape($slideProduct['ProductName']) : 'Made with care, just for you' ?></strong>
                                    </div>
                                    <span class="hero-flower-stamp" aria-hidden="true">✿</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="carousel-control-prev" type="button" data-bs-target="#home-feature-carousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous slide</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#home-feature-carousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next slide</span>
            </button>
        </div>
    </section>
    <div class="store-promise-strip d-flex flex-wrap justify-content-center justify-content-lg-between gap-3 gap-lg-4" aria-label="Our shop promises">
        <span>✿ &nbsp; Thoughtful seasonal arrangements</span>
        <span>♡ &nbsp; Prepared with care</span>
        <span>♧ &nbsp; Pickup or delivery at checkout</span>
    </div>
    <?php if ($customerError !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= customerEscape($customerError) ?></div>
    <?php endif; ?>
    <?php if ($customerSuccess !== ''): ?>
        <div class="alert alert-success" role="status"><?= customerEscape($customerSuccess) ?></div>
    <?php endif; ?>

    <?php if ($catalogError !== ''): ?>
        <div class="alert alert-warning"><?= customerEscape($catalogError) ?></div>
    <?php endif; ?>
    <section id="catalog" class="mb-5">
        <?php if ($products !== []): ?>
            <nav class="catalog-filter-nav" aria-label="Filter arrangements">
                <?php foreach ($catalogFilters as $filterKey => $filterLabel): ?>
                    <button class="catalog-filter-button <?= $filterKey === 'all' ? 'active' : '' ?>" type="button" data-catalog-filter="<?= customerEscape($filterKey) ?>" aria-pressed="<?= $filterKey === 'all' ? 'true' : 'false' ?>"><?= customerEscape($filterLabel) ?></button>
                <?php endforeach; ?>
                <?php if ($catalogEventOptions !== []): ?>
                    <details class="catalog-events-dropdown" id="catalog-events-dropdown">
                        <summary class="catalog-filter-button" aria-label="Filter products by event">Events <span class="catalog-event-count" id="catalog-event-count"></span></summary>
                        <div class="catalog-events-menu">
                            <?php foreach ($catalogEventOptions as $eventOption): ?>
                                <label class="catalog-event-option">
                                    <input type="checkbox" value="<?= customerEscape($eventOption) ?>" data-catalog-event>
                                    <span><?= customerEscape($eventOption) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <button class="catalog-events-clear" id="clear-catalog-events" type="button">Clear events</button>
                        </div>
                    </details>
                <?php endif; ?>
                <details class="catalog-events-dropdown" id="catalog-ratings-dropdown">
                    <summary class="catalog-filter-button" aria-label="Filter products by rating">By ratings <span id="catalog-rating-count"></span></summary>
                    <div class="catalog-events-menu" role="group" aria-label="Minimum product rating">
                        <button class="catalog-rating-option" type="button" data-min-rating="0" aria-pressed="true">Any rating</button>
                        <button class="catalog-rating-option" type="button" data-min-rating="3" aria-pressed="false">3 stars &amp; up</button>
                        <button class="catalog-rating-option" type="button" data-min-rating="4" aria-pressed="false">4 stars &amp; up</button>
                        <button class="catalog-rating-option" type="button" data-min-rating="5" aria-pressed="false">5 stars</button>
                    </div>
                </details>
            </nav>
        <?php endif; ?>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><p class="section-eyebrow mb-1">Our collection</p><h2 class="section-title mb-0">Available arrangements</h2></div>
            <span class="text-secondary small"><?= count($products) ?> items</span>
        </div>
        <?php if ($products === []): ?>
            <div class="alert alert-info">There are no products available yet. Please check back soon.</div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($products as $product): ?>
                    <?php $outOfStock = $product['ProductStock'] !== null && (int) $product['ProductStock'] < 1; ?>
                    <?php
                    $productFilterTags = [];
                    if ((int) $product['Featured'] === 1) {
                        $productFilterTags[] = 'featured';
                    }
                    if ($bestSellerProductId > 0 && (int) $product['ProductID'] === $bestSellerProductId) {
                        $productFilterTags[] = 'bestseller';
                    }
                    if ((float) $product['DiscountPercent'] > 0) {
                        $productFilterTags[] = 'discounted';
                    }
                    ?>
                    <div class="col-sm-6 col-lg-3 catalog-item" data-catalog-search="<?= customerEscape(implode(' ', array_merge([$product['ProductName']], $product['Categories']))) ?>" data-catalog-tags="<?= customerEscape(implode(' ', $productFilterTags)) ?>" data-catalog-events="<?= customerEscape((string) json_encode($product['Categories'], JSON_UNESCAPED_UNICODE)) ?>" data-catalog-rating="<?= customerEscape((string) $product['AverageRating']) ?>">
                        <article class="card product-card h-100" id="catalog-product-<?= (int) $product['ProductID'] ?>" tabindex="0" aria-haspopup="dialog" aria-label="View details for <?= customerEscape($product['ProductName']) ?>" data-product-details-id="<?= (int) $product['ProductID'] ?>">
                            <?php if ($product['image'] !== ''): ?>
                                <img class="product-image card-img-top" src="<?= customerEscape($product['image']) ?>" alt="<?= customerEscape($product['ProductName']) ?>">
                            <?php else: ?>
                                <div class="placeholder-image d-flex align-items-center justify-content-center text-secondary" aria-hidden="true">Fresh flowers, thoughtfully arranged</div>
                            <?php endif; ?>
                            <div class="card-body d-flex flex-column">
                                <h3 class="h5"><?= customerEscape($product['ProductName']) ?></h3>
                                <p class="product-rating-summary" aria-label="<?= (int) $product['RatingCount'] > 0 ? customerEscape(number_format((float) $product['AverageRating'], 1) . ' out of 5 stars, ' . (int) $product['RatingCount'] . ' ratings') : 'No ratings yet' ?>">
                                    <?php if ((int) $product['RatingCount'] > 0): ?>
                                        <span aria-hidden="true">★</span> <?= number_format((float) $product['AverageRating'], 1) ?> (<?= (int) $product['RatingCount'] ?>)
                                    <?php else: ?>
                                        No ratings yet
                                    <?php endif; ?>
                                </p>
                                <?php if ($product['Categories'] !== []): ?>
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <?php foreach ($product['Categories'] as $category): ?>
                                            <span class="badge rounded-pill text-bg-light"><?= customerEscape($category) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($product['DiscountPercent'] > 0): ?>
                                    <small class="text-danger fw-semibold mb-1"><?= rtrim(rtrim(number_format($product['DiscountPercent'], 2), '0'), '.') ?>% off</small>
                                <?php endif; ?>
                                <?php if ($product['ProductStock'] !== null): ?><small class="text-secondary mb-2"><?= (int) $product['ProductStock'] ?> in stock</small><?php endif; ?>
                                <p class="text-secondary small flex-grow-1"><?= trim((string) ($product['Description'] ?? '')) !== '' ? nl2br(customerEscape((string) $product['Description'])) : 'Beautifully prepared by our florists. Choose a quantity when adding to your bag.' ?></p>
                                <div class="d-flex justify-content-between align-items-center mt-auto">
                                    <span class="fs-5 fw-bold">
                                        ₱<?= number_format((float) $product['Price'], 2) ?>
                                        <?php if ($product['DiscountPercent'] > 0): ?>
                                            <del class="d-block text-secondary small fw-normal">₱<?= number_format((float) $product['OriginalPrice'], 2) ?></del>
                                        <?php endif; ?>
                                    </span>
                                    <button class="btn btn-rose add-product" data-product-id="<?= (int) $product['ProductID'] ?>" data-product-name="<?= customerEscape($product['ProductName']) ?>" <?= $outOfStock ? 'disabled' : '' ?>>
                                        <?= $outOfStock ? 'Sold out' : 'Add to bag' ?>
                                    </button>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="catalog-no-matches mt-3" id="catalog-no-matches" role="status" hidden>No arrangements match your search or filters. Try clearing a filter or choosing another event.</p>
            <nav class="catalog-pagination mt-4" id="catalog-pagination" aria-label="Product pages" hidden>
                <ul class="pagination justify-content-center flex-wrap mb-0" id="catalog-pagination-list"></ul>
            </nav>
        <?php endif; ?>
    </section>

</main>
<footer class="site-footer store-footer" id="contact">
    <div class="container">
        <div class="row g-4 pb-4">
            <div class="col-6 col-lg-4 store-footer-section">
                <a class="store-footer-brand" href="#home"><span aria-hidden="true">✿</span> Petal &amp; Bloom</a>
                <p class="store-footer-copy mt-2">Thoughtful flowers, made easy.</p>
            </div>
            <div class="col-6 col-lg-4 store-footer-section">
                <h2 class="store-footer-title">Come say hello</h2>
                <a class="store-footer-link" href="mailto:Kurtcat428@gmail.com">Kurtcat428@gmail.com</a>
                <p class="store-footer-copy mt-2">We'd love to hear from you.</p>
            </div>
            <div class="col-6 col-lg-4 store-footer-section">
                <h2 class="store-footer-title">Find your way</h2>
                <nav class="store-footer-links" aria-label="Footer navigation">
                    <a href="#catalog">Browse the collection</a>
                    <button type="button" data-open-cart>Shopping bag</button>
                </nav>
            </div>
        </div>
        <div class="store-footer-bottom d-flex justify-content-between flex-wrap gap-2">
            <span>&copy; <?= date('Y') ?> Petal &amp; Bloom</span>
            <span>Made with a little extra love ♡</span>
        </div>
    </div>
</footer>
<?php if ($customerId > 0): ?>
<section class="customer-cart-page" id="orders-page" aria-labelledby="my-orders-title" hidden>
    <div class="container">
        <section id="my-orders" aria-labelledby="my-orders-title">
            <div class="cart-page-header">
                <div>
                    <p class="text-uppercase small fw-bold text-secondary mb-1">Order tracking</p>
                    <h1 id="my-orders-title">Your orders</h1>
                </div>
                <a class="btn btn-outline-primary btn-sm" href="CustomerShop.php#my-orders">Refresh status</a>
            </div>
            <?php if ($customerError !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= customerEscape($customerError) ?></div>
            <?php endif; ?>
            <?php if ($customerSuccess !== ''): ?>
                <div class="alert alert-success" role="status"><?= customerEscape($customerSuccess) ?></div>
            <?php endif; ?>
            <div class="d-flex flex-nowrap gap-2 overflow-auto mb-3 pb-2" role="group" aria-label="Filter your orders by status">
                <?php
                    $customerOrderCategories = [
                        'all' => 'All',
                        'to-be-approved' => 'To be approved',
                        'received' => 'Received',
                        'out-for-delivery' => 'Out for delivery',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'pending' => 'Pending',
                        'declined' => 'Declined',
                        'delivery-failed' => 'Delivery failed',
                        'delivered' => 'Delivered',
                    ];
                    foreach ($customerOrderCategories as $categoryValue => $categoryLabel):
                ?>
                    <button class="btn btn-outline-primary text-nowrap customer-order-category-filter<?= $categoryValue === 'all' ? ' active' : '' ?>" type="button" data-customer-order-category="<?= customerEscape($categoryValue) ?>" aria-pressed="<?= $categoryValue === 'all' ? 'true' : 'false' ?>"><?= customerEscape($categoryLabel) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="alert alert-info" id="customer-orders-no-matches" role="status" hidden>No orders match this status.</div>
            <?php if ($orderHistoryError !== ''): ?>
                <div class="alert alert-warning" role="alert"><?= customerEscape($orderHistoryError) ?></div>
            <?php elseif ($customerOrders === []): ?>
                <div class="alert alert-info">You have not placed any orders yet. Your orders will appear here after checkout.</div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($customerOrders as $order): ?>
                        <?php
                            $customerOrderCategory = match ($order['Status']) {
                                'Pending review', 'Pending' => 'to-be-approved',
                                'Received' => 'received',
                                'Out for delivery' => 'out-for-delivery',
                                'Delivered' => 'delivered',
                                'Completed' => 'completed',
                                'Cancelled' => 'cancelled',
                                'Declined' => 'declined',
                                default => 'pending',
                            };
                        ?>
                        <article class="customer-order-card customer-order-filter-row" data-customer-order-category="<?= customerEscape($customerOrderCategory) ?>" aria-labelledby="order-title-<?= (int) $order['OrderID'] ?>">
                            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
                                <div>
                                    <h2 class="h5 mb-1" id="order-title-<?= (int) $order['OrderID'] ?>">Order #<?= (int) $order['OrderID'] ?></h2>
                                    <p class="small text-secondary mb-0">Placed <?= customerEscape(date('M j, Y g:i A', strtotime($order['OrderDate']))) ?></p>
                                </div>
                                <span class="customer-order-status" data-order-status-id="<?= (int) $order['OrderID'] ?>"><?= customerEscape($order['Status']) ?></span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <span class="small text-secondary d-block">Fulfillment</span>
                                    <strong><?= customerEscape(ucfirst($order['FulfillmentType'])) ?> · <?= customerEscape(date('M j, Y', strtotime($order['FulfillmentDate']))) ?> at <?= customerEscape(date('g:i A', strtotime($order['FulfillmentTime']))) ?></strong>
                                </div>
                                <div class="col-sm-6">
                                    <span class="small text-secondary d-block">Payment</span>
                                    <strong><?= customerEscape(ucfirst($order['PaymentMethod'])) ?> · <?= customerEscape($order['PaymentStatus']) ?></strong>
                                </div>
                            </div>
                            <div class="border-top pt-2">
                                <?php foreach ($order['items'] as $orderItem): ?>
                                    <div class="customer-order-item">
                                        <span><?= customerEscape($orderItem['name']) ?> × <?= (int) $orderItem['quantity'] ?></span>
                                        <strong>₱<?= number_format((float) $orderItem['total'], 2) ?></strong>
                                    </div>
                                    <?php if ($orderItem['product_id'] !== null): ?>
                                        <?php
                                            $canRateOrder = in_array(strtolower((string) $order['Status']), ['received', 'completed'], true);
                                            $displayRating = $orderItem['rating'] ?? 0;
                                        ?>
                                        <?php if ($canRateOrder): ?>
                                            <div class="customer-order-rating">
                                                <span class="small fw-semibold"><?= $displayRating > 0 ? 'Your rating:' : 'Rate this product:' ?></span>
                                                <?php if ($displayRating > 0): ?>
                                                    <span class="product-rating-stars" aria-label="<?= $displayRating ?> out of 5 stars"><?= str_repeat('★', $displayRating) . str_repeat('☆', 5 - $displayRating) ?></span>
                                                <?php endif; ?>
                                                <button
                                                    class="btn btn-outline-primary btn-sm"
                                                    type="button"
                                                    data-open-rating-dialog
                                                    data-order-id="<?= (int) $order['OrderID'] ?>"
                                                    data-order-item-id="<?= (int) $orderItem['order_item_id'] ?>"
                                                    data-product-name="<?= customerEscape($orderItem['name']) ?>"
                                                    data-current-rating="<?= (int) $displayRating ?>"
                                                    data-current-review="<?= customerEscape($orderItem['review']) ?>"
                                                    data-current-images="<?= customerEscape(implode('|', $orderItem['images'])) ?>"
                                                ><?= $displayRating > 0 ? 'Edit review' : 'Leave a review' ?></button>
                                            </div>
                                            <?php if (trim((string) $orderItem['review']) !== ''): ?>
                                                <p class="small text-secondary mb-2"><strong>Your review:</strong> <?= customerEscape($orderItem['review']) ?></p>
                                            <?php endif; ?>
                                            <?php if ($orderItem['images'] !== []): ?>
                                                <div class="customer-review-image-list" aria-label="Review images">
                                                    <?php foreach ($orderItem['images'] as $imagePath): ?>
                                                        <a href="<?= customerEscape($imagePath) ?>" target="_blank" rel="noopener">
                                                            <img src="<?= customerEscape($imagePath) ?>" alt="Customer review for <?= customerEscape($orderItem['name']) ?>" loading="lazy">
                                                        </a>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php elseif ($displayRating > 0): ?>
                                            <p class="customer-order-rating small mb-0">Your rating: <?= str_repeat('★', $displayRating) . str_repeat('☆', 5 - $displayRating) ?></p>
                                            <?php if (trim((string) $orderItem['review']) !== ''): ?>
                                                <p class="small text-secondary mb-2"><strong>Your review:</strong> <?= customerEscape($orderItem['review']) ?></p>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="d-flex justify-content-between gap-3 pt-2 fw-bold">
                                    <span>Order total</span>
                                    <span>₱<?= number_format((float) $order['TotalAmount'], 2) ?></span>
                                </div>
                            </div>
                            <?php if (in_array($order['Status'], ['Pending review', 'Pending'], true)): ?>
                                <form method="post" class="mt-3" onsubmit="return confirm('Cancel this order?');">
                                    <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= (int) $order['OrderID'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">Cancel order</button>
                                </form>
                            <?php endif; ?>
                            <p class="small text-secondary mt-3 mb-0">You can rate catalog products after an order is received or completed.</p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>
<?php endif; ?>
<?php if ($customerId > 0): ?>
<section class="customer-cart-page" id="notifications-page" aria-labelledby="notifications-title" hidden>
    <div class="container">
        <div class="cart-page-header">
            <div>
                <p class="text-uppercase small fw-bold text-secondary mb-1">Order updates</p>
                <h1 id="notifications-title">Notifications</h1>
            </div>
            <a class="btn btn-outline-primary btn-sm" href="CustomerShop.php#notifications">Refresh</a>
        </div>
        <div class="alert alert-success" id="customer-order-update-alert" role="status" aria-live="polite" hidden></div>
        <?php if ($orderHistoryError !== ''): ?>
            <div class="alert alert-warning" role="alert"><?= customerEscape($orderHistoryError) ?></div>
        <?php endif; ?>
        <div class="d-grid gap-3" id="customer-order-notifications">
            <?php if ($customerOrders === []): ?>
                <div class="alert alert-info mb-0" data-notifications-empty>You do not have any order updates yet. Updates will appear here after you place an order.</div>
            <?php else: ?>
                <?php foreach ($customerOrders as $order): ?>
                    <?php
                        $orderNotificationText = match ($order['Status']) {
                            'Accepted' => 'The shop has accepted your order.',
                            'Out for delivery' => 'Your order is on its way.',
                            'Delivered' => 'Your order is marked delivered. Please confirm when you have received it.',
                            'Received' => 'Your order has been marked received.',
                            'Completed' => 'Your order is complete.',
                            'Declined' => 'The shop has declined your order.',
                            'Cancelled' => 'You cancelled this order before it was approved.',
                            'Pending review', 'Pending' => 'Your order was received and is waiting for the shop to review it.',
                            default => 'Your order status was updated by the shop.',
                        };
                    ?>
                    <article class="customer-order-card" data-notification-order-id="<?= (int) $order['OrderID'] ?>">
                        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
                            <div>
                                <h2 class="h5 mb-1">Order #<?= (int) $order['OrderID'] ?></h2>
                                <p class="mb-1"><?= customerEscape($orderNotificationText) ?></p>
                                <p class="small text-secondary mb-0">Placed <?= customerEscape(date('M j, Y g:i A', strtotime($order['OrderDate']))) ?></p>
                            </div>
                            <span class="customer-order-status"><?= customerEscape($order['Status']) ?></span>
                        </div>
                        <p class="small text-secondary mt-3 mb-0"><?= customerEscape(ucfirst($order['FulfillmentType'])) ?> · <?= customerEscape(date('M j, Y', strtotime($order['FulfillmentDate']))) ?> at <?= customerEscape(date('g:i A', strtotime($order['FulfillmentTime']))) ?> · ₱<?= number_format((float) $order['TotalAmount'], 2) ?></p>
                        <?php if ($order['Status'] === 'Delivered' && strtolower((string) $order['FulfillmentType']) === 'delivery'): ?>
                            <form method="post" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                                <input type="hidden" name="action" value="confirm_order_received">
                                <input type="hidden" name="order_id" value="<?= (int) $order['OrderID'] ?>">
                                <button class="btn btn-rose btn-sm" type="submit">Confirm order received</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php if ($customerId > 0): ?>
<div class="modal fade" id="customer-rating-modal" tabindex="-1" aria-labelledby="customer-rating-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="customer-rating-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                <input type="hidden" name="action" value="rate_product">
                <input type="hidden" name="order_id" id="customer-rating-order-id">
                <input type="hidden" name="order_item_id" id="customer-rating-order-item-id">
                <div class="modal-header">
                    <div>
                        <p class="text-uppercase small fw-bold text-secondary mb-1">Product feedback</p>
                        <h2 class="modal-title h5" id="customer-rating-modal-title">Rate this product</h2>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close review"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="customer-rating-review">Your message <span class="text-secondary fw-normal">(optional)</span></label>
                    <textarea class="form-control customer-rating-review" id="customer-rating-review" name="review" rows="8" maxlength="255" placeholder="Share what you liked about this product."></textarea>
                    <small class="text-secondary d-block mt-1 mb-3">Optional · up to 255 characters</small>
                    <label class="form-label mt-2" for="customer-rating-images">Add images <span class="text-secondary fw-normal">(up to 3 total)</span></label>
                    <input class="form-control" type="file" id="customer-rating-images" name="review_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <small class="text-secondary d-block mt-1">JPEG, PNG, GIF, or WebP · up to 5 MB each</small>
                    <div class="customer-review-image-list mt-2" id="customer-rating-existing-images" aria-label="Already uploaded review images"></div>
                    <fieldset class="customer-rating-fieldset">
                        <legend class="form-label">Star rating <span class="text-danger">*</span></legend>
                        <div class="customer-review-stars" role="radiogroup" aria-label="Choose a rating from 1 to 5 stars">
                            <?php for ($star = 1; $star <= 5; $star++): ?>
                                <label>
                                    <input type="radio" name="rating" value="<?= $star ?>" aria-label="<?= $star ?> <?= $star === 1 ? 'star' : 'stars' ?>" required>
                                    <span aria-hidden="true">★</span>
                                </label>
                            <?php endfor; ?>
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-rose">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<section class="customer-cart-page" id="customer-cart-page" aria-labelledby="customer-cart-title" hidden>
    <div class="container">
        <div class="cart-page-header">
            <div>
                <p class="text-uppercase small fw-bold text-secondary mb-1">Checkout</p>
                <h1 id="customer-cart-title">Your bag <span class="text-secondary fs-5" id="cart-count"></span></h1>
            </div>
        </div>
        <div class="cart-page-layout">
            <section class="cart-page-card" aria-labelledby="cart-items-title">
                <h2 id="cart-items-title">Items in your bag</h2>
                <div id="cart-items" class="cart-dialog-items"><p class="text-secondary mb-0">Your bag is empty.</p></div>
                <button class="btn btn-outline-secondary mt-3 w-100" id="open-addons-dialog" type="button">Add add-ons</button>
            </section>
            <section class="cart-page-card" aria-labelledby="checkout-title">
                <h2 id="checkout-title">Purchase details</h2>
                <?php if ($customerId > 0): ?>
                    <form id="checkout-form" class="cart-checkout-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                        <input type="hidden" name="order_token" value="<?= customerEscape($customerOrderToken) ?>">
                        <input type="hidden" name="action" value="place_order">
                        <input type="hidden" name="cart" id="cart-json">
                        <fieldset class="m-0">
                            <legend class="form-label mb-2">Pickup or delivery?</legend>
                            <div class="cart-fulfillment-options">
                                <label class="cart-fulfillment-option" for="pickup"><input class="form-check-input m-0" type="radio" name="fulfillment_type" id="pickup" value="pickup" checked><span>Pick up</span></label>
                                <label class="cart-fulfillment-option" for="delivery"><input class="form-check-input m-0" type="radio" name="fulfillment_type" id="delivery" value="delivery"><span>Delivery</span></label>
                            </div>
                        </fieldset>
                        <div class="row g-2">
                            <div class="col-7"><label class="form-label" for="fulfillment-date">Date</label><input class="form-control" type="date" name="fulfillment_date" id="fulfillment-date" min="<?= date('Y-m-d') ?>" required></div>
                            <div class="col-5"><label class="form-label" for="fulfillment-time">Time</label><input class="form-control" type="time" name="fulfillment_time" id="fulfillment-time" required></div>
                        </div>
                        <div id="delivery-fields" class="delivery-details-fields d-none">
                            <h3 class="h6">Delivery details</h3>
                            <div class="mb-2"><label class="form-label" for="recipient-name">Recipient name</label><input class="form-control" id="recipient-name" name="recipient_name" maxlength="150"></div>
                            <div class="mb-2"><label class="form-label" for="recipient-phone">Recipient phone</label><input class="form-control" id="recipient-phone" name="recipient_phone" maxlength="30"></div>
                            <div class="mb-2"><label class="form-label" for="delivery-address">Street address</label><input class="form-control" id="delivery-address" name="delivery_address" maxlength="200"></div>
                            <div class="row g-2">
                                <div class="col-6 mb-2"><label class="form-label" for="delivery-barangay">Barangay</label><input class="form-control" id="delivery-barangay" name="delivery_barangay" maxlength="100"></div>
                                <div class="col-6 mb-2"><label class="form-label" for="delivery-city">City</label><input class="form-control" id="delivery-city" name="delivery_city" maxlength="100"></div>
                                <div class="col-7 mb-2"><label class="form-label" for="delivery-province">Province</label><input class="form-control" id="delivery-province" name="delivery_province" maxlength="100"></div>
                                <div class="col-5 mb-2"><label class="form-label" for="delivery-postal">Postal code</label><input class="form-control" id="delivery-postal" name="delivery_postal_code" maxlength="20"></div>
                            </div>
                            <div class="mb-1"><label class="form-label" for="delivery-instructions">Delivery instructions</label><textarea class="form-control" id="delivery-instructions" name="delivery_instructions" maxlength="500" rows="2"></textarea></div>
                        </div>
                        <div>
                            <label class="form-label" for="payment-method">Payment</label>
                            <select class="form-select" id="payment-method" name="payment_method" required>
                                <option value="cash">Cash on pickup / delivery</option>
                                <option value="gcash">GCash</option>
                                <option value="maribank">MariBank</option>
                                <option value="gotyme">GoTyme</option>
                                <option value="landbank">Landbank</option>
                            </select>
                            <div class="form-text">Online payment choices are recorded for payment processing; checkout does not charge an account.</div>
                        </div>
                        <div class="cart-total-row"><span>Estimated total</span><span id="cart-total">₱0.00</span></div>
                        <button class="btn btn-rose w-100 btn-lg" id="place-order" type="submit">Place order</button>
                    </form>
                <?php else: ?>
                    <p class="small text-secondary">Sign in or create an account to continue to checkout.</p>
                    <div class="cart-total-row mb-3"><span>Estimated total</span><span id="cart-total">₱0.00</span></div>
                    <button class="btn btn-rose w-100" type="button" data-bs-toggle="modal" data-bs-target="#account-modal" data-account-tab="signin">Sign in to place order</button>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
<div class="modal fade" id="product-details-modal" tabindex="-1" aria-labelledby="product-details-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h4" id="product-details-title">Product details</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close product details"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4 product-details-summary">
                    <div class="col-md-5">
                        <img class="product-details-image" id="product-details-image" alt="">
                    </div>
                    <div class="col-md-7">
                        <p class="product-rating-summary mb-2" id="product-details-rating"></p>
                        <p class="text-secondary mb-3 product-details-description" id="product-details-description"></p>
                        <dl class="row small mb-0" id="product-details-facts"></dl>
                    </div>
                </div>
                <section class="mt-4 product-details-review-section" aria-labelledby="product-details-reviews-title">
                    <h3 class="h5" id="product-details-reviews-title">Customer reviews</h3>
                    <div class="product-details-reviews" id="product-details-reviews"></div>
                </section>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" id="product-details-add" type="button">Add to Bag</button>
                <button class="btn btn-rose" id="product-details-buy" type="button">Buy Now</button>
            </div>
        </div>
    </div>
</div>
<?php
$availableAddons = array_values(array_filter(
    $inventory,
    static fn (array $component): bool => $component['Category'] === 'Addons' && (int) $component['Stock'] > 0
));
?>
<dialog id="customer-addons-dialog" class="customer-addons-dialog" aria-labelledby="customer-addons-title">
    <div class="customer-addons-dialog-content">
        <div class="customer-addons-dialog-header">
            <div>
                <p class="text-uppercase small fw-bold text-secondary mb-1">Finishing touches</p>
                <h2 id="customer-addons-title">Choose add-ons</h2>
            </div>
            <button class="customer-customize-close" id="close-addons-dialog" type="button" aria-label="Close add-ons">&times;</button>
        </div>
        <?php if ($availableAddons === []): ?>
            <p class="text-secondary mb-0">No add-ons are currently in stock.</p>
        <?php else: ?>
            <div class="cart-addons">
                <?php foreach ($availableAddons as $addon): ?>
                    <article class="cart-addon-card">
                        <strong title="<?= customerEscape($addon['ItemName']) ?>"><?= customerEscape($addon['ItemName']) ?></strong>
                        <span class="small text-secondary">₱<?= number_format((float) $addon['Price'], 2) ?> · <?= (int) $addon['Stock'] ?> in stock</span>
                        <button
                            class="btn btn-outline-secondary btn-sm add-addon"
                            type="button"
                            data-item-id="<?= (int) $addon['ItemID'] ?>"
                            data-item-name="<?= customerEscape($addon['ItemName']) ?>"
                            data-item-price="<?= customerEscape($addon['Price']) ?>"
                            data-item-stock="<?= (int) $addon['Stock'] ?>"
                        >Add to bag</button>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</dialog>
<?php if ($customerId > 0): ?>
<div class="modal fade" id="signout-modal" tabindex="-1" aria-labelledby="signout-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title" id="signout-modal-title">Sign out?</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel sign out"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to sign out of your customer account?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="btn btn-signout" type="submit">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<button class="add-user-button customize-floating-button" id="open-customer-customizer" type="button" aria-label="Customize your bouquet" title="Customize your bouquet">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path></svg>
</button>
<dialog id="customer-customize-dialog" class="customer-customize-dialog" aria-labelledby="customer-customize-title">
    <div class="customer-customize-form">
        <div class="customer-customize-header">
            <div>
                <p class="text-uppercase small fw-bold text-secondary mb-1">Custom bouquet</p>
                <h2 id="customer-customize-title">Choose your arrangement</h2>
            </div>
            <button class="customer-customize-close" id="close-customer-customizer" type="button" aria-label="Close bouquet customizer">&times;</button>
        </div>
        <div class="customer-component-builder">
            <div class="customer-component-picker">
                <div class="customer-category-tabs" role="group" aria-label="Bouquet component categories">
                    <button class="customer-category-tab active" type="button" data-custom-category="Flower" aria-pressed="true">Flowers</button>
                    <button class="customer-category-tab" type="button" data-custom-category="Wrapper" aria-pressed="false">Wrappers</button>
                    <button class="customer-category-tab" type="button" data-custom-category="Ribbon" aria-pressed="false">Ribbon</button>
                    <button class="customer-category-tab" type="button" data-custom-category="Addons" aria-pressed="false">Add-ons</button>
                </div>
                <?php
                $customCategories = [
                    'Flower' => ['title' => 'Flowers', 'description' => 'Select one or more flower types.'],
                    'Wrapper' => ['title' => 'Wrappers', 'description' => 'Choose up to two wrapper types.'],
                    'Ribbon' => ['title' => 'Ribbon', 'description' => 'Choose one ribbon.'],
                    'Addons' => ['title' => 'Add-ons', 'description' => 'Optional finishing touches.'],
                ];
                foreach ($customCategories as $categoryKey => $categoryInfo):
                    $categoryItems = array_values(array_filter(
                        $inventory,
                        static fn (array $component): bool => $component['Category'] === $categoryKey
                    ));
                ?>
                    <section class="customer-category-panel" data-custom-panel="<?= customerEscape($categoryKey) ?>" aria-label="<?= customerEscape($categoryInfo['title']) ?>" <?= $categoryKey !== 'Flower' ? 'hidden' : '' ?>>
                        <div class="product-category-heading">
                            <div>
                                <h3 class="h6 mb-1"><?= customerEscape($categoryInfo['title']) ?></h3>
                                <p class="text-secondary small mb-0"><?= customerEscape($categoryInfo['description']) ?></p>
                            </div>
                            <?php if ($categoryKey === 'Wrapper'): ?><span class="text-secondary small" id="wrapper-selection-count">0 of 2 types</span><?php endif; ?>
                        </div>
                        <?php if ($categoryItems === []): ?>
                            <p class="text-secondary small">No <?= customerEscape(strtolower($categoryInfo['title'])) ?> are currently in stock.</p>
                        <?php else: ?>
                            <div class="customer-component-grid">
                                <?php foreach ($categoryItems as $component): ?>
                                    <label class="customer-component-card">
                                        <input
                                            class="customer-component-choice"
                                            type="<?= $categoryKey === 'Ribbon' ? 'radio' : 'checkbox' ?>"
                                            name="customer_component_<?= customerEscape($categoryKey) ?><?= $categoryKey === 'Ribbon' ? '' : '[]' ?>"
                                            value="<?= (int) $component['ItemID'] ?>"
                                            data-item-id="<?= (int) $component['ItemID'] ?>"
                                            data-category="<?= customerEscape($categoryKey) ?>"
                                            data-name="<?= customerEscape($component['ItemName']) ?>"
                                            data-color="<?= customerEscape($component['Color']) ?>"
                                            data-price="<?= customerEscape($component['Price']) ?>"
                                            data-stock="<?= (int) $component['Stock'] ?>"
                                        >
                                        <?php if ($component['image'] !== ''): ?>
                                            <img src="<?= customerEscape($component['image']) ?>" alt="">
                                        <?php else: ?>
                                            <span class="customer-component-placeholder" aria-hidden="true">No image</span>
                                        <?php endif; ?>
                                        <span class="customer-component-copy">
                                            <strong><?= customerEscape($component['ItemName']) ?></strong>
                                            <span><?= customerEscape($component['Color']) ?></span>
                                            <span><?= (int) $component['Stock'] ?> in stock · ₱<?= number_format((float) $component['Price'], 2) ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
            <aside class="customer-selected-items" aria-label="Selected bouquet items">
                <div class="d-flex align-items-center justify-content-between gap-2 border-bottom pb-2">
                    <div>
                        <h3 class="h6 mb-1">Selected items</h3>
                        <p class="text-secondary small mb-0">Adjust quantities for your bouquet.</p>
                    </div>
                    <span class="badge text-bg-primary" id="custom-selected-count" aria-live="polite">0</span>
                </div>
                <p class="text-secondary small text-center my-3" id="custom-selected-empty">Your selected flowers, wrappers, ribbon, and add-ons will appear here.</p>
                <div class="customer-selected-list" id="custom-selected-list"></div>
                <div class="d-flex justify-content-between border-top pt-2 fw-semibold">
                    <span>Estimated bouquet price</span>
                    <span id="custom-selected-total">₱0.00</span>
                </div>
            </aside>
        </div>
        <div class="customer-customize-footer">
            <div>
                <label class="form-label mb-1" for="bouquet-name">Name your bouquet</label>
                <input id="bouquet-name" class="form-control" maxlength="50" placeholder="e.g. Birthday Blooms">
            </div>
            <div class="d-flex justify-content-end gap-2">
                <button class="btn btn-outline-secondary" id="cancel-customer-customizer" type="button">Cancel</button>
                <button class="btn btn-primary" id="add-custom" type="button">Add bouquet to bag</button>
            </div>
        </div>
    </div>
</dialog>
<?php if ($customerId < 1): ?>
<div class="modal fade" id="account-modal" tabindex="-1" aria-labelledby="account-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="text-uppercase small fw-bold text-secondary mb-1">Customer account</p>
                    <h2 class="modal-title fs-4" id="account-modal-title">Welcome to Petal & Bloom</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <?php if ($customerAuthStep !== ''): ?>
                    <p class="small text-secondary"><?= customerEscape($customerSuccess !== '' ? $customerSuccess : $customerError) ?></p>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="<?= $customerAuthStep === 'signin' ? 'verify_customer_signin_pin' : 'verify_customer_signup_pin' ?>">
                        <div class="mb-3">
                            <label class="form-label" for="customer-auth-pin">6-digit email verification PIN</label>
                            <input class="form-control" id="customer-auth-pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                        </div>
                        <button class="btn btn-rose w-100" type="submit"><?= $customerAuthStep === 'signin' ? 'Verify and sign in' : 'Verify and create account' ?></button>
                    </form>
                    <form method="post" class="text-center mt-2">
                        <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="cancel_customer_auth_pin">
                        <button class="btn btn-link btn-sm" type="submit">Back to account sign-in</button>
                    </form>
                <?php else: ?>
                <ul class="nav nav-tabs mb-4" role="tablist">
                    <li class="nav-item"><button class="nav-link active" id="signin-tab" data-bs-toggle="tab" data-bs-target="#signin-panel" type="button" role="tab" aria-controls="signin-panel" aria-selected="true">Sign in</button></li>
                    <li class="nav-item"><button class="nav-link" id="signup-tab" data-bs-toggle="tab" data-bs-target="#signup-panel" type="button" role="tab" aria-controls="signup-panel" aria-selected="false">Create account</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="signin-panel" role="tabpanel" aria-labelledby="signin-tab">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="login">
                            <div class="mb-3"><label class="form-label" for="login-email">Email</label><input class="form-control" id="login-email" name="email" type="email" autocomplete="email" required></div>
                            <div class="mb-3"><label class="form-label" for="login-password">Password</label><input class="form-control" id="login-password" name="password" type="password" autocomplete="current-password" required></div>
                            <div class="text-end mb-3"><button class="btn btn-link p-0 small" id="open-customer-password-reset" type="button">Forgot password?</button></div>
                            <button class="btn btn-rose w-100" type="submit">Sign in</button>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="signup-panel" role="tabpanel" aria-labelledby="signup-tab">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= customerEscape($csrfToken) ?>">
                            <input type="hidden" name="action" value="signup">
                            <div class="row">
                                <div class="col-sm-6 mb-3"><label class="form-label" for="first-name">First name</label><input class="form-control" id="first-name" name="first_name" maxlength="80" autocomplete="given-name" required></div>
                                <div class="col-sm-6 mb-3"><label class="form-label" for="last-name">Last name</label><input class="form-control" id="last-name" name="last_name" maxlength="80" autocomplete="family-name" required></div>
                            </div>
                            <div class="mb-3"><label class="form-label" for="signup-email">Email</label><input class="form-control" id="signup-email" name="email" type="email" maxlength="190" autocomplete="email" required></div>
                            <div class="mb-3"><label class="form-label" for="signup-phone">Phone</label><input class="form-control" id="signup-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" required></div>
                            <div class="mb-3"><label class="form-label" for="signup-password">Password</label><input class="form-control" id="signup-password" name="password" type="password" minlength="8" autocomplete="new-password" required><div class="form-text">Use at least 8 characters.</div></div>
                            <button class="btn btn-rose w-100" type="submit">Create account</button>
                        </form>
                    </div>
                </div>
                <?php if ($customerError !== ''): ?>
                    <div class="alert alert-danger mt-3 mb-0" role="alert"><?= customerEscape($customerError) ?></div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<dialog class="password-reset-dialog p-0 border-0 rounded-3 shadow" id="customer-password-reset-dialog" aria-labelledby="customer-password-reset-title">
    <form class="password-reset-form p-4" data-password-reset-form data-account-type="customer" data-csrf-token="<?= customerEscape($csrfToken) ?>">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
            <div>
                <h2 class="h4 mb-1" id="customer-password-reset-title">Reset your password</h2>
                <p class="text-secondary small mb-0">Customer account</p>
            </div>
            <button class="btn-close" type="button" data-password-reset-close aria-label="Close"></button>
        </div>
        <div data-reset-step="request">
            <p class="small text-secondary">Enter the email address registered to your account. If it matches, we will send a recovery PIN.</p>
            <div class="mb-3">
                <label class="form-label" for="customer-reset-email">Email address</label>
                <input class="form-control" id="customer-reset-email" name="email" type="email" maxlength="190" autocomplete="email" required>
            </div>
            <button class="btn btn-rose w-100" type="button" data-password-reset-send>Send PIN</button>
        </div>
        <div data-reset-step="verify" hidden>
            <div class="mb-3">
                <label class="form-label" for="customer-reset-pin">6-digit recovery PIN</label>
                <input class="form-control" id="customer-reset-pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="customer-reset-password">New password</label>
                <input class="form-control" id="customer-reset-password" name="new_password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="customer-reset-confirm-password">Confirm new password</label>
                <input class="form-control" id="customer-reset-confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary flex-fill" type="button" data-password-reset-back>Back</button>
                <button class="btn btn-rose flex-fill" type="submit">Reset password</button>
            </div>
        </div>
        <p class="small mt-3 mb-0" data-password-reset-message role="status" aria-live="polite"></p>
    </form>
</dialog>
<?php endif; ?>
<script src="bootstrap-5.3.8/js/bootstrap.bundle.min.js"></script>
<script src="password-reset.js"></script>
<script>
const cartStorageKey = 'petal-bloom-customer-cart-v1';
const serverRestoredCart = <?= json_encode($restoreCart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const cartRestoreFromServer = <?= $cartRestoreFromServer ? 'true' : 'false' ?>;
const loadStoredCart = () => {
    if (cartRestoreFromServer) return serverRestoredCart;
    try {
        const savedCart = JSON.parse(localStorage.getItem(cartStorageKey) || '[]');
        return Array.isArray(savedCart) ? savedCart : [];
    } catch (error) {
        console.warn('Unable to restore the saved shopping bag.', error);
        return [];
    }
};
const cart = loadStoredCart().filter(item => item && typeof item === 'object').map(item => ({
    ...item,
    selected: item.selected !== false
}));
const saveStoredCart = () => {
    try {
        localStorage.setItem(cartStorageKey, JSON.stringify(cart));
    } catch (error) {
        console.warn('Unable to save the shopping bag in this browser.', error);
    }
};
const customerCsrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const peso = value => '₱' + Number(value).toFixed(2);
const catalogPrices = <?= json_encode(array_column($products, 'Price', 'ProductID'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const catalogStocks = <?= json_encode(array_column($products, 'ProductStock', 'ProductID'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const catalogProducts = <?= json_encode(array_column($products, null, 'ProductID'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const catalogReviews = <?= json_encode($productReviews, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const catalogReviewsError = <?= json_encode($productReviewsError, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const catalogFilterButtons = [...document.querySelectorAll('[data-catalog-filter]')];
const catalogItems = [...document.querySelectorAll('.catalog-item')];
const catalogEventChoices = [...document.querySelectorAll('[data-catalog-event]')];
const catalogRatingOptions = [...document.querySelectorAll('[data-min-rating]')];
const catalogEventCount = document.getElementById('catalog-event-count');
const catalogRatingCount = document.getElementById('catalog-rating-count');
const catalogNoMatches = document.getElementById('catalog-no-matches');
const storeSearchForm = document.getElementById('store-search-form');
const storeSearchInput = document.getElementById('store-search-input');
let activeCatalogFilter = 'all';
let minimumCatalogRating = 0;
const catalogPageSize = 12;
let currentCatalogPage = 1;
const catalogPagination = document.getElementById('catalog-pagination');
const catalogPaginationList = document.getElementById('catalog-pagination-list');
const applyCatalogFilters = (resetPage = false) => {
    if (resetPage) currentCatalogPage = 1;
    const selectedEvents = catalogEventChoices.filter(input => input.checked).map(input => input.value);
    const searchTerm = storeSearchInput?.value.trim().toLocaleLowerCase() || '';
    const matchingItems = [];
    catalogItems.forEach(item => {
        const tags = (item.dataset.catalogTags || '').split(/\s+/).filter(Boolean);
        const searchText = (item.dataset.catalogSearch || '').toLocaleLowerCase();
        let events = [];
        try {
            const parsedEvents = JSON.parse(item.dataset.catalogEvents || '[]');
            events = Array.isArray(parsedEvents) ? parsedEvents : [];
        } catch (error) {
            console.error('Unable to read product event categories.', error);
        }
        const matchesFilter = activeCatalogFilter === 'all' || tags.includes(activeCatalogFilter);
        const matchesEvents = selectedEvents.length === 0 || selectedEvents.some(event => events.includes(event));
        const matchesSearch = searchTerm === '' || searchText.includes(searchTerm);
        const productRating = Number(item.dataset.catalogRating || 0);
        const matchesRating = minimumCatalogRating === 0 || productRating >= minimumCatalogRating;
        const matches = matchesFilter && matchesEvents && matchesSearch && matchesRating;
        item.hidden = !matches;
        if (matches) matchingItems.push(item);
    });
    const pageCount = Math.ceil(matchingItems.length / catalogPageSize);
    currentCatalogPage = Math.min(currentCatalogPage, Math.max(pageCount, 1));
    const pageStart = (currentCatalogPage - 1) * catalogPageSize;
    matchingItems.forEach((item, index) => {
        item.hidden = index < pageStart || index >= pageStart + catalogPageSize;
    });
    if (catalogEventCount) {
        catalogEventCount.textContent = selectedEvents.length > 0 ? `(${selectedEvents.length})` : '';
    }
    if (catalogNoMatches) {
        catalogNoMatches.hidden = matchingItems.length > 0;
    }
    if (catalogPagination && catalogPaginationList) {
        catalogPagination.hidden = pageCount <= 1;
        catalogPaginationList.replaceChildren();
        if (pageCount > 1) {
            const appendPageButton = (label, page, {active = false, disabled = false, ariaLabel = label} = {}) => {
                const item = document.createElement('li');
                item.className = `page-item${active ? ' active' : ''}${disabled ? ' disabled' : ''}`;
                const button = document.createElement('button');
                button.className = 'page-link';
                button.type = 'button';
                button.textContent = label;
                button.setAttribute('aria-label', ariaLabel);
                if (active) button.setAttribute('aria-current', 'page');
                if (disabled) button.disabled = true;
                else button.addEventListener('click', () => {
                    currentCatalogPage = page;
                    applyCatalogFilters();
                    document.getElementById('catalog')?.scrollIntoView({behavior: 'smooth', block: 'start'});
                });
                item.append(button);
                catalogPaginationList.append(item);
            };
            appendPageButton('‹', currentCatalogPage - 1, {
                disabled: currentCatalogPage === 1,
                ariaLabel: 'Previous product page'
            });
            appendPageButton(String(currentCatalogPage), currentCatalogPage, {
                active: true,
                disabled: true,
                ariaLabel: `Current product page ${currentCatalogPage} of ${pageCount}`
            });
            appendPageButton('›', currentCatalogPage + 1, {
                disabled: currentCatalogPage === pageCount,
                ariaLabel: 'Next product page'
            });
        }
    }
};
storeSearchInput?.addEventListener('input', () => applyCatalogFilters(true));
storeSearchForm?.addEventListener('submit', event => {
    event.preventDefault();
    applyCatalogFilters(true);
    document.getElementById('catalog')?.scrollIntoView({behavior: 'smooth', block: 'start'});
});
catalogFilterButtons.forEach(button => button.addEventListener('click', () => {
    activeCatalogFilter = button.dataset.catalogFilter || 'all';
    catalogFilterButtons.forEach(filterButton => {
        const isActive = filterButton === button;
        filterButton.classList.toggle('active', isActive);
        filterButton.setAttribute('aria-pressed', String(isActive));
    });
    applyCatalogFilters(true);
}));
catalogEventChoices.forEach(input => input.addEventListener('change', () => applyCatalogFilters(true)));
catalogRatingOptions.forEach(button => button.addEventListener('click', () => {
    minimumCatalogRating = Number(button.dataset.minRating || 0);
    catalogRatingOptions.forEach(option => option.setAttribute('aria-pressed', String(option === button)));
    const ratingsDropdown = document.getElementById('catalog-ratings-dropdown');
    if (ratingsDropdown) ratingsDropdown.open = false;
    if (catalogRatingCount) {
        catalogRatingCount.textContent = minimumCatalogRating > 0 ? `(${minimumCatalogRating}+)` : '';
    }
    applyCatalogFilters(true);
}));
document.getElementById('clear-catalog-events')?.addEventListener('click', () => {
    catalogEventChoices.forEach(input => {
        input.checked = false;
    });
    applyCatalogFilters(true);
});
applyCatalogFilters();
cart.forEach(item => {
    if (item.product_id > 0 && Object.prototype.hasOwnProperty.call(catalogPrices, item.product_id)) {
        item.price = Number(catalogPrices[item.product_id]);
    }
});
let editingCartIndex = null;
const accountModal = document.getElementById('account-modal');
const requireCustomerAccount = () => {
    if (<?= $customerId > 0 ? 'true' : 'false' ?>) {
        return true;
    }
    if (addonsDialog.open) addonsDialog.close();
    if (customizeDialog.open) customizeDialog.close();
    if (productDetailsModalElement.classList.contains('show')) {
        productDetailsModalElement.addEventListener('hidden.bs.modal', () => {
            bootstrap.Modal.getOrCreateInstance(accountModal).show();
        }, {once: true});
        productDetailsModal.hide();
    } else {
        bootstrap.Modal.getOrCreateInstance(accountModal).show();
    }
    return false;
};
const renderCart = () => {
    const container = document.getElementById('cart-items');
    saveStoredCart();
    const selectedItems = cart.filter(item => item.selected !== false);
    const total = selectedItems.reduce((sum, item) => sum + item.price * item.quantity, 0);
    const itemCount = cart.reduce((sum, item) => sum + item.quantity, 0);
    document.getElementById('cart-total').textContent = peso(total);
    document.getElementById('cart-count').textContent = cart.length ? '(' + cart.length + ')' : '';
    document.getElementById('nav-cart-count').textContent = itemCount ? (itemCount > 99 ? '99+' : String(itemCount)) : '';
    document.querySelector('.store-nav-link[data-open-cart]').setAttribute(
        'aria-label',
        'Shopping cart, ' + itemCount + (itemCount === 1 ? ' item' : ' items')
    );
    if (!cart.length) {
        container.innerHTML = '<p class="text-secondary mb-0">Your bag is empty.</p>';
        return;
    }
    container.innerHTML = (selectedItems.length ? '' : '<p class="small text-secondary mb-2">Select at least one item to purchase.</p>') + cart.map((item, index) =>
        '<article class="cart-line-item">' +
        '<div><strong>' + escapeHtml(item.name) + '</strong><div class="small text-secondary">' +
        escapeHtml(item.details || '') + '</div><label class="small d-block mt-2"><input class="form-check-input me-1 cart-selection" data-index="' + index + '" type="checkbox"' + (item.selected !== false ? ' checked' : '') + ' aria-label="Select ' + escapeHtml(item.name) + ' to purchase">Purchase this item</label><label class="small">Qty <input class="form-control form-control-sm d-inline-block ms-1 cart-quantity" data-index="' + index + '" type="number" min="1" max="' + Math.min(99, Number(item.stock) || 99) + '" value="' + item.quantity + '" style="width:75px"></label></div>' +
        '<div class="text-end text-nowrap fw-semibold">' + peso(item.price * item.quantity) + '<br>' +
        (item.custom && item.custom.type !== 'addon'
            ? '<button class="btn btn-link btn-sm p-0 edit-custom-item" data-index="' + index + '">Edit bouquet</button><br>'
            : '') +
        '<button class="btn btn-link btn-sm text-danger p-0 remove-item" data-index="' + index + '">Remove</button></div></article>'
    ).join('');
    container.querySelectorAll('.cart-selection').forEach(input => input.addEventListener('change', () => {
        cart[Number(input.dataset.index)].selected = input.checked;
        renderCart();
    }));
    container.querySelectorAll('.cart-quantity').forEach(input => input.addEventListener('change', () => {
        const item = cart[Number(input.dataset.index)];
        item.quantity = Math.max(1, Math.min(99, Number(item.stock) || 99, Number(input.value) || 1));
        renderCart();
    }));
    container.querySelectorAll('.remove-item').forEach(button => button.addEventListener('click', () => {
        cart.splice(Number(button.dataset.index), 1);
        renderCart();
    }));
};
const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
}[char]));
const productDetailsModalElement = document.getElementById('product-details-modal');
const productDetailsModal = bootstrap.Modal.getOrCreateInstance(productDetailsModalElement);
const productDetailsTitle = document.getElementById('product-details-title');
const productDetailsImage = document.getElementById('product-details-image');
const productDetailsRating = document.getElementById('product-details-rating');
const productDetailsDescription = document.getElementById('product-details-description');
const productDetailsFacts = document.getElementById('product-details-facts');
const productDetailsReviews = document.getElementById('product-details-reviews');
const productDetailsAddButton = document.getElementById('product-details-add');
const productDetailsBuyButton = document.getElementById('product-details-buy');
let selectedProductDetails = null;
const addProductToCart = (id, name) => {
    if (!requireCustomerAccount()) return false;
    const stockValue = catalogStocks[id];
    const stock = stockValue === null || stockValue === undefined ? null : Number(stockValue);
    const existing = cart.find(item => item.product_id === id);
    if (stock !== null && (stock < 1 || (existing && existing.quantity >= stock))) {
        window.alert('There is no more stock available for this product.');
        return false;
    }
    if (existing) {
        existing.quantity = Math.min(99, stock === null ? 99 : stock, existing.quantity + 1);
        if (stock !== null) existing.stock = stock;
    } else {
        cart.push({
            product_id: id,
            name,
            price: Number(catalogPrices[id]),
            quantity: 1,
            selected: true,
            ...(stock === null ? {} : {stock})
        });
    }
    renderCart();
    return true;
};
const appendProductFact = (label, value) => {
    const term = document.createElement('dt');
    term.className = 'col-5 text-secondary';
    term.textContent = label;
    const detail = document.createElement('dd');
    detail.className = 'col-7';
    detail.textContent = value;
    productDetailsFacts.append(term, detail);
};
const openProductDetails = productId => {
    const product = catalogProducts[productId];
    if (!product) return;
    selectedProductDetails = product;
    productDetailsTitle.textContent = product.ProductName;
    productDetailsImage.hidden = !product.image;
    if (product.image) {
        productDetailsImage.src = product.image;
    } else {
        productDetailsImage.removeAttribute('src');
    }
    productDetailsImage.alt = product.ProductName;
    const description = String(product.Description || '').trim();
    productDetailsDescription.textContent = description || 'Beautifully prepared by our florists.';
    productDetailsFacts.replaceChildren();
    appendProductFact('Price', peso(product.Price));
    if (Number(product.DiscountPercent) > 0) {
        appendProductFact('Original price', peso(product.OriginalPrice));
        appendProductFact('Discount', `${Number(product.DiscountPercent)}% off`);
    }
    appendProductFact('Categories', product.Categories.length ? product.Categories.join(', ') : 'General');
    appendProductFact(
        'Availability',
        product.ProductStock === null ? 'Available' : `${Number(product.ProductStock)} in stock`
    );
    appendProductFact('Product ID', `#${Number(product.ProductID)}`);

    const ratingCount = Number(product.RatingCount);
    const averageRating = Number(product.AverageRating);
    productDetailsRating.textContent = ratingCount > 0
        ? `${'★'.repeat(Math.round(averageRating))}${'☆'.repeat(5 - Math.round(averageRating))}  ${averageRating.toFixed(1)} out of 5 · ${ratingCount} ${ratingCount === 1 ? 'rating' : 'ratings'}`
        : 'No ratings yet';
    productDetailsRating.setAttribute('aria-label', ratingCount > 0
        ? `${averageRating.toFixed(1)} out of 5 stars, ${ratingCount} ratings`
        : 'No ratings yet');

    productDetailsReviews.replaceChildren();
    const reviews = catalogReviews[productId] || [];
    if (reviews.length === 0) {
        const emptyState = document.createElement('p');
        emptyState.className = 'small text-secondary mb-0';
        emptyState.textContent = catalogReviewsError || 'No customer reviews yet.';
        productDetailsReviews.append(emptyState);
    } else {
        if (catalogReviewsError) {
            const reviewError = document.createElement('p');
            reviewError.className = 'alert alert-warning py-2 mb-0';
            reviewError.setAttribute('role', 'status');
            reviewError.textContent = catalogReviewsError;
            productDetailsReviews.append(reviewError);
        }
        reviews.forEach(review => {
            const reviewCard = document.createElement('article');
            reviewCard.className = 'product-details-review';
            const reviewHeader = document.createElement('div');
            reviewHeader.className = 'd-flex justify-content-between align-items-start gap-2';
            const reviewer = document.createElement('strong');
            reviewer.textContent = review.reviewer || 'Customer';
            const date = document.createElement('time');
            date.className = 'small text-secondary text-nowrap';
            date.textContent = review.date;
            date.dateTime = review.created_at.replace(' ', 'T');
            reviewHeader.append(reviewer, date);
            const stars = document.createElement('p');
            stars.className = 'product-details-review-stars mb-1';
            stars.setAttribute('role', 'img');
            stars.setAttribute('aria-label', `${review.rating} out of 5 stars`);
            stars.textContent = `${'★'.repeat(review.rating)}${'☆'.repeat(5 - review.rating)}`;
            const reviewText = document.createElement('p');
            reviewText.className = 'small mb-0';
            reviewText.textContent = String(review.review || '').trim() || 'Rated without a written review.';
            reviewCard.append(reviewHeader, stars, reviewText);
            if (Array.isArray(review.images) && review.images.length > 0) {
                const images = document.createElement('div');
                images.className = 'customer-review-image-list product-details-review-images mt-2';
                images.setAttribute('aria-label', `Review images from ${review.reviewer || 'customer'}`);
                review.images.forEach((imagePath, index) => {
                    const link = document.createElement('a');
                    link.href = imagePath;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    const image = document.createElement('img');
                    image.src = imagePath;
                    image.alt = `Customer review image ${index + 1} for ${product.ProductName}`;
                    image.loading = 'lazy';
                    link.append(image);
                    images.append(link);
                });
                reviewCard.append(images);
            }
            productDetailsReviews.append(reviewCard);
        });
    }

    const outOfStock = product.ProductStock !== null && Number(product.ProductStock) < 1;
    productDetailsAddButton.disabled = outOfStock;
    productDetailsBuyButton.disabled = outOfStock;
    productDetailsAddButton.textContent = outOfStock ? 'Sold Out' : 'Add to Bag';
    productDetailsBuyButton.textContent = outOfStock ? 'Sold Out' : 'Buy Now';
    productDetailsModal.show();
};
document.querySelectorAll('.product-card[data-product-details-id]').forEach(card => {
    card.addEventListener('click', event => {
        if (event.target.closest('button, a, input, select, textarea')) return;
        openProductDetails(Number(card.dataset.productDetailsId));
    });
    card.addEventListener('keydown', event => {
        if (event.target !== card || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        openProductDetails(Number(card.dataset.productDetailsId));
    });
});
document.querySelectorAll('.add-product').forEach(button => button.addEventListener('click', () => {
    addProductToCart(Number(button.dataset.productId), button.dataset.productName);
}));
productDetailsAddButton.addEventListener('click', () => {
    if (selectedProductDetails) {
        addProductToCart(Number(selectedProductDetails.ProductID), selectedProductDetails.ProductName);
    }
});
productDetailsBuyButton.addEventListener('click', () => {
    if (
        selectedProductDetails
        && addProductToCart(Number(selectedProductDetails.ProductID), selectedProductDetails.ProductName)
    ) {
        productDetailsModal.hide();
        showCartPage();
    }
});
document.querySelectorAll('.add-addon').forEach(button => button.addEventListener('click', () => {
    if (!requireCustomerAccount()) return;
    const itemId = Number(button.dataset.itemId);
    const stock = Number(button.dataset.itemStock);
    const existing = cart.find(item => item.custom?.type === 'addon' && item.custom.components[0]?.item_id === itemId);
    if (existing) {
        if (existing.quantity >= stock) {
            window.alert('There is no more stock available for this add-on.');
            return;
        }
        existing.quantity += 1;
    } else {
        cart.push({
            product_id: 0,
            name: button.dataset.itemName,
            price: Number(button.dataset.itemPrice),
            quantity: 1,
            selected: true,
            stock,
            custom: {
                type: 'addon',
                name: button.dataset.itemName,
                components: [{item_id: itemId, quantity: 1}]
            }
        });
    }
    renderCart();
}));
const customizeDialog = document.getElementById('customer-customize-dialog');
const cartPage = document.getElementById('customer-cart-page');
const ordersPage = document.getElementById('orders-page');
const notificationsPage = document.getElementById('notifications-page');
const storeMain = document.getElementById('store-main');
const storeSearchWrap = document.getElementById('store-search-wrap');
const customerOrderCategoryForStatus = status => ({
    'Pending review': 'to-be-approved',
    Pending: 'to-be-approved',
    Received: 'received',
    'Out for delivery': 'out-for-delivery',
    Delivered: 'delivered',
    Completed: 'completed',
    Cancelled: 'cancelled',
    Declined: 'declined'
}[status] || 'pending');
const customerOrderFilterButtons = [...document.querySelectorAll('.customer-order-category-filter')];
const customerOrderFilterRows = [...document.querySelectorAll('.customer-order-filter-row')];
const customerOrderNoMatches = document.getElementById('customer-orders-no-matches');
let selectedCustomerOrderCategory = 'all';
const applyCustomerOrderFilter = () => {
    let visibleOrders = 0;
    customerOrderFilterRows.forEach(order => {
        order.hidden = selectedCustomerOrderCategory !== 'all'
            && order.dataset.customerOrderCategory !== selectedCustomerOrderCategory;
        if (!order.hidden) visibleOrders++;
    });
    if (customerOrderNoMatches) {
        customerOrderNoMatches.hidden = customerOrderFilterRows.length === 0 || visibleOrders > 0;
    }
};
customerOrderFilterButtons.forEach(button => button.addEventListener('click', () => {
    selectedCustomerOrderCategory = button.dataset.customerOrderCategory || 'all';
    customerOrderFilterButtons.forEach(categoryButton => {
        const isSelected = categoryButton === button;
        categoryButton.classList.toggle('active', isSelected);
        categoryButton.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    });
    applyCustomerOrderFilter();
}));
applyCustomerOrderFilter();
const customizeButton = document.getElementById('open-customer-customizer');
const storeFooter = document.querySelector('.store-footer');
const addonsDialog = document.getElementById('customer-addons-dialog');
let previousScrollY = window.scrollY;
window.addEventListener('scroll', () => {
    const currentScrollY = window.scrollY;
    if (
        cartPage.hidden
        && (!ordersPage || ordersPage.hidden)
        && (!notificationsPage || notificationsPage.hidden)
    ) {
        const footerTop = storeFooter.getBoundingClientRect().top;
        if (currentScrollY < previousScrollY && footerTop >= window.innerHeight) {
            customizeButton.hidden = false;
        } else if (currentScrollY > previousScrollY && footerTop <= window.innerHeight) {
            customizeButton.hidden = true;
        }
    }
    previousScrollY = currentScrollY;
}, {passive: true});
const showCartPage = () => {
    cartPage.hidden = false;
    if (ordersPage) ordersPage.hidden = true;
    if (notificationsPage) notificationsPage.hidden = true;
    storeSearchWrap.hidden = true;
    storeMain.hidden = true;
    storeFooter.hidden = true;
    customizeButton.hidden = true;
    history.replaceState(null, '', '#cart');
    window.scrollTo({top: 0, behavior: 'auto'});
};
const hideCartPage = () => {
    cartPage.hidden = true;
    if (ordersPage) ordersPage.hidden = true;
    if (notificationsPage) notificationsPage.hidden = true;
    storeSearchWrap.hidden = false;
    storeMain.hidden = false;
    storeFooter.hidden = false;
    customizeButton.hidden = false;
    window.scrollTo({top: 0, behavior: 'auto'});
};
const showOrdersPage = () => {
    if (!ordersPage) return;
    cartPage.hidden = true;
    ordersPage.hidden = false;
    if (notificationsPage) notificationsPage.hidden = true;
    storeSearchWrap.hidden = true;
    storeMain.hidden = true;
    storeFooter.hidden = true;
    customizeButton.hidden = true;
    history.replaceState(null, '', '#my-orders');
    window.scrollTo({top: 0, behavior: 'auto'});
};
const showNotificationsPage = () => {
    if (!notificationsPage) return;
    cartPage.hidden = true;
    if (ordersPage) ordersPage.hidden = true;
    notificationsPage.hidden = false;
    storeSearchWrap.hidden = true;
    storeMain.hidden = true;
    storeFooter.hidden = true;
    customizeButton.hidden = true;
    history.replaceState(null, '', '#notifications');
    window.scrollTo({top: 0, behavior: 'auto'});
};
document.querySelectorAll('[data-open-cart]').forEach(button => button.addEventListener('click', showCartPage));
document.querySelectorAll('[data-open-home]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    hideCartPage();
    history.replaceState(null, '', '#catalog');
}));
document.querySelectorAll('[data-open-orders]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    showOrdersPage();
}));
document.querySelectorAll('[data-open-notifications]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    showNotificationsPage();
}));
if (notificationsPage) {
    const initialOrderStatuses = <?= json_encode(
        (object) array_map(static fn (array $order): string => $order['Status'], $customerOrders),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
    const knownOrderStatuses = new Map(
        Object.entries(initialOrderStatuses).map(([orderId, status]) => [String(orderId), status])
    );
    const notificationList = document.getElementById('customer-order-notifications');
    const notificationAlert = document.getElementById('customer-order-update-alert');
    const notificationCount = document.getElementById('nav-notification-count');
    const readNotificationStorageKey = 'customer-order-notifications-read-v1-<?= $customerId ?>';
    let readNotificationStatuses = new Map();
    try {
        const storedReadStatuses = JSON.parse(localStorage.getItem(readNotificationStorageKey) || '{}');
        if (storedReadStatuses && typeof storedReadStatuses === 'object' && !Array.isArray(storedReadStatuses)) {
            readNotificationStatuses = new Map(
                Object.entries(storedReadStatuses).filter(([, status]) => typeof status === 'string')
            );
        }
    } catch (error) {
        console.error('Unable to load notification read state.', error);
    }
    const markNotificationsRead = () => {
        knownOrderStatuses.forEach((status, orderId) => {
            if (['Accepted', 'Out for delivery', 'Delivered', 'Declined', 'Cancelled'].includes(status)) {
                readNotificationStatuses.set(orderId, status);
            }
        });
        try {
            localStorage.setItem(readNotificationStorageKey, JSON.stringify(Object.fromEntries(readNotificationStatuses)));
        } catch (error) {
            console.error('Unable to save notification read state.', error);
        }
        notificationCount.textContent = '';
        notificationCount.hidden = true;
        notificationCount.setAttribute('aria-label', '0 order updates');
    };
    document.querySelectorAll('[data-open-notifications]').forEach(link => {
        link.addEventListener('click', markNotificationsRead);
    });
    const statusMessage = status => {
        if (status === 'Accepted') return 'The shop has accepted your order.';
        if (status === 'Out for delivery') return 'Your order is on its way.';
        if (status === 'Delivered') return 'Your order is marked delivered. Please confirm when you have received it.';
        if (status === 'Received') return 'Your order has been marked received.';
        if (status === 'Completed') return 'Your order is complete.';
        if (status === 'Declined') return 'The shop has declined your order.';
        if (status === 'Cancelled') return 'You cancelled this order before it was approved.';
        if (status === 'Pending review' || status === 'Pending') {
            return 'Your order was received and is waiting for the shop to review it.';
        }
        return 'Your order status was updated by the shop.';
    };
    const renderOrderNotifications = orders => {
        notificationList.replaceChildren();
        if (!orders.length) {
            const emptyMessage = document.createElement('div');
            emptyMessage.className = 'alert alert-info mb-0';
            emptyMessage.textContent = 'You do not have any order updates yet. Updates will appear here after you place an order.';
            notificationList.appendChild(emptyMessage);
            return;
        }
        orders.forEach(order => {
            const card = document.createElement('article');
            card.className = 'customer-order-card';
            const header = document.createElement('div');
            header.className = 'd-flex flex-wrap align-items-start justify-content-between gap-3';
            const details = document.createElement('div');
            const title = document.createElement('h2');
            title.className = 'h5 mb-1';
            title.textContent = 'Order #' + order.order_id;
            const message = document.createElement('p');
            message.className = 'mb-1';
            message.textContent = statusMessage(order.status);
            const placed = document.createElement('p');
            placed.className = 'small text-secondary mb-0';
            placed.textContent = 'Placed ' + new Date(order.order_date.replace(' ', 'T')).toLocaleString();
            const status = document.createElement('span');
            status.className = 'customer-order-status';
            status.textContent = order.status;
            details.append(title, message, placed);
            header.append(details, status);
            const fulfillment = document.createElement('p');
            fulfillment.className = 'small text-secondary mt-3 mb-0';
            const fulfillmentDate = new Date(order.fulfillment_date + 'T' + order.fulfillment_time);
            fulfillment.textContent =
                order.fulfillment_type.charAt(0).toUpperCase() + order.fulfillment_type.slice(1)
                + ' · ' + fulfillmentDate.toLocaleString()
                + ' · ₱' + Number(order.total_amount).toFixed(2);
            card.append(header, fulfillment);
            if (order.status === 'Delivered' && order.fulfillment_type === 'delivery') {
                const confirmationForm = document.createElement('form');
                confirmationForm.method = 'post';
                confirmationForm.className = 'mt-3';
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = customerCsrfToken;
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'confirm_order_received';
                const orderInput = document.createElement('input');
                orderInput.type = 'hidden';
                orderInput.name = 'order_id';
                orderInput.value = String(order.order_id);
                const confirmButton = document.createElement('button');
                confirmButton.className = 'btn btn-rose btn-sm';
                confirmButton.type = 'submit';
                confirmButton.textContent = 'Confirm order received';
                confirmationForm.append(csrfInput, actionInput, orderInput, confirmButton);
                card.appendChild(confirmationForm);
            }
            notificationList.appendChild(card);
        });
    };
    const refreshCustomerOrderUpdates = async () => {
        try {
            const response = await fetch('CustomerShop.php?customer_order_updates=1', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'}
            });
            if (!response.ok) {
                throw new Error('Order update request failed with status ' + response.status);
            }
            const updateData = await response.json();
            if (!Array.isArray(updateData.orders)) {
                throw new Error('Order update response did not contain an order list');
            }
            let newDecisionMessage = '';
            updateData.orders.forEach(order => {
                const orderId = String(order.order_id);
                const previousStatus = knownOrderStatuses.get(orderId);
                if (previousStatus !== undefined && previousStatus !== order.status
                    && ['Accepted', 'Out for delivery', 'Delivered', 'Declined', 'Cancelled'].includes(order.status)) {
                    newDecisionMessage = 'Order #' + orderId + ': ' + statusMessage(order.status);
                }
                knownOrderStatuses.set(orderId, order.status);
                const orderStatus = document.querySelector('[data-order-status-id="' + orderId + '"]');
                if (orderStatus) {
                    orderStatus.textContent = order.status;
                    const orderCard = orderStatus.closest('.customer-order-filter-row');
                    if (orderCard) {
                        orderCard.dataset.customerOrderCategory = customerOrderCategoryForStatus(order.status);
                    }
                    const cancelForm = orderStatus.closest('.customer-order-card')?.querySelector('form');
                    if (cancelForm && !['Pending review', 'Pending'].includes(order.status)) {
                        cancelForm.remove();
                    }
                }
            });
            applyCustomerOrderFilter();
            const unreadCount = updateData.orders.filter(order =>
                ['Accepted', 'Out for delivery', 'Delivered', 'Declined', 'Cancelled'].includes(order.status)
                && readNotificationStatuses.get(String(order.order_id)) !== order.status
            ).length;
            notificationCount.textContent = unreadCount > 99 ? '99+' : (unreadCount > 0 ? String(unreadCount) : '');
            notificationCount.hidden = unreadCount === 0;
            notificationCount.setAttribute('aria-label', unreadCount + ' order updates');
            renderOrderNotifications(updateData.orders);
            if (newDecisionMessage !== '') {
                notificationAlert.textContent = newDecisionMessage;
                notificationAlert.hidden = false;
            }
        } catch (error) {
            console.error('Unable to refresh customer order updates.', error);
        }
    };
    refreshCustomerOrderUpdates();
    window.setInterval(refreshCustomerOrderUpdates, 15000);
}
document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) return;
    const openButton = event.target.closest('[data-open-rating-dialog]');
    if (openButton) {
        const ratingModal = document.getElementById('customer-rating-modal');
        if (!ratingModal) return;
        document.getElementById('customer-rating-modal-title').textContent =
            'Review ' + (openButton.dataset.productName || 'this product');
        document.getElementById('customer-rating-order-id').value = openButton.dataset.orderId || '';
        document.getElementById('customer-rating-order-item-id').value = openButton.dataset.orderItemId || '';
        document.getElementById('customer-rating-review').value = openButton.dataset.currentReview || '';
        const existingImages = (openButton.dataset.currentImages || '').split('|').filter(Boolean);
        const existingImagesContainer = document.getElementById('customer-rating-existing-images');
        existingImagesContainer.replaceChildren(...existingImages.map(imagePath => {
            const link = document.createElement('a');
            link.href = imagePath;
            link.target = '_blank';
            link.rel = 'noopener';
            const image = document.createElement('img');
            image.src = imagePath;
            image.alt = 'Existing product review image';
            link.appendChild(image);
            return link;
        }));
        const imageInput = document.getElementById('customer-rating-images');
        imageInput.value = '';
        imageInput.setCustomValidity('');
        imageInput.dataset.existingImageCount = String(existingImages.length);
        const currentRating = openButton.dataset.currentRating || '';
        const ratingStars = ratingModal.querySelector('.customer-review-stars');
        ratingModal.querySelectorAll('input[name="rating"]').forEach(input => {
            input.checked = input.value === currentRating;
        });
        ratingStars.dataset.rating = currentRating;
        bootstrap.Modal.getOrCreateInstance(ratingModal).show();
    }
});
document.querySelectorAll('#customer-rating-modal input[name="rating"]').forEach(input => {
    input.addEventListener('change', () => {
        if (input.checked) {
            input.closest('.customer-review-stars').dataset.rating = input.value;
        }
    });
});
document.getElementById('customer-rating-images').addEventListener('change', event => {
    const imageInput = event.currentTarget;
    const existingImageCount = Number(imageInput.dataset.existingImageCount || 0);
    imageInput.setCustomValidity(existingImageCount + imageInput.files.length > 3
        ? 'A product review can have no more than 3 images in total.'
        : '');
    if (!imageInput.validity.valid) {
        imageInput.reportValidity();
    }
});
document.getElementById('customer-rating-modal').addEventListener('hidden.bs.modal', () => {
    const imageInput = document.getElementById('customer-rating-images');
    imageInput.value = '';
    imageInput.setCustomValidity('');
});
document.getElementById('open-addons-dialog').addEventListener('click', () => addonsDialog.showModal());
document.getElementById('close-addons-dialog').addEventListener('click', () => addonsDialog.close());
addonsDialog.addEventListener('click', event => {
    if (event.target === addonsDialog) {
        addonsDialog.close();
    }
});
document.querySelector('[data-open-home]').addEventListener('click', event => {
    event.preventDefault();
    hideCartPage();
    history.replaceState(null, '', '#catalog');
    document.getElementById('catalog').scrollIntoView({behavior: 'smooth'});
});
cartPage.querySelector('[data-bs-target="#account-modal"]')?.addEventListener('click', hideCartPage);
const componentChoices = [...customizeDialog.querySelectorAll('.customer-component-choice')];
const selectedComponents = new Map();
const categoryNames = {Flower: 'Flowers', Wrapper: 'Wrappers', Ribbon: 'Ribbon', Addons: 'Add-ons'};
const renderSelectedComponents = () => {
    const list = document.getElementById('custom-selected-list');
    const emptyState = document.getElementById('custom-selected-empty');
    const selected = [...selectedComponents.values()];
    const total = selected.reduce((sum, component) => sum + Number(component.price) * component.quantity, 0);
    document.getElementById('custom-selected-count').textContent = String(selected.length);
    document.getElementById('custom-selected-total').textContent = peso(total);
    document.getElementById('wrapper-selection-count').textContent =
        selected.filter(component => component.category === 'Wrapper').length + ' of 2 types';
    emptyState.hidden = selected.length > 0;
    list.innerHTML = selected.map(component =>
        '<div class="customer-selected-row">' +
        '<div><strong>' + escapeHtml(component.name) + '</strong><small>' + escapeHtml(categoryNames[component.category]) +
        ' · ' + peso(component.price) + ' each</small></div>' +
        '<div class="customer-selected-controls">' +
        '<button type="button" data-component-action="decrease" data-item-id="' + component.item_id + '" aria-label="Decrease ' + escapeHtml(component.name) + ' quantity">−</button>' +
        '<output aria-label="Quantity">' + component.quantity + '</output>' +
        '<button type="button" data-component-action="increase" data-item-id="' + component.item_id + '" aria-label="Increase ' + escapeHtml(component.name) + ' quantity">+</button>' +
        '<button type="button" data-component-action="remove" data-item-id="' + component.item_id + '" aria-label="Remove ' + escapeHtml(component.name) + '">×</button>' +
        '</div></div>'
    ).join('');
    componentChoices.forEach(input => {
        input.closest('.customer-component-card').classList.toggle('selected', input.checked);
    });
};
const resetCustomizer = () => {
    editingCartIndex = null;
    selectedComponents.clear();
    componentChoices.forEach(input => { input.checked = false; });
    document.getElementById('bouquet-name').value = '';
    document.getElementById('add-custom').textContent = 'Add bouquet to bag';
    renderSelectedComponents();
};
const openCustomItemEditor = index => {
    const item = cart[index];
    if (!item?.custom || item.custom.type === 'addon' || !Array.isArray(item.custom.components)) {
        return;
    }
    resetCustomizer();
    const inputsById = new Map(componentChoices.map(input => [Number(input.dataset.itemId), input]));
    const unavailableComponents = item.custom.components.filter(component => !inputsById.has(Number(component.item_id)));
    if (unavailableComponents.length) {
        window.alert('Some bouquet components are no longer available to customize. Remove this bouquet and create a new one.');
        return;
    }
    editingCartIndex = index;
    document.getElementById('bouquet-name').value = item.custom.name;
    item.custom.components.forEach(component => {
        const input = inputsById.get(Number(component.item_id));
        input.checked = true;
        selectedComponents.set(Number(component.item_id), {
            item_id: Number(component.item_id),
            name: input.dataset.name,
            category: input.dataset.category,
            price: Number(input.dataset.price),
            stock: Number(input.dataset.stock),
            quantity: Number(component.quantity)
        });
    });
    document.getElementById('add-custom').textContent = 'Save changes';
    renderSelectedComponents();
    customizeDialog.showModal();
};
document.getElementById('cart-items').addEventListener('click', event => {
    const button = event.target.closest('.edit-custom-item');
    if (button) {
        openCustomItemEditor(Number(button.dataset.index));
    }
});
document.getElementById('open-customer-customizer').addEventListener('click', () => {
    resetCustomizer();
    customizeDialog.showModal();
});
document.querySelectorAll('[data-open-carousel-customizer]').forEach(button => {
    button.addEventListener('click', () => document.getElementById('open-customer-customizer').click());
});
document.getElementById('close-customer-customizer').addEventListener('click', () => customizeDialog.close());
document.getElementById('cancel-customer-customizer').addEventListener('click', () => customizeDialog.close());
customizeDialog.addEventListener('click', event => {
    if (event.target === customizeDialog) {
        customizeDialog.close();
    }
});
document.querySelectorAll('.customer-category-tab').forEach(button => button.addEventListener('click', () => {
    const category = button.dataset.customCategory;
    document.querySelectorAll('.customer-category-tab').forEach(tab => {
        const isActive = tab === button;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-pressed', String(isActive));
    });
    document.querySelectorAll('.customer-category-panel').forEach(panel => {
        panel.hidden = panel.dataset.customPanel !== category;
    });
}));
componentChoices.forEach(input => input.addEventListener('change', () => {
    const itemId = Number(input.dataset.itemId);
    if (input.checked) {
        if (input.dataset.category === 'Wrapper') {
            const wrapperCount = [...selectedComponents.values()]
                .filter(component => component.category === 'Wrapper').length;
            if (wrapperCount >= 2) {
                input.checked = false;
                window.alert('Choose up to two wrapper types for your bouquet.');
                return;
            }
        }
        if (input.dataset.category === 'Ribbon') {
            [...selectedComponents.values()]
                .filter(component => component.category === 'Ribbon')
                .forEach(component => selectedComponents.delete(component.item_id));
        }
        selectedComponents.set(itemId, {
            item_id: itemId,
            name: input.dataset.name,
            category: input.dataset.category,
            price: Number(input.dataset.price),
            stock: Number(input.dataset.stock),
            quantity: 1
        });
    } else {
        selectedComponents.delete(itemId);
    }
    renderSelectedComponents();
}));
document.getElementById('custom-selected-list').addEventListener('click', event => {
    const button = event.target.closest('button[data-component-action]');
    if (!button) {
        return;
    }
    const itemId = Number(button.dataset.itemId);
    const component = selectedComponents.get(itemId);
    if (!component) {
        return;
    }
    if (button.dataset.componentAction === 'remove') {
        selectedComponents.delete(itemId);
        const input = componentChoices.find(choice => Number(choice.dataset.itemId) === itemId);
        if (input) {
            input.checked = false;
        }
    } else if (button.dataset.componentAction === 'increase') {
        if (component.quantity >= component.stock) {
            window.alert('You cannot choose more than the available stock.');
            return;
        }
        component.quantity += 1;
    } else if (button.dataset.componentAction === 'decrease') {
        component.quantity = Math.max(1, component.quantity - 1);
    }
    renderSelectedComponents();
});
document.getElementById('add-custom').addEventListener('click', () => {
    if (editingCartIndex === null && !requireCustomerAccount()) return;
    const selected = [...selectedComponents.values()];
    const counts = selected.reduce((result, component) => {
        result[component.category] = (result[component.category] || 0) + 1;
        return result;
    }, {});
    if ((counts.Flower || 0) < 1 || (counts.Wrapper || 0) < 1 || (counts.Wrapper || 0) > 2 || counts.Ribbon !== 1) {
        window.alert('Choose at least one flower, one or two wrappers, and exactly one ribbon.');
        return;
    }
    if (selected.some(component => component.quantity > component.stock)) {
        window.alert('A selected component no longer has enough stock. Adjust its quantity before saving.');
        return;
    }
    const name = document.getElementById('bouquet-name').value.trim();
    if (!name) {
        window.alert('Give your bouquet a name.');
        document.getElementById('bouquet-name').focus();
        return;
    }
    const price = selected.reduce((sum, component) => sum + component.price * component.quantity, 0);
    const components = selected.map(component => ({
        item_id: component.item_id,
        quantity: component.quantity,
        name: component.name,
        category: component.category
    }));
    const bouquet = {
        product_id: 0,
        name: 'Custom: ' + name,
        price,
        quantity: 1,
        selected: true,
        custom: {type: 'bouquet', name, components},
        details: components.map(component => component.name + ' × ' + component.quantity).join(', ')
    };
    if (editingCartIndex === null) {
        cart.push(bouquet);
    } else {
        bouquet.selected = cart[editingCartIndex].selected !== false;
        bouquet.quantity = cart[editingCartIndex].quantity;
        cart[editingCartIndex] = bouquet;
    }
    customizeDialog.close();
    resetCustomizer();
    renderCart();
});
const deliveryChoice = document.getElementById('delivery');
if (deliveryChoice) {
    const deliveryFields = document.getElementById('delivery-fields');
    const setFulfillment = () => {
        const isDelivery = deliveryChoice.checked;
        deliveryFields.classList.toggle('d-none', !isDelivery);
        deliveryFields.querySelectorAll('input').forEach(input => { input.required = isDelivery && input.id !== 'delivery-postal'; });
    };
    document.querySelectorAll('input[name="fulfillment_type"]').forEach(input => input.addEventListener('change', setFulfillment));
    setFulfillment();
}
const checkoutForm = document.getElementById('checkout-form');
if (checkoutForm) {
    checkoutForm.addEventListener('submit', event => {
        if (!cart.some(item => item.selected !== false)) {
            event.preventDefault();
            window.alert('Select at least one item before checkout.');
            return;
        }
        document.getElementById('cart-json').value = JSON.stringify(cart.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            selected: item.selected !== false,
            name: item.name,
            ...(item.custom ? {custom: item.custom} : {})
        })));
    });
}
if (accountModal) {
    accountModal.addEventListener('show.bs.modal', event => {
        const trigger = event.relatedTarget;
        const tabName = trigger && trigger.dataset.accountTab === 'signup' ? 'signup-tab' : 'signin-tab';
        bootstrap.Tab.getOrCreateInstance(document.getElementById(tabName)).show();
    });
    <?php if ($customerError !== '' || $customerSuccess !== ''): ?>
    bootstrap.Modal.getOrCreateInstance(accountModal).show();
    <?php endif; ?>
}
renderCart();
renderSelectedComponents();
if (window.location.hash === '#my-orders') {
    showOrdersPage();
} else if (window.location.hash === '#notifications') {
    showNotificationsPage();
}
<?php if ($customerError !== '' && $customerId > 0): ?>
<?php if ($showCustomerOrdersPage): ?>
showOrdersPage();
<?php else: ?>
showCartPage();
<?php endif; ?>
<?php endif; ?>
</script>
</body>
</html>
