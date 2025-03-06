<?php
// new_coach_report_list.php - List of coach reports
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if user can view coach reports
$isAdmin = isAdmin();
$isCoach = isCoach();

// Store in session if user can view coach reports (for menu visibility)
$_SESSION['can_view_coach_reports'] = hasPermission('view_coach_reports') || $isCoach;

if (!$_SESSION['can_view_coach_reports']) {
    // Check if user has any reports as recipient
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE personnel_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    
    if ($stmt->fetchColumn() == 0) {
        redirect('index.php');
    }
}

$message = '';
$reports = [];

// Get filter parameters
$filterCompany = isset($_GET['company_id']) && is_numeric($_GET['company_id']) ? clean($_GET['company_id']) : null;
$filterDateFrom = isset($_GET['date_from']) ? clean($_GET['date_from']) : date('Y-m-01'); // First day of current month
$filterDateTo = isset($_GET['date_to']) ? clean($_GET['date_to']) : date('Y-m-t'); // Last day of current month

// Delete report (admin only)
if (hasPermission('delete_coach_report') && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $reportId = clean($_GET['delete']);
    
    // Check if user is a recipient (recipients should not be able to delete)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE coach_report_id = ? AND personnel_id = ?");
    $stmt->execute([$reportId, $_SESSION['user_id']]);
    $isRecipient = ($stmt->fetchColumn() > 0);
    
    // Prevent recipients from deleting reports
    if (!$isRecipient) {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Delete recipients
            $stmt = $pdo->prepare("DELETE FROM new_coach_report_recipients WHERE coach_report_id = ?");
            $stmt->execute([$reportId]);
            
            // Delete involved personnel
            $stmt = $pdo->prepare("DELETE FROM new_coach_report_personnel WHERE coach_report_id = ?");
            $stmt->execute([$reportId]);
            
            // Delete responses
            $stmt = $pdo->prepare("DELETE FROM new_coach_report_responses WHERE coach_report_id = ?");
            $stmt->execute([$reportId]);
            
            // Delete report
            $stmt = $pdo->prepare("DELETE FROM new_coach_reports WHERE id = ?");
            $stmt->execute([$reportId]);
            
            // Commit transaction
            $pdo->commit();
            
            $message = showSuccess('گزارش با موفقیت حذف شد.');
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = showError('خطا در حذف گزارش: ' . $e->getMessage());
        }
    } else {
        $message = showError('شما به عنوان دریافت کننده گزارش اجازه حذف آن را ندارید.');
    }
}

