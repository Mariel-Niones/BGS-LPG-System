
<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$page_title = 'Sales History';

$isOwner = is_owner();

$sales = [];
$errorMessage = '';
$branchName = '';
$branchId = null;

try {

    /*
    |--------------------------------------------------------------------------
    | STAFF BRANCH
    |--------------------------------------------------------------------------
    | Owner can view all branches.
    | Staff can only view their assigned branch.
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
            $errorMessage = 'Your assigned branch could not be found. Please contact the owner.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SALES HISTORY QUERY
    |--------------------------------------------------------------------------
    */

    if ($isOwner || $branchId !== null) {

        $sql = "
            SELECT
                bs.id,
                bs.branch_id,
                bs.customer_name,
                bs.payment_method,
                bs.total_amount,
                bs.product_id,
                bs.quantity,
                bs.created_at,
                b.branch_name,
                u.staff_name,
                p.product_name
            FROM branch_sales bs
            LEFT JOIN branches b
                ON b.id = bs.branch_id
            LEFT JOIN users u
                ON u.id = bs.user_id
            LEFT JOIN products p
                ON p.id = bs.product_id
        ";

        $params = [];

        // Staff can only see sales from their assigned branch.
        if (!$isOwner) {
            $sql .= " WHERE bs.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= "
            ORDER BY bs.created_at DESC, bs.id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {

    error_log('Sales History Error: ' . $e->getMessage());

    $errorMessage = 'Unable to load sales history. Please check your database tables.';
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
.history-header {
    margin-bottom: 25px;
}

.history-header h1 {
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

/* TABLE PANEL */
.history-panel {
    background: #FFFFFF;
    padding: 24px;
    border-radius: 12px;
    box-shadow: 0 2px 8px #0000000D;
}

/* CENTERED TABLE TITLE */
.history-panel h2 {
    margin: 0 0 22px;
    color: #20253B;
    font-size: 22px;
    font-weight: 700;
    text-align: center;
}

/* OWNER SEARCH BAR */
.history-tools {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
}

.history-search {
    width: 350px;
    max-width: 100%;
    padding: 11px 14px;
    border: 1px solid #CBD5E1;
    border-radius: 6px;
    font-size: 15px;
    outline: none;
}

.history-search:focus {
    border-color: #2563EB;
    box-shadow: 0 0 0 3px #1D4ED81F;
}

/* TABLE WRAPPER */
.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

/* TABLE */
.history-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

/* TABLE HEADERS */
.history-table th {
    background: #F1F5F9;
    color: #334155;
    font-weight: 700;
    text-align: center;
    padding: 13px 12px;
    white-space: nowrap;
    border-bottom: 1px solid #E2E8F0;
}

/* TABLE DATA */
.history-table td {
    padding: 13px 12px;
    color: #334155;
    border-bottom: 1px solid #E2E8F0;
    vertical-align: middle;
    text-align: center;
}

.history-table tbody tr:hover {
    background: #F8FAFC;
}

/* PAYMENT LABELS */
.payment-label {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.payment-cash {
    background: #DCFCE7;
    color: #166534;
}

.payment-gcash {
    background: #DBEAFE;
    color: #1E40AF;
}

/* EMPTY ROW */
.empty-row {
    text-align: center !important;
    padding: 25px !important;
    color: #64748B !important;
}

/* ERROR MESSAGE */
.alert-error {
    padding: 13px 15px;
    margin-bottom: 18px;
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
    border-radius: 6px;
}

/* RESPONSIVE */
@media (max-width: 700px) {

    .history-panel {
        padding: 16px;
    }

    .history-tools {
        justify-content: stretch;
    }

    .history-search {
        width: 100%;
    }
}

</style>


<!-- PAGE HEADER -->
<div class="history-header">

    <h1>Sales History</h1>

    <p class="muted">
        <?php if ($isOwner): ?>

            View all recorded sales transactions from every branch.

        <?php else: ?>

            View all recorded sales transactions from your assigned branch.

        <?php endif; ?>
    </p>

</div>


<!-- ERROR MESSAGE -->
<?php if ($errorMessage !== ''): ?>

    <div class="alert-error">
        <?= h($errorMessage) ?>
    </div>

<?php endif; ?>


<?php if ($isOwner || $branchId !== null): ?>


    <!-- SALES TRANSACTIONS PANEL -->
    <section class="history-panel">

        <!-- CENTERED TITLE -->
        <h2>Sales Transactions</h2>


        <!-- OWNER-ONLY BRANCH SEARCH -->
        <?php if ($isOwner): ?>

            <div class="history-tools">

                <input
                    type="text"
                    id="branchSearch"
                    class="history-search"
                    placeholder="Search by branch name..."
                    autocomplete="off"
                >

            </div>

        <?php endif; ?>


        <!-- SALES TRANSACTIONS TABLE -->
        <div class="table-wrapper">

            <table class="history-table" id="historyTable">

                <thead>
                    <tr>

                        <?php if ($isOwner): ?>
                            <th>Branch Name</th>
                        <?php endif; ?>

                        <th>Product</th>

                        <th>Quantity</th>

                        <th>Payment Method</th>

                        <th>Total Amount</th>

                        <th>Date &amp; Time</th>

                    </tr>
                </thead>


                <tbody>

                    <?php if (!empty($sales)): ?>

                        <?php foreach ($sales as $sale): ?>

                            <tr>

                                <!-- OWNER-ONLY BRANCH NAME -->
                                <?php if ($isOwner): ?>

                                    <td class="branch-name">
                                        <?= h($sale['branch_name'] ?? 'Unknown Branch') ?>
                                    </td>

                                <?php endif; ?>


                                <!-- PRODUCT -->
                                <td>
                                    <?= h($sale['product_name'] ?? 'Unknown Product') ?>
                                </td>


                                <!-- QUANTITY -->
                                <td>
                                    <?= number_format((int)$sale['quantity']) ?>
                                </td>


                                <!-- PAYMENT METHOD -->
                                <td>

                                    <?php

                                    $paymentClass =
                                        strtolower($sale['payment_method'] ?? '') === 'gcash'
                                            ? 'payment-gcash'
                                            : 'payment-cash';

                                    ?>

                                    <span class="payment-label <?= $paymentClass ?>">
                                        <?= h($sale['payment_method'] ?? 'N/A') ?>
                                    </span>

                                </td>


                                <!-- TOTAL AMOUNT -->
                                <td>
                                    ₱<?= number_format((float)$sale['total_amount'], 2) ?>
                                </td>


                                <!-- DATE AND TIME -->
                                <td>

                                    <?= !empty($sale['created_at'])
                                        ? date(
                                            'M d, Y h:i A',
                                            strtotime($sale['created_at'])
                                        )
                                        : 'N/A'
                                    ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                    <?php else: ?>

                        <tr>

                            <td colspan="<?= $isOwner ? 6 : 5 ?>" class="empty-row">

                                No sales transactions recorded yet.

                            </td>

                        </tr>

                    <?php endif; ?>


                    <!-- NO MATCHING BRANCH MESSAGE -->
                    <?php if ($isOwner && !empty($sales)): ?>

                        <tr id="noSearchResults" style="display:none;">

                            <td colspan="6" class="empty-row">

                                No matching branch found.

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


<?php endif; ?>


<!-- OWNER-ONLY BRANCH SEARCH SCRIPT -->
<?php if ($isOwner): ?>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('branchSearch');

    const table = document.getElementById('historyTable');

    if (!searchInput || !table) {
        return;
    }

    const rows = table.querySelectorAll(
        'tbody tr:not(#noSearchResults)'
    );

    const noResults = document.getElementById('noSearchResults');


    searchInput.addEventListener('input', function () {

        const searchValue = this.value.toLowerCase().trim();

        let visibleCount = 0;


        rows.forEach(function (row) {

            const branchCell = row.querySelector('.branch-name');

            if (!branchCell) {
                return;
            }

            const branch = branchCell.textContent.toLowerCase();

            const matches = branch.includes(searchValue);

            row.style.display = matches ? '' : 'none';

            if (matches) {
                visibleCount++;
            }

        });


        // Show message if no branch matches.
        if (noResults) {

            noResults.style.display =
                visibleCount === 0 ? '' : 'none';

        }

    });

});

</script>

<?php endif; ?>


<?php require_once 'includes/footer.php'; ?>
