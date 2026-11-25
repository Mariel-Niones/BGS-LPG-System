
<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$isOwner = is_owner();
$page_title = 'Sales Report';

$branchId = null;
$branchName = '';
$branchSearch = $isOwner
    ? trim((string)($_GET['branch_search'] ?? ''))
    : '';

$transactions = [];
$totalSales = 0;
$totalTransactions = 0;
$pageError = '';

/*
|--------------------------------------------------------------------------
| REPORTING PERIOD
|--------------------------------------------------------------------------
*/

$period = $_GET['period'] ?? 'daily';

if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    $period = 'daily';
}

$today = new DateTimeImmutable('today');

$startDate = $today;
$endDate = $today->modify('+1 day');

if ($period === 'weekly') {

    // Monday through Sunday.
    $startDate = $today->modify('monday this week');
    $endDate = $startDate->modify('+1 week');

} elseif ($period === 'monthly') {

    // First day of this month through next month.
    $startDate = $today->modify('first day of this month');
    $endDate = $startDate->modify('first day of next month');

}

/*
|--------------------------------------------------------------------------
| DATE RANGE LABEL
|--------------------------------------------------------------------------
*/

$dateRangeLabel = $startDate->format('M d, Y');

if ($period === 'weekly') {

    $dateRangeLabel .= ' – ' .
        $endDate->modify('-1 day')->format('M d, Y');

} elseif ($period === 'monthly') {

    $dateRangeLabel = $startDate->format('F Y');

}

/*
|--------------------------------------------------------------------------
| GET STAFF BRANCH
|--------------------------------------------------------------------------
| Owner can view every branch.
| Staff can only view their assigned branch.
*/

try {

    if (!$isOwner) {

        $stmt = $pdo->prepare("
            SELECT branch_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$_SESSION['user_id']]);

        $staffBranch = $stmt->fetchColumn();

        if (
            $staffBranch !== false &&
            $staffBranch !== null &&
            $staffBranch !== ''
        ) {

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
    | LOAD SALES FROM RECORD SALE TRANSACTIONS
    |--------------------------------------------------------------------------
    | record_sale.php must insert sales into branch_sales.
    | This report reads those same records.
    */

    $sql = "
        SELECT
            bs.id,
            bs.branch_id,
            bs.product_id,
            bs.quantity,
            bs.payment_method,
            bs.total_amount,
            bs.created_at,
            b.branch_name,
            p.product_name

        FROM branch_sales bs

        LEFT JOIN branches b
            ON b.id = bs.branch_id

        LEFT JOIN products p
            ON p.id = bs.product_id

        WHERE bs.created_at >= ?
          AND bs.created_at < ?
    ";

    $params = [
        $startDate->format('Y-m-d 00:00:00'),
        $endDate->format('Y-m-d 00:00:00')
    ];

    /*
    |--------------------------------------------------------------------------
    | OWNER FILTER
    |--------------------------------------------------------------------------
    */

    if ($isOwner) {

        if ($branchSearch !== '') {

            $sql .= " AND b.branch_name LIKE ?";

            $params[] = '%' . $branchSearch . '%';
        }

    } else {

        /*
        |--------------------------------------------------------------------------
        | STAFF BRANCH RESTRICTION
        |--------------------------------------------------------------------------
        */

        $sql .= " AND bs.branch_id = ?";

        $params[] = $branchId;
    }

    $sql .= "
        ORDER BY bs.created_at DESC, bs.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | CALCULATE SUMMARY
    |--------------------------------------------------------------------------
    | Summary follows the same date and branch filters
    | as the transaction table.
    */

    foreach ($transactions as $transaction) {

        $totalSales += (float)$transaction['total_amount'];

    }

    $totalTransactions = count($transactions);

} catch (Throwable $e) {

    error_log('Sales Report Error: ' . $e->getMessage());

    $pageError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to load the sales report. Please check your database tables and try again.';

}

require_once 'includes/header.php';
?>

<style>

/* SIDEBAR */
.layout > .sidebar {
    background-color: #1D4ED8 !important;
}

.layout > .sidebar a[href="sales_report.php"] {
    background-color: #0F4C81 !important;
    color: #FFFFFF !important;
    font-weight: 700;
    border-left: none !important;
    border-radius: 8px;
}

/* MAIN REPORT */
.sales-report {
    color: #20253B;
    font-size: 16px;
}

/* HEADING */
.report-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    margin-bottom: 24px;
}

.report-heading-text {
    flex: 1;
    min-width: 0;
}

.sales-report h1 {
    margin: 0 0 8px;
    color: #20253B;
    font-size: 28px;
    font-weight: 700;
    line-height: 1.3;
}

