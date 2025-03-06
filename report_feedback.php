<?php
// report_feedback.php - View and add feedback for a report
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if logged in
requireLogin();

if (!isset($_GET['report_id']) || !is_numeric($_GET['report_id'])) {
    redirect('view_reports.php');
}

$reportId = clean($_GET['report_id']);
$userId = $_SESSION['user_id'];

// Get report details first to determine the company
$stmt = $pdo->prepare("SELECT r.*, p.username as personnel_name, p.id as personnel_id, r.company_id
                      FROM reports r 
                      JOIN personnel p ON r.personnel_id = p.id
                      WHERE r.id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    redirect('view_reports.php');
}

// Usamos la company_id del reporte en lugar de la sesión
$currentCompanyId = $report['company_id'];

// Verificamos que la compañía existe
$stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ?");
$stmt->execute([$currentCompanyId]);
if (!$stmt->fetch()) {
    $_SESSION['error_message'] = 'ID de compañía no válido.';
    redirect('view_reports.php');
}

// Check if user has permission to view and add feedback to this report
// Simplificamos la verificación de permisos
$hasPermission = false;
if (isAdmin()) {
    $hasPermission = true;
} elseif (isCEO() || isCoach()) {
    // Para CEO o Coach, verificar si tienen acceso a esta compañía
    $sessionCompanyId = isset($_SESSION['company_id']) ? $_SESSION['company_id'] : 0;
    $hasPermission = $currentCompanyId == $sessionCompanyId;
} else {
    // Para usuario normal, verificar si son el creador del reporte
    $hasPermission = $report['personnel_id'] == $userId;
}

if (!$hasPermission) {
    $_SESSION['error_message'] = 'شما دسترسی لازم برای ثبت بازخورد برای این گزارش را ندارید.';
    redirect('view_reports.php');
}

$message = '';

// Verificar si la tabla report_feedback existe
$tableExistsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM information_schema.tables 
    WHERE table_schema = DATABASE() 
    AND table_name = 'report_feedback'
");
$tableExistsStmt->execute();
$tableExists = (bool)$tableExistsStmt->fetchColumn();

// Crear la tabla si no existe - sin restricciones de clave externa
if (!$tableExists) {
    try {
        // Creamos la tabla sin restricciones de clave externa para mayor flexibilidad
        $sql = "CREATE TABLE report_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT NOT NULL,
            content TEXT NOT NULL,
            created_by INT NOT NULL,
            is_admin TINYINT(1) DEFAULT 0,
            creator_name VARCHAR(255) NULL,
            company_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        $pdo->exec($sql);
        $message = showSuccess('جدول بازخورد با موفقیت ایجاد شد. اکنون می‌توانید بازخورد ثبت کنید.');
    } catch (PDOException $e) {
        $message = showError('خطا در ایجاد جدول بازخورد: ' . $e->getMessage());
    }
}

