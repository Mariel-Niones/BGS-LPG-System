
<?php
require_once 'includes/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('Asia/Manila');

$isOwner = is_owner();
$page_title = 'Stock-In History';

$branchId = null;
$branchName = '';

$branchSearch = $isOwner
    ? trim((string)($_GET['branch_search'] ?? ''))
    : '';

$transactions = [];
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

    $startDate = $today->modify('monday this week');
    $endDate = $startDate->modify('+1 week');

} elseif ($period === 'monthly') {

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
| GET STAFF BRANCH AND STOCK-IN HISTORY
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | STAFF BRANCH
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
    | LOAD STOCK-IN HISTORY
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            si.id,
            si.product_id,
            si.branch_id,
            si.quantity,
            si.supplier,
            si.delivery_reference,
            si.received_by,
            si.received_at,

            p.product_name,

            b.branch_name,

            u.staff_name AS received_by_name

        FROM stock_in si

        LEFT JOIN products p
            ON CAST(p.id AS CHAR) =
               CAST(si.product_id AS CHAR)

        LEFT JOIN branches b
            ON CAST(b.id AS CHAR) =
               CAST(si.branch_id AS CHAR)

        LEFT JOIN users u
            ON u.id = si.received_by

        WHERE si.received_at >= ?
          AND si.received_at < ?
    ";

    $params = [
        $startDate->format('Y-m-d 00:00:00'),
        $endDate->format('Y-m-d 00:00:00')
    ];

    /*
    |--------------------------------------------------------------------------
    | OWNER BRANCH SEARCH / STAFF BRANCH RESTRICTION
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

        $sql .= "
            AND si.branch_id = ?
        ";

        $params[] = $branchId;
    }

    $sql .= "
        ORDER BY
            si.received_at DESC,
            si.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log('Stock-In History Error: ' . $e->getMessage());

    $pageError = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Unable to load stock-in history. Please check your database tables and try again.';

}

require_once 'includes/header.php';
?>

<style>

/* SIDEBAR */
.layout > .sidebar {
    background-color: #1D4ED8 !important;
}

.layout > .sidebar a[href="stockin_history.php"] {
    background-color: #0F4C81 !important;
    color: #FFFFFF !important;
    font-weight: 700;
    border-left: none !important;
    border-radius: 8px;
}

/* MAIN CONTENT */
.stockin-history {
    color: #20253B;
    font-size: 16px;
}

.stockin-history h1 {
    margin: 0 0 8px;
    color: #20253B;
    font-size: 28px;
    font-weight: 700;
}

.history-subtitle {
    margin: 0;
    color: #64748B;
    font-size: 16px;
    line-height: 1.5;
}

.history-date {
    margin-top: 7px;
    color: #64748B;
    font-size: 14px;
}

/* PAGE HEADING */
.history-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 24px;
    margin-bottom: 24px;
}

.history-heading-text {
    flex: 1;
    min-width: 0;
}

/* PERIOD BUTTONS */
.history-period-options {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 8px;
    flex-shrink: 0;
}

.history-period-button {
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
}

.history-period-button:hover {
    background: #EFF6FF;
    color: #1D4ED8;
}

.history-period-button.active {
    background: #1D4ED8;
    border-color: #1D4ED8;
    color: #FFFFFF;
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
    box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.10);
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

/* TABLE PANEL */
.history-table-panel {
    overflow: hidden;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, .04);
}

.history-table-header {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px 24px;
    border-bottom: 1px solid #E2E8F0;
}

.history-table-header h2 {
    margin: 0;
    color: #20253B;
    font-size: 20px;
    font-weight: 700;
    text-align: center;
}

.history-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.history-table {
    width: 100%;
    min-width: 1000px;
    border-collapse: collapse;
}

.history-table th {
    padding: 15px 18px;
    background: #F1F5F9;
    color: #334155;
    font-size: 14px;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
    border-bottom: 1px solid #E2E8F0;
}

.history-table td {
    padding: 15px 18px;
    border-bottom: 1px solid #E2E8F0;
    color: #334155;
    font-size: 14px;
    text-align: center;
    vertical-align: middle;
}

.history-table tbody tr:hover {
    background: #F8FAFC;
}

.history-table tbody tr:last-child td {
    border-bottom: none;
}

.history-product {
    font-weight: 600;
}

.history-quantity {
    color: #166534 !important;
    font-weight: 700;
}

.history-empty {
    padding: 42px 20px !important;
    color: #64748B !important;
}

