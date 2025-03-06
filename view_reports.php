<?php
// view_reports.php - View personnel reports with enhanced filtering
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if logged in
requireLogin();

$personnelId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
// Verificar si company_id existe en la sesión y asignar un valor predeterminado si no existe
$currentCompanyId = isset($_SESSION['company_id']) ? $_SESSION['company_id'] : 0; 

// Procesar eliminación directa si se solicita
if (isset($_GET['delete_report']) && is_numeric($_GET['delete_report'])) {
    $reportIdToDelete = clean($_GET['delete_report']);
    
    // Verificar permisos para borrar
    $canDelete = false;
    
    if (isAdmin()) {
        $canDelete = true;
    } else {
        // Verificar si el reporte pertenece a la empresa actual
        $stmt = $pdo->prepare("SELECT company_id FROM reports WHERE id = ?");
        $stmt->execute([$reportIdToDelete]);
        $reportCompanyId = $stmt->fetchColumn();
        
        // Solo pueden borrar Coach o CEO con rol de Coach de la misma empresa
        if ($reportCompanyId == $currentCompanyId) {
            if (isCoach() || (isCEO() && isCoach())) {
                $canDelete = true;
            }
        }
    }
    
    if ($canDelete) {
        try {
            // Iniciar transacción
            $pdo->beginTransaction();
            
            // Verificar si la tabla report_feedback existe
            $tableExistsStmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = 'report_feedback'
            ");
            $tableExistsStmt->execute();
            $feedbackTableExists = (bool)$tableExistsStmt->fetchColumn();
            
            // Eliminar feedback si la tabla existe
            if ($feedbackTableExists) {
                $stmt = $pdo->prepare("DELETE FROM report_feedback WHERE report_id = ?");
                $stmt->execute([$reportIdToDelete]);
            }
            
            // Eliminar categorías de items
            $stmt = $pdo->prepare("
                DELETE ric FROM report_item_categories ric
                JOIN report_items ri ON ric.item_id = ri.id
                WHERE ri.report_id = ?
            ");
            $stmt->execute([$reportIdToDelete]);
            
            // Eliminar items del reporte
            $stmt = $pdo->prepare("DELETE FROM report_items WHERE report_id = ?");
            $stmt->execute([$reportIdToDelete]);
            
            // Eliminar el reporte
            $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
            $stmt->execute([$reportIdToDelete]);
            
            $pdo->commit();
            
            // Mensaje de éxito
            $successMessage = "گزارش با موفقیت حذف شد";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errorMessage = "خطا در حذف گزارش: " . $e->getMessage();
        }
    } else {
        $errorMessage = "شما مجوز حذف این گزارش را ندارید";
    }
}

// Filter parameters
$startDate = isset($_GET['start_date']) ? clean($_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? clean($_GET['end_date']) : '';
$categoryId = isset($_GET['category']) && is_numeric($_GET['category']) ? clean($_GET['category']) : '';
$companyFilter = isset($_GET['company']) && is_numeric($_GET['company']) ? clean($_GET['company']) : '';
$personnelFilter = isset($_GET['personnel']) && is_numeric($_GET['personnel']) ? clean($_GET['personnel']) : '';

// Get all categories for filter
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

// For admin, get companies for filter
if (isAdmin()) {
    $stmt = $pdo->query("SELECT * FROM companies ORDER BY name");
    $companies = $stmt->fetchAll();
    
    // Get personnel for the selected company (if any)
    $personnel = [];
    if (!empty($companyFilter)) {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM personnel WHERE company_id = ? ORDER BY first_name, last_name");
        $stmt->execute([$companyFilter]);
        $personnel = $stmt->fetchAll();
    }
}

// Build query based on user type and permission rules
if (isAdmin()) {
    // Admin can see all reports
    $query = "SELECT r.*, 
              CONCAT(p.first_name, ' ', p.last_name) as personnel_name, 
              c.name as company_name,
              (SELECT COUNT(*) FROM report_items WHERE report_id = r.id) as item_count
              FROM reports r
              JOIN personnel p ON r.personnel_id = p.id
              JOIN companies c ON r.company_id = c.id
              WHERE 1=1";
    $params = [];
    
    // Filter by company if selected
    if (!empty($companyFilter)) {
        $query .= " AND r.company_id = ?";
        $params[] = $companyFilter;
    }
    
    // Filter by personnel if selected
    if (!empty($personnelFilter)) {
        $query .= " AND p.id = ?";
        $params[] = $personnelFilter;
    }
} else {
    // Para cumplir con las reglas de acceso, construimos la consulta según el rol del usuario
    $isCEOCoach = isCEO() && isCoach();
    $isOnlyCoach = !isCEO() && isCoach();
    $isOnlyCEO = isCEO() && !isCoach();
    
    if ($isCEOCoach || $isOnlyCoach || $isOnlyCEO) {
        // CEO (con o sin rol Coach) o Coach pueden ver todos los informes de su empresa
        $query = "SELECT r.*, 
                 CONCAT(p.first_name, ' ', p.last_name) as personnel_name, 
                 c.name as company_name,
                 (SELECT COUNT(*) FROM report_items WHERE report_id = r.id) as item_count
                 FROM reports r
                 JOIN personnel p ON r.personnel_id = p.id
                 JOIN companies c ON r.company_id = c.id
                 WHERE r.company_id = ?";
        $params = [$currentCompanyId];
        
        // Filter by personnel if selected
        if (!empty($personnelFilter)) {
            $query .= " AND p.id = ?";
            $params[] = $personnelFilter;
        }
    } else {
        // Usuario normal solo puede ver sus propios informes
        $query = "SELECT r.*, 
                  CONCAT(p.first_name, ' ', p.last_name) as personnel_name,
                  c.name as company_name,
                  (SELECT COUNT(*) FROM report_items WHERE report_id = r.id) as item_count
                  FROM reports r
                  JOIN personnel p ON r.personnel_id = p.id
                  JOIN companies c ON r.company_id = c.id
                  WHERE r.personnel_id = ? AND r.company_id = ?";
        $params = [$personnelId, $currentCompanyId];
    }
}

// Apply filters
if (!empty($startDate)) {
    $query .= " AND r.report_date >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $query .= " AND r.report_date <= ?";
    $params[] = $endDate;
}

if (!empty($categoryId)) {
    $query .= " AND EXISTS (
                SELECT 1 FROM report_items ri 
                JOIN report_item_categories ric ON ri.id = ric.item_id 
                WHERE ri.report_id = r.id AND ric.category_id = ?)";
    $params[] = $categoryId;
}

$query .= " ORDER BY r.report_date DESC, r.created_at DESC";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Para mostrar el personal en el filtro de CEO/Coach
$companyPersonnel = [];
if ((isCEO() && isCoach()) || (!isCEO() && isCoach()) || (isCEO() && !isCoach())) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM personnel WHERE company_id = ? ORDER BY first_name, last_name");
    $stmt->execute([$currentCompanyId]);
    $companyPersonnel = $stmt->fetchAll();
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>مشاهده گزارش‌های روزانه</h1>
    <a href="reports.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> ثبت گزارش جدید
    </a>
</div>

<?php if (isset($successMessage)): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i> <?php echo $successMessage; ?>
</div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0">فیلترها</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" id="filterForm">
            <div class="row">
                <?php if (isAdmin()): ?>
                <div class="col-md-3 mb-3">
                    <label for="company" class="form-label">شرکت</label>
                    <select class="form-select" id="company" name="company">
                        <option value="">همه شرکت‌ها</option>
                        <?php foreach ($companies as $company): ?>
                            <option value="<?php echo $company['id']; ?>" <?php echo $companyFilter == $company['id'] ? 'selected' : ''; ?>>
                                <?php echo $company['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label for="personnel" class="form-label">پرسنل</label>
                    <select class="form-select" id="personnel" name="personnel" <?php echo empty($companyFilter) ? 'disabled' : ''; ?>>
                        <option value="">همه پرسنل</option>
                        <?php if (!empty($companyFilter) && !empty($personnel)): ?>
                            <?php foreach ($personnel as $person): ?>
                                <option value="<?php echo $person['id']; ?>" <?php echo $personnelFilter == $person['id'] ? 'selected' : ''; ?>>
                                    <?php echo $person['first_name'] . ' ' . $person['last_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <?php elseif ((isCEO() && isCoach()) || (!isCEO() && isCoach()) || (isCEO() && !isCoach())): ?>
                <!-- CEO (con o sin rol Coach) o Coach pueden filtrar por personal de su empresa -->
                <div class="col-md-3 mb-3">
                    <label for="personnel" class="form-label">پرسنل</label>
                    <select class="form-select" id="personnel" name="personnel">
                        <option value="">همه پرسنل</option>
                        <?php foreach ($companyPersonnel as $person): ?>
                            <option value="<?php echo $person['id']; ?>" <?php echo $personnelFilter == $person['id'] ? 'selected' : ''; ?>>
                                <?php echo $person['first_name'] . ' ' . $person['last_name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-<?php echo (isAdmin() || isCEO() || isCoach()) ? '2' : '3'; ?> mb-3">
                    <label for="start_date" class="form-label">از تاریخ</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-<?php echo (isAdmin() || isCEO() || isCoach()) ? '2' : '3'; ?> mb-3">
                    <label for="end_date" class="form-label">تا تاریخ</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-<?php echo (isAdmin() || isCEO() || isCoach()) ? '2' : '4'; ?> mb-3">
                    <label for="category" class="form-label">دسته‌بندی</label>
                    <select class="form-select" id="category" name="category">
                        <option value="">همه دسته‌بندی‌ها</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" <?php echo $categoryId == $category['id'] ? 'selected' : ''; ?>>
                                <?php echo $category['name']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-<?php echo (isAdmin() || isCEO() || isCoach()) ? '12' : '2'; ?> mb-3 <?php echo (isAdmin() || isCEO() || isCoach()) ? 'text-center' : 'd-flex align-items-end'; ?>">
                    <button type="submit" class="btn btn-primary <?php echo (isAdmin() || isCEO() || isCoach()) ? 'px-5' : 'w-100'; ?>">اعمال فیلتر</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if (!isAdmin()): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle"></i> در حال نمایش گزارش‌های شرکت: <strong><?php echo isset($_SESSION['company_name']) ? $_SESSION['company_name'] : 'نامشخص'; ?></strong>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (count($reports) > 0): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>تاریخ گزارش</th>
                            <th>نام و نام خانوادگی</th>
                            <?php if (isAdmin()): ?>
                            <th>شرکت</th>
                            <?php endif; ?>
                            <th>تعداد آیتم</th>
                            <th>تاریخ ثبت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $index => $report): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo $report['report_date']; ?></td>
                                <td><?php echo $report['personnel_name']; ?></td>
                                <?php if (isAdmin()): ?>
                                <td><?php echo $report['company_name']; ?></td>
                                <?php endif; ?>
                                <td><?php echo $report['item_count']; ?></td>
                                <td><?php echo $report['created_at']; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="view_report.php?id=<?php echo $report['id']; ?>" class="btn btn-info">
                                            <i class="fas fa-eye"></i> مشاهده
                                        </a>
                                        
                                        <?php 
                                        // Verificamos si el usuario tiene permisos para editar/eliminar (CEO con rol Coach o Coach)
                                        $canModify = (isAdmin() || 
                                                     ((isCEO() && isCoach()) || (!isCEO() && isCoach())) && 
                                                     $report['company_id'] == $currentCompanyId);
                                        
                                        if ($canModify): 
                                        ?>
                                        <a href="edit_report.php?id=<?php echo $report['id']; ?>" class="btn btn-primary">
                                            <i class="fas fa-edit"></i> ویرایش
                                        </a>
                                        <a href="?delete_report=<?php echo $report['id']; ?>" class="btn btn-danger" 
                                           onclick="return confirm('آیا از حذف این گزارش اطمینان دارید؟ این عمل قابل بازگشت نیست.');">
                                            <i class="fas fa-trash"></i> حذف
                                        </a>
                                        <?php endif; ?>
                                        
                                        <a href="report_feedback.php?report_id=<?php echo $report['id']; ?>" class="btn btn-success">
                                            <i class="fas fa-comment"></i> بازخورد
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-center">هیچ گزارشی یافت نشد.</p>
        <?php endif; ?>
    </div>
</div>

<?php if (isAdmin()): ?>
<!-- AJAX script to get personnel when company changes -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const companySelect = document.getElementById('company');
    const personnelSelect = document.getElementById('personnel');
    
    companySelect.addEventListener('change', function() {
        const companyId = this.value;
        
        // Clear personnel dropdown
        personnelSelect.innerHTML = '<option value="">همه پرسنل</option>';
        
        if (companyId) {
            // Enable personnel dropdown
            personnelSelect.disabled = false;
            
            // Fetch personnel for the selected company
            fetch('get_personnel.php?company_id=' + companyId)
                .then(response => response.json())
                .then(data => {
                    data.forEach(person => {
                        const option = document.createElement('option');
                        option.value = person.id;
                        option.textContent = person.first_name + ' ' + person.last_name;
                        personnelSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error fetching personnel:', error);
                });
        } else {
            // Disable personnel dropdown when no company is selected
            personnelSelect.disabled = true;
        }
    });
});
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>