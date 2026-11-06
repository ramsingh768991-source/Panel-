<?php
/**
 * api/check-payment.php - AJAX endpoint for client-side payment polling.
 *
 * Called by the payment-success page's JavaScript auto-retry to check
 * if a payment has been confirmed by the gateway. Uses the gateway's
 * /api/check-order-status endpoint so the order is actively looked for,
 * not just read from a DB.
 *
 * Returns JSON:
 *   { "success": true,  "status": "paid",    "utr": "...", "fulfilled": true/false }
 *   { "success": true,  "status": "pending", "fulfilled": false }
 *   { "success": false, "error": "..." }
 *
 * No login required (called via AJAX from payment-success page which may
 * have recovered the session). Order ownership is verified via the
 * payment_orders table.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/payment.php';

$paymentId = trim($_GET['oid'] ?? $_GET['order_id'] ?? $_GET['payment_id'] ?? $_POST['oid'] ?? $_POST['order_id'] ?? $_POST['payment_id'] ?? '');

if (empty($paymentId)) {
    echo json_encode(['success' => false, 'error' => 'Payment ID required']);
    exit;
}

// Check if the order is already fulfilled locally (fast path)
ensurePaymentOrdersTable();
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM payment_orders WHERE payment_id = ? LIMIT 1");
    $stmt->execute([$paymentId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Payment order not found']);
        exit;
    }

    // Already paid locally — return immediately
    if ($order['status'] === 'paid' || $order['status'] === 'completed') {
        echo json_encode([
            'success'    => true,
            'status'     => 'paid',
            'utr'        => $order['utr'],
            'fulfilled'  => true,
            'purpose'    => $order['purpose'],
        ]);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
    exit;
}

// Query the gateway. Single call — the JS will retry.
$gw = checkPaymentStatus($paymentId);

if (!empty($gw['success']) && isset($gw['status']) && strcasecmp($gw['status'], 'paid') === 0) {
    // Gateway says paid — fulfill the order!
    $utr = $gw['utr'] ?? null;
    $fulfilled = fulfillPaymentOrder($paymentId, $utr);

    echo json_encode([
        'success'    => true,
        'status'     => 'paid',
        'utr'        => $utr,
        'fulfilled'  => (bool)$fulfilled,
        'purpose'    => $order['purpose'] ?? 'wallet',
    ]);
} elseif (!empty($gw['success'])) {
    // Gateway says pending/failed — not paid yet
    echo json_encode([
        'success'    => true,
        'status'     => $gw['status'],
        'fulfilled'  => false,
    ]);
} else {
    // Gateway check failed (network/invalid token/etc.)
    echo json_encode([
        'success'    => false,
        'error'      => $gw['error'] ?? 'Gateway check failed',
        'fulfilled'  => false,
    ]);
}