// Si la tabla existe, verificamos su estructura y la actualizamos si es necesario
if ($tableExists) {
    try {
        // Comprobamos si la columna is_admin existe en la tabla
        $columnExistsStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.columns 
            WHERE table_schema = DATABASE() 
            AND table_name = 'report_feedback' 
            AND column_name = 'is_admin'
        ");
        $columnExistsStmt->execute();
        $isAdminColumnExists = (bool)$columnExistsStmt->fetchColumn();
        
        // Si la columna no existe, la añadimos
        if (!$isAdminColumnExists) {
            $pdo->exec("ALTER TABLE report_feedback 
                        ADD COLUMN is_admin TINYINT(1) DEFAULT 0,
                        ADD COLUMN creator_name VARCHAR(255) NULL");
            
            // Actualizamos los registros existentes
            $pdo->exec("UPDATE report_feedback rf 
                        JOIN personnel p ON rf.created_by = p.id 
                        SET rf.creator_name = p.username, 
                            rf.is_admin = 0");
        }
        
        // Intentamos eliminar las restricciones de clave externa que pueden causar problemas
        try {
            // Primero obtenemos los nombres de todas las restricciones
            $constraintStmt = $pdo->prepare("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_NAME = 'report_feedback'
                AND CONSTRAINT_NAME LIKE 'report_feedback_ibfk_%'
            ");
            $constraintStmt->execute();
            $constraints = $constraintStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Quitamos cada restricción
            foreach ($constraints as $constraintName) {
                $pdo->exec("ALTER TABLE report_feedback DROP FOREIGN KEY $constraintName");
            }
        } catch (PDOException $e) {
            // Ignoramos errores aquí, continuamos de todos modos
        }
    } catch (PDOException $e) {
        // Ignoramos errores aquí, continuamos de todos modos
    }
}

// Si la tabla existe o se creó correctamente, obtenemos los feedback existentes
$feedback = [];
try {
    // Consulta que funciona tanto con la estructura antigua como con la nueva
    $columnExistsStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
        AND table_name = 'report_feedback' 
        AND column_name = 'is_admin'
    ");
    $columnExistsStmt->execute();
    $isAdminColumnExists = (bool)$columnExistsStmt->fetchColumn();
    
    if ($isAdminColumnExists) {
        $stmt = $pdo->prepare("
            SELECT rf.*, 
            CASE 
                WHEN rf.is_admin = 1 THEN rf.creator_name
                WHEN rf.creator_name IS NOT NULL THEN rf.creator_name
                ELSE (SELECT username FROM personnel WHERE id = rf.created_by LIMIT 1)
            END as creator_name
            FROM report_feedback rf
            WHERE rf.report_id = ?
            ORDER BY rf.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT rf.* 
            FROM report_feedback rf
            WHERE rf.report_id = ?
            ORDER BY rf.created_at DESC
        ");
    }
    
    $stmt->execute([$reportId]);
    $feedback = $stmt->fetchAll();
    
    // Si estamos usando la estructura antigua, tenemos que obtener los nombres de creador manualmente
    if (!$isAdminColumnExists && !empty($feedback)) {
        foreach ($feedback as &$fb) {
            // Intentamos obtener el nombre del personal
            $creatorStmt = $pdo->prepare("SELECT username FROM personnel WHERE id = ?");
            $creatorStmt->execute([$fb['created_by']]);
            $creatorName = $creatorStmt->fetchColumn();
            
            if (!$creatorName) {
                // Intentamos con admin_users
                $adminStmt = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
                $adminStmt->execute([$fb['created_by']]);
                $creatorName = $adminStmt->fetchColumn();
            }
            
            $fb['creator_name'] = $creatorName ?: 'کاربر نامشخص';
        }
    }
} catch (PDOException $e) {
    // Si hay error, simplemente dejamos $feedback como array vacío
}

// Add new feedback
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_feedback'])) {
    $content = clean($_POST['feedback_content']);
    
    if (empty($content)) {
        $message = showError('لطفا متن بازخورد را وارد کنید.');
    } else {
        try {
            // Verificamos si el usuario es admin o usuario normal
            $isUserAdmin = isAdmin();
            $creatorName = '';
            
            if ($isUserAdmin) {
                // Si es admin, obtenemos su nombre de la tabla admin_users
                $stmt = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
                $stmt->execute([$userId]);
                $creatorName = $stmt->fetchColumn();
                
                // Verificamos si la columna is_admin existe
                $columnExistsStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'report_feedback' 
                    AND column_name = 'is_admin'
                ");
                $columnExistsStmt->execute();
                $isAdminColumnExists = (bool)$columnExistsStmt->fetchColumn();
                
                if ($isAdminColumnExists) {
                    // Usamos la nueva estructura de la tabla
                    $stmt = $pdo->prepare("
                        INSERT INTO report_feedback 
                        (report_id, content, created_by, company_id, is_admin, creator_name) 
                        VALUES (?, ?, ?, ?, 1, ?)
                    ");
                    $stmt->execute([$reportId, $content, $userId, $currentCompanyId, $creatorName]);
                } else {
                    // Para la estructura antigua, insertamos directamente
                    $stmt = $pdo->prepare("
                        INSERT INTO report_feedback 
                        (report_id, content, created_by, company_id) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$reportId, $content, $userId, $currentCompanyId]);
                }
            } else {
                // Para usuarios normales, obtenemos su nombre
                $stmt = $pdo->prepare("SELECT username FROM personnel WHERE id = ?");
                $stmt->execute([$userId]);
                $creatorName = $stmt->fetchColumn();
                
                // Verificamos si la columna is_admin existe
                $columnExistsStmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.columns 
                    WHERE table_schema = DATABASE() 
                    AND table_name = 'report_feedback' 
                    AND column_name = 'is_admin'
                ");
                $columnExistsStmt->execute();
                $isAdminColumnExists = (bool)$columnExistsStmt->fetchColumn();
                
                if ($isAdminColumnExists) {
                    // Usamos la nueva estructura de la tabla
                    $stmt = $pdo->prepare("
                        INSERT INTO report_feedback 
                        (report_id, content, created_by, company_id, is_admin, creator_name) 
                        VALUES (?, ?, ?, ?, 0, ?)
                    ");
                    $stmt->execute([$reportId, $content, $userId, $currentCompanyId, $creatorName]);
                } else {
                    // Para la estructura antigua, insertamos directamente
                    $stmt = $pdo->prepare("
                        INSERT INTO report_feedback 
                        (report_id, content, created_by, company_id) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$reportId, $content, $userId, $currentCompanyId]);
                }
            }
            
            $message = showSuccess('بازخورد با موفقیت ثبت شد.');
            
            // Actualizamos la lista de feedback
            if ($isAdminColumnExists) {
                $stmt = $pdo->prepare("
                    SELECT rf.*, 
                    CASE 
                        WHEN rf.is_admin = 1 THEN rf.creator_name
                        WHEN rf.creator_name IS NOT NULL THEN rf.creator_name
                        ELSE (SELECT username FROM personnel WHERE id = rf.created_by LIMIT 1)
                    END as creator_name
                    FROM report_feedback rf
                    WHERE rf.report_id = ?
                    ORDER BY rf.created_at DESC
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT rf.* 
                    FROM report_feedback rf
                    WHERE rf.report_id = ?
                    ORDER BY rf.created_at DESC
                ");
            }
            
            $stmt->execute([$reportId]);
            $feedback = $stmt->fetchAll();
            
            // Si estamos usando la estructura antigua, tenemos que obtener los nombres de creador manualmente
            if (!$isAdminColumnExists && !empty($feedback)) {
                foreach ($feedback as &$fb) {
                    // Intentamos obtener el nombre del personal
                    $creatorStmt = $pdo->prepare("SELECT username FROM personnel WHERE id = ?");
                    $creatorStmt->execute([$fb['created_by']]);
                    $creatorName = $creatorStmt->fetchColumn();
                    
                    if (!$creatorName) {
                        // Intentamos con admin_users
                        $adminStmt = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
                        $adminStmt->execute([$fb['created_by']]);
                        $creatorName = $adminStmt->fetchColumn();
                    }
                    
                    $fb['creator_name'] = $creatorName ?: 'کاربر نامشخص';
                }
            }
            
        } catch (Exception $e) {
            $message = showError('خطا در ثبت بازخورد: ' . $e->getMessage());
        }
    }
}

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>بازخوردهای گزارش</h1>
    <a href="view_report.php?id=<?php echo $reportId; ?>" class="btn btn-secondary">بازگشت به مشاهده گزارش</a>
</div>

<?php echo $message; ?>

<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0">اطلاعات گزارش</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <p><strong>تاریخ گزارش:</strong> <?php echo $report['report_date']; ?></p>
            </div>
            <div class="col-md-4">
                <p><strong>نام پرسنل:</strong> <?php echo $report['personnel_name']; ?></p>
            </div>
            <div class="col-md-4">
                <p><strong>تاریخ ایجاد:</strong> <?php echo $report['created_at']; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- فرم ثبت بازخورد جدید -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0">ثبت بازخورد جدید</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <div class="mb-3">
                <label for="feedback_content" class="form-label">متن بازخورد</label>
                <textarea class="form-control" id="feedback_content" name="feedback_content" rows="5" required></textarea>
            </div>
            <div class="text-center">
                <button type="submit" name="add_feedback" class="btn btn-success">ثبت بازخورد</button>
            </div>
        </form>
    </div>
</div>

<!-- لیست بازخوردهای موجود -->
<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0">بازخوردهای ثبت شده</h5>
    </div>
    <div class="card-body">
        <?php if (count($feedback) > 0): ?>
            <?php foreach ($feedback as $fb): ?>
                <div class="card mb-3 border-info">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between">
                            <h6 class="mb-0"><?php echo isset($fb['creator_name']) ? $fb['creator_name'] : 'کاربر نامشخص'; ?></h6>
                            <span>ثبت شده در: <?php echo $fb['created_at']; ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php echo nl2br(htmlspecialchars($fb['content'])); ?>
                    </div>
                    <?php 
                    // Verificación simplificada para modificar feedback
                    $canModifyFeedback = isAdmin() || 
                                       ((isCEO() && isCoach()) || isCoach()) && 
                                       isset($fb['company_id']) && $fb['company_id'] == $currentCompanyId;
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
        <?php else: ?>
            <div class="alert alert-info">
                هیچ بازخوردی برای این گزارش ثبت نشده است.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>