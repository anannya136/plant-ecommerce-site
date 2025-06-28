<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

function getDbConnection() {
    try {
        $db = new PDO('sqlite:'. __DIR__ .'/gachpala.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        sendJsonResponse(['success' => false, 'message' => 'Database connection failed.']);
    }
}

function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$request_body = file_get_contents('php://input');
$data = json_decode($request_body, true);
$action = $data['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    sendJsonResponse(['success' => false, 'message' => 'No action specified.'], 400);
}

$db = getDbConnection();
$user_id = $_SESSION['user_id'] ?? null;

if ($action === 'signup') {
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) { sendJsonResponse(['success' => false, 'message' => 'Please fill all fields.'], 400); }
    $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
    try {
        $stmt = $db->prepare("INSERT INTO users (name, email, password, created_at) VALUES (:name, :email, :password, :created_at)");
        $stmt->execute([':name' => $data['name'], ':email' => $data['email'], ':password' => $hashed_password, ':created_at' => date('Y-m-d H:i:s')]);
        sendJsonResponse(['success' => true, 'message' => 'Signup successful! You can now log in.']);
    } catch (PDOException $e) { sendJsonResponse(['success' => false, 'message' => 'This email is already registered.'], 409); }
} elseif ($action === 'login') {
    if (empty($data['email']) || empty($data['password'])) { sendJsonResponse(['success' => false, 'message' => 'Please fill all fields.'], 400); }
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute([':email' => $data['email']]);
    $user = $stmt->fetch();
    if ($user && password_verify($data['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        sendJsonResponse(['success' => true, 'message' => 'Login successful!', 'user' => ['name' => $user['name']]]);
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Invalid email or password.'], 401);
    }
} elseif ($action === 'logout') {
    session_destroy();
    sendJsonResponse(['success' => true, 'message' => 'Logged out successfully.']);
} elseif ($action === 'check_session') {
    if ($user_id) {
        sendJsonResponse(['success' => true, 'loggedIn' => true, 'user' => ['name' => $_SESSION['user_name']]]);
    } else {
        sendJsonResponse(['success' => true, 'loggedIn' => false]);
    }
} elseif ($action === 'get_profile_data') {
    if (!$user_id) { sendJsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401); }
    $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = :user_id ORDER BY ordered_at DESC");
    $stmt->execute([':user_id' => $user_id]);
    $orders = $stmt->fetchAll();
    sendJsonResponse(['success' => true, 'user' => ['name' => $_SESSION['user_name']], 'orders' => $orders]);
} elseif ($action === 'get_cart') {
    if (!$user_id) { sendJsonResponse(['success' => true, 'cart' => []]); } 
    $stmt = $db->prepare("SELECT * FROM cart_items WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    sendJsonResponse(['success' => true, 'cart' => $stmt->fetchAll()]);
} elseif ($action === 'add_to_cart') {
    if (!$user_id) { sendJsonResponse(['success' => false, 'message' => 'Please log in to add items to your cart.'], 401); }
    $item = $data['item'];
    $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = :user_id AND product_id = :product_id");
    $stmt->execute([':user_id' => $user_id, ':product_id' => $item['id']]);
    $existing = $stmt->fetch();
    if ($existing) {
        $stmt = $db->prepare("UPDATE cart_items SET quantity = quantity + 1 WHERE id = :id");
        $stmt->execute([':id' => $existing['id']]);
    } else {
        $stmt = $db->prepare("INSERT INTO cart_items (user_id, product_id, product_name, price, quantity) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $item['id'], $item['name'], $item['price'], 1]);
    }
    sendJsonResponse(['success' => true]);
} elseif ($action === 'update_cart_quantity') {
    if (!$user_id) { sendJsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401); }
    $productId = $data['productId'];
    $change = $data['change'];
    $stmt = $db->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
    $stmt->execute([$user_id, $productId]);
    $item = $stmt->fetch();
    if ($item) {
        $newQty = $item['quantity'] + $change;
        if ($newQty > 0) {
            $updateStmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $updateStmt->execute([$newQty, $item['id']]);
        } else {
            $deleteStmt = $db->prepare("DELETE FROM cart_items WHERE id = ?");
            $deleteStmt->execute([$item['id']]);
        }
    }
    sendJsonResponse(['success' => true]);
} elseif ($action === 'clear_cart') {
    if (!$user_id) { sendJsonResponse(['success' => false, 'message' => 'Not authenticated.'], 401); }
    $stmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
    $stmt->execute([$user_id]);
    sendJsonResponse(['success' => true]);
} elseif ($action === 'checkout') {
    if (!$user_id) { sendJsonResponse(['success' => false, 'message' => 'Please log in to check out.'], 401); }
    $cartStmt = $db->prepare("SELECT * FROM cart_items WHERE user_id = ?");
    $cartStmt->execute([$user_id]);
    $items = $cartStmt->fetchAll();
    if (empty($items)) { sendJsonResponse(['success' => false, 'message' => 'Your cart is empty.'], 400); }
    $order_group_id = uniqid('order_', true);
    $db->beginTransaction();
    try {
        $orderStmt = $db->prepare("INSERT INTO orders (order_group_id, user_id, product_name, quantity, price_per_item, ordered_at) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($items as $item) {
            $orderStmt->execute([$order_group_id, $user_id, $item['product_name'], $item['quantity'], $item['price'], date('Y-m-d H:i:s')]);
        }
        $clearStmt = $db->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $clearStmt->execute([$user_id]);
        $db->commit();
        sendJsonResponse(['success' => true, 'message' => 'Order placed successfully!']);
    } catch (PDOException $e) {
        $db->rollBack();
        sendJsonResponse(['success' => false, 'message' => 'Failed to place order.'], 500);
    }
} else {
    sendJsonResponse(['success' => false, 'message' => 'Invalid action specified.'], 400);
}
?>