.report-subtitle {
    margin: 0;
    color: #64748B;
    font-size: 16px;
    line-height: 1.5;
}

.report-date-label {
    margin: 7px 0 0;
    color: #64748B;
    font-size: 14px;
}

/* PERIOD BUTTONS */
.report-period-options {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
}

.report-period-top {
    flex-shrink: 0;
}

.report-period-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 17px;
    border: 1px solid #7394EE;
    border-radius: 8px;
    background: #FFFFFF;
    color: #475569;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
    transition: background-color 0.2s, color 0.2s;
}

.report-period-button:hover {
    background: #EFF6FF;
    border-color: #93C5FD;
    color: #1D4ED8;
}

.report-period-button.active {
    background: #1D4ED8;
    border-color: #1D4ED8;
    color: #FFFFFF;
}

/* OWNER BRANCH SEARCH */
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
    background: #FFFFFF;
    color: #1E293B;
    font-size: 15px;
    outline: none;
}

.branch-search-form input:focus {
    border-color: #1D4ED8;
    box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.12);
}

.branch-search-form button,
.branch-search-form .clear-search {
    display: inline-flex;
    align-items: center;
    justify-content: center;
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

.branch-search-form button:hover {
    background: #1E40AF;
}

.branch-search-form .clear-search {
    background: #E2E8F0;
    color: #334155;
}

.branch-search-form .clear-search:hover {
    background: #CBD5E1;
}

/* SUMMARY CARDS */
.sales-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 24px;
}

