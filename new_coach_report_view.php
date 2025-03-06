<?php
// new_coach_report_view.php - View a single coach report
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if user can view coach reports
if (!hasPermission('view_coach_reports') && !isCoach()) {
    // Check if user is a recipient of this specific report
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $reportId = clean($_GET['id']);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE coach_report_id = ? AND personnel_id = ?");
        $stmt->execute([$reportId, $_SESSION['user_id']]);
        
        if ($stmt->fetchColumn() == 0) {
            redirect('index.php');
        }
    } else {
        redirect('index.php');
    }
}

$message = '';
$report = null;
$company = null;
$creator = null;
$involvedPersonnel = [];
$recipients = [];
$responses = [];

// Finding the personnel_id based on session information or using default if admin
// Finding the personnel_id based on session information or admin status
function findPersonnelId($pdo) {
    // اگر کاربر مدیر سیستم است
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        // پیدا کردن رکورد مدیر سیستم در جدول personnel
        $adminId = $_SESSION['user_id'];
        
        // جستجو بر اساس admin_id
        try {
            $stmt = $pdo->prepare("SELECT id FROM personnel WHERE admin_id = ?");
            $stmt->execute([$adminId]);
            $adminPersonnelId = $stmt->fetchColumn();
            
            if ($adminPersonnelId) {
                return $adminPersonnelId;
            }
        } catch (PDOException $e) {
            // ممکن است فیلد admin_id وجود نداشته باشد، خطا را نادیده می‌گیریم
        }
        
        // اگر پیدا نشد، اولین رکورد فعال را استفاده می‌کنیم
        $stmt = $pdo->query("SELECT id FROM personnel WHERE is_active = 1 ORDER BY id LIMIT 1");
        $adminPersonnelId = $stmt->fetchColumn();
        
        if ($adminPersonnelId) {
            return $adminPersonnelId;
        }
        
        // اگر هیچ رکوردی در جدول personnel پیدا نشد، با حداقل ID را برمی‌گردانیم
        $stmt = $pdo->query("SELECT MIN(id) FROM personnel");
        return $stmt->fetchColumn() ?: 1;
    }
    
    // برای سایر کاربران
    // First try: If we already have the personnel_id directly
    if (isset($_SESSION['user_id'])) {
        // Check if this is directly a personnel_id
        $stmt = $pdo->prepare("SELECT id FROM personnel WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $direct_id = $stmt->fetchColumn();
        
        if ($direct_id) {
            return $direct_id;
        }
        
        // Second attempt: Maybe user_id refers to user table and we need to find the personnel record
        $stmt = $pdo->prepare("SELECT id FROM personnel WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $via_user_id = $stmt->fetchColumn();
        
        if ($via_user_id) {
            return $via_user_id;
        }
    }
    
    // If we have a specific field for personnel_id
    if (isset($_SESSION['personnel_id'])) {
        return $_SESSION['personnel_id'];
    }
    
    // If nothing worked, return false
    return false;
}

// Get report details
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $reportId = clean($_GET['id']);
    
    // Get report basic information
    $stmt = $pdo->prepare("SELECT r.*, c.name as company_name, 
                         CONCAT(p.first_name, ' ', p.last_name) as creator_name
                       FROM new_coach_reports r
                       JOIN companies c ON r.company_id = c.id
                       JOIN personnel p ON r.created_by = p.id
                       WHERE r.id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    
    if (!$report) {
        redirect('new_coach_report_list.php');
    }
    
    // Get involved personnel
    $stmt = $pdo->prepare("SELECT rp.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as full_name,
                         p.id as personnel_id
                       FROM new_coach_report_personnel rp
                       JOIN personnel p ON rp.personnel_id = p.id
                       WHERE rp.coach_report_id = ?
                       ORDER BY p.first_name, p.last_name");
    $stmt->execute([$reportId]);
    $involvedPersonnel = $stmt->fetchAll();
    
    // Get recipients
    $stmt = $pdo->prepare("SELECT r.personnel_id, 
                         CONCAT(p.first_name, ' ', p.last_name) as full_name,
                         rl.name as role_name
                       FROM new_coach_report_recipients r
                       JOIN personnel p ON r.personnel_id = p.id
                       JOIN roles rl ON p.role_id = rl.id
                       WHERE r.coach_report_id = ?
                       ORDER BY p.first_name, p.last_name");
    $stmt->execute([$reportId]);
    $recipients = $stmt->fetchAll();
    
    // Get responses
    $stmt = $pdo->prepare("SELECT r.*, 
                         CONCAT(p.first_name, ' ', p.last_name) as responder_name
                       FROM new_coach_report_responses r
                       JOIN personnel p ON r.personnel_id = p.id
                       WHERE r.coach_report_id = ?
                       ORDER BY r.created_at DESC");
    $stmt->execute([$reportId]);
    $responses = $stmt->fetchAll();
}

// Handle report response submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_response'])) {
    $reportId = clean($_POST['report_id']);
    $response = clean($_POST['response']);
    
    if (empty($response)) {
        $message = showError('لطفا پاسخ خود را وارد کنید.');
    } else {
        try {
            // Get personnel_id for the current user
            $personnel_id = findPersonnelId($pdo);
            
            if (!$personnel_id) {
                throw new Exception('کاربر جاری در جدول personnel پیدا نشد. لطفا با مدیر سیستم تماس بگیرید.');
            }
            
            $stmt = $pdo->prepare("INSERT INTO new_coach_report_responses (coach_report_id, personnel_id, response) VALUES (?, ?, ?)");
            $stmt->execute([$reportId, $personnel_id, $response]);
            
            $message = showSuccess('پاسخ شما با موفقیت ثبت شد.');
            
            // Refresh the page to show the new response
            redirect('new_coach_report_view.php?id=' . $reportId);
            
        } catch (PDOException $e) {
            $message = showError('خطا در ثبت پاسخ: ' . $e->getMessage());
        } catch (Exception $e) {
            $message = showError($e->getMessage());
        }
    }
}

include 'header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-md-10 ms-auto">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>جزئیات گزارش کوچ</h1>
                <div>
                    <a href="new_coach_report_list.php" class="btn btn-secondary">
                        <i class="fas fa-list"></i> لیست گزارش‌ها
                    </a>
                    <?php 
                    // Only show edit button for admins with proper permission or coaches who created the report
                    // Recipients should NOT see this button even if they have general permissions
                    $isCreator = isCoach() && $report['created_by'] == $_SESSION['user_id'];
                    $hasEditPerm = hasPermission('edit_coach_report');
                    
                    // Check if user is just a recipient and not the creator or admin
                    $isOnlyRecipient = false;
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE coach_report_id = ? AND personnel_id = ?");
                    $stmt->execute([$report['id'], $_SESSION['user_id']]);
                    $isRecipient = ($stmt->fetchColumn() > 0);
                    
                    $isOnlyRecipient = $isRecipient && !$isCreator && !isAdmin();
                    
                    // Only show edit button if user is NOT just a recipient
                    if (($hasEditPerm || $isCreator) && !$isOnlyRecipient): 
                    ?>
                        <a href="new_coach_report_edit.php?id=<?php echo $report['id']; ?>" class="btn btn-primary">
                            <i class="fas fa-edit"></i> ویرایش گزارش
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php echo $message; ?>
            
            <?php if ($report): ?>
                <!-- Basic Report Information -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">اطلاعات پایه گزارش</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">تاریخ گزارش:</label>
                                <div><?php echo format_date($report['report_date']); ?></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">شرکت:</label>
                                <div><?php echo $report['company_name']; ?></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">دوره گزارش:</label>
                                <div>از <?php echo format_date($report['date_from']); ?> تا <?php echo format_date($report['date_to']); ?></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="fw-bold">تهیه کننده گزارش:</label>
                                <div><?php echo $report['creator_name']; ?></div>
                            </div>
                            <div class="col-12 mb-3">
                                <label class="fw-bold">توضیحات کلی:</label>
                                <div class="card">
                                    <div class="card-body bg-light">
                                        <?php echo nl2br($report['description']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Evaluations -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">ارزیابی افراد</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($involvedPersonnel) > 0): ?>
                            <div class="accordion" id="evaluationsAccordion">
                                <?php foreach ($involvedPersonnel as $index => $person): ?>
                                    <?php 
                                    // Calculate score class and text
                                    $scoreClass = '';
                                    $scoreText = '';
                                    
                                    if ($person['coach_score'] >= 6) {
                                        $scoreClass = 'success';
                                        $scoreText = 'عالی';
                                    } elseif ($person['coach_score'] >= 5) {
                                        $scoreClass = 'primary';
                                        $scoreText = 'خوب';
                                    } elseif ($person['coach_score'] >= 4) {
                                        $scoreClass = 'info';
                                        $scoreText = 'متوسط';
                                    } elseif ($person['coach_score'] >= 3) {
                                        $scoreClass = 'warning';
                                        $scoreText = 'ضعیف';
                                    } else {
                                        $scoreClass = 'danger';
                                        $scoreText = 'بسیار ضعیف';
                                    }
                                    ?>
                                    
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="heading<?php echo $index; ?>">
                                            <button class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $index; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="collapse<?php echo $index; ?>">
                                                <span class="me-2"><?php echo $person['full_name']; ?></span>
                                                <span class="badge bg-<?php echo $scoreClass; ?> ms-auto">
                                                    نمره: <?php echo $person['coach_score']; ?> (<?php echo $scoreText; ?>)
                                                </span>
                                            </button>
                                        </h2>
                                        <div id="collapse<?php echo $index; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" aria-labelledby="heading<?php echo $index; ?>" data-bs-parent="#evaluationsAccordion">
                                            <div class="accordion-body">
                                                <div class="card">
                                                    <div class="card-body bg-light">
                                                        <?php echo nl2br($person['coach_notes']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center">هیچ ارزیابی ثبت نشده است.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Report Recipients -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">دریافت کنندگان گزارش</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($recipients) > 0): ?>
                            <div class="row">
                                <?php foreach ($recipients as $recipient): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="card">
                                            <div class="card-body py-2 px-3">
                                                <i class="fas fa-user me-2"></i> <?php echo $recipient['full_name']; ?>
                                                <div><small class="text-muted"><?php echo $recipient['role_name']; ?></small></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center">هیچ دریافت کننده‌ای ثبت نشده است.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Responses -->
                <div class="card mb-4">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">پاسخ‌ها و نظرات</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($responses) > 0): ?>
                            <div class="mb-4">
                                <?php foreach ($responses as $response): ?>
                                    <div class="card mb-3">
                                        <div class="card-header d-flex justify-content-between align-items-center bg-light">
                                            <div>
                                                <i class="fas fa-user me-2"></i> <?php echo $response['responder_name']; ?>
                                            </div>
                                            <small class="text-muted"><?php echo format_datetime($response['created_at']); ?></small>
                                        </div>
                                        <div class="card-body">
                                            <?php echo nl2br($response['response']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-center mb-4">هنوز هیچ پاسخی برای این گزارش ثبت نشده است.</p>
                        <?php endif; ?>
                        
                        <!-- Response Form -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">ثبت پاسخ جدید</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                    <div class="mb-3">
                                        <label for="response" class="form-label">پاسخ شما:</label>
                                        <textarea class="form-control" id="response" name="response" rows="3" required></textarea>
                                    </div>
                                    <button type="submit" name="submit_response" class="btn btn-primary">ثبت پاسخ</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php 
// Helper function to format date
function format_date($date) {
    return date('Y/m/d', strtotime($date));
}

// Helper function to format datetime
function format_datetime($datetime) {
    return date('Y/m/d H:i', strtotime($datetime));
}

include 'footer.php'; 
?>