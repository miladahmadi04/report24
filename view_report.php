<?php
// view_report.php - View a specific report with multiple items
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if logged in
requireLogin();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    if (isAdmin()) {
        redirect('admin_dashboard.php');
    } else {
        redirect('view_reports.php');
    }
}

$reportId = clean($_GET['id']);
// Verificar si company_id existe en la sesión
$currentCompanyId = isset($_SESSION['company_id']) ? $_SESSION['company_id'] : 0;
$currentUserId = $_SESSION['user_id'];

// Get report details
$stmt = $pdo->prepare("SELECT r.*, 
                      CONCAT(p.first_name, ' ', p.last_name) as personnel_name, 
                      p.id as personnel_id, 
                      c.name as company_name, 
                      c.id as company_id 
                      FROM reports r 
                      JOIN personnel p ON r.personnel_id = p.id 
                      JOIN companies c ON r.company_id = c.id 
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    if (isAdmin()) {
        redirect('admin_dashboard.php');
    } else {
        redirect('view_reports.php');
    }
}

// Verificar permisos de acceso según las reglas corregidas
$hasPermission = false;

if (isAdmin()) {
    // Admin siempre tiene acceso
    $hasPermission = true;
} else if ($report['personnel_id'] == $currentUserId) {
    // El creador del informe puede verlo
    $hasPermission = true;
} else if ($report['company_id'] == $currentCompanyId) {
    // Cualquier CEO puede ver informes de su empresa
    if (isCEO()) {
        $hasPermission = true;
    }
    // Coach de la misma empresa puede verlo
    else if (isCoach()) {
        $hasPermission = true;
    }
}

if (!$hasPermission) {
    $_SESSION['error_message'] = 'شما دسترسی لازم برای مشاهده این گزارش را ندارید.';
    redirect('view_reports.php');
}

