<?php

require_once("mysql-config.php");

function getTotal($mysqli) {
    $total = 0;
    if (isset($_SESSION['cart']) && count($_SESSION['cart'])) {
        $result = $mysqli->query("SELECT itemId, price FROM cart WHERE itemId IN (" . implode(", ", array_keys($_SESSION['cart'])) . ")");
        while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
            $total += $row['price'] * $_SESSION['cart'][$row['itemId']];
        }
        $result->close();
    }
    return $total;
}

function deleteFromCart($mysqli, $itemId) {
    unset($_SESSION['cart'][$itemId]);
    if (isset($_SESSION['userid'])) {
        $statement = $mysqli->prepare('DELETE FROM cart WHERE accountId = ? AND itemId = ?');
        if ($statement) {
            $statement->bind_param('ii', $_SESSION['userid'], $itemId);
            $result = $statement->execute();
            $statement->close();
        }
        if (!$statement || !$result) {
            return false;
        }
    }
    return true;
}

function updateCartDB($mysqli, $items) {
    if (isset($_SESSION['userid']) && count($items) > 0) {
        $itemValues = array();
        foreach ($items as $key => $value) {
            $itemValues[] = "({$_SESSION['userid']}, $key, $value)";
        }
        $result = $mysqli->query("INSERT INTO cart (accountId, itemId, quantity) VALUES " . implode(', ', $itemValues) . " ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
        if (!$result) {
            return false;
        }
    }
    return true;
}

function syncCartSessionWithDB($mysqli, $accountId) {
    $mysqli->query("UPDATE cart INNER JOIN inventory ON cart.itemId = inventory.id SET cart.price = IF(cart.price <> inventory.price, inventory.price, cart.price) WHERE accountId = $accountId");
    $result = $mysqli->query("SELECT itemId, price, quantity FROM cart WHERE accountId = $accountId");
    while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
        $_SESSION['cart'][$row['itemId']] = $row['quantity'];
    }
    $result->close();
}

function getAddressFromId($mysqli, $addrId) {
    $addr_result = $mysqli->query("SELECT name, address, city, state, zip FROM address WHERE id = $addrId AND accountId = {$_SESSION['userid']}");
    return $addr_result->fetch_array(MYSQLI_ASSOC);
}

function insertOrderItems($mysqli, $orderId) {
    $itemsResult = $mysqli->query("INSERT INTO order_items (accountId, orderId, itemId, quantity, price) SELECT '{$_SESSION['userid']}', '$orderId', itemId, quantity, price FROM `cart` WHERE accountId = '{$_SESSION['userid']}' AND `itemId` IN (" . implode(", ", array_keys($_SESSION['cart'])) . ")");
    $deleteCartResult = $mysqli->query("DELETE FROM cart WHERE accountId = '{$_SESSION['userid']}' AND itemId IN (" . implode(", ", array_keys($_SESSION['cart'])) . ")");
    return $itemsResult && $deleteCartResult;
}

function placeOrder($mysqli, $addrId, $last4) {
    $mysqli->autocommit(false);
    $address = getAddressFromId($mysqli, $addrId);
    $address_statement = $mysqli->prepare("INSERT INTO `order` (`accountId`, `name`, `address_pt1`, `address_pt2`, `warehouseId`, `total`, `last4`, `status`) VALUES (?, ?, ?, ?, 1, ?, ?, 'SHIPPING')");
    if ($address_statement) {
        $total = getTotal($mysqli);
        $addressPt2 = $address['city'] . ', ' . $address['state'] . ' ' . $address['zip'];
        $address_statement->bind_param('isssdi', $_SESSION['userid'], $address['name'], $address['address'], $addressPt2, $total, $last4);
        $orderResult = $address_statement->execute();
        $address_statement->close();
    }
    if ($orderResult) {
        $orderId = $mysqli->insert_id;
        $insertResult = insertOrderItems($mysqli, $orderId);
    }
    if (!$address_statement || !$orderResult || !$insertResult || !$mysqli->commit()) {
        return ["success" => "false", "message" => "An error occured while placing your order."];
    } else {
        unset($_SESSION['cart']);
        return ["success" => "true", "order_id" => $orderId];
    }
}

if (isset($_GET['action'])) {
    session_start();
    $mysqli = new mysqli($mysql['host'], $mysql['user'], $mysql['pass'], $mysql['db']);
    if ($mysqli === null) {
        echo "An error occured while accessing the database.";
        return;
    }
    switch ($_GET['action']) {
        case "add":
            if (isset($_GET['item-id']) && isset($_GET['quantity']) && is_numeric($_GET['item-id']) && is_numeric($_GET['quantity'])) {
                if (isset($_SESSION['cart'][$_GET['item-id']])) {
                    $_SESSION['cart'][$_GET['item-id']] += $_GET['quantity'];
                } else {
                    //$_SESSION['cart'][$_GET['item-id']] = ["price" => "bar", "quantity" => $_GET['quantity']];
                    $_SESSION['cart'][$_GET['item-id']] = $_GET['quantity'];
                }

                if (isset($_SESSION['userid'])) {
                    $itemId = $_GET['item-id'];
                    $sql = "SET @price := (SELECT price FROM inventory WHERE id = $itemId); ";
                    $sql .= "INSERT INTO cart (accountId, itemId, price, quantity) VALUES ({$_SESSION['userid']}, $itemId, @price, {$_SESSION['cart'][$_GET['item-id']]}) ON DUPLICATE KEY UPDATE price = VALUES(price), quantity = VALUES(quantity)";
                    $result = $mysqli->multi_query($sql);
                    if (!$result) {
                        $mysqli->close();
                        echo "An error occured while adding to your cart.";
                        return;
                    }
                }
                $count = count($_SESSION['cart']);
                if ($count > 0) {
                    echo array_sum(array_values($_SESSION['cart']));
                }
            }
            break;
        case "remove":
            if (isset($_GET['item-id']) && is_numeric($_GET['item-id'])) {
                $success = deleteFromCart($mysqli, $_GET['item-id']);
                if ($success) {
                    echo json_encode(array("success" => "true", "count" => isset($_SESSION['cart']) ? array_sum(array_values($_SESSION['cart'])) : 0, "total" => getTotal($mysqli)));
                } else {
                    echo json_encode(array("success" => "false", "message" => "An error occured while deleting from your cart."));
                }
            }
            break;
        case "update":
            unset($_SESSION['cart']);
            $newItems = json_decode($_POST['items']);
            foreach ($newItems as $key => $value) {
                if (is_numeric($key) && is_numeric($value)) {
                    if ($value != 0) {
                        $_SESSION['cart'][$key] = $value;
                    } else {
                        deleteFromCart($mysqli, $_SESSION['cart'][$key]);
                    }
                }
            }
            $count = 0;
            if (isset($_SESSION['cart'])) {
                updateCartDB($mysqli, $_SESSION['cart']);
                $count = array_sum(array_values($_SESSION['cart']));
            }
            echo json_encode(array("success" => "true", "count" => $count, "total" => getTotal($mysqli)));
            break;
    }
    $mysqli->close();
}
?>