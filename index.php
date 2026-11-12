<?php
session_start();

$logoutSuccess = false;
$displayName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
$userRole = $_SESSION['role'] ?? '';
$isAdmin = $userRole === 'Admin';
$canManageCatalog = $isAdmin || $userRole === 'Staff';
$firstName = isset($_SESSION['first_name']) && is_string($_SESSION['first_name'])
    ? trim($_SESSION['first_name'])
    : '';
$lastName = isset($_SESSION['last_name']) && is_string($_SESSION['last_name'])
    ? trim($_SESSION['last_name'])
    : '';

if ($firstName === '' || $lastName === '') {
    $nameParts = preg_split('/\s+/', trim((string) ($_SESSION['full_name'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $firstName = $firstName !== '' ? $firstName : ($nameParts[0] ?? '');
    $lastName = $lastName !== '' ? $lastName : (count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '');
}

$firstInitial = preg_match('/^./u', $firstName, $firstInitialMatch) ? $firstInitialMatch[0] : '';
$lastInitial = preg_match('/^./u', $lastName, $lastInitialMatch) ? $lastInitialMatch[0] : '';
$profileInitials = $firstInitial . $lastInitial;

function autoConfirmDeliveredCustomerOrders(mysqli $conn): bool
{
    $result = mysqli_query(
        $conn,
        "UPDATE tblcustomerorders
         SET Status = 'Received', PaymentStatus = 'Paid'
         WHERE FulfillmentType = 'delivery'
           AND Status = 'Delivered'
           AND DeliveredAt <= CURRENT_TIMESTAMP - INTERVAL 24 HOUR"
    );
    if ($result === false) {
        error_log('Unable to automatically confirm delivered customer orders: ' . mysqli_error($conn));
        return false;
    }
    return true;
}

function recordCompletedCustomerOrderSale(mysqli $conn, int $orderId, int $userId, string &$error): bool
{
    $existingSale = mysqli_prepare($conn, 'SELECT SaleID FROM tblsales WHERE CustomerOrderID = ?');
    if ($existingSale === false) {
        error_log('Unable to prepare customer order sales lookup: ' . mysqli_error($conn));
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    mysqli_stmt_bind_param($existingSale, 'i', $orderId);
    if (!mysqli_stmt_execute($existingSale)) {
        error_log('Unable to check for an existing customer order sale: ' . mysqli_stmt_error($existingSale));
        mysqli_stmt_close($existingSale);
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    mysqli_stmt_store_result($existingSale);
    $saleAlreadyRecorded = mysqli_stmt_num_rows($existingSale) > 0;
    mysqli_stmt_close($existingSale);
    if ($saleAlreadyRecorded) {
        return true;
    }

    $orderLookup = mysqli_prepare(
        $conn,
        'SELECT FulfillmentType, FulfillmentDate, FulfillmentTime, RecipientName, RecipientPhone,
                DeliveryAddress, DeliveryBarangay, DeliveryCity, DeliveryProvince, DeliveryPostalCode,
                DeliveryInstructions, PaymentMethod, PaymentStatus, TotalAmount, Status
         FROM tblcustomerorders WHERE OrderID = ? FOR UPDATE'
    );
    if ($orderLookup === false) {
        error_log('Unable to prepare completed customer order lookup: ' . mysqli_error($conn));
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    mysqli_stmt_bind_param($orderLookup, 'i', $orderId);
    if (!mysqli_stmt_execute($orderLookup)) {
        error_log('Unable to load completed customer order: ' . mysqli_stmt_error($orderLookup));
        mysqli_stmt_close($orderLookup);
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    $orderResult = mysqli_stmt_get_result($orderLookup);
    $order = $orderResult === false ? null : mysqli_fetch_assoc($orderResult);
    if ($orderResult !== false) {
        mysqli_free_result($orderResult);
    }
    mysqli_stmt_close($orderLookup);
    if (!is_array($order) || !in_array((string) $order['Status'], ['Delivered', 'Completed'], true)) {
        $error = 'This online order is not in a completed status.';
        return false;
    }

    $isDelivery = $order['FulfillmentType'] === 'delivery';
    $orderType = $isDelivery ? 'delivery' : 'walk-in';
    $saleStatus = $isDelivery ? 'Delivered' : 'Completed';
    $saleDate = date('Y-m-d H:i:s');
    $subtotal = (float) $order['TotalAmount'];
    $discountAmount = 0.0;
    $totalAmount = (float) $order['TotalAmount'];
    $amountPaid = $order['PaymentStatus'] === 'Paid' ? $totalAmount : 0.0;
    $changeAmount = 0.0;
    $discountType = 'none';
    $deliveryValues = $isDelivery
        ? [
            $order['FulfillmentDate'], $order['FulfillmentTime'], $order['RecipientName'],
            $order['RecipientPhone'], $order['DeliveryAddress'], $order['DeliveryBarangay'],
            $order['DeliveryCity'], $order['DeliveryProvince'], $order['DeliveryPostalCode'],
            $order['DeliveryInstructions'],
        ]
        : array_fill(0, 10, null);
    $saleStatement = mysqli_prepare(
        $conn,
        'INSERT INTO tblsales (
            SaleDate, UserID, Subtotal, DiscountAmount, TotalAmount, PaymentMethod,
            AmountPaid, ChangeAmount, Status, OrderType, DiscountType, DeliveryDate,
            DeliveryTime, RecipientName, RecipientPhone, DeliveryAddress, DeliveryBarangay,
            DeliveryCity, DeliveryProvince, DeliveryPostalCode, DeliveryInstructions, CustomerOrderID
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($saleStatement === false) {
        error_log('Unable to prepare completed customer order sale: ' . mysqli_error($conn));
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    $saleParametersBound = mysqli_stmt_bind_param(
        $saleStatement,
        'sidddsdd' . 'sssssssssssss' . 'i',
        $saleDate,
        $userId,
        $subtotal,
        $discountAmount,
        $totalAmount,
        $order['PaymentMethod'],
        $amountPaid,
        $changeAmount,
        $saleStatus,
        $orderType,
        $discountType,
        $deliveryValues[0],
        $deliveryValues[1],
        $deliveryValues[2],
        $deliveryValues[3],
        $deliveryValues[4],
        $deliveryValues[5],
        $deliveryValues[6],
        $deliveryValues[7],
        $deliveryValues[8],
        $deliveryValues[9],
        $orderId
    );
    if (!$saleParametersBound || !mysqli_stmt_execute($saleStatement)) {
        error_log('Unable to record completed customer order sale: ' . mysqli_stmt_error($saleStatement));
        mysqli_stmt_close($saleStatement);
        $error = 'Unable to record this completed online order in sales.';
        return false;
    }
    $saleId = (int) mysqli_insert_id($conn);
    mysqli_stmt_close($saleStatement);

    $itemsStatement = mysqli_prepare(
        $conn,
        'SELECT ProductID, ProductName, CustomComponents, Quantity, UnitPrice, LineTotal
         FROM tblcustomerorderitems WHERE OrderID = ? ORDER BY OrderItemID'
    );
    if ($itemsStatement === false) {
        error_log('Unable to prepare completed customer order items: ' . mysqli_error($conn));
        $error = 'Unable to copy the completed online order items into sales.';
        return false;
    }
    mysqli_stmt_bind_param($itemsStatement, 'i', $orderId);
    if (!mysqli_stmt_execute($itemsStatement)) {
        error_log('Unable to load completed customer order items: ' . mysqli_stmt_error($itemsStatement));
        mysqli_stmt_close($itemsStatement);
        $error = 'Unable to copy the completed online order items into sales.';
        return false;
    }
    if (!mysqli_stmt_store_result($itemsStatement)) {
        error_log('Unable to buffer completed customer order items: ' . mysqli_stmt_error($itemsStatement));
        mysqli_stmt_close($itemsStatement);
        $error = 'Unable to copy the completed online order items into sales.';
        return false;
    }
    mysqli_stmt_bind_result(
        $itemsStatement,
        $productId,
        $productName,
        $customComponents,
        $quantity,
        $unitPrice,
        $lineTotal
    );
    $detailStatement = mysqli_prepare(
        $conn,
        'INSERT INTO tblsaledetails (SaleID, ProductID, ProductName, CustomComponents, Quantity, UnitPrice, LineTotal)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    if ($detailStatement === false) {
        error_log('Unable to prepare completed customer order sale items: ' . mysqli_error($conn));
        mysqli_stmt_close($itemsStatement);
        $error = 'Unable to copy the completed online order items into sales.';
        return false;
    }
    $itemCount = 0;
    while (mysqli_stmt_fetch($itemsStatement)) {
        if (!mysqli_stmt_bind_param(
            $detailStatement,
            'iissidd',
            $saleId,
            $productId,
            $productName,
            $customComponents,
            $quantity,
            $unitPrice,
            $lineTotal
        ) || !mysqli_stmt_execute($detailStatement)) {
            error_log('Unable to copy a completed customer order item to sales: ' . mysqli_stmt_error($detailStatement));
            mysqli_stmt_close($detailStatement);
            mysqli_stmt_close($itemsStatement);
            $error = 'Unable to copy the completed online order items into sales.';
            return false;
        }
        $itemCount++;
    }
    mysqli_stmt_close($detailStatement);
    mysqli_stmt_close($itemsStatement);
    if ($itemCount === 0) {
        error_log('Completed customer order has no items to record in sales.');
        $error = 'Unable to copy the completed online order items into sales.';
        return false;
    }

    return true;
}

if (!isset($_SESSION['user_id']) && !($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout')) {
    header('Location: LoginForm.php');
    exit;
}

if (isset($_GET['customer_order_notifications'])) {
    if ($userRole !== 'Admin') {
        http_response_code(403);
        exit;
    }
    require_once __DIR__ . '/Connection.php';
    $automaticReceiptUpdated = autoConfirmDeliveredCustomerOrders($conn);
    if (!$automaticReceiptUpdated) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to update delivered customer orders.']);
        exit;
    }
    $notificationResult = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS PendingCount
         FROM tblcustomerorders
         WHERE Status IN ('Pending review', 'Pending')"
    );
    if ($notificationResult === false) {
        error_log('Unable to poll pending customer orders: ' . mysqli_error($conn));
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to load order notifications.']);
        exit;
    }
    $notificationCount = (int) (mysqli_fetch_assoc($notificationResult)['PendingCount'] ?? 0);
    mysqli_free_result($notificationResult);
    $receivedResult = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS ReceivedCount FROM tblcustomerorders WHERE Status = 'Received'"
    );
    if ($receivedResult === false) {
        error_log('Unable to count customer-confirmed orders: ' . mysqli_error($conn));
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to load order notifications.']);
        exit;
    }
    $receivedCount = (int) (mysqli_fetch_assoc($receivedResult)['ReceivedCount'] ?? 0);
    mysqli_free_result($receivedResult);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'pending_customer_orders' => $notificationCount,
        'received_customer_orders' => $receivedCount,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid logout request.');
    }
    session_unset();
    session_destroy();
    $logoutSuccess = true;
}

if (!$logoutSuccess && !isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userNotice = isset($_SESSION['user_notice']) && is_string($_SESSION['user_notice'])
    ? $_SESSION['user_notice']
    : '';
unset($_SESSION['user_notice']);
$inventoryNotice = isset($_SESSION['inventory_notice']) && is_string($_SESSION['inventory_notice'])
    ? $_SESSION['inventory_notice']
    : '';
unset($_SESSION['inventory_notice']);
$productNotice = isset($_SESSION['product_notice']) && is_string($_SESSION['product_notice'])
    ? $_SESSION['product_notice']
    : '';
unset($_SESSION['product_notice']);
$saleNotice = isset($_SESSION['sale_notice']) && is_string($_SESSION['sale_notice'])
    ? $_SESSION['sale_notice']
    : '';
unset($_SESSION['sale_notice']);
$supplierNotice = isset($_SESSION['supplier_notice']) && is_string($_SESSION['supplier_notice'])
    ? $_SESSION['supplier_notice']
    : '';
unset($_SESSION['supplier_notice']);

$users = [];
$usersLoadError = '';
$userCreateError = '';
$userCreateNotice = '';
$userCreateSuccess = false;
$userUpdateError = '';
$userUpdateSuccess = false;
$userDeleteError = '';
$userDeleteSuccess = false;
$inventoryItems = [];
$inventoryColorsById = [];
$inventoryLoadError = '';
$itemCreateError = '';
$itemUpdateError = '';
$itemDeleteError = '';
$editInventoryItem = null;
$suppliers = [];
$suppliersByInventoryId = [];
$supplierStockTransactions = [];
$supplierLoadError = '';
$supplierTransactionsLoadError = '';
$supplierCreateError = '';
$supplierUpdateError = '';
$supplierDeleteError = '';
$products = [];
$productComponentsById = [];
$productColorsById = [];
$productsLoadError = '';
$deliveryOrders = [];
$deliveryOrdersLoadError = '';
$customerOrders = [];
$customerOrdersLoadError = '';
$automaticReceiptUpdated = true;
$pendingCustomerOrderCount = 0;
$receivedCustomerOrderCount = 0;
$customerOrderReviewNotice = isset($_SESSION['customer_order_review_notice']) && is_string($_SESSION['customer_order_review_notice'])
    ? $_SESSION['customer_order_review_notice']
    : '';
unset($_SESSION['customer_order_review_notice']);
$customerOrderReviewError = '';
$salesRecords = [];
$salesLoadError = '';
$dashboardMetrics = [
    'today_sales' => 0,
    'today_revenue' => 0.0,
    'pending_deliveries' => 0,
    'product_count' => 0,
    'inventory_count' => 0,
    'low_stock_count' => 0,
];
$dashboardRecentSales = [];
$dashboardActivity = [];
$dashboardLowStockItems = [];
$dashboardLoadError = '';
$staffDashboardMetrics = [
    'today_sales' => 0,
    'today_revenue' => 0.0,
    'pending_deliveries' => 0,
];
$staffDashboardRecentSales = [];
$staffDashboardLoadError = '';
$productCreateError = '';
$productDeleteError = '';
$saleCreateError = '';
$deliveryStatusNotice = isset($_SESSION['delivery_status_notice']) && is_string($_SESSION['delivery_status_notice'])
    ? $_SESSION['delivery_status_notice']
    : '';
unset($_SESSION['delivery_status_notice']);
$deliveryStatusError = '';
$productFormSelections = [
    'mode' => 'create',
    'product_id' => '',
    'product_name' => '',
    'product_description' => '',
    'product_price' => '',
    'product_discount_percent' => '0',
    'product_featured' => false,
    'product_categories' => [],
    'product_stock' => '0',
    'flower_ids' => [],
    'wrapper_ids' => [],
    'container_ids' => [],
    'ribbon_id' => '',
    'addon_ids' => [],
    'quantities' => [],
];
$productCategoryOptions = [
    "Valentine's Day",
    "Mother's Day",
    "Birthday",
    'Anniversary',
    'Monthsary',
    'Christmas',
    'Graduation',
    'Get Well Soon',
    'Just Because',
];
$inventoryColorOptions = ['Red', 'Blue', 'Yellow', 'Green', 'Orange', 'Violet', 'Black', 'White', 'N/A'];
$userCreateToken = '';
if (!$logoutSuccess) {
    $userCreateToken = isset($_SESSION['user_create_token']) && is_string($_SESSION['user_create_token'])
        ? $_SESSION['user_create_token']
        : '';
    if ($userCreateToken === '') {
        $userCreateToken = bin2hex(random_bytes(32));
        $_SESSION['user_create_token'] = $userCreateToken;
    }
}
$posOrderToken = '';
if (!$logoutSuccess) {
    $posOrderToken = isset($_SESSION['pos_order_token']) && is_string($_SESSION['pos_order_token'])
        ? $_SESSION['pos_order_token']
        : '';
    if ($posOrderToken === '') {
        $posOrderToken = bin2hex(random_bytes(32));
        $_SESSION['pos_order_token'] = $posOrderToken;
    }
}

if (!$logoutSuccess) {
    require_once __DIR__ . '/Connection.php';
    require_once __DIR__ . '/ActivityLog.php';
    require_once __DIR__ . '/AuthPin.php';
    $automaticReceiptUpdated = autoConfirmDeliveredCustomerOrders($conn);

    $postedAction = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && is_string($_POST['action'])
        ? $_POST['action']
        : '';

    if ($postedAction === 'review_customer_order') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Only an administrator can review customer orders.');
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid customer order review request.');
        }

        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = isset($_POST['decision']) && is_string($_POST['decision']) ? $_POST['decision'] : '';
        if ($orderId === false || $orderId < 1 || !in_array($decision, ['Accepted', 'Declined'], true)) {
            $customerOrderReviewError = 'Choose a valid customer order and review decision.';
        } elseif (!mysqli_begin_transaction($conn)) {
            error_log('Unable to start customer order review transaction: ' . mysqli_error($conn));
            $customerOrderReviewError = 'Unable to review this customer order right now. Please try again.';
        } else {
            $reviewStatus = '';
            $reviewLookup = mysqli_prepare($conn, 'SELECT Status FROM tblcustomerorders WHERE OrderID = ? FOR UPDATE');
            if ($reviewLookup === false) {
                error_log('Unable to prepare customer order review lookup: ' . mysqli_error($conn));
                $customerOrderReviewError = 'Unable to review this customer order right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($reviewLookup, 'i', $orderId)
                    || !mysqli_stmt_execute($reviewLookup)
                    || !mysqli_stmt_bind_result($reviewLookup, $reviewStatus)
                ) {
                    error_log('Unable to load customer order for review: ' . mysqli_stmt_error($reviewLookup));
                    $customerOrderReviewError = 'Unable to review this customer order right now. Please try again.';
                } elseif (!mysqli_stmt_fetch($reviewLookup)) {
                    $customerOrderReviewError = 'That customer order could not be found.';
                }
                mysqli_stmt_close($reviewLookup);
            }

            if ($customerOrderReviewError === '' && !in_array($reviewStatus, ['Pending review', 'Pending'], true)) {
                $customerOrderReviewError = 'This customer order has already been reviewed.';
            }

            if ($customerOrderReviewError === '' && $decision === 'Declined') {
                $reviewItems = mysqli_prepare(
                    $conn,
                    'SELECT ProductID, Quantity, ItemType, CustomComponents
                     FROM tblcustomerorderitems WHERE OrderID = ? FOR UPDATE'
                );
                if ($reviewItems === false) {
                    error_log('Unable to prepare customer order stock release: ' . mysqli_error($conn));
                    $customerOrderReviewError = 'Unable to decline this customer order right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param($reviewItems, 'i', $orderId)
                        || !mysqli_stmt_execute($reviewItems)
                        || !mysqli_stmt_store_result($reviewItems)
                        || !mysqli_stmt_bind_result($reviewItems, $reviewProductId, $reviewQuantity, $reviewItemType, $reviewComponentsJson)
                    ) {
                        error_log('Unable to read customer order items for decline: ' . mysqli_stmt_error($reviewItems));
                        $customerOrderReviewError = 'Unable to release this order reservation right now.';
                    } else {
                        while (true) {
                            $itemFetch = mysqli_stmt_fetch($reviewItems);
                            if ($itemFetch === null || ($itemFetch === false && mysqli_stmt_errno($reviewItems) === 0)) {
                                break;
                            }
                            if ($itemFetch === false) {
                                error_log('Unable to fetch customer order items for decline: ' . mysqli_stmt_error($reviewItems));
                                $customerOrderReviewError = 'Unable to release this order reservation right now.';
                                break;
                            }
                            $quantityToRestore = (int) $reviewQuantity;
                            if ($reviewItemType === 'catalog' && $reviewProductId !== null) {
                                $reservedProductStock = null;
                                $productStockLookup = mysqli_prepare($conn, 'SELECT ProductStock FROM tblproducts WHERE ProductID = ? FOR UPDATE');
                                if ($productStockLookup === false) {
                                    error_log('Unable to prepare product stock release lookup: ' . mysqli_error($conn));
                                    $customerOrderReviewError = 'Unable to release reserved product stock.';
                                    break;
                                }
                                if (
                                    !mysqli_stmt_bind_param($productStockLookup, 'i', $reviewProductId)
                                    || !mysqli_stmt_execute($productStockLookup)
                                    || !mysqli_stmt_bind_result($productStockLookup, $reservedProductStock)
                                ) {
                                    error_log('Unable to read reserved product stock: ' . mysqli_stmt_error($productStockLookup));
                                    $customerOrderReviewError = 'Unable to release reserved product stock.';
                                    mysqli_stmt_close($productStockLookup);
                                    break;
                                }
                                $productExists = mysqli_stmt_fetch($productStockLookup);
                                mysqli_stmt_close($productStockLookup);
                                if ($productExists !== true) {
                                    $customerOrderReviewError = 'A reserved product could not be found.';
                                    break;
                                }
                                if ($reservedProductStock !== null) {
                                    $restoreProduct = mysqli_prepare(
                                        $conn,
                                        'UPDATE tblproducts SET ProductStock = ProductStock + ? WHERE ProductID = ?'
                                    );
                                    if ($restoreProduct === false) {
                                        error_log('Unable to prepare reserved product stock release: ' . mysqli_error($conn));
                                        $customerOrderReviewError = 'Unable to release reserved product stock.';
                                        break;
                                    }
                                    mysqli_stmt_bind_param($restoreProduct, 'ii', $quantityToRestore, $reviewProductId);
                                    if (!mysqli_stmt_execute($restoreProduct) || mysqli_stmt_affected_rows($restoreProduct) !== 1) {
                                        error_log('Unable to release reserved product stock: ' . mysqli_stmt_error($restoreProduct));
                                        $customerOrderReviewError = 'Unable to release reserved product stock.';
                                    }
                                    mysqli_stmt_close($restoreProduct);
                                    if ($customerOrderReviewError !== '') {
                                        break;
                                    }
                                }
                            } elseif ($reviewItemType === 'custom') {
                                $savedComponents = json_decode((string) $reviewComponentsJson, true);
                                if (!is_array($savedComponents) || !isset($savedComponents['components']) || !is_array($savedComponents['components'])) {
                                    $customerOrderReviewError = 'The customized order reservation details are invalid; stock was not released.';
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
                                        $customerOrderReviewError = 'The customized order reservation details are invalid; stock was not released.';
                                        break 2;
                                    }
                                    $componentId = (int) $savedComponent['item_id'];
                                    $componentQuantity = (int) $savedComponent['quantity'] * $quantityToRestore;
                                    $restoreComponent = mysqli_prepare(
                                        $conn,
                                        'UPDATE tblinventory SET Stock = Stock + ? WHERE ItemID = ?'
                                    );
                                    if ($restoreComponent === false) {
                                        error_log('Unable to prepare reserved component stock release: ' . mysqli_error($conn));
                                        $customerOrderReviewError = 'Unable to release reserved bouquet stock.';
                                        break 2;
                                    }
                                    mysqli_stmt_bind_param($restoreComponent, 'ii', $componentQuantity, $componentId);
                                    if (!mysqli_stmt_execute($restoreComponent) || mysqli_stmt_affected_rows($restoreComponent) !== 1) {
                                        error_log('Unable to release reserved component stock: ' . mysqli_stmt_error($restoreComponent));
                                        $customerOrderReviewError = 'Unable to release reserved bouquet stock.';
                                    }
                                    mysqli_stmt_close($restoreComponent);
                                    if ($customerOrderReviewError !== '') {
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                    mysqli_stmt_close($reviewItems);
                }
            }

            if ($customerOrderReviewError === '') {
                $updateReview = mysqli_prepare(
                    $conn,
                    "UPDATE tblcustomerorders SET Status = ?
                     WHERE OrderID = ? AND Status IN ('Pending review', 'Pending')"
                );
                if ($updateReview === false) {
                    error_log('Unable to prepare customer order review update: ' . mysqli_error($conn));
                    $customerOrderReviewError = 'Unable to update the customer order status.';
                } else {
                    mysqli_stmt_bind_param($updateReview, 'si', $decision, $orderId);
                    if (!mysqli_stmt_execute($updateReview) || mysqli_stmt_affected_rows($updateReview) !== 1) {
                        error_log('Unable to update customer order review: ' . mysqli_stmt_error($updateReview));
                        $customerOrderReviewError = 'Unable to update the customer order status.';
                    }
                    mysqli_stmt_close($updateReview);
                }
            }

            if ($customerOrderReviewError === '') {
                if (mysqli_commit($conn)) {
                    $_SESSION['customer_order_review_notice'] = 'Customer order #' . $orderId . ' ' . strtolower($decision) . '.';
                    header('Location: index.php?tab=orders');
                    exit;
                }
                error_log('Unable to commit customer order review: ' . mysqli_error($conn));
                $customerOrderReviewError = 'Unable to finish reviewing this customer order.';
            }
            mysqli_rollback($conn);
        }
    }

    if ($postedAction === 'update_customer_delivery_status') {
        if (!$canManageCatalog) {
            http_response_code(403);
            exit('You do not have permission to update customer delivery orders.');
        }
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid customer delivery status request.');
        }
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $nextStatus = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';
        if ($orderId === false || $orderId < 1 || !in_array($nextStatus, ['Pending', 'Out for delivery', 'Delivered', 'Delivery failed', 'Completed'], true)) {
            $customerOrderReviewError = 'Choose a valid customer order status.';
        } elseif (!mysqli_begin_transaction($conn)) {
            error_log('Unable to start customer delivery status transaction: ' . mysqli_error($conn));
            $customerOrderReviewError = 'Unable to update this customer order right now. Please try again.';
        } else {
            $currentStatus = '';
            $fulfillmentType = '';
            $statusLookup = mysqli_prepare(
                $conn,
                'SELECT Status, FulfillmentType FROM tblcustomerorders WHERE OrderID = ? FOR UPDATE'
            );
            if ($statusLookup === false) {
                error_log('Unable to prepare customer delivery status lookup: ' . mysqli_error($conn));
                $customerOrderReviewError = 'Unable to update this customer order right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($statusLookup, 'i', $orderId)
                    || !mysqli_stmt_execute($statusLookup)
                    || !mysqli_stmt_bind_result($statusLookup, $currentStatus, $fulfillmentType)
                ) {
                    error_log('Unable to load customer delivery status: ' . mysqli_stmt_error($statusLookup));
                    $customerOrderReviewError = 'Unable to update this customer order right now. Please try again.';
                } elseif (!mysqli_stmt_fetch($statusLookup)) {
                    $customerOrderReviewError = 'That customer order could not be found.';
                }
                mysqli_stmt_close($statusLookup);
            }
            $validTransition = $fulfillmentType === 'delivery'
                ? (
                    in_array($currentStatus, ['Pending review', 'Pending', 'Accepted', 'Out for delivery', 'Delivered', 'Delivery failed'], true)
                    && in_array($nextStatus, ['Pending', 'Out for delivery', 'Delivered', 'Delivery failed'], true)
                )
                : ($fulfillmentType === 'pickup' && $currentStatus === 'Accepted' && $nextStatus === 'Completed');
            if ($customerOrderReviewError === '' && !$validTransition) {
                $customerOrderReviewError = 'This order cannot be changed to the selected status.';
            }
            if ($customerOrderReviewError === '') {
                $statusUpdate = mysqli_prepare(
                    $conn,
                    "UPDATE tblcustomerorders
                     SET Status = ?,
                         PaymentStatus = IF(? IN ('Delivered', 'Completed'), 'Paid', PaymentStatus),
                         DeliveredAt = CASE
                             WHEN ? = 'Delivered' THEN COALESCE(DeliveredAt, CURRENT_TIMESTAMP)
                             ELSE NULL
                         END
                     WHERE OrderID = ? AND Status = ?"
                );
                if ($statusUpdate === false) {
                    error_log('Unable to prepare customer delivery status update: ' . mysqli_error($conn));
                    $customerOrderReviewError = 'Unable to update this customer order right now. Please try again.';
                } else {
                    if (
                        !mysqli_stmt_bind_param(
                            $statusUpdate,
                            'sssis',
                            $nextStatus,
                            $nextStatus,
                            $nextStatus,
                            $orderId,
                            $currentStatus
                        )
                        || !mysqli_stmt_execute($statusUpdate)
                        || mysqli_stmt_affected_rows($statusUpdate) !== 1
                    ) {
                        error_log('Unable to update customer delivery status: ' . mysqli_stmt_error($statusUpdate));
                        $customerOrderReviewError = 'This order status could not be updated.';
                    }
                    mysqli_stmt_close($statusUpdate);
                }
            }
            if (
                $customerOrderReviewError === ''
                && in_array($nextStatus, ['Delivered', 'Completed'], true)
                && !recordCompletedCustomerOrderSale(
                    $conn,
                    (int) $orderId,
                    (int) $_SESSION['user_id'],
                    $customerOrderReviewError
                )
            ) {
                error_log('Unable to create the sales copy for completed customer order #' . (int) $orderId . '.');
            }
            if ($customerOrderReviewError === '') {
                if (mysqli_commit($conn)) {
                    $_SESSION['customer_order_review_notice'] = 'Customer order #' . $orderId . ' marked ' . strtolower($nextStatus) . '.';
                    header('Location: index.php?tab=orders');
                    exit;
                }
                error_log('Unable to commit customer delivery status update: ' . mysqli_error($conn));
                $customerOrderReviewError = 'Unable to finish updating this customer order.';
            }
            mysqli_rollback($conn);
        }
    }

    if ($postedAction === 'update_delivery_status') {
        if (!$canManageCatalog) {
            http_response_code(403);
            exit('You do not have permission to update delivery orders.');
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid delivery status update request.');
        }

        $saleId = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
        $deliveryStatus = isset($_POST['delivery_status']) && is_string($_POST['delivery_status'])
            ? $_POST['delivery_status']
            : '';
        $failureRemarks = isset($_POST['failure_remarks']) && is_string($_POST['failure_remarks'])
            ? trim($_POST['failure_remarks'])
            : '';
        $amountReceivedInput = isset($_POST['amount_received']) && is_string($_POST['amount_received'])
            ? trim($_POST['amount_received'])
            : '';
        $allowedDeliveryStatuses = ['Pending', 'Out for delivery', 'Delivered', 'Delivery failed'];

        if (
            $saleId === false
            || $saleId < 1
            || !in_array($deliveryStatus, $allowedDeliveryStatuses, true)
            || ($deliveryStatus === 'Delivery failed' && ($failureRemarks === '' || mb_strlen($failureRemarks) > 500))
        ) {
            $deliveryStatusError = 'Choose a valid delivery status and provide remarks when delivery failed.';
        } else {
            $failureRemarksValue = $deliveryStatus === 'Delivery failed' ? $failureRemarks : null;
            $orderPaymentMethod = '';
            $orderTotal = 0.0;
            $amountReceivedCents = 0;
            $orderTotalCents = 0;
            $orderPaymentStatement = mysqli_prepare(
                $conn,
                "SELECT PaymentMethod, TotalAmount
                 FROM tblsales
                 WHERE SaleID = ? AND OrderType = 'delivery'"
            );
            if ($orderPaymentStatement === false) {
                error_log('Unable to prepare delivery payment lookup: ' . mysqli_error($conn));
                $deliveryStatusError = 'Unable to update this delivery order right now. Please try again.';
            } else {
                if (
                    !mysqli_stmt_bind_param($orderPaymentStatement, 'i', $saleId)
                    || !mysqli_stmt_execute($orderPaymentStatement)
                    || !mysqli_stmt_bind_result($orderPaymentStatement, $orderPaymentMethod, $orderTotal)
                ) {
                    error_log('Unable to read delivery payment details: ' . mysqli_stmt_error($orderPaymentStatement));
                    $deliveryStatusError = 'Unable to verify this delivery order payment.';
                } elseif (!mysqli_stmt_fetch($orderPaymentStatement)) {
                    $deliveryStatusError = 'That delivery order could not be found or is already finalized.';
                }
                mysqli_stmt_close($orderPaymentStatement);
            }

            $amountReceived = 0.0;
            $changeToGive = 0.0;
            $collectCashOnDelivery = $deliveryStatusError === ''
                && $deliveryStatus === 'Delivered'
                && $orderPaymentMethod === 'cash';
            if ($collectCashOnDelivery) {
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amountReceivedInput)) {
                    $deliveryStatusError = 'Enter the cash amount collected for this delivery.';
                } else {
                    [$cashWhole, $cashFraction] = array_pad(explode('.', $amountReceivedInput, 2), 2, '');
                    $amountReceivedCents = ((int) $cashWhole * 100) + (int) str_pad($cashFraction, 2, '0');
                    $orderTotalCents = (int) round((float) $orderTotal * 100);
                    if ($amountReceivedCents < $orderTotalCents) {
                        $deliveryStatusError = 'The cash collected must cover the delivery total.';
                    } else {
                        $amountReceived = $amountReceivedCents / 100;
                        $changeToGive = ($amountReceivedCents - $orderTotalCents) / 100;
                    }
                }
            }

            $statusStatement = false;
            if ($deliveryStatusError === '') {
                $statusStatement = $collectCashOnDelivery
                    ? mysqli_prepare(
                        $conn,
                        "UPDATE tblsales
                         SET Status = ?, DeliveryFailureRemarks = ?, AmountPaid = ?, ChangeAmount = ?
                         WHERE SaleID = ? AND OrderType = 'delivery'"
                    )
                    : mysqli_prepare(
                        $conn,
                        "UPDATE tblsales
                         SET Status = ?, DeliveryFailureRemarks = ?
                         WHERE SaleID = ? AND OrderType = 'delivery'"
                    );
            }
            if ($statusStatement === false) {
                if ($deliveryStatusError === '') {
                    error_log('Unable to prepare delivery status update: ' . mysqli_error($conn));
                    $deliveryStatusError = 'Unable to update this delivery order right now. Please try again.';
                }
            } elseif ($statusStatement !== false) {
                if (
                    ($collectCashOnDelivery
                        ? !mysqli_stmt_bind_param($statusStatement, 'ssddi', $deliveryStatus, $failureRemarksValue, $amountReceived, $changeToGive, $saleId)
                        : !mysqli_stmt_bind_param($statusStatement, 'ssi', $deliveryStatus, $failureRemarksValue, $saleId))
                    || !mysqli_stmt_execute($statusStatement)
                ) {
                    error_log('Unable to update delivery status: ' . mysqli_stmt_error($statusStatement));
                    $deliveryStatusError = 'Unable to update this delivery order right now. Please try again.';
                } else {
                    $rowsAffected = mysqli_stmt_affected_rows($statusStatement);
                    if ($rowsAffected === 0) {
                        $existsStatement = mysqli_prepare(
                            $conn,
                            "SELECT Status FROM tblsales WHERE SaleID = ? AND OrderType = 'delivery'"
                        );
                        if ($existsStatement === false) {
                            error_log('Unable to verify delivery order update: ' . mysqli_error($conn));
                            $deliveryStatusError = 'Unable to verify the delivery order update.';
                        } else {
                            if (
                                !mysqli_stmt_bind_param($existsStatement, 'i', $saleId)
                                || !mysqli_stmt_execute($existsStatement)
                            ) {
                                error_log('Unable to verify delivery order: ' . mysqli_stmt_error($existsStatement));
                                $deliveryStatusError = 'Unable to verify the delivery order update.';
                            } else {
                                if (
                                    !mysqli_stmt_bind_result($existsStatement, $currentDeliveryStatus)
                                    || !mysqli_stmt_fetch($existsStatement)
                                ) {
                                    $deliveryStatusError = 'That delivery order could not be found.';
                                } else {
                                    $deliveryStatusError = 'The delivery order already has that status.';
                                }
                            }
                            mysqli_stmt_close($existsStatement);
                        }
                    }
                    if ($deliveryStatusError === '') {
                        mysqli_stmt_close($statusStatement);
                        $activityDescription = 'Delivery order #' . $saleId . ' status changed to ' . $deliveryStatus . '.';
                        if ($deliveryStatus === 'Delivery failed') {
                            $activityDescription .= ' Remarks: ' . $failureRemarks;
                        } elseif ($collectCashOnDelivery) {
                            $activityDescription .= ' Cash received: ₱' . number_format($amountReceived, 2)
                                . '; change: ₱' . number_format($changeToGive, 2) . '.';
                        }
                        recordActivity($conn, 'order_status', $activityDescription, 'delivery', $saleId);
                        $_SESSION['delivery_status_notice'] = 'Delivery order #' . $saleId . ' updated to ' . $deliveryStatus . '.';
                        header('Location: index.php?tab=orders');
                        exit;
                    }
                }
                mysqli_stmt_close($statusStatement);
            }
        }
    }

    $adminOnlyActions = [
        'create_user',
        'update_user',
        'delete_user',
        'create_supplier',
        'update_supplier',
        'delete_supplier',
    ];
    $catalogActions = [
        'create_inventory_item',
        'update_inventory_item',
        'delete_inventory_item',
        'create_product',
        'update_product',
        'delete_product',
    ];
    $adminOnlyCatalogActions = [
        'create_inventory_item',
        'update_inventory_item',
        'delete_inventory_item',
        'create_product',
        'update_product',
        'delete_product',
    ];

    if (
        (!$isAdmin && in_array($postedAction, $adminOnlyActions, true))
        || (!$canManageCatalog && in_array($postedAction, $catalogActions, true))
        || (!$isAdmin && in_array($postedAction, $adminOnlyCatalogActions, true))
    ) {
        http_response_code(403);
        exit('You do not have permission to perform this action.');
    }

    if (in_array($postedAction, array_merge($adminOnlyActions, $catalogActions), true)) {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid user management request.');
        }
    }

    if ($postedAction === 'complete_sale') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $submittedOrderToken = $_POST['order_token'] ?? '';
        $sessionOrderToken = $_SESSION['pos_order_token'] ?? '';
        if (
            !is_string($submittedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $submittedToken)
            || !is_string($submittedOrderToken)
            || !is_string($sessionOrderToken)
            || $sessionOrderToken === ''
            || !hash_equals($sessionOrderToken, $submittedOrderToken)
        ) {
            http_response_code(403);
            exit('Invalid or already submitted checkout request.');
        }
        unset($_SESSION['pos_order_token']);

        $cartInput = isset($_POST['cart']) && is_string($_POST['cart']) ? $_POST['cart'] : '';
        $cart = json_decode($cartInput, true);
        $orderType = 'walk-in';
        $paymentMethod = isset($_POST['payment_method']) && is_string($_POST['payment_method']) ? $_POST['payment_method'] : '';
        $discountType = isset($_POST['discount_type']) && is_string($_POST['discount_type']) ? $_POST['discount_type'] : '';
        $amountPaidInput = isset($_POST['amount_paid']) && is_string($_POST['amount_paid']) ? trim($_POST['amount_paid']) : '';
        $allowedPaymentMethods = ['cash', 'gcash', 'maribank', 'gotyme', 'landbank'];
        $allowedDiscountTypes = ['none', 'senior', 'pwd'];
        $saleCreateError = '';
        $validatedCart = [];
        $seenProductIds = [];

        if (!is_array($cart) || $cart === [] || count($cart) > 100) {
            $saleCreateError = 'Add at least one product before placing the order.';
        } elseif (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            $saleCreateError = 'Choose a valid payment method.';
        } elseif (!in_array($discountType, $allowedDiscountTypes, true)) {
            $saleCreateError = 'Choose a valid discount option.';
        } else {
            foreach ($cart as $cartItem) {
                if (
                    !is_array($cartItem)
                    || !isset($cartItem['product_id'], $cartItem['quantity'])
                    || !is_int($cartItem['product_id'])
                    || !is_int($cartItem['quantity'])
                    || $cartItem['quantity'] < 1
                    || $cartItem['quantity'] > 1000
                ) {
                    $saleCreateError = 'The cart contains an invalid product or quantity.';
                    break;
                }
                $customItem = $cartItem['product_id'] === 0;
                if ($customItem) {
                    if (
                        !isset($cartItem['custom'])
                        || !is_array($cartItem['custom'])
                    ) {
                        $saleCreateError = 'A customized order item is invalid.';
                        break;
                    }
                    if (isset($cartItem['custom']['type']) && !is_string($cartItem['custom']['type'])) {
                        $saleCreateError = 'A customized order item is invalid.';
                        break;
                    }
                    $customType = $cartItem['custom']['type'] ?? 'bouquet';
                    if (
                        !isset($cartItem['custom']['name'], $cartItem['custom']['price'], $cartItem['custom']['components'])
                        || !is_string($cartItem['custom']['name'])
                        || trim($cartItem['custom']['name']) === ''
                        || mb_strlen(trim($cartItem['custom']['name'])) > 50
                        || !is_string($cartItem['custom']['price'])
                        || !in_array($customType, ['bouquet', 'addon'], true)
                        || !is_array($cartItem['custom']['components'])
                        || $cartItem['custom']['components'] === []
                        || count($cartItem['custom']['components']) > 100
                    ) {
                        $saleCreateError = 'A customized order item is invalid.';
                        break;
                    }
                    $componentIds = [];
                    foreach ($cartItem['custom']['components'] as $component) {
                        if (
                            !is_array($component)
                            || !isset($component['item_id'], $component['quantity'])
                            || !is_int($component['item_id'])
                            || $component['item_id'] < 1
                            || !is_int($component['quantity'])
                            || $component['quantity'] < 1
                            || $component['quantity'] > 1000
                            || isset($componentIds[$component['item_id']])
                        ) {
                            $saleCreateError = 'A customized order contains an invalid component.';
                            break 2;
                        }
                        $componentIds[$component['item_id']] = $component['quantity'];
                    }
                } elseif (
                    $cartItem['product_id'] < 1
                    || isset($cartItem['custom'])
                    || isset($seenProductIds[$cartItem['product_id']])
                ) {
                    $saleCreateError = 'The cart contains a duplicate or invalid product.';
                    break;
                } else {
                    $seenProductIds[$cartItem['product_id']] = true;
                }
                $validatedCart[] = $cartItem;
            }
        }

        $saleProducts = [];
        $subtotalCents = 0;
        $discountCents = 0;
        $totalCents = 0;
        $amountPaidCents = 0;
        if ($saleCreateError === '') {
            $productName = '';
            $productPrice = 0.0;
            $productDiscountPercent = 0.0;
            $productStock = null;
            $componentName = '';
            $componentCategory = '';
            $componentPrice = 0.0;
            $componentStock = 0;
            $productLookup = mysqli_prepare($conn, 'SELECT ProductName, Price, ProductDiscountPercent FROM tblproducts WHERE ProductID = ?');
            $componentLookup = mysqli_prepare($conn, 'SELECT ItemName, Category, Price FROM tblinventory WHERE ItemID = ?');
            if ($productLookup === false || $componentLookup === false) {
                error_log('Unable to prepare POS product validation: ' . mysqli_error($conn));
                $saleCreateError = 'Unable to validate the order right now. Please try again.';
            } else {
                foreach ($validatedCart as $cartItem) {
                    $productId = $cartItem['product_id'];
                    $quantity = $cartItem['quantity'];
                    $customComponents = null;
                    if ($productId === 0) {
                        $custom = $cartItem['custom'];
                        $customType = isset($custom['type']) && is_string($custom['type']) ? $custom['type'] : 'bouquet';
                        $componentTypes = [];
                        $componentNames = [];
                        $componentCostCents = 0;
                        $normalizedComponents = [];
                        foreach ($custom['components'] as $component) {
                            $componentId = $component['item_id'];
                            if (
                                !mysqli_stmt_bind_param($componentLookup, 'i', $componentId)
                                || !mysqli_stmt_execute($componentLookup)
                                || !mysqli_stmt_bind_result($componentLookup, $componentName, $componentCategory, $componentPrice)
                            ) {
                                error_log('Unable to validate custom POS component: ' . mysqli_stmt_error($componentLookup));
                                $saleCreateError = 'Unable to validate the customized order right now. Please try again.';
                                break 2;
                            }
                            if (!mysqli_stmt_fetch($componentLookup)) {
                                $saleCreateError = 'A component in the customized order is no longer available.';
                                mysqli_stmt_free_result($componentLookup);
                                break 2;
                            }
                            mysqli_stmt_free_result($componentLookup);
                            $componentQuantity = $component['quantity'];
                            $componentTypes[$componentCategory] = ($componentTypes[$componentCategory] ?? 0) + 1;
                            $componentNames[] = (string) $componentName . ' x ' . $componentQuantity;
                            $componentCostCents += (int) round((float) $componentPrice * 100) * $componentQuantity;
                            $normalizedComponents[] = [
                                'item_id' => $componentId,
                                'name' => (string) $componentName,
                                'category' => (string) $componentCategory,
                                'quantity' => $componentQuantity,
                            ];
                        }
                        if ($customType === 'addon') {
                            if (
                                count($normalizedComponents) !== 1
                                || ($componentTypes['Addons'] ?? 0) !== 1
                                || $normalizedComponents[0]['quantity'] !== 1
                            ) {
                                $saleCreateError = 'Choose one valid add-on for each add-on order item.';
                                break;
                            }
                            $unitPriceCents = (int) round((float) $componentPrice * 100);
                            $productName = (string) $componentName;
                        } else {
                            if (
                                ($componentTypes['Flower'] ?? 0) < 1
                                || (($componentTypes['Wrapper'] ?? 0) < 1 && ($componentTypes['Container'] ?? 0) !== 1)
                                || (($componentTypes['Wrapper'] ?? 0) > 0 && ($componentTypes['Container'] ?? 0) > 0)
                                || ($componentTypes['Wrapper'] ?? 0) > 2
                                || ($componentTypes['Container'] ?? 0) > 1
                                || ($componentTypes['Ribbon'] ?? 0) !== 1
                                || array_diff(array_keys($componentTypes), ['Flower', 'Wrapper', 'Container', 'Ribbon', 'Addons']) !== []
                            ) {
                                $saleCreateError = 'Choose at least one flower, either one or two wrapper types or one container, and one ribbon for each customized item.';
                                break;
                            }
                            if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $custom['price'])) {
                                $saleCreateError = 'A customized product has an invalid selling price.';
                                break;
                            }
                            $customPriceCents = (int) round((float) $custom['price'] * 100);
                            if ($customPriceCents < 1 || $customPriceCents < $componentCostCents) {
                                $saleCreateError = 'A customized item selling price cannot be less than its inventory component cost.';
                                break;
                            }
                            $unitPriceCents = $customPriceCents;
                            $productName = 'Custom: ' . trim($custom['name']);
                            if (mb_strlen($productName) > 100) {
                                $productName = mb_substr($productName, 0, 100);
                            }
                        }
                        $lineTotalCents = $unitPriceCents * $quantity;
                        $customComponents = json_encode(
                            ['components' => $normalizedComponents, 'component_names' => $componentNames],
                            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                        );
                        if (!is_string($customComponents)) {
                            $saleCreateError = 'Unable to encode the customized order components.';
                            break;
                        }
                    } else {
                    if (!mysqli_stmt_bind_param($productLookup, 'i', $productId)) {
                        error_log('Unable to bind POS product validation: ' . mysqli_stmt_error($productLookup));
                        $saleCreateError = 'Unable to validate the order right now. Please try again.';
                        break;
                    }
                    if (!mysqli_stmt_execute($productLookup)) {
                        error_log('Unable to validate POS product: ' . mysqli_stmt_error($productLookup));
                        $saleCreateError = 'Unable to validate the order right now. Please try again.';
                        break;
                    }
                    if (!mysqli_stmt_bind_result($productLookup, $productName, $productPrice, $productDiscountPercent)) {
                        error_log('Unable to read validated POS product: ' . mysqli_stmt_error($productLookup));
                        $saleCreateError = 'Unable to validate the order right now. Please try again.';
                        break;
                    }
                    if (!mysqli_stmt_fetch($productLookup)) {
                        $saleCreateError = 'A product in the order is no longer available. Please refresh and try again.';
                        mysqli_stmt_free_result($productLookup);
                        break;
                    }
                    mysqli_stmt_free_result($productLookup);
                    $unitPriceCents = (int) round((float) $productPrice * 100 * (1 - (float) $productDiscountPercent / 100));
                    $lineTotalCents = $unitPriceCents * $quantity;
                    }
                    if ($subtotalCents > 9999999999 - $lineTotalCents) {
                        $saleCreateError = 'The order total exceeds the maximum supported amount.';
                        break;
                    }
                    $subtotalCents += $lineTotalCents;
                    $saleProducts[] = [
                        'product_id' => $productId,
                        'name' => (string) $productName,
                        'quantity' => $quantity,
                        'unit_price' => $unitPriceCents / 100,
                        'line_total' => $lineTotalCents / 100,
                        'custom_components' => $customComponents,
                    ];
                }
                mysqli_stmt_close($productLookup);
                mysqli_stmt_close($componentLookup);
            }
        }

        if ($saleCreateError === '') {
            $discountCents = 0;
            $totalCents = 0;
            $amountPaidCents = 0;
            $discountCents = $discountType === 'none' ? 0 : intdiv($subtotalCents * 20 + 50, 100);
            $totalCents = $subtotalCents - $discountCents;
            $amountPaidCents = $totalCents;
            if ($paymentMethod === 'cash') {
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amountPaidInput)) {
                    $saleCreateError = 'Enter a valid cash amount paid.';
                } else {
                    [$cashWhole, $cashFraction] = array_pad(explode('.', $amountPaidInput, 2), 2, '');
                    $amountPaidCents = ((int) $cashWhole * 100) + (int) str_pad($cashFraction, 2, '0');
                    if ($amountPaidCents < $totalCents) {
                        $saleCreateError = 'The cash amount paid must cover the order total.';
                    }
                }
            }
        }

        if ($saleCreateError === '') {
            $subtotal = $subtotalCents / 100;
            $discountAmount = $discountCents / 100;
            $totalAmount = $totalCents / 100;
            $saleDate = date('Y-m-d H:i:s');
            $userId = (int) $_SESSION['user_id'];
            $amountPaid = $amountPaidCents / 100;
            $changeAmount = max(0, $amountPaidCents - $totalCents) / 100;
            $saleStatus = 'Completed';
            $deliveryValues = array_fill(0, 10, null);

            if (!mysqli_begin_transaction($conn)) {
                error_log('Unable to begin POS transaction: ' . mysqli_error($conn));
                $saleCreateError = 'Unable to save this order right now. Please try again.';
            } else {
                if (
                    deductProductStockForSale($conn, $saleProducts, $saleCreateError)
                    && deductInventoryForSale($conn, $saleProducts, $saleCreateError)
                ) {
                $saleStatement = mysqli_prepare(
                    $conn,
                    'INSERT INTO tblsales (
                        SaleDate, UserID, Subtotal, DiscountAmount, TotalAmount,
                        PaymentMethod, AmountPaid, ChangeAmount, Status, OrderType,
                        DiscountType, DeliveryDate, DeliveryTime, RecipientName,
                        RecipientPhone, DeliveryAddress, DeliveryBarangay, DeliveryCity,
                        DeliveryProvince, DeliveryPostalCode, DeliveryInstructions
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($saleStatement === false) {
                    error_log('Unable to prepare POS sale insert: ' . mysqli_error($conn));
                    $saleCreateError = 'Unable to save this order right now. Please run the POS checkout database migration and try again.';
                } else {
                    $saleTypes = 'sidddsdds' . str_repeat('s', 12);
                    $saleParametersBound = mysqli_stmt_bind_param(
                        $saleStatement,
                        $saleTypes,
                        $saleDate,
                        $userId,
                        $subtotal,
                        $discountAmount,
                        $totalAmount,
                        $paymentMethod,
                        $amountPaid,
                        $changeAmount,
                        $saleStatus,
                        $orderType,
                        $discountType,
                        $deliveryValues[0],
                        $deliveryValues[1],
                        $deliveryValues[2],
                        $deliveryValues[3],
                        $deliveryValues[4],
                        $deliveryValues[5],
                        $deliveryValues[6],
                        $deliveryValues[7],
                        $deliveryValues[8],
                        $deliveryValues[9]
                    );
                    if (!$saleParametersBound) {
                        error_log('Unable to bind POS sale: ' . mysqli_stmt_error($saleStatement));
                        $saleCreateError = 'Unable to save this order right now. Please try again.';
                    } elseif (!mysqli_stmt_execute($saleStatement)) {
                        error_log('Unable to save POS sale: ' . mysqli_stmt_error($saleStatement));
                        $saleCreateError = 'Unable to save this order right now. Please try again.';
                    } else {
                        $saleId = (int) mysqli_insert_id($conn);
                        $detailStatement = mysqli_prepare(
                            $conn,
                            'INSERT INTO tblsaledetails (SaleID, ProductID, ProductName, CustomComponents, Quantity, UnitPrice, LineTotal)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        if ($detailStatement === false) {
                            error_log('Unable to prepare POS sale detail insert: ' . mysqli_error($conn));
                            $saleCreateError = 'Unable to save the order items right now. Please try again.';
                        } else {
                            foreach ($saleProducts as $saleProduct) {
                                $detailParametersBound = mysqli_stmt_bind_param(
                                    $detailStatement,
                                    'iissidd',
                                    $saleId,
                                    $saleProduct['product_id'],
                                    $saleProduct['name'],
                                    $saleProduct['custom_components'],
                                    $saleProduct['quantity'],
                                    $saleProduct['unit_price'],
                                    $saleProduct['line_total']
                                );
                                if (!$detailParametersBound) {
                                    error_log('Unable to bind POS sale detail: ' . mysqli_stmt_error($detailStatement));
                                    $saleCreateError = 'Unable to save the order items right now. Please try again.';
                                    break;
                                }
                                if (!mysqli_stmt_execute($detailStatement)) {
                                    error_log('Unable to save POS sale detail: ' . mysqli_stmt_error($detailStatement));
                                    $saleCreateError = 'Unable to save the order items right now. Please try again.';
                                    break;
                                }
                            }
                            mysqli_stmt_close($detailStatement);
                        }
                    }
                    if (isset($saleStatement) && $saleStatement instanceof mysqli_stmt) {
                        mysqli_stmt_close($saleStatement);
                    }
                }
                }
                if ($saleCreateError === '') {
                    if (mysqli_commit($conn)) {
                        recordActivity(
                            $conn,
                            'sale',
                            'Completed ' . $orderType . ' sale #' . $saleId . ' for ₱' . number_format($totalAmount, 2) . ' (' . $paymentMethod . ').',
                            'sale',
                            $saleId
                        );
                        $_SESSION['sale_notice'] = 'Order #' . $saleId . ' completed successfully.';
                        $_SESSION['pos_order_token'] = bin2hex(random_bytes(32));
                        header('Location: index.php?tab=' . ($isAdmin ? 'sales' : 'pos'));
                        exit;
                    }
                    error_log('Unable to commit POS transaction: ' . mysqli_error($conn));
                    $saleCreateError = 'Unable to complete this order right now. Please try again.';
                }
                mysqli_rollback($conn);
            }
        }
        $_SESSION['pos_order_token'] = bin2hex(random_bytes(32));
    } elseif ($postedAction === 'create_product' || $postedAction === 'update_product') {
        $isProductUpdate = $postedAction === 'update_product';
        $productIdInput = isset($_POST['product_id']) && is_string($_POST['product_id']) ? $_POST['product_id'] : '';
        $productId = ctype_digit($productIdInput) ? (int) $productIdInput : 0;
        $productName = isset($_POST['product_name']) && is_string($_POST['product_name']) ? trim($_POST['product_name']) : '';
        $productDescription = isset($_POST['product_description']) && is_string($_POST['product_description'])
            ? trim($_POST['product_description'])
            : '';
        $priceInput = isset($_POST['product_price']) && is_string($_POST['product_price']) ? trim($_POST['product_price']) : '';
        $discountInput = isset($_POST['product_discount_percent']) && is_string($_POST['product_discount_percent'])
            ? trim($_POST['product_discount_percent'])
            : '0';
        $productFeatured = $isAdmin && isset($_POST['product_featured']) && $_POST['product_featured'] === '1';
        $submittedProductCategories = $_POST['product_categories'] ?? [];
        $productCategories = [];
        $invalidProductCategories = !is_array($submittedProductCategories);
        if (is_array($submittedProductCategories)) {
            foreach ($submittedProductCategories as $submittedCategory) {
                if (!is_string($submittedCategory) || !in_array($submittedCategory, $productCategoryOptions, true)) {
                    $invalidProductCategories = true;
                    break;
                }
                $productCategories[] = $submittedCategory;
            }
            $productCategories = array_values(array_unique($productCategories));
        }
        $productStockInput = isset($_POST['product_stock']) && is_string($_POST['product_stock'])
            ? trim($_POST['product_stock'])
            : '';
        $productStock = filter_var($productStockInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2147483647],
        ]);
        $uploadedProductImage = $_FILES['product_image'] ?? null;
        $productImageExtension = null;
        $productImageTemporaryPath = '';
        $productImageError = '';
        if (
            !is_array($uploadedProductImage)
            || !isset($uploadedProductImage['error'])
            || !is_int($uploadedProductImage['error'])
        ) {
            $productImageError = 'The selected product image could not be read. Please choose it again.';
        } elseif ($uploadedProductImage['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedProductImage['error'] !== UPLOAD_ERR_OK) {
                $productImageError = 'The image upload failed. Please choose an image smaller than 5 MB and try again.';
            } elseif (
                !isset($uploadedProductImage['tmp_name'], $uploadedProductImage['size'])
                || !is_string($uploadedProductImage['tmp_name'])
                || !is_int($uploadedProductImage['size'])
                || $uploadedProductImage['size'] < 1
                || $uploadedProductImage['size'] > 5 * 1024 * 1024
                || !is_uploaded_file($uploadedProductImage['tmp_name'])
            ) {
                $productImageError = 'Choose a valid product image smaller than 5 MB.';
            } else {
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($fileInfo === false) {
                    error_log('Unable to initialize product image upload validation.');
                    $productImageError = 'Unable to validate the product image right now. Please try again later.';
                } else {
                    $imageMimeType = finfo_file($fileInfo, $uploadedProductImage['tmp_name']);
                    finfo_close($fileInfo);
                    $allowedImageTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];
                    if (!is_string($imageMimeType) || !isset($allowedImageTypes[$imageMimeType])) {
                        $productImageError = 'The product image must be a JPEG, PNG, GIF, or WebP file.';
                    } else {
                        $imageDetails = @getimagesize($uploadedProductImage['tmp_name']);
                        if (
                            $imageDetails === false
                            || !isset($imageDetails['mime'])
                            || $imageDetails['mime'] !== $imageMimeType
                        ) {
                            $productImageError = 'The selected product file is not a valid image.';
                        } else {
                            $productImageExtension = $allowedImageTypes[$imageMimeType];
                            $productImageTemporaryPath = $uploadedProductImage['tmp_name'];
                        }
                    }
                }
            }
        }
        $readProductSelection = static function (string $field): ?array {
            $submittedIds = $_POST[$field] ?? [];
            if (!is_array($submittedIds)) {
                return null;
            }

            $ids = [];
            foreach ($submittedIds as $submittedId) {
                if (!is_string($submittedId) || !ctype_digit($submittedId) || (int) $submittedId < 1) {
                    return null;
                }
                $ids[] = (int) $submittedId;
            }

            return array_values(array_unique($ids));
        };
        $flowerIds = $readProductSelection('flower_ids');
        $wrapperIds = $readProductSelection('wrapper_ids');
        $containerIds = $readProductSelection('container_ids');
        $addonIds = $readProductSelection('addon_ids');
        $ribbonIdInput = isset($_POST['ribbon_id']) && is_string($_POST['ribbon_id']) ? $_POST['ribbon_id'] : '';
        $ribbonId = ctype_digit($ribbonIdInput) ? (int) $ribbonIdInput : 0;
        $readProductQuantities = static function (string $field): ?array {
            $submittedQuantities = $_POST[$field] ?? [];
            if (!is_array($submittedQuantities)) {
                return null;
            }

            $quantities = [];
            foreach ($submittedQuantities as $submittedId => $submittedQuantity) {
                $itemId = (is_int($submittedId) || is_string($submittedId)) ? (string) $submittedId : '';
                if (
                    !ctype_digit($itemId)
                    || (int) $itemId < 1
                    || !is_string($submittedQuantity)
                ) {
                    return null;
                }

                $quantity = filter_var($submittedQuantity, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 1 || $quantity > 2147483647) {
                    return null;
                }
                $quantities[(int) $itemId] = $quantity;
            }

            return $quantities;
        };
        $flowerQuantities = $readProductQuantities('flower_quantities');
        $wrapperQuantities = $readProductQuantities('wrapper_quantities');
        $containerQuantities = $readProductQuantities('container_quantities');
        $ribbonQuantities = $readProductQuantities('ribbon_quantities');
        $addonQuantities = $readProductQuantities('addon_quantities');
        $productFormSelections = [
            'mode' => $isProductUpdate ? 'edit' : 'create',
            'product_id' => $productIdInput,
            'product_name' => $productName,
            'product_description' => $productDescription,
            'product_price' => $priceInput,
            'product_discount_percent' => $discountInput,
            'product_featured' => $productFeatured,
            'product_categories' => $productCategories,
            'product_stock' => $productStockInput,
            'flower_ids' => $flowerIds ?? [],
            'wrapper_ids' => $wrapperIds ?? [],
            'container_ids' => $containerIds ?? [],
            'ribbon_id' => $ribbonIdInput,
            'addon_ids' => $addonIds ?? [],
            'quantities' => array_merge(
                $flowerQuantities ?? [],
                $wrapperQuantities ?? [],
                $containerQuantities ?? [],
                $ribbonQuantities ?? [],
                $addonQuantities ?? []
            ),
        ];
        $hasExactQuantities = static function (?array $itemIds, ?array $quantities): bool {
            if ($itemIds === null || $quantities === null) {
                return false;
            }
            $expectedIds = $itemIds;
            $submittedIds = array_keys($quantities);
            sort($expectedIds);
            sort($submittedIds);
            return $expectedIds === $submittedIds;
        };
        $validPrice = preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $priceInput) === 1;
        $validDiscount = preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,2})?\z/', $discountInput) === 1
            && (float) $discountInput >= 0
            && (float) $discountInput <= 100;

        if ($productImageError !== '') {
            $productCreateError = $productImageError;
        } elseif ($isProductUpdate && $productId < 1) {
            $productCreateError = 'Invalid product selected for editing.';
        } elseif (
            $productName === ''
            || strlen($productName) > 50
            || strlen($productDescription) > 2000
            || !$validPrice
            || (float) $priceInput <= 0
            || !$validDiscount
            || $invalidProductCategories
            || $productStock === false
            || $flowerIds === null
            || $wrapperIds === null
            || $containerIds === null
            || $addonIds === null
            || count($flowerIds) < 1
            || (count($wrapperIds) === 0 && count($containerIds) === 0)
            || (count($wrapperIds) > 0 && count($containerIds) > 0)
            || count($wrapperIds) > 2
            || count($containerIds) > 1
            || $ribbonId < 1
            || !$hasExactQuantities($flowerIds, $flowerQuantities)
            || !$hasExactQuantities($wrapperIds, $wrapperQuantities)
            || !$hasExactQuantities($containerIds, $containerQuantities)
            || !$hasExactQuantities([$ribbonId], $ribbonQuantities)
            || !$hasExactQuantities($addonIds, $addonQuantities)
        ) {
            $productCreateError = 'Enter a product name, valid price and available stock, select at least one flower, either one or two wrapper types or one container, one ribbon, and valid quantities.';
        } else {
            if ($isProductUpdate) {
                $productExistsStatement = mysqli_prepare($conn, 'SELECT ProductID FROM tblproducts WHERE ProductID = ?');
                if ($productExistsStatement === false) {
                    error_log('Unable to prepare product lookup before editing: ' . mysqli_error($conn));
                    $productCreateError = 'Unable to edit this product right now. Please try again later.';
                } else {
                    mysqli_stmt_bind_param($productExistsStatement, 'i', $productId);
                    if (!mysqli_stmt_execute($productExistsStatement)) {
                        error_log('Unable to check product before editing: ' . mysqli_stmt_error($productExistsStatement));
                        $productCreateError = 'Unable to edit this product right now. Please try again later.';
                    } else {
                        mysqli_stmt_store_result($productExistsStatement);
                        if (mysqli_stmt_num_rows($productExistsStatement) !== 1) {
                            $productCreateError = 'This product no longer exists.';
                        }
                    }
                    mysqli_stmt_close($productExistsStatement);
                }
            }
            $inventoryForProducts = [];
            $inventoryForProductsResult = $productCreateError === ''
                ? mysqli_query($conn, 'SELECT ItemID, ItemName, Category FROM tblinventory')
                : false;

            if ($productCreateError === '' && $inventoryForProductsResult === false) {
                error_log('Unable to load inventory for product creation: ' . mysqli_error($conn));
                $productCreateError = 'Unable to load inventory choices right now. Please try again later.';
            } elseif ($inventoryForProductsResult !== false) {
                while ($inventoryItem = mysqli_fetch_assoc($inventoryForProductsResult)) {
                    $inventoryForProducts[(int) $inventoryItem['ItemID']] = $inventoryItem;
                }
                mysqli_free_result($inventoryForProductsResult);
            }

            $selectedComponents = [];
            if ($productCreateError === '') {
                foreach ($flowerIds as $itemId) {
                    if (!isset($inventoryForProducts[$itemId]) || $inventoryForProducts[$itemId]['Category'] !== 'Flower') {
                        $productCreateError = 'One or more selected flowers are no longer available. Please refresh and try again.';
                        break;
                    }
                    $selectedComponents[] = ['item_id' => $itemId, 'quantity' => $flowerQuantities[$itemId]];
                }
                foreach ($wrapperIds as $itemId) {
                    if (!isset($inventoryForProducts[$itemId]) || $inventoryForProducts[$itemId]['Category'] !== 'Wrapper') {
                        $productCreateError = 'One or more selected wrappers are no longer available. Please refresh and try again.';
                        break;
                    }
                    $selectedComponents[] = ['item_id' => $itemId, 'quantity' => $wrapperQuantities[$itemId]];
                }
                foreach ($containerIds as $itemId) {
                    if (
                        !isset($inventoryForProducts[$itemId])
                        || strcasecmp(trim((string) $inventoryForProducts[$itemId]['Category']), 'Container') !== 0
                    ) {
                        $productCreateError = 'The selected container is no longer available. Please refresh and try again.';
                        break;
                    }
                    $selectedComponents[] = ['item_id' => $itemId, 'quantity' => $containerQuantities[$itemId]];
                }
                if ($productCreateError === '') {
                    if (!isset($inventoryForProducts[$ribbonId]) || $inventoryForProducts[$ribbonId]['Category'] !== 'Ribbon') {
                        $productCreateError = 'The selected ribbon is no longer available. Please refresh and try again.';
                    } else {
                        $selectedComponents[] = ['item_id' => $ribbonId, 'quantity' => $ribbonQuantities[$ribbonId]];
                    }
                }
                foreach ($addonIds as $itemId) {
                    if (!isset($inventoryForProducts[$itemId]) || $inventoryForProducts[$itemId]['Category'] !== 'Addons') {
                        $productCreateError = 'One or more selected add-ons are no longer available. Please refresh and try again.';
                        break;
                    }
                    $selectedComponents[] = ['item_id' => $itemId, 'quantity' => $addonQuantities[$itemId]];
                }
            }

            if ($productCreateError === '') {
                $flowerNames = implode(', ', array_map(
                    static fn (int $itemId): string => (string) $inventoryForProducts[$itemId]['ItemName'],
                    $flowerIds
                ));
                $wrapperNames = implode(', ', array_map(
                    static fn (int $itemId): string => (string) $inventoryForProducts[$itemId]['ItemName'],
                    $wrapperIds
                ));
                $ribbonName = (string) $inventoryForProducts[$ribbonId]['ItemName'];
                $addonNames = implode(', ', array_map(
                    static fn (int $itemId): string => (string) $inventoryForProducts[$itemId]['ItemName'],
                    $addonIds
                ));

                if (strlen($flowerNames) > 50 || strlen($wrapperNames) > 50 || strlen($ribbonName) > 50 || strlen($addonNames) > 100) {
                    $productCreateError = 'The selected inventory names exceed the product table column limits. Shorten the inventory item names or select fewer add-ons.';
                } else {
                    $savedProductImagePath = '';
                    $newProductImageSaved = false;
                    $productImageBackupPath = '';
                    $productImageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
                    if (!mysqli_begin_transaction($conn)) {
                        error_log('Unable to begin product save transaction: ' . mysqli_error($conn));
                        $productCreateError = 'Unable to save this product right now. Please try again later.';
                    } else {
                        $additionalProductStock = $productStock;
                        if ($isProductUpdate) {
                            $existingStockStatement = mysqli_prepare(
                                $conn,
                                'SELECT ProductStock FROM tblproducts WHERE ProductID = ? FOR UPDATE'
                            );
                            if ($existingStockStatement === false) {
                                error_log('Unable to prepare product stock lookup before editing: ' . mysqli_error($conn));
                                $productCreateError = 'Unable to verify this product’s current stock. Please try again.';
                            } else {
                                mysqli_stmt_bind_param($existingStockStatement, 'i', $productId);
                                if (!mysqli_stmt_execute($existingStockStatement)) {
                                    error_log('Unable to read current product stock: ' . mysqli_stmt_error($existingStockStatement));
                                    $productCreateError = 'Unable to verify this product’s current stock. Please try again.';
                                } else {
                                    mysqli_stmt_bind_result($existingStockStatement, $existingProductStock);
                                    if (!mysqli_stmt_fetch($existingStockStatement)) {
                                        $productCreateError = 'This product no longer exists.';
                                    } else {
                                        $existingStockCount = $existingProductStock === null ? 0 : (int) $existingProductStock;
                                        $additionalProductStock = max(0, $productStock - $existingStockCount);
                                    }
                                }
                                mysqli_stmt_close($existingStockStatement);
                            }
                        }

                        $productQuery = $isProductUpdate
                            ? 'UPDATE tblproducts SET ProductName = ?, Flower = ?, Wrapper = ?, Ribbon = ?, Addons = ?, Price = ?, ProductStock = ?, ProductDiscountPercent = ?, ProductCategories = ?, Featured = ?, Description = ? WHERE ProductID = ?'
                            : 'INSERT INTO tblproducts (ProductName, Flower, Wrapper, Ribbon, Addons, Price, ProductStock, ProductDiscountPercent, ProductCategories, Featured, Description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                        $productStatement = $productCreateError === '' ? mysqli_prepare($conn, $productQuery) : false;

                        if ($productStatement === false) {
                            if ($productCreateError === '') {
                                error_log('Unable to prepare product save query: ' . mysqli_error($conn));
                                $productCreateError = 'Unable to save this product right now. Please try again later.';
                            }
                        } else {
                            $categoriesJson = json_encode($productCategories, JSON_UNESCAPED_UNICODE);
                            if (!is_string($categoriesJson)) {
                                error_log('Unable to encode product categories.');
                                $productCreateError = 'Unable to save product categories right now. Please try again.';
                            }
                            if ($isProductUpdate) {
                                mysqli_stmt_bind_param($productStatement, 'ssssssissisi', $productName, $flowerNames, $wrapperNames, $ribbonName, $addonNames, $priceInput, $productStock, $discountInput, $categoriesJson, $productFeatured, $productDescription, $productId);
                            } else {
                                mysqli_stmt_bind_param($productStatement, 'ssssssissis', $productName, $flowerNames, $wrapperNames, $ribbonName, $addonNames, $priceInput, $productStock, $discountInput, $categoriesJson, $productFeatured, $productDescription);
                            }

                            if ($productCreateError === '' && !mysqli_stmt_execute($productStatement)) {
                                error_log('Unable to save product: ' . mysqli_stmt_error($productStatement));
                                $productCreateError = 'Unable to save this product right now. Please check the details and try again.';
                            } elseif ($productCreateError === '') {
                                if (!$isProductUpdate) {
                                    $productId = mysqli_insert_id($conn);
                                }
                                if ($isProductUpdate) {
                                    $deleteComponentsStatement = mysqli_prepare($conn, 'DELETE FROM tblproductcomponents WHERE ProductID = ?');
                                    if ($deleteComponentsStatement === false) {
                                        error_log('Unable to prepare previous product component removal: ' . mysqli_error($conn));
                                        $productCreateError = 'Unable to update this product’s inventory components right now. Please try again.';
                                    } else {
                                        mysqli_stmt_bind_param($deleteComponentsStatement, 'i', $productId);
                                        if (!mysqli_stmt_execute($deleteComponentsStatement)) {
                                            error_log('Unable to remove previous product components: ' . mysqli_stmt_error($deleteComponentsStatement));
                                            $productCreateError = 'Unable to update this product’s inventory components right now. Please try again.';
                                        }
                                        mysqli_stmt_close($deleteComponentsStatement);
                                    }
                                }

                                if ($productCreateError === '') {
                                    $componentStatement = mysqli_prepare($conn, 'INSERT INTO tblproductcomponents (ProductID, ItemID, Quantity) VALUES (?, ?, ?)');
                                    if ($componentStatement === false) {
                                        error_log('Unable to prepare product component save query: ' . mysqli_error($conn));
                                        $productCreateError = 'Unable to save this product’s inventory components right now. Please try again later.';
                                    } else {
                                        foreach ($selectedComponents as $component) {
                                            mysqli_stmt_bind_param($componentStatement, 'iii', $productId, $component['item_id'], $component['quantity']);
                                            if (!mysqli_stmt_execute($componentStatement)) {
                                                error_log('Unable to save product component: ' . mysqli_stmt_error($componentStatement));
                                                $productCreateError = 'Unable to save this product’s inventory components right now. Please try again.';
                                                break;
                                            }
                                        }
                                        mysqli_stmt_close($componentStatement);
                                    }
                                }
                                if ($productCreateError === '' && $additionalProductStock > 0) {
                                    if (!deductInventoryForProductStock($conn, $selectedComponents, $additionalProductStock, $productCreateError)) {
                                        if ($productCreateError === '') {
                                            $productCreateError = 'Unable to deduct inventory for the available product quantity.';
                                        }
                                    }
                                }
                            }
                            mysqli_stmt_close($productStatement);
                        }

                        if ($productCreateError === '' && $productImageExtension !== null) {
                            if (
                                (!is_dir($productImageDirectory) && !@mkdir($productImageDirectory, 0755, true))
                                || !is_writable($productImageDirectory)
                            ) {
                                error_log('Unable to create or write to the product image directory: ' . $productImageDirectory);
                                $productCreateError = 'Unable to save product images right now. Please try again later.';
                            } else {
                                $savedProductImagePath = $productImageDirectory . DIRECTORY_SEPARATOR . $productId . '.' . $productImageExtension;
                                if (is_file($savedProductImagePath)) {
                                    $productImageBackupPath = $savedProductImagePath . '.backup-' . bin2hex(random_bytes(6));
                                    if (!rename($savedProductImagePath, $productImageBackupPath)) {
                                        error_log('Unable to preserve the current product image before replacement: ' . $savedProductImagePath);
                                        $productImageBackupPath = '';
                                        $productCreateError = 'Unable to replace the current product image right now. Please try again.';
                                    }
                                }
                                if ($productCreateError === '' && !move_uploaded_file($productImageTemporaryPath, $savedProductImagePath)) {
                                    error_log('Unable to save image for product ' . $productId . '.');
                                    $savedProductImagePath = '';
                                    $productCreateError = 'The product image could not be saved, so the product was not saved. Please try again.';
                                } elseif ($productCreateError === '') {
                                    $newProductImageSaved = true;
                                }
                            }
                        }

                        $productTransactionCommitted = false;
                        if ($productCreateError === '') {
                            if (!mysqli_commit($conn)) {
                                error_log('Unable to commit product save: ' . mysqli_error($conn));
                                if (!mysqli_rollback($conn)) {
                                    error_log('Unable to roll back failed product save: ' . mysqli_error($conn));
                                }
                                $productCreateError = 'Unable to save this product right now. Please try again later.';
                            } else {
                                $productTransactionCommitted = true;
                            }
                        } else {
                            if (!mysqli_rollback($conn)) {
                                error_log('Unable to roll back incomplete product save: ' . mysqli_error($conn));
                            }
                        }

                        if ($productTransactionCommitted) {
                            if ($productImageBackupPath !== '' && is_file($productImageBackupPath) && !@unlink($productImageBackupPath)) {
                                error_log('Unable to remove replaced product image backup: ' . $productImageBackupPath);
                            }
                            if ($productImageExtension !== null) {
                                foreach (['jpg', 'png', 'gif', 'webp'] as $oldExtension) {
                                    if ($oldExtension === $productImageExtension) {
                                        continue;
                                    }
                                    $oldImagePath = $productImageDirectory . DIRECTORY_SEPARATOR . $productId . '.' . $oldExtension;
                                    if (is_file($oldImagePath) && !@unlink($oldImagePath)) {
                                        error_log('Unable to remove old image for product ' . $productId . ': ' . $oldImagePath);
                                    }
                                }
                            }
                        } else {
                            if ($newProductImageSaved && $savedProductImagePath !== '' && is_file($savedProductImagePath) && !@unlink($savedProductImagePath)) {
                                error_log('Unable to remove product image after failed save: ' . $savedProductImagePath);
                            }
                            if ($productImageBackupPath !== '') {
                                $backupSeparator = strpos($productImageBackupPath, '.backup-');
                                $originalImagePath = $backupSeparator === false
                                    ? ''
                                    : substr($productImageBackupPath, 0, $backupSeparator);
                                if ($originalImagePath !== '' && is_file($productImageBackupPath) && !@rename($productImageBackupPath, $originalImagePath)) {
                                    error_log('Unable to restore previous product image after failed save: ' . $productImageBackupPath);
                                }
                            }
                        }
                    }

                    if ($productCreateError === '') {
                        recordActivity(
                            $conn,
                            'product',
                            ($isProductUpdate ? 'Updated product "' : 'Created product "') . $productName . '" (#' . $productId . ').',
                            'product',
                            $productId
                        );
                        $_SESSION['product_notice'] = $isProductUpdate ? 'Product updated successfully.' : 'Product created successfully.';
                        header('Location: index.php?tab=products');
                        exit;
                    }
                }
            }
        }
    } elseif ($postedAction === 'delete_product') {
        $productIdInput = isset($_POST['product_id']) && is_string($_POST['product_id']) ? $_POST['product_id'] : '';
        $productId = ctype_digit($productIdInput) ? (int) $productIdInput : 0;
        if ($productId < 1) {
            $productDeleteError = 'Invalid product selected for deletion.';
        } elseif (!mysqli_begin_transaction($conn)) {
            error_log('Unable to begin product deletion transaction: ' . mysqli_error($conn));
            $productDeleteError = 'Unable to delete this product right now. Please try again later.';
        } else {
            $deleteComponentsStatement = mysqli_prepare($conn, 'DELETE FROM tblproductcomponents WHERE ProductID = ?');
            if ($deleteComponentsStatement === false) {
                error_log('Unable to prepare product components deletion: ' . mysqli_error($conn));
                $productDeleteError = 'Unable to delete this product right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($deleteComponentsStatement, 'i', $productId);
                if (!mysqli_stmt_execute($deleteComponentsStatement)) {
                    error_log('Unable to delete product components: ' . mysqli_stmt_error($deleteComponentsStatement));
                    $productDeleteError = 'Unable to delete this product right now. Please try again later.';
                }
                mysqli_stmt_close($deleteComponentsStatement);
            }

            if ($productDeleteError === '') {
                $deleteProductStatement = mysqli_prepare($conn, 'DELETE FROM tblproducts WHERE ProductID = ?');
                if ($deleteProductStatement === false) {
                    error_log('Unable to prepare product deletion: ' . mysqli_error($conn));
                    $productDeleteError = 'Unable to delete this product right now. Please try again later.';
                } else {
                    mysqli_stmt_bind_param($deleteProductStatement, 'i', $productId);
                    if (!mysqli_stmt_execute($deleteProductStatement)) {
                        error_log('Unable to delete product: ' . mysqli_stmt_error($deleteProductStatement));
                        $productDeleteError = 'Unable to delete this product. It may be used in another record.';
                    } elseif (mysqli_stmt_affected_rows($deleteProductStatement) !== 1) {
                        $productDeleteError = 'This product no longer exists.';
                    }
                    mysqli_stmt_close($deleteProductStatement);
                }
            }

            if ($productDeleteError === '') {
                if (!mysqli_commit($conn)) {
                    error_log('Unable to commit product deletion: ' . mysqli_error($conn));
                    if (!mysqli_rollback($conn)) {
                        error_log('Unable to roll back failed product deletion: ' . mysqli_error($conn));
                    }
                    $productDeleteError = 'Unable to delete this product right now. Please try again later.';
                }
            } else {
                if (!mysqli_rollback($conn)) {
                    error_log('Unable to roll back incomplete product deletion: ' . mysqli_error($conn));
                }
            }

            if ($productDeleteError === '') {
                recordActivity($conn, 'product', 'Deleted product #' . $productId . '.', 'product', $productId);
                $imageCleanupSucceeded = true;
                foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
                    $imagePath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products'
                        . DIRECTORY_SEPARATOR . $productId . '.' . $extension;
                    if (is_file($imagePath) && !unlink($imagePath)) {
                        error_log('Unable to remove image for deleted product ' . $productId . '.');
                        $imageCleanupSucceeded = false;
                    }
                }
                $_SESSION['product_notice'] = $imageCleanupSucceeded
                    ? 'Product deleted successfully.'
                    : 'Product deleted, but its image could not be removed.';
                header('Location: index.php?tab=products');
                exit;
            }
        }
    } elseif ($postedAction === 'create_inventory_item') {
        $itemName = isset($_POST['item_name']) && is_string($_POST['item_name']) ? trim($_POST['item_name']) : '';
        $category = isset($_POST['category']) && is_string($_POST['category']) ? trim($_POST['category']) : '';
        $colorChoice = isset($_POST['color_choice']) && is_string($_POST['color_choice']) ? trim($_POST['color_choice']) : '';
        $customColor = isset($_POST['custom_color']) && is_string($_POST['custom_color']) ? trim($_POST['custom_color']) : '';
        $color = $customColor !== '' ? $customColor : $colorChoice;
        $priceInput = isset($_POST['price']) && is_string($_POST['price']) ? trim($_POST['price']) : '';
        $stockInput = isset($_POST['stock']) && is_string($_POST['stock']) ? trim($_POST['stock']) : '';
        $stock = filter_var($stockInput, FILTER_VALIDATE_INT);
        $validPrice = preg_match('/\A[0-9]{1,20}\z/', $priceInput) === 1;
        $uploadedImage = $_FILES['item_image'] ?? null;
        $imageExtension = null;
        $imageTemporaryPath = '';

        if (
            $itemName === ''
            || strlen($itemName) > 50
            || !in_array($category, ['Flower', 'Wrapper', 'Ribbon', 'Addons', 'Container'], true)
            || ($customColor === '' && !in_array($colorChoice, $inventoryColorOptions, true))
            || $color === ''
            || !$validPrice
            || $stock === false
            || $stock < 0
            || $stock > 2147483647
        ) {
            $itemCreateError = 'Enter an item name up to 50 characters, choose a category and color (or enter a custom color), and provide a non-negative whole-number price and stock quantity.';
        } elseif (
            !is_array($uploadedImage)
            || !isset($uploadedImage['error'])
            || !is_int($uploadedImage['error'])
        ) {
            $itemCreateError = 'The selected image could not be read. Please choose it again.';
        } elseif ($uploadedImage['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedImage['error'] !== UPLOAD_ERR_OK) {
                $itemCreateError = 'The image upload failed. Please choose an image smaller than 5 MB and try again.';
            } elseif (
                !isset($uploadedImage['tmp_name'], $uploadedImage['size'])
                || !is_string($uploadedImage['tmp_name'])
                || !is_int($uploadedImage['size'])
                || $uploadedImage['size'] < 1
                || $uploadedImage['size'] > 5 * 1024 * 1024
                || !is_uploaded_file($uploadedImage['tmp_name'])
            ) {
                $itemCreateError = 'Choose a valid image file smaller than 5 MB.';
            } else {
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($fileInfo === false) {
                    error_log('Unable to initialize image upload validation.');
                    $itemCreateError = 'Unable to validate the image right now. Please try again later.';
                } else {
                    $imageMimeType = finfo_file($fileInfo, $uploadedImage['tmp_name']);
                    finfo_close($fileInfo);

                    $allowedImageTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];

                    if (!is_string($imageMimeType) || !isset($allowedImageTypes[$imageMimeType])) {
                        $itemCreateError = 'The image must be a JPEG, PNG, GIF, or WebP file.';
                    } else {
                        $imageDetails = @getimagesize($uploadedImage['tmp_name']);
                        if (
                            $imageDetails === false
                            || !isset($imageDetails['mime'])
                            || $imageDetails['mime'] !== $imageMimeType
                        ) {
                            $itemCreateError = 'The selected file is not a valid image.';
                        } else {
                            $imageExtension = $allowedImageTypes[$imageMimeType];
                            $imageTemporaryPath = $uploadedImage['tmp_name'];
                        }
                    }
                }
            }
        }

        if ($itemCreateError === '' && $imageExtension !== null) {
            $inventoryImageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'inventory';
            if (
                (!is_dir($inventoryImageDirectory) && !@mkdir($inventoryImageDirectory, 0755, true))
                || !is_writable($inventoryImageDirectory)
            ) {
                error_log('Unable to create or write to the inventory image directory: ' . $inventoryImageDirectory);
                $itemCreateError = 'Unable to save item images right now. Please try again later.';
            }
        }

        if ($itemCreateError === '') {
            $statement = mysqli_prepare(
                $conn,
                'INSERT INTO tblinventory (ItemName, Category, Color, Price, Stock) VALUES (?, ?, ?, ?, ?)'
            );

            if ($statement === false) {
                error_log('Unable to prepare inventory item creation query: ' . mysqli_error($conn));
                $itemCreateError = 'Unable to add this item right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($statement, 'ssssi', $itemName, $category, $color, $priceInput, $stock);

                if (!mysqli_stmt_execute($statement)) {
                    error_log('Unable to create inventory item: ' . mysqli_stmt_error($statement));
                    $itemCreateError = 'Unable to add this item. Please check the item details and try again.';
                } else {
                    $itemId = mysqli_insert_id($conn);

                    if ($imageExtension !== null) {
                        $imageDestination = __DIR__
                            . DIRECTORY_SEPARATOR . 'uploads'
                            . DIRECTORY_SEPARATOR . 'inventory'
                            . DIRECTORY_SEPARATOR . $itemId . '.' . $imageExtension;

                        if (file_exists($imageDestination) || !move_uploaded_file($imageTemporaryPath, $imageDestination)) {
                            error_log('Unable to save image for inventory item ' . $itemId . '.');
                            $itemCleanupSucceeded = false;
                            $deleteStatement = mysqli_prepare($conn, 'DELETE FROM tblinventory WHERE ItemID = ?');
                            if ($deleteStatement !== false) {
                                mysqli_stmt_bind_param($deleteStatement, 'i', $itemId);
                                $itemCleanupSucceeded = mysqli_stmt_execute($deleteStatement)
                                    && mysqli_stmt_affected_rows($deleteStatement) === 1;
                                if (!$itemCleanupSucceeded) {
                                    error_log('Unable to remove inventory item ' . $itemId . ' after its image upload failed.');
                                }
                                mysqli_stmt_close($deleteStatement);
                            } else {
                                error_log('Unable to prepare cleanup for inventory item ' . $itemId . ': ' . mysqli_error($conn));
                            }
                            $itemCreateError = $itemCleanupSucceeded
                                ? 'The item image could not be saved, so the item was not added. Please try again.'
                                : 'The item image could not be saved and the item may still exist without its picture. Contact support with item ID ' . $itemId . '.';
                        }
                    }

                    if ($itemCreateError === '') {
                        mysqli_stmt_close($statement);
                        recordActivity(
                            $conn,
                            'inventory',
                            'Added inventory item "' . $itemName . '" (#' . $itemId . ') in category ' . $category . '.',
                            'inventory',
                            $itemId
                        );
                        $_SESSION['inventory_notice'] = 'Item added successfully.';
                        header('Location: index.php');
                        exit;
                    }
                }
                mysqli_stmt_close($statement);
            }
        }
    } elseif ($postedAction === 'update_inventory_item') {
        $itemIdInput = isset($_POST['item_id']) && is_string($_POST['item_id']) ? $_POST['item_id'] : '';
        $itemId = ctype_digit($itemIdInput) ? (int) $itemIdInput : 0;
        $itemName = isset($_POST['item_name']) && is_string($_POST['item_name']) ? trim($_POST['item_name']) : '';
        $category = isset($_POST['category']) && is_string($_POST['category']) ? trim($_POST['category']) : '';
        $colorChoice = isset($_POST['color_choice']) && is_string($_POST['color_choice']) ? trim($_POST['color_choice']) : '';
        $customColor = isset($_POST['custom_color']) && is_string($_POST['custom_color']) ? trim($_POST['custom_color']) : '';
        $color = $customColor !== '' ? $customColor : $colorChoice;
        $priceInput = isset($_POST['price']) && is_string($_POST['price']) ? trim($_POST['price']) : '';
        $stockInput = isset($_POST['stock']) && is_string($_POST['stock']) ? trim($_POST['stock']) : '';
        $stock = filter_var($stockInput, FILTER_VALIDATE_INT);
        $stockSupplierIdInput = isset($_POST['stock_supplier_id']) && is_string($_POST['stock_supplier_id'])
            ? trim($_POST['stock_supplier_id'])
            : '';
        $stockSupplierId = $stockSupplierIdInput === ''
            ? null
            : (ctype_digit($stockSupplierIdInput) ? (int) $stockSupplierIdInput : 0);
        $validPrice = preg_match('/\A[0-9]{1,20}\z/', $priceInput) === 1;
        $uploadedImage = $_FILES['item_image'] ?? null;
        $imageExtension = null;
        $imageTemporaryPath = '';
        $editInventoryItem = [
            'ItemID' => $itemIdInput,
            'ItemName' => $itemName,
            'Category' => $category,
            'Color' => $color,
            'Price' => $priceInput,
            'Stock' => $stockInput,
        ];
        $existingCategory = '';

        if (
            $itemId < 1
            || $itemName === ''
            || strlen($itemName) > 50
            || !in_array($category, ['Flower', 'Wrapper', 'Ribbon', 'Addons', 'Container'], true)
            || ($customColor === '' && !in_array($colorChoice, $inventoryColorOptions, true))
            || $color === ''
            || !$validPrice
            || $stock === false
            || $stock < 0
            || $stock > 2147483647
        ) {
            $itemUpdateError = 'Enter an item name up to 50 characters, choose a category and color (or enter a custom color), and provide a non-negative whole-number price and stock quantity.';
        } elseif (
            !is_array($uploadedImage)
            || !isset($uploadedImage['error'])
            || !is_int($uploadedImage['error'])
        ) {
            $itemUpdateError = 'The selected image could not be read. Please choose it again.';
        } elseif ($uploadedImage['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($uploadedImage['error'] !== UPLOAD_ERR_OK) {
                $itemUpdateError = 'The image upload failed. Please choose an image smaller than 5 MB and try again.';
            } elseif (
                !isset($uploadedImage['tmp_name'], $uploadedImage['size'])
                || !is_string($uploadedImage['tmp_name'])
                || !is_int($uploadedImage['size'])
                || $uploadedImage['size'] < 1
                || $uploadedImage['size'] > 5 * 1024 * 1024
                || !is_uploaded_file($uploadedImage['tmp_name'])
            ) {
                $itemUpdateError = 'Choose a valid image file smaller than 5 MB.';
            } else {
                $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($fileInfo === false) {
                    error_log('Unable to initialize inventory image update validation.');
                    $itemUpdateError = 'Unable to validate the image right now. Please try again later.';
                } else {
                    $imageMimeType = finfo_file($fileInfo, $uploadedImage['tmp_name']);
                    finfo_close($fileInfo);
                    $allowedImageTypes = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                    ];

                    if (!is_string($imageMimeType) || !isset($allowedImageTypes[$imageMimeType])) {
                        $itemUpdateError = 'The image must be a JPEG, PNG, GIF, or WebP file.';
                    } else {
                        $imageDetails = @getimagesize($uploadedImage['tmp_name']);
                        if (
                            $imageDetails === false
                            || !isset($imageDetails['mime'])
                            || $imageDetails['mime'] !== $imageMimeType
                        ) {
                            $itemUpdateError = 'The selected file is not a valid image.';
                        } else {
                            $imageExtension = $allowedImageTypes[$imageMimeType];
                            $imageTemporaryPath = $uploadedImage['tmp_name'];
                        }
                    }
                }
            }
        }

        if ($itemUpdateError === '' && $imageExtension !== null) {
            $inventoryImageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'inventory';
            if (
                (!is_dir($inventoryImageDirectory) && !@mkdir($inventoryImageDirectory, 0755, true))
                || !is_writable($inventoryImageDirectory)
            ) {
                error_log('Unable to create or write to the inventory image directory: ' . $inventoryImageDirectory);
                $itemUpdateError = 'Unable to save item images right now. Please try again later.';
            }
        }

        if ($itemUpdateError === '') {
            $existsStatement = mysqli_prepare($conn, 'SELECT Category FROM tblinventory WHERE ItemID = ?');
            if ($existsStatement === false) {
                error_log('Unable to prepare inventory item lookup: ' . mysqli_error($conn));
                $itemUpdateError = 'Unable to update this item right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($existsStatement, 'i', $itemId);
                if (!mysqli_stmt_execute($existsStatement)) {
                    error_log('Unable to verify inventory item: ' . mysqli_stmt_error($existsStatement));
                    $itemUpdateError = 'Unable to update this item right now. Please try again later.';
                } else {
                    mysqli_stmt_store_result($existsStatement);
                    mysqli_stmt_bind_result($existsStatement, $existingCategory);
                    if (mysqli_stmt_num_rows($existsStatement) !== 1) {
                        $itemUpdateError = 'This inventory item no longer exists.';
                    } elseif (!mysqli_stmt_fetch($existsStatement)) {
                        error_log('Unable to read inventory item category for item ' . $itemId . '.');
                        $itemUpdateError = 'Unable to update this item right now. Please try again later.';
                    }
                }
                mysqli_stmt_close($existsStatement);
            }
        }

        if ($itemUpdateError === '' && $existingCategory !== $category) {
            $componentLookup = mysqli_prepare(
                $conn,
                'SELECT ProductID FROM tblproductcomponents WHERE ItemID = ? LIMIT 1'
            );
            if ($componentLookup === false) {
                error_log('Unable to prepare product component lookup before inventory category change: ' . mysqli_error($conn));
                $itemUpdateError = 'Unable to update this item right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($componentLookup, 'i', $itemId);
                if (!mysqli_stmt_execute($componentLookup)) {
                    error_log('Unable to check product component references: ' . mysqli_stmt_error($componentLookup));
                    $itemUpdateError = 'Unable to update this item right now. Please try again later.';
                } else {
                    mysqli_stmt_store_result($componentLookup);
                    if (mysqli_stmt_num_rows($componentLookup) > 0) {
                        mysqli_stmt_bind_result($componentLookup, $usedProductId);
                        mysqli_stmt_fetch($componentLookup);
                        $itemUpdateError = 'This inventory item is used by product #' . $usedProductId . ' and its category cannot be changed.';
                    }
                }
                mysqli_stmt_close($componentLookup);
            }
        }

        if ($itemUpdateError === '') {
            if (!mysqli_begin_transaction($conn)) {
                error_log('Unable to begin inventory update transaction: ' . mysqli_error($conn));
                $itemUpdateError = 'Unable to update this item right now. Please try again later.';
            } else {
                $stockLookup = mysqli_prepare(
                    $conn,
                    'SELECT Stock FROM tblinventory WHERE ItemID = ? FOR UPDATE'
                );
                $stockBefore = 0;
                if ($stockLookup === false) {
                    error_log('Unable to prepare inventory stock lookup: ' . mysqli_error($conn));
                    $itemUpdateError = 'Unable to verify the current inventory stock. Please try again.';
                } else {
                    mysqli_stmt_bind_param($stockLookup, 'i', $itemId);
                    if (!mysqli_stmt_execute($stockLookup)) {
                        error_log('Unable to read inventory stock: ' . mysqli_stmt_error($stockLookup));
                        $itemUpdateError = 'Unable to verify the current inventory stock. Please try again.';
                    } else {
                        mysqli_stmt_bind_result($stockLookup, $stockBefore);
                        if (!mysqli_stmt_fetch($stockLookup)) {
                            $itemUpdateError = 'This inventory item no longer exists.';
                        }
                    }
                    mysqli_stmt_close($stockLookup);
                }

                $stockIncreaseQuantity = $itemUpdateError === '' ? $stock - (int) $stockBefore : 0;
                $supplierNameForTransaction = 'Unassigned supplier';
                if ($itemUpdateError === '' && $stockIncreaseQuantity > 0) {
                    $assignedSupplierLookup = mysqli_prepare(
                        $conn,
                        'SELECT s.SupplierName
                         FROM tblsupplieritems si
                         INNER JOIN tblsuppliers s ON s.SupplierID = si.SupplierID
                         WHERE si.ItemID = ? AND si.SupplierID = ?'
                    );
                    $assignedSupplierCount = mysqli_prepare(
                        $conn,
                        'SELECT COUNT(*) FROM tblsupplieritems WHERE ItemID = ?'
                    );
                    if ($assignedSupplierLookup === false || $assignedSupplierCount === false) {
                        error_log('Unable to prepare supplier validation for stock receipt: ' . mysqli_error($conn));
                        $itemUpdateError = 'Unable to verify the selected supplier. Please try again.';
                    } else {
                        mysqli_stmt_bind_param($assignedSupplierCount, 'i', $itemId);
                        if (!mysqli_stmt_execute($assignedSupplierCount)) {
                            error_log('Unable to check suppliers assigned to inventory item: ' . mysqli_stmt_error($assignedSupplierCount));
                            $itemUpdateError = 'Unable to verify the selected supplier. Please try again.';
                        } else {
                            mysqli_stmt_bind_result($assignedSupplierCount, $assignedSupplierTotal);
                            mysqli_stmt_fetch($assignedSupplierCount);
                            if ($stockSupplierId === null && (int) $assignedSupplierTotal > 0) {
                                $itemUpdateError = 'Select a supplier for this stock increase.';
                            }
                        }
                        mysqli_stmt_close($assignedSupplierCount);

                        if ($itemUpdateError === '' && $stockSupplierId !== null) {
                            if ($stockSupplierId < 1) {
                                $itemUpdateError = 'Select a valid supplier for this stock increase.';
                            } else {
                                mysqli_stmt_bind_param($assignedSupplierLookup, 'ii', $itemId, $stockSupplierId);
                                if (!mysqli_stmt_execute($assignedSupplierLookup)) {
                                    error_log('Unable to verify selected stock supplier: ' . mysqli_stmt_error($assignedSupplierLookup));
                                    $itemUpdateError = 'Unable to verify the selected supplier. Please try again.';
                                } else {
                                    mysqli_stmt_bind_result($assignedSupplierLookup, $supplierNameForTransaction);
                                    if (!mysqli_stmt_fetch($assignedSupplierLookup)) {
                                        $itemUpdateError = 'The selected supplier does not supply this inventory item.';
                                    }
                                }
                            }
                        }
                        mysqli_stmt_close($assignedSupplierLookup);
                    }
                }

                if ($itemUpdateError === '') {
                    $statement = mysqli_prepare(
                        $conn,
                        'UPDATE tblinventory SET ItemName = ?, Category = ?, Color = ?, Price = ?, Stock = ? WHERE ItemID = ?'
                    );
                    if ($statement === false) {
                        error_log('Unable to prepare inventory item update query: ' . mysqli_error($conn));
                        $itemUpdateError = 'Unable to update this item right now. Please try again later.';
                    } else {
                        mysqli_stmt_bind_param($statement, 'ssssii', $itemName, $category, $color, $priceInput, $stock, $itemId);
                        if (!mysqli_stmt_execute($statement)) {
                            error_log('Unable to update inventory item: ' . mysqli_stmt_error($statement));
                            $itemUpdateError = 'Unable to update this item. Please check the item details and try again.';
                        }
                        mysqli_stmt_close($statement);
                    }
                }

                if ($itemUpdateError === '' && $stockIncreaseQuantity > 0) {
                    $receivedBy = (int) $_SESSION['user_id'];
                    $transactionStatement = mysqli_prepare(
                        $conn,
                        'INSERT INTO tblsupplierstocktransactions
                            (SupplierID, SupplierName, ItemID, ItemName, Quantity, StockBefore, StockAfter, ReceivedBy)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if ($transactionStatement === false) {
                        error_log('Unable to prepare supplier stock transaction insert: ' . mysqli_error($conn));
                        $itemUpdateError = 'Unable to record the supplier transaction. The stock update was not saved.';
                    } else {
                        mysqli_stmt_bind_param(
                            $transactionStatement,
                            'isisiiii',
                            $stockSupplierId,
                            $supplierNameForTransaction,
                            $itemId,
                            $itemName,
                            $stockIncreaseQuantity,
                            $stockBefore,
                            $stock,
                            $receivedBy
                        );
                        if (!mysqli_stmt_execute($transactionStatement)) {
                            error_log('Unable to record supplier stock transaction: ' . mysqli_stmt_error($transactionStatement));
                            $itemUpdateError = 'Unable to record the supplier transaction. The stock update was not saved.';
                        }
                        mysqli_stmt_close($transactionStatement);
                    }
                }

                if ($itemUpdateError === '' && !mysqli_commit($conn)) {
                    error_log('Unable to commit inventory update transaction: ' . mysqli_error($conn));
                    $itemUpdateError = 'Unable to save this inventory update. Please try again.';
                }
                if ($itemUpdateError !== '') {
                    mysqli_rollback($conn);
                }
            }
        }

        if ($itemUpdateError === '' && $imageExtension !== null) {
            $imageDestination = __DIR__
                . DIRECTORY_SEPARATOR . 'uploads'
                . DIRECTORY_SEPARATOR . 'inventory'
                . DIRECTORY_SEPARATOR . $itemId . '.' . $imageExtension;
            if (!move_uploaded_file($imageTemporaryPath, $imageDestination)) {
                error_log('Unable to replace image for inventory item ' . $itemId . '.');
                $itemUpdateError = 'Item details were updated, but the picture could not be saved. Please try uploading the picture again.';
            } else {
                foreach (['jpg', 'png', 'gif', 'webp'] as $oldExtension) {
                    if ($oldExtension !== $imageExtension) {
                        $oldImagePath = __DIR__
                            . DIRECTORY_SEPARATOR . 'uploads'
                            . DIRECTORY_SEPARATOR . 'inventory'
                            . DIRECTORY_SEPARATOR . $itemId . '.' . $oldExtension;
                        if (is_file($oldImagePath) && !unlink($oldImagePath)) {
                            error_log('Unable to remove outdated image for inventory item ' . $itemId . '.');
                        }
                    }
                }
            }
        }

        if ($itemUpdateError === '') {
            recordActivity(
                $conn,
                'inventory',
                'Updated inventory item "' . $itemName . '" (#' . $itemId . '), stock ' . $stock . '.',
                'inventory',
                $itemId
            );
            $_SESSION['inventory_notice'] = 'Item updated successfully.';
            header('Location: index.php');
            exit;
        }
    } elseif ($postedAction === 'delete_inventory_item') {
        $itemIdInput = isset($_POST['item_id']) && is_string($_POST['item_id']) ? $_POST['item_id'] : '';
        $itemId = ctype_digit($itemIdInput) ? (int) $itemIdInput : 0;

        if ($itemId < 1) {
            $itemDeleteError = 'Invalid inventory item selected.';
        } else {
            $componentLookup = mysqli_prepare(
                $conn,
                'SELECT ProductID FROM tblproductcomponents WHERE ItemID = ? LIMIT 1'
            );

            if ($componentLookup === false) {
                error_log('Unable to prepare product component lookup before inventory deletion: ' . mysqli_error($conn));
                $itemDeleteError = 'Unable to delete this item right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($componentLookup, 'i', $itemId);
                if (!mysqli_stmt_execute($componentLookup)) {
                    error_log('Unable to check product component references: ' . mysqli_stmt_error($componentLookup));
                    $itemDeleteError = 'Unable to delete this item right now. Please try again later.';
                } else {
                    mysqli_stmt_store_result($componentLookup);
                    if (mysqli_stmt_num_rows($componentLookup) > 0) {
                        mysqli_stmt_bind_result($componentLookup, $usedProductId);
                        mysqli_stmt_fetch($componentLookup);
                        $itemDeleteError = 'This inventory item is used by product #' . $usedProductId . ' and cannot be deleted.';
                    }
                }
                mysqli_stmt_close($componentLookup);
            }

            if ($itemDeleteError === '') {
                $statement = mysqli_prepare($conn, 'DELETE FROM tblinventory WHERE ItemID = ?');

                if ($statement === false) {
                    error_log('Unable to prepare inventory item deletion query: ' . mysqli_error($conn));
                    $itemDeleteError = 'Unable to delete this item right now. Please try again later.';
                } else {
                    mysqli_stmt_bind_param($statement, 'i', $itemId);

                    if (!mysqli_stmt_execute($statement)) {
                        error_log('Unable to delete inventory item: ' . mysqli_stmt_error($statement));
                        $itemDeleteError = 'Unable to delete this item right now. Please try again later.';
                    } elseif (mysqli_stmt_affected_rows($statement) !== 1) {
                        $itemDeleteError = 'This inventory item no longer exists.';
                    }
                    mysqli_stmt_close($statement);

                    if ($itemDeleteError === '') {
                        recordActivity($conn, 'inventory', 'Deleted inventory item #' . $itemId . '.', 'inventory', $itemId);
                        $imageCleanupSucceeded = true;
                        foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
                            $imagePath = __DIR__
                                . DIRECTORY_SEPARATOR . 'uploads'
                                . DIRECTORY_SEPARATOR . 'inventory'
                                . DIRECTORY_SEPARATOR . $itemId . '.' . $extension;
                            if (is_file($imagePath) && !unlink($imagePath)) {
                                error_log('Unable to remove image for deleted inventory item ' . $itemId . '.');
                                $imageCleanupSucceeded = false;
                            }
                        }

                        $_SESSION['inventory_notice'] = $imageCleanupSucceeded
                            ? 'Item deleted successfully.'
                            : 'Item deleted, but its picture could not be removed.';
                        header('Location: index.php');
                        exit;
                    }
                }
            }
        }
    } elseif ($postedAction === 'toggle_customer_access') {
        if (!$isAdmin) {
            http_response_code(403);
            exit('Only an administrator can change customer access.');
        }

        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            exit('Invalid customer access request.');
        }

        $customerId = isset($_POST['customer_id']) && is_string($_POST['customer_id']) && ctype_digit($_POST['customer_id'])
            ? (int) $_POST['customer_id']
            : 0;
        $access = isset($_POST['access']) && is_string($_POST['access']) ? $_POST['access'] : '';
        if ($customerId < 1 || !in_array($access, ['Enabled', 'Disabled'], true)) {
            $userUpdateError = 'Select a valid customer account and access status.';
        } else {
            $statement = mysqli_prepare(
                $conn,
                "UPDATE tblcustomers SET `Access` = ? WHERE CustomerID = ? AND `Access` IN ('Enabled', 'Disabled')"
            );
            if ($statement === false) {
                error_log('Unable to prepare customer access update: ' . mysqli_error($conn));
                $userUpdateError = 'Unable to update customer access right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($statement, 'si', $access, $customerId);
                if (!mysqli_stmt_execute($statement)) {
                    error_log('Unable to update customer access: ' . mysqli_stmt_error($statement));
                    $userUpdateError = 'Unable to update customer access right now. Please try again later.';
                } elseif (mysqli_stmt_affected_rows($statement) !== 1) {
                    $userUpdateError = 'This customer account no longer exists or already has that access status.';
                }
                mysqli_stmt_close($statement);
                if ($userUpdateError === '') {
                    $accessLabel = $access === 'Enabled' ? 'enabled' : 'disabled';
                    recordActivity(
                        $conn,
                        'user',
                        'Customer account #' . $customerId . ' access ' . $accessLabel . '.',
                        'customer',
                        $customerId
                    );
                    $_SESSION['user_notice'] = 'Customer access ' . $accessLabel . ' successfully.';
                    header('Location: index.php?tab=users&user_category=customer');
                    exit;
                }
            }
        }
    } elseif ($postedAction === 'cancel_create_user_pin') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            $userCreateError = 'Your session expired. Refresh the page and try again.';
        } else {
            unset($_SESSION['pending_staff_create']);
        }
    } elseif ($postedAction === 'verify_create_user_pin') {
        $submittedToken = $_POST['csrf_token'] ?? '';
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        $pendingUser = $_SESSION['pending_staff_create'] ?? null;
        $submittedPin = isset($_POST['pin']) && is_string($_POST['pin']) ? trim($_POST['pin']) : '';
        if (!is_string($submittedToken) || !is_string($sessionToken) || !hash_equals($sessionToken, $submittedToken)) {
            $userCreateError = 'Your session expired. Refresh the page and try again.';
        } elseif (!is_array($pendingUser) || !isset($pendingUser['email'], $pendingUser['password_hash'])) {
            unset($_SESSION['pending_staff_create']);
            $userCreateError = 'Account verification expired. Please add the user again.';
        } elseif (!preg_match('/^\d{6}$/', $submittedPin)) {
            $userCreateError = 'Enter the 6-digit verification PIN.';
        } elseif (verifyAuthenticationPin(
            $conn,
            'staff',
            'create_user',
            (string) $pendingUser['email'],
            $submittedPin,
            null,
            $userCreateError
        )) {
            $statement = mysqli_prepare(
                $conn,
                'INSERT INTO tblusers (Firstname, Lastname, Username, Email, Password, Role, `Access`)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($statement === false) {
                error_log('Unable to prepare verified user creation: ' . mysqli_error($conn));
                unset($_SESSION['pending_staff_create']);
                $userCreateError = 'Unable to add this user right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param(
                    $statement,
                    'sssssss',
                    $pendingUser['first_name'],
                    $pendingUser['last_name'],
                    $pendingUser['username'],
                    $pendingUser['email'],
                    $pendingUser['password_hash'],
                    $pendingUser['role'],
                    $pendingUser['access']
                );
                if (mysqli_stmt_execute($statement)) {
                    $userId = (int) mysqli_insert_id($conn);
                    mysqli_stmt_close($statement);
                    unset($_SESSION['pending_staff_create']);
                    recordActivity(
                        $conn,
                        'user',
                        'Created ' . $pendingUser['role'] . ' account "' . $pendingUser['username'] . '" (#' . $userId . ').',
                        'user',
                        $userId
                    );
                    $_SESSION['user_notice'] = 'User added successfully.';
                    header('Location: index.php');
                    exit;
                }
                error_log('Unable to create verified user: ' . mysqli_stmt_error($statement));
                $userCreateError = 'Unable to add this user. Check that the username is unique and try again.';
                mysqli_stmt_close($statement);
                unset($_SESSION['pending_staff_create']);
            }
        }
    } elseif ($postedAction === 'create_user') {
        $userId = 0;
        $submittedCreateToken = $_POST['create_token'] ?? '';
        if (
            !is_string($submittedCreateToken)
            || $userCreateToken === ''
            || !hash_equals($userCreateToken, $submittedCreateToken)
        ) {
            $userCreateError = 'This add-user form was already submitted. Please open a new form and try again.';
        } else {
            unset($_SESSION['user_create_token']);
            $userCreateToken = '';
        }

        $firstName = isset($_POST['firstname']) && is_string($_POST['firstname']) ? trim($_POST['firstname']) : '';
        $lastName = isset($_POST['lastname']) && is_string($_POST['lastname']) ? trim($_POST['lastname']) : '';
        $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $role = isset($_POST['role']) && is_string($_POST['role']) ? trim($_POST['role']) : '';
        $access = isset($_POST['access']) && is_string($_POST['access']) ? trim($_POST['access']) : '';

        if (
            $userCreateError === ''
            && (
            $firstName === ''
            || $lastName === ''
            || $username === ''
            || $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $password === ''
            || !in_array($role, ['Admin', 'Staff'], true)
            || !in_array($access, ['Enabled', 'Disabled'], true)
            )
        ) {
            $userCreateError = 'Please complete all fields.';
        } elseif ($userCreateError === '') {
            $authPinError = '';
            if (sendAuthenticationPin($conn, 'staff', 'create_user', $email, null, $authPinError)) {
                $_SESSION['pending_staff_create'] = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role,
                    'access' => $access,
                ];
                $userCreateNotice = 'A verification PIN was sent to the new user’s email. Enter it below to finish creating the account.';
            } else {
                $userCreateError = $authPinError;
            }
        }
    } elseif ($postedAction === 'update_user') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id']) && ctype_digit($_POST['user_id'])
            ? (int) $_POST['user_id']
            : 0;
        $firstName = isset($_POST['firstname']) && is_string($_POST['firstname']) ? trim($_POST['firstname']) : '';
        $lastName = isset($_POST['lastname']) && is_string($_POST['lastname']) ? trim($_POST['lastname']) : '';
        $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) && is_string($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $role = isset($_POST['role']) && is_string($_POST['role']) ? trim($_POST['role']) : '';
        $access = isset($_POST['access']) && is_string($_POST['access']) ? trim($_POST['access']) : '';

        if (
            $userId < 1
            || $firstName === ''
            || $lastName === ''
            || $username === ''
            || $email === ''
            || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || !in_array($role, ['Admin', 'Staff'], true)
            || !in_array($access, ['Enabled', 'Disabled'], true)
        ) {
            $userUpdateError = 'Please provide valid information for every required field.';
        } else {
            $updatePassword = $password !== '';
            $query = $updatePassword
                ? 'UPDATE tblusers SET Firstname = ?, Lastname = ?, Username = ?, Email = ?, Password = ?, Role = ?, `Access` = ? WHERE UserID = ?'
                : 'UPDATE tblusers SET Firstname = ?, Lastname = ?, Username = ?, Email = ?, Role = ?, `Access` = ? WHERE UserID = ?';
            $statement = mysqli_prepare($conn, $query);

            if ($statement === false) {
                error_log('Unable to prepare user update query: ' . mysqli_error($conn));
                $userUpdateError = 'Unable to update this user right now. Please try again later.';
            } else {
                if ($updatePassword) {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    mysqli_stmt_bind_param($statement, 'sssssssi', $firstName, $lastName, $username, $email, $hashedPassword, $role, $access, $userId);
                } else {
                    mysqli_stmt_bind_param($statement, 'ssssssi', $firstName, $lastName, $username, $email, $role, $access, $userId);
                }

                if (mysqli_stmt_execute($statement)) {
                    $userUpdateSuccess = true;
                } else {
                    error_log('Unable to update user: ' . mysqli_stmt_error($statement));
                    $userUpdateError = 'Unable to update this user. Check that the username is unique and try again.';
                }
                mysqli_stmt_close($statement);

                if ($userUpdateSuccess) {
                    recordActivity(
                        $conn,
                        'user',
                        'Updated account "' . $username . '" (#' . $userId . ').',
                        'user',
                        $userId
                    );
                    $_SESSION['user_notice'] = 'User updated successfully.';
                    header('Location: index.php');
                    exit;
                }
            }
        }
    } elseif ($postedAction === 'delete_user') {
        $userId = isset($_POST['user_id']) && is_string($_POST['user_id']) && ctype_digit($_POST['user_id'])
            ? (int) $_POST['user_id']
            : 0;

        if ($userId < 1) {
            $userDeleteError = 'Invalid user selected.';
        } else {
            $statement = mysqli_prepare($conn, 'DELETE FROM tblusers WHERE UserID = ?');

            if ($statement === false) {
                error_log('Unable to prepare user deletion query: ' . mysqli_error($conn));
                $userDeleteError = 'Unable to delete this user right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($statement, 'i', $userId);

                if (mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) === 1) {
                    $userDeleteSuccess = true;
                } else {
                    error_log('Unable to delete user: ' . mysqli_stmt_error($statement));
                    $userDeleteError = 'Unable to delete this user. It may no longer exist.';
                }
                mysqli_stmt_close($statement);

                if ($userDeleteSuccess) {
                    recordActivity($conn, 'user', 'Deleted user account #' . $userId . '.', 'user', $userId);
                    $_SESSION['user_notice'] = 'User deleted successfully.';
                    header('Location: index.php');
                    exit;
                }
            }
        }
    } elseif (in_array($postedAction, ['create_supplier', 'update_supplier'], true)) {
        $isSupplierUpdate = $postedAction === 'update_supplier';
        $supplierId = isset($_POST['supplier_id']) && is_string($_POST['supplier_id']) && ctype_digit($_POST['supplier_id'])
            ? (int) $_POST['supplier_id']
            : 0;
        $supplierName = isset($_POST['supplier_name']) && is_string($_POST['supplier_name'])
            ? trim($_POST['supplier_name'])
            : '';
        $supplierContact = isset($_POST['contact_name']) && is_string($_POST['contact_name'])
            ? trim($_POST['contact_name'])
            : '';
        $supplierPhone = isset($_POST['phone']) && is_string($_POST['phone'])
            ? trim($_POST['phone'])
            : '';
        $supplierEmail = isset($_POST['email']) && is_string($_POST['email'])
            ? trim($_POST['email'])
            : '';
        $supplierAddress = isset($_POST['address']) && is_string($_POST['address'])
            ? trim($_POST['address'])
            : '';
        $supplierItemInput = $_POST['inventory_item_ids'] ?? [];
        $supplierItemIds = [];
        $supplierOperationError = '';

        if (!is_array($supplierItemInput) || count($supplierItemInput) > 200) {
            $supplierOperationError = 'Choose valid inventory items for this supplier.';
        } else {
            foreach ($supplierItemInput as $itemIdInput) {
                if (!is_string($itemIdInput) || !ctype_digit($itemIdInput) || (int) $itemIdInput < 1) {
                    $supplierOperationError = 'Choose valid inventory items for this supplier.';
                    break;
                }
                $supplierItemIds[] = (int) $itemIdInput;
            }
            $supplierItemIds = array_values(array_unique($supplierItemIds));
        }

        if (
            $supplierOperationError === ''
            && (
                ($isSupplierUpdate && $supplierId < 1)
                || $supplierName === ''
                || mb_strlen($supplierName) > 100
                || mb_strlen($supplierContact) > 100
                || mb_strlen($supplierPhone) > 40
                || mb_strlen($supplierEmail) > 254
                || ($supplierEmail !== '' && filter_var($supplierEmail, FILTER_VALIDATE_EMAIL) === false)
                || mb_strlen($supplierAddress) > 255
            )
        ) {
            $supplierOperationError = 'Enter a supplier name and valid contact details within the listed length limits.';
        }

        if (
            $supplierOperationError === ''
            && !supplierInventoryItemsExist($conn, $supplierItemIds)
        ) {
            $supplierOperationError = 'One or more selected inventory items could not be found.';
        }

        if ($supplierOperationError === '' && $isSupplierUpdate) {
            $existsStatement = mysqli_prepare($conn, 'SELECT SupplierID FROM tblsuppliers WHERE SupplierID = ?');
            if ($existsStatement === false) {
                error_log('Unable to prepare supplier lookup: ' . mysqli_error($conn));
                $supplierOperationError = 'Unable to update this supplier right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($existsStatement, 'i', $supplierId);
                if (!mysqli_stmt_execute($existsStatement)) {
                    error_log('Unable to check supplier before update: ' . mysqli_stmt_error($existsStatement));
                    $supplierOperationError = 'Unable to update this supplier right now. Please try again later.';
                } else {
                    mysqli_stmt_store_result($existsStatement);
                    if (mysqli_stmt_num_rows($existsStatement) !== 1) {
                        $supplierOperationError = 'The selected supplier could not be found.';
                    }
                }
                mysqli_stmt_close($existsStatement);
            }
        }

        if ($supplierOperationError === '' && !mysqli_begin_transaction($conn)) {
            error_log('Unable to begin supplier transaction: ' . mysqli_error($conn));
            $supplierOperationError = 'Unable to save this supplier right now. Please try again later.';
        } elseif ($supplierOperationError === '') {
            $supplierQuery = $isSupplierUpdate
                ? 'UPDATE tblsuppliers SET SupplierName = ?, ContactName = ?, Phone = ?, Email = ?, Address = ? WHERE SupplierID = ?'
                : 'INSERT INTO tblsuppliers (SupplierName, ContactName, Phone, Email, Address) VALUES (?, ?, ?, ?, ?)';
            $supplierStatement = mysqli_prepare($conn, $supplierQuery);
            $supplierSaved = false;

            if ($supplierStatement === false) {
                error_log('Unable to prepare supplier save query: ' . mysqli_error($conn));
                $supplierOperationError = 'Unable to save this supplier right now. Please try again later.';
            } else {
                if ($isSupplierUpdate) {
                    mysqli_stmt_bind_param(
                        $supplierStatement,
                        'sssssi',
                        $supplierName,
                        $supplierContact,
                        $supplierPhone,
                        $supplierEmail,
                        $supplierAddress,
                        $supplierId
                    );
                } else {
                    mysqli_stmt_bind_param(
                        $supplierStatement,
                        'sssss',
                        $supplierName,
                        $supplierContact,
                        $supplierPhone,
                        $supplierEmail,
                        $supplierAddress
                    );
                }

                if (!mysqli_stmt_execute($supplierStatement)) {
                    error_log('Unable to save supplier: ' . mysqli_stmt_error($supplierStatement));
                    $supplierOperationError = 'Unable to save this supplier right now. Please try again.';
                } else {
                    if (!$isSupplierUpdate) {
                        $supplierId = (int) mysqli_insert_id($conn);
                    }
                    $supplierSaved = replaceSupplierInventoryItems($conn, $supplierId, $supplierItemIds);
                    if (!$supplierSaved) {
                        $supplierOperationError = 'Unable to save the supplier inventory items. No changes were saved.';
                    }
                }
                mysqli_stmt_close($supplierStatement);
            }

            if ($supplierSaved && !mysqli_commit($conn)) {
                error_log('Unable to commit supplier transaction: ' . mysqli_error($conn));
                $supplierSaved = false;
                $supplierOperationError = 'Unable to save this supplier right now. Please try again.';
            }
            if (!$supplierSaved) {
                mysqli_rollback($conn);
            } else {
                recordActivity(
                    $conn,
                    'supplier',
                    ($isSupplierUpdate ? 'Updated supplier "' : 'Added supplier "') . $supplierName . '" (#' . $supplierId . ').',
                    'supplier',
                    $supplierId
                );
                $_SESSION['supplier_notice'] = $isSupplierUpdate
                    ? 'Supplier updated successfully.'
                    : 'Supplier added successfully.';
                header('Location: index.php?tab=suppliers');
                exit;
            }
        }

        if ($isSupplierUpdate) {
            $supplierUpdateError = $supplierOperationError;
        } else {
            $supplierCreateError = $supplierOperationError;
        }
    } elseif ($postedAction === 'delete_supplier') {
        $supplierId = isset($_POST['supplier_id']) && is_string($_POST['supplier_id']) && ctype_digit($_POST['supplier_id'])
            ? (int) $_POST['supplier_id']
            : 0;

        if ($supplierId < 1) {
            $supplierDeleteError = 'Select a valid supplier to delete.';
        } else {
            $statement = mysqli_prepare($conn, 'DELETE FROM tblsuppliers WHERE SupplierID = ?');
            if ($statement === false) {
                error_log('Unable to prepare supplier deletion: ' . mysqli_error($conn));
                $supplierDeleteError = 'Unable to delete this supplier right now. Please try again later.';
            } else {
                mysqli_stmt_bind_param($statement, 'i', $supplierId);
                if (mysqli_stmt_execute($statement) && mysqli_stmt_affected_rows($statement) === 1) {
                    recordActivity($conn, 'supplier', 'Deleted supplier #' . $supplierId . '.', 'supplier', $supplierId);
                    $_SESSION['supplier_notice'] = 'Supplier deleted successfully.';
                    mysqli_stmt_close($statement);
                    header('Location: index.php?tab=suppliers');
                    exit;
                }
                error_log('Unable to delete supplier: ' . mysqli_stmt_error($statement));
                $supplierDeleteError = 'Unable to delete this supplier. It may no longer exist.';
                mysqli_stmt_close($statement);
            }
        }
    }

    if ($userCreateToken === '') {
        $userCreateToken = bin2hex(random_bytes(32));
        $_SESSION['user_create_token'] = $userCreateToken;
    }

    $usersResult = mysqli_query(
        $conn,
        'SELECT UserID, Firstname, Lastname, Username, Email, Role, `Access`
         FROM tblusers
         ORDER BY UserID'
    );

    if ($usersResult === false) {
        error_log('Unable to load users: ' . mysqli_error($conn));
        $usersLoadError = 'Unable to load users right now. Please try again later.';
    } else {
        while ($user = mysqli_fetch_assoc($usersResult)) {
            $user['IsCustomer'] = false;
            $users[] = $user;
        }
        mysqli_free_result($usersResult);
    }

    if ($usersLoadError === '') {
        $customersResult = mysqli_query(
            $conn,
            'SELECT CustomerID, FirstName, LastName, Email, `Access`
             FROM tblcustomers
             ORDER BY CustomerID'
        );
        if ($customersResult === false) {
            error_log('Unable to load customer accounts in users tab: ' . mysqli_error($conn));
            $usersLoadError = 'Unable to load customer accounts right now. Please try again later.';
        } else {
            while ($customer = mysqli_fetch_assoc($customersResult)) {
                $users[] = [
                    'CustomerID' => (int) $customer['CustomerID'],
                    'UserID' => 'C-' . (string) $customer['CustomerID'],
                    'Firstname' => $customer['FirstName'],
                    'Lastname' => $customer['LastName'],
                    'Username' => $customer['Email'],
                    'Role' => 'Customer',
                    'Access' => $customer['Access'],
                    'IsCustomer' => true,
                ];
            }
            mysqli_free_result($customersResult);
        }
    }

    $inventoryResult = mysqli_query(
        $conn,
        'SELECT ItemID, ItemName, Category, Color, Price, Stock
         FROM tblinventory
         ORDER BY ItemID DESC'
    );

    if ($inventoryResult === false) {
        error_log('Unable to load inventory: ' . mysqli_error($conn));
        $inventoryLoadError = 'Unable to load inventory right now. Please try again later.';
    } else {
        while ($item = mysqli_fetch_assoc($inventoryResult)) {
            $inventoryItems[] = $item;
            $inventoryColorsById[(int) $item['ItemID']] = trim((string) $item['Color']);
        }
        mysqli_free_result($inventoryResult);
    }

    if ($isAdmin) {
        $suppliersResult = mysqli_query(
            $conn,
            'SELECT s.SupplierID, s.SupplierName, s.ContactName, s.Phone, s.Email, s.Address,
                    si.ItemID, i.ItemName, i.Category
             FROM tblsuppliers s
             LEFT JOIN tblsupplieritems si ON si.SupplierID = s.SupplierID
             LEFT JOIN tblinventory i ON i.ItemID = si.ItemID
             ORDER BY s.SupplierName, i.ItemName'
        );
        if ($suppliersResult === false) {
            error_log('Unable to load suppliers: ' . mysqli_error($conn));
            $supplierLoadError = 'Unable to load suppliers right now. Please check the supplier database migration.';
        } else {
            while ($supplier = mysqli_fetch_assoc($suppliersResult)) {
                $supplierId = (int) $supplier['SupplierID'];
                if (!isset($suppliers[$supplierId])) {
                    $suppliers[$supplierId] = [
                        'SupplierID' => $supplierId,
                        'SupplierName' => (string) $supplier['SupplierName'],
                        'ContactName' => (string) $supplier['ContactName'],
                        'Phone' => (string) $supplier['Phone'],
                        'Email' => (string) $supplier['Email'],
                        'Address' => (string) $supplier['Address'],
                        'Items' => [],
                    ];
                }
                if ($supplier['ItemID'] !== null) {
                    $supplierItem = [
                        'ItemID' => (int) $supplier['ItemID'],
                        'ItemName' => (string) $supplier['ItemName'],
                        'Category' => (string) $supplier['Category'],
                    ];
                    $suppliers[$supplierId]['Items'][] = $supplierItem;
                    $suppliersByInventoryId[$supplierItem['ItemID']][] = [
                        'SupplierID' => $supplierId,
                        'SupplierName' => (string) $supplier['SupplierName'],
                        'ContactName' => (string) $supplier['ContactName'],
                        'Phone' => (string) $supplier['Phone'],
                        'Email' => (string) $supplier['Email'],
                    ];
                }
            }
            mysqli_free_result($suppliersResult);
        }

        $supplierTransactionsResult = mysqli_query(
            $conn,
            'SELECT TransactionID, SupplierName, ItemName, Quantity, StockBefore, StockAfter, ReceivedAt
             FROM tblsupplierstocktransactions
             ORDER BY ReceivedAt DESC, TransactionID DESC'
        );
        if ($supplierTransactionsResult === false) {
            error_log('Unable to load supplier stock transactions: ' . mysqli_error($conn));
            $supplierTransactionsLoadError = 'Unable to load supplier transactions right now. Please try again later.';
        } else {
            while ($supplierTransaction = mysqli_fetch_assoc($supplierTransactionsResult)) {
                $supplierStockTransactions[] = $supplierTransaction;
            }
            mysqli_free_result($supplierTransactionsResult);
        }
    }

    $productsResult = mysqli_query(
        $conn,
        "SELECT p.ProductID, p.ProductName, p.Description,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(i.ItemName, ' x ', pc.Quantity) ORDER BY i.ItemName SEPARATOR ', ')
                    FROM tblproductcomponents pc
                    INNER JOIN tblinventory i ON i.ItemID = pc.ItemID
                    WHERE pc.ProductID = p.ProductID AND i.Category = 'Flower'
                ), p.Flower) AS Flower,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(i.ItemName, ' x ', pc.Quantity) ORDER BY i.ItemName SEPARATOR ', ')
                    FROM tblproductcomponents pc
                    INNER JOIN tblinventory i ON i.ItemID = pc.ItemID
                    WHERE pc.ProductID = p.ProductID AND i.Category = 'Wrapper'
                ), p.Wrapper) AS Wrapper,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(i.ItemName, ' x ', pc.Quantity) ORDER BY i.ItemName SEPARATOR ', ')
                    FROM tblproductcomponents pc
                    INNER JOIN tblinventory i ON i.ItemID = pc.ItemID
                    WHERE pc.ProductID = p.ProductID AND i.Category = 'Container'
                ), '') AS Container,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(i.ItemName, ' x ', pc.Quantity) ORDER BY i.ItemName SEPARATOR ', ')
                    FROM tblproductcomponents pc
                    INNER JOIN tblinventory i ON i.ItemID = pc.ItemID
                    WHERE pc.ProductID = p.ProductID AND i.Category = 'Ribbon'
                ), p.Ribbon) AS Ribbon,
                COALESCE((
                    SELECT GROUP_CONCAT(CONCAT(i.ItemName, ' x ', pc.Quantity) ORDER BY i.ItemName SEPARATOR ', ')
                    FROM tblproductcomponents pc
                    INNER JOIN tblinventory i ON i.ItemID = pc.ItemID
                    WHERE pc.ProductID = p.ProductID AND i.Category = 'Addons'
                ), p.Addons) AS Addons,
                p.Price,
                p.ProductDiscountPercent,
                p.ProductCategories,
                p.ProductStock,
                p.Featured
         FROM tblproducts p
         ORDER BY p.ProductID DESC"
    );

    if ($productsResult === false) {
        error_log('Unable to load products: ' . mysqli_error($conn));
        $productsLoadError = 'Unable to load products right now. Please try again later.';
    } else {
        while ($product = mysqli_fetch_assoc($productsResult)) {
            $decodedCategories = json_decode((string) $product['ProductCategories'], true);
            $product['ProductCategories'] = is_array($decodedCategories)
                ? array_values(array_filter(
                    $decodedCategories,
                    static fn ($category): bool => is_string($category) && in_array($category, $productCategoryOptions, true)
                ))
                : [];
            $product['SalePrice'] = round(
                (float) $product['Price'] * (1 - (float) $product['ProductDiscountPercent'] / 100),
                2
            );
            $products[] = $product;
        }
        mysqli_free_result($productsResult);

        $productComponentsResult = mysqli_query(
            $conn,
            'SELECT ProductID, ItemID, Quantity FROM tblproductcomponents ORDER BY ProductID, ItemID'
        );
        if ($productComponentsResult === false) {
            error_log('Unable to load product components for editing: ' . mysqli_error($conn));
            $productsLoadError = 'Unable to load product components right now. Please try again later.';
        } else {
            while ($component = mysqli_fetch_assoc($productComponentsResult)) {
                $productComponentsById[(int) $component['ProductID']][] = [
                    'item_id' => (int) $component['ItemID'],
                    'quantity' => (int) $component['Quantity'],
                ];
            }
            mysqli_free_result($productComponentsResult);
            foreach ($productComponentsById as $productId => $components) {
                $productColors = [];
                foreach ($components as $component) {
                    $color = $inventoryColorsById[$component['item_id']] ?? '';
                    if ($color !== '' && !in_array($color, $productColors, true)) {
                        $productColors[] = $color;
                    }
                }
                $productColorsById[$productId] = $productColors;
            }
        }
    }

    $deliveryOrdersResult = mysqli_query(
        $conn,
        "SELECT SaleID, SaleDate, RecipientName, RecipientPhone, DeliveryDate, DeliveryTime,
                DeliveryAddress, DeliveryBarangay, DeliveryCity, DeliveryProvince,
                DeliveryPostalCode, DeliveryInstructions, DeliveryFailureRemarks,
                TotalAmount, PaymentMethod, AmountPaid, ChangeAmount, Status
         FROM tblsales
         WHERE OrderType = 'delivery'
         ORDER BY SaleDate DESC, SaleID DESC"
    );
    if ($deliveryOrdersResult === false) {
        error_log('Unable to load delivery orders: ' . mysqli_error($conn));
        $deliveryOrdersLoadError = 'Unable to load delivery orders right now. Please try again later.';
    } else {
        while ($deliveryOrder = mysqli_fetch_assoc($deliveryOrdersResult)) {
            $deliveryOrder['items'] = [];
            $deliveryOrders[(int) $deliveryOrder['SaleID']] = $deliveryOrder;
        }
        mysqli_free_result($deliveryOrdersResult);

        if ($deliveryOrders !== []) {
            $deliveryOrderItemsResult = mysqli_query(
                $conn,
                "SELECT sd.SaleID, sd.ProductName, sd.Quantity, sd.UnitPrice, sd.LineTotal
                 FROM tblsaledetails sd
                 INNER JOIN tblsales s ON s.SaleID = sd.SaleID
                 WHERE s.OrderType = 'delivery'
                 ORDER BY sd.SaleID DESC, sd.SaleDetailID"
            );
            if ($deliveryOrderItemsResult === false) {
                error_log('Unable to load delivery order items: ' . mysqli_error($conn));
                $deliveryOrdersLoadError = 'Unable to load delivery order items right now. Please try again later.';
            } else {
                while ($deliveryOrderItem = mysqli_fetch_assoc($deliveryOrderItemsResult)) {
                    $saleId = (int) $deliveryOrderItem['SaleID'];
                    if (isset($deliveryOrders[$saleId])) {
                        $deliveryOrders[$saleId]['items'][] = $deliveryOrderItem;
                    }
                }
                mysqli_free_result($deliveryOrderItemsResult);
            }
        }
    }

    if ($isAdmin) {
        $pendingCustomerOrdersResult = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS PendingCount
             FROM tblcustomerorders
             WHERE Status IN ('Pending review', 'Pending')"
        );
        if ($pendingCustomerOrdersResult === false) {
            error_log('Unable to count customer orders awaiting review: ' . mysqli_error($conn));
            $customerOrdersLoadError = 'Unable to load customer orders right now. Please try again later.';
        } else {
            $pendingCustomerOrderCount = (int) (mysqli_fetch_assoc($pendingCustomerOrdersResult)['PendingCount'] ?? 0);
            mysqli_free_result($pendingCustomerOrdersResult);
        }
        $receivedCustomerOrdersResult = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS ReceivedCount FROM tblcustomerorders WHERE Status = 'Received'"
        );
        if ($receivedCustomerOrdersResult === false) {
            error_log('Unable to count customer-confirmed orders: ' . mysqli_error($conn));
            $customerOrdersLoadError = 'Unable to load customer order confirmations right now. Please try again later.';
        } else {
            $receivedCustomerOrderCount = (int) (mysqli_fetch_assoc($receivedCustomerOrdersResult)['ReceivedCount'] ?? 0);
            mysqli_free_result($receivedCustomerOrdersResult);
        }
    }

    $customerOrdersResult = mysqli_query(
        $conn,
        "SELECT recent.OrderID, recent.OrderDate, recent.FulfillmentType, recent.FulfillmentDate,
                recent.FulfillmentTime, recent.RecipientName, recent.RecipientPhone,
                recent.DeliveryAddress, recent.DeliveryBarangay, recent.DeliveryCity,
                recent.DeliveryProvince, recent.DeliveryPostalCode, recent.DeliveryInstructions,
                recent.PaymentMethod, recent.PaymentStatus, recent.Status, recent.TotalAmount,
                customers.FirstName, customers.LastName, customers.Email, customers.Phone,
                items.ProductName, items.ItemType, items.CustomComponents, items.Quantity, items.LineTotal
         FROM (
             SELECT OrderID, CustomerID, OrderDate, FulfillmentType, FulfillmentDate,
                    FulfillmentTime, RecipientName, RecipientPhone, DeliveryAddress,
                    DeliveryBarangay, DeliveryCity, DeliveryProvince, DeliveryPostalCode,
                    DeliveryInstructions, PaymentMethod, PaymentStatus, Status, TotalAmount
             FROM tblcustomerorders
             ORDER BY (Status IN ('Pending review', 'Pending')) DESC, OrderDate DESC, OrderID DESC
             LIMIT 100
         ) AS recent
         INNER JOIN tblcustomers AS customers ON customers.CustomerID = recent.CustomerID
         LEFT JOIN tblcustomerorderitems AS items ON items.OrderID = recent.OrderID
         ORDER BY (recent.Status IN ('Pending review', 'Pending')) DESC, recent.OrderDate DESC,
                  recent.OrderID DESC, items.OrderItemID ASC"
    );
    if ($customerOrdersResult === false) {
        error_log('Unable to load admin customer orders: ' . mysqli_error($conn));
        $customerOrdersLoadError = 'Unable to load customer orders right now. Please try again later.';
    } else {
        while ($customerOrder = mysqli_fetch_assoc($customerOrdersResult)) {
            $customerOrderId = (int) $customerOrder['OrderID'];
            if (!isset($customerOrders[$customerOrderId])) {
                $customerOrders[$customerOrderId] = $customerOrder;
                $customerOrders[$customerOrderId]['items'] = [];
            }
            if ($customerOrder['ProductName'] !== null) {
                $components = json_decode((string) $customerOrder['CustomComponents'], true);
                $customerOrders[$customerOrderId]['items'][] = [
                    'name' => (string) $customerOrder['ProductName'],
                    'type' => (string) $customerOrder['ItemType'],
                    'quantity' => (int) $customerOrder['Quantity'],
                    'total' => (string) $customerOrder['LineTotal'],
                    'component_names' => is_array($components) && isset($components['component_names']) && is_array($components['component_names'])
                        ? $components['component_names']
                        : [],
                ];
            }
        }
        mysqli_free_result($customerOrdersResult);
    }
    foreach ($deliveryOrders as $saleId => $deliveryOrder) {
        $customerOrderKey = 'pos-' . $saleId;
        $customerOrders[$customerOrderKey] = [
            'OrderID' => 'POS-' . $saleId,
            'SaleID' => (int) $saleId,
            'IsPosOrder' => true,
            'OrderDate' => (string) $deliveryOrder['SaleDate'],
            'FulfillmentType' => 'delivery',
            'FulfillmentDate' => (string) $deliveryOrder['DeliveryDate'],
            'FulfillmentTime' => (string) $deliveryOrder['DeliveryTime'],
            'RecipientName' => (string) ($deliveryOrder['RecipientName'] ?? ''),
            'RecipientPhone' => (string) ($deliveryOrder['RecipientPhone'] ?? ''),
            'DeliveryAddress' => (string) ($deliveryOrder['DeliveryAddress'] ?? ''),
            'DeliveryBarangay' => (string) ($deliveryOrder['DeliveryBarangay'] ?? ''),
            'DeliveryCity' => (string) ($deliveryOrder['DeliveryCity'] ?? ''),
            'DeliveryProvince' => (string) ($deliveryOrder['DeliveryProvince'] ?? ''),
            'DeliveryPostalCode' => (string) ($deliveryOrder['DeliveryPostalCode'] ?? ''),
            'DeliveryInstructions' => (string) ($deliveryOrder['DeliveryInstructions'] ?? ''),
            'DeliveryFailureRemarks' => (string) ($deliveryOrder['DeliveryFailureRemarks'] ?? ''),
            'PaymentMethod' => (string) $deliveryOrder['PaymentMethod'],
            'PaymentStatus' => in_array((string) $deliveryOrder['Status'], ['Delivered', 'Received'], true)
                ? 'Paid'
                : ($deliveryOrder['PaymentMethod'] === 'cash'
                ? ((float) $deliveryOrder['AmountPaid'] > 0 ? 'Paid' : 'Pending')
                : 'Recorded'),
            'AmountPaid' => (string) $deliveryOrder['AmountPaid'],
            'ChangeAmount' => (string) $deliveryOrder['ChangeAmount'],
            'Status' => (string) $deliveryOrder['Status'],
            'TotalAmount' => (string) $deliveryOrder['TotalAmount'],
            'FirstName' => 'POS checkout',
            'LastName' => '',
            'Email' => '',
            'items' => array_map(
                static fn (array $item): array => [
                    'name' => (string) $item['ProductName'],
                    'quantity' => (int) $item['Quantity'],
                    'total' => (string) $item['LineTotal'],
                    'component_names' => [],
                ],
                $deliveryOrder['items']
            ),
        ];
    }
    uasort(
        $customerOrders,
        static fn (array $left, array $right): int => strcmp((string) $right['OrderDate'], (string) $left['OrderDate'])
    );

    $salesResult = mysqli_query(
        $conn,
        "SELECT s.SaleID, s.SaleDate, s.UserID, s.CustomerOrderID,
            customers.FirstName AS CustomerFirstName, customers.LastName AS CustomerLastName,
            customers.Email AS CustomerEmail, customers.Phone AS CustomerPhone,
            u.Firstname AS CashierFirstName,
                u.Lastname AS CashierLastName, u.Username AS CashierUsername,
                s.Subtotal, s.DiscountAmount, s.TotalAmount, s.PaymentMethod,
                s.AmountPaid, s.ChangeAmount, s.Status, s.OrderType,
                s.RecipientName, s.RecipientPhone
         FROM tblsales s
         LEFT JOIN tblusers u ON u.UserID = s.UserID
         LEFT JOIN tblcustomerorders co ON co.OrderID = s.CustomerOrderID
         LEFT JOIN tblcustomers customers ON customers.CustomerID = co.CustomerID
         WHERE (s.OrderType = 'walk-in' AND s.Status = 'Completed')
            OR (s.OrderType = 'delivery' AND s.Status = 'Delivered')
         ORDER BY s.SaleDate DESC, s.SaleID DESC"
    );
    if ($salesResult === false) {
        error_log('Unable to load sales: ' . mysqli_error($conn));
        $salesLoadError = 'Unable to load sales right now. Please try again later.';
    } else {
        while ($sale = mysqli_fetch_assoc($salesResult)) {
        $sale['items'] = [];
        $salesRecords[(int) $sale['SaleID']] = $sale;
        }
        mysqli_free_result($salesResult);

        if ($salesRecords !== []) {
            $saleItemsResult = mysqli_query(
                $conn,
                "SELECT sd.SaleID, sd.ProductName, sd.CustomComponents, sd.Quantity, sd.UnitPrice, sd.LineTotal
                 FROM tblsaledetails sd
                 INNER JOIN tblsales s ON s.SaleID = sd.SaleID
                 WHERE (s.OrderType = 'walk-in' AND s.Status = 'Completed')
                    OR (s.OrderType = 'delivery' AND s.Status = 'Delivered')
                 ORDER BY sd.SaleID DESC, sd.SaleDetailID"
            );
            if ($saleItemsResult === false) {
                error_log('Unable to load sale items: ' . mysqli_error($conn));
                $salesLoadError = 'Unable to load sale items right now. Please try again later.';
            } else {
                while ($saleItem = mysqli_fetch_assoc($saleItemsResult)) {
                    $saleId = (int) $saleItem['SaleID'];
                    if (isset($salesRecords[$saleId])) {
                        $salesRecords[$saleId]['items'][] = $saleItem;
                    }
                }
                mysqli_free_result($saleItemsResult);
            }
        }
    }

    foreach ($salesRecords as $saleId => $sale) {
        if ((string) $sale['OrderType'] !== 'walk-in' || !empty($sale['CustomerOrderID'])) {
            continue;
        }
        $saleTimestamp = strtotime((string) $sale['SaleDate']);
        $customerOrders['pos-walk-in-' . $saleId] = [
            'OrderID' => 'POS-' . $saleId,
            'SaleID' => (int) $saleId,
            'IsPosOrder' => false,
            'IsPosWalkIn' => true,
            'OrderDate' => (string) $sale['SaleDate'],
            'FulfillmentType' => 'walk-in',
            'FulfillmentDate' => $saleTimestamp === false ? '' : date('Y-m-d', $saleTimestamp),
            'FulfillmentTime' => $saleTimestamp === false ? '' : date('H:i:s', $saleTimestamp),
            'RecipientName' => '',
            'RecipientPhone' => '',
            'DeliveryAddress' => '',
            'DeliveryBarangay' => '',
            'DeliveryCity' => '',
            'DeliveryProvince' => '',
            'DeliveryPostalCode' => '',
            'DeliveryInstructions' => '',
            'DeliveryFailureRemarks' => '',
            'PaymentMethod' => (string) $sale['PaymentMethod'],
            'PaymentStatus' => 'Paid',
            'AmountPaid' => (string) $sale['AmountPaid'],
            'ChangeAmount' => (string) $sale['ChangeAmount'],
            'Status' => (string) $sale['Status'],
            'TotalAmount' => (string) $sale['TotalAmount'],
            'FirstName' => 'POS walk-in',
            'LastName' => '',
            'Email' => '',
            'items' => array_map(
                static fn (array $item): array => [
                    'name' => (string) $item['ProductName'],
                    'quantity' => (int) $item['Quantity'],
                    'total' => (string) $item['LineTotal'],
                    'component_names' => [],
                ],
                $sale['items']
            ),
        ];
    }
    $linkedCustomerOrderIds = [];
    foreach ($salesRecords as $sale) {
        if (!empty($sale['CustomerOrderID'])) {
            $linkedCustomerOrderIds[(int) $sale['CustomerOrderID']] = true;
        }
    }
    foreach ($customerOrders as $customerOrder) {
        if (
            !empty($customerOrder['IsPosOrder'])
            || !empty($customerOrder['IsPosWalkIn'])
            || !in_array((string) $customerOrder['Status'], ['Delivered', 'Completed', 'Received'], true)
        ) {
            continue;
        }
        $customerOrderId = filter_var($customerOrder['OrderID'] ?? null, FILTER_VALIDATE_INT);
        if ($customerOrderId === false || $customerOrderId < 1 || isset($linkedCustomerOrderIds[$customerOrderId])) {
            continue;
        }
        $onlineSaleItems = array_map(
            static fn (array $item): array => [
                'ProductName' => (string) $item['name'],
                'CustomComponents' => null,
                'Quantity' => (int) $item['quantity'],
                'UnitPrice' => (int) $item['quantity'] > 0
                    ? (float) $item['total'] / (int) $item['quantity']
                    : 0.0,
                'LineTotal' => (string) $item['total'],
            ],
            $customerOrder['items']
        );
        $onlineSaleTotal = (float) $customerOrder['TotalAmount'];
        $salesRecords['customer-order-' . $customerOrderId] = [
            'SaleID' => 'SHOP-' . $customerOrderId,
            'SaleDate' => (string) $customerOrder['OrderDate'],
            'UserID' => '',
            'CustomerOrderID' => $customerOrderId,
            'CustomerFirstName' => (string) $customerOrder['FirstName'],
            'CustomerLastName' => (string) $customerOrder['LastName'],
            'CustomerEmail' => (string) $customerOrder['Email'],
            'CustomerPhone' => (string) $customerOrder['Phone'],
            'CashierFirstName' => 'Customer Shop',
            'CashierLastName' => '',
            'CashierUsername' => 'Online order',
            'Subtotal' => $onlineSaleTotal,
            'DiscountAmount' => 0.0,
            'TotalAmount' => $onlineSaleTotal,
            'PaymentMethod' => (string) $customerOrder['PaymentMethod'],
            'AmountPaid' => $customerOrder['PaymentStatus'] === 'Paid' ? $onlineSaleTotal : 0.0,
            'ChangeAmount' => 0.0,
            'Status' => (string) $customerOrder['Status'],
            'OrderType' => (string) $customerOrder['FulfillmentType'],
            'RecipientName' => (string) $customerOrder['RecipientName'],
            'RecipientPhone' => (string) $customerOrder['RecipientPhone'],
            'items' => $onlineSaleItems,
        ];
    }
    uasort(
        $customerOrders,
        static fn (array $left, array $right): int => strcmp((string) $right['OrderDate'], (string) $left['OrderDate'])
    );

    if ($isAdmin) {
        $dashboardMetricsResult = mysqli_query(
            $conn,
            "SELECT
                (SELECT COUNT(*) FROM tblsales
                 WHERE DATE(SaleDate) = CURDATE()
                   AND ((OrderType = 'walk-in' AND Status = 'Completed')
                     OR (OrderType = 'delivery' AND Status = 'Delivered'))) AS today_sales,
                (SELECT COALESCE(SUM(TotalAmount), 0) FROM tblsales
                 WHERE DATE(SaleDate) = CURDATE()
                   AND ((OrderType = 'walk-in' AND Status = 'Completed')
                     OR (OrderType = 'delivery' AND Status = 'Delivered'))) AS today_revenue,
                (SELECT COUNT(*) FROM tblsales
                 WHERE OrderType = 'delivery' AND Status = 'Pending') AS pending_deliveries,
                (SELECT COUNT(*) FROM tblproducts) AS product_count,
                (SELECT COUNT(*) FROM tblinventory) AS inventory_count,
                (SELECT COUNT(*) FROM tblinventory WHERE Stock <= 5) AS low_stock_count"
        );
        if ($dashboardMetricsResult === false) {
            error_log('Unable to load admin dashboard metrics: ' . mysqli_error($conn));
            $dashboardLoadError = 'Unable to load dashboard metrics right now.';
        } else {
            $metricsRow = mysqli_fetch_assoc($dashboardMetricsResult);
            if ($metricsRow !== null) {
                $dashboardMetrics = [
                    'today_sales' => (int) $metricsRow['today_sales'],
                    'today_revenue' => (float) $metricsRow['today_revenue'],
                    'pending_deliveries' => (int) $metricsRow['pending_deliveries'],
                    'product_count' => (int) $metricsRow['product_count'],
                    'inventory_count' => (int) $metricsRow['inventory_count'],
                    'low_stock_count' => (int) $metricsRow['low_stock_count'],
                ];
            }
            mysqli_free_result($dashboardMetricsResult);
        }

        $dashboardLowStockResult = mysqli_query(
            $conn,
            'SELECT ItemID, ItemName, Category, Stock
             FROM tblinventory
             WHERE Stock <= 5
             ORDER BY Stock ASC, ItemName ASC
             LIMIT 8'
        );
        if ($dashboardLowStockResult === false) {
            error_log('Unable to load low-stock dashboard items: ' . mysqli_error($conn));
            $dashboardLoadError = 'Unable to load low-stock inventory right now.';
        } else {
            while ($lowStockItem = mysqli_fetch_assoc($dashboardLowStockResult)) {
                $dashboardLowStockItems[] = $lowStockItem;
            }
            mysqli_free_result($dashboardLowStockResult);
        }

        $dashboardRecentSalesResult = mysqli_query(
            $conn,
            "SELECT s.SaleID, s.SaleDate, s.TotalAmount, s.OrderType, s.PaymentMethod,
                    u.Firstname, u.Lastname, u.Username
             FROM tblsales s
             LEFT JOIN tblusers u ON u.UserID = s.UserID
             WHERE (s.OrderType = 'walk-in' AND s.Status = 'Completed')
                OR (s.OrderType = 'delivery' AND s.Status = 'Delivered')
             ORDER BY s.SaleDate DESC, s.SaleID DESC
             LIMIT 5"
        );
        if ($dashboardRecentSalesResult === false) {
            error_log('Unable to load recent admin dashboard sales: ' . mysqli_error($conn));
            $dashboardLoadError = 'Unable to load recent sales right now.';
        } else {
            while ($recentSale = mysqli_fetch_assoc($dashboardRecentSalesResult)) {
                $dashboardRecentSales[] = $recentSale;
            }
            mysqli_free_result($dashboardRecentSalesResult);
        }

        $dashboardActivityResult = mysqli_query(
            $conn,
            'SELECT ActivityID, ActorName, EventType, Description, EntityType, EntityID, CreatedAt
             FROM tblactivitylog
             ORDER BY CreatedAt DESC, ActivityID DESC
             LIMIT 20'
        );
        if ($dashboardActivityResult === false) {
            error_log('Unable to load admin dashboard activity: ' . mysqli_error($conn));
            $dashboardLoadError = 'Unable to load activity history right now.';
        } else {
            while ($activity = mysqli_fetch_assoc($dashboardActivityResult)) {
                $dashboardActivity[] = $activity;
            }
            mysqli_free_result($dashboardActivityResult);
        }
    } elseif ($userRole === 'Staff') {
        $staffUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : 0;
        if ($staffUserId < 1) {
            error_log('Unable to load staff dashboard: the session has no valid user ID.');
            $staffDashboardLoadError = 'Unable to load your dashboard right now.';
        } else {
            $staffDashboardMetricsStatement = mysqli_prepare(
                $conn,
                "SELECT
                    (SELECT COUNT(*) FROM tblsales
                     WHERE UserID = ? AND DATE(SaleDate) = CURDATE()
                       AND ((OrderType = 'walk-in' AND Status = 'Completed')
                         OR (OrderType = 'delivery' AND Status = 'Delivered'))) AS today_sales,
                    (SELECT COALESCE(SUM(TotalAmount), 0) FROM tblsales
                     WHERE UserID = ? AND DATE(SaleDate) = CURDATE()
                       AND ((OrderType = 'walk-in' AND Status = 'Completed')
                         OR (OrderType = 'delivery' AND Status = 'Delivered'))) AS today_revenue,
                    (SELECT COUNT(*) FROM tblsales
                     WHERE OrderType = 'delivery' AND Status = 'Pending') AS pending_deliveries"
            );
            if ($staffDashboardMetricsStatement === false) {
                error_log('Unable to prepare staff dashboard metrics: ' . mysqli_error($conn));
                $staffDashboardLoadError = 'Unable to load your dashboard right now.';
            } else {
                mysqli_stmt_bind_param($staffDashboardMetricsStatement, 'ii', $staffUserId, $staffUserId);
                if (!mysqli_stmt_execute($staffDashboardMetricsStatement)) {
                    error_log('Unable to load staff dashboard metrics: ' . mysqli_stmt_error($staffDashboardMetricsStatement));
                    $staffDashboardLoadError = 'Unable to load your dashboard right now.';
                } else {
                    mysqli_stmt_bind_result(
                        $staffDashboardMetricsStatement,
                        $staffTodaySales,
                        $staffTodayRevenue,
                        $staffPendingDeliveries
                    );
                    if (mysqli_stmt_fetch($staffDashboardMetricsStatement)) {
                        $staffDashboardMetrics = [
                            'today_sales' => (int) $staffTodaySales,
                            'today_revenue' => (float) $staffTodayRevenue,
                            'pending_deliveries' => (int) $staffPendingDeliveries,
                        ];
                    }
                }
                mysqli_stmt_close($staffDashboardMetricsStatement);
            }

            $staffDashboardRecentSalesStatement = mysqli_prepare(
                $conn,
                "SELECT SaleID, SaleDate, TotalAmount, OrderType
                 FROM tblsales
                 WHERE UserID = ?
                   AND ((OrderType = 'walk-in' AND Status = 'Completed')
                     OR (OrderType = 'delivery' AND Status = 'Delivered'))
                 ORDER BY SaleDate DESC, SaleID DESC
                 LIMIT 5"
            );
            if ($staffDashboardRecentSalesStatement === false) {
                error_log('Unable to prepare recent staff sales query: ' . mysqli_error($conn));
                $staffDashboardLoadError = 'Unable to load your recent sales right now.';
            } else {
                mysqli_stmt_bind_param($staffDashboardRecentSalesStatement, 'i', $staffUserId);
                if (!mysqli_stmt_execute($staffDashboardRecentSalesStatement)) {
                    error_log('Unable to load recent staff sales: ' . mysqli_stmt_error($staffDashboardRecentSalesStatement));
                    $staffDashboardLoadError = 'Unable to load your recent sales right now.';
                } else {
                    mysqli_stmt_bind_result(
                        $staffDashboardRecentSalesStatement,
                        $staffSaleId,
                        $staffSaleDate,
                        $staffSaleTotal,
                        $staffSaleOrderType
                    );
                    while (mysqli_stmt_fetch($staffDashboardRecentSalesStatement)) {
                        $staffDashboardRecentSales[] = [
                            'SaleID' => $staffSaleId,
                            'SaleDate' => $staffSaleDate,
                            'TotalAmount' => $staffSaleTotal,
                            'OrderType' => $staffSaleOrderType,
                        ];
                    }
                }
                mysqli_stmt_close($staffDashboardRecentSalesStatement);
            }
        }
    }
}

function getInventoryImageUrl(int $itemId): ?string
{
    foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
        $relativePath = 'uploads/inventory/' . $itemId . '.' . $extension;
        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
            return $relativePath;
        }
    }

    return null;
}

function getProductImageUrl(int $productId): ?string
{
    foreach (['jpg', 'png', 'gif', 'webp'] as $extension) {
        $relativePath = 'uploads/products/' . $productId . '.' . $extension;
        if (is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
            return $relativePath;
        }
    }

    return null;
}

function supplierInventoryItemsExist(mysqli $connection, array $itemIds): bool
{
    if ($itemIds === []) {
        return true;
    }

    $statement = mysqli_prepare($connection, 'SELECT ItemID FROM tblinventory WHERE ItemID = ?');
    if ($statement === false) {
        error_log('Unable to prepare supplier inventory validation: ' . mysqli_error($connection));
        return false;
    }

    $itemId = 0;
    mysqli_stmt_bind_param($statement, 'i', $itemId);
    foreach ($itemIds as $itemId) {
        if (!mysqli_stmt_execute($statement)) {
            error_log('Unable to validate supplier inventory item: ' . mysqli_stmt_error($statement));
            mysqli_stmt_close($statement);
            return false;
        }
        mysqli_stmt_store_result($statement);
        $itemExists = mysqli_stmt_num_rows($statement) === 1;
        mysqli_stmt_free_result($statement);
        if (!$itemExists) {
            mysqli_stmt_close($statement);
            return false;
        }
    }

    mysqli_stmt_close($statement);
    return true;
}

function deductInventoryForProductStock(mysqli $connection, array $components, int $productQuantity, string &$error): bool
{
    if ($productQuantity < 1) {
        $error = 'Unable to verify the quantity of products being prepared.';
        return false;
    }

    $requiredQuantities = [];
    foreach ($components as $component) {
        if (
            !is_array($component)
            || !isset($component['item_id'], $component['quantity'])
            || !is_numeric($component['item_id'])
            || !is_numeric($component['quantity'])
        ) {
            error_log('Invalid product recipe component encountered while preparing stock.');
            $error = 'Unable to verify this product’s inventory recipe.';
            return false;
        }

        $itemId = (int) $component['item_id'];
        $componentQuantity = (int) $component['quantity'];
        if (
            $itemId < 1
            || $componentQuantity < 1
            || $componentQuantity > intdiv(2147483647, $productQuantity)
        ) {
            error_log('Invalid or excessive recipe quantity encountered while preparing product stock.');
            $error = 'The product quantity is too large to deduct from inventory.';
            return false;
        }

        $requiredQuantity = $componentQuantity * $productQuantity;
        $currentQuantity = $requiredQuantities[$itemId] ?? 0;
        if ($currentQuantity > 2147483647 - $requiredQuantity) {
            error_log('Aggregated recipe quantity exceeds the supported inventory range.');
            $error = 'The product quantity is too large to deduct from inventory.';
            return false;
        }
        $requiredQuantities[$itemId] = $currentQuantity + $requiredQuantity;
    }

    if ($requiredQuantities === []) {
        $error = 'This product has no inventory components to prepare.';
        return false;
    }

    $deductStatement = mysqli_prepare(
        $connection,
        'UPDATE tblinventory SET Stock = Stock - ? WHERE ItemID = ? AND Stock >= ?'
    );
    if ($deductStatement === false) {
        error_log('Unable to prepare inventory deduction for product preparation: ' . mysqli_error($connection));
        $error = 'Unable to update inventory for this product. Please try again.';
        return false;
    }

    $requiredQuantity = 0;
    $itemId = 0;
    mysqli_stmt_bind_param($deductStatement, 'iii', $requiredQuantity, $itemId, $requiredQuantity);
    foreach ($requiredQuantities as $itemId => $requiredQuantity) {
        if (!mysqli_stmt_execute($deductStatement)) {
            error_log('Unable to deduct inventory while preparing product stock: ' . mysqli_stmt_error($deductStatement));
            mysqli_stmt_close($deductStatement);
            $error = 'Unable to update inventory for this product. Please try again.';
            return false;
        }
        if (mysqli_stmt_affected_rows($deductStatement) !== 1) {
            mysqli_stmt_close($deductStatement);
            $nameStatement = mysqli_prepare($connection, 'SELECT ItemName FROM tblinventory WHERE ItemID = ?');
            if ($nameStatement === false) {
                error_log('Unable to prepare inventory name lookup after product stock deduction failed: ' . mysqli_error($connection));
                $error = 'There is not enough inventory to prepare this product quantity.';
                return false;
            }
            mysqli_stmt_bind_param($nameStatement, 'i', $itemId);
            if (!mysqli_stmt_execute($nameStatement)) {
                error_log('Unable to read inventory item after product stock deduction failed: ' . mysqli_stmt_error($nameStatement));
                mysqli_stmt_close($nameStatement);
                $error = 'There is not enough inventory to prepare this product quantity.';
                return false;
            }
            mysqli_stmt_bind_result($nameStatement, $itemName);
            $itemExists = mysqli_stmt_fetch($nameStatement);
            mysqli_stmt_close($nameStatement);
            $error = $itemExists
                ? 'Not enough inventory for "' . (string) $itemName . '" to prepare ' . $productQuantity . ' product(s).'
                : 'An inventory item for this product is no longer available.';
            return false;
        }
    }

    mysqli_stmt_close($deductStatement);
    return true;
}

function deductProductStockForSale(mysqli $connection, array &$saleProducts, string &$error): bool
{
    $productQuantities = [];
    foreach ($saleProducts as $saleProduct) {
        $productId = (int) ($saleProduct['product_id'] ?? 0);
        $quantity = (int) ($saleProduct['quantity'] ?? 0);
        if ($productId === 0) {
            continue;
        }
        if ($productId < 1 || $quantity < 1 || $quantity > 2147483647) {
            error_log('Invalid product quantity encountered during product stock deduction.');
            $error = 'Unable to verify product stock quantities for this order.';
            return false;
        }
        $currentQuantity = $productQuantities[$productId] ?? 0;
        if ($currentQuantity > 2147483647 - $quantity) {
            error_log('Product quantity exceeds the supported stock range during sale deduction.');
            $error = 'Unable to verify product stock quantities for this order.';
            return false;
        }
        $productQuantities[$productId] = $currentQuantity + $quantity;
    }

    if ($productQuantities === []) {
        return true;
    }

    $stockLookup = mysqli_prepare(
        $connection,
        'SELECT ProductName, ProductStock FROM tblproducts WHERE ProductID = ? FOR UPDATE'
    );
    $stockDeduction = mysqli_prepare(
        $connection,
        'UPDATE tblproducts SET ProductStock = ProductStock - ? WHERE ProductID = ? AND ProductStock >= ?'
    );
    if ($stockLookup === false || $stockDeduction === false) {
        error_log('Unable to prepare product stock deduction: ' . mysqli_error($connection));
        if ($stockLookup instanceof mysqli_stmt) {
            mysqli_stmt_close($stockLookup);
        }
        if ($stockDeduction instanceof mysqli_stmt) {
            mysqli_stmt_close($stockDeduction);
        }
        $error = 'Unable to update product stock for this order. Please try again.';
        return false;
    }

    $productId = 0;
    $productName = '';
    $availableStock = null;
    $trackedProducts = [];
    mysqli_stmt_bind_param($stockLookup, 'i', $productId);
    mysqli_stmt_bind_result($stockLookup, $productName, $availableStock);
    foreach ($productQuantities as $productId => $quantity) {
        if (!mysqli_stmt_execute($stockLookup)) {
            error_log('Unable to read product stock: ' . mysqli_stmt_error($stockLookup));
            mysqli_stmt_close($stockLookup);
            mysqli_stmt_close($stockDeduction);
            $error = 'Unable to verify product stock for this order. Please try again.';
            return false;
        }
        if (!mysqli_stmt_fetch($stockLookup)) {
            mysqli_stmt_free_result($stockLookup);
            mysqli_stmt_close($stockLookup);
            mysqli_stmt_close($stockDeduction);
            $error = 'A product in this order is no longer available.';
            return false;
        }
        mysqli_stmt_free_result($stockLookup);
        $trackedProducts[$productId] = $availableStock !== null;

        if ($availableStock === null) {
            continue;
        }
        if ((int) $availableStock < $quantity) {
            mysqli_stmt_close($stockLookup);
            mysqli_stmt_close($stockDeduction);
            $error = 'Not enough product stock for "' . (string) $productName . '" (available: ' . (int) $availableStock . ', requested: ' . $quantity . ').';
            return false;
        }

        mysqli_stmt_bind_param($stockDeduction, 'iii', $quantity, $productId, $quantity);
        if (!mysqli_stmt_execute($stockDeduction)) {
            error_log('Unable to deduct product stock for product ' . $productId . ': ' . mysqli_stmt_error($stockDeduction));
            mysqli_stmt_close($stockLookup);
            mysqli_stmt_close($stockDeduction);
            $error = 'Unable to update product stock for this order. Please try again.';
            return false;
        }
        if (mysqli_stmt_affected_rows($stockDeduction) !== 1) {
            mysqli_stmt_close($stockLookup);
            mysqli_stmt_close($stockDeduction);
            $error = 'Not enough product stock for "' . (string) $productName . '" to complete this order.';
            return false;
        }
    }

    foreach ($saleProducts as &$saleProduct) {
        $productId = (int) ($saleProduct['product_id'] ?? 0);
        if ($productId > 0) {
            $saleProduct['stock_tracked'] = $trackedProducts[$productId] ?? false;
        }
    }
    unset($saleProduct);

    mysqli_stmt_close($stockLookup);
    mysqli_stmt_close($stockDeduction);
    return true;
}

function deductInventoryForSale(mysqli $connection, array $saleProducts, string &$error): bool
{
    $requiredQuantities = [];
    $productComponentStatement = mysqli_prepare(
        $connection,
        'SELECT ItemID, Quantity FROM tblproductcomponents WHERE ProductID = ?'
    );
    if ($productComponentStatement === false) {
        error_log('Unable to prepare sale inventory recipe lookup: ' . mysqli_error($connection));
        $error = 'Unable to verify inventory for this order. Please try again.';
        return false;
    }

    $productId = 0;
    mysqli_stmt_bind_param($productComponentStatement, 'i', $productId);
    foreach ($saleProducts as $saleProduct) {
        $componentRows = [];
        if (is_string($saleProduct['custom_components'] ?? null)) {
            $customData = json_decode($saleProduct['custom_components'], true);
            if (!is_array($customData) || !isset($customData['components']) || !is_array($customData['components'])) {
                error_log('Invalid saved custom components encountered during inventory deduction.');
                mysqli_stmt_close($productComponentStatement);
                $error = 'Unable to verify inventory for a customized order item.';
                return false;
            }
            $componentRows = $customData['components'];
        } else {
            if (($saleProduct['stock_tracked'] ?? false) === true) {
                continue;
            }
            $productId = (int) ($saleProduct['product_id'] ?? 0);
            if ($productId < 1 || !mysqli_stmt_execute($productComponentStatement)) {
                error_log('Unable to load sale product recipe: ' . mysqli_stmt_error($productComponentStatement));
                mysqli_stmt_close($productComponentStatement);
                $error = 'Unable to verify inventory for this product. Please try again.';
                return false;
            }
            mysqli_stmt_bind_result($productComponentStatement, $componentItemId, $componentQuantity);
            while (mysqli_stmt_fetch($productComponentStatement)) {
                $componentRows[] = [
                    'item_id' => (int) $componentItemId,
                    'quantity' => (int) $componentQuantity,
                ];
            }
            mysqli_stmt_free_result($productComponentStatement);
            if ($componentRows === []) {
                mysqli_stmt_close($productComponentStatement);
                $error = 'A product in this order has no inventory recipe. Update its components before selling it.';
                return false;
            }
        }

        foreach ($componentRows as $component) {
            if (
                !is_array($component)
                || !isset($component['item_id'], $component['quantity'])
                || !is_numeric($component['item_id'])
                || !is_numeric($component['quantity'])
            ) {
                error_log('Invalid inventory component encountered during sale deduction.');
                mysqli_stmt_close($productComponentStatement);
                $error = 'Unable to verify inventory for this order. Please try again.';
                return false;
            }
            $itemId = (int) $component['item_id'];
            $componentQuantity = (int) $component['quantity'];
            $saleQuantity = (int) ($saleProduct['quantity'] ?? 0);
            if (
                $itemId < 1
                || $componentQuantity < 1
                || $saleQuantity < 1
                || $componentQuantity > intdiv(PHP_INT_MAX, $saleQuantity)
            ) {
                error_log('Invalid or excessive inventory quantity encountered during sale deduction.');
                mysqli_stmt_close($productComponentStatement);
                $error = 'Unable to verify inventory quantities for this order.';
                return false;
            }
            $requiredQuantity = $componentQuantity * $saleQuantity;
            $currentRequiredQuantity = $requiredQuantities[$itemId] ?? 0;
            if (
                $requiredQuantity > 2147483647
                || $currentRequiredQuantity > 2147483647 - $requiredQuantity
            ) {
                error_log('Inventory quantity exceeds the supported stock range during sale deduction.');
                mysqli_stmt_close($productComponentStatement);
                $error = 'Unable to verify inventory quantities for this order.';
                return false;
            }
            $requiredQuantities[$itemId] = $currentRequiredQuantity + $requiredQuantity;
        }
    }
    mysqli_stmt_close($productComponentStatement);

    if ($requiredQuantities === []) {
        return true;
    }

    $deductStatement = mysqli_prepare(
        $connection,
        'UPDATE tblinventory SET Stock = Stock - ? WHERE ItemID = ? AND Stock >= ?'
    );
    if ($deductStatement === false) {
        error_log('Unable to prepare inventory deduction: ' . mysqli_error($connection));
        $error = 'Unable to update inventory for this order. Please try again.';
        return false;
    }

    $requiredQuantity = 0;
    $itemId = 0;
    mysqli_stmt_bind_param($deductStatement, 'iii', $requiredQuantity, $itemId, $requiredQuantity);
    foreach ($requiredQuantities as $itemId => $requiredQuantity) {
        if (!mysqli_stmt_execute($deductStatement)) {
            error_log('Unable to deduct inventory item ' . $itemId . ': ' . mysqli_stmt_error($deductStatement));
            mysqli_stmt_close($deductStatement);
            $error = 'Unable to update inventory for this order. Please try again.';
            return false;
        }
        if (mysqli_stmt_affected_rows($deductStatement) !== 1) {
            mysqli_stmt_close($deductStatement);
            $nameStatement = mysqli_prepare($connection, 'SELECT ItemName FROM tblinventory WHERE ItemID = ?');
            if ($nameStatement === false) {
                error_log('Unable to prepare inventory name lookup after failed deduction: ' . mysqli_error($connection));
                $error = 'There is not enough inventory to complete this order.';
                return false;
            }
            mysqli_stmt_bind_param($nameStatement, 'i', $itemId);
            if (!mysqli_stmt_execute($nameStatement)) {
                error_log('Unable to read inventory item after failed deduction: ' . mysqli_stmt_error($nameStatement));
                mysqli_stmt_close($nameStatement);
                $error = 'There is not enough inventory to complete this order.';
                return false;
            }
            mysqli_stmt_bind_result($nameStatement, $itemName);
            $itemExists = mysqli_stmt_fetch($nameStatement);
            mysqli_stmt_close($nameStatement);
            $error = $itemExists
                ? 'Not enough stock for "' . (string) $itemName . '" to complete this order.'
                : 'An inventory item for this order is no longer available.';
            return false;
        }
    }

    mysqli_stmt_close($deductStatement);
    return true;
}

function replaceSupplierInventoryItems(mysqli $connection, int $supplierId, array $itemIds): bool
{
    $deleteStatement = mysqli_prepare($connection, 'DELETE FROM tblsupplieritems WHERE SupplierID = ?');
    if ($deleteStatement === false) {
        error_log('Unable to prepare supplier inventory update: ' . mysqli_error($connection));
        return false;
    }
    mysqli_stmt_bind_param($deleteStatement, 'i', $supplierId);
    if (!mysqli_stmt_execute($deleteStatement)) {
        error_log('Unable to clear supplier inventory links: ' . mysqli_stmt_error($deleteStatement));
        mysqli_stmt_close($deleteStatement);
        return false;
    }
    mysqli_stmt_close($deleteStatement);

    if ($itemIds === []) {
        return true;
    }

    $insertStatement = mysqli_prepare(
        $connection,
        'INSERT INTO tblsupplieritems (SupplierID, ItemID) VALUES (?, ?)'
    );
    if ($insertStatement === false) {
        error_log('Unable to prepare supplier inventory links: ' . mysqli_error($connection));
        return false;
    }

    $itemId = 0;
    mysqli_stmt_bind_param($insertStatement, 'ii', $supplierId, $itemId);
    foreach ($itemIds as $itemId) {
        if (!mysqli_stmt_execute($insertStatement)) {
            error_log('Unable to save supplier inventory link: ' . mysqli_stmt_error($insertStatement));
            mysqli_stmt_close($insertStatement);
            return false;
        }
    }
    mysqli_stmt_close($insertStatement);
    return true;
}

$showInventory = $canManageCatalog && ($inventoryNotice !== '' || $itemCreateError !== '' || $itemUpdateError !== '' || $itemDeleteError !== '');
$showProducts = $canManageCatalog && ((isset($_GET['tab']) && $_GET['tab'] === 'products')
    || $productNotice !== ''
    || $productCreateError !== '');
$showPosTab = (isset($_GET['tab']) && $_GET['tab'] === 'pos')
    || $saleCreateError !== ''
    || ($saleNotice !== '' && (!isset($_GET['tab']) || !in_array($_GET['tab'], ['orders', 'sales'], true)));
$showSalesTab = $isAdmin && (isset($_GET['tab']) && $_GET['tab'] === 'sales') && !$showPosTab && !$showProducts;
$showOrdersTab = (isset($_GET['tab']) && $_GET['tab'] === 'orders') && !$showPosTab && !$showProducts && !$showSalesTab;
$showSuppliersTab = $isAdmin
    && (($_GET['tab'] ?? '') === 'suppliers' || $supplierNotice !== '' || $supplierCreateError !== '' || $supplierUpdateError !== '' || $supplierDeleteError !== '')
    && !$showPosTab
    && !$showProducts
    && !$showOrdersTab
    && !$showSalesTab
    && !$showInventory;
$showInventoryTab = !$showProducts && !$showPosTab && !$showOrdersTab && !$showSalesTab && !$showSuppliersTab && $showInventory;
$showUsersTab = $isAdmin
    && (($_GET['tab'] ?? '') === 'users' || $userNotice !== '' || $userCreateError !== '' || $userUpdateError !== '' || $userDeleteError !== '')
    && !$showInventory
    && !$showProducts
    && !$showPosTab
    && !$showOrdersTab
    && !$showSalesTab
    && !$showSuppliersTab;
$showDashboardTab = !$showUsersTab && !$showInventoryTab && !$showProducts && !$showPosTab && !$showOrdersTab && !$showSalesTab && !$showSuppliersTab;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flower Shop</title>
    <link rel="stylesheet" href="bootstrap-5.3.8/css/bootstrap.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,500;0,600;1,500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="index.css">
    <?php if ($logoutSuccess): ?>
        <meta http-equiv="refresh" content="1.5;url=LoginForm.php">
    <?php endif; ?>
</head>
<body class="bg-primary bg-opacity-25">
    <main class="container-fluid dashboard-shell py-3">
        <div class="dashboard-frame d-flex mx-auto bg-white rounded-3 shadow-lg overflow-hidden">
            <aside class="dashboard-sidebar d-flex flex-column flex-shrink-0 bg-white border-end">
                <header class="profile-panel d-flex flex-column align-items-center justify-content-center border-bottom">
                    <div class="profile-avatar profile-avatar-initials rounded-circle d-flex align-items-center justify-content-center" aria-label="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($profileInitials, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <h2 class="h5 text-black mt-3 mb-0"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-secondary mb-0"><?= htmlspecialchars((string) $userRole, ENT_QUOTES, 'UTF-8') ?></p>
                </header>

                <div class="sidebar-navigation d-flex flex-column flex-grow-1 p-3">
                    <nav class="nav nav-pills flex-column gap-2" aria-label="Main navigation">
                        <?php if ($isAdmin): ?>
                            <button class="nav-link <?= $showUsersTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showUsersTab ? 'true' : 'false' ?>">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"></circle><path d="M3 20a6 6 0 0 1 12 0M17 8h5M19.5 5.5v5"></path></svg>Users
                            </button>
                        <?php endif; ?>
                        <button class="nav-link <?= $showDashboardTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showDashboardTab ? 'true' : 'false' ?>">
                            <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1Z"></path><path d="M9 21v-6h6v6"></path></svg>Dashboard
                        </button>
                        <button class="nav-link <?= $showPosTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showPosTab ? 'true' : 'false' ?>">
                            <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h2l2.2 10.2a2 2 0 0 0 2 1.6h7.2a2 2 0 0 0 1.9-1.5L21 9H7"></path><circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle></svg>Point of Sale
                        </button>
                        <?php if ($canManageCatalog): ?>
                            <button class="nav-link <?= $showInventoryTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showInventoryTab ? 'true' : 'false' ?>">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="6" width="16" height="15" rx="2"></rect><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M8 11h8M8 15h5"></path></svg>Inventory
                            </button>
                            <button class="nav-link <?= $showProducts ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showProducts ? 'true' : 'false' ?>">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4v10l-8 4-8-4V7Z"></path><path d="m4 7 8 4 8-4M12 11v10"></path></svg>Products
                            </button>
                        <?php endif; ?>
                        <button class="nav-link <?= $showOrdersTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" data-tab-name="Orders" aria-pressed="<?= $showOrdersTab ? 'true' : 'false' ?>">
                            <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4.5h6M9 10h6M9 14h6M9 18h3"></path></svg>Orders
                            <?php if ($isAdmin): ?>
                                <span class="badge rounded-pill text-bg-danger ms-2" id="pending-customer-order-badge" aria-label="<?= $pendingCustomerOrderCount ?> customer orders awaiting review" <?= $pendingCustomerOrderCount > 0 ? '' : 'hidden' ?>><?= $pendingCustomerOrderCount ?></span>
                                <span class="badge rounded-pill text-bg-success ms-1" id="received-customer-order-badge" aria-label="<?= $receivedCustomerOrderCount ?> customer orders confirmed received" <?= $receivedCustomerOrderCount > 0 ? '' : 'hidden' ?>><?= $receivedCustomerOrderCount ?></span>
                            <?php endif; ?>
                        </button>
                        <?php if ($isAdmin): ?>
                            <button class="nav-link <?= $showSalesTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showSalesTab ? 'true' : 'false' ?>">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 18l6-6 4 4 8-10"></path><path d="M18 6h2v2"></path><path d="M3 20h18"></path></svg>Sales
                            </button>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <button class="nav-link <?= $showSuppliersTab ? 'active' : '' ?> text-black text-start py-3 fs-5" type="button" aria-pressed="<?= $showSuppliersTab ? 'true' : 'false' ?>">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="12" rx="2"></rect><path d="m3 7 3-4h12l3 4M8 12h8"></path></svg>Suppliers
                            </button>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <button class="nav-link text-black text-start py-3 fs-5" type="button" aria-pressed="false">
                                <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19 12a7 7 0 0 0-.2-1.6l1.7-1.3-2-3.4-2 .8a7 7 0 0 0-2.8-1.6L13.5 3h-3l-.3 1.9a7 7 0 0 0-2.8 1.6l-2-.8-2 3.4 1.7 1.3a7 7 0 0 0 0 3.2l-1.7 1.3 2 3.4 2-.8a7 7 0 0 0 2.8 1.6l.3 1.9h3l.3-1.9a7 7 0 0 0 2.8-1.6l2 .8 2-3.4-1.7-1.3A7 7 0 0 0 19 12Z"></path></svg>Settings
                            </button>
                        <?php endif; ?>
                    </nav>

                    <?php if (!$logoutSuccess): ?>
                        <button class="nav-link rounded-3 text-black text-start mt-auto py-1 fs-5 border-0 bg-transparent w-100" id="logout-button" type="button">
                            <svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v16H4z"></path><path d="M11 12h9M16 8l4 4-4 4"></path></svg>Logout
                        </button>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="dashboard-content flex-grow-1">
                <?php if ($isAdmin): ?>
                    <div class="alert alert-info position-fixed top-0 end-0 m-3 shadow" id="customer-order-live-notice" role="status" aria-live="polite" style="z-index: 1100;" hidden>
                        New customer orders are waiting for review.
                        <a href="index.php?tab=orders" class="alert-link ms-1">Review orders</a>
                        <button type="button" class="btn-close ms-2" aria-label="Dismiss notification" data-dismiss-customer-order-notice></button>
                    </div>
                <?php endif; ?>
                <header class="dashboard-topbar bg-white px-5 py-2">
                    <h2 class="dashboard-page-title" id="dashboard-page-title"><?= $showSuppliersTab ? 'Suppliers' : ($showProducts ? 'Products' : ($showInventoryTab ? 'Inventory' : ($showPosTab ? 'Point of Sale' : ($showOrdersTab ? 'Orders' : ($showSalesTab ? 'Sales' : ($showUsersTab ? 'Users' : 'Dashboard')))))) ?></h2>
                    <div class="dashboard-search-tools" id="users-search-tools" <?= $showUsersTab ? '' : 'hidden' ?>>
                        <div class="dashboard-search input-group" role="search">
                            <span class="input-group-text bg-white border-end-0" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control border-start-0" id="users-search" type="search" placeholder="Search..." aria-label="Search users">
                        </div>
                        <?php if ($usersLoadError === ''): ?>
                            <select class="form-select users-filter-select" id="users-filter-field" aria-label="Choose user search field">
                                <option value="all">All fields</option>
                                <option value="firstname">First name</option>
                                <option value="lastname">Last name</option>
                            </select>
                        <?php endif; ?>
                    </div>
                    <?php
                        $posColorOptions = [];
                        foreach ($productColorsById as $productColors) {
                            foreach ($productColors as $productColor) {
                                if (!in_array($productColor, $posColorOptions, true)) {
                                    $posColorOptions[] = $productColor;
                                }
                            }
                        }
                        sort($posColorOptions, SORT_NATURAL | SORT_FLAG_CASE);
                    ?>
                    <div class="dashboard-search-tools" id="pos-search-tools" <?= $showPosTab ? '' : 'hidden' ?>>
                        <div class="dashboard-search input-group" role="search">
                            <span class="input-group-text bg-white border-end-0" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control border-start-0" id="pos-product-search" type="search" placeholder="Search products..." aria-label="Search POS products">
                        </div>
                        <select class="form-select users-filter-select" id="pos-price-sort" aria-label="Sort POS products by price">
                            <option value="default">Price: default</option>
                            <option value="high-to-low">Price: high to low</option>
                            <option value="low-to-high">Price: low to high</option>
                        </select>
                        <select class="form-select users-filter-select" id="pos-color-filter" aria-label="Filter POS products by color">
                            <option value="all">All colors</option>
                            <?php foreach ($posColorOptions as $posColor): ?>
                                <option value="<?= htmlspecialchars($posColor, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($posColor, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="dashboard-search-tools orders-search-tools" id="orders-search-tools" <?= $showOrdersTab ? '' : 'hidden' ?>>
                        <div class="dashboard-search input-group" role="search">
                            <span class="input-group-text bg-white border-end-0" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control border-start-0" id="orders-search" type="search" placeholder="Search orders..." aria-label="Search delivery orders">
                        </div>
                        <div class="orders-filter-group" role="group" aria-label="Filter orders by delivery date and status">
                            <input class="form-control orders-date-filter" id="orders-date-from" type="date" aria-label="Delivery date from">
                            <input class="form-control orders-date-filter" id="orders-date-to" type="date" aria-label="Delivery date to">
                            <select class="form-select users-filter-select" id="orders-status-filter" aria-label="Filter orders by status">
                                <option value="all">All statuses</option>
                                <option value="pending">Pending</option>
                                <option value="delivered">Delivered</option>
                                <option value="delivery failed">Delivery failed</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <div class="dashboard-search-tools" id="inventory-search-tools" <?= $showInventoryTab ? '' : 'hidden' ?>>
                        <div class="dashboard-search input-group" role="search">
                            <span class="input-group-text bg-white border-end-0" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control border-start-0" id="inventory-search" type="search" placeholder="Search inventory..." aria-label="Search inventory">
                        </div>
                        <select class="form-select users-filter-select" id="inventory-filter-field" aria-label="Choose inventory search field">
                            <option value="all">All fields</option>
                            <option value="itemName">Item name</option>
                            <option value="category">Category</option>
                            <option value="color">Color</option>
                            <option value="price">Price</option>
                            <option value="stock">Stock</option>
                            <option value="itemId">Item ID</option>
                        </select>
                    </div>
                    <div class="dashboard-search-tools" id="products-search-tools" <?= $showProducts ? '' : 'hidden' ?>>
                        <div class="dashboard-search input-group" role="search">
                            <span class="input-group-text bg-white border-end-0" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control border-start-0" id="products-search" type="search" placeholder="Search products..." aria-label="Search products">
                        </div>
                        <select class="form-select users-filter-select" id="products-price-sort" aria-label="Sort products by price">
                            <option value="default">Default order</option>
                            <option value="name-a-to-z">Name: A to Z</option>
                            <option value="high-to-low">Price: high to low</option>
                            <option value="low-to-high">Price: low to high</option>
                        </select>
                    </div>
                    <div class="dashboard-clock text-end" aria-label="Current date and time">
                        <time id="current-date" datetime="<?= htmlspecialchars(date(DATE_ATOM), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></time>
                        <time id="current-time"><?= htmlspecialchars(date('g:i:s A'), ENT_QUOTES, 'UTF-8') ?></time>
                    </div>
                </header>
                <section class="admin-dashboard-panel p-4" id="dashboard-panel" aria-label="Dashboard" <?= $showDashboardTab ? '' : 'hidden' ?>>
                    <?php if ($isAdmin): ?>
                        <div class="dashboard-welcome mb-4">
                            <div>
                                <p class="text-uppercase small fw-semibold mb-1">Admin overview</p>
                                <h3 class="h3 mb-1">Welcome, <?= htmlspecialchars($firstName !== '' ? $firstName : 'Admin', ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="mb-0">Here’s how your shop is doing today.</p>
                            </div>
                            <span class="dashboard-welcome-date"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if ($dashboardLoadError !== ''): ?>
                            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($dashboardLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="dashboard-metric-grid mb-4">
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">Sales today</span>
                                <strong><?= number_format($dashboardMetrics['today_sales']) ?></strong>
                                <span class="dashboard-metric-note">Completed purchases</span>
                            </article>
                            <article class="dashboard-metric-card dashboard-metric-revenue">
                                <span class="dashboard-metric-label">Revenue today</span>
                                <strong>₱<?= number_format($dashboardMetrics['today_revenue'], 2) ?></strong>
                                <span class="dashboard-metric-note">From completed purchases</span>
                            </article>
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">Pending deliveries</span>
                                <strong><?= number_format($dashboardMetrics['pending_deliveries']) ?></strong>
                                <span class="dashboard-metric-note">Awaiting delivery completion</span>
                            </article>
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">Products</span>
                                <strong><?= number_format($dashboardMetrics['product_count']) ?></strong>
                                <span class="dashboard-metric-note">In your product catalog</span>
                            </article>
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">Inventory items</span>
                                <strong><?= number_format($dashboardMetrics['inventory_count']) ?></strong>
                                <span class="dashboard-metric-note">Across all categories</span>
                            </article>
                            <article class="dashboard-metric-card <?= $dashboardMetrics['low_stock_count'] > 0 ? 'dashboard-metric-warning' : '' ?>">
                                <span class="dashboard-metric-label">Low stock</span>
                                <strong><?= number_format($dashboardMetrics['low_stock_count']) ?></strong>
                                <span class="dashboard-metric-note">Items with 5 or fewer in stock</span>
                            </article>
                        </div>
                        <div class="dashboard-detail-grid">
                            <section class="dashboard-detail-card">
                                <div class="dashboard-detail-heading">
                                    <div>
                                        <h4 class="h5 mb-1">Recent sales</h4>
                                        <p class="text-secondary small mb-0">Latest completed walk-in and delivered orders.</p>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary dashboard-open-tab" type="button" data-dashboard-tab="Sales">View sales</button>
                                </div>
                                <?php if ($dashboardRecentSales === []): ?>
                                    <p class="text-secondary text-center py-4 mb-0">No completed sales yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle dashboard-recent-table mb-0">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Sale</th>
                                                    <th scope="col">Processed by</th>
                                                    <th scope="col">Type</th>
                                                    <th scope="col">Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($dashboardRecentSales as $recentSale): ?>
                                                    <?php
                                                        $recentCashierName = trim((string) ($recentSale['Firstname'] ?? '') . ' ' . (string) ($recentSale['Lastname'] ?? ''));
                                                        if ($recentCashierName === '') {
                                                            $recentCashierName = (string) ($recentSale['Username'] ?? 'Unknown user');
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td class="text-nowrap">
                                                            #<?= htmlspecialchars((string) $recentSale['SaleID'], ENT_QUOTES, 'UTF-8') ?>
                                                            <span class="d-block small text-secondary"><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $recentSale['SaleDate'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                        </td>
                                                        <td><?= htmlspecialchars($recentCashierName, ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars(ucfirst((string) $recentSale['OrderType']), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td class="text-nowrap fw-semibold">₱<?= htmlspecialchars(number_format((float) $recentSale['TotalAmount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </section>
                            <section class="dashboard-detail-card">
                                <div class="dashboard-detail-heading">
                                    <div>
                                        <h4 class="h5 mb-1">Low-stock inventory</h4>
                                        <p class="text-secondary small mb-0">Items with 5 or fewer units remaining.</p>
                                    </div>
                                    <button class="btn btn-sm btn-outline-primary dashboard-open-tab" type="button" data-dashboard-tab="Inventory">View inventory</button>
                                </div>
                                <?php if ($dashboardLowStockItems === []): ?>
                                    <p class="text-secondary text-center py-4 mb-0">No low-stock items. Inventory levels look good.</p>
                                <?php else: ?>
                                    <ul class="dashboard-low-stock-list">
                                        <?php foreach ($dashboardLowStockItems as $lowStockItem): ?>
                                            <li>
                                                <span>
                                                    <strong><?= htmlspecialchars((string) $lowStockItem['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <small><?= htmlspecialchars((string) $lowStockItem['Category'], ENT_QUOTES, 'UTF-8') ?> · Item #<?= htmlspecialchars((string) $lowStockItem['ItemID'], ENT_QUOTES, 'UTF-8') ?></small>
                                                </span>
                                                <span class="dashboard-stock-count"><?= htmlspecialchars((string) $lowStockItem['Stock'], ENT_QUOTES, 'UTF-8') ?> left</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </section>
                        </div>
                        <section class="dashboard-detail-card dashboard-activity-card mt-4" aria-labelledby="dashboard-activity-heading">
                            <div class="dashboard-detail-heading">
                                <div>
                                    <h4 class="h5 mb-1" id="dashboard-activity-heading">Recent activity</h4>
                                    <p class="text-secondary small mb-0">Latest sales, delivery, inventory, product, user, and login events.</p>
                                </div>
                            </div>
                            <?php if ($dashboardActivity === []): ?>
                                <p class="text-secondary text-center py-4 mb-0">No activity has been recorded yet.</p>
                            <?php else: ?>
                                <ol class="dashboard-activity-list">
                                    <?php foreach ($dashboardActivity as $activity): ?>
                                        <li class="dashboard-activity-item">
                                            <div class="dashboard-activity-meta">
                                                <span class="dashboard-activity-type"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $activity['EventType'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                <time datetime="<?= htmlspecialchars(date(DATE_ATOM, strtotime((string) $activity['CreatedAt'])), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(date('M j, Y · g:i A', strtotime((string) $activity['CreatedAt'])), ENT_QUOTES, 'UTF-8') ?>
                                                </time>
                                            </div>
                                            <p class="dashboard-activity-description"><?= htmlspecialchars((string) $activity['Description'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <span class="dashboard-activity-actor">By <?= htmlspecialchars((string) $activity['ActorName'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </section>
                    <?php else: ?>
                        <div class="dashboard-welcome mb-4">
                            <div>
                                <p class="text-uppercase small fw-semibold mb-1">Staff overview</p>
                                <h3 class="h3 mb-1">Welcome, <?= htmlspecialchars($firstName !== '' ? $firstName : 'Staff', ENT_QUOTES, 'UTF-8') ?></h3>
                                <p class="mb-0">Here’s your sales summary and current delivery workload.</p>
                            </div>
                            <span class="dashboard-welcome-date"><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <?php if ($staffDashboardLoadError !== ''): ?>
                            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($staffDashboardLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="dashboard-metric-grid mb-4">
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">My sales today</span>
                                <strong><?= number_format($staffDashboardMetrics['today_sales']) ?></strong>
                                <span class="dashboard-metric-note">Purchases processed by you</span>
                            </article>
                            <article class="dashboard-metric-card dashboard-metric-revenue">
                                <span class="dashboard-metric-label">My revenue today</span>
                                <strong>₱<?= number_format($staffDashboardMetrics['today_revenue'], 2) ?></strong>
                                <span class="dashboard-metric-note">From your completed purchases</span>
                            </article>
                            <article class="dashboard-metric-card">
                                <span class="dashboard-metric-label">Pending deliveries</span>
                                <strong><?= number_format($staffDashboardMetrics['pending_deliveries']) ?></strong>
                                <span class="dashboard-metric-note">Awaiting delivery completion</span>
                            </article>
                        </div>
                        <section class="dashboard-detail-card" aria-labelledby="staff-recent-sales-heading">
                            <div class="dashboard-detail-heading">
                                <div>
                                    <h4 class="h5 mb-1" id="staff-recent-sales-heading">My recent sales</h4>
                                    <p class="text-secondary small mb-0">Only purchases processed by your account are shown.</p>
                                </div>
                                <button class="btn btn-sm btn-outline-primary dashboard-open-tab" type="button" data-dashboard-tab="Point of Sale">New sale</button>
                            </div>
                            <?php if ($staffDashboardRecentSales === []): ?>
                                <p class="text-secondary text-center py-4 mb-0">You have no completed sales yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle dashboard-recent-table mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Sale</th>
                                                <th scope="col">Type</th>
                                                <th scope="col">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($staffDashboardRecentSales as $recentStaffSale): ?>
                                                <tr>
                                                    <td class="text-nowrap">
                                                        #<?= htmlspecialchars((string) $recentStaffSale['SaleID'], ENT_QUOTES, 'UTF-8') ?>
                                                        <span class="d-block small text-secondary"><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $recentStaffSale['SaleDate'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                    </td>
                                                    <td><?= htmlspecialchars(ucfirst((string) $recentStaffSale['OrderType']), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-nowrap fw-semibold">₱<?= htmlspecialchars(number_format((float) $recentStaffSale['TotalAmount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </section>
                <section class="users-panel p-4" id="users-panel" aria-label="Users" <?= $showUsersTab ? '' : 'hidden' ?>>
                    <?php
                        $adminUsers = array_values(array_filter(
                            $users,
                            static fn (array $user): bool => empty($user['IsCustomer']) && (string) ($user['Role'] ?? '') === 'Admin'
                        ));
                        $staffUsers = array_values(array_filter(
                            $users,
                            static fn (array $user): bool => empty($user['IsCustomer']) && (string) ($user['Role'] ?? '') === 'Staff'
                        ));
                        $customerAccounts = array_values(array_filter(
                            $users,
                            static fn (array $user): bool => !empty($user['IsCustomer'])
                        ));
                        $userCategory = $_GET['user_category'] ?? 'admin';
                        if (!in_array($userCategory, ['admin', 'staff', 'customer'], true)) {
                            $userCategory = 'admin';
                        }
                        $showAdminAccounts = $userCategory === 'admin';
                        $showStaffAccounts = $userCategory === 'staff';
                        $showCustomerAccounts = $userCategory === 'customer';
                    ?>
                    <?php if ($userNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($userNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($userCreateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($userCreateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($userCreateNotice !== ''): ?>
                        <div class="alert alert-info" role="status"><?= htmlspecialchars($userCreateNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($userUpdateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($userUpdateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($userDeleteError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($userDeleteError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($usersLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($usersLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php else: ?>
                        <div class="nav nav-tabs mb-4" role="tablist" aria-label="User account categories">
                            <button class="nav-link <?= $showAdminAccounts ? 'active' : '' ?>" id="admin-users-tab" type="button" role="tab" aria-controls="admin-users-view" aria-selected="<?= $showAdminAccounts ? 'true' : 'false' ?>" data-user-category-tab="admin" tabindex="<?= $showAdminAccounts ? '0' : '-1' ?>">Admin</button>
                            <button class="nav-link <?= $showStaffAccounts ? 'active' : '' ?>" id="staff-users-tab" type="button" role="tab" aria-controls="staff-users-view" aria-selected="<?= $showStaffAccounts ? 'true' : 'false' ?>" data-user-category-tab="staff" tabindex="<?= $showStaffAccounts ? '0' : '-1' ?>">Staff</button>
                            <button class="nav-link <?= $showCustomerAccounts ? 'active' : '' ?>" id="customer-users-tab" type="button" role="tab" aria-controls="customer-users-view" aria-selected="<?= $showCustomerAccounts ? 'true' : 'false' ?>" data-user-category-tab="customer" tabindex="<?= $showCustomerAccounts ? '0' : '-1' ?>">Customer</button>
                        </div>
                        <section class="mb-4" id="admin-users-view" role="tabpanel" aria-labelledby="admin-users-tab" data-user-category-panel="admin" <?= $showAdminAccounts ? '' : 'hidden' ?>>
                            <h3 class="h5 mb-3" id="admin-users-title">Admin users</h3>
                            <div class="table-responsive bg-white rounded-3 shadow-sm">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <caption class="visually-hidden">Admin user accounts</caption>
                                    <thead class="users-table-header">
                                        <tr>
                                            <th scope="col">User ID</th>
                                            <th scope="col">First name</th>
                                            <th scope="col">Last name</th>
                                            <th scope="col">Username</th>
                                            <th scope="col">Email</th>
                                            <th scope="col">Role</th>
                                            <th scope="col">Access</th>
                                            <th scope="col" class="actions-header">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="admin-users-table-body" data-column-count="8">
                                        <?php if ($adminUsers === []): ?>
                                            <tr class="users-empty-row">
                                                <td class="text-center text-secondary py-4" colspan="8">No admin users found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($adminUsers as $user): ?>
                                                <tr data-search-text="<?= htmlspecialchars(implode(' ', array_map('strval', [$user['UserID'], $user['Firstname'], $user['Lastname'], $user['Username'], $user['Email'], $user['Role'], $user['Access']])), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-firstname="<?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-lastname="<?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-role="<?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Email'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Access'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-nowrap">
                                                        <button class="btn btn-sm row-action-button edit-user-button" type="button" aria-label="Edit user" title="Edit user"
                                                            data-user-id="<?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-firstname="<?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-lastname="<?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-username="<?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-email="<?= htmlspecialchars((string) $user['Email'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-role="<?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-access="<?= htmlspecialchars((string) $user['Access'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <svg class="row-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m14 5 5 5M4 20l4.2-.8L19 8.4a2.1 2.1 0 0 0-3-3L5.2 16.2 4 20Z"></path></svg>
                                                        </button>
                                                        <form class="d-inline" method="post" action="index.php">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <button class="btn btn-sm row-action-button delete-user-button" type="submit" aria-label="Delete user" title="Delete user"
                                                                data-username="<?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <svg class="row-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 14h12l1-14M9 7V4h6v3"></path></svg>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="add-user-button btn" id="open-add-user" type="button" aria-label="Add user" title="Add user">
                                <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 5v14"></path>
                                    <path d="M5 12h14"></path>
                                </svg>
                            </button>
                        </section>
                        <section class="mb-4" id="staff-users-view" role="tabpanel" aria-labelledby="staff-users-tab" data-user-category-panel="staff" <?= $showStaffAccounts ? '' : 'hidden' ?>>
                            <h3 class="h5 mb-3" id="staff-users-title">Staff users</h3>
                            <div class="table-responsive bg-white rounded-3 shadow-sm">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <caption class="visually-hidden">Staff user accounts</caption>
                                    <thead class="users-table-header">
                                        <tr>
                                            <th scope="col">User ID</th>
                                            <th scope="col">First name</th>
                                            <th scope="col">Last name</th>
                                            <th scope="col">Username</th>
                                            <th scope="col">Email</th>
                                            <th scope="col">Role</th>
                                            <th scope="col">Access</th>
                                            <th scope="col" class="actions-header">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="staff-users-table-body" data-column-count="8">
                                        <?php if ($staffUsers === []): ?>
                                            <tr class="users-empty-row">
                                                <td class="text-center text-secondary py-4" colspan="8">No staff users found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($staffUsers as $user): ?>
                                                <tr data-search-text="<?= htmlspecialchars(implode(' ', array_map('strval', [$user['UserID'], $user['Firstname'], $user['Lastname'], $user['Username'], $user['Email'], $user['Role'], $user['Access']])), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-firstname="<?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-lastname="<?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-role="<?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Email'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $user['Access'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="text-nowrap">
                                                        <button class="btn btn-sm row-action-button edit-user-button" type="button" aria-label="Edit user" title="Edit user"
                                                            data-user-id="<?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-firstname="<?= htmlspecialchars((string) $user['Firstname'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-lastname="<?= htmlspecialchars((string) $user['Lastname'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-username="<?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-email="<?= htmlspecialchars((string) $user['Email'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-role="<?= htmlspecialchars((string) $user['Role'], ENT_QUOTES, 'UTF-8') ?>"
                                                            data-access="<?= htmlspecialchars((string) $user['Access'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <svg class="row-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m14 5 5 5M4 20l4.2-.8L19 8.4a2.1 2.1 0 0 0-3-3L5.2 16.2 4 20Z"></path></svg>
                                                        </button>
                                                        <form class="d-inline" method="post" action="index.php">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $user['UserID'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <button class="btn btn-sm row-action-button delete-user-button" type="submit" aria-label="Delete user" title="Delete user"
                                                                data-username="<?= htmlspecialchars((string) $user['Username'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <svg class="row-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 14h12l1-14M9 7V4h6v3"></path></svg>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button class="add-user-button btn" id="open-staff-add-user" type="button" aria-label="Add staff user" title="Add staff user">
                                <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 5v14"></path>
                                    <path d="M5 12h14"></path>
                                </svg>
                            </button>
                        </section>
                        <section id="customer-users-view" role="tabpanel" aria-labelledby="customer-users-tab" data-user-category-panel="customer" <?= $showCustomerAccounts ? '' : 'hidden' ?>>
                            <h3 class="h5 mb-3" id="customer-accounts-title">Customer accounts</h3>
                            <div class="table-responsive bg-white rounded-3 shadow-sm">
                                <table class="table table-striped table-hover align-middle mb-0">
                                    <caption class="visually-hidden">Customer accounts</caption>
                                    <thead class="users-table-header">
                                        <tr>
                                            <th scope="col">Customer ID</th>
                                            <th scope="col">First name</th>
                                            <th scope="col">Last name</th>
                                            <th scope="col">Email</th>
                                            <th scope="col">Access</th>
                                            <th scope="col" class="actions-header">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="customer-accounts-table-body" data-column-count="6">
                                        <?php if ($customerAccounts === []): ?>
                                            <tr class="users-empty-row">
                                                <td class="text-center text-secondary py-4" colspan="6">No customer accounts found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($customerAccounts as $customer): ?>
                                                <tr data-search-text="<?= htmlspecialchars(implode(' ', array_map('strval', [$customer['UserID'], $customer['Firstname'], $customer['Lastname'], $customer['Username'], $customer['Role'], $customer['Access']])), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-firstname="<?= htmlspecialchars((string) $customer['Firstname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-lastname="<?= htmlspecialchars((string) $customer['Lastname'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-role="<?= htmlspecialchars((string) $customer['Role'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= htmlspecialchars((string) $customer['UserID'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $customer['Firstname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $customer['Lastname'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= htmlspecialchars((string) $customer['Username'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td>
                                                        <span class="badge <?= $customer['Access'] === 'Enabled' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                                            <?= htmlspecialchars((string) $customer['Access'], ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <form method="post" action="index.php?tab=users&amp;user_category=customer" class="m-0">
                                                            <input type="hidden" name="action" value="toggle_customer_access">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="customer_id" value="<?= (int) $customer['CustomerID'] ?>">
                                                            <input type="hidden" name="access" value="<?= $customer['Access'] === 'Enabled' ? 'Disabled' : 'Enabled' ?>">
                                                            <button class="btn btn-sm <?= $customer['Access'] === 'Enabled' ? 'btn-outline-danger' : 'btn-outline-success' ?>" type="submit">
                                                                <?= $customer['Access'] === 'Enabled' ? 'Disable access' : 'Enable access' ?>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endif; ?>
                </section>
                <section class="pos-panel p-4" id="pos-panel" aria-label="Point of Sale" <?= $showPosTab ? '' : 'hidden' ?>>
                    <?php if ($saleNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($saleNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($saleCreateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($saleCreateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="pos-layout">
                        <div class="pos-products">
                            <?php if ($productsLoadError !== ''): ?>
                                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($productsLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php elseif ($products === []): ?>
                                <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary">
                                    <p class="mb-0">No products are available for sale.</p>
                                </div>
                            <?php else: ?>
                                <div class="pos-product-grid">
                                    <?php foreach ($products as $product): ?>
                                        <?php $imageUrl = getProductImageUrl((int) $product['ProductID']); ?>
                                        <article class="inventory-item-card pos-product-card"
                                            data-product-id="<?= htmlspecialchars((string) $product['ProductID'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-product-name="<?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-product-price="<?= htmlspecialchars(number_format((float) $product['SalePrice'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-product-stock="<?= htmlspecialchars((string) ($product['ProductStock'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-product-colors="<?= htmlspecialchars(implode(', ', $productColorsById[(int) $product['ProductID']] ?? []), ENT_QUOTES, 'UTF-8') ?>">
                                            <?php if ($imageUrl !== null): ?>
                                                <img class="inventory-item-image" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?php else: ?>
                                                <div class="inventory-item-image inventory-item-image-placeholder" role="img" aria-label="No image available">
                                                    <span>No image</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="inventory-item-card-body">
                                                <h3 class="inventory-item-name"><?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?></h3>
                                                <?php if ((float) $product['ProductDiscountPercent'] > 0): ?>
                                                    <span class="inventory-item-price">₱<?= number_format((float) $product['SalePrice'], 2) ?> <del class="text-secondary small">₱<?= number_format((float) $product['Price'], 2) ?></del> <span class="badge text-bg-danger"><?= number_format((float) $product['ProductDiscountPercent'], 2) ?>% off</span></span>
                                                <?php else: ?>
                                                    <span class="inventory-item-price">₱<?= number_format((float) $product['SalePrice'], 2) ?></span>
                                                <?php endif; ?>
                                                <span class="inventory-item-stock"><?= $product['ProductStock'] === null ? 'Stock not tracked' : htmlspecialchars((string) $product['ProductStock'], ENT_QUOTES, 'UTF-8') . ' available' ?></span>
                                                <button class="btn btn-sm btn-primary pos-add-to-order-button mt-2" type="button" <?= $product['ProductStock'] !== null && (int) $product['ProductStock'] < 1 ? 'disabled' : '' ?>>Add to order</button>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary" id="pos-product-no-matches" hidden>
                                    <p class="mb-0">No matching POS products found.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        <form class="pos-order-panel pos-checkout-form p-3" id="pos-checkout-form" method="post" action="index.php">
                            <input type="hidden" name="action" value="complete_sale">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="order_token" value="<?= htmlspecialchars($posOrderToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="cart" id="pos-checkout-cart">
                            <h2 class="h5 mb-3">Current order</h2>
                            <div class="pos-order-items" id="pos-order-items" aria-live="polite">
                                <p class="pos-order-empty text-secondary mb-0">No items added yet.</p>
                            </div>
                            <button class="btn btn-outline-primary w-100 mt-3" id="pos-add-ons-button" type="button">Add add-ons</button>
                            <div class="pos-order-totals mt-3 pt-3">
                                <div class="d-flex justify-content-between">
                                    <span>Subtotal</span>
                                    <span id="pos-order-subtotal">₱0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mt-2" id="pos-discount-row" hidden>
                                    <span id="pos-discount-label">Discount</span>
                                    <span id="pos-discount-amount">−₱0.00</span>
                                </div>
                                <div class="d-flex justify-content-between mt-2 fw-bold">
                                    <span>Total</span>
                                    <span id="pos-order-total">₱0.00</span>
                                </div>
                            </div>
                            <p class="small fw-semibold text-primary mt-3 mb-0" id="pos-checkout-step-label" aria-live="polite">Step 1 of 3: Walk-in order</p>
                            <section class="pos-checkout-step mt-3" id="pos-checkout-step-1">
                                <p class="mb-3">Order type: <strong>Walk-in</strong></p>
                                <button class="btn btn-primary w-100 mt-3" id="pos-continue-to-payment" type="button" disabled>Continue to payment</button>
                            </section>
                            <section class="pos-checkout-step mt-3" id="pos-checkout-step-2" hidden>
                                <div class="mb-3">
                                    <label class="form-label" for="pos-payment-method">Payment method</label>
                                    <select class="form-select" id="pos-payment-method" name="payment_method">
                                        <option value="cash">Cash</option>
                                        <option value="gcash">GCash</option>
                                        <option value="maribank">MariBank</option>
                                        <option value="gotyme">GoTyme</option>
                                        <option value="landbank">Landbank</option>
                                    </select>
                                </div>
                                <div class="mb-3" id="pos-cash-payment-field" hidden>
                                    <label class="form-label" for="pos-cash-amount">Cash amount received</label>
                                    <div class="input-group">
                                        <span class="input-group-text">₱</span>
                                        <input class="form-control" id="pos-cash-amount" name="amount_paid" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" placeholder="Enter amount received" disabled>
                                    </div>
                                    <div class="form-text">Order total: <span id="pos-cash-total-due">₱0.00</span></div>
                                    <div class="invalid-feedback d-block" id="pos-cash-payment-error" role="alert" hidden></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="pos-discount-type">Discount</label>
                                    <select class="form-select" id="pos-discount-type" name="discount_type">
                                        <option value="none">No discount</option>
                                        <option value="senior">Senior citizen (20%)</option>
                                        <option value="pwd">Person with disability (20%)</option>
                                    </select>
                                    <small class="form-text text-secondary">Apply a Senior Citizen or PWD discount only when eligible and valid identification is presented.</small>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-secondary flex-fill" id="pos-back-to-order" type="button">Back</button>
                                    <button class="btn btn-primary flex-fill" id="pos-review-order" type="button">Review order</button>
                                </div>
                            </section>
                            <section class="pos-checkout-step mt-3" id="pos-checkout-step-3" hidden>
                                <h3 class="h6">Review your order</h3>
                                <div class="small" id="pos-checkout-review"></div>
                                <div class="d-flex gap-2 mt-3">
                                    <button class="btn btn-outline-secondary flex-fill" id="pos-back-to-payment" type="button">Back</button>
                                    <button class="btn btn-success flex-fill" id="pos-place-order" type="submit">Place order</button>
                                </div>
                            </section>
                        </form>
                    </div>
                    <dialog class="pos-add-ons-dialog border-0 rounded-3 shadow p-4" id="pos-add-ons-dialog" aria-labelledby="pos-add-ons-title">
                        <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                            <div>
                                <h2 class="h4 mb-1" id="pos-add-ons-title">Add-ons</h2>
                                <p class="text-secondary mb-0">Choose an add-on to include in the current order.</p>
                            </div>
                            <button class="btn-close" id="pos-close-add-ons" type="button" aria-label="Close add-ons"></button>
                        </div>
                        <div class="input-group mb-3" role="search">
                            <span class="input-group-text bg-white" aria-hidden="true">
                                <svg class="search-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
                            </span>
                            <input class="form-control" id="pos-add-ons-search" type="search" placeholder="Search add-ons..." aria-label="Search add-ons">
                        </div>
                        <?php
                            $posAddons = array_values(array_filter(
                                $inventoryItems,
                                static fn (array $item): bool => $item['Category'] === 'Addons'
                            ));
                        ?>
                        <?php if ($posAddons === []): ?>
                            <p class="text-secondary mb-0">No add-ons are available in inventory.</p>
                        <?php else: ?>
                            <div class="pos-add-ons-grid">
                                <?php foreach ($posAddons as $addon): ?>
                                    <?php $addonImageUrl = getInventoryImageUrl((int) $addon['ItemID']); ?>
                                    <article class="inventory-item-card pos-add-on-card"
                                        data-item-id="<?= htmlspecialchars((string) $addon['ItemID'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-item-name="<?= htmlspecialchars((string) $addon['ItemName'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-item-price="<?= htmlspecialchars(number_format((float) $addon['Price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if ($addonImageUrl !== null): ?>
                                            <img class="inventory-item-image" src="<?= htmlspecialchars($addonImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $addon['ItemName'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?php else: ?>
                                            <div class="inventory-item-image inventory-item-image-placeholder" role="img" aria-label="No image available"><span>No image</span></div>
                                        <?php endif; ?>
                                        <div class="inventory-item-card-body">
                                            <h3 class="inventory-item-name"><?= htmlspecialchars((string) $addon['ItemName'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="inventory-item-price">₱<?= htmlspecialchars(number_format((float) $addon['Price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="inventory-item-stock"><?= htmlspecialchars((string) $addon['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</span>
                                            <button class="btn btn-sm btn-primary pos-add-on-to-order-button mt-2" type="button">Add to order</button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <p class="text-secondary text-center mt-3 mb-0" id="pos-add-ons-no-matches" aria-live="polite" hidden>No matching add-ons found.</p>
                        <?php endif; ?>
                    </dialog>
                    <button class="add-user-button pos-customize-order-button btn" id="pos-customize-order-button" type="button" aria-label="Customize order" title="Customize order">
                        <svg class="pos-customize-order-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path>
                        </svg>
                    </button>
                </section>
                <section class="orders-panel p-4" id="orders-panel" aria-label="Customer and delivery orders" <?= $showOrdersTab ? '' : 'hidden' ?>>
                    <?php if ($customerOrderReviewNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($customerOrderReviewNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($customerOrderReviewError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($customerOrderReviewError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php
                        $walkInOrderCount = count(array_filter(
                            $customerOrders,
                            static fn (array $order): bool => !empty($order['IsPosWalkIn'])
                        ));
                        $deliveryOrderCount = count(array_filter(
                            $customerOrders,
                            static fn (array $order): bool => empty($order['IsPosWalkIn']) && empty($order['IsPosOrder'])
                        ));
                    ?>
                    <div class="nav nav-tabs mb-3" role="group" aria-label="Filter orders by fulfillment">
                        <button class="nav-link active order-source-filter" type="button" aria-pressed="true" data-order-source="walk-in">Walk-In <span class="badge text-bg-secondary ms-1"><?= $walkInOrderCount ?></span></button>
                        <button class="nav-link order-source-filter" type="button" aria-pressed="false" data-order-source="delivery">Delivery <span class="badge text-bg-secondary ms-1"><?= $deliveryOrderCount ?></span></button>
                    </div>
                    <div class="orders-category-filters d-flex flex-nowrap gap-2 overflow-auto mb-4 pb-2" role="group" aria-label="Filter orders by category">
                        <?php
                            $orderCategories = [
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
                            foreach ($orderCategories as $categoryValue => $categoryLabel):
                        ?>
                            <button class="btn btn-outline-primary text-nowrap order-category-filter<?= $categoryValue === 'all' ? ' active' : '' ?>" type="button" data-order-category="<?= htmlspecialchars($categoryValue, ENT_QUOTES, 'UTF-8') ?>" aria-pressed="<?= $categoryValue === 'all' ? 'true' : 'false' ?>"><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="alert alert-info" id="orders-category-no-matches" role="status" hidden>No orders match this category and the selected search filters.</div>
                    <div class="mb-4">
                        <h3 class="h4 mb-1">Orders</h3>
                        <p class="text-secondary mb-0">Walk-in purchases made at the POS and deliveries ordered through the customer shop.</p>
                    </div>
                    <?php if ($deliveryOrdersLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($deliveryOrdersLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <div class="alert alert-warning" id="pending-customer-order-summary" role="status" <?= $pendingCustomerOrderCount > 0 ? '' : 'hidden' ?>>
                            <span id="pending-customer-order-count"><?= $pendingCustomerOrderCount ?></span> customer <span id="pending-customer-order-plural"><?= $pendingCustomerOrderCount === 1 ? 'order is' : 'orders are' ?></span> waiting for your review.
                        </div>
                        <div class="alert alert-success" id="received-customer-order-summary" role="status" <?= $receivedCustomerOrderCount > 0 ? '' : 'hidden' ?>>
                            <span id="received-customer-order-count"><?= $receivedCustomerOrderCount ?></span> customer <span id="received-customer-order-plural"><?= $receivedCustomerOrderCount === 1 ? 'order has' : 'orders have' ?></span> confirmed receipt.
                        </div>
                    <?php endif; ?>
                    <?php if ($customerOrdersLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($customerOrdersLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!$automaticReceiptUpdated): ?>
                        <div class="alert alert-danger" role="alert">Automatic order receipt updates are temporarily unavailable.</div>
                    <?php endif; ?>
                    <?php if ($customerOrders === []): ?>
                        <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary mb-4" data-customer-orders-empty>
                            <p class="mb-0">No orders have been placed yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive bg-white rounded-3 shadow-sm mb-4" id="orders-table-container">
                            <table class="table table-striped table-hover align-middle mb-0 orders-table">
                                <caption class="visually-hidden">Walk-in POS sales and delivery orders from the customer shop</caption>
                                <thead class="users-table-header">
                                    <tr>
                                        <th scope="col">Order / placed</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Fulfillment</th>
                                        <th scope="col">Items</th>
                                        <th scope="col">Total / payment</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Review / update status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customerOrders as $customerOrder): ?>
                                        <?php
                                            $customerOrderAddress = implode(', ', array_filter([
                                                $customerOrder['DeliveryAddress'],
                                                $customerOrder['DeliveryBarangay'],
                                                $customerOrder['DeliveryCity'],
                                                $customerOrder['DeliveryProvince'],
                                                $customerOrder['DeliveryPostalCode'],
                                            ], static fn ($part): bool => is_string($part) && trim($part) !== ''));
                                            $needsReview = empty($customerOrder['IsPosOrder'])
                                                && in_array((string) $customerOrder['Status'], ['Pending review', 'Pending'], true);
                                            $customerOrderCategory = match ((string) $customerOrder['Status']) {
                                                'Pending review' => 'to-be-approved',
                                                'Pending' => 'pending',
                                                'Received' => 'received',
                                                'Out for delivery' => 'out-for-delivery',
                                                'Delivered' => 'delivered',
                                                'Completed' => 'completed',
                                                'Cancelled' => 'cancelled',
                                                'Declined' => 'declined',
                                                'Delivery failed' => 'delivery-failed',
                                                default => 'pending',
                                            };
                                        ?>
                                        <tr class="customer-shop-order-row" data-order-source="<?= !empty($customerOrder['IsPosWalkIn']) ? 'walk-in' : (!empty($customerOrder['IsPosOrder']) ? 'pos-delivery' : 'delivery') ?>" data-order-category="<?= htmlspecialchars($customerOrderCategory, ENT_QUOTES, 'UTF-8') ?>" data-payment-method="<?= htmlspecialchars((string) $customerOrder['PaymentMethod'], ENT_QUOTES, 'UTF-8') ?>" data-total-amount="<?= htmlspecialchars((string) $customerOrder['TotalAmount'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td class="text-nowrap">
                                                <strong><?= htmlspecialchars((string) $customerOrder['OrderID'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <span class="d-block small text-secondary"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $customerOrder['OrderDate'])), ENT_QUOTES, 'UTF-8') ?></span>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(!empty($customerOrder['IsPosWalkIn']) ? 'POS walk-in' : (!empty($customerOrder['IsPosOrder']) ? 'POS checkout' : trim((string) $customerOrder['FirstName'] . ' ' . (string) $customerOrder['LastName'])), ENT_QUOTES, 'UTF-8') ?>
                                                <?php if (empty($customerOrder['IsPosOrder']) && empty($customerOrder['IsPosWalkIn'])): ?>
                                                    <span class="d-block small text-secondary"><?= htmlspecialchars((string) $customerOrder['Email'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <?php if (empty($customerOrder['IsPosWalkIn'])): ?>
                                                    <span class="d-block small text-secondary">Recipient: <?= htmlspecialchars((string) $customerOrder['RecipientName'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $customerOrder['RecipientPhone'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars(ucfirst((string) $customerOrder['FulfillmentType']), ENT_QUOTES, 'UTF-8') ?>
                                                <span class="d-block small text-secondary"><?= htmlspecialchars(date('M j, Y', strtotime((string) $customerOrder['FulfillmentDate'])), ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(date('g:i A', strtotime((string) $customerOrder['FulfillmentTime'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if ($customerOrderAddress !== ''): ?>
                                                    <span class="d-block small text-secondary"><?= htmlspecialchars($customerOrderAddress, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <?php if (is_string($customerOrder['DeliveryInstructions']) && trim($customerOrder['DeliveryInstructions']) !== ''): ?>
                                                    <span class="d-block small text-secondary">Note: <?= htmlspecialchars((string) $customerOrder['DeliveryInstructions'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <?php if (is_string($customerOrder['DeliveryFailureRemarks'] ?? null) && trim((string) $customerOrder['DeliveryFailureRemarks']) !== ''): ?>
                                                    <span class="d-block small text-danger">Remarks: <?= htmlspecialchars((string) $customerOrder['DeliveryFailureRemarks'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($customerOrder['items'] === []): ?>
                                                    <span class="text-secondary">Items unavailable</span>
                                                <?php else: ?>
                                                    <ul class="orders-item-list">
                                                        <?php foreach ($customerOrder['items'] as $customerOrderItem): ?>
                                                            <li>
                                                                <?= htmlspecialchars($customerOrderItem['name'], ENT_QUOTES, 'UTF-8') ?>
                                                                × <?= (int) $customerOrderItem['quantity'] ?>
                                                                <span class="text-secondary">(₱<?= number_format((float) $customerOrderItem['total'], 2) ?>)</span>
                                                                <?php if ($customerOrderItem['component_names'] !== []): ?>
                                                                    <span class="d-block small text-secondary"><?= htmlspecialchars(implode(', ', array_map('strval', $customerOrderItem['component_names'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">
                                                <strong>₱<?= number_format((float) $customerOrder['TotalAmount'], 2) ?></strong>
                                                <span class="d-block small text-secondary"><?= htmlspecialchars(ucfirst((string) $customerOrder['PaymentMethod']), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $customerOrder['PaymentStatus'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php if (!empty($customerOrder['IsPosOrder']) && $customerOrder['PaymentMethod'] === 'cash' && (float) $customerOrder['AmountPaid'] > 0): ?>
                                                    <span class="d-block small text-secondary">Cash received: ₱<?= number_format((float) $customerOrder['AmountPaid'], 2) ?> · Change: ₱<?= number_format((float) $customerOrder['ChangeAmount'], 2) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge text-bg-<?= in_array($customerOrder['Status'], ['Declined', 'Delivery failed'], true) ? 'danger' : (in_array($customerOrder['Status'], ['Accepted', 'Out for delivery', 'Delivered', 'Completed', 'Received'], true) ? 'success' : 'warning') ?>"><?= htmlspecialchars($customerOrder['Status'] === 'Received' && empty($customerOrder['IsPosOrder']) && empty($customerOrder['IsPosWalkIn']) ? 'Customer confirmed receipt' : (string) $customerOrder['Status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td>
                                                <?php if ($isAdmin && $needsReview): ?>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <form method="post" action="index.php?tab=orders">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="review_customer_order">
                                                            <input type="hidden" name="order_id" value="<?= (int) $customerOrder['OrderID'] ?>">
                                                            <input type="hidden" name="decision" value="Accepted">
                                                            <button class="btn btn-sm btn-success" type="submit">Accept</button>
                                                        </form>
                                                        <form method="post" action="index.php?tab=orders" onsubmit="return confirm('Decline this order and release its reserved stock?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="review_customer_order">
                                                            <input type="hidden" name="order_id" value="<?= (int) $customerOrder['OrderID'] ?>">
                                                            <input type="hidden" name="decision" value="Declined">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit">Decline</button>
                                                        </form>
                                                    </div>
                                                <?php elseif ($needsReview): ?>
                                                    <span class="text-secondary">Awaiting administrator review</span>
                                                <?php elseif (
                                                    $canManageCatalog
                                                    && (string) $customerOrder['FulfillmentType'] === 'delivery'
                                                    && (
                                                            !empty($customerOrder['IsPosOrder'])
                                                            || in_array((string) $customerOrder['Status'], ['Pending', 'Accepted', 'Out for delivery', 'Delivered', 'Delivery failed'], true)
                                                    )
                                                ): ?>
                                                    <form method="post" action="index.php?tab=orders" class="<?= !empty($customerOrder['IsPosOrder']) ? 'delivery-status-form ' : '' ?>d-flex flex-wrap align-items-center gap-2">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            <?php if (!empty($customerOrder['IsPosOrder'])): ?>
                                                                <input type="hidden" name="action" value="update_delivery_status">
                                                                <input type="hidden" name="sale_id" value="<?= (int) $customerOrder['SaleID'] ?>">
                                                                <?php $deliveryStatusField = 'delivery_status'; ?>
                                                            <?php else: ?>
                                                                <input type="hidden" name="action" value="update_customer_delivery_status">
                                                                <input type="hidden" name="order_id" value="<?= (int) $customerOrder['OrderID'] ?>">
                                                                <?php $deliveryStatusField = 'status'; ?>
                                                        <?php endif; ?>
                                                            <select class="form-select form-select-sm<?= !empty($customerOrder['IsPosOrder']) ? ' delivery-status-select' : '' ?>" name="<?= $deliveryStatusField ?>" aria-label="Update delivery status for order <?= htmlspecialchars((string) $customerOrder['OrderID'], ENT_QUOTES, 'UTF-8') ?>" required>
                                                                <?php foreach (['Pending', 'Out for delivery', 'Delivered', 'Delivery failed'] as $deliveryOption): ?>
                                                                    <option value="<?= htmlspecialchars($deliveryOption, ENT_QUOTES, 'UTF-8') ?>" <?= $customerOrder['Status'] === $deliveryOption ? 'selected' : '' ?>><?= htmlspecialchars($deliveryOption, ENT_QUOTES, 'UTF-8') ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button class="btn btn-sm btn-outline-primary" type="submit">Save status</button>
                                                    </form>
                                                <?php elseif (
                                                    $canManageCatalog
                                                    && empty($customerOrder['IsPosOrder'])
                                                    && (string) $customerOrder['FulfillmentType'] === 'pickup'
                                                    && (string) $customerOrder['Status'] === 'Accepted'
                                                ): ?>
                                                    <form method="post" action="index.php?tab=orders" class="d-flex flex-wrap align-items-center gap-2">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="update_customer_delivery_status">
                                                            <input type="hidden" name="order_id" value="<?= (int) $customerOrder['OrderID'] ?>">
                                                            <input type="hidden" name="status" value="Completed">
                                                            <button class="btn btn-sm btn-outline-primary" type="submit">Mark completed</button>
                                                    </form>
                                                <?php elseif (
                                                    (string) $customerOrder['Status'] === 'Received'
                                                    && empty($customerOrder['IsPosOrder'])
                                                    && empty($customerOrder['IsPosWalkIn'])
                                                ): ?>
                                                    <span class="text-secondary">Customer confirmed receipt</span>
                                                <?php else: ?>
                                                    <span class="text-secondary"><?= !empty($customerOrder['IsPosWalkIn']) ? 'POS sale completed' : 'Reviewed' ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <?php if ($saleNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($saleNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($deliveryStatusNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($deliveryStatusNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($deliveryStatusError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($deliveryStatusError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="alert alert-danger" id="orders-filter-error" role="alert" hidden>Start date must be on or before the end date.</div>
                </section>
                <section class="sales-panel p-4" id="sales-panel" aria-label="Sales" <?= $showSalesTab ? '' : 'hidden' ?>>
                    <?php if ($saleNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($saleNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($deliveryStatusNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($deliveryStatusNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <h3 class="h4 mb-1">Completed sales</h3>
                        <p class="text-secondary mb-0">Walk-in purchases appear after checkout. Delivery purchases appear here when marked Delivered.</p>
                    </div>
                    <?php
                        $walkInSalesTotal = 0.0;
                        $deliverySalesTotal = 0.0;
                        $walkInSalesCount = 0;
                        $deliverySalesCount = 0;
                        foreach ($salesRecords as $sale) {
                            if (empty($sale['CustomerOrderID'])) {
                                $walkInSalesTotal += (float) $sale['TotalAmount'];
                                $walkInSalesCount++;
                            } else {
                                $deliverySalesTotal += (float) $sale['TotalAmount'];
                                $deliverySalesCount++;
                            }
                        }
                    ?>
                    <?php if ($salesLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($salesLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php else: ?>
                        <div class="nav nav-tabs mb-3" role="group" aria-label="Filter sales by source">
                            <button class="nav-link active sales-source-filter" type="button" aria-pressed="true" data-sale-source="walk-in">Walk-In Sales <span class="badge text-bg-secondary ms-1"><?= $walkInSalesCount ?></span></button>
                            <button class="nav-link sales-source-filter" type="button" aria-pressed="false" data-sale-source="delivery">Delivery Sales <span class="badge text-bg-secondary ms-1"><?= $deliverySalesCount ?></span></button>
                        </div>
                        <div class="d-flex flex-wrap align-items-end gap-3 mb-3" aria-label="Filter sales by date">
                            <div>
                                <span class="form-label d-block">Date range</span>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Quick sales date range">
                                    <button class="btn btn-outline-primary sales-date-preset active" type="button" data-sale-date-preset="all" aria-pressed="true">All dates</button>
                                    <button class="btn btn-outline-primary sales-date-preset" type="button" data-sale-date-preset="today" aria-pressed="false">Today</button>
                                    <button class="btn btn-outline-primary sales-date-preset" type="button" data-sale-date-preset="week" aria-pressed="false">This week</button>
                                    <button class="btn btn-outline-primary sales-date-preset" type="button" data-sale-date-preset="month" aria-pressed="false">This month</button>
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="sales-date-from">From</label>
                                <input class="form-control form-control-sm" id="sales-date-from" type="date">
                            </div>
                            <div>
                                <label class="form-label" for="sales-date-to">To</label>
                                <input class="form-control form-control-sm" id="sales-date-to" type="date">
                            </div>
                        </div>
                        <div class="alert alert-danger py-2" id="sales-date-filter-error" role="alert" hidden>Start date must be on or before the end date.</div>
                        <?php if ($salesRecords === []): ?>
                            <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary">
                                <p class="mb-0">No completed sales yet.</p>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive bg-white rounded-3 shadow-sm">
                            <table class="table table-striped table-hover align-middle mb-0 orders-table">
                                <caption class="visually-hidden">Completed walk-in and delivered orders recorded in tblsales</caption>
                                <thead class="users-table-header">
                                    <tr>
                                        <th scope="col">Sale</th>
                                        <th scope="col" data-sale-view="walk-in">Processed by</th>
                                        <th scope="col" data-sale-view="delivery">Customer / details</th>
                                        <th scope="col">Items</th>
                                        <th scope="col">Subtotal</th>
                                        <th scope="col">Discount</th>
                                        <th scope="col">Total</th>
                                        <th scope="col">Payment</th>
                                        <th scope="col">Paid / change</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" data-sale-view="walk-in">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salesRecords as $sale): ?>
                                        <tr class="sales-record-row" data-sale-source="<?= empty($sale['CustomerOrderID']) ? 'walk-in' : 'delivery' ?>" data-sale-date="<?= htmlspecialchars(substr((string) $sale['SaleDate'], 0, 10), ENT_QUOTES, 'UTF-8') ?>" data-sale-total="<?= htmlspecialchars((string) $sale['TotalAmount'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td class="text-nowrap">
                                                <strong>#<?= htmlspecialchars((string) $sale['SaleID'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <span class="d-block small text-secondary"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $sale['SaleDate'])), ENT_QUOTES, 'UTF-8') ?></span>
                                            </td>
                                            <td data-sale-view="walk-in">
                                                <?php
                                                    $cashierName = trim((string) $sale['CashierFirstName'] . ' ' . (string) $sale['CashierLastName']);
                                                    $cashierDisplay = $cashierName !== '' ? $cashierName : (string) ($sale['CashierUsername'] ?? '');
                                                ?>
                                                <?= $cashierDisplay !== '' ? htmlspecialchars($cashierDisplay, ENT_QUOTES, 'UTF-8') : 'User #' . htmlspecialchars((string) $sale['UserID'], ENT_QUOTES, 'UTF-8') ?>
                                            </td>
                                            <td data-sale-view="delivery">
                                                <?php $customerName = trim((string) $sale['CustomerFirstName'] . ' ' . (string) $sale['CustomerLastName']); ?>
                                                <strong><?= htmlspecialchars($customerName !== '' ? $customerName : 'Customer #' . (string) $sale['CustomerOrderID'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <?php if (is_string($sale['CustomerEmail'] ?? null) && trim((string) $sale['CustomerEmail']) !== ''): ?>
                                                    <span class="d-block small text-secondary"><?= htmlspecialchars((string) $sale['CustomerEmail'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <?php if (is_string($sale['CustomerPhone'] ?? null) && trim((string) $sale['CustomerPhone']) !== ''): ?>
                                                    <span class="d-block small text-secondary"><?= htmlspecialchars((string) $sale['CustomerPhone'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($sale['items'] === []): ?>
                                                    <span class="text-secondary">Items unavailable</span>
                                                <?php else: ?>
                                                    <ul class="orders-item-list">
                                                        <?php foreach ($sale['items'] as $saleItem): ?>
                                                            <li>
                                                                <?= htmlspecialchars((string) $saleItem['ProductName'], ENT_QUOTES, 'UTF-8') ?>
                                                                × <?= htmlspecialchars((string) $saleItem['Quantity'], ENT_QUOTES, 'UTF-8') ?>
                                                                <span class="text-secondary">(₱<?= htmlspecialchars(number_format((float) $saleItem['LineTotal'], 2), ENT_QUOTES, 'UTF-8') ?>)</span>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-nowrap">₱<?= htmlspecialchars(number_format((float) $sale['Subtotal'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-nowrap">₱<?= htmlspecialchars(number_format((float) $sale['DiscountAmount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-nowrap fw-semibold">₱<?= htmlspecialchars(number_format((float) $sale['TotalAmount'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-nowrap"><?= htmlspecialchars(ucfirst((string) $sale['PaymentMethod']), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-nowrap">
                                                Paid: ₱<?= htmlspecialchars(number_format((float) $sale['AmountPaid'], 2), ENT_QUOTES, 'UTF-8') ?>
                                                <span class="d-block small text-secondary">Change: ₱<?= htmlspecialchars(number_format((float) $sale['ChangeAmount'], 2), ENT_QUOTES, 'UTF-8') ?></span>
                                            </td>
                                            <td class="text-nowrap"><?= htmlspecialchars((string) $sale['Status'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-nowrap" data-sale-view="walk-in">
                                                <button class="btn btn-sm btn-outline-primary print-sale-receipt" type="button" data-sale-id="<?= htmlspecialchars((string) $sale['SaleID'], ENT_QUOTES, 'UTF-8') ?>">
                                                    Print receipt
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="sales-source-no-matches" hidden>
                                        <td class="text-center text-secondary py-4" colspan="10" id="sales-source-no-matches-message">No completed sales in this category.</td>
                                    </tr>
                                </tbody>
                            </table>
                            <?php foreach ($salesRecords as $sale): ?>
                                <?php
                                    $cashierName = trim((string) $sale['CashierFirstName'] . ' ' . (string) $sale['CashierLastName']);
                                    $cashierDisplay = $cashierName !== '' ? $cashierName : (string) ($sale['CashierUsername'] ?? '');
                                ?>
                                <template id="sale-receipt-<?= htmlspecialchars((string) $sale['SaleID'], ENT_QUOTES, 'UTF-8') ?>">
                                    <article class="receipt">
                                        <header class="receipt-header">
                                            <h1>Flower Shop</h1>
                                            <p>Purchase Receipt</p>
                                        </header>
                                        <dl class="receipt-details">
                                            <div><dt>Sale</dt><dd>#<?= htmlspecialchars((string) $sale['SaleID'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Date</dt><dd><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $sale['SaleDate'])), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Cashier</dt><dd><?= htmlspecialchars($cashierDisplay !== '' ? $cashierDisplay : 'User #' . (string) $sale['UserID'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Order type</dt><dd><?= htmlspecialchars(ucfirst((string) $sale['OrderType']), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <?php if ($sale['OrderType'] === 'delivery' && is_string($sale['RecipientName']) && $sale['RecipientName'] !== ''): ?>
                                                <div><dt>Recipient</dt><dd><?= htmlspecialchars($sale['RecipientName'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <?php endif; ?>
                                            <?php if ($sale['OrderType'] === 'delivery' && is_string($sale['RecipientPhone']) && $sale['RecipientPhone'] !== ''): ?>
                                                <div><dt>Phone</dt><dd><?= htmlspecialchars($sale['RecipientPhone'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <?php endif; ?>
                                        </dl>
                                        <table class="receipt-items">
                                            <thead>
                                                <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($sale['items'] as $saleItem): ?>
                                                    <tr>
                                                        <td>
                                                            <?= htmlspecialchars((string) $saleItem['ProductName'], ENT_QUOTES, 'UTF-8') ?>
                                                            <?php
                                                                $receiptCustomData = json_decode((string) ($saleItem['CustomComponents'] ?? ''), true);
                                                                $receiptComponents = is_array($receiptCustomData) && isset($receiptCustomData['components']) && is_array($receiptCustomData['components'])
                                                                    ? $receiptCustomData['components']
                                                                    : [];
                                                            ?>
                                                            <?php if ($receiptComponents !== []): ?>
                                                                <ul class="receipt-component-list">
                                                                    <?php foreach ($receiptComponents as $receiptComponent): ?>
                                                                        <?php if (is_array($receiptComponent) && isset($receiptComponent['name'], $receiptComponent['quantity'])): ?>
                                                                            <li><?= htmlspecialchars((string) $receiptComponent['name'], ENT_QUOTES, 'UTF-8') ?> × <?= htmlspecialchars((string) $receiptComponent['quantity'], ENT_QUOTES, 'UTF-8') ?></li>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars((string) $saleItem['Quantity'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td>₱<?= htmlspecialchars(number_format((float) $saleItem['UnitPrice'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td>₱<?= htmlspecialchars(number_format((float) $saleItem['LineTotal'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if ($sale['items'] === []): ?>
                                                    <tr><td colspan="4">Sale item details are unavailable.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                        <dl class="receipt-totals">
                                            <div><dt>Subtotal</dt><dd>₱<?= htmlspecialchars(number_format((float) $sale['Subtotal'], 2), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Discount</dt><dd>₱<?= htmlspecialchars(number_format((float) $sale['DiscountAmount'], 2), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div class="receipt-grand-total"><dt>Total</dt><dd>₱<?= htmlspecialchars(number_format((float) $sale['TotalAmount'], 2), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Payment</dt><dd><?= htmlspecialchars(ucfirst((string) $sale['PaymentMethod']), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Paid</dt><dd>₱<?= htmlspecialchars(number_format((float) $sale['AmountPaid'], 2), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                            <div><dt>Change</dt><dd>₱<?= htmlspecialchars(number_format((float) $sale['ChangeAmount'], 2), ENT_QUOTES, 'UTF-8') ?></dd></div>
                                        </dl>
                                        <p class="receipt-thanks">Thank you for your purchase!</p>
                                    </article>
                                </template>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <div class="d-flex justify-content-end mt-3">
                            <p class="fw-semibold mb-0 sales-source-total" data-sale-source-total="walk-in">Total Walk-In Sales: <span data-sale-total-amount="walk-in">₱<?= htmlspecialchars(number_format($walkInSalesTotal, 2), ENT_QUOTES, 'UTF-8') ?></span></p>
                            <p class="fw-semibold mb-0 sales-source-total" data-sale-source-total="delivery" hidden>Total Delivery Sales: <span data-sale-total-amount="delivery">₱<?= htmlspecialchars(number_format($deliverySalesTotal, 2), ENT_QUOTES, 'UTF-8') ?></span></p>
                        </div>
                    <?php endif; ?>
                </section>
                <?php if ($isAdmin): ?>
                    <?php
                        $supplierFormIsUpdate = $supplierUpdateError !== '';
                        $supplierFormHasError = $supplierFormIsUpdate || $supplierCreateError !== '';
                        $supplierFormItemInput = $_POST['inventory_item_ids'] ?? [];
                        $supplierFormItemIds = is_array($supplierFormItemInput)
                            ? array_map('strval', array_filter($supplierFormItemInput, static fn ($itemId): bool => is_string($itemId) && ctype_digit($itemId)))
                            : [];
                    ?>
                    <section class="suppliers-panel p-4" id="suppliers-panel" aria-label="Suppliers" <?= $showSuppliersTab ? '' : 'hidden' ?>>
                        <?php if ($supplierNotice !== ''): ?>
                            <div class="alert alert-success" role="status"><?= htmlspecialchars($supplierNotice, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php foreach ([$supplierCreateError, $supplierUpdateError, $supplierDeleteError] as $supplierError): ?>
                            <?php if ($supplierError !== ''): ?>
                                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($supplierError, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($supplierLoadError !== ''): ?>
                            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($supplierLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php else: ?>
                            <div class="suppliers-heading">
                                <div>
                                    <h3 class="h4 mb-1">Supplier directory</h3>
                                    <p class="text-secondary mb-0">Manage suppliers and the inventory items they provide.</p>
                                </div>
                            </div>
                            <?php if ($suppliers === []): ?>
                                <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary mt-3">
                                    <p class="mb-0">No suppliers have been added yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="supplier-card-grid mt-3">
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <?php
                                            $supplierItemIds = array_map(
                                                static fn (array $item): string => (string) $item['ItemID'],
                                                $supplier['Items']
                                            );
                                        ?>
                                        <article class="supplier-card">
                                            <div class="supplier-card-heading">
                                                <div>
                                                    <h4><?= htmlspecialchars($supplier['SupplierName'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                    <span class="supplier-card-id">Supplier #<?= htmlspecialchars((string) $supplier['SupplierID'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                                <div class="supplier-card-actions">
                                                    <button
                                                        class="btn btn-sm btn-outline-primary edit-supplier-button"
                                                        type="button"
                                                        data-supplier-id="<?= htmlspecialchars((string) $supplier['SupplierID'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-supplier-name="<?= htmlspecialchars($supplier['SupplierName'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-contact-name="<?= htmlspecialchars($supplier['ContactName'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-phone="<?= htmlspecialchars($supplier['Phone'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-email="<?= htmlspecialchars($supplier['Email'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-address="<?= htmlspecialchars($supplier['Address'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-item-ids="<?= htmlspecialchars(implode(',', $supplierItemIds), ENT_QUOTES, 'UTF-8') ?>">
                                                        Edit
                                                    </button>
                                                    <form class="delete-supplier-form" method="post" action="index.php?tab=suppliers">
                                                        <input type="hidden" name="action" value="delete_supplier">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="supplier_id" value="<?= htmlspecialchars((string) $supplier['SupplierID'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <button class="btn btn-sm btn-outline-danger" type="submit" data-supplier-name="<?= htmlspecialchars($supplier['SupplierName'], ENT_QUOTES, 'UTF-8') ?>">Delete</button>
                                                    </form>
                                                </div>
                                            </div>
                                            <?php if ($supplier['ContactName'] !== ''): ?>
                                                <p class="supplier-card-detail"><strong>Contact:</strong> <?= htmlspecialchars($supplier['ContactName'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                            <?php if ($supplier['Phone'] !== ''): ?>
                                                <p class="supplier-card-detail"><strong>Phone:</strong> <?= htmlspecialchars($supplier['Phone'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                            <?php if ($supplier['Email'] !== ''): ?>
                                                <p class="supplier-card-detail"><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($supplier['Email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($supplier['Email'], ENT_QUOTES, 'UTF-8') ?></a></p>
                                            <?php endif; ?>
                                            <?php if ($supplier['Address'] !== ''): ?>
                                                <p class="supplier-card-detail"><strong>Address:</strong> <?= htmlspecialchars($supplier['Address'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <?php endif; ?>
                                            <div class="supplier-items">
                                                <strong>Supplied inventory</strong>
                                                <?php if ($supplier['Items'] === []): ?>
                                                    <p class="text-secondary mb-0">No inventory items assigned.</p>
                                                <?php else: ?>
                                                    <ul>
                                                        <?php foreach ($supplier['Items'] as $supplierItem): ?>
                                                            <li><?= htmlspecialchars($supplierItem['ItemName'], ENT_QUOTES, 'UTF-8') ?> <span><?= htmlspecialchars($supplierItem['Category'], ENT_QUOTES, 'UTF-8') ?></span></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="supplier-panel-actions">
                            <button class="add-user-button supplier-add-button btn" id="open-add-supplier" type="button" aria-label="Add supplier" title="Add supplier">
                                <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 5v14"></path>
                                    <path d="M5 12h14"></path>
                                </svg>
                            </button>
                            <button class="add-user-button supplier-transactions-button btn" id="view-supplier-transactions" type="button" aria-label="View supplier transactions" title="View transactions">
                                <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M8 3h8l4 4v14H4V3h4Z"></path>
                                    <path d="M8 11h8M8 15h8M8 7h.01"></path>
                                </svg>
                            </button>
                        </div>
                        <dialog class="add-user-dialog supplier-dialog border-0 rounded-3 shadow p-4" id="supplier-dialog" aria-labelledby="supplier-dialog-title" <?= $supplierCreateError !== '' || $supplierUpdateError !== '' ? 'open' : '' ?>>
                            <form class="add-user-form" id="supplier-form" method="post" action="index.php?tab=suppliers">
                                <h2 class="h4 mb-3" id="supplier-dialog-title"><?= $supplierFormIsUpdate ? 'Edit supplier' : 'Add supplier' ?></h2>
                                <input type="hidden" name="action" id="supplier-form-action" value="<?= $supplierFormIsUpdate ? 'update_supplier' : 'create_supplier' ?>">
                                <input type="hidden" name="supplier_id" id="supplier-form-id" value="<?= htmlspecialchars($supplierFormIsUpdate && isset($_POST['supplier_id']) && is_string($_POST['supplier_id']) ? $_POST['supplier_id'] : '', ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="supplier-name">Supplier name</label>
                                    <input class="form-control" id="supplier-name" name="supplier_name" type="text" maxlength="100" value="<?= htmlspecialchars($supplierFormHasError && isset($_POST['supplier_name']) && is_string($_POST['supplier_name']) ? $_POST['supplier_name'] : '', ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="supplier-contact-name">Contact person <span class="text-secondary">(optional)</span></label>
                                    <input class="form-control" id="supplier-contact-name" name="contact_name" type="text" maxlength="100" value="<?= htmlspecialchars($supplierFormHasError && isset($_POST['contact_name']) && is_string($_POST['contact_name']) ? $_POST['contact_name'] : '', ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-sm-6">
                                        <label class="form-label" for="supplier-phone">Phone</label>
                                        <input class="form-control" id="supplier-phone" name="phone" type="tel" maxlength="40" value="<?= htmlspecialchars($supplierFormHasError && isset($_POST['phone']) && is_string($_POST['phone']) ? $_POST['phone'] : '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="supplier-email">Email</label>
                                        <input class="form-control" id="supplier-email" name="email" type="email" maxlength="254" value="<?= htmlspecialchars($supplierFormHasError && isset($_POST['email']) && is_string($_POST['email']) ? $_POST['email'] : '', ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="supplier-address">Address</label>
                                    <textarea class="form-control" id="supplier-address" name="address" rows="2" maxlength="255"><?= htmlspecialchars($supplierFormHasError && isset($_POST['address']) && is_string($_POST['address']) ? $_POST['address'] : '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                                <fieldset class="mb-3">
                                    <legend class="form-label">Inventory items supplied <span class="text-secondary">(optional)</span></legend>
                                    <?php if ($inventoryItems === []): ?>
                                        <p class="form-text">Add inventory items before assigning them to suppliers.</p>
                                    <?php else: ?>
                                        <div class="supplier-inventory-picker">
                                            <?php foreach ($inventoryItems as $inventoryItem): ?>
                                                <?php
                                                    $inventoryItemId = (string) $inventoryItem['ItemID'];
                                                    $isSupplierItemSelected = $supplierFormHasError
                                                        && in_array($inventoryItemId, $supplierFormItemIds, true);
                                                ?>
                                                <label class="supplier-inventory-option">
                                                    <input type="checkbox" name="inventory_item_ids[]" value="<?= htmlspecialchars($inventoryItemId, ENT_QUOTES, 'UTF-8') ?>" <?= $isSupplierItemSelected ? 'checked' : '' ?>>
                                                    <span><?= htmlspecialchars((string) $inventoryItem['ItemName'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <small><?= htmlspecialchars((string) $inventoryItem['Category'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) $inventoryItem['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</small>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </fieldset>
                                <div class="d-flex justify-content-end gap-2">
                                    <button class="btn btn-outline-secondary" id="cancel-supplier" type="button">Cancel</button>
                                    <button class="btn btn-primary" id="save-supplier" type="submit"><?= $supplierFormIsUpdate ? 'Save changes' : 'Add supplier' ?></button>
                                </div>
                            </form>
                        </dialog>
                        <dialog class="supplier-transactions-dialog border-0 rounded-3 shadow p-4" id="supplier-transactions-dialog" aria-labelledby="supplier-transactions-title">
                            <div>
                                <h2 class="h4 mb-3" id="supplier-transactions-title">Supplier transactions</h2>
                                <?php if ($supplierTransactionsLoadError !== ''): ?>
                                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($supplierTransactionsLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php elseif ($supplierStockTransactions === []): ?>
                                    <p class="text-secondary">No supplier stock transactions have been recorded yet.</p>
                                <?php else: ?>
                                    <div class="table-responsive supplier-transactions-table-wrap">
                                        <table class="table table-striped table-hover align-middle supplier-transactions-table">
                                            <thead>
                                                <tr>
                                                    <th scope="col">Date</th>
                                                    <th scope="col">Supplier</th>
                                                    <th scope="col">Item supplied</th>
                                                    <th scope="col">Quantity</th>
                                                    <th scope="col">Stock after</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($supplierStockTransactions as $supplierTransaction): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $supplierTransaction['ReceivedAt'])), ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $supplierTransaction['SupplierName'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $supplierTransaction['ItemName'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $supplierTransaction['Quantity'], ENT_QUOTES, 'UTF-8') ?></td>
                                                        <td><?= htmlspecialchars((string) $supplierTransaction['StockAfter'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex justify-content-end mt-3">
                                    <button class="btn btn-outline-secondary" id="close-supplier-transactions" type="button">Close</button>
                                </div>
                            </div>
                        </dialog>
                    </section>
                <?php endif; ?>
                <dialog class="add-user-dialog border-0 rounded-3 shadow p-4" id="delivery-status-confirm-dialog" aria-labelledby="delivery-status-confirm-title">
                    <div class="add-user-form">
                        <h2 class="h4 mb-2" id="delivery-status-confirm-title">Confirm delivery status</h2>
                        <p class="text-secondary" id="delivery-status-confirm-message"></p>
                        <div class="d-flex justify-content-end gap-2">
                            <button class="btn btn-outline-secondary" id="cancel-delivery-status-confirm" type="button">Cancel</button>
                            <button class="btn btn-primary" id="confirm-delivery-status-update" type="button">Continue</button>
                        </div>
                    </div>
                </dialog>
                <dialog class="add-user-dialog border-0 rounded-3 shadow p-4" id="delivery-failure-dialog" aria-labelledby="delivery-failure-title">
                    <form class="add-user-form" id="delivery-failure-form" method="post" action="index.php?tab=orders">
                        <h2 class="h4 mb-2" id="delivery-failure-title">Record delivery failure</h2>
                        <p class="text-secondary">Add a brief remark explaining why this order could not be delivered.</p>
                        <input type="hidden" name="action" value="update_delivery_status">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="sale_id" id="delivery-failure-sale-id">
                        <input type="hidden" name="delivery_status" value="Delivery failed">
                        <div class="mb-3">
                            <label class="form-label" for="delivery-failure-remarks">Remarks</label>
                            <textarea class="form-control" id="delivery-failure-remarks" name="failure_remarks" rows="4" maxlength="500" required></textarea>
                            <div class="form-text">Up to 500 characters.</div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button class="btn btn-outline-secondary" id="cancel-delivery-failure" type="button">Cancel</button>
                            <button class="btn btn-danger" type="submit">Save failed status</button>
                        </div>
                    </form>
                </dialog>
                <dialog class="add-user-dialog border-0 rounded-3 shadow p-4" id="delivery-cash-dialog" aria-labelledby="delivery-cash-title">
                    <form class="add-user-form" id="delivery-cash-form" method="post" action="index.php?tab=orders">
                        <h2 class="h4 mb-2" id="delivery-cash-title">Record cash collected</h2>
                        <p class="text-secondary">Enter the cash received from the customer for order <strong id="delivery-cash-order"></strong>. The amount must cover the total.</p>
                        <input type="hidden" name="action" value="update_delivery_status">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="sale_id" id="delivery-cash-sale-id">
                        <input type="hidden" name="delivery_status" value="Delivered">
                        <div class="mb-3">
                            <label class="form-label" for="delivery-cash-amount">Cash received</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input class="form-control" id="delivery-cash-amount" name="amount_received" type="number" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" required>
                            </div>
                            <div class="form-text">Order total: <span id="delivery-cash-total"></span>. Change due: <span id="delivery-cash-change">₱0.00</span>.</div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button class="btn btn-outline-secondary" id="cancel-delivery-cash" type="button">Cancel</button>
                            <button class="btn btn-success" type="submit">Record payment and deliver</button>
                        </div>
                    </form>
                </dialog>
                <section class="inventory-panel p-4" id="inventory-panel" aria-label="Inventory" <?= $showInventoryTab ? '' : 'hidden' ?>>
                    <?php if ($inventoryNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($inventoryNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($itemCreateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($itemCreateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($itemUpdateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($itemUpdateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($itemDeleteError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($itemDeleteError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($inventoryLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($inventoryLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($inventoryItems === []): ?>
                        <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary">
                            <p class="mb-0">No inventory items found.</p>
                        </div>
                    <?php else: ?>
                        <div class="inventory-card-grid">
                            <?php foreach ($inventoryItems as $item): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <article class="inventory-item-card"
                                    data-item-name="<?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-category="<?= htmlspecialchars((string) $item['Category'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-color="<?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-stock="<?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-item-id="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($imageUrl !== null): ?>
                                        <img class="inventory-item-image" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <div class="inventory-item-image inventory-item-image-placeholder" aria-label="No image available">
                                            <span>No image</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="inventory-item-card-body">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <h4 class="inventory-item-name"><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></h4>
                                            <span class="inventory-item-id">#<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <span class="inventory-item-category"><?= htmlspecialchars((string) $item['Category'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="inventory-item-color">Color: <?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <div class="inventory-item-details">
                                            <span class="inventory-item-price">Price: ₱<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="inventory-item-stock"><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> <?= $item['Category'] === 'Ribbon' ? 'yards' : 'in stock' ?></span>
                                        </div>
                                        <div class="inventory-item-actions">
                                            <?php if ($isAdmin): ?>
                                                <button class="btn btn-outline-primary btn-sm edit-inventory-item-button" type="button"
                                                    data-item-id="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-item-name="<?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-category="<?= htmlspecialchars((string) $item['Category'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-color="<?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-stock="<?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-suppliers="<?= htmlspecialchars((string) json_encode($suppliersByInventoryId[(int) $item['ItemID']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                                    Modify item
                                                </button>
                                                <form class="delete-inventory-item-form" method="post" action="index.php">
                                                    <input type="hidden" name="action" value="delete_inventory_item">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="item_id" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit" data-item-name="<?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?>">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="inventory-no-matches inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary" hidden>
                            <p class="mb-0">No matching inventory items found.</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <button class="add-user-button inventory-add-item-button btn" id="open-add-inventory-item" type="button" aria-label="Add item" title="Add item">
                            <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                        </button>
                    <?php endif; ?>
                </section>
                <section class="products-panel p-4" id="products-panel" aria-label="Products" <?= $showProducts ? '' : 'hidden' ?>>
                    <?php if ($productNotice !== ''): ?>
                        <div class="alert alert-success" role="status"><?= htmlspecialchars($productNotice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($productCreateError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($productCreateError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($productsLoadError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($productsLoadError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php elseif ($products === []): ?>
                        <div class="inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary">
                            <p class="mb-0">No products found. Create a product from your inventory items.</p>
                        </div>
                    <?php else: ?>
                        <div class="product-card-grid">
                            <?php foreach ($products as $product): ?>
                                <?php $imageUrl = getProductImageUrl((int) $product['ProductID']); ?>
                                <article class="inventory-item-card product-item-card"
                                    data-price="<?= htmlspecialchars((string) $product['SalePrice'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-product-id="<?= htmlspecialchars((string) $product['ProductID'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($imageUrl !== null): ?>
                                        <img class="inventory-item-image" src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                        <div class="inventory-item-image inventory-item-image-placeholder" role="img" aria-label="No image available">
                                            <span>No image</span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="inventory-item-card-body">
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <h3 class="inventory-item-name"><?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="inventory-item-id">#<?= htmlspecialchars((string) $product['ProductID'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <span class="inventory-item-category">Product</span>
                                        <?php if (trim((string) ($product['Description'] ?? '')) !== ''): ?>
                                            <p class="text-secondary small my-2"><?= nl2br(htmlspecialchars((string) $product['Description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                        <?php endif; ?>
                                        <?php if ($product['ProductCategories'] !== []): ?>
                                            <div class="d-flex flex-wrap gap-1 my-2">
                                                <?php foreach ($product['ProductCategories'] as $category): ?>
                                                    <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="product-item-components">
                                            <p><strong>Flowers:</strong> <?= htmlspecialchars((string) $product['Flower'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p><strong>Wrappers:</strong> <?= htmlspecialchars((string) $product['Wrapper'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p><strong>Container:</strong> <?= $product['Container'] === '' ? 'None' : htmlspecialchars((string) $product['Container'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p><strong>Ribbon:</strong> <?= htmlspecialchars((string) $product['Ribbon'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p><strong>Add-ons:</strong> <?= $product['Addons'] === '' ? 'None' : htmlspecialchars((string) $product['Addons'], ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <div class="inventory-item-details">
                                            <?php if ((float) $product['ProductDiscountPercent'] > 0): ?>
                                                <span class="inventory-item-price">₱<?= number_format((float) $product['SalePrice'], 2) ?> <del class="text-secondary small">₱<?= number_format((float) $product['Price'], 2) ?></del> <span class="badge text-bg-danger"><?= number_format((float) $product['ProductDiscountPercent'], 2) ?>% off</span></span>
                                            <?php else: ?>
                                                <span class="inventory-item-price">₱<?= number_format((float) $product['SalePrice'], 2) ?></span>
                                            <?php endif; ?>
                                            <span class="inventory-item-stock"><?= $product['ProductStock'] === null ? 'Stock not tracked' : htmlspecialchars((string) $product['ProductStock'], ENT_QUOTES, 'UTF-8') . ' available' ?></span>
                                        </div>
                                        <div class="inventory-item-actions">
                                            <?php if ($isAdmin): ?>
                                                <button class="btn btn-outline-primary btn-sm edit-product-button" type="button"
                                                    data-product-id="<?= htmlspecialchars((string) $product['ProductID'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-name="<?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-description="<?= htmlspecialchars((string) ($product['Description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-price="<?= htmlspecialchars((string) $product['Price'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-discount-percent="<?= htmlspecialchars((string) $product['ProductDiscountPercent'], ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-featured="<?= (int) $product['Featured'] ?>"
                                                    data-product-stock="<?= htmlspecialchars((string) ($product['ProductStock'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-categories="<?= htmlspecialchars((string) json_encode($product['ProductCategories'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-product-components="<?= htmlspecialchars((string) json_encode($productComponentsById[(int) $product['ProductID']] ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                                    Modify product
                                                </button>
                                                <form class="delete-product-form" method="post" action="index.php?tab=products">
                                                    <input type="hidden" name="action" value="delete_product">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="product_id" value="<?= htmlspecialchars((string) $product['ProductID'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button class="btn btn-outline-danger btn-sm" type="submit" data-product-name="<?= htmlspecialchars((string) $product['ProductName'], ENT_QUOTES, 'UTF-8') ?>">
                                                        Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <div class="product-no-matches inventory-empty-state bg-white rounded-3 shadow-sm text-center text-secondary" hidden>
                            <p class="mb-0">No matching products found.</p>
                        </div>
                    <?php endif; ?>
                    <?php if ($productDeleteError !== ''): ?>
                        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($productDeleteError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <button class="add-user-button product-add-button btn" id="open-add-product" type="button" aria-label="Create product" title="Create product">
                            <svg class="add-user-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                        </button>
                    <?php endif; ?>
                </section>
            </section>
        </div>
    </main>
    <?php if ($logoutSuccess): ?>
        <div class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background-color: rgba(0, 0, 0, 0.25); z-index: 1050;" role="status">
            <div class="bg-white text-dark rounded-3 shadow-sm px-4 py-3 text-center">
                Logout successful!
            </div>
        </div>
        <script>
            window.setTimeout(function () {
                window.location.href = 'LoginForm.php';
            }, 1500);
        </script>
    <?php else: ?>
    <dialog id="logout-dialog" class="border-0 rounded-3 shadow p-3">
        <form method="post" action="index.php" class="text-center">
            <p class="fs-6 mb-3">Are you sure you want to logout?</p>
            <input type="hidden" name="action" value="logout">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <button class="btn  border border-1 me-2" type="submit">Yes</button>
            <button class="btn border border-1" id="cancel-logout" type="button">No</button>
        </form>
    </dialog>
    <dialog id="add-user-dialog" class="add-user-dialog border-0 rounded-3 shadow p-4">
        <?php if (isset($_SESSION['pending_staff_create']) && is_array($_SESSION['pending_staff_create'])): ?>
            <form method="post" action="index.php" class="add-user-form">
                <h2 class="h4 mb-2">Verify email to add user</h2>
                <p class="small text-secondary">Enter the 6-digit PIN sent to <?= htmlspecialchars((string) $_SESSION['pending_staff_create']['email'], ENT_QUOTES, 'UTF-8') ?>.</p>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label" for="new-user-pin">Email verification PIN</label>
                    <input class="form-control" id="new-user-pin" name="pin" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                </div>
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button class="btn btn-outline-secondary" id="cancel-add-user" type="submit" name="action" value="cancel_create_user_pin">Cancel</button>
                    <button class="btn btn-primary" type="submit" name="action" value="verify_create_user_pin">Verify and create user</button>
                </div>
            </form>
        <?php else: ?>
        <form method="post" action="index.php" class="add-user-form">
            <h2 class="h4 mb-3">Add user</h2>
            <input type="hidden" name="action" value="create_user">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="create_token" value="<?= htmlspecialchars($userCreateToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label class="form-label" for="new-firstname">First name</label>
                <input class="form-control" id="new-firstname" name="firstname" type="text" maxlength="100" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-lastname">Last name</label>
                <input class="form-control" id="new-lastname" name="lastname" type="text" maxlength="100" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-username">Username</label>
                <input class="form-control" id="new-username" name="username" type="text" maxlength="100" autocomplete="username" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-email">Email</label>
                <input class="form-control" id="new-email" name="email" type="email" maxlength="254" autocomplete="email" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-password">Password</label>
                <div class="password-input-group">
                    <input class="form-control" id="new-password" name="password" type="password" autocomplete="new-password" required>
                    <button class="password-visibility-button" type="button" aria-label="Show password" aria-pressed="false" data-password-target="new-password">
                        <svg class="password-visibility-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-role">Role</label>
                <select class="form-select" id="new-role" name="role" required>
                    <option value="" selected disabled>Select a role</option>
                    <option value="Admin">Admin</option>
                    <option value="Staff">Staff</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new-access">Access</label>
                <select class="form-select" id="new-access" name="access" required>
                    <option value="" selected disabled>Select access status</option>
                    <option value="Enabled">Enabled</option>
                    <option value="Disabled">Disabled</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-add-user" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Add user</button>
            </div>
        </form>
        <?php endif; ?>
    </dialog>
    <dialog id="edit-user-dialog" class="add-user-dialog border-0 rounded-3 shadow p-4">
        <form method="post" action="index.php" class="add-user-form">
            <h2 class="h4 mb-3">Edit user</h2>
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="edit-user-id" name="user_id">
            <div class="mb-3">
                <label class="form-label" for="edit-firstname">First name</label>
                <input class="form-control" id="edit-firstname" name="firstname" type="text" maxlength="100" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-lastname">Last name</label>
                <input class="form-control" id="edit-lastname" name="lastname" type="text" maxlength="100" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-username">Username</label>
                <input class="form-control" id="edit-username" name="username" type="text" maxlength="100" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-email">Email</label>
                <input class="form-control" id="edit-email" name="email" type="email" maxlength="254" autocomplete="email" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-password">New password <span class="text-secondary">(leave blank to keep current)</span></label>
                <input class="form-control" id="edit-password" name="password" type="password" autocomplete="new-password">
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-role">Role</label>
                <select class="form-select" id="edit-role" name="role" required>
                    <option value="Admin">Admin</option>
                    <option value="Staff">Staff</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-access">Access</label>
                <select class="form-select" id="edit-access" name="access" required>
                    <option value="Enabled">Enabled</option>
                    <option value="Disabled">Disabled</option>
                </select>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-edit-user" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
        </form>
    </dialog>
    <dialog id="add-product-dialog" class="add-user-dialog add-product-dialog border-0 rounded-3 shadow p-4">
        <form method="post" action="index.php?tab=products" class="add-user-form add-product-form" enctype="multipart/form-data">
            <div class="product-form-heading">
                <h2 class="h4 mb-0" id="product-form-title"><?= $productFormSelections['mode'] === 'edit' ? 'Edit product' : 'Create product' ?></h2>
                <div class="product-basic-fields">
                    <div>
                        <label class="form-label" for="product-name">Product name</label>
                        <input class="form-control" id="product-name" name="product_name" type="text" maxlength="50" value="<?= htmlspecialchars($productFormSelections['product_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="product-description-field">
                        <label class="form-label" for="product-description">Description <span class="text-secondary">(optional)</span></label>
                        <textarea class="form-control" id="product-description" name="product_description" rows="4" maxlength="2000"><?= htmlspecialchars($productFormSelections['product_description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        <div class="form-text">Add details customers should know about this arrangement. Maximum 2,000 characters.</div>
                    </div>
                    <div>
                        <label class="form-label" for="product-image">Product image <span class="text-secondary">(optional)</span></label>
                        <input class="form-control" id="product-image" name="product_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
                        <div class="form-text">JPEG, PNG, GIF, or WebP; up to 5 MB. Leave empty to keep the current image when editing.</div>
                    </div>
                    <div>
                        <label class="form-label" for="product-price">Selling price (₱)</label>
                        <input class="form-control" id="product-price" name="product_price" type="number" min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($productFormSelections['product_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                        <div class="product-price-estimate">
                            <span>Selected inventory cost: <strong id="product-component-cost">₱0.00</strong></span>
                            <button class="btn btn-link btn-sm p-0" id="use-calculated-product-price" type="button" hidden>Use calculated price</button>
                        </div>
                    </div>
                    <div class="product-marketing-fields">
                        <label class="form-label" for="product-discount-percent">Discount (%)</label>
                        <input class="form-control" id="product-discount-percent" name="product_discount_percent" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars($productFormSelections['product_discount_percent'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="form-text">Set 0 for no discount, or up to 100% off.</div>
                    </div>
                    <?php if ($isAdmin): ?>
                        <div class="product-marketing-fields">
                            <label class="form-check">
                                <input class="form-check-input" id="product-featured" name="product_featured" type="checkbox" value="1" <?= $productFormSelections['product_featured'] ? 'checked' : '' ?>>
                                <span class="form-check-label">Feature this product on the shop carousel</span>
                            </label>
                            <div class="form-text">You can feature multiple products; each will appear on the shop carousel.</div>
                        </div>
                    <?php endif; ?>
                    <fieldset class="product-marketing-fields product-occasion-field">
                        <legend class="form-label">Occasions</legend>
                        <details class="product-occasion-dropdown">
                            <summary class="form-select" id="product-occasion-summary">Select occasions</summary>
                            <div class="product-occasion-options">
                                <?php foreach ($productCategoryOptions as $category): ?>
                                    <label class="form-check">
                                        <input class="form-check-input product-occasion-choice" type="checkbox" name="product_categories[]" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>" <?= in_array($category, $productFormSelections['product_categories'], true) ? 'checked' : '' ?>>
                                        <span class="form-check-label"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </fieldset>
                    <div class="product-stock-field">
                        <label class="form-label" for="product-stock">Available product stock</label>
                        <input class="form-control" id="product-stock" name="product_stock" type="number" min="0" max="2147483647" step="1" value="<?= htmlspecialchars($productFormSelections['product_stock'], ENT_QUOTES, 'UTF-8') ?>" required>
                        <div class="form-text">Adding finished products consumes the matching recipe items from inventory. This count decreases after each purchase.</div>
                    </div>
                </div>
            </div>
            <input type="hidden" name="action" id="product-form-action" value="<?= $productFormSelections['mode'] === 'edit' ? 'update_product' : 'create_product' ?>">
            <input type="hidden" name="product_id" id="product-form-id" value="<?= htmlspecialchars($productFormSelections['product_id'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

            <div class="product-component-builder">
                <div class="product-picker-column">
                    <div class="product-category-buttons" role="group" aria-label="Product component categories">
                        <button class="btn btn-outline-primary product-category-button" type="button" data-product-category="flowers" aria-pressed="false">Select flowers</button>
                        <button class="btn btn-outline-primary product-category-button" type="button" data-product-category="wrappers" aria-pressed="false">Select wrappers</button>
                        <button class="btn btn-outline-primary product-category-button" type="button" data-product-category="containers" aria-pressed="false">Select container</button>
                        <button class="btn btn-outline-primary product-category-button" type="button" data-product-category="ribbon" aria-pressed="false">Select ribbon</button>
                        <button class="btn btn-outline-primary product-category-button" type="button" data-product-category="addons" aria-pressed="false">Select add-ons</button>
                    </div>

                    <section class="product-category-panel" data-product-panel="flowers" aria-label="Select flowers" hidden>
                    <div class="product-category-heading">
                        <div>
                            <h3 class="h6 mb-1">Flowers</h3>
                            <p class="text-secondary small mb-0">Select one or more flower types.</p>
                        </div>
                    </div>
                    <div class="product-component-card-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php if ($item['Category'] === 'Flower'): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <label class="product-component-card">
                                    <input class="product-card-choice" type="checkbox" name="flower_ids[]" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>" data-unit-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>" data-quantity-field="flower_quantities" data-quantity="<?= htmlspecialchars((string) ($productFormSelections['quantities'][(int) $item['ItemID']] ?? 1), ENT_QUOTES, 'UTF-8') ?>" <?= in_array((int) $item['ItemID'], $productFormSelections['flower_ids'], true) ? 'checked' : '' ?>>
                                    <?php if ($imageUrl !== null): ?>
                                        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="product-component-image-placeholder">No image</span>
                                    <?php endif; ?>
                                    <span class="product-component-card-copy">
                                        <strong><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    </section>

                    <section class="product-category-panel" data-product-panel="wrappers" aria-label="Select wrappers" hidden>
                    <div class="product-category-heading">
                        <div>
                            <h3 class="h6 mb-1">Wrappers</h3>
                            <p class="text-secondary small mb-0">Select one or two wrapper types, or choose one container instead. You cannot combine them.</p>
                        </div>
                        <span class="text-secondary small" id="wrapper-selection-count" aria-live="polite">0 of 2 types</span>
                    </div>
                    <div class="product-component-card-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php if ($item['Category'] === 'Wrapper'): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <label class="product-component-card">
                                    <input class="product-card-choice wrapper-card-choice" type="checkbox" name="wrapper_ids[]" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>" data-unit-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>" data-quantity-field="wrapper_quantities" data-quantity="<?= htmlspecialchars((string) ($productFormSelections['quantities'][(int) $item['ItemID']] ?? 1), ENT_QUOTES, 'UTF-8') ?>" <?= in_array((int) $item['ItemID'], $productFormSelections['wrapper_ids'], true) ? 'checked' : '' ?>>
                                    <?php if ($imageUrl !== null): ?>
                                        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="product-component-image-placeholder">No image</span>
                                    <?php endif; ?>
                                    <span class="product-component-card-copy">
                                        <strong><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    </section>

                    <section class="product-category-panel" data-product-panel="containers" aria-label="Select a container" hidden>
                    <div class="product-category-heading">
                        <div>
                            <h3 class="h6 mb-1">Container</h3>
                            <p class="text-secondary small mb-0">Choose one container instead of wrappers.</p>
                        </div>
                        <span class="text-secondary small" id="container-selection-count" aria-live="polite">0 of 1 type</span>
                    </div>
                    <div class="product-component-card-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php if (strcasecmp(trim((string) $item['Category']), 'Container') === 0): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <label class="product-component-card">
                                    <input class="product-card-choice container-card-choice" type="checkbox" name="container_ids[]" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>" data-unit-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>" data-quantity-field="container_quantities" data-quantity="<?= htmlspecialchars((string) ($productFormSelections['quantities'][(int) $item['ItemID']] ?? 1), ENT_QUOTES, 'UTF-8') ?>" <?= in_array((int) $item['ItemID'], $productFormSelections['container_ids'], true) ? 'checked' : '' ?>>
                                    <?php if ($imageUrl !== null): ?>
                                        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="product-component-image-placeholder">No image</span>
                                    <?php endif; ?>
                                    <span class="product-component-card-copy">
                                        <strong><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    </section>

                    <section class="product-category-panel" data-product-panel="ribbon" aria-label="Select ribbon" hidden>
                    <div class="product-category-heading">
                        <div>
                            <h3 class="h6 mb-1">Ribbon</h3>
                            <p class="text-secondary small mb-0">Choose one ribbon. You can adjust the yards in the selected items panel.</p>
                        </div>
                    </div>
                    <div class="product-component-card-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php if ($item['Category'] === 'Ribbon'): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <label class="product-component-card">
                                    <input class="product-card-choice" type="radio" name="ribbon_id" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>" data-unit-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>" data-quantity-field="ribbon_quantities" data-quantity="<?= htmlspecialchars((string) ($productFormSelections['quantities'][(int) $item['ItemID']] ?? 1), ENT_QUOTES, 'UTF-8') ?>" <?= $productFormSelections['ribbon_id'] === (string) $item['ItemID'] ? 'checked' : '' ?>>
                                    <?php if ($imageUrl !== null): ?>
                                        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="product-component-image-placeholder">No image</span>
                                    <?php endif; ?>
                                    <span class="product-component-card-copy">
                                        <strong><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> yards in stock</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    </section>

                    <section class="product-category-panel" data-product-panel="addons" aria-label="Select add-ons" hidden>
                    <div class="product-category-heading">
                        <div>
                            <h3 class="h6 mb-1">Add-ons</h3>
                            <p class="text-secondary small mb-0">Optional: select any add-ons. Adjust quantity in the selected items panel.</p>
                        </div>
                    </div>
                    <div class="product-component-card-grid">
                        <?php foreach ($inventoryItems as $item): ?>
                            <?php if ($item['Category'] === 'Addons'): ?>
                                <?php $imageUrl = getInventoryImageUrl((int) $item['ItemID']); ?>
                                <label class="product-component-card">
                                    <input class="product-card-choice addon-card-choice" type="checkbox" name="addon_ids[]" value="<?= htmlspecialchars((string) $item['ItemID'], ENT_QUOTES, 'UTF-8') ?>" data-unit-price="<?= htmlspecialchars((string) $item['Price'], ENT_QUOTES, 'UTF-8') ?>" data-quantity-field="addon_quantities" data-quantity="<?= htmlspecialchars((string) ($productFormSelections['quantities'][(int) $item['ItemID']] ?? 1), ENT_QUOTES, 'UTF-8') ?>" <?= in_array((int) $item['ItemID'], $productFormSelections['addon_ids'], true) ? 'checked' : '' ?>>
                                    <?php if ($imageUrl !== null): ?>
                                        <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="product-component-image-placeholder">No image</span>
                                    <?php endif; ?>
                                    <span class="product-component-card-copy">
                                        <strong><?= htmlspecialchars((string) $item['ItemName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span><?= htmlspecialchars((string) $item['Color'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars((string) $item['Stock'], ENT_QUOTES, 'UTF-8') ?> in stock</span>
                                    </span>
                                </label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    </section>
                </div>

                <aside class="product-selected-items" aria-label="Selected product items">
                    <div class="product-selected-heading">
                        <div>
                            <h3 class="h6 mb-1">Selected items</h3>
                            <p class="text-secondary small mb-0">Your product components</p>
                        </div>
                        <span class="product-selected-count" id="product-selected-count" aria-live="polite">0</span>
                    </div>
                    <div class="product-selected-empty" id="product-selected-empty">Your selected flowers, wrappers or container, ribbon, and add-ons will appear here.</div>
                    <div class="product-selected-groups" id="product-selected-groups"></div>
                </aside>
            </div>

            <div class="product-form-footer">
                <p class="form-text mb-0">The calculated amount is component cost only and does not include markup. Adjust the selling price as needed. Inventory is not deducted until a sale is completed.</p>
                <div class="alert alert-danger mb-0" id="product-selection-error" role="alert" hidden></div>
                <?php if ($productCreateError !== ''): ?>
                    <div class="alert alert-danger mb-0" role="alert"><?= htmlspecialchars($productCreateError, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-outline-secondary" id="cancel-add-product" type="button">Cancel</button>
                    <button class="btn btn-primary" id="save-product-button" type="submit"><?= $productFormSelections['mode'] === 'edit' ? 'Save changes' : 'Create product' ?></button>
                </div>
            </div>
        </form>
    </dialog>
    <dialog id="add-inventory-item-dialog" class="add-user-dialog add-inventory-item-dialog border-0 rounded-3 shadow p-4">
        <form method="post" action="index.php" class="add-user-form add-inventory-item-form" enctype="multipart/form-data">
            <h2 class="h4 mb-3">Add inventory item</h2>
            <input type="hidden" name="action" value="create_inventory_item">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <div class="mb-3">
                <label class="form-label" for="item-name">Item name</label>
                <input class="form-control" id="item-name" name="item_name" type="text" maxlength="50" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-category">Category</label>
                <select class="form-select" id="item-category" name="category" required>
                    <option value="" selected disabled>Select a category</option>
                    <option value="Flower">Flower</option>
                    <option value="Wrapper">Wrapper</option>
                    <option value="Ribbon">Ribbon</option>
                    <option value="Addons">Addons</option>
                    <option value="Container">Container</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-color-choice">Color</label>
                <select class="form-select" id="item-color-choice" name="color_choice">
                    <option value="" selected>Select a color or enter one below</option>
                    <?php foreach ($inventoryColorOptions as $colorOption): ?>
                        <option value="<?= htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-custom-color">Custom color <span class="text-secondary">(optional)</span></label>
                <input class="form-control" id="item-custom-color" name="custom_color" type="text" autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-price">Price <span class="text-secondary">(whole number)</span></label>
                <input class="form-control" id="item-price" name="price" type="text" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-stock">Stock</label>
                <input class="form-control" id="item-stock" name="stock" type="number" min="0" step="1" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="item-image">Picture <span class="text-secondary">(optional; JPEG, PNG, GIF, or WebP, up to 5 MB)</span></label>
                <input class="form-control" id="item-image" name="item_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-add-inventory-item" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Add item</button>
            </div>
        </form>
    </dialog>
    <dialog id="edit-inventory-item-dialog" class="add-user-dialog border-0 rounded-3 shadow p-4">
        <form method="post" action="index.php" class="add-user-form" enctype="multipart/form-data">
            <h2 class="h4 mb-3">Modify inventory item</h2>
            <input type="hidden" name="action" value="update_inventory_item">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="edit-item-id" name="item_id" value="<?= htmlspecialchars((string) ($editInventoryItem['ItemID'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" id="edit-stock-supplier-id" name="stock_supplier_id" value="">
            <div class="mb-3">
                <label class="form-label" for="edit-item-name">Item name</label>
                <input class="form-control" id="edit-item-name" name="item_name" type="text" maxlength="50" value="<?= htmlspecialchars((string) ($editInventoryItem['ItemName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-item-category">Category</label>
                <?php $editCategory = $editInventoryItem['Category'] ?? ''; ?>
                <select class="form-select" id="edit-item-category" name="category" required>
                    <option value="" disabled <?= $editCategory === '' ? 'selected' : '' ?>>Select a category</option>
                    <option value="Flower" <?= $editCategory === 'Flower' ? 'selected' : '' ?>>Flower</option>
                    <option value="Wrapper" <?= $editCategory === 'Wrapper' ? 'selected' : '' ?>>Wrapper</option>
                    <option value="Ribbon" <?= $editCategory === 'Ribbon' ? 'selected' : '' ?>>Ribbon</option>
                    <option value="Addons" <?= $editCategory === 'Addons' ? 'selected' : '' ?>>Addons</option>
                    <option value="Container" <?= $editCategory === 'Container' ? 'selected' : '' ?>>Container</option>
                </select>
            </div>
            <?php
                $editColor = (string) ($editInventoryItem['Color'] ?? '');
                $editColorIsStandard = in_array($editColor, $inventoryColorOptions, true);
            ?>
            <div class="mb-3">
                <label class="form-label" for="edit-item-color-choice">Color</label>
                <select class="form-select" id="edit-item-color-choice" name="color_choice">
                    <option value="" <?= !$editColorIsStandard ? 'selected' : '' ?>>Select a color or enter one below</option>
                    <?php foreach ($inventoryColorOptions as $colorOption): ?>
                        <option value="<?= htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8') ?>" <?= $editColorIsStandard && $editColor === $colorOption ? 'selected' : '' ?>><?= htmlspecialchars($colorOption, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-item-custom-color">Custom color <span class="text-secondary">(optional)</span></label>
                <input class="form-control" id="edit-item-custom-color" name="custom_color" type="text" autocomplete="off" value="<?= htmlspecialchars($editColorIsStandard ? '' : $editColor, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-item-price">Price <span class="text-secondary">(whole number)</span></label>
                <input class="form-control" id="edit-item-price" name="price" type="text" inputmode="numeric" pattern="[0-9]{1,20}" maxlength="20" value="<?= htmlspecialchars((string) ($editInventoryItem['Price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-item-stock">Stock</label>
                <input class="form-control" id="edit-item-stock" name="stock" type="number" min="0" step="1" value="<?= htmlspecialchars((string) ($editInventoryItem['Stock'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="edit-item-image">Replace picture <span class="text-secondary">(optional; JPEG, PNG, GIF, or WebP, up to 5 MB)</span></label>
                <input class="form-control" id="edit-item-image" name="item_image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
            <?php if ($itemUpdateError !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($itemUpdateError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-edit-inventory-item" type="button">Cancel</button>
                <button class="btn btn-primary" type="submit">Save changes</button>
            </div>
        </form>
    </dialog>
    <dialog class="add-user-dialog stock-supplier-dialog border-0 rounded-3 shadow p-4" id="stock-supplier-dialog" aria-labelledby="stock-supplier-title">
        <div>
            <h2 class="h4 mb-2" id="stock-supplier-title">Suppliers for this item</h2>
            <p class="text-secondary" id="stock-supplier-item-name"></p>
            <p class="form-text" id="stock-supplier-instructions">Select the supplier for this stock increase.</p>
            <div id="stock-supplier-list" class="stock-supplier-list" aria-live="polite"></div>
            <div class="d-flex justify-content-end gap-2 mt-4">
                <button class="btn btn-outline-secondary" id="cancel-stock-increase" type="button">Back</button>
                <button class="btn btn-primary" id="confirm-stock-increase" type="button" disabled>Continue</button>
            </div>
        </div>
    </dialog>
    <script>
        const currentDate = document.getElementById("current-date");
        const currentTime = document.getElementById("current-time");

        function updateClock() {
            const now = new Date();
            currentDate.dateTime = now.toISOString();
            currentDate.textContent = new Intl.DateTimeFormat(undefined, {
                weekday: "long",
                year: "numeric",
                month: "long",
                day: "numeric"
            }).format(now);
            currentTime.textContent = new Intl.DateTimeFormat(undefined, {
                hour: "numeric",
                minute: "2-digit",
                second: "2-digit"
            }).format(now);
        }

        updateClock();
        window.setInterval(updateClock, 1000);

        document.addEventListener("click", function (event) {
            const printButton = event.target.closest(".print-sale-receipt");
            if (!printButton) {
                return;
            }

            const receiptTemplate = document.getElementById("sale-receipt-" + printButton.dataset.saleId);
            if (!receiptTemplate) {
                alert("Unable to find this sale's receipt.");
                return;
            }

            let printContainer = document.getElementById("sale-receipt-print-container");
            if (!printContainer) {
                printContainer = document.createElement("div");
                printContainer.id = "sale-receipt-print-container";
                printContainer.setAttribute("aria-hidden", "true");
                document.body.appendChild(printContainer);
            }
            printContainer.innerHTML = receiptTemplate.innerHTML;
            document.body.classList.add("printing-sale-receipt");
            window.print();
            document.body.classList.remove("printing-sale-receipt");
            printContainer.replaceChildren();
        });

        const navigationItems = document.querySelectorAll(".sidebar-navigation nav .nav-link");
        const pageTitle = document.getElementById("dashboard-page-title");
        const dashboardPanel = document.getElementById("dashboard-panel");
        const usersPanel = document.getElementById("users-panel");
        const posPanel = document.getElementById("pos-panel");
        const ordersPanel = document.getElementById("orders-panel");
        const salesPanel = document.getElementById("sales-panel");
        const suppliersPanel = document.getElementById("suppliers-panel");
        const inventoryPanel = document.getElementById("inventory-panel");
        const productsPanel = document.getElementById("products-panel");
        const usersSearchTools = document.getElementById("users-search-tools");
        const posSearchTools = document.getElementById("pos-search-tools");
        const ordersSearchTools = document.getElementById("orders-search-tools");
        const inventorySearchTools = document.getElementById("inventory-search-tools");
        const productsSearchTools = document.getElementById("products-search-tools");
        const productsPriceSort = document.getElementById("products-price-sort");
        const usersSearch = document.getElementById("users-search");
        const usersFilterField = document.getElementById("users-filter-field");
        const inventorySearch = document.getElementById("inventory-search");
        const inventoryFilterField = document.getElementById("inventory-filter-field");
        const productsSearch = document.getElementById("products-search");
        const posProductSearch = document.getElementById("pos-product-search");
        const posPriceSort = document.getElementById("pos-price-sort");
        const posColorFilter = document.getElementById("pos-color-filter");
        const inventoryCardGrid = inventoryPanel.querySelector(".inventory-card-grid");
        const usersTableBodies = [...document.querySelectorAll("#users-panel tbody[data-column-count]")];
        const userCategoryTabs = [...document.querySelectorAll("[data-user-category-tab]")];
        const userCategoryPanels = [...document.querySelectorAll("[data-user-category-panel]")];
        const addUserDialog = document.getElementById("add-user-dialog");
        const editUserDialog = document.getElementById("edit-user-dialog");

        const activateUserCategory = function (category) {
            userCategoryTabs.forEach(function (tab) {
                const isActive = tab.dataset.userCategoryTab === category;
                tab.classList.toggle("active", isActive);
                tab.setAttribute("aria-selected", String(isActive));
                tab.tabIndex = isActive ? 0 : -1;
            });
            userCategoryPanels.forEach(function (panel) {
                panel.hidden = panel.dataset.userCategoryPanel !== category;
            });
        };
        userCategoryTabs.forEach(function (tab, index) {
            tab.addEventListener("click", function () {
                activateUserCategory(tab.dataset.userCategoryTab);
            });
            tab.addEventListener("keydown", function (event) {
                if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
                event.preventDefault();
                const offset = event.key === "ArrowRight" ? 1 : -1;
                const nextTab = userCategoryTabs[(index + offset + userCategoryTabs.length) % userCategoryTabs.length];
                activateUserCategory(nextTab.dataset.userCategoryTab);
                nextTab.focus();
            });
        });

        navigationItems.forEach(function (item) {
            item.addEventListener("click", function () {
                navigationItems.forEach(function (navigationItem) {
                    navigationItem.classList.remove("active");
                    navigationItem.setAttribute("aria-pressed", "false");
                });

                item.classList.add("active");
                item.setAttribute("aria-pressed", "true");
                const selectedTab = item.dataset.tabName || item.textContent.trim();
                pageTitle.textContent = selectedTab;
                dashboardPanel.hidden = selectedTab !== "Dashboard";
                usersPanel.hidden = selectedTab !== "Users";
                posPanel.hidden = selectedTab !== "Point of Sale";
                ordersPanel.hidden = selectedTab !== "Orders";
                salesPanel.hidden = selectedTab !== "Sales";
                if (suppliersPanel) {
                    suppliersPanel.hidden = selectedTab !== "Suppliers";
                }
                inventoryPanel.hidden = selectedTab !== "Inventory";
                productsPanel.hidden = selectedTab !== "Products";
                usersSearchTools.hidden = selectedTab !== "Users";
                posSearchTools.hidden = selectedTab !== "Point of Sale";
                inventorySearchTools.hidden = selectedTab !== "Inventory";
                productsSearchTools.hidden = selectedTab !== "Products";
                ordersSearchTools.hidden = selectedTab !== "Orders";
            });
        });
        document.querySelectorAll(".dashboard-open-tab").forEach(function (button) {
            button.addEventListener("click", function () {
                const targetTab = button.dataset.dashboardTab;
                const targetNavigationItem = Array.from(navigationItems).find(function (item) {
                    return (item.dataset.tabName || item.textContent.trim()) === targetTab;
                });
                if (targetNavigationItem) {
                    targetNavigationItem.click();
                }
            });
        });

        const pendingCustomerOrderBadge = document.getElementById("pending-customer-order-badge");
        if (pendingCustomerOrderBadge) {
            let knownPendingCustomerOrders = Number(pendingCustomerOrderBadge.textContent) || 0;
            const customerOrderLiveNotice = document.getElementById("customer-order-live-notice");
            const dismissCustomerOrderNotice = customerOrderLiveNotice?.querySelector("[data-dismiss-customer-order-notice]");
            dismissCustomerOrderNotice?.addEventListener("click", function () {
                customerOrderLiveNotice.hidden = true;
            });
            window.setInterval(async function () {
                try {
                    const response = await fetch("index.php?customer_order_notifications=1", {
                        credentials: "same-origin",
                        cache: "no-store",
                        headers: {"Accept": "application/json"}
                    });
                    if (!response.ok) {
                        throw new Error("Order notification request failed with status " + response.status);
                    }
                    const notificationData = await response.json();
                    const pendingCount = notificationData.pending_customer_orders;
                    if (!Number.isInteger(pendingCount) || pendingCount < 0) {
                        throw new Error("Order notification response contained an invalid pending count");
                    }
                    pendingCustomerOrderBadge.textContent = String(pendingCount);
                    pendingCustomerOrderBadge.hidden = pendingCount === 0;
                    pendingCustomerOrderBadge.setAttribute(
                        "aria-label",
                        pendingCount + " customer orders awaiting review"
                    );
                    const receivedCount = notificationData.received_customer_orders;
                    if (!Number.isInteger(receivedCount) || receivedCount < 0) {
                        throw new Error("Order notification response contained an invalid received count");
                    }
                    const receivedBadge = document.getElementById("received-customer-order-badge");
                    if (receivedBadge) {
                        receivedBadge.textContent = String(receivedCount);
                        receivedBadge.hidden = receivedCount === 0;
                        receivedBadge.setAttribute(
                            "aria-label",
                            receivedCount + " customer orders confirmed received"
                        );
                    }
                    const pendingSummary = document.getElementById("pending-customer-order-summary");
                    if (pendingSummary) {
                        pendingSummary.hidden = pendingCount === 0;
                        document.getElementById("pending-customer-order-count").textContent = String(pendingCount);
                        document.getElementById("pending-customer-order-plural").textContent =
                            pendingCount === 1 ? "order is" : "orders are";
                    }
                    const receivedSummary = document.getElementById("received-customer-order-summary");
                    if (receivedSummary) {
                        receivedSummary.hidden = receivedCount === 0;
                        document.getElementById("received-customer-order-count").textContent = String(receivedCount);
                        document.getElementById("received-customer-order-plural").textContent =
                            receivedCount === 1 ? "order has" : "orders have";
                    }
                    if (pendingCount > knownPendingCustomerOrders && customerOrderLiveNotice) {
                        customerOrderLiveNotice.hidden = false;
                    }
                    knownPendingCustomerOrders = pendingCount;
                } catch (error) {
                    console.error("Unable to refresh customer order notifications.", error);
                }
            }, 30000);
        }

        const supplierDialog = document.getElementById("supplier-dialog");
        const supplierForm = document.getElementById("supplier-form");
        const supplierFormAction = document.getElementById("supplier-form-action");
        const supplierFormId = document.getElementById("supplier-form-id");
        if (supplierDialog && supplierForm && supplierFormAction && supplierFormId) {
            const supplierDialogTitle = document.getElementById("supplier-dialog-title");
            const supplierSaveButton = document.getElementById("save-supplier");
            const supplierItemChoices = supplierForm.querySelectorAll('input[name="inventory_item_ids[]"]');

            const openAddSupplierButton = document.getElementById("open-add-supplier");
            if (openAddSupplierButton) {
                openAddSupplierButton.addEventListener("click", function () {
                    supplierForm.reset();
                    supplierFormAction.value = "create_supplier";
                    supplierFormId.value = "";
                    supplierDialogTitle.textContent = "Add supplier";
                    supplierSaveButton.textContent = "Add supplier";
                    if (supplierDialog.open) {
                        supplierDialog.close();
                    }
                    supplierDialog.showModal();
                });
            }

            document.querySelectorAll(".edit-supplier-button").forEach(function (button) {
                button.addEventListener("click", function () {
                    document.getElementById("supplier-name").value = button.dataset.supplierName || "";
                    document.getElementById("supplier-contact-name").value = button.dataset.contactName || "";
                    document.getElementById("supplier-phone").value = button.dataset.phone || "";
                    document.getElementById("supplier-email").value = button.dataset.email || "";
                    document.getElementById("supplier-address").value = button.dataset.address || "";
                    supplierFormAction.value = "update_supplier";
                    supplierFormId.value = button.dataset.supplierId || "";
                    supplierDialogTitle.textContent = "Edit supplier";
                    supplierSaveButton.textContent = "Save changes";
                    const selectedItemIds = new Set((button.dataset.itemIds || "").split(",").filter(Boolean));
                    supplierItemChoices.forEach(function (choice) {
                        choice.checked = selectedItemIds.has(choice.value);
                    });
                    if (supplierDialog.open) {
                        supplierDialog.close();
                    }
                    supplierDialog.showModal();
                });
            });

            document.getElementById("cancel-supplier").addEventListener("click", function () {
                supplierDialog.close();
            });
        }

        document.querySelectorAll(".delete-supplier-form").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                const supplierName = form.querySelector("button").dataset.supplierName || "this supplier";
                if (!window.confirm("Delete " + supplierName + "? Its supplied-item assignments will also be removed. This cannot be undone.")) {
                    event.preventDefault();
                }
            });
        });

        const ordersSearch = document.getElementById("orders-search");
        const ordersDateFrom = document.getElementById("orders-date-from");
        const ordersDateTo = document.getElementById("orders-date-to");
        const ordersStatusFilter = document.getElementById("orders-status-filter");
        const orderCategoryButtons = Array.from(document.querySelectorAll(".order-category-filter"));
        const orderSourceButtons = Array.from(document.querySelectorAll(".order-source-filter"));
        const customerShopOrderRows = Array.from(document.querySelectorAll(".customer-shop-order-row"));
        const deliveryOrderRows = Array.from(document.querySelectorAll(".delivery-order-row"));
        const categoryNoMatches = document.getElementById("orders-category-no-matches");
        if (orderCategoryButtons.length > 0) {
            let selectedOrderCategory = "all";
            let selectedOrderSource = "walk-in";
            function filterOrders() {
                const searchTerm = ordersSearch ? ordersSearch.value.trim().toLocaleLowerCase() : "";
                const dateFrom = ordersDateFrom ? ordersDateFrom.value : "";
                const dateTo = ordersDateTo ? ordersDateTo.value : "";
                const selectedStatus = ordersStatusFilter ? ordersStatusFilter.value : "all";
                const invalidDateRange = dateFrom !== "" && dateTo !== "" && dateFrom > dateTo;
                const filterError = document.getElementById("orders-filter-error");
                let visibleRows = 0;

                if (filterError) filterError.hidden = !invalidDateRange;
                customerShopOrderRows.forEach(function (row) {
                    const matchesCategory = selectedOrderCategory === "all"
                        || row.dataset.orderCategory === selectedOrderCategory;
                    const matchesSource = row.dataset.orderSource === selectedOrderSource;
                    const matchesSearch = row.textContent.toLocaleLowerCase().includes(searchTerm);
                    row.hidden = invalidDateRange || !matchesSource || !matchesCategory || !matchesSearch;
                    if (!row.hidden) visibleRows++;
                });
                deliveryOrderRows.forEach(function (row) {
                    const deliveryDate = row.dataset.deliveryDate || "";
                    const matchesCategory = selectedOrderCategory === "all"
                        || row.dataset.orderCategory === selectedOrderCategory;
                    const matchesSearch = row.textContent.toLocaleLowerCase().includes(searchTerm);
                    const matchesFrom = dateFrom === "" || deliveryDate >= dateFrom;
                    const matchesTo = dateTo === "" || deliveryDate <= dateTo;
                    const matchesStatus = selectedStatus === "all" || row.dataset.orderStatus === selectedStatus;
                    row.hidden = invalidDateRange || !matchesCategory || !matchesSearch
                        || !matchesFrom || !matchesTo || !matchesStatus;
                    if (!row.hidden) visibleRows++;
                });

                const noMatchesRow = document.getElementById("orders-no-matches");
                if (noMatchesRow) noMatchesRow.hidden = true;
                if (categoryNoMatches) {
                    const hasOrders = customerShopOrderRows.length + deliveryOrderRows.length > 0;
                    categoryNoMatches.hidden = invalidDateRange || visibleRows > 0
                        || (selectedOrderCategory === "all" && !hasOrders);
                }
                const customerEmpty = document.querySelector("[data-customer-orders-empty]");
                if (customerEmpty) customerEmpty.hidden = selectedOrderCategory !== "all";
                const deliveryEmpty = document.querySelector("[data-delivery-orders-empty]");
                if (deliveryEmpty) deliveryEmpty.hidden = selectedOrderCategory !== "all";
            }

            orderCategoryButtons.forEach(function (button) {
                button.addEventListener("click", function () {
                    selectedOrderCategory = button.dataset.orderCategory || "all";
                    orderCategoryButtons.forEach(function (categoryButton) {
                        const isSelected = categoryButton === button;
                        categoryButton.classList.toggle("active", isSelected);
                        categoryButton.setAttribute("aria-pressed", isSelected ? "true" : "false");
                    });
                    filterOrders();
                });
            });
            orderSourceButtons.forEach(function (button) {
                button.addEventListener("click", function () {
                    selectedOrderSource = button.dataset.orderSource || "walk-in";
                    orderSourceButtons.forEach(function (sourceButton) {
                        const isSelected = sourceButton === button;
                        sourceButton.classList.toggle("active", isSelected);
                        sourceButton.setAttribute("aria-pressed", isSelected ? "true" : "false");
                    });
                    filterOrders();
                });
            });
            [ordersSearch, ordersDateFrom, ordersDateTo, ordersStatusFilter].forEach(function (filter) {
                if (filter) {
                    filter.addEventListener(filter.type === "search" ? "input" : "change", filterOrders);
                }
            });
            filterOrders();
        }

        const salesSourceButtons = Array.from(document.querySelectorAll(".sales-source-filter"));
        const salesRecordRows = Array.from(document.querySelectorAll(".sales-record-row"));
        const salesSourceTotals = Array.from(document.querySelectorAll(".sales-source-total"));
        const salesDateFrom = document.getElementById("sales-date-from");
        const salesDateTo = document.getElementById("sales-date-to");
        const salesDatePresetButtons = Array.from(document.querySelectorAll(".sales-date-preset"));
        const salesDateFilterError = document.getElementById("sales-date-filter-error");
        if (salesSourceButtons.length > 0) {
            let selectedSalesSource = "walk-in";
            const salesCurrencyFormatter = new Intl.NumberFormat(undefined, {
                style: "currency",
                currency: "PHP"
            });

            function filterSales() {
                const dateFrom = salesDateFrom ? salesDateFrom.value : "";
                const dateTo = salesDateTo ? salesDateTo.value : "";
                const invalidDateRange = dateFrom !== "" && dateTo !== "" && dateFrom > dateTo;
                let visibleRows = 0;
                let filteredTotal = 0;
                document.querySelectorAll("[data-sale-view]").forEach(function (cell) {
                    cell.hidden = cell.dataset.saleView !== selectedSalesSource;
                });
                if (salesDateFilterError) {
                    salesDateFilterError.hidden = !invalidDateRange;
                }
                salesRecordRows.forEach(function (row) {
                    const saleDate = row.dataset.saleDate || "";
                    const matchesSource = row.dataset.saleSource === selectedSalesSource;
                    const matchesFrom = dateFrom === "" || saleDate >= dateFrom;
                    const matchesTo = dateTo === "" || saleDate <= dateTo;
                    row.hidden = invalidDateRange || !matchesSource || !matchesFrom || !matchesTo;
                    if (!row.hidden) {
                        visibleRows++;
                        filteredTotal += Number(row.dataset.saleTotal) || 0;
                    }
                });
                salesSourceTotals.forEach(function (total) {
                    const isSelected = total.dataset.saleSourceTotal === selectedSalesSource;
                    total.hidden = !isSelected;
                    if (isSelected) {
                        const amount = total.querySelector("[data-sale-total-amount]");
                        if (amount) {
                            amount.textContent = salesCurrencyFormatter.format(filteredTotal);
                        }
                    }
                });
                const noMatchesRow = document.getElementById("sales-source-no-matches");
                if (noMatchesRow) {
                    noMatchesRow.hidden = visibleRows > 0;
                    const message = document.getElementById("sales-source-no-matches-message");
                    if (message) {
                        message.textContent = invalidDateRange
                            ? "Start date must be on or before the end date."
                            : "No completed sales match this source and date range.";
                    }
                }
            }

            salesSourceButtons.forEach(function (button) {
                button.addEventListener("click", function () {
                    selectedSalesSource = button.dataset.saleSource || "walk-in";
                    salesSourceButtons.forEach(function (sourceButton) {
                        const isSelected = sourceButton === button;
                        sourceButton.classList.toggle("active", isSelected);
                        sourceButton.setAttribute("aria-pressed", isSelected ? "true" : "false");
                    });
                    filterSales();
                });
            });
            salesDatePresetButtons.forEach(function (button) {
                button.addEventListener("click", function () {
                    const preset = button.dataset.saleDatePreset || "all";
                    const today = new Date();
                    const formatLocalDate = function (date) {
                        return date.getFullYear() + "-"
                            + String(date.getMonth() + 1).padStart(2, "0") + "-"
                            + String(date.getDate()).padStart(2, "0");
                    };
                    let startDate = "";
                    let endDate = "";
                    if (preset === "today") {
                        startDate = formatLocalDate(today);
                        endDate = startDate;
                    } else if (preset === "week") {
                        const weekStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
                        weekStart.setDate(weekStart.getDate() - ((weekStart.getDay() + 6) % 7));
                        startDate = formatLocalDate(weekStart);
                        endDate = formatLocalDate(today);
                    } else if (preset === "month") {
                        startDate = formatLocalDate(new Date(today.getFullYear(), today.getMonth(), 1));
                        endDate = formatLocalDate(today);
                    }
                    if (salesDateFrom) salesDateFrom.value = startDate;
                    if (salesDateTo) salesDateTo.value = endDate;
                    salesDatePresetButtons.forEach(function (presetButton) {
                        const isSelected = presetButton === button;
                        presetButton.classList.toggle("active", isSelected);
                        presetButton.setAttribute("aria-pressed", isSelected ? "true" : "false");
                    });
                    filterSales();
                });
            });
            [salesDateFrom, salesDateTo].forEach(function (input) {
                if (input) {
                    input.addEventListener("change", function () {
                        salesDatePresetButtons.forEach(function (button) {
                            button.classList.remove("active");
                            button.setAttribute("aria-pressed", "false");
                        });
                        filterSales();
                    });
                }
            });
            filterSales();
        }

        const posOrderItems = document.getElementById("pos-order-items");
        const posOrderSubtotal = document.getElementById("pos-order-subtotal");
        const posOrderTotal = document.getElementById("pos-order-total");
        const posDiscountType = document.getElementById("pos-discount-type");
        const posDiscountRow = document.getElementById("pos-discount-row");
        const posDiscountLabel = document.getElementById("pos-discount-label");
        const posDiscountAmount = document.getElementById("pos-discount-amount");
        const posCheckoutStepLabel = document.getElementById("pos-checkout-step-label");
        const posCheckoutSteps = [
            document.getElementById("pos-checkout-step-1"),
            document.getElementById("pos-checkout-step-2"),
            document.getElementById("pos-checkout-step-3")
        ];
        const posContinueToPaymentButton = document.getElementById("pos-continue-to-payment");
        const posBackToOrderButton = document.getElementById("pos-back-to-order");
        const posReviewOrderButton = document.getElementById("pos-review-order");
        const posBackToPaymentButton = document.getElementById("pos-back-to-payment");
        const posCheckoutReview = document.getElementById("pos-checkout-review");
        const posCheckoutCart = document.getElementById("pos-checkout-cart");
        const posPaymentMethod = document.getElementById("pos-payment-method");
        const posCashPaymentField = document.getElementById("pos-cash-payment-field");
        const posCashAmount = document.getElementById("pos-cash-amount");
        const posCashTotalDue = document.getElementById("pos-cash-total-due");
        const posCashPaymentError = document.getElementById("pos-cash-payment-error");
        const posAddOnsDialog = document.getElementById("pos-add-ons-dialog");
        const posAddOnsButton = document.getElementById("pos-add-ons-button");
        const deliveryStatusConfirmDialog = document.getElementById("delivery-status-confirm-dialog");
        const deliveryFailureDialog = document.getElementById("delivery-failure-dialog");
        const deliveryFailureForm = document.getElementById("delivery-failure-form");
        const deliveryCashDialog = document.getElementById("delivery-cash-dialog");
        const deliveryCashForm = document.getElementById("delivery-cash-form");
        const deliveryCashAmount = document.getElementById("delivery-cash-amount");
        const posCart = new Map();
        let posCheckoutStep = 1;
        let posCurrentTotalCents = 0;
        let posCustomizationTargetKey = null;
        let posProductFormMode = "create";
        let posCustomItemSequence = 0;
        const posCurrencyFormatter = new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: "PHP"
        });

        function renderPosOrder() {
            posOrderItems.replaceChildren();
            let subtotal = 0;

            if (posCart.size === 0) {
                const emptyMessage = document.createElement("p");
                emptyMessage.className = "pos-order-empty text-secondary mb-0";
                emptyMessage.textContent = "No items added yet.";
                posOrderItems.appendChild(emptyMessage);
            }

            posCart.forEach(function (item, productId) {
                const row = document.createElement("div");
                row.className = "pos-order-item";

                const details = document.createElement("div");
                details.className = "pos-order-item-details";

                const name = document.createElement("span");
                name.className = "pos-order-item-name";
                name.textContent = item.name;
                details.appendChild(name);

                if (item.type === "custom") {
                    const componentSummary = document.createElement("span");
                    componentSummary.className = "pos-order-item-total";
                    componentSummary.textContent = item.components
                        .map(function (component) {
                            return component.name + " × " + component.quantity;
                        })
                        .join(", ");
                    details.appendChild(componentSummary);
                }

                if (item.type === "custom") {
                    const editButton = document.createElement("button");
                    editButton.className = "btn btn-sm btn-outline-primary align-self-start mt-1 pos-edit-custom-order-button";
                    editButton.type = "button";
                    editButton.textContent = "Edit customization";
                    editButton.addEventListener("click", function () {
                        openPosCustomization(productId);
                    });
                    details.appendChild(editButton);
                }

                const lineTotal = document.createElement("span");
                lineTotal.className = "pos-order-item-total";
                lineTotal.textContent = posCurrencyFormatter.format(item.price * item.quantity);
                details.appendChild(lineTotal);

                const quantityControls = document.createElement("div");
                quantityControls.className = "pos-quantity-controls";

                const decreaseButton = document.createElement("button");
                decreaseButton.className = "btn btn-sm btn-outline-secondary";
                decreaseButton.type = "button";
                decreaseButton.textContent = "−";
                decreaseButton.setAttribute("aria-label", "Decrease " + item.name + " quantity");
                decreaseButton.addEventListener("click", function () {
                    if (item.quantity === 1) {
                        posCart.delete(productId);
                    } else {
                        item.quantity--;
                    }
                    returnToOrderStepAfterCartChange();
                    renderPosOrder();
                });

                const quantity = document.createElement("span");
                quantity.className = "pos-order-quantity";
                quantity.textContent = String(item.quantity);
                quantity.setAttribute("aria-label", "Quantity");

                const increaseButton = document.createElement("button");
                increaseButton.className = "btn btn-sm btn-outline-secondary";
                increaseButton.type = "button";
                increaseButton.textContent = "+";
                increaseButton.setAttribute("aria-label", "Increase " + item.name + " quantity");
                increaseButton.addEventListener("click", function () {
                    item.quantity = Math.min(1000, item.quantity + 1);
                    returnToOrderStepAfterCartChange();
                    renderPosOrder();
                });

                quantityControls.append(decreaseButton, quantity, increaseButton);
                row.append(details, quantityControls);
                posOrderItems.appendChild(row);
                subtotal += item.price * item.quantity;
            });

            subtotal = Math.round((subtotal + Number.EPSILON) * 100) / 100;
            const discountRate = posDiscountType.value === "none" ? 0 : 0.2;
            const discount = Math.round((subtotal * discountRate + Number.EPSILON) * 100) / 100;
            const total = Math.max(0, subtotal - discount);
            posCurrentTotalCents = Math.round(total * 100);

            posOrderSubtotal.textContent = posCurrencyFormatter.format(subtotal);
            posDiscountRow.hidden = discountRate === 0;
            posDiscountLabel.textContent = posDiscountType.value === "senior"
                ? "Senior citizen discount (20%)"
                : "PWD discount (20%)";
            posDiscountAmount.textContent = "−" + posCurrencyFormatter.format(discount);
            posOrderTotal.textContent = posCurrencyFormatter.format(total);
            posCashTotalDue.textContent = posCurrencyFormatter.format(total);
            posCashAmount.min = (posCurrentTotalCents / 100).toFixed(2);
            posContinueToPaymentButton.disabled = posCart.size === 0;
        }

        function showPosCheckoutStep(step) {
            posCheckoutStep = step;
            posCheckoutSteps.forEach(function (panel, index) {
                panel.hidden = index + 1 !== step;
            });
            const stepNames = [
                "Walk-in order",
                "Payment and discount",
                "Review and confirm"
            ];
            posCheckoutStepLabel.textContent = "Step " + step + " of 3: " + stepNames[step - 1];
        }

        function returnToOrderStepAfterCartChange() {
            if (posCheckoutStep !== 1) {
                showPosCheckoutStep(1);
            }
        }

        function addPosReviewLine(label, value) {
            const line = document.createElement("p");
            line.className = "mb-2";
            const strong = document.createElement("strong");
            strong.textContent = label + ": ";
            line.append(strong, document.createTextNode(value));
            posCheckoutReview.appendChild(line);
        }

        function renderPosCheckoutReview() {
            posCheckoutReview.replaceChildren();
            const productCount = Array.from(posCart.values()).reduce(function (count, item) {
                return count + item.quantity;
            }, 0);
            const paymentLabel = document.getElementById("pos-payment-method").selectedOptions[0].text;
            const discountLabel = posDiscountType.options[posDiscountType.selectedIndex].text;
            addPosReviewLine("Items", productCount + " item(s) across " + posCart.size + " product(s)");
            addPosReviewLine("Order type", "Walk-in");
            addPosReviewLine("Payment", paymentLabel);
            addPosReviewLine("Discount", discountLabel);
            addPosReviewLine("Subtotal", posOrderSubtotal.textContent);
            if (!posDiscountRow.hidden) {
                addPosReviewLine("Discount amount", posDiscountAmount.textContent);
            }
            addPosReviewLine("Total due", posOrderTotal.textContent);
            if (posPaymentMethod.value === "cash") {
                const amountPaidCents = Math.round(Number(posCashAmount.value) * 100);
                addPosReviewLine("Cash received", posCurrencyFormatter.format(amountPaidCents / 100));
                addPosReviewLine("Change due", posCurrencyFormatter.format((amountPaidCents - posCurrentTotalCents) / 100));
            }
        }

        function validatePosCashPayment() {
            if (posPaymentMethod.value !== "cash") {
                return true;
            }

            const amountPaidCents = Math.round(posCashAmount.valueAsNumber * 100);
            let errorMessage = "";
            if (posCashAmount.value.trim() === "" || !Number.isFinite(amountPaidCents)) {
                errorMessage = "Enter the cash amount received.";
            } else if (posCashAmount.validity.stepMismatch || posCashAmount.validity.rangeOverflow) {
                errorMessage = "Enter a valid cash amount using increments of ₱0.01.";
            } else if (amountPaidCents < posCurrentTotalCents) {
                errorMessage = "The amount received must cover the order total.";
            }

            posCashAmount.setCustomValidity(errorMessage);
            posCashPaymentError.textContent = errorMessage;
            posCashPaymentError.hidden = errorMessage === "";
            if (errorMessage !== "") {
                posCashAmount.reportValidity();
                return false;
            }
            return true;
        }

        function updatePosCashPaymentField() {
            const isCashPayment = posPaymentMethod.value === "cash";
            posCashPaymentField.hidden = !isCashPayment;
            posCashAmount.disabled = !isCashPayment;
            posCashAmount.required = isCashPayment;
            posCashPaymentError.hidden = true;
            posCashAmount.setCustomValidity("");
        }

        posContinueToPaymentButton.addEventListener("click", function () {
            if (posCart.size === 0) {
                return;
            }
            showPosCheckoutStep(2);
        });
        posBackToOrderButton.addEventListener("click", function () {
            showPosCheckoutStep(1);
        });
        posReviewOrderButton.addEventListener("click", function () {
            if (posCart.size === 0) {
                showPosCheckoutStep(1);
                return;
            }
            if (!validatePosCashPayment()) {
                return;
            }
            renderPosCheckoutReview();
            showPosCheckoutStep(3);
        });
        posBackToPaymentButton.addEventListener("click", function () {
            showPosCheckoutStep(2);
        });

        document.querySelectorAll(".pos-product-card").forEach(function (card) {
            const addButton = card.querySelector(".pos-add-to-order-button");
            addButton.addEventListener("click", function () {
                const productId = card.dataset.productId;
                const existingItem = posCart.get(productId);
                if (existingItem) {
                    existingItem.quantity = Math.min(1000, existingItem.quantity + 1);
                } else {
                    const price = Number(card.dataset.productPrice);
                    if (!Number.isFinite(price) || price < 0) {
                        window.alert("This product has an invalid price and cannot be added to the order.");
                        return;
                    }
                    posCart.set(productId, {
                        name: card.dataset.productName,
                        price: price,
                        quantity: 1
                    });
                }
                returnToOrderStepAfterCartChange();
                renderPosOrder();
            });
        });

        posAddOnsButton.addEventListener("click", function () {
            posAddOnsDialog.showModal();
        });
        document.getElementById("pos-close-add-ons").addEventListener("click", function () {
            posAddOnsDialog.close();
        });
        posAddOnsDialog.querySelectorAll(".pos-add-on-card").forEach(function (card) {
            card.querySelector(".pos-add-on-to-order-button").addEventListener("click", function () {
                const itemId = Number(card.dataset.itemId);
                const price = Number(card.dataset.itemPrice);
                const name = card.dataset.itemName;
                if (!Number.isSafeInteger(itemId) || itemId < 1 || !Number.isFinite(price) || price < 0 || !name) {
                    window.alert("This add-on has invalid inventory details and cannot be added.");
                    return;
                }
                const cartKey = "addon-" + itemId;
                const existingItem = posCart.get(cartKey);
                if (existingItem) {
                    existingItem.quantity = Math.min(1000, existingItem.quantity + 1);
                } else {
                    posCart.set(cartKey, {
                        type: "addon",
                        name: name,
                        price: price,
                        quantity: 1,
                        components: [{ item_id: itemId, quantity: 1, name: name }]
                    });
                }
                posAddOnsDialog.close();
                returnToOrderStepAfterCartChange();
                renderPosOrder();
            });
        });
        const posAddOnsSearch = document.getElementById("pos-add-ons-search");
        if (posAddOnsSearch) {
            posAddOnsSearch.addEventListener("input", function () {
                const searchTerm = posAddOnsSearch.value.trim().toLocaleLowerCase();
                let visibleCards = 0;
                posAddOnsDialog.querySelectorAll(".pos-add-on-card").forEach(function (card) {
                    const matches = card.textContent.toLocaleLowerCase().includes(searchTerm);
                    card.hidden = !matches;
                    if (matches) {
                        visibleCards++;
                    }
                });
                const noMatches = document.getElementById("pos-add-ons-no-matches");
                if (noMatches) {
                    noMatches.hidden = visibleCards > 0;
                }
            });
        }

        posDiscountType.addEventListener("change", renderPosOrder);
        posPaymentMethod.addEventListener("change", updatePosCashPaymentField);
        posCashAmount.addEventListener("input", function () {
            posCashPaymentError.hidden = true;
            posCashAmount.setCustomValidity("");
        });
        let pendingDeliveryStatusForm = null;
        document.querySelectorAll(".delivery-status-form").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                event.preventDefault();
                const status = form.elements.delivery_status.value;
                if (!status) {
                    form.elements.delivery_status.reportValidity();
                    return;
                }
                pendingDeliveryStatusForm = form;
                const saleId = form.elements.sale_id.value;
                document.getElementById("delivery-status-confirm-message").textContent =
                    "Update delivery order #" + saleId + " to “" + status + "”?";
                deliveryStatusConfirmDialog.showModal();
            });
        });
        document.getElementById("cancel-delivery-status-confirm").addEventListener("click", function () {
            deliveryStatusConfirmDialog.close();
        });
        document.getElementById("confirm-delivery-status-update").addEventListener("click", function () {
            if (!pendingDeliveryStatusForm) {
                return;
            }
            const form = pendingDeliveryStatusForm;
            const status = form.elements.delivery_status.value;
            if (status === "Delivery failed") {
                document.getElementById("delivery-failure-sale-id").value = form.elements.sale_id.value;
                document.getElementById("delivery-failure-remarks").value = "";
                deliveryStatusConfirmDialog.close();
                pendingDeliveryStatusForm = null;
                deliveryFailureDialog.showModal();
                document.getElementById("delivery-failure-remarks").focus();
                return;
            }
            if (status === "Delivered" && form.closest("tr").dataset.paymentMethod === "cash") {
                const orderRow = form.closest("tr");
                const total = Number(orderRow.dataset.totalAmount);
                if (!Number.isFinite(total) || total <= 0) {
                    window.alert("This delivery order has an invalid total and cannot be finalized.");
                    return;
                }
                document.getElementById("delivery-cash-sale-id").value = form.elements.sale_id.value;
                document.getElementById("delivery-cash-order").textContent = "#" + form.elements.sale_id.value;
                document.getElementById("delivery-cash-total").textContent = posCurrencyFormatter.format(total);
                deliveryCashAmount.min = total.toFixed(2);
                deliveryCashAmount.value = "";
                deliveryStatusConfirmDialog.close();
                pendingDeliveryStatusForm = null;
                deliveryCashDialog.showModal();
                deliveryCashAmount.focus();
                return;
            }
            deliveryStatusConfirmDialog.close();
            pendingDeliveryStatusForm = null;
            HTMLFormElement.prototype.submit.call(form);
        });
        deliveryStatusConfirmDialog.addEventListener("close", function () {
            pendingDeliveryStatusForm = null;
        });
        document.getElementById("cancel-delivery-failure").addEventListener("click", function () {
            deliveryFailureDialog.close();
        });
        deliveryFailureDialog.addEventListener("close", function () {
            deliveryFailureForm.reset();
        });
        deliveryCashAmount.addEventListener("input", function () {
            const receivedCents = Math.round(Number(deliveryCashAmount.value) * 100);
            const totalCents = Math.round(Number(deliveryCashAmount.min) * 100);
            const changeCents = Number.isFinite(receivedCents) && Number.isFinite(totalCents)
                ? Math.max(0, receivedCents - totalCents)
                : 0;
            document.getElementById("delivery-cash-change").textContent =
                posCurrencyFormatter.format(changeCents / 100);
        });
        document.getElementById("cancel-delivery-cash").addEventListener("click", function () {
            deliveryCashDialog.close();
        });
        deliveryCashDialog.addEventListener("close", function () {
            deliveryCashForm.reset();
            document.getElementById("delivery-cash-change").textContent = posCurrencyFormatter.format(0);
        });
        document.getElementById("pos-checkout-form").addEventListener("submit", function (event) {
            if (posCart.size === 0 || posCheckoutStep !== 3) {
                event.preventDefault();
                return;
            }
            if (!validatePosCashPayment()) {
                event.preventDefault();
                showPosCheckoutStep(2);
                return;
            }
            posCheckoutCart.value = JSON.stringify(Array.from(posCart.entries()).map(function (entry) {
                if (entry[1].type === "custom" || entry[1].type === "addon") {
                    return {
                        product_id: 0,
                        quantity: entry[1].quantity,
                        custom: {
                            type: entry[1].type === "addon" ? "addon" : "bouquet",
                            name: entry[1].name,
                            price: entry[1].price.toFixed(2),
                            components: entry[1].components.map(function (component) {
                                return {
                                    item_id: component.item_id,
                                    quantity: component.quantity
                                };
                            })
                        }
                    };
                }
                return { product_id: Number(entry[0]), quantity: entry[1].quantity };
            }));
            document.getElementById("pos-place-order").disabled = true;
        });
        updatePosCashPaymentField();
        renderPosOrder();
        showPosCheckoutStep(1);

        if (usersTableBodies.length && usersSearch && usersFilterField) {
            function filterUsers() {
                const searchTerm = usersSearch.value.trim().toLocaleLowerCase();
                const selectedField = usersFilterField.value;
                usersTableBodies.forEach(function (tableBody) {
                    const rows = [...tableBody.querySelectorAll("tr:not(.users-empty-row):not(.users-no-matches)")];
                    let visibleRows = 0;
                    rows.forEach(function (row) {
                        const searchableValue = selectedField === "all"
                            ? row.dataset.searchText || row.textContent
                            : row.dataset[selectedField] || "";
                        const matches = searchableValue.toLocaleLowerCase().includes(searchTerm);
                        row.hidden = !matches;
                        if (matches) visibleRows++;
                    });

                    let noMatchesRow = tableBody.querySelector(".users-no-matches");
                    if (rows.length > 0 && visibleRows === 0) {
                        if (!noMatchesRow) {
                            noMatchesRow = document.createElement("tr");
                            noMatchesRow.className = "users-no-matches";
                            const messageCell = document.createElement("td");
                            messageCell.className = "text-center text-secondary py-4";
                            messageCell.colSpan = Number(tableBody.dataset.columnCount);
                            messageCell.textContent = "No matching accounts found.";
                            noMatchesRow.appendChild(messageCell);
                            tableBody.appendChild(noMatchesRow);
                        }
                    } else if (noMatchesRow) {
                        noMatchesRow.remove();
                    }
                });
            }

            usersSearch.addEventListener("input", filterUsers);
            usersFilterField.addEventListener("change", filterUsers);
        }

        if (inventorySearch && inventoryFilterField && inventoryCardGrid) {
            function filterInventory() {
                const searchTerm = inventorySearch.value.trim().toLocaleLowerCase();
                const selectedField = inventoryFilterField.value;
                const cards = inventoryCardGrid.querySelectorAll(".inventory-item-card");
                let visibleCards = 0;

                cards.forEach(function (card) {
                    const searchableValue = selectedField === "all"
                        ? [
                            card.dataset.itemName,
                            card.dataset.category,
                            card.dataset.color,
                            card.dataset.price,
                            card.dataset.stock,
                            card.dataset.itemId
                        ].join(" ")
                        : card.dataset[selectedField] || "";
                    const matches = searchableValue.toLocaleLowerCase().includes(searchTerm);
                    card.hidden = !matches;
                    if (matches) {
                        visibleCards++;
                    }
                });

                inventoryPanel.querySelector(".inventory-no-matches").hidden = visibleCards > 0;
            }

            inventorySearch.addEventListener("input", filterInventory);
            inventoryFilterField.addEventListener("change", filterInventory);
        }

        const productCardGrid = productsPanel.querySelector(".product-card-grid");
        const posProductGrid = posPanel.querySelector(".pos-product-grid");
        if (posProductSearch && posPriceSort && posColorFilter && posProductGrid) {
            function filterPosProducts() {
                const searchTerm = posProductSearch.value.trim().toLocaleLowerCase();
                const selectedColor = posColorFilter.value.toLocaleLowerCase();
                const cards = Array.from(posProductGrid.querySelectorAll(".pos-product-card"));
                const sortOrder = posPriceSort.value;
                cards.sort(function (firstCard, secondCard) {
                    const firstPrice = Number(firstCard.dataset.productPrice);
                    const secondPrice = Number(secondCard.dataset.productPrice);
                    if (sortOrder === "high-to-low") {
                        return secondPrice - firstPrice;
                    }
                    if (sortOrder === "low-to-high") {
                        return firstPrice - secondPrice;
                    }
                    return Number(secondCard.dataset.productId) - Number(firstCard.dataset.productId);
                });
                cards.forEach(function (card) {
                    posProductGrid.appendChild(card);
                });

                let visibleCards = 0;
                cards.forEach(function (card) {
                    const productColors = card.dataset.productColors
                        .split(",")
                        .map(function (color) { return color.trim().toLocaleLowerCase(); });
                    const matchesSearch = [
                        card.dataset.productName,
                        card.dataset.productColors,
                        card.dataset.productPrice
                    ].join(" ").toLocaleLowerCase().includes(searchTerm);
                    const matchesColor = selectedColor === "all" || productColors.includes(selectedColor);
                    card.hidden = !matchesSearch || !matchesColor;
                    if (!card.hidden) {
                        visibleCards++;
                    }
                });
                document.getElementById("pos-product-no-matches").hidden = visibleCards > 0;
            }

            posProductSearch.addEventListener("input", filterPosProducts);
            posPriceSort.addEventListener("change", filterPosProducts);
            posColorFilter.addEventListener("change", filterPosProducts);
        }

        if (productsSearch && productsPriceSort && productCardGrid) {
            function filterProducts() {
                const searchTerm = productsSearch.value.trim().toLocaleLowerCase();
                const cards = Array.from(productCardGrid.querySelectorAll(".product-item-card"));
                const sortOrder = productsPriceSort.value;
                cards.sort(function (firstCard, secondCard) {
                    const firstPrice = Number(firstCard.dataset.price);
                    const secondPrice = Number(secondCard.dataset.price);
                    if (sortOrder === "high-to-low") {
                        return secondPrice - firstPrice;
                    }
                    if (sortOrder === "low-to-high") {
                        return firstPrice - secondPrice;
                    }
                    if (sortOrder === "name-a-to-z") {
                        const firstName = firstCard.querySelector(".inventory-item-name").textContent.trim();
                        const secondName = secondCard.querySelector(".inventory-item-name").textContent.trim();
                        return firstName.localeCompare(secondName, undefined, { sensitivity: "base" });
                    }
                    return Number(secondCard.dataset.productId) - Number(firstCard.dataset.productId);
                });
                cards.forEach(function (card) {
                    productCardGrid.appendChild(card);
                });
                let visibleCards = 0;

                cards.forEach(function (card) {
                    const matches = card.textContent.toLocaleLowerCase().includes(searchTerm);
                    card.hidden = !matches;
                    if (matches) {
                        visibleCards++;
                    }
                });

                productsPanel.querySelector(".product-no-matches").hidden = visibleCards > 0;
            }

            productsSearch.addEventListener("input", filterProducts);
            productsPriceSort.addEventListener("change", filterProducts);
        }

        const logoutDialog = document.getElementById("logout-dialog");
        const addInventoryItemDialog = document.getElementById("add-inventory-item-dialog");
        const editInventoryItemDialog = document.getElementById("edit-inventory-item-dialog");
        const editInventoryItemForm = editInventoryItemDialog.querySelector("form");
        const stockSupplierDialog = document.getElementById("stock-supplier-dialog");
        const stockSupplierList = document.getElementById("stock-supplier-list");
        const stockSupplierItemName = document.getElementById("stock-supplier-item-name");
        const stockSupplierInstructions = document.getElementById("stock-supplier-instructions");
        const confirmStockIncreaseButton = document.getElementById("confirm-stock-increase");
        const editItemStockInput = document.getElementById("edit-item-stock");
        const editStockSupplierIdInput = document.getElementById("edit-stock-supplier-id");
        let stockSupplierConfirmationAccepted = false;
        const addProductDialog = document.getElementById("add-product-dialog");
        const addProductForm = addProductDialog.querySelector("form");
        const productPriceInput = document.getElementById("product-price");
        const calculatedProductPriceButton = document.getElementById("use-calculated-product-price");
        const productOccasionDropdown = addProductDialog.querySelector(".product-occasion-dropdown");
        const productOccasionSummary = document.getElementById("product-occasion-summary");
        const productOccasionChoices = addProductDialog.querySelectorAll(".product-occasion-choice");
        let productPriceManuallyEdited = productPriceInput.value.trim() !== "";
        const openAddInventoryItemButton = document.getElementById("open-add-inventory-item");
        const openAddProductButton = document.getElementById("open-add-product");
        const productCategoryButtons = addProductDialog.querySelectorAll(".product-category-button");
        const productCategoryPanels = addProductDialog.querySelectorAll(".product-category-panel");
        function setProductCategory(category) {
            productCategoryButtons.forEach(function (button) {
                const isSelected = button.dataset.productCategory === category;
                button.classList.toggle("active", isSelected);
                button.setAttribute("aria-pressed", String(isSelected));
            });
            productCategoryPanels.forEach(function (panel) {
                panel.hidden = panel.dataset.productPanel !== category;
            });
        }
        productCategoryButtons.forEach(function (button) {
            button.addEventListener("click", function () {
                setProductCategory(button.dataset.productCategory);
            });
        });
        addProductDialog.addEventListener("close", function () {
            productCategoryPanels.forEach(function (panel) {
                panel.hidden = true;
            });
            productCategoryButtons.forEach(function (button) {
                button.classList.remove("active");
                button.setAttribute("aria-pressed", "false");
            });
        });
        function updateProductCardSelection(card) {
            const choice = card.querySelector(".product-card-choice");
            card.classList.toggle("selected", choice.checked);
        }
        function updatePackagingChoiceAvailability() {
            const wrapperSelected = addProductDialog.querySelectorAll(".wrapper-card-choice:checked").length > 0;
            const containerSelected = addProductDialog.querySelectorAll(".container-card-choice:checked").length > 0;
            addProductDialog.querySelectorAll(".wrapper-card-choice").forEach(function (choice) {
                choice.disabled = containerSelected && !choice.checked;
            });
            addProductDialog.querySelectorAll(".container-card-choice").forEach(function (choice) {
                choice.disabled = wrapperSelected && !choice.checked;
            });
            document.getElementById("wrapper-selection-count").textContent =
                addProductDialog.querySelectorAll(".wrapper-card-choice:checked").length + " of 2 types";
            document.getElementById("container-selection-count").textContent =
                addProductDialog.querySelectorAll(".container-card-choice:checked").length + " of 1 type";
        }
        function updateProductOccasionSummary() {
            const selectedOccasions = Array.from(productOccasionChoices)
                .filter(function (choice) { return choice.checked; })
                .map(function (choice) { return choice.value; });
            if (selectedOccasions.length === 0) {
                productOccasionSummary.textContent = "Select occasions";
            } else if (selectedOccasions.length <= 2) {
                productOccasionSummary.textContent = selectedOccasions.join(", ");
            } else {
                productOccasionSummary.textContent = selectedOccasions.slice(0, 2).join(", ") +
                    " +" + (selectedOccasions.length - 2);
            }
        }
        productOccasionChoices.forEach(function (choice) {
            choice.addEventListener("change", updateProductOccasionSummary);
        });
        document.addEventListener("click", function (event) {
            if (!productOccasionDropdown.contains(event.target)) {
                productOccasionDropdown.open = false;
            }
        });
        updateProductOccasionSummary();
        function configureProductForm(mode, productId, productName, productPrice, productStock, productDescription) {
            const isEditing = mode === "edit";
            const isPosCustomization = mode === "pos-customization";
            const productStockField = document.querySelector(".product-stock-field");
            const productStockInput = document.getElementById("product-stock");
            const discountInput = document.getElementById("product-discount-percent");
            const productDescriptionField = document.querySelector(".product-description-field");
            const marketingFields = addProductDialog.querySelectorAll(".product-marketing-fields");
            posProductFormMode = mode;
            addProductDialog.querySelector('[data-product-category="addons"]').hidden = isPosCustomization;
            addProductDialog.querySelector('[data-product-panel="addons"]').hidden = isPosCustomization;
            document.getElementById("product-form-title").textContent = isPosCustomization
                ? "Customize customer order"
                : (isEditing ? "Edit product" : "Create product");
            document.getElementById("product-form-action").value = isEditing ? "update_product" : "create_product";
            document.getElementById("product-form-id").value = isEditing ? productId : "";
            document.getElementById("save-product-button").textContent = isPosCustomization
                ? (posCustomizationTargetKey === null ? "Add to current order" : "Save customization")
                : (isEditing ? "Save changes" : "Create product");
            document.getElementById("product-name").value = productName || "";
            document.getElementById("product-description").value = productDescription || "";
            productDescriptionField.hidden = isPosCustomization;
            document.getElementById("product-name").placeholder = isPosCustomization ? "e.g. Birthday bouquet" : "";
            document.querySelector('label[for="product-name"]').textContent = isPosCustomization ? "Order item name" : "Product name";
            document.getElementById("product-image").closest("div").hidden = isPosCustomization;
            productStockField.hidden = isPosCustomization;
            productStockInput.disabled = isPosCustomization;
            productStockInput.required = !isPosCustomization;
            productStockInput.value = productStock === undefined ? "0" : productStock;
            productPriceInput.value = productPrice || "";
            discountInput.value = "0";
            marketingFields.forEach(function (field) {
                field.querySelectorAll("input").forEach(function (input) {
                    input.checked = false;
                    input.disabled = isPosCustomization;
                });
                field.hidden = isPosCustomization;
            });
            productOccasionDropdown.open = false;
            updateProductOccasionSummary();
            document.getElementById("product-image").value = "";
            productPriceManuallyEdited = (isEditing || (isPosCustomization && productPriceInput.value !== "")) && productPriceInput.value !== "";
            document.querySelector('label[for="product-price"]').textContent = isPosCustomization ? "Selling price (₱)" : "Selling price (₱)";
            document.getElementById("product-selection-error").hidden = true;
            updateCalculatedProductPrice();
        }
        function resetProductComponents() {
            addProductForm.querySelectorAll(".product-quantity-input").forEach(function (input) {
                input.remove();
            });
            addProductDialog.querySelectorAll(".product-card-choice").forEach(function (choice) {
                choice.checked = false;
                choice.dataset.quantity = "1";
                updateProductCardSelection(choice.closest(".product-component-card"));
            });
            updatePackagingChoiceAvailability();
            renderSelectedProductItems();
        }
        function getProductQuantityInput(choice) {
            const inputId = "product-component-quantity-" + choice.dataset.quantityField.replace(/[^a-z0-9-]/gi, "-") + "-" + choice.value;
            let quantityInput = document.getElementById(inputId);
            if (!quantityInput) {
                quantityInput = document.createElement("input");
                quantityInput.type = "hidden";
                quantityInput.id = inputId;
                quantityInput.className = "product-quantity-input";
                quantityInput.name = choice.dataset.quantityField + "[" + choice.value + "]";
                quantityInput.value = choice.dataset.quantity || "1";
                addProductForm.appendChild(quantityInput);
            }
            return quantityInput;
        }
        function syncSelectedProductQuantityInputs() {
            const selectedQuantities = new Set();
            addProductDialog.querySelectorAll(".product-card-choice:checked").forEach(function (choice) {
                selectedQuantities.add(getProductQuantityInput(choice).id);
            });
            addProductForm.querySelectorAll(".product-quantity-input").forEach(function (input) {
                if (!selectedQuantities.has(input.id)) {
                    input.remove();
                }
            });
        }
        function updateCalculatedProductPrice() {
            let totalCost = 0;
            addProductDialog.querySelectorAll(".product-card-choice:checked").forEach(function (choice) {
                const quantityInput = getProductQuantityInput(choice);
                const unitPrice = Number(choice.dataset.unitPrice);
                const quantity = Number(quantityInput.value);
                if (Number.isFinite(unitPrice) && Number.isFinite(quantity)) {
                    totalCost += unitPrice * quantity;
                }
            });

            document.getElementById("product-component-cost").textContent =
                totalCost.toLocaleString("en-PH", { style: "currency", currency: "PHP" });
            calculatedProductPriceButton.hidden = !productPriceManuallyEdited;
            if (!productPriceManuallyEdited) {
                productPriceInput.value = totalCost > 0 ? totalCost.toFixed(2) : "";
            }
        }
        function renderSelectedProductItems() {
            const groups = [
                { label: "Flowers", selector: 'input[name="flower_ids[]"]:checked', unit: "" },
                { label: "Wrappers", selector: 'input[name="wrapper_ids[]"]:checked', unit: "" },
                { label: "Containers", selector: 'input[name="container_ids[]"]:checked', unit: "" },
                { label: "Ribbon", selector: 'input[name="ribbon_id"]:checked', unit: "yards" },
                { label: "Add-ons", selector: ".addon-card-choice:checked", unit: "" }
            ];
            const selectedGroups = document.getElementById("product-selected-groups");
            const selectedEmpty = document.getElementById("product-selected-empty");
            const selectedCount = document.getElementById("product-selected-count");
            selectedGroups.replaceChildren();
            syncSelectedProductQuantityInputs();
            updateCalculatedProductPrice();
            let totalSelected = 0;

            groups.forEach(function (group) {
                const choices = addProductDialog.querySelectorAll(group.selector);
                if (choices.length === 0) {
                    return;
                }

                const groupElement = document.createElement("section");
                groupElement.className = "product-selected-group";
                const heading = document.createElement("h4");
                heading.textContent = group.label;
                const list = document.createElement("ul");

                choices.forEach(function (choice) {
                    const itemName = choice.closest(".product-component-card").querySelector("strong").textContent;
                    const quantityInput = getProductQuantityInput(choice);
                    const item = document.createElement("li");
                    item.className = "product-selected-item";
                    item.dataset.itemId = choice.value;
                    item.dataset.quantityField = choice.dataset.quantityField;
                    const itemNameElement = document.createElement("span");
                    itemNameElement.className = "product-selected-item-name";
                    itemNameElement.textContent = itemName;
                    const controls = document.createElement("div");
                    controls.className = "product-selected-item-controls";
                    const decrement = document.createElement("button");
                    decrement.className = "product-quantity-button";
                    decrement.type = "button";
                    decrement.dataset.quantityAction = "decrement";
                    decrement.setAttribute("aria-label", "Decrease " + itemName + " quantity");
                    decrement.textContent = "−";
                    let quantity;
                    if (group.label === "Flowers") {
                        quantity = document.createElement("input");
                        quantity.type = "number";
                        quantity.className = "product-selected-item-quantity-input";
                        quantity.min = "1";
                        quantity.max = "2147483647";
                        quantity.step = "1";
                        quantity.required = true;
                        quantity.value = quantityInput.value;
                        quantity.setAttribute("aria-label", itemName + " quantity");
                        quantity.addEventListener("input", function () {
                            quantityInput.value = quantity.value;
                            choice.dataset.quantity = quantity.value;
                            updateCalculatedProductPrice();
                        });
                    } else {
                        quantity = document.createElement("output");
                        quantity.className = "product-selected-item-quantity";
                        quantity.textContent = quantityInput.value + (group.unit ? " " + group.unit : "");
                    }
                    const increment = document.createElement("button");
                    increment.className = "product-quantity-button";
                    increment.type = "button";
                    increment.dataset.quantityAction = "increment";
                    increment.setAttribute("aria-label", "Increase " + itemName + " quantity");
                    increment.textContent = "+";
                    const remove = document.createElement("button");
                    remove.className = "product-selected-item-remove";
                    remove.type = "button";
                    remove.dataset.quantityAction = "remove";
                    remove.setAttribute("aria-label", "Remove " + itemName);
                    remove.title = "Remove " + itemName;
                    remove.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 14h12l1-14M9 7V4h6v3"></path></svg>';
                    controls.append(decrement, quantity, increment, remove);
                    item.append(itemNameElement, controls);
                    list.appendChild(item);
                    totalSelected++;
                });

                groupElement.append(heading, list);
                selectedGroups.appendChild(groupElement);
            });

            selectedCount.textContent = String(totalSelected);
            selectedEmpty.hidden = totalSelected > 0;
        }
        document.getElementById("product-selected-groups").addEventListener("click", function (event) {
            const button = event.target.closest("button[data-quantity-action]");
            if (!button) {
                return;
            }

            const selectedItem = button.closest(".product-selected-item");
            const choiceValue = selectedItem.dataset.itemId;
            const choice = addProductDialog.querySelector('.product-card-choice[value="' + CSS.escape(choiceValue) + '"]');
            if (!choice) {
                return;
            }

            if (button.dataset.quantityAction === "remove") {
                choice.checked = false;
                updateProductCardSelection(choice.closest(".product-component-card"));
                updatePackagingChoiceAvailability();
                document.getElementById("product-selection-error").hidden = true;
                renderSelectedProductItems();
                return;
            }

            const quantityInput = getProductQuantityInput(choice);
            const nextQuantity = Number(quantityInput.value) + (button.dataset.quantityAction === "increment" ? 1 : -1);
            quantityInput.value = String(Math.max(1, Math.min(2147483647, nextQuantity)));
            choice.dataset.quantity = quantityInput.value;
            renderSelectedProductItems();
        });
        productPriceInput.addEventListener("input", function () {
            productPriceManuallyEdited = true;
            calculatedProductPriceButton.hidden = false;
        });
        calculatedProductPriceButton.addEventListener("click", function () {
            productPriceManuallyEdited = false;
            updateCalculatedProductPrice();
        });
        addProductDialog.querySelectorAll(".product-component-card").forEach(function (card) {
            const choice = card.querySelector(".product-card-choice");
            updateProductCardSelection(card);
            choice.addEventListener("change", function () {
                let packagingLimitReached = false;
                if (choice.classList.contains("wrapper-card-choice")) {
                    const selectedWrappers = addProductDialog.querySelectorAll(".wrapper-card-choice:checked");
                    if (selectedWrappers.length > 2) {
                        choice.checked = false;
                        updateProductCardSelection(card);
                        packagingLimitReached = true;
                        const errorMessage = document.getElementById("product-selection-error");
                        errorMessage.textContent = "You can select no more than two wrapper types.";
                        errorMessage.hidden = false;
                    }
                } else if (choice.classList.contains("container-card-choice")) {
                    const selectedContainers = addProductDialog.querySelectorAll(".container-card-choice:checked");
                    if (selectedContainers.length > 1) {
                        choice.checked = false;
                        updateProductCardSelection(card);
                        packagingLimitReached = true;
                        const errorMessage = document.getElementById("product-selection-error");
                        errorMessage.textContent = "Choose only one container.";
                        errorMessage.hidden = false;
                    }
                }
                if (choice.type === "radio") {
                    addProductDialog.querySelectorAll('input[name="ribbon_id"]').forEach(function (ribbonChoice) {
                        ribbonChoice.closest(".product-component-card").classList.toggle("selected", ribbonChoice.checked);
                    });
                }
                updateProductCardSelection(card);
                updatePackagingChoiceAvailability();
                if (!packagingLimitReached) {
                    document.getElementById("product-selection-error").hidden = true;
                }
                renderSelectedProductItems();
            });
        });
        updatePackagingChoiceAvailability();
        renderSelectedProductItems();

        function getSelectedPosComponents() {
            return Array.from(addProductDialog.querySelectorAll(".product-card-choice:checked")).map(function (choice) {
                const nameElement = choice.closest(".product-component-card").querySelector(".product-component-card-copy strong");
                return {
                    item_id: Number(choice.value),
                    quantity: Number(getProductQuantityInput(choice).value),
                    name: nameElement ? nameElement.textContent.trim() : "Inventory item",
                    unit_price: Number(choice.dataset.unitPrice)
                };
            });
        }

        function openPosCustomization(targetKey) {
            resetProductComponents();
            posCustomizationTargetKey = targetKey === undefined ? null : targetKey;
            const existingItem = posCustomizationTargetKey === null ? null : posCart.get(posCustomizationTargetKey);
            configureProductForm(
                "pos-customization",
                "",
                existingItem ? existingItem.name : "",
                existingItem ? String(existingItem.price) : ""
            );

            if (existingItem) {
                existingItem.components.forEach(function (component) {
                    const choice = addProductDialog.querySelector('.product-card-choice[value="' + CSS.escape(String(component.item_id)) + '"]');
                    if (!choice) {
                        return;
                    }
                    choice.checked = true;
                    choice.dataset.quantity = String(component.quantity);
                    getProductQuantityInput(choice).value = String(component.quantity);
                    updateProductCardSelection(choice.closest(".product-component-card"));
                });
            }
            updatePackagingChoiceAvailability();
            renderSelectedProductItems();
            setProductCategory("flowers");
            addProductDialog.showModal();
        }

        document.getElementById("pos-customize-order-button").addEventListener("click", function () {
            openPosCustomization();
        });

        addProductForm.addEventListener("submit", function (event) {
            const flowerCount = addProductDialog.querySelectorAll('input[name="flower_ids[]"]:checked').length;
            const wrapperCount = addProductDialog.querySelectorAll('input[name="wrapper_ids[]"]:checked').length;
            const containerCount = addProductDialog.querySelectorAll('input[name="container_ids[]"]:checked').length;
            const ribbonSelected = addProductDialog.querySelector('input[name="ribbon_id"]:checked') !== null;
            let message = "";
            let category = "";
            if (flowerCount === 0) {
                message = "Select at least one flower.";
                category = "flowers";
            } else if (wrapperCount > 0 && containerCount > 0) {
                message = "Choose wrappers or a container, not both.";
                category = "wrappers";
            } else if ((wrapperCount === 0 && containerCount === 0) || wrapperCount > 2 || containerCount > 1) {
                message = "Select one or two wrapper types or one container.";
                category = wrapperCount > 0 ? "wrappers" : "containers";
            } else if (!ribbonSelected) {
                message = "Select a ribbon.";
                category = "ribbon";
            } else {
                syncSelectedProductQuantityInputs();
                const invalidQuantity = Array.from(addProductForm.querySelectorAll(".product-quantity-input")).find(function (input) {
                    const quantity = Number(input.value);
                    return !Number.isInteger(quantity) || quantity < 1 || quantity > 2147483647;
                });
                if (invalidQuantity) {
                    message = "Set each selected item's quantity to a whole number of at least one.";
                }
            }

            if (message === "" && posProductFormMode === "pos-customization") {
                const components = getSelectedPosComponents();
                const componentCostCents = components.reduce(function (total, component) {
                    return total + Math.round(component.unit_price * 100) * component.quantity;
                }, 0);
                const price = Number(productPriceInput.value);
                const priceCents = Math.round(price * 100);
                const name = document.getElementById("product-name").value.trim();
                if (!name) {
                    message = "Enter a name for this customized order item.";
                } else if (!Number.isFinite(price) || priceCents < componentCostCents || price > 99999999.99) {
                    message = "Selling price must be at least the selected inventory cost.";
                } else if (components.some(function (component) {
                    return !Number.isSafeInteger(component.item_id) || component.item_id < 1 ||
                        !Number.isSafeInteger(component.quantity) || component.quantity < 1 || component.quantity > 1000 ||
                        !Number.isFinite(component.unit_price) || component.unit_price < 0;
                })) {
                    message = "One or more selected components has an invalid quantity or price.";
                } else {
                    event.preventDefault();
                    const cartKey = posCustomizationTargetKey === null
                        ? "custom-" + Date.now() + "-" + posCustomItemSequence++
                        : posCustomizationTargetKey;
                    posCart.set(cartKey, {
                        type: "custom",
                        name: name,
                        price: price,
                        quantity: posCustomizationTargetKey !== null && posCart.has(posCustomizationTargetKey)
                            ? posCart.get(posCustomizationTargetKey).quantity
                            : 1,
                        components: components.map(function (component) {
                            return {
                                item_id: component.item_id,
                                quantity: component.quantity,
                                name: component.name
                            };
                        })
                    });
                    addProductDialog.close();
                    posCustomizationTargetKey = null;
                    returnToOrderStepAfterCartChange();
                    renderPosOrder();
                }
            }

            if (message !== "") {
                event.preventDefault();
                if (category !== "") {
                    setProductCategory(category);
                }
                const errorMessage = document.getElementById("product-selection-error");
                errorMessage.textContent = message;
                errorMessage.hidden = false;
                const categoryButton = Array.from(productCategoryButtons).find(function (button) {
                    return button.dataset.productCategory === category;
                });
                if (categoryButton) {
                    categoryButton.focus();
                }
            }
        });
        if (openAddProductButton) {
            openAddProductButton.addEventListener("click", function () {
                resetProductComponents();
                configureProductForm("create", "", "", "", "0");
                setProductCategory("flowers");
                addProductDialog.showModal();
            });
        }
        document.querySelectorAll(".edit-product-button").forEach(function (button) {
            button.addEventListener("click", function () {
                let components;
                try {
                    components = JSON.parse(button.dataset.productComponents);
                } catch (error) {
                    console.error("Unable to read saved product components.", error);
                    window.alert("This product’s components could not be loaded. Please refresh and try again.");
                    return;
                }
                if (!Array.isArray(components) || components.length === 0) {
                    window.alert("This product has no saved component quantities to edit.");
                    return;
                }

                resetProductComponents();
                configureProductForm("edit", button.dataset.productId, button.dataset.productName, button.dataset.productPrice, button.dataset.productStock, button.dataset.productDescription);
                document.getElementById("product-discount-percent").value = button.dataset.productDiscountPercent || "0";
                const featuredProductCheckbox = document.getElementById("product-featured");
                if (featuredProductCheckbox) {
                    featuredProductCheckbox.checked = button.dataset.productFeatured === "1";
                }
                let savedCategories;
                try {
                    savedCategories = JSON.parse(button.dataset.productCategories || "[]");
                } catch (error) {
                    console.error("Unable to read saved product occasions.", error);
                    window.alert("This product’s occasions could not be loaded. Please refresh and try again.");
                    return;
                }
                if (Array.isArray(savedCategories)) {
                    savedCategories.forEach(function (category) {
                        const checkbox = Array.from(productOccasionChoices)
                            .find(function (choice) { return choice.value === category; });
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                    });
                }
                updateProductOccasionSummary();
                components.forEach(function (component) {
                    const choice = addProductDialog.querySelector('.product-card-choice[value="' + CSS.escape(String(component.item_id)) + '"]');
                    if (!choice) {
                        return;
                    }
                    choice.checked = true;
                    choice.dataset.quantity = String(component.quantity);
                    updateProductCardSelection(choice.closest(".product-component-card"));
                    getProductQuantityInput(choice).value = String(component.quantity);
                });
                updatePackagingChoiceAvailability();
                renderSelectedProductItems();
                setProductCategory("flowers");
                addProductDialog.showModal();
            });
        });
        document.querySelectorAll(".delete-product-form").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                const productName = form.querySelector("button").dataset.productName;
                if (!window.confirm("Delete product " + productName + "? Its saved component recipe and image will also be deleted. This cannot be undone.")) {
                    event.preventDefault();
                }
            });
        });
        const openAddUserButtons = [
            document.getElementById("open-add-user"),
            document.getElementById("open-staff-add-user")
        ].filter(Boolean);
        openAddUserButtons.forEach(function (button) {
            button.addEventListener("click", function () {
                addUserDialog.showModal();
            });
        });
        if (openAddInventoryItemButton) {
            openAddInventoryItemButton.addEventListener("click", function () {
                addInventoryItemDialog.showModal();
            });
        }
        document.getElementById("cancel-add-user").addEventListener("click", function () {
            addUserDialog.close();
        });
        document.getElementById("cancel-add-inventory-item").addEventListener("click", function () {
            addInventoryItemDialog.close();
        });
        document.getElementById("cancel-add-product").addEventListener("click", function () {
            addProductDialog.close();
        });
        document.querySelectorAll(".edit-inventory-item-button").forEach(function (button) {
            button.addEventListener("click", function () {
                document.getElementById("edit-item-id").value = button.dataset.itemId;
                document.getElementById("edit-item-name").value = button.dataset.itemName;
                document.getElementById("edit-item-category").value = button.dataset.category;
                const editColorSelect = document.getElementById("edit-item-color-choice");
                const editColor = button.dataset.color;
                const hasStandardColor = Array.from(editColorSelect.options).some(function (option) {
                    return option.value === editColor;
                });
                editColorSelect.value = hasStandardColor ? editColor : "";
                document.getElementById("edit-item-custom-color").value = hasStandardColor ? "" : editColor;
                document.getElementById("edit-item-price").value = button.dataset.price;
                document.getElementById("edit-item-stock").value = button.dataset.stock;
                document.getElementById("edit-item-image").value = "";
                editInventoryItemForm.dataset.originalStock = button.dataset.stock;
                editInventoryItemForm.dataset.itemName = button.dataset.itemName;
                editInventoryItemForm.dataset.suppliers = button.dataset.suppliers || "[]";
                editInventoryItemDialog.showModal();
            });
        });
        editInventoryItemForm.addEventListener("submit", function (event) {
            if (stockSupplierConfirmationAccepted) {
                stockSupplierConfirmationAccepted = false;
                return;
            }

            const previousStock = Number(editInventoryItemForm.dataset.originalStock);
            const updatedStock = Number(editItemStockInput.value);
            if (!Number.isFinite(previousStock) || !Number.isFinite(updatedStock) || updatedStock <= previousStock) {
                return;
            }

            let suppliers;
            try {
                suppliers = JSON.parse(editInventoryItemForm.dataset.suppliers || "[]");
            } catch (error) {
                console.error("Unable to read suppliers for the selected inventory item.", error);
                alert("Unable to load the suppliers for this item. Please reload the page and try again.");
                event.preventDefault();
                return;
            }

            event.preventDefault();
            editStockSupplierIdInput.value = "";
            stockSupplierItemName.textContent =
                (editInventoryItemForm.dataset.itemName || "Selected item") +
                " stock will increase from " + previousStock + " to " + updatedStock + ".";
            stockSupplierList.replaceChildren();
            if (!Array.isArray(suppliers) || suppliers.length === 0) {
                stockSupplierInstructions.hidden = true;
                const emptyMessage = document.createElement("p");
                emptyMessage.className = "text-secondary mb-0";
                emptyMessage.textContent = "No suppliers are assigned. The stock receipt will be recorded without a supplier.";
                stockSupplierList.appendChild(emptyMessage);
                confirmStockIncreaseButton.disabled = false;
            } else {
                stockSupplierInstructions.hidden = false;
                confirmStockIncreaseButton.disabled = true;
                suppliers.forEach(function (supplier) {
                    const supplierCard = document.createElement("label");
                    supplierCard.className = "stock-supplier-card";
                    const supplierChoice = document.createElement("input");
                    supplierChoice.type = "radio";
                    supplierChoice.name = "stock-increase-supplier";
                    supplierChoice.value = String(supplier.SupplierID || "");
                    supplierChoice.required = true;
                    supplierChoice.checked = false;
                    supplierChoice.addEventListener("change", function () {
                        editStockSupplierIdInput.value = supplierChoice.value;
                        confirmStockIncreaseButton.disabled = false;
                        stockSupplierList.querySelectorAll(".stock-supplier-card").forEach(function (card) {
                            card.classList.toggle(
                                "selected",
                                card.querySelector('input[name="stock-increase-supplier"]').checked
                            );
                        });
                    });
                    supplierCard.classList.remove("selected");
                    supplierCard.appendChild(supplierChoice);
                    const supplierName = document.createElement("strong");
                    supplierName.textContent = supplier.SupplierName || "Unnamed supplier";
                    supplierCard.appendChild(supplierName);

                    [
                        ["Contact", supplier.ContactName],
                        ["Phone", supplier.Phone],
                        ["Email", supplier.Email]
                    ].forEach(function (detail) {
                        if (!detail[1]) {
                            return;
                        }
                        const line = document.createElement("p");
                        line.textContent = detail[0] + ": " + detail[1];
                        supplierCard.appendChild(line);
                    });
                    stockSupplierList.appendChild(supplierCard);
                });
                confirmStockIncreaseButton.disabled = true;
            }
            editInventoryItemDialog.close();
            stockSupplierDialog.showModal();
        });
        document.getElementById("cancel-stock-increase").addEventListener("click", function () {
            editStockSupplierIdInput.value = "";
            stockSupplierDialog.close();
            editInventoryItemDialog.showModal();
        });
        document.getElementById("confirm-stock-increase").addEventListener("click", function () {
            if (!editInventoryItemForm.reportValidity()) {
                stockSupplierDialog.close();
                editInventoryItemDialog.showModal();
                return;
            }
            const selectedSupplier = stockSupplierList.querySelector('input[name="stock-increase-supplier"]:checked');
            editStockSupplierIdInput.value = selectedSupplier ? selectedSupplier.value : "";
            stockSupplierConfirmationAccepted = true;
            stockSupplierDialog.close();
            editInventoryItemDialog.showModal();
            editInventoryItemForm.requestSubmit();
        });
        const supplierTransactionsDialog = document.getElementById("supplier-transactions-dialog");
        const viewSupplierTransactionsButton = document.getElementById("view-supplier-transactions");
        const closeSupplierTransactionsButton = document.getElementById("close-supplier-transactions");
        if (supplierTransactionsDialog && viewSupplierTransactionsButton && closeSupplierTransactionsButton) {
            viewSupplierTransactionsButton.addEventListener("click", function () {
                supplierTransactionsDialog.showModal();
            });
            closeSupplierTransactionsButton.addEventListener("click", function () {
                supplierTransactionsDialog.close();
            });
        }
        document.querySelectorAll(".delete-inventory-item-form").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                const itemName = form.querySelector("button").dataset.itemName;
                if (!window.confirm("Delete " + itemName + " from inventory? This cannot be undone.")) {
                    event.preventDefault();
                }
            });
        });
        document.getElementById("cancel-edit-inventory-item").addEventListener("click", function () {
            editInventoryItemDialog.close();
        });
        document.querySelectorAll(".password-visibility-button").forEach(function (button) {
            button.addEventListener("click", function () {
                const passwordInput = document.getElementById(button.dataset.passwordTarget);
                const shouldShowPassword = passwordInput.type === "password";
                passwordInput.type = shouldShowPassword ? "text" : "password";
                button.setAttribute("aria-pressed", String(shouldShowPassword));
                button.setAttribute("aria-label", shouldShowPassword ? "Hide password" : "Show password");
            });
        });
        document.querySelectorAll(".edit-user-button").forEach(function (button) {
            button.addEventListener("click", function () {
                document.getElementById("edit-user-id").value = button.dataset.userId;
                document.getElementById("edit-firstname").value = button.dataset.firstname;
                document.getElementById("edit-lastname").value = button.dataset.lastname;
                document.getElementById("edit-username").value = button.dataset.username;
                document.getElementById("edit-email").value = button.dataset.email;
                document.getElementById("edit-password").value = "";
                document.getElementById("edit-role").value = button.dataset.role;
                document.getElementById("edit-access").value = button.dataset.access;
                editUserDialog.showModal();
            });
        });
        document.getElementById("cancel-edit-user").addEventListener("click", function () {
            editUserDialog.close();
        });
        document.querySelectorAll(".delete-user-button").forEach(function (button) {
            button.form.addEventListener("submit", function (event) {
                if (!window.confirm("Delete user " + button.dataset.username + "? This cannot be undone.")) {
                    event.preventDefault();
                }
            });
        });
        <?php if ($userCreateError !== '' || $userCreateNotice !== ''): ?>
        addUserDialog.showModal();
        <?php endif; ?>
        <?php if ($itemCreateError !== ''): ?>
        addInventoryItemDialog.showModal();
        <?php endif; ?>
        <?php if ($itemUpdateError !== ''): ?>
        editInventoryItemDialog.showModal();
        <?php endif; ?>
        <?php if ($productCreateError !== ''): ?>
        setProductCategory("flowers");
        addProductDialog.showModal();
        <?php endif; ?>

        document.getElementById("logout-button").addEventListener("click", function () {
            logoutDialog.showModal();
        });
        document.getElementById("cancel-logout").addEventListener("click", function () {
            logoutDialog.close();
        });
    </script>
    <?php endif; ?>
</body>
</html>