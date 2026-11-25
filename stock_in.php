
<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (is_owner()) {
    header('Location: owner_db.php');
    exit;
}

$page_title = 'Stock In';
$isPopup = isset($_GET['popup']) && $_GET['popup'] === '1';

$errorMessage = '';
$successMessage = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];
$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Find the logged-in staff member's assigned branch
|--------------------------------------------------------------------------
| Supports users.branch_name containing either the branch ID (b1)
| or the branch name (Inabanga Branch).
*/

$branchId = null;
$branchName = '';

try {
    $stmt = $pdo->prepare(
        "SELECT b.id, b.branch_name
         FROM users u
         INNER JOIN branches b
           ON CAST(b.id AS CHAR) = CAST(u.branch_name AS CHAR)
           OR b.branch_name = u.branch_name
         WHERE u.id = ?
           AND u.role = 'staff'
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($branch) {
        $branchId = (string) $branch['id'];
        $branchName = $branch['branch_name'];
    } else {
        $errorMessage = 'Your account has no valid assigned branch. Please contact the owner.';
    }
} catch (Throwable $e) {
    error_log('Stock-in branch lookup failed: ' . $e->getMessage());
    $errorMessage = 'Unable to identify your assigned branch. Please check your database.';
}

/*
|--------------------------------------------------------------------------
| Load products
|--------------------------------------------------------------------------
*/

$products = [];

try {
    $stmt = $pdo->query(
        "SELECT id, product_name, category, price
         FROM products
         ORDER BY product_name ASC"
    );

    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Stock-in product lookup failed: ' . $e->getMessage());
    $errorMessage = 'Unable to load products. Please check your products table.';
}

/*
|--------------------------------------------------------------------------
| Record incoming stock
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        $errorMessage = 'Your session token is invalid. Please refresh and try again.';
    } elseif ($branchId === null) {
        $errorMessage = 'No valid branch is assigned to your account.';
    } else {
        $productId = filter_var(
            $_POST['product_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $_POST['quantity'] ?? '',
            FILTER_VALIDATE_INT
        );

        $supplier = trim($_POST['supplier'] ?? '');
        $deliveryReference = trim($_POST['delivery_reference'] ?? '');

        if (!$productId || $productId < 1) {
            $errorMessage = 'Please select a valid product.';
        } elseif ($quantity === false || $quantity < 1) {
            $errorMessage = 'Quantity must be at least 1.';
        } elseif (strlen($supplier) > 150) {
            $errorMessage = 'Supplier name must not exceed 150 characters.';
        } elseif (strlen($deliveryReference) > 100) {
            $errorMessage = 'Delivery reference must not exceed 100 characters.';
        } else {
            try {
                $pdo->beginTransaction();

                
// Verify that the selected product exists
// and retrieve its details.
$stmt = $pdo->prepare(
    "SELECT id, product_name, category, price
     FROM products
     WHERE id = ?
     LIMIT 1"
);

$stmt->execute([$productId]);

$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    throw new RuntimeException('The selected product does not exist.');
}


                
/*
|--------------------------------------------------------------------------
| Check and update branch inventory
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT id, quantity
     FROM branch_inventory
     WHERE product_id = ?
       AND branch_id = ?
     LIMIT 1
     FOR UPDATE"
);

$stmt->execute([$productId, $branchId]);
$inventory = $stmt->fetch(PDO::FETCH_ASSOC);

if ($inventory) {

    // Add received quantity to existing inventory.
    $stmt = $pdo->prepare(
        "UPDATE branch_inventory
         SET quantity = quantity + ?
         WHERE id = ?"
    );

    $stmt->execute([
        $quantity,
        $inventory['id']
    ]);

} else {

    // Generate a unique inventory ID.
    $inventoryId = 'BI' . strtoupper(bin2hex(random_bytes(8)));

    // Create a new branch inventory record.
    $stmt = $pdo->prepare(
        "INSERT INTO branch_inventory
            (
                id,
                name,
                category,
                price,
                min_stock,
                branch_id,
                product_id,
                quantity
            )
         VALUES (?, ?, ?, ?, 5, ?, ?, ?)"
    );

    $stmt->execute([
        $inventoryId,
        $product['product_name'],
        $product['category'],
        $product['price'],
        $branchId,
        $productId,
        $quantity
    ]);
}


               
// Save the incoming-stock transaction.
$stmt = $pdo->prepare(
    "INSERT INTO stock_in
        (
            product_id,
            branch_id,
            quantity,
            supplier,
            delivery_reference,
            received_by,
            received_at
        )
     VALUES (?, ?, ?, ?, ?, ?, NOW())"
);

$stmt->execute([
    $productId,
    $branchId,
    $quantity,
    $supplier !== '' ? $supplier : null,
    $deliveryReference !== '' ? $deliveryReference : null,
    $userId
]);


                $pdo->commit();

                $_SESSION['stock_in_success'] =
                    'Stock-in recorded successfully! Inventory quantity has been updated.';

                $redirect = 'stock_in.php';

                if ($isPopup) {
                    $redirect .= '?popup=1&success=1';
                } else {
                    $redirect .= '?success=1';
                }

                header('Location: ' . $redirect);
                exit;
            } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Stock-in transaction failed: ' . $e->getMessage());

    $errorMessage = 'Database Error: ' . $e->getMessage();
}
        }
    }
}

if (isset($_GET['success']) && isset($_SESSION['stock_in_success'])) {
    $successMessage = $_SESSION['stock_in_success'];
    unset($_SESSION['stock_in_success']);
}

$selectedProductId = filter_var(
    $_GET['pid'] ?? '',
    FILTER_VALIDATE_INT
);

if ($selectedProductId === false) {
    $selectedProductId = null;
}

function stockInEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock In | BGS LPG Store</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            background: #f3f6fb;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
        }

        .stock-container {
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            background: #fff;
            border-radius: 12px;
            padding: 26px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.07);
        }

        h2 {
            margin: 0 0 8px;
            font-size: 24px;
            color: #123b69;
        }

        .subtitle {
            color: #64748b;
            margin: 0 0 24px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 7px;
        }

        input,
        select {
            display: block;
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            font: inherit;
            background: #fff;
        }

        input:focus,
        select:focus {
            outline: 2px solid #bfdbfe;
            border-color: #2563eb;
        }

        input[readonly] {
            background: #f1f5f9;
            color: #475569;
        }

        .buttons {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 24px;
        }

        button {
            border: 0;
            border-radius: 7px;
            padding: 11px 18px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: #1d4ed8;
            color: #fff;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
        }

        .notice {
            padding: 12px 14px;
            border-radius: 7px;
            margin-bottom: 18px;
            line-height: 1.5;
            transition: opacity .4s ease;
        }

        .notice.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .notice.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .notice.fade {
            opacity: 0;
        }

        .help-text {
            color: #64748b;
            font-size: 13px;
            margin-top: 6px;
        }

        @media (max-width: 500px) {
            body {
                padding: 12px;
            }

            .stock-container {
                padding: 18px;
            }

            .buttons button {
                flex: 1;
            }
        }
    </style>
</head>
<body>

<div class="stock-container">
    <h2>Stock In</h2>
    <p class="subtitle">
        Record products received from suppliers and update your branch inventory.
    </p>

    <?php if ($successMessage !== ''): ?>
        <div class="notice success" id="successNotice">
            <?= stockInEscape($successMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="notice error" id="errorNotice">
            <?= stockInEscape($errorMessage) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= stockInEscape($csrfToken) ?>"
        >

        <div class="form-group">
            <label for="branch_name">Assigned Branch</label>
            <input
                type="text"
                id="branch_name"
                value="<?= stockInEscape($branchName ?: 'No branch assigned') ?>"
                readonly
            >
        </div>

        <div class="form-group">
            <label for="product_id">Product</label>
            <select id="product_id" name="product_id" required>
                <option value="">-- Select Product --</option>

                <?php foreach ($products as $product): ?>
                    <option
                        value="<?= (int) $product['id'] ?>"
                        <?= (string) $selectedProductId === (string) $product['id'] ? 'selected' : '' ?>
                    >
                        <?= stockInEscape($product['product_name']) ?>
                        — ₱<?= number_format((float) $product['price'], 2) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="quantity">Quantity Received</label>
            <input
                type="number"
                id="quantity"
                name="quantity"
                min="1"
                step="1"
                required
                value="<?= stockInEscape($_POST['quantity'] ?? '1') ?>"
            >
        </div>

        <div class="form-group">
            <label for="supplier">Supplier (Optional)</label>
            <input
                type="text"
                id="supplier"
                name="supplier"
                maxlength="150"
                value="<?= stockInEscape($_POST['supplier'] ?? '') ?>"
                placeholder="Enter supplier name"
            >
        </div>

        <div class="form-group">
            <label for="delivery_reference">Delivery Reference (Optional)</label>
            <input
                type="text"
                id="delivery_reference"
                name="delivery_reference"
                maxlength="100"
                value="<?= stockInEscape($_POST['delivery_reference'] ?? '') ?>"
                placeholder="Enter delivery receipt or reference number"
            >
        </div>

        <div class="buttons">
            <button
                type="button"
                class="btn-secondary"
                id="cancelButton"
            >
                Cancel
            </button>

            <button
                type="submit"
                class="btn-primary"
                id="submitButton"
                <?= $branchId === null || count($products) === 0 ? 'disabled' : '' ?>
            >
                Save Stock In
            </button>
        </div>
    </form>
</div>


<script>
    // Show the success message for 2.5 seconds,
    // fade it out, then notify the inventory page.
    const successNotice = document.getElementById('successNotice');

    if (successNotice) {
        setTimeout(function () {
            successNotice.classList.add('fade');

            setTimeout(function () {
                successNotice.remove();

                if (window.parent && window.parent !== window) {
                    window.parent.postMessage(
                        { type: 'stockInSaved' },
                        window.location.origin
                    );
                } else {
                    window.location.href = 'staff_product.php';
                }
            }, 400);
        }, 2500);
    }

    // Fade error messages without closing the form.
    const errorNotice = document.getElementById('errorNotice');

    if (errorNotice) {
        setTimeout(function () {
            errorNotice.classList.add('fade');

            setTimeout(function () {
                errorNotice.remove();
            }, 400);
        }, 2500);
    }

    // Prevent accidental double submissions.
    const stockForm = document.querySelector('form');
    const submitButton = document.getElementById('submitButton');

    stockForm.addEventListener('submit', function () {
        submitButton.disabled = true;
        submitButton.textContent = 'Saving...';
    });

    // Close the popup when Cancel is clicked.
    document.getElementById('cancelButton').addEventListener('click', function () {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage(
                { type: 'closeStockInPopup' },
                window.location.origin
            );
        } else {
            window.location.href = 'staff_product.php';
        }
    });
</script>

</body>
</html>
