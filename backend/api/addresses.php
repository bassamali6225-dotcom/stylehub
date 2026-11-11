<?php
/**
 * StyleHub - Customer Address Management API
 * Add, edit, delete, set default, and list addresses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/notifications.php';

// Address management is strictly for logged-in customers
Auth::requireCustomer(true);

$pdo = getDB();
$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id, full_name, phone, address_line1, address_line2,
                city, state, postal_code, country, is_default, created_at
            FROM `addresses`
            WHERE user_id = :uid
            ORDER BY is_default DESC, id DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $addresses = $stmt->fetchAll();

        ApiResponse::success('Addresses loaded', [
            'count'     => count($addresses),
            'addresses' => $addresses
        ]);
    } catch (Exception $e) {
        error_log("Addresses GET Error: " . $e->getMessage());
        ApiResponse::error('Failed to load addresses', 500);
    }
}

if ($method === 'POST') {
    CSRF::enforce();

    $rawInput = file_get_contents('php://input');
    $data = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) $data = $decoded;
    }
    if (empty($data)) $data = $_POST;

    $action       = trim((string)($data['action'] ?? 'add'));
    $addressId    = isset($data['address_id']) ? (int)$data['address_id'] : 0;
    $fullName     = trim((string)($data['full_name'] ?? ''));
    $phone        = trim((string)($data['phone'] ?? ''));
    $addressLine1 = trim((string)($data['address_line1'] ?? ''));
    $addressLine2 = trim((string)($data['address_line2'] ?? ''));
    $city         = trim((string)($data['city'] ?? ''));
    $state        = trim((string)($data['state'] ?? ''));
    $postalCode   = trim((string)($data['postal_code'] ?? ''));
    $country      = trim((string)($data['country'] ?? 'United States'));
    $isDefault    = !empty($data['is_default']) ? 1 : 0;

    try {
        switch ($action) {
            case 'add':
                if (empty($fullName) || empty($phone) || empty($addressLine1) || empty($city) || empty($postalCode)) {
                    ApiResponse::error('Please fill in all required address fields.', 422);
                }

                // If setting as default or if this is user's first address, clear others
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM `addresses` WHERE user_id = :uid");
                $cntStmt->execute([':uid' => $userId]);
                $hasAddresses = (int)$cntStmt->fetchColumn();
                if ($isDefault || $hasAddresses === 0) {
                    $isDefault = 1;
                    $pdo->prepare("UPDATE `addresses` SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO `addresses` 
                    (`user_id`, `full_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `is_default`)
                    VALUES (:uid, :fn, :ph, :a1, :a2, :city, :st, :pc, :co, :def)
                ");
                $stmt->execute([
                    ':uid'  => $userId,
                    ':fn'   => $fullName,
                    ':ph'   => $phone,
                    ':a1'   => $addressLine1,
                    ':a2'   => $addressLine2 ?: null,
                    ':city' => $city,
                    ':st'   => $state ?: 'N/A',
                    ':pc'   => $postalCode,
                    ':co'   => $country,
                    ':def'  => $isDefault
                ]);

                $newAddrId = (int)$pdo->lastInsertId();

                AdminNotification::create([
                    'notification_type' => 'address_added',
                    'title'             => 'Customer Added Address',
                    'message'           => "Customer saved new address: {$addressLine1}, {$city}, {$country}.",
                    'related_user_id'   => $userId
                ], $pdo);

                ApiResponse::success('Address saved successfully.', [
                    'address_id' => $newAddrId
                ], 201);
                break;

            case 'edit':
                if ($addressId <= 0) {
                    ApiResponse::error('Address ID required.', 422);
                }

                // Verify ownership
                $chk = $pdo->prepare("SELECT id FROM `addresses` WHERE id = :id AND user_id = :uid LIMIT 1");
                $chk->execute([':id' => $addressId, ':uid' => $userId]);
                if (!$chk->fetch()) {
                    ApiResponse::error('Address not found.', 404);
                }

                if ($isDefault) {
                    $pdo->prepare("UPDATE `addresses` SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
                }

                $stmt = $pdo->prepare("
                    UPDATE `addresses` SET
                        full_name = :fn,
                        phone = :ph,
                        address_line1 = :a1,
                        address_line2 = :a2,
                        city = :city,
                        state = :st,
                        postal_code = :pc,
                        country = :co,
                        is_default = :def
                    WHERE id = :id AND user_id = :uid
                ");
                $stmt->execute([
                    ':fn'   => $fullName,
                    ':ph'   => $phone,
                    ':a1'   => $addressLine1,
                    ':a2'   => $addressLine2 ?: null,
                    ':city' => $city,
                    ':st'   => $state ?: 'N/A',
                    ':pc'   => $postalCode,
                    ':co'   => $country,
                    ':def'  => $isDefault,
                    ':id'   => $addressId,
                    ':uid'  => $userId
                ]);

                ApiResponse::success('Address updated successfully.');
                AdminNotification::create([
                    'notification_type' => 'address_updated',
                    'title'             => 'Customer Updated Address',
                    'message'           => "Customer modified address #{$addressId}: {$addressLine1}, {$city}.",
                    'related_user_id'   => $userId
                ], $pdo);
                break;

            case 'delete':
                if ($addressId <= 0) {
                    ApiResponse::error('Address ID required.', 422);
                }

                $del = $pdo->prepare("DELETE FROM `addresses` WHERE id = :id AND user_id = :uid");
                $del->execute([':id' => $addressId, ':uid' => $userId]);

                // If deleted was default, promote another address to default
                $remaining = $pdo->prepare("SELECT id FROM `addresses` WHERE user_id = :uid ORDER BY id DESC LIMIT 1");
                $remaining->execute([':uid' => $userId]);
                $nextId = $remaining->fetchColumn();
                if ($nextId) {
                    $pdo->prepare("UPDATE `addresses` SET is_default = 1 WHERE id = :id")->execute([':id' => $nextId]);
                }

                ApiResponse::success('Address removed.');
                AdminNotification::create([
                    'notification_type' => 'address_deleted',
                    'title'             => 'Customer Deleted Address',
                    'message'           => "Customer removed delivery address #{$addressId}.",
                    'related_user_id'   => $userId
                ], $pdo);
                break;

            case 'set_default':
                if ($addressId <= 0) {
                    ApiResponse::error('Address ID required.', 422);
                }

                $pdo->prepare("UPDATE `addresses` SET is_default = 0 WHERE user_id = :uid")->execute([':uid' => $userId]);
                $upd = $pdo->prepare("UPDATE `addresses` SET is_default = 1 WHERE id = :id AND user_id = :uid");
                $upd->execute([':id' => $addressId, ':uid' => $userId]);

                ApiResponse::success('Default address updated.');
                break;

            default:
                ApiResponse::error('Unknown address action.', 400);
        }

    } catch (Exception $e) {
        error_log("Address Action Error: " . $e->getMessage());
        ApiResponse::error('Failed to process address operation.', 500);
    }
}