/* ERROR MESSAGE */
.history-notice {
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

    .history-heading {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }

    .history-period-options {
        justify-content: flex-start;
        width: 100%;
    }

    .history-period-button {
        flex: 1;
        padding: 10px 12px;
    }

    .branch-search-form {
        flex-wrap: wrap;
    }

    .branch-search-form input {
        width: 100%;
    }

    .history-table-header {
        padding: 18px;
    }

}

</style>


<div class="stockin-history">

    <!-- PAGE HEADING -->
    <div class="history-heading">

        <div class="history-heading-text">

            <h1>Stock-In History</h1>

            <p class="history-subtitle">

                <?php if ($isOwner): ?>

                    View recorded stock-in transactions across all branches.

                <?php else: ?>

                    View stock-in transactions recorded for your assigned branch.

                <?php endif; ?>

            </p>

            <?php if ($pageError === ''): ?>

                <p class="history-date">

                    <?php if (!$isOwner): ?>
                        <?= h($branchName) ?> |
                    <?php endif; ?>

                    <?= h($dateRangeLabel) ?>

                </p>

            <?php endif; ?>

        </div>


        <!-- PERIOD FILTER -->
        <?php if ($pageError === ''): ?>

            <nav class="history-period-options">

                <?php
                $periodUrl = 'stockin_history.php?';

                if ($isOwner && $branchSearch !== '') {
                    $periodUrl .= 'branch_search=' .
                        urlencode($branchSearch) . '&';
                }
                ?>

                <a
                    href="<?= h($periodUrl . 'period=daily') ?>"
                    class="history-period-button <?= $period === 'daily' ? 'active' : '' ?>"
                >
                    Daily
                </a>

                <a
                    href="<?= h($periodUrl . 'period=weekly') ?>"
                    class="history-period-button <?= $period === 'weekly' ? 'active' : '' ?>"
                >
                    Weekly
                </a>

                <a
                    href="<?= h($periodUrl . 'period=monthly') ?>"
                    class="history-period-button <?= $period === 'monthly' ? 'active' : '' ?>"
                >
                    Monthly
                </a>

            </nav>

        <?php endif; ?>

    </div>


    <?php if ($pageError !== ''): ?>

        <div class="history-notice">
            <?= h($pageError) ?>
        </div>

    <?php else: ?>


        <!-- OWNER BRANCH SEARCH -->
        <?php if ($isOwner): ?>

            <div class="branch-search-container">

                <form
                    method="GET"
                    action="stockin_history.php"
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
                            href="stockin_history.php?period=<?= urlencode($period) ?>"
                            class="clear-search"
                        >
                            Clear
                        </a>

                    <?php endif; ?>

                </form>

            </div>

        <?php endif; ?>


        <!-- STOCK-IN HISTORY TABLE -->
        <section class="history-table-panel">

            <div class="history-table-header">

                <h2>Stock-In Transactions</h2>

            </div>

            <div class="history-table-wrapper">

                <table class="history-table">

                    <thead>

                        <tr>

                            <th>Product</th>

                            <?php if ($isOwner): ?>
                                <th>Branch</th>
                            <?php endif; ?>

                            <th>Quantity Received</th>
                            <th>Supplier</th>
                            <th>Delivery Reference</th>
                            <th>Received By</th>
                            <th>Date &amp; Time</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($transactions)): ?>

                            <tr>

                                <td
                                    colspan="<?= $isOwner ? 7 : 6 ?>"
                                    class="history-empty"
                                >

                                    <?php if ($isOwner && $branchSearch !== ''): ?>

                                        No stock-in transactions found for
                                        "<?= h($branchSearch) ?>"
                                        during this period.

                                    <?php else: ?>

                                        No stock-in transactions were recorded
                                        during this period.

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($transactions as $stock): ?>

                                <tr>

                                    <td class="history-product">
                                        <?= h($stock['product_name'] ?? 'Unknown Product') ?>
                                    </td>

                                    <?php if ($isOwner): ?>

                                        <td>
                                            <?= h($stock['branch_name'] ?? 'Unknown Branch') ?>
                                        </td>

                                    <?php endif; ?>

                                    <td class="history-quantity">
                                        <?= number_format((int)$stock['quantity']) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            !empty($stock['supplier'])
                                                ? $stock['supplier']
                                                : 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h(
                                            !empty($stock['delivery_reference'])
                                                ? $stock['delivery_reference']
                                                : 'N/A'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= h($stock['received_by_name'] ?? 'N/A') ?>
                                    </td>

                                    <td>

                                        <?php
                                        $timestamp = strtotime(
                                            $stock['received_at'] ?? ''
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
