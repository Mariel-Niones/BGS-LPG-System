<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$isOwner = is_owner();
$page_title = 'Current Inventory';

$branchId = null;
$branchName = '';
$branchSearch = $isOwner
    ? trim((string)($_GET['branch_search'] ?? ''))
    : '';

$inventoryItems = [];
$pageError = '';

try {

    /*
    |--------------------------------------------------------------------------
    | GET STAFF BRANCH
    |--------------------------------------------------------------------------
    */

    if (!$isOwner) {

        $stmt = $pdo->prepare("
            SELECT branch_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$_SESSION['user_id']]);

        $staffBranch = $stmt->fetchColumn();

        if ($staffBranch !== false && $staffBranch !== null && $staffBranch !== '') {

            $stmt = $pdo->prepare("
                SELECT id, branch_name
                FROM branches
                WHERE CAST(id AS CHAR) = ?
                   OR branch_name = ?
                LIMIT 1
            ");

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

        if ($branchId === null) {
            throw new RuntimeException(
                'Your assigned branch could not be found. Please contact the owner.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | LOAD CURRENT INVENTORY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            bi.id,
            bi.product_id,
            bi.branch_id,
            bi.quantity,
            bi.min_stock,

            p.product_name,
            p.category,
            p.price,

            b.branch_name

        FROM branch_inventory bi

        INNER JOIN products p
            ON CAST(p.id AS CHAR) =
               CAST(bi.product_id AS CHAR)

        LEFT JOIN branches b
            ON CAST(b.id AS CHAR) =
               CAST(bi.branch_id AS CHAR)

        WHERE bi.branch_id IS NOT NULL
          AND bi.product_id IS NOT NULL
    ";

    $params = [];

    /*
    |--------------------------------------------------------------------------
    | OWNER BRANCH SEARCH
    |--------------------------------------------------------------------------
    */

    if ($isOwner) {

        if ($branchSearch !== '') {

            $sql .= "
                AND b.branch_name LIKE ?
            ";

            $params[] = '%' . $branchSearch . '%';
        }

    } else {

        /*
        |--------------------------------------------------------------------------
        | STAFF BRANCH RESTRICTION
        |--------------------------------------------------------------------------
        */

        $sql .= "
            AND bi.branch_id = ?
        ";

        $params[] = $branchId;
    }

    $sql .= "
        ORDER BY
            b.branch_name ASC,
            p.product_name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $inventoryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Current Inventory Error: ' . $e->getMessage());

    $pageError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to load current inventory. Please check your database tables and try again.';

}

require_once 'includes/header.php';
?>

<style>

/* SIDEBAR */
.layout > .sidebar {
    background-color: #1D4ED8 !important;
}

.layout > .sidebar a[href="inventory_report.php"] {
    background-color: #0F4C81 !important;
    color: #FFFFFF !important;
    font-weight: 700;
    border-left: none !important;
    border-radius: 8px;
}

/* MAIN CONTENT */
.inventory-report {
    color: #20253B;
    font-size: 16px;
}

/* HEADING */
.report-heading {
    margin-bottom: 24px;
}

.inventory-report h1 {
    margin: 0 0 8px;
    color: #20253B;
    font-size: 28px;
    font-weight: 700;
}

.report-subtitle {
    margin: 0;
    color: #64748B;
    font-size: 16px;
    line-height: 1.5;
}

/* BRANCH SEARCH */
.branch-search-container {
    display: flex;
    justify-content: center;
    width: 100%;
    margin: 18px 0 24px;
}

.branch-search-form {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    max-width: 500px;
}

.branch-search-form input {
    flex: 1;
    min-width: 0;
    padding: 11px 13px;
    border: 1px solid #CBD5E1;
    border-radius: 8px;
    font-size: 15px;
    outline: none;
}

.branch-search-form input:focus {
    border-color: #1D4ED8;
    box-shadow: 0 0 0 3px rgba(29,78,216,.12);
}

.branch-search-form button,
.branch-search-form .clear-search {
    padding: 11px 15px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    white-space: nowrap;
}

.branch-search-form button {
    background: #1D4ED8;
    color: #FFFFFF;
}

.branch-search-form .clear-search {
    background: #E2E8F0;
    color: #334155;
}

/* TABLE PANEL */
.inventory-table-panel {
    overflow: hidden;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15,23,42,.04);
}

.inventory-table-header {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #E2E8F0;
}

.inventory-table-header h2 {
    margin: 0;
    color: #20253B;
    font-size: 20px;
    font-weight: 700;
    text-align: center;
}

/* TABLE */
.inventory-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.inventory-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.inventory-table th {
    padding: 15px 18px;
    background: #F1F5F9;
    color: #334155;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
    border-bottom: 1px solid #E2E8F0;
}

.inventory-table td {
    padding: 15px 18px;
    border-bottom: 1px solid #E2E8F0;
    color: #334155;
    font-size: 14px;
    text-align: center;
    vertical-align: middle;
}

.inventory-table tbody tr:hover {
    background: #F8FAFC;
}

.product-cell {
    font-weight: 600;
}

.quantity-cell {
    font-weight: 700;
    color: #166534 !important;
}

.price-cell {
    font-weight: 600;
}

/* STATUS */
.stock-status {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
}

.stock-in {
    background: #DCFCE7;
    color: #166534;
}

.stock-low {
    background: #FEF3C7;
    color: #92400E;
}

/* EMPTY AND ERROR */
.empty-cell {
    padding: 42px 20px !important;
    color: #64748B !important;
}

.report-notice {
    margin-bottom: 20px;
    padding: 14px 16px;
    border: 1px solid #FECACA;
    border-radius: 8px;
    background: #FEF2F2;
    color: #991B1B;
    font-size: 14px;
}

/* RESPONSIVE */
@media (max-width: 700px) {

    .branch-search-form {
        flex-wrap: wrap;
    }

    .branch-search-form input {
        width: 100%;
    }

    .inventory-table-header {
        padding: 18px;
    }
}

</style>


<div class="inventory-report">

    <!-- PAGE HEADING -->
    <div class="report-heading">

        <h1>Current Inventory</h1>

        <p class="report-subtitle">

            <?php if ($isOwner): ?>

                View the current product quantities across all branches.

            <?php else: ?>

                View the current product quantities in your assigned branch.

            <?php endif; ?>

        </p>

    </div>


    <?php if ($pageError !== ''): ?>

        <div class="report-notice">
            <?= h($pageError) ?>
        </div>

    <?php else: ?>


        <!-- OWNER-ONLY BRANCH SEARCH -->
        <?php if ($isOwner): ?>

            <div class="branch-search-container">

                <form
                    method="GET"
                    action="inventory_report.php"
                    class="branch-search-form"
                >

                    <input
                        type="search"
                        name="branch_search"
                        placeholder="Search branch name..."
                        aria-label="Search branch name"
                        value="<?= h($branchSearch) ?>"
                    >

                    <button type="submit">Search</button>

                    <?php if ($branchSearch !== ''): ?>

                        <a
                            href="inventory_report.php"
                            class="clear-search"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </form>

            </div>

        <?php endif; ?>


        <!-- CURRENT INVENTORY TABLE -->
        <section class="inventory-table-panel">

            <div class="inventory-table-header">

                <h2>
                    <?= $isOwner ? 'Branch Inventory' : 'My Branch Inventory' ?>
                </h2>

            </div>


            <div class="inventory-table-wrapper">

                <table class="inventory-table">

                    <thead>

                        <tr>

                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>

                            <?php if ($isOwner): ?>
                                <th>Branch</th>
                            <?php endif; ?>

                            <th>Quantity</th>
                            
                            <th>Stock Status</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($inventoryItems)): ?>

                            <tr>

                                <td
                                    colspan="<?= $isOwner ? 6 : 5 ?>"
                                    class="empty-cell"
                                >

                                    <?php if ($isOwner && $branchSearch !== ''): ?>

                                        No inventory found for
                                        "<?= h($branchSearch) ?>".

                                    <?php else: ?>

                                        No inventory records found.

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($inventoryItems as $item): ?>

    <?php
    $quantity = (int)$item['quantity'];
    $minimumStock = (int)$item['min_stock'];

    $isLowStock = $quantity <= $minimumStock;
    ?>

    <tr>

        <td class="product-cell">
            <?= h($item['product_name']) ?>
        </td>

        <td>
            <?= h($item['category']) ?>
        </td>

        <td class="price-cell">
            ₱<?= number_format((float)$item['price'], 2) ?>
        </td>

        <?php if ($isOwner): ?>

            <td>
                <?= h($item['branch_name'] ?? 'Unknown Branch') ?>
            </td>

        <?php endif; ?>

        <td class="quantity-cell">
            <?= number_format($quantity) ?>
        </td>

        <td>

            <?php if ($isLowStock): ?>

                <span class="stock-status stock-low">
                    Low Stock
                </span>

            <?php else: ?>

                <span class="stock-status stock-in">
                    In Stock
                </span>

            <?php endif; ?>

        </td>

    </tr>

<?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>

</div>


<?php require_once 'includes/footer.php'; ?>