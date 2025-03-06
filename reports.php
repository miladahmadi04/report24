<?php
// reports.php - Submit daily reports with multiple items
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Require login (any user type can submit reports)
requireLogin();

$message = '';
$personnelId = $_SESSION['user_id'];
$companyId = $_SESSION['company_id']; // دریافت شرکت فعال کاربر

// Add new report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_report'])) {
    $report_date = clean($_POST['report_date']);
    $item_contents = $_POST['item_content'];
    $item_categories = isset($_POST['item_categories']) ? $_POST['item_categories'] : [];
    
    if (empty($report_date) || empty($item_contents) || !array_filter($item_contents)) {
        $message = showError('لطفا تاریخ گزارش و حداقل یک آیتم را وارد کنید.');
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Insert main report - اضافه کردن شرکت فعال
            $stmt = $pdo->prepare("INSERT INTO reports (personnel_id, report_date, company_id) VALUES (?, ?, ?)");
            $stmt->execute([$personnelId, $report_date, $companyId]);
            $reportId = $pdo->lastInsertId();
            
            // Insert report items and their categories
            foreach ($item_contents as $index => $content) {
                if (!empty($content)) {
                    // Insert report item
                    $stmt = $pdo->prepare("INSERT INTO report_items (report_id, content) VALUES (?, ?)");
                    $stmt->execute([$reportId, $content]);
                    $itemId = $pdo->lastInsertId();
                    
                    // Insert item categories if any
                    if (isset($item_categories[$index]) && !empty($item_categories[$index])) {
                        $insertValues = [];
                        $placeholders = [];
                        
                        foreach ($item_categories[$index] as $categoryId) {
                            $insertValues[] = $itemId;
                            $insertValues[] = $categoryId;
                            $placeholders[] = "(?, ?)";
                        }
                        
                        $placeholdersStr = implode(', ', $placeholders);
                        $stmt = $pdo->prepare("INSERT INTO report_item_categories (item_id, category_id) VALUES " . $placeholdersStr);
                        $stmt->execute($insertValues);
                    }
                }
            }
            
            $pdo->commit();
            $message = showSuccess('گزارش با موفقیت ثبت شد.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = showError('خطا در ثبت گزارش: ' . $e->getMessage());
        }
    }
}

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>ثبت گزارش روزانه</h1>
</div>

<?php echo $message; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <div class="row mb-3">
                <label for="report_date" class="col-md-2 col-form-label">تاریخ گزارش</label>
                <div class="col-md-10">
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <!-- نمایش شرکت فعال کاربر -->
            <div class="row mb-3">
                <label class="col-md-2 col-form-label">شرکت</label>
                <div class="col-md-10">
                    <input type="text" class="form-control" value="<?php echo $_SESSION['company_name']; ?>" disabled>
                    <small class="form-text text-muted">گزارش برای شرکت فعال شما ثبت خواهد شد.</small>
                </div>
            </div>
            
            <div class="report-items">
                <h5 class="mb-3">آیتم‌های گزارش</h5>
                <div id="items-container">
                    <!-- First item is added by default -->
                    <div class="item-block mb-4 border p-3 rounded" data-index="0">
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">متن آیتم</label>
                                <textarea class="form-control" name="item_content[0]" rows="3" required></textarea>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12">
                                <label class="form-label">دسته‌بندی‌ها</label>
                                <div class="row">
                                    <?php foreach ($categories as $category): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="item_categories[0][]" value="<?php echo $category['id']; ?>" id="category_0_<?php echo $category['id']; ?>">
                                                <label class="form-check-label" for="category_0_<?php echo $category['id']; ?>">
                                                    <?php echo $category['name']; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mb-4">
                    <button type="button" id="add-item-btn" class="btn btn-success">
                        <i class="fas fa-plus"></i> افزودن آیتم جدید
                    </button>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 text-center">
                    <button type="submit" name="add_report" class="btn btn-primary">ثبت گزارش</button>
                    <?php if (isAdmin()): ?>
                    <a href="admin_dashboard.php" class="btn btn-secondary me-2">انصراف</a>
                    <?php elseif (isCEO()): ?>
                    <a href="personnel_dashboard.php" class="btn btn-secondary me-2">انصراف</a>
                    <?php else: ?>
                    <a href="personnel_dashboard.php" class="btn btn-secondary me-2">انصراف</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Template for new items (hidden) -->
<template id="item-template">
    <div class="item-block mb-4 border p-3 rounded" data-index="__INDEX__">
        <div class="d-flex justify-content-end mb-2">
            <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                <i class="fas fa-times"></i> حذف آیتم
            </button>
        </div>
        <div class="row mb-3">
            <div class="col-md-12">
                <label class="form-label">متن آیتم</label>
                <textarea class="form-control" name="item_content[__INDEX__]" rows="3" required></textarea>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <label class="form-label">دسته‌بندی‌ها</label>
                <div class="row">
                    <?php foreach ($categories as $category): ?>
                        <div class="col-md-4 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="item_categories[__INDEX__][]" value="<?php echo $category['id']; ?>" id="category___INDEX___<?php echo $category['id']; ?>">
                                <label class="form-check-label" for="category___INDEX___<?php echo $category['id']; ?>">
                                    <?php echo $category['name']; ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add Item Button
    const addItemBtn = document.getElementById('add-item-btn');
    const itemsContainer = document.getElementById('items-container');
    const itemTemplate = document.getElementById('item-template');
    
    let itemIndex = 1; // Start with 1 because we already have item 0
    
    addItemBtn.addEventListener('click', function() {
        // Clone template content
        const template = itemTemplate.innerHTML;
        
        // Replace placeholder index with actual index
        const newItem = template.replace(/__INDEX__/g, itemIndex);
        
        // Add to container
        itemsContainer.insertAdjacentHTML('beforeend', newItem);
        
        // Increment index for next item
        itemIndex++;
        
        // Add event listeners to new remove buttons
        addRemoveListeners();
    });
    
    // Function to add event listeners to all remove buttons
    function addRemoveListeners() {
        const removeButtons = document.querySelectorAll('.remove-item-btn');
        removeButtons.forEach(button => {
            button.addEventListener('click', function() {
                // Remove the parent item block
                const itemBlock = this.closest('.item-block');
                itemBlock.remove();
            });
        });
    }
});
</script>

<?php include 'footer.php'; ?>