.sales-summary-card {
    padding: 22px 24px;
    background: #FFFFFF;
    border: 1px solid #7394EE;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

.sales-summary-label {
    margin: 0 0 12px;
    color: #64748B;
    font-size: 14px;
    font-weight: 600;
}

.sales-summary-value {
    margin: 0;
    color: #1E293B;
    font-size: 28px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

/* TABLE PANEL */
.sales-table-panel {
    overflow: hidden;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}

/* TABLE HEADER */
.sales-table-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 20px 24px;
    border-bottom: 1px solid #E2E8F0;
}

.sales-table-header h2 {
    margin: 0;
    color: #20253B;
    font-size: 20px;
    font-weight: 700;
    text-align: center;
}

.sales-table-count {
    color: #64748B;
    font-size: 14px;
}

/* TABLE */
.sales-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.sales-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

.sales-table th {
    padding: 15px 18px;
    background: #F1F5F9;
    color: #334155;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
    border-bottom: 1px solid #E2E8F0;
}

.sales-table td {
    padding: 15px 18px;
    border-bottom: 1px solid #E2E8F0;
    color: #334155;
    font-size: 14px;
    text-align: center;
    vertical-align: middle;
}

.sales-table tbody tr:hover {
    background: #F8FAFC;
}

/* AMOUNT */
.sales-table .amount-cell {
    color: #166534;
    font-weight: 700;
    white-space: nowrap;
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

/* EMPTY TABLE */
.sales-table .empty-cell {
    padding: 42px 20px;
    color: #64748B;
    text-align: center;
}

/* ERROR */
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

    .report-heading {
        align-items: stretch;
        flex-direction: column;
        gap: 16px;
    }

    .report-period-top {
        justify-content: flex-start;
        width: 100%;
    }

    .report-period-button {
        flex: 1;
        min-width: 0;
        padding: 10px 12px;
    }

    .branch-search-form {
        flex-wrap: wrap;
    }

    .branch-search-form input {
        width: 100%;
    }

    .sales-summary-grid {
        grid-template-columns: 1fr;
        gap: 14px;
    }

    .sales-summary-card {
        padding: 18px;
    }

    .sales-summary-value {
        font-size: 25px;
    }

    .sales-table-header {
        flex-direction: column;
        gap: 6px;
        padding: 18px;
    }
}

</style>


<div class="sales-report">

    <!-- PAGE HEADING -->
    <div class="report-heading">

        <div class="report-heading-text">

            <h1>Sales Report</h1>

            <p class="report-subtitle">

                <?php if ($isOwner): ?>

                    Overview of recorded sales across all branches.

                <?php else: ?>

                    Overview of recorded sales for your assigned branch.

                <?php endif; ?>

            </p>

            <?php if ($pageError === ''): ?>

                <p class="report-date-label">

                    <?php if (!$isOwner): ?>
                        <?= h($branchName) ?> |
                    <?php endif; ?>

                    <?= h($dateRangeLabel) ?>

                </p>

            <?php endif; ?>

        </div>


        <!-- REPORT PERIOD BUTTONS -->
        <?php if ($pageError === ''): ?>

            <nav
                class="report-period-options report-period-top"
                aria-label="Select report period"
            >

                <?php
                $periodUrl = 'sales_report.php?';

                if ($isOwner && $branchSearch !== '') {
                    $periodUrl .= 'branch_search=' .
                        urlencode($branchSearch) . '&';
                }
                ?>

                <a
                    href="<?= h($periodUrl . 'period=daily') ?>"
                    class="report-period-button <?= $period === 'daily' ? 'active' : '' ?>"
                >
                    Daily
                </a>

                <a
                    href="<?= h($periodUrl . 'period=weekly') ?>"
                    class="report-period-button <?= $period === 'weekly' ? 'active' : '' ?>"
                >
                    Weekly
                </a>

                <a
                    href="<?= h($periodUrl . 'period=monthly') ?>"
                    class="report-period-button <?= $period === 'monthly' ? 'active' : '' ?>"
                >
                    Monthly
                </a>

            </nav>

        <?php endif; ?>

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
                    action="sales_report.php"
                    class="branch-search-form"
                >

                    <input
                        type="hidden"
                        name="period"
                        value="<?= h($period) ?>"
                    >

                    <input
                        type="search"
                        name="branch_search"
                        placeholder="Search branch name..."
                        aria-label="Search branch name"
                        value="<?= h($branchSearch) ?>"
                    >

                    <button type="submit">
                        Search
                    </button>

                    <?php if ($branchSearch !== ''): ?>

                        <a
                            href="sales_report.php?period=<?= urlencode($period) ?>"
                            class="clear-search"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </form>

            </div>

        <?php endif; ?>


        <!-- SUMMARY CARDS -->
        <div class="sales-summary-grid">

            <div class="sales-summary-card">

                <p class="sales-summary-label">
                    Total Sales
                </p>

                <p class="sales-summary-value">
                    ₱<?= number_format($totalSales, 2) ?>
                </p>

            </div>


            <div class="sales-summary-card">

                <p class="sales-summary-label">
                    Total Transactions
                </p>

                <p class="sales-summary-value">
                    <?= number_format($totalTransactions) ?>
                </p>

            </div>

        </div>


        <!-- SALES TRANSACTIONS TABLE -->
        <section class="sales-table-panel">

            <div class="sales-table-header">
    <h2>Sales Transactions</h2>
</div>


            <div class="sales-table-wrapper">

                <table class="sales-table">

                    <thead>

                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Payment Method</th>
                            <th>Total Amount</th>
                            <th>Date &amp; Time</th>
                        </tr>

                    </thead>


                    <tbody>

                        <?php if (empty($transactions)): ?>

                            <tr>

                                <td colspan="5" class="empty-cell">

                                    <?php if ($isOwner && $branchSearch !== ''): ?>

                                        No sales transactions found for
                                        "<?= h($branchSearch) ?>"
                                        during this reporting period.

                                    <?php else: ?>

                                        No sales transactions were recorded
                                        during this period.

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($transactions as $sale): ?>

                                <tr>

                                    <!-- PRODUCT -->
                                    <td>
                                        <?= h(
                                            $sale['product_name'] ?? 'Unknown Product'
                                        ) ?>
                                    </td>


                                    <!-- QUANTITY -->
                                    <td>
                                        <?= number_format(
                                            (int)$sale['quantity']
                                        ) ?>
                                    </td>


                                    <!-- PAYMENT METHOD -->
                                    <td>

                                        <?php
                                        $paymentMethod = trim(
                                            (string)($sale['payment_method'] ?? '')
                                        );

                                        $paymentClass =
                                            strtolower($paymentMethod) === 'gcash'
                                                ? 'payment-gcash'
                                                : 'payment-cash';
                                        ?>

                                        <span class="payment-label <?= $paymentClass ?>">

                                            <?= h(
                                                $paymentMethod !== ''
                                                    ? ucfirst($paymentMethod)
                                                    : 'N/A'
                                            ) ?>

                                        </span>

                                    </td>


                                    <!-- TOTAL AMOUNT -->
                                    <td class="amount-cell">

                                        ₱<?= number_format(
                                            (float)$sale['total_amount'],
                                            2
                                        ) ?>

                                    </td>


                                    <!-- DATE AND TIME -->
                                    <td>

                                        <?php
                                        $timestamp = strtotime(
                                            $sale['created_at'] ?? ''
                                        );
                                        ?>

                                        <?= $timestamp !== false
                                            ? h(date('M d, Y h:i A', $timestamp))
                                            : 'N/A'
                                        ?>

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
