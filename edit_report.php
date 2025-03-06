<?php
// edit_report.php - Edit an existing report
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if logged in
requireLogin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    redirect('view_reports.php');
}

$reportId = clean($_GET['id']);
// Verificar si company_id existe en la sesión y asignar un valor predeterminado si no existe
$currentCompanyId = isset($_SESSION['company_id']) ? $_SESSION['company_id'] : 0;

// Get report details first to get the company_id from the report
$stmt = $pdo->prepare("SELECT r.*, c.id as company_id, c.name as company_name 
                      FROM reports r 
                      JOIN companies c ON r.company_id = c.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$reportInfo = $stmt->fetch();

if (!$reportInfo) {
    redirect('view_reports.php');
}

// Check if user has permission to edit this report
$canModify = false;
if (isAdmin()) {
    $canModify = true;
} elseif ((isCoach() || (isCEO() && isCoach())) && $reportInfo['company_id'] == $currentCompanyId) {
    $canModify = true;
}

if (!$canModify) {
    $_SESSION['error_message'] = 'شما دسترسی لازم برای ویرایش این گزارش را ندارید.';
    redirect('view_reports.php');
}

$message = '';

// Get report details with personnel info
$stmt = $pdo->prepare("SELECT r.*, CONCAT(p.first_name, ' ', p.last_name) as personnel_name,
                      c.name as company_name
                      FROM reports r 
                      JOIN personnel p ON r.personnel_id = p.id
                      JOIN companies c ON r.company_id = c.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

// Get report items with their categories
$stmt = $pdo->prepare("SELECT ri.* FROM report_items ri WHERE ri.report_id = ? ORDER BY ri.id");
$stmt->execute([$reportId]);
$reportItems = $stmt->fetchAll();

// Get categories for each item
foreach ($reportItems as $key => $item) {
    $stmt = $pdo->prepare("SELECT category_id FROM report_item_categories WHERE item_id = ?");
    $stmt->execute([$item['id']]);
    $reportItems[$key]['categories'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Update report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_report'])) {
    $report_date = clean($_POST['report_date']);
    $item_contents = $_POST['item_content'];
    $item_ids = isset($_POST['item_id']) ? $_POST['item_id'] : [];
    $item_categories = isset($_POST['item_categories']) ? $_POST['item_categories'] : [];
    
    if (empty($report_date) || empty($item_contents) || !array_filter($item_contents)) {
        $message = showError('لطفا تاریخ گزارش و حداقل یک آیتم را وارد کنید.');
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update report date
            $stmt = $pdo->prepare("UPDATE reports SET report_date = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$report_date, $reportId]);
            
            // Get existing item IDs
            $stmt = $pdo->prepare("SELECT id FROM report_items WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $existingItemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Process items
            $processedItemIds = [];
            
            foreach ($item_contents as $index => $content) {
                if (!empty($content)) {
                    $itemId = isset($item_ids[$index]) ? $item_ids[$index] : null;
                    
                    if ($itemId && in_array($itemId, $existingItemIds)) {
                        // Update existing item
                        $stmt = $pdo->prepare("UPDATE report_items SET content = ? WHERE id = ?");
                        $stmt->execute([$content, $itemId]);
                        $processedItemIds[] = $itemId;
                        
                        // Delete existing categories for this item
                        $stmt = $pdo->prepare("DELETE FROM report_item_categories WHERE item_id = ?");
                        $stmt->execute([$itemId]);
                    } else {
                        // Insert new item
                        $stmt = $pdo->prepare("INSERT INTO report_items (report_id, content) VALUES (?, ?)");
                        $stmt->execute([$reportId, $content]);
                        $itemId = $pdo->lastInsertId();
                        $processedItemIds[] = $itemId;
                    }
                    
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
            
            // Delete items that are no longer in the form
            $itemsToDelete = array_diff($existingItemIds, $processedItemIds);
            if (!empty($itemsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($itemsToDelete), '?'));
                
                // Delete categories for these items
                $stmt = $pdo->prepare("DELETE FROM report_item_categories WHERE item_id IN ($placeholders)");
                $stmt->execute($itemsToDelete);
                
                // Delete the items
                $stmt = $pdo->prepare("DELETE FROM report_items WHERE id IN ($placeholders)");
                $stmt->execute($itemsToDelete);
            }
            
            $pdo->commit();
            $message = showSuccess('گزارش با موفقیت به‌روزرسانی شد.');
            
            // Refresh report items
            $stmt = $pdo->prepare("SELECT ri.* FROM report_items ri WHERE ri.report_id = ? ORDER BY ri.id");
            $stmt->execute([$reportId]);
            $reportItems = $stmt->fetchAll();
            
            // Refresh categories for each item
            foreach ($reportItems as $key => $item) {
                $stmt = $pdo->prepare("SELECT category_id FROM report_item_categories WHERE item_id = ?");
                $stmt->execute([$item['id']]);
                $reportItems[$key]['categories'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = showError('خطا در به‌روزرسانی گزارش: ' . $e->getMessage());
        }
    }
}

// Get all categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>ویرایش گزارش</h1>
    <a href="view_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary">بازگشت به مشاهده گزارش</a>
</div>

<?php echo $message; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <div class="row mb-3">
                <label for="report_date" class="col-md-2 col-form-label">تاریخ گزارش</label>
                <div class="col-md-10">
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo $report['report_date']; ?>" required>
                </div>
            </div>
            
            <div class="row mb-3">
                <label class="col-md-2 col-form-label">نام و نام خانوادگی</label>
                <div class="col-md-10">
                    <input type="text" class="form-control" value="<?php echo $report['personnel_name']; ?>" disabled>
                </div>
            </div>
            
            <div class="row mb-3">
                <label class="col-md-2 col-form-label">شرکت</label>
                <div class="col-md-10">
                    <input type="text" class="form-control" value="<?php echo $report['company_name']; ?>" disabled>
                </div>
            </div>
            
            <div class="report-items">
                <h5 class="mb-3">آیتم‌های گزارش</h5>
                <div id="items-container">
                    <?php foreach ($reportItems as $index => $item): ?>
                        <div class="item-block mb-4 border p-3 rounded" data-index="<?php echo $index; ?>">
                            <?php if ($index > 0): ?>
                                <div class="d-flex justify-content-end mb-2">
                                    <button type="button" class="btn btn-sm btn-danger remove-item-btn">
                                        <i class="fas fa-times"></i> حذف آیتم
                                    </button>
                                </div>
                            <?php endif; ?>
                            <input type="hidden" name="item_id[<?php echo $index; ?>]" value="<?php echo $item['id']; ?>">
                            <div class="row mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">متن آیتم</label>
                                    <textarea class="form-control" name="item_content[<?php echo $index; ?>]" rows="3" required><?php echo htmlspecialchars($item['content']); ?></textarea>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12">
                                    <label class="form-label">دسته‌بندی‌ها</label>
                                    <div class="row">
                                        <?php foreach ($categories as $category): ?>
                                            <div class="col-md-4 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" 
                                                           name="item_categories[<?php echo $index; ?>][]" 
                                                           value="<?php echo $category['id']; ?>" 
                                                           id="category_<?php echo $index; ?>_<?php echo $category['id']; ?>"
                                                           <?php echo in_array($category['id'], $item['categories']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="category_<?php echo $index; ?>_<?php echo $category['id']; ?>">
                                                        <?php echo $category['name']; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="text-center mb-4">
                    <button type="button" id="add-item-btn" class="btn btn-success">
                        <i class="fas fa-plus"></i> افزودن آیتم جدید
                    </button>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 text-center">
                    <button type="submit" name="update_report" class="btn btn-primary">به‌روزرسانی گزارش</button>
                    <a href="view_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary me-2">انصراف</a>
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
        <input type="hidden" name="item_id[__INDEX__]" value="">
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
    
    let itemIndex = <?php echo count($reportItems); ?>;
    
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
    
    // Add listeners to existing remove buttons
    addRemoveListeners();
});
</script>

<?php include 'footer.php'; ?>