<?php
// delete_report.php - Delete an existing report
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if logged in
requireLogin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('view_reports.php');
}

$reportId = clean($_GET['id']);

// Check if user has permission to delete this report
if (!canModifyReport($reportId, $pdo)) {
    $_SESSION['error_message'] = 'شما دسترسی لازم برای حذف این گزارش را ندارید.';
    redirect('view_reports.php');
}

// Get report details for confirmation
$stmt = $pdo->prepare("SELECT r.*, p.username as personnel_name
                      FROM reports r 
                      JOIN personnel p ON r.personnel_id = p.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    redirect('view_reports.php');
}

$message = '';

// Process deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete feedback related to this report
        $stmt = $pdo->prepare("DELETE FROM report_feedback WHERE report_id = ?");
        $stmt->execute([$reportId]);
        
        // Get all item IDs for this report
        $stmt = $pdo->prepare("SELECT id FROM report_items WHERE report_id = ?");
        $stmt->execute([$reportId]);
        $itemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($itemIds)) {
            // Delete categories for these items
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM report_item_categories WHERE item_id IN ($placeholders)");
            $stmt->execute($itemIds);
            
            // Delete report items
            $stmt = $pdo->prepare("DELETE FROM report_items WHERE report_id = ?");
            $stmt->execute([$reportId]);
        }
        
        // Delete the report itself
        $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$reportId]);
        
        $pdo->commit();
        
        $_SESSION['success_message'] = 'گزارش با موفقیت حذف شد.';
        redirect('view_reports.php');
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = showError('خطا در حذف گزارش: ' . $e->getMessage());
    }
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>حذف گزارش</h1>
    <a href="view_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary">بازگشت به مشاهده گزارش</a>
</div>

<?php echo $message; ?>

<div class="card">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0">تأیید حذف گزارش</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> هشدار: این عمل غیرقابل بازگشت است و تمام اطلاعات گزارش، آیتم‌ها و بازخوردهای آن حذف خواهد شد.
        </div>
        
        <div class="mb-4">
            <p><strong>تاریخ گزارش:</strong> <?php echo $report['report_date']; ?></p>
            <p><strong>نام پرسنل:</strong> <?php echo $report['personnel_name']; ?></p>
            <p><strong>تاریخ ایجاد:</strong> <?php echo $report['created_at']; ?></p>
        </div>
        
        <form method="POST" action="">
            <div class="row">
                <div class="col-md-12 text-center">
                    <button type="submit" name="confirm_delete" class="btn btn-danger" onclick="return confirm('آیا از حذف این گزارش اطمینان دارید؟');">
                        تأیید و حذف گزارش
                    </button>
                    <a href="view_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary me-2">انصراف</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>