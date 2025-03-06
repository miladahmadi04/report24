<?php
// companies.php - Manage companies
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check admin access
requireAdmin();

$message = '';

// Add new company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $name = clean($_POST['name']);
    
    if (empty($name)) {
        $message = showError('لطفا نام شرکت را وارد کنید.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt->execute([$name]);
            $message = showSuccess('شرکت با موفقیت اضافه شد.');
        } catch (PDOException $e) {
            $message = showError('خطا در ثبت شرکت: ' . $e->getMessage());
        }
    }
}

// Toggle company status
if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $companyId = $_GET['toggle'];
    
    // Get current status
    $stmt = $pdo->prepare("SELECT is_active FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if ($company) {
        $newStatus = $company['is_active'] ? 0 : 1;
        
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update company status
            $stmt = $pdo->prepare("UPDATE companies SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $companyId]);
            
            // If deactivating company, also deactivate its personnel
            if ($newStatus == 0) {
                $stmt = $pdo->prepare("UPDATE personnel SET is_active = 0 WHERE company_id = ?");
                $stmt->execute([$companyId]);
            }
            
            $pdo->commit();
            $message = showSuccess('وضعیت شرکت با موفقیت تغییر کرد.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = showError('خطا در تغییر وضعیت شرکت: ' . $e->getMessage());
        }
    }
}

// Get all companies
$stmt = $pdo->query("SELECT * FROM companies ORDER BY name");
$companies = $stmt->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>مدیریت شرکت‌ها</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCompanyModal">
        <i class="fas fa-plus"></i> افزودن شرکت جدید
    </button>
</div>

<?php echo $message; ?>

<div class="card">
    <div class="card-body">
        <?php if (count($companies) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>نام شرکت</th>
                            <th>وضعیت</th>
                            <th>تاریخ ایجاد</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($companies as $index => $company): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo $company['name']; ?></td>
                                <td>
                                    <?php if ($company['is_active']): ?>
                                        <span class="badge bg-success">فعال</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">غیرفعال</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $company['created_at']; ?></td>
                                <td>
                                    <a href="?toggle=<?php echo $company['id']; ?>" class="btn btn-sm 
                                        <?php echo $company['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $company['is_active'] ? 'غیرفعال کردن' : 'فعال کردن'; ?>
                                    </a>
                                    <a href="personnel.php?company=<?php echo $company['id']; ?>" class="btn btn-sm btn-info">
                                        مشاهده پرسنل
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-center">هیچ شرکتی یافت نشد.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Add Company Modal -->
<div class="modal fade" id="addCompanyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">افزودن شرکت جدید</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">نام شرکت</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">انصراف</button>
                    <button type="submit" name="add_company" class="btn btn-primary">ذخیره</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>