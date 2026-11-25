
<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$page_title = 'Record Sale';
$isOwner = is_owner();

$branchId = null;
$branchName = '';
$branchError = '';
$products = [];

$successMessage = $_SESSION['sale_success'] ?? '';
$errorMessage = $_SESSION['sale_error'] ?? '';

unset($_SESSION['sale_success'], $_SESSION['sale_error']);

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| RESOLVE ASSIGNED BRANCH
|--------------------------------------------------------------------------
*/

try {

    if ($isOwner) {

        $branchId = current_branch();

        if ($branchId !== null && $branchId !== '') {

            $stmt = $pdo->prepare(
                "SELECT id, branch_name
                 FROM branches
                 WHERE CAST(id AS CHAR) = ?
                    OR branch_name = ?
                 LIMIT 1"
            );

            $stmt->execute([
                (string)$branchId,
                (string)$branchId
            ]);

            $branch = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($branch) {
                $branchId = $branch['id'];
                $branchName = $branch['branch_name'];
            } else {
                $branchId = null;
            }
        }

    } else {

        $stmt = $pdo->prepare(
            "SELECT branch_name
             FROM users
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->execute([$_SESSION['user_id']]);

        $staffBranch = $stmt->fetchColumn();

        if (
            $staffBranch !== false &&
            $staffBranch !== null &&
            $staffBranch !== ''
        ) {

            $stmt = $pdo->prepare(
                "SELECT id, branch_name
                 FROM branches
                 WHERE CAST(id AS CHAR) = ?
                    OR branch_name = ?
                 LIMIT 1"
            );

            $stmt->execute([
                (string)$staffBranch,
                (string)$staffBranch
            ]);

            $branch = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($branch) {
                $branchId = $branch['id'];
                $branchName = $branch['branch_name'];
            }
        }
    }

    if ($branchId === null || $branchId === '') {

        $branchError = $isOwner
            ? 'Please select or assign a valid branch before recording a sale.'
            : 'Your account does not have a valid assigned branch. Please contact the owner.';
    }

} catch (PDOException $e) {

    error_log('Record Sale branch error: ' . $e->getMessage());

    $branchError = 'Unable to identify your assigned branch. Please contact the owner.';

    $branchId = null;
}

/*
|--------------------------------------------------------------------------
| LOAD PRODUCTS AND BRANCH STOCK
|--------------------------------------------------------------------------
*/

if ($branchId !== null) {

    try {

        $stmt = $pdo->prepare(
            "SELECT
                p.id,
                p.product_name,
                p.category,
                p.price,
                COALESCE(bi.quantity, 0) AS stock_quantity
             FROM products p
             LEFT JOIN branch_inventory bi
                ON bi.product_id = p.id
               AND bi.branch_id = ?
             ORDER BY p.product_name ASC"
        );

        $stmt->execute([$branchId]);

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log('Record Sale product error: ' . $e->getMessage());

        $errorMessage = 'Unable to load products. Please check your database tables.';
    }
}

/*
|--------------------------------------------------------------------------
| PROCESS MULTIPLE PRODUCTS IN ONE SALE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf_token, $postedToken)) {

        $errorMessage = 'Invalid or expired form submission. Please try again.';

    } elseif ($branchId === null) {

        $errorMessage = $branchError !== ''
            ? $branchError
            : 'Your assigned branch could not be found.';

    } else {

        $productIds = $_POST['product_ids'] ?? [];
        $quantities = $_POST['quantities'] ?? [];

        $customerName = trim($_POST['customer_name'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? '');

        $allowedPayments = ['Cash', 'GCash'];

        if (!is_array($productIds) || !is_array($quantities)) {

            $errorMessage = 'Invalid product information.';

        } elseif (
            count($productIds) < 1 ||
            count($productIds) !== count($quantities) ||
            count($productIds) > 100
        ) {

            $errorMessage = 'Please add at least one valid product.';

        } elseif (!in_array($paymentMethod, $allowedPayments, true)) {

            $errorMessage = 'Please select a valid payment method.';

        } elseif (mb_strlen($customerName) > 150) {

            $errorMessage = 'Customer name must not exceed 150 characters.';

        } else {

            $saleItems = [];
            $validationError = '';

            /*
            |--------------------------------------------------------------------------
            | VALIDATE PRODUCT INPUTS
            |--------------------------------------------------------------------------
            */

            foreach ($productIds as $index => $rawProductId) {

                $productId = filter_var(
                    $rawProductId,
                    FILTER_VALIDATE_INT
                );

                $quantity = filter_var(
                    $quantities[$index] ?? null,
                    FILTER_VALIDATE_INT
                );

                if (!$productId || !$quantity || $quantity < 1) {

                    $validationError =
                        'Please select a product and enter a valid quantity for every product.';

                    break;
                }

                $saleItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity
                ];
            }

            if ($validationError !== '') {

                $errorMessage = $validationError;

            } else {

                try {

                    $pdo->beginTransaction();

                    /*
                    |--------------------------------------------------------------------------
                    | COMBINE QUANTITIES OF DUPLICATE PRODUCTS
                    |--------------------------------------------------------------------------
                    */

                    $requiredStock = [];

                    foreach ($saleItems as $item) {

                        $id = $item['product_id'];

                        if (!isset($requiredStock[$id])) {
                            $requiredStock[$id] = 0;
                        }

                        $requiredStock[$id] += $item['quantity'];
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CHECK AND LOCK STOCK
                    |--------------------------------------------------------------------------
                    */

                    $lockedProducts = [];

                    $stmt = $pdo->prepare(
                        "SELECT
                            p.id,
                            p.product_name,
                            p.price,
                            bi.quantity AS stock_quantity
                         FROM products p
                         INNER JOIN branch_inventory bi
                            ON bi.product_id = p.id
                           AND bi.branch_id = ?
                         WHERE p.id = ?
                         LIMIT 1
                         FOR UPDATE"
                    );

                    foreach ($requiredStock as $id => $requiredQuantity) {

                        $stmt->execute([
                            $branchId,
                            $id
                        ]);

                        $product = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$product) {

                            throw new RuntimeException(
                                'A selected product does not have an inventory record in this branch.'
                            );
                        }

                        $stock = (int)$product['stock_quantity'];

                        if ($stock < 1) {

                            throw new RuntimeException(
                                $product['product_name'] . ' is out of stock.'
                            );
                        }

                        if ($requiredQuantity > $stock) {

                            throw new RuntimeException(
                                'Insufficient stock for ' .
                                $product['product_name'] .
                                '. Available quantity: ' . $stock .
                                ', requested quantity: ' . $requiredQuantity . '.'
                            );
                        }

                        $lockedProducts[$id] = $product;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | CALCULATE INDIVIDUAL SUBTOTALS AND GRAND TOTAL
                    |--------------------------------------------------------------------------
                    */

                    $totalAmount = 0;

                    foreach ($saleItems as $index => $item) {

                        $product = $lockedProducts[$item['product_id']];

                        $price = (float)$product['price'];

                        $subtotal = $price * $item['quantity'];

                        $saleItems[$index]['subtotal'] = $subtotal;
                        $saleItems[$index]['product_name'] = $product['product_name'];

                        $totalAmount += $subtotal;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | DEDUCT STOCK FROM THE ASSIGNED BRANCH
                    |--------------------------------------------------------------------------
                    */

                    $updateStock = $pdo->prepare(
                        "UPDATE branch_inventory
                         SET quantity = quantity - ?
                         WHERE branch_id = ?
                           AND product_id = ?
                           AND quantity >= ?"
                    );

                    foreach ($requiredStock as $id => $requiredQuantity) {

                        $updateStock->execute([
                            $requiredQuantity,
                            $branchId,
                            $id,
                            $requiredQuantity
                        ]);

                        if ($updateStock->rowCount() !== 1) {

                            throw new RuntimeException(
                                'Stock could not be updated. Please refresh and try again.'
                            );
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | RECORD EACH PRODUCT IN branch_sales
                    |--------------------------------------------------------------------------
                    */

                    $saleDate = (new DateTimeImmutable(
                        'now',
                        new DateTimeZone('Asia/Manila')
                    ))->format('Y-m-d H:i:s');

                    $insertSale = $pdo->prepare(
                        "INSERT INTO branch_sales
                        (
                            branch_id,
                            user_id,
                            customer_name,
                            payment_method,
                            total_amount,
                            product_id,
                            quantity,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    foreach ($saleItems as $item) {

                        $insertSale->execute([
                            (string)$branchId,
                            $_SESSION['user_id'],
                            $customerName !== '' ? $customerName : null,
                            $paymentMethod,
                            $item['subtotal'],
                            $item['product_id'],
                            $item['quantity'],
                            $saleDate
                        ]);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | COMMIT SALE
                    |--------------------------------------------------------------------------
                    */

                    $pdo->commit();

                    $_SESSION['sale_success'] =
                        'Sale recorded successfully! ' .
                        count($saleItems) .
                        ' product item(s). Total: ₱' .
                        number_format($totalAmount, 2);

                    header('Location: record_sale.php');
                    exit;

                } catch (Throwable $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($e instanceof PDOException) {

                        error_log(
                            'Record Sale database error: ' . $e->getMessage()
                        );

                        $errorMessage =
                            'Unable to record the sale. Please check the database and try again.';

                    } else {

                        $errorMessage = $e->getMessage();
                    }
                }
            }
        }
    }
}

require_once 'includes/header.php';
?>

<style>

/* SIDEBAR */

.layout > .sidebar {
    background-color: #1D4ED8 !important;
    width: 220px;
    padding: 20px 12px;
    box-shadow: 1px 0 5px #0001;
    flex-shrink: 0;
}

.layout > .sidebar a {
    display: block;
    padding: 11px 14px;
    margin: 3px 0;
    border-radius: 8px;
    text-decoration: none;
    color: #FFFFFF !important;
}

.layout > .sidebar a:hover,
.layout > .sidebar a.active {
    background-color: #0F4C81 !important;
    color: #FFFFFF !important;
    border-left: none !important;
    font-weight: bold;
}

.layout > .sidebar a[href="logout.php"],
.layout a[href="logout.php"] {
    background-color: #DC2626 !important;
    color: #FFFFFF !important;
    margin-top: 15px;
    text-align: center;
    font-weight: bold;
    border-radius: 8px;
}

.layout > .content {
    font-size: 16px;
    font-family: inherit;
}

/* PAGE HEADER */

.sale-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.sale-header h1 {
    margin: 0;
    color: #20253B;
    font-size: 2em;
    font-weight: 700;
}

.muted {
    margin: 6px 0 0;
    color: #64748B;
    font-size: 16px;
}

/* SALE FORM PANEL */

.sale-panel {
    background-color: #8db4e7;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px #0000000D;
    margin-bottom: 24px;
}

.sale-panel h2 {
    margin: 0 0 20px;
    color: #20253B;
    font-size: 20px;
}

/* FORM FIELDS */

.sale-form {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
}

.sale-field {
    min-width: 0;
}

.sale-field.full {
    grid-column: 1 / -1;
}

.sale-field label {
    display: block;
    margin-bottom: 7px;
    color: #20253B;
    font-weight: 600;
}

.sale-field input,
.sale-field select {
    display: block;
    position: relative;
    z-index: 2;
    width: 100%;
    min-height: 44px;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    background-color: #FFFFFF;
    color: #20253B;
    font-family: inherit;
    font-size: 16px;
    pointer-events: auto;
    cursor: pointer;
}

.sale-field select:disabled {
    background-color: #F1F5F9;
    cursor: not-allowed;
    opacity: .7;
}

.sale-field input[readonly] {
    background-color: #F1F5F9;
    color: #475569;
}

.sale-field input:focus,
.sale-field select:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px #1D4ED81F;
}

.sale-field input:disabled,
.sale-field select:disabled {
    background-color: #F1F5F9;
    cursor: not-allowed;
    opacity: .7;
}

.field-hint {
    display: block;
    margin-top: 6px;
    color: #64748B;
    font-size: 13px;
}

/* MULTIPLE PRODUCTS */

.product-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.product-item {
    background: #FFFFFF;
    border: 1px solid #CBD5E1;
    border-radius: 8px;
    padding: 16px;
}

.product-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

.product-item-title {
    color: #20253B;
    font-weight: 700;
}

.product-row {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(100px, .8fr);
    gap: 15px;
}

.product-row label {
    display: block;
    margin-bottom: 7px;
    color: #20253B;
    font-weight: 600;
}

.product-row input,
.product-row select {
    width: 100%;
    min-height: 44px;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    background: #FFFFFF;
    color: #20253B;
    font-family: inherit;
    font-size: 16px;
}

.product-row input:focus,
.product-row select:focus {
    outline: none;
    border-color: #2563EB;
    box-shadow: 0 0 0 3px #1D4ED81F;
}

.product-subtotal {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #EFF6FF;
    border-radius: 6px;
    padding: 11px 13px;
    margin-top: 14px;
}

.product-subtotal strong {
    color: #1D4ED8;
    font-size: 17px;
}

.add-product-btn {
    width: 100%;
    margin-top: 14px;
    padding: 12px;
    border: 1px dashed #1D4ED8;
    border-radius: 6px;
    background: #EFF6FF;
    color: #1D4ED8;
    font-family: inherit;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.add-product-btn:hover {
    background: #DBEAFE;
}

.add-product-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.remove-product-btn {
    padding: 7px 11px;
    border: none;
    border-radius: 5px;
    background: #FEE2E2;
    color: #B91C1C;
    font-weight: 600;
    cursor: pointer;
}

.remove-product-btn:hover {
    background: #FECACA;
}

/* TOTAL DISPLAY */

.total-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background-color: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 8px;
}

.total-box span {
    color: #20253B;
    font-weight: 600;
}

.total-box strong {
    color: #1D4ED8;
    font-size: 24px;
}

/* BUTTON */

.sale-btn {
    cursor: pointer;
    padding: 11px 18px;
    border: none;
    border-radius: 6px;
    font-family: inherit;
    font-size: 15px;
    font-weight: 600;
    transition: background-color .2s ease;
    background-color: #1D4ED8;
    color: #FFFFFF;
}

.sale-btn:hover {
    background-color: #1E40AF;
}

.sale-btn:disabled {
    opacity: .6;
    cursor: not-allowed;
}

.sale-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* NOTIFICATIONS */

.alert {
    padding: 12px 14px;
    border-radius: 6px;
    margin-bottom: 15px;
    background-color: #DCFCE7;
    color: #166534;
    border: 1px solid #BBF7D0;
    font-size: 16px;
    opacity: 1;
    transition: opacity .5s ease;
}

.alert.error {
    background-color: #FEE2E2;
    color: #991B1B;
    border-color: #FECACA;
}

.alert.warning {
    background-color: #FEF3C7;
    color: #92400E;
    border-color: #FDE68A;
}

.alert.fade-out {
    opacity: 0;
}

/* RESPONSIVE */

@media (max-width: 800px) {
    .sale-form {
        grid-template-columns: 1fr;
    }

    .sale-field.full {
        grid-column: 1;
    }
}

@media (max-width: 700px) {
    .sale-panel {
        padding: 16px;
    }
}

@media (max-width: 600px) {
    .sale-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .total-box {
        align-items: flex-start;
        flex-direction: column;
    }

    .total-box strong {
        font-size: 22px;
    }

    .product-row {
        grid-template-columns: 1fr;
    }
}

</style>

<div class="sale-header">
    <div>
        <h1>Record Sale</h1>
        <p class="muted">
            Record customer purchases and update your branch inventory.
        </p>
    </div>
</div>

<!-- SUCCESS NOTIFICATION -->

<div id="saleNotification"
     class="alert"
     role="status"
     aria-live="polite"
     style="<?= $successMessage === '' ? 'display:none' : '' ?>">

    <?= h($successMessage) ?>

</div>

<!-- ERROR NOTIFICATION -->

<?php if ($errorMessage !== ''): ?>

    <div class="alert error page-alert" role="alert">
        <?= h($errorMessage) ?>
    </div>

<?php endif; ?>

<!-- ASSIGNED BRANCH WARNING -->

<?php if ($branchError !== ''): ?>

    <div class="alert warning page-alert" role="alert">
        <?= h($branchError) ?>
    </div>

<?php endif; ?>

<!-- SALE INFORMATION FORM -->

<section class="sale-panel">

    <h2>Sale Information</h2>

    <form method="POST"
          action="record_sale.php"
          id="recordSaleForm"
          class="sale-form">

        <input type="hidden"
               name="csrf_token"
               value="<?= h($csrf_token) ?>">

        <!-- ASSIGNED BRANCH -->

        <div class="sale-field">

            <label for="branch_name">Assigned Branch</label>

            <input type="text"
                   id="branch_name"
                   value="<?= h($branchName !== '' ? $branchName : 'No branch assigned') ?>"
                   readonly>

        </div>

        <!-- PAYMENT METHOD -->

        <div class="sale-field">

            <label for="payment_method">Payment Method *</label>

            <select name="payment_method"
                    id="payment_method"
                    required
                    <?= $branchId === null ? 'disabled' : '' ?>>

                <option value="">Select payment method</option>

                <option value="Cash"
                    <?= (($_POST['payment_method'] ?? '') === 'Cash') ? 'selected' : '' ?>>

                    Cash

                </option>

                <option value="GCash"
                    <?= (($_POST['payment_method'] ?? '') === 'GCash') ? 'selected' : '' ?>>

                    GCash

                </option>

            </select>

        </div>

        <!-- MULTIPLE PRODUCTS -->

        <div class="sale-field full">

            <label>Product/s *</label>

            <div id="productList" class="product-list">

                <?php

                $oldProductIds = $_POST['product_ids'] ?? [''];
                $oldQuantities = $_POST['quantities'] ?? ['1'];

                if (!is_array($oldProductIds) || count($oldProductIds) < 1) {
                    $oldProductIds = [''];
                }

                if (!is_array($oldQuantities)) {
                    $oldQuantities = ['1'];
                }

                $oldCount = min(count($oldProductIds), 100);

                ?>

                <?php for ($i = 0; $i < $oldCount; $i++): ?>

                    <div class="product-item">

                        <div class="product-item-header">

                            <span class="product-item-title">
                                Product <?= $i + 1 ?>
                            </span>

                            <button type="button"
                                    class="remove-product-btn"
                                    onclick="removeProduct(this)"
                                    style="<?= $i === 0 ? 'display:none' : '' ?>">

                                Remove

                            </button>

                        </div>

                        <div class="product-row">

                            <div>

                            

                                <select name="product_ids[]"
                                        class="product-select"
                                        required
                                        <?= ($branchId === null || count($products) === 0) ? 'disabled' : '' ?>>

                                    <option value="">Select product</option>

                                    <?php foreach ($products as $product): ?>

                                        <option
                                            value="<?= (int)$product['id'] ?>"
                                            data-price="<?= h((string)$product['price']) ?>"
                                            data-stock="<?= (int)$product['stock_quantity'] ?>"
                                            <?= ((string)($oldProductIds[$i] ?? '') === (string)$product['id']) ? 'selected' : '' ?>
                                            <?= (int)$product['stock_quantity'] < 1 ? 'disabled' : '' ?>>

                                            <?= h($product['product_name']) ?>
                                            - <?= h($product['category']) ?>
                                            | Stock: <?= (int)$product['stock_quantity'] ?>
                                            | ₱<?= number_format((float)$product['price'], 2) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div>

                                <label>Quantity</label>

                                <input type="number"
                                       name="quantities[]"
                                       class="product-quantity"
                                       min="1"
                                       step="1"
                                       value="<?= h((string)($oldQuantities[$i] ?? '1')) ?>"
                                       required
                                       <?= $branchId === null ? 'disabled' : '' ?>>

                                <small class="field-hint stock-hint">
                                    Select a product to view available stock.
                                </small>

                            </div>

                        </div>

                        <div class="product-subtotal">

                            <span>Subtotal</span>

                            <strong class="item-subtotal">₱0.00</strong>

                        </div>

                    </div>

                <?php endfor; ?>

            </div>

            <button type="button"
                    class="add-product-btn"
                    id="addProductBtn"
                    <?= ($branchId === null || count($products) === 0) ? 'disabled' : '' ?>>

                + Add Product

            </button>

            <small class="field-hint">
                Add multiple products to record them in one sale.
                Products with zero stock cannot be selected.
            </small>

        </div>

        <!-- CUSTOMER NAME -->

        <div class="sale-field full">

            <label for="customer_name">Customer Name</label>

            <input type="text"
                   name="customer_name"
                   id="customer_name"
                   maxlength="150"
                   value="<?= h($_POST['customer_name'] ?? '') ?>"
                   placeholder="Enter customer name (optional)"
                   <?= $branchId === null ? 'disabled' : '' ?>>

        </div>

        <!-- ESTIMATED TOTAL -->

        <div class="sale-field full">

            <div class="total-box">

                <span>Estimated Total Amount</span>

                <strong id="totalAmount">₱0.00</strong>

            </div>

            <small class="field-hint">
                The total is calculated using the saved product prices.
            </small>

        </div>

        <!-- SUBMIT BUTTON -->

        <div class="sale-field full sale-actions">

            <button type="submit"
                    class="sale-btn"
                    id="submitSale"
                    <?= ($branchId === null || count($products) === 0) ? 'disabled' : '' ?>>

                Record Sale

            </button>

        </div>

    </form>

</section>

<!-- TEMPLATE FOR ADDITIONAL PRODUCTS -->

<template id="productTemplate">

    <div class="product-item">

        <div class="product-item-header">

            <span class="product-item-title">
                Product
            </span>

            <button type="button"
                    class="remove-product-btn"
                    onclick="removeProduct(this)">

                Remove

            </button>

        </div>

        <div class="product-row">

            <div>

                <label>Product</label>

                <select name="product_ids[]"
                        class="product-select"
                        required>

                    <option value="">Select product</option>

                    <?php foreach ($products as $product): ?>

                        <option
                            value="<?= (int)$product['id'] ?>"
                            data-price="<?= h((string)$product['price']) ?>"
                            data-stock="<?= (int)$product['stock_quantity'] ?>"
                            <?= (int)$product['stock_quantity'] < 1 ? 'disabled' : '' ?>>

                            <?= h($product['product_name']) ?>
                            - <?= h($product['category']) ?>
                            | Stock: <?= (int)$product['stock_quantity'] ?>
                            | ₱<?= number_format((float)$product['price'], 2) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div>

                <label>Quantity</label>

                <input type="number"
                       name="quantities[]"
                       class="product-quantity"
                       min="1"
                       step="1"
                       value="1"
                       required>

                <small class="field-hint stock-hint">
                    Select a product to view available stock.
                </small>

            </div>

        </div>

        <div class="product-subtotal">

            <span>Subtotal</span>

            <strong class="item-subtotal">₱0.00</strong>

        </div>

    </div>

</template>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const productList = document.getElementById('productList');
    const productTemplate = document.getElementById('productTemplate');
    const addProductBtn = document.getElementById('addProductBtn');

    const totalAmount = document.getElementById('totalAmount');
    const form = document.getElementById('recordSaleForm');
    const submitButton = document.getElementById('submitSale');

    let submitting = false;

    /*
    |--------------------------------------------------------------------------
    | FORMAT CURRENCY
    |--------------------------------------------------------------------------
    */

    function formatMoney(amount) {

        return Number(amount).toLocaleString('en-PH', {
            style: 'currency',
            currency: 'PHP'
        });
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PRODUCT NUMBERS
    |--------------------------------------------------------------------------
    */

    function updateProductNumbers() {

        const items = productList.querySelectorAll('.product-item');

        items.forEach(function (item, index) {

            item.querySelector('.product-item-title').textContent =
                'Product ' + (index + 1);

            const removeButton =
                item.querySelector('.remove-product-btn');

            removeButton.style.display =
                items.length === 1 ? 'none' : 'inline-block';

        });
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE INDIVIDUAL SUBTOTAL
    |--------------------------------------------------------------------------
    */

    function updateProduct(item) {

        const select = item.querySelector('.product-select');

        const quantityInput =
            item.querySelector('.product-quantity');

        const subtotalDisplay =
            item.querySelector('.item-subtotal');

        const stockHint =
            item.querySelector('.stock-hint');

        const option = select.options[select.selectedIndex];

        if (!option || !option.value) {

            subtotalDisplay.textContent = '₱0.00';

            quantityInput.removeAttribute('max');

            stockHint.textContent =
                'Select a product to view available stock.';

            stockHint.style.color = '#64748B';

            return 0;
        }

        const price = Number(option.dataset.price || 0);
        const stock = Number(option.dataset.stock || 0);
        const quantity = Number(quantityInput.value || 0);

        quantityInput.max = stock;

        stockHint.textContent = 'Available stock: ' + stock;

        if (quantity > stock) {

            stockHint.textContent +=
                ' - Quantity exceeds available stock.';

            stockHint.style.color = '#DC2626';

        } else {

            stockHint.style.color = '#64748B';
        }

        const subtotal = price * Math.max(quantity, 0);

        subtotalDisplay.textContent = formatMoney(subtotal);

        return subtotal;
    }

    /*
    |--------------------------------------------------------------------------
    | CALCULATE GRAND TOTAL
    |--------------------------------------------------------------------------
    */

    function updateTotal() {

        const items = productList.querySelectorAll('.product-item');

        let total = 0;

        items.forEach(function (item) {

            total += updateProduct(item);

        });

        totalAmount.textContent = formatMoney(total);

        updateProductNumbers();
    }

    /*
    |--------------------------------------------------------------------------
    | ADD PRODUCT
    |--------------------------------------------------------------------------
    */

    function addProduct() {

        const items = productList.querySelectorAll('.product-item');

        if (items.length >= 100) {

            alert('You can add a maximum of 100 product entries.');

            return;
        }

        const newProduct = productTemplate.content.cloneNode(true);

        productList.appendChild(newProduct);

        updateTotal();
    }

    addProductBtn.addEventListener('click', addProduct);

    /*
    |--------------------------------------------------------------------------
    | REMOVE PRODUCT
    |--------------------------------------------------------------------------
    */

    window.removeProduct = function (button) {

        const items = productList.querySelectorAll('.product-item');

        if (items.length <= 1) {
            return;
        }

        button.closest('.product-item').remove();

        updateTotal();
    };

    /*
    |--------------------------------------------------------------------------
    | UPDATE TOTAL WHEN PRODUCT OR QUANTITY CHANGES
    |--------------------------------------------------------------------------
    */

    productList.addEventListener('change', function (event) {

        if (event.target.classList.contains('product-select')) {
            updateTotal();
        }

    });

    productList.addEventListener('input', function (event) {

        if (event.target.classList.contains('product-quantity')) {
            updateTotal();
        }

    });

    /*
    |--------------------------------------------------------------------------
    | VALIDATE BEFORE SUBMISSION
    |--------------------------------------------------------------------------
    */

    form.addEventListener('submit', function (event) {

        if (submitting) {

            event.preventDefault();

            return;
        }

        const items = productList.querySelectorAll('.product-item');

        let hasError = false;

        const requestedStock = {};

        items.forEach(function (item) {

            const select = item.querySelector('.product-select');

            const quantityInput =
                item.querySelector('.product-quantity');

            const option = select.options[select.selectedIndex];

            const productId = select.value;

            const quantity = Number(quantityInput.value || 0);

            const stock = option
                ? Number(option.dataset.stock || 0)
                : 0;

            if (
                !option ||
                !productId ||
                !Number.isInteger(quantity) ||
                quantity < 1 ||
                quantity > stock
            ) {

                hasError = true;

                return;
            }

            if (!requestedStock[productId]) {

                requestedStock[productId] = {
                    quantity: 0,
                    stock: stock,
                    name: option.textContent.trim()
                };
            }

            requestedStock[productId].quantity += quantity;

        });

        /*
        |--------------------------------------------------------------------------
        | CHECK COMBINED QUANTITY FOR DUPLICATE PRODUCTS
        |--------------------------------------------------------------------------
        */

        Object.values(requestedStock).forEach(function (product) {

            if (product.quantity > product.stock) {

                hasError = true;

                alert(
                    'Insufficient stock for ' + product.name +
                    '. Available: ' + product.stock +
                    ', requested: ' + product.quantity
                );

            }

        });

        if (hasError) {

            event.preventDefault();

            updateTotal();

            return;
        }

        submitting = true;

        submitButton.disabled = true;

        submitButton.textContent = 'Recording Sale...';

    });

    /*
    |--------------------------------------------------------------------------
    | FADE NOTIFICATIONS
    |--------------------------------------------------------------------------
    */

    function fadeNotification(element) {

        if (
            !element ||
            element.style.display === 'none' ||
            !element.textContent.trim()
        ) {
            return;
        }

        setTimeout(function () {

            element.classList.add('fade-out');

            setTimeout(function () {

                element.style.display = 'none';

                element.classList.remove('fade-out');

            }, 500);

        }, 2500);
    }

    fadeNotification(
        document.getElementById('saleNotification')
    );

    document.querySelectorAll('.page-alert').forEach(
        fadeNotification
    );

    /*
    |--------------------------------------------------------------------------
    | INITIAL CALCULATION
    |--------------------------------------------------------------------------
    */

    updateTotal();

});

</script>

<?php require_once 'includes/footer.php'; ?>