// Get report items with their categories
$stmt = $pdo->prepare("SELECT ri.*, 
                      (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') 
                       FROM report_item_categories ric 
                       JOIN categories c ON ric.category_id = c.id 
                       WHERE ric.item_id = ri.id) as categories 
                      FROM report_items ri 
                      WHERE ri.report_id = ? 
                      ORDER BY ri.created_at");
$stmt->execute([$reportId]);
$reportItems = $stmt->fetchAll();

// Verificar si la tabla report_feedback existe antes de consultarla
$feedback = [];
try {
    // Comprobar si la tabla existe
    $tableExistsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name = 'report_feedback'
    ");
    $tableExistsStmt->execute();
    $tableExists = (bool)$tableExistsStmt->fetchColumn();
    
    if ($tableExists) {
        // La tabla existe, podemos consultarla
        $stmt = $pdo->prepare("SELECT rf.*, 
                           p.username as creator_name
                           FROM report_feedback rf
                           JOIN personnel p ON rf.created_by = p.id
                           WHERE rf.report_id = ?
                           ORDER BY rf.created_at");
        $stmt->execute([$reportId]);
        $feedback = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // Si hay un error, simplemente dejamos $feedback como un array vacío
    $feedback = [];
}

// Verificar permisos para modificar el informe - Solo Coach o CEO+Coach de la misma empresa
$canModifyReport = false;
if (isAdmin()) {
    $canModifyReport = true;
} else if ($report['company_id'] == $currentCompanyId) {
    if (isCoach() || (isCEO() && isCoach())) {
        $canModifyReport = true;
    }
}

// Verificar permisos para añadir feedback
$canAddFeedback = false;
if (isAdmin()) {
    $canAddFeedback = true;
} else if ($report['personnel_id'] == $currentUserId) {
    // Creador del informe
    $canAddFeedback = true;
} else if ($report['company_id'] == $currentCompanyId) {
    // CEO o Coach de la misma empresa
    if (isCEO() || isCoach()) {
        $canAddFeedback = true;
    }
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>مشاهده گزارش</h1>
    <div>
        <!-- Enlace siempre visible para volver a la lista de reportes -->
        <a href="view_reports.php" class="btn btn-secondary">
            <i class="fas fa-list"></i> لیست گزارش‌ها
        </a>
        
        <?php if ($canModifyReport): ?>
            <a href="edit_report.php?id=<?php echo $reportId; ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> ویرایش گزارش
            </a>
            <a href="delete_report.php?id=<?php echo $reportId; ?>" class="btn btn-danger" 
               onclick="return confirm('آیا از حذف این گزارش اطمینان دارید؟');">
                <i class="fas fa-trash"></i> حذف گزارش
            </a>
        <?php endif; ?>
        
        <?php if ($canAddFeedback): ?>
            <a href="report_feedback.php?report_id=<?php echo $reportId; ?>" class="btn btn-success">
                <i class="fas fa-comment"></i> ثبت بازخورد
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-light">
        <div class="d-flex justify-content-between">
            <h5 class="mb-0">گزارش مورخ <?php echo $report['report_date']; ?></h5>
            <span>ثبت شده در: <?php echo $report['created_at']; ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4">
                <p><strong>نام و نام خانوادگی:</strong> <?php echo $report['personnel_name']; ?></p>
            </div>
            <div class="col-md-4">
                <p><strong>شرکت:</strong> <?php echo $report['company_name']; ?></p>
            </div>
            <div class="col-md-4">
                <p><strong>تاریخ گزارش:</strong> <?php echo $report['report_date']; ?></p>
            </div>
        </div>
        
        <hr>
        
        <h5 class="mb-3">آیتم‌های گزارش</h5>
        
        <?php if (count($reportItems) > 0): ?>
            <?php foreach ($reportItems as $index => $item): ?>
                <div class="card mb-3 border-secondary">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between">
                            <h6 class="mb-0">آیتم <?php echo $index + 1; ?></h6>
                            <span>
                                <strong>دسته‌بندی‌ها:</strong> 
                                <?php echo $item['categories'] ? $item['categories'] : 'بدون دسته‌بندی'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo nl2br(htmlspecialchars($item['content'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-warning">
                هیچ آیتمی برای این گزارش یافت نشد.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($feedback)): ?>
<!-- بخش بازخوردها -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">بازخوردها</h5>
    </div>
    <div class="card-body">
        <?php foreach ($feedback as $index => $fb): ?>
            <div class="card mb-3 border-success">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-0"><?php echo $fb['creator_name']; ?></h6>
                        <span>ثبت شده در: <?php echo $fb['created_at']; ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <?php echo nl2br(htmlspecialchars($fb['content'])); ?>
                </div>
                <?php 
                // Solo Coach o CEO+Coach pueden modificar feedback
                $canModifyFeedback = false;
                if (isAdmin()) {
                    $canModifyFeedback = true;
                } else if (isset($fb['company_id']) && $fb['company_id'] == $currentCompanyId) {
                    if (isCoach() || (isCEO() && isCoach())) {
                        $canModifyFeedback = true;
                    }
                }
                
                if ($canModifyFeedback): 
                ?>
                <div class="card-footer bg-light text-end">
                    <a href="edit_feedback.php?id=<?php echo $fb['id']; ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-edit"></i> ویرایش
                    </a>
                    <a href="delete_feedback.php?id=<?php echo $fb['id']; ?>" class="btn btn-sm btn-danger" 
                       onclick="return confirm('آیا از حذف این بازخورد اطمینان دارید؟');">
                        <i class="fas fa-trash"></i> حذف
                    </a>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <!-- لینک به ثبت بازخورد جدید -->
        <?php if ($canAddFeedback): ?>
            <div class="text-center mt-3">
                <a href="report_feedback.php?report_id=<?php echo $reportId; ?>" class="btn btn-success">
                    <i class="fas fa-plus"></i> ثبت بازخورد جدید
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>