// Get all companies for filter
$companies = [];
if ($isAdmin) {
    $stmt = $pdo->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name");
    $companies = $stmt->fetchAll();
} else {
    // For coach or other users, get only their assigned companies
    $stmt = $pdo->prepare("SELECT c.id, c.name
                         FROM companies c
                         JOIN personnel_companies pc ON c.id = pc.company_id
                         WHERE pc.personnel_id = ? AND c.is_active = 1
                         ORDER BY c.name");
    $stmt->execute([$_SESSION['user_id']]);
    $companies = $stmt->fetchAll();
    
    // Also get their primary company
    $stmt = $pdo->prepare("SELECT c.id, c.name
                         FROM companies c
                         JOIN personnel p ON c.id = p.company_id
                         WHERE p.id = ? AND c.is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $primaryCompany = $stmt->fetch();
    
    if ($primaryCompany) {
        // Check if primary company already exists in the companies array
        $exists = false;
        foreach ($companies as $company) {
            if ($company['id'] == $primaryCompany['id']) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $companies[] = $primaryCompany;
        }
    }
}

// Get reports based on user role
$params = [];
$query = "SELECT r.*, c.name as company_name, 
         CONCAT(p.first_name, ' ', p.last_name) as creator_name,
         (SELECT COUNT(*) FROM new_coach_report_personnel WHERE coach_report_id = r.id) as personnel_count
         FROM new_coach_reports r
         JOIN companies c ON r.company_id = c.id
         JOIN personnel p ON r.created_by = p.id";

if ($isAdmin) {
    // Admin can see all reports
    $whereClause = [];
    
    if ($filterCompany) {
        $whereClause[] = "r.company_id = ?";
        $params[] = $filterCompany;
    }
    
    if ($filterDateFrom) {
        $whereClause[] = "r.report_date >= ?";
        $params[] = $filterDateFrom;
    }
    
    if ($filterDateTo) {
        $whereClause[] = "r.report_date <= ?";
        $params[] = $filterDateTo;
    }
    
    if (!empty($whereClause)) {
        $query .= " WHERE " . implode(" AND ", $whereClause);
    }
    
} elseif ($isCoach) {
    // Coach can see reports they created or where they are recipients
    $whereClause = [];
    
    $whereClause[] = "(r.created_by = ? OR r.id IN (SELECT coach_report_id FROM new_coach_report_recipients WHERE personnel_id = ?))";
    $params[] = $_SESSION['user_id'];
    $params[] = $_SESSION['user_id'];
    
    if ($filterCompany) {
        $whereClause[] = "r.company_id = ?";
        $params[] = $filterCompany;
    }
    
    if ($filterDateFrom) {
        $whereClause[] = "r.report_date >= ?";
        $params[] = $filterDateFrom;
    }
    
    if ($filterDateTo) {
        $whereClause[] = "r.report_date <= ?";
        $params[] = $filterDateTo;
    }
    
    $query .= " WHERE " . implode(" AND ", $whereClause);
    
} else {
    // Regular users can only see reports where they are recipients
    $query .= " WHERE r.id IN (SELECT coach_report_id FROM new_coach_report_recipients WHERE personnel_id = ?)";
    $params[] = $_SESSION['user_id'];
    
    if ($filterDateFrom) {
        $query .= " AND r.report_date >= ?";
        $params[] = $filterDateFrom;
    }
    
    if ($filterDateTo) {
        $query .= " AND r.report_date <= ?";
        $params[] = $filterDateTo;
    }
}

$query .= " ORDER BY r.report_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll();

include 'header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-md-10 ms-auto">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>لیست گزارش‌های کوچ</h1>
                <?php if (hasPermission('add_coach_report') || $isCoach): ?>
                    <a href="new_coach_report.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> ثبت گزارش جدید
                    </a>
                <?php endif; ?>
            </div>
            
            <?php echo $message; ?>
            
            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0">فیلترها</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row align-items-end">
                        <?php if ($isAdmin || count($companies) > 1): ?>
                            <div class="col-md-3 mb-3">
                                <label for="company_id" class="form-label">شرکت</label>
                                <select class="form-select" id="company_id" name="company_id">
                                    <option value="">همه شرکت‌ها</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>" <?php echo ($filterCompany == $company['id']) ? 'selected' : ''; ?>>
                                            <?php echo $company['name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3 mb-3">
                            <label for="date_from" class="form-label">از تاریخ</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $filterDateFrom; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="date_to" class="form-label">تا تاریخ</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $filterDateTo; ?>">
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <button type="submit" class="btn btn-primary w-100">اعمال فیلتر</button>
                        </div>
                        
                        <div class="col-12">
                            <div class="btn-group w-100">
                                <a href="?date_from=<?php echo date('Y-m-d'); ?>&date_to=<?php echo date('Y-m-d'); ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" class="btn btn-outline-secondary">امروز</a>
                                <a href="?date_from=<?php echo date('Y-m-d', strtotime('this week')); ?>&date_to=<?php echo date('Y-m-d'); ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" class="btn btn-outline-secondary">هفته جاری</a>
                                <a href="?date_from=<?php echo date('Y-m-01'); ?>&date_to=<?php echo date('Y-m-t'); ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" class="btn btn-outline-secondary">ماه جاری</a>
                                <a href="?date_from=<?php echo date('Y-m-01', strtotime('last month')); ?>&date_to=<?php echo date('Y-m-t', strtotime('last month')); ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" class="btn btn-outline-secondary">ماه گذشته</a>
                                <a href="?date_from=<?php echo date('Y-01-01'); ?>&date_to=<?php echo date('Y-12-31'); ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" class="btn btn-outline-secondary">سال جاری</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Reports List -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($reports) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>شناسه</th>
                                        <th>تاریخ گزارش</th>
                                        <th>شرکت</th>
                                        <th>دوره گزارش</th>
                                        <th>تهیه کننده</th>
                                        <th>تعداد افراد</th>
                                        <th>عملیات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reports as $report): ?>
                                        <tr>
                                            <td><?php echo $report['id']; ?></td>
                                            <td><?php echo date('Y/m/d', strtotime($report['report_date'])); ?></td>
                                            <td><?php echo $report['company_name']; ?></td>
                                            <td>
                                                <?php echo date('Y/m/d', strtotime($report['date_from'])); ?> تا 
                                                <?php echo date('Y/m/d', strtotime($report['date_to'])); ?>
                                            </td>
                                            <td><?php echo $report['creator_name']; ?></td>
                                            <td><?php echo $report['personnel_count']; ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="new_coach_report_view.php?id=<?php echo $report['id']; ?>" class="btn btn-info">
                                                        <i class="fas fa-eye"></i> مشاهده
                                                    </a>
                                                    
                                                    <?php 
                                                    // Check if user is just a recipient
                                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE coach_report_id = ? AND personnel_id = ?");
                                                    $stmt->execute([$report['id'], $_SESSION['user_id']]);
                                                    $isRecipient = ($stmt->fetchColumn() > 0);
                                                    
                                                    // Check if user is the creator
                                                    $isCreator = isCoach() && $report['created_by'] == $_SESSION['user_id'];
                                                    
                                                    // Only show edit button if user has permission AND is not just a recipient
                                                    // Allow creators or admins with edit permission to edit
                                                    if ((hasPermission('edit_coach_report') || $isCreator) && !($isRecipient && !$isCreator && !isAdmin())): 
                                                    ?>
                                                        <a href="new_coach_report_edit.php?id=<?php echo $report['id']; ?>" class="btn btn-primary">
                                                            <i class="fas fa-edit"></i> ویرایش
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                    // Only show delete button for admins with proper permission who are NOT recipients
                                                    if (hasPermission('delete_coach_report') && !$isRecipient): 
                                                    ?>
                                                        <a href="?delete=<?php echo $report['id']; ?>&date_from=<?php echo $filterDateFrom; ?>&date_to=<?php echo $filterDateTo; ?><?php echo $filterCompany ? '&company_id='.$filterCompany : ''; ?>" 
                                                           class="btn btn-danger"
                                                           onclick="return confirm('آیا از حذف این گزارش اطمینان دارید؟ این عمل غیرقابل بازگشت است.')">
                                                            <i class="fas fa-trash"></i> حذف
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            هیچ گزارشی با معیارهای انتخاب شده یافت نشد.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>