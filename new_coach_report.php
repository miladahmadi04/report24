<?php
// new_coach_report.php - Form for creating coach reports
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check access permissions
if (!isAdmin() && !isCoach()) {
    redirect('index.php');
}

$message = '';
$companies = [];
$personnel = [];
$report_date = date('Y-m-d');
$date_from = date('Y-m-d', strtotime('-7 days'));
$date_to = date('Y-m-d');
$selectedCompany = null;

// DEBUG SECTION - Uncomment to troubleshoot issues
/*
echo "<pre>";
echo "SESSION Variables:\n";
print_r($_SESSION);
echo "\n\nUSER ID: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set') . "\n";
echo "USER TYPE: " . (isset($_SESSION['user_type']) ? $_SESSION['user_type'] : 'not set') . "\n";
echo "IS ADMIN: " . (isAdmin() ? 'Yes' : 'No') . "\n";

// Check if the user exists in personnel table
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $personnel_data = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Personnel record for user_id:\n";
    print_r($personnel_data);
    
    // If not found directly, try through user_id
    if (!$personnel_data) {
        $stmt = $pdo->prepare("SELECT * FROM personnel WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $personnel_data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Personnel record through user_id:\n";
        print_r($personnel_data);
    }
}

echo "</pre>";
*/
// END DEBUG SECTION

// Get all active companies
$stmt = $pdo->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name");
$companies = $stmt->fetchAll();

// If a company is selected, get its personnel
if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
    $selectedCompany = $_GET['company_id'];
    
    // Get company personnel
    $stmt = $pdo->prepare("SELECT p.id, CONCAT(p.first_name, ' ', p.last_name) as full_name, r.name as role_name 
                         FROM personnel p 
                         JOIN roles r ON p.role_id = r.id 
                         WHERE p.is_active = 1 AND 
                         (p.company_id = ? OR 
                          p.id IN (SELECT personnel_id FROM personnel_companies WHERE company_id = ?)) 
                         ORDER BY p.first_name, p.last_name");
    $stmt->execute([$selectedCompany, $selectedCompany]);
    $personnel = $stmt->fetchAll();
}

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    // Clean and validate inputs
    $report_date = clean($_POST['report_date']);
    $company_id = clean($_POST['company_id']);
    $date_from = clean($_POST['date_from']);
    $date_to = clean($_POST['date_to']);
    $description = clean($_POST['description']);
    $recipients = isset($_POST['recipients']) ? $_POST['recipients'] : [];
    $involved_personnel = isset($_POST['involved_personnel']) ? $_POST['involved_personnel'] : [];
    
    // Validate required fields
    if (empty($report_date) || empty($company_id) || empty($date_from) || empty($date_to) || empty($description)) {
        $message = showError('لطفا تمام فیلدهای ضروری را پر کنید.');
    } elseif (empty($recipients)) {
        $message = showError('لطفا حداقل یک دریافت کننده برای گزارش انتخاب کنید.');
    } elseif (empty($involved_personnel)) {
        $message = showError('لطفا حداقل یک نفر را به عنوان فرد دخیل در گزارش انتخاب کنید.');
    } else {
        $transaction_started = false;
        
        try {
            // Get personnel_id for the current user
            $personnel_id = findPersonnelId($pdo);
            
            if (!$personnel_id) {
                throw new Exception('کاربر جاری در جدول personnel پیدا نشد. لطفا با مدیر سیستم تماس بگیرید.');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            $transaction_started = true;
            
            // Insert coach report
            $stmt = $pdo->prepare("INSERT INTO new_coach_reports (report_date, company_id, date_from, date_to, description, created_by) 
                                 VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$report_date, $company_id, $date_from, $date_to, $description, $personnel_id]);
            $reportId = $pdo->lastInsertId();
            
            // Insert recipients
            $recipientStmt = $pdo->prepare("INSERT INTO new_coach_report_recipients (coach_report_id, personnel_id) VALUES (?, ?)");
            foreach ($recipients as $recipient) {
                $recipientStmt->execute([$reportId, $recipient]);
            }
            
            // Insert involved personnel with scores and notes
            $involvedStmt = $pdo->prepare("INSERT INTO new_coach_report_personnel (coach_report_id, personnel_id, coach_score, coach_notes) 
                                         VALUES (?, ?, ?, ?)");
            
            foreach ($involved_personnel as $personId) {
                $scoreKey = 'score_' . $personId;
                $notesKey = 'notes_' . $personId;
                
                $score = isset($_POST[$scoreKey]) ? clean($_POST[$scoreKey]) : null;
                $notes = isset($_POST[$notesKey]) ? clean($_POST[$notesKey]) : '';
                
                if (empty($score) || $score < 1 || $score > 7) {
                    throw new Exception('لطفا نمره معتبر بین 1 تا 7 وارد کنید.');
                }
                
                $involvedStmt->execute([$reportId, $personId, $score, $notes]);
            }
            
            // Commit transaction
            $pdo->commit();
            $transaction_started = false;
            
            // Redirect to view page
            redirect('new_coach_report_view.php?id=' . $reportId);
            
        } catch (Exception $e) {
            // Only rollback if transaction was started
            if ($transaction_started) {
                $pdo->rollBack();
            }
            $message = showError('خطا در ثبت گزارش: ' . $e->getMessage());
        }
    }
    
    // If there was an error, keep the selected company to repopulate the form
    $selectedCompany = $company_id;
}

include 'header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-md-10 ms-auto">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>ثبت گزارش جدید کوچ</h1>
                <a href="new_coach_report_list.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> لیست گزارش‌های کوچ
                </a>
            </div>
            
            <?php echo $message; ?>
            
            <!-- Step 1: Select Company -->
            <?php if (empty($selectedCompany)): ?>
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">انتخاب شرکت</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-3">لطفا شرکتی که میخواهید برای آن گزارش کوچ ثبت کنید را انتخاب نمایید:</p>
                        
                        <div class="row">
                            <?php foreach ($companies as $company): ?>
                                <div class="col-md-4 mb-3">
                                    <a href="?company_id=<?php echo $company['id']; ?>" class="card text-decoration-none">
                                        <div class="card-body text-center">
                                            <i class="fas fa-building fa-3x mb-2 text-primary"></i>
                                            <h5><?php echo $company['name']; ?></h5>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            
            <!-- Step 2: Fill Report Form -->
            <?php else: ?>
                <form method="POST" action="" id="coachReportForm">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">اطلاعات پایه گزارش</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <label for="report_date" class="col-md-3 col-form-label">تاریخ گزارش:</label>
                                <div class="col-md-9">
                                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo $report_date; ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label class="col-md-3 col-form-label">شرکت:</label>
                                <div class="col-md-9">
                                    <?php 
                                    $companyName = '';
                                    foreach ($companies as $company) {
                                        if ($company['id'] == $selectedCompany) {
                                            $companyName = $company['name'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="form-control-plaintext">
                                        <strong><?php echo $companyName; ?></strong>
                                        <a href="new_coach_report.php" class="btn btn-sm btn-outline-secondary ms-2">تغییر شرکت</a>
                                    </div>
                                    <input type="hidden" name="company_id" value="<?php echo $selectedCompany; ?>">
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="date_from" class="col-md-3 col-form-label">از تاریخ:</label>
                                <div class="col-md-9">
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="date_to" class="col-md-3 col-form-label">تا تاریخ:</label>
                                <div class="col-md-9">
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>" required>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="description" class="col-md-3 col-form-label">توضیحات کلی:</label>
                                <div class="col-md-9">
                                    <textarea class="form-control" id="description" name="description" rows="5" required></textarea>
                                    <div class="form-text">توضیحات کلی خود را درباره این دوره از کوچینگ وارد کنید.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recipients Selection -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0">دریافت کنندگان گزارش</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">افرادی که این گزارش برای آنها قابل مشاهده خواهد بود را انتخاب کنید:</p>
                            
                            <div class="row">
                                <?php foreach ($personnel as $person): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="recipients[]" id="recipient_<?php echo $person['id']; ?>" value="<?php echo $person['id']; ?>">
                                            <label class="form-check-label" for="recipient_<?php echo $person['id']; ?>">
                                                <?php echo $person['full_name']; ?> 
                                                <small class="text-muted">(<?php echo $person['role_name']; ?>)</small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personnel Involved Selection -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">افراد دخیل در گزارش</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">افرادی که در این دوره کوچینگ مورد ارزیابی قرار گرفته‌اند را انتخاب کنید:</p>
                            
                            <div class="row">
                                <?php foreach ($personnel as $person): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input personnel-checkbox" type="checkbox" 
                                                name="involved_personnel[]" 
                                                id="involved_<?php echo $person['id']; ?>" 
                                                value="<?php echo $person['id']; ?>"
                                                data-person-id="<?php echo $person['id']; ?>"
                                                data-person-name="<?php echo $person['full_name']; ?>">
                                            <label class="form-check-label" for="involved_<?php echo $person['id']; ?>">
                                                <?php echo $person['full_name']; ?> 
                                                <small class="text-muted">(<?php echo $person['role_name']; ?>)</small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personnel Evaluation Sections (will be dynamically shown/hidden) -->
                    <div id="evaluation-sections"></div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                        <a href="new_coach_report.php" class="btn btn-secondary">انصراف</a>
                        <button type="submit" name="submit_report" class="btn btn-primary">ثبت گزارش</button>
                    </div>
                </form>
                
                <!-- Template for personnel evaluation section -->
                <template id="evaluation-template">
                    <div class="card mb-4 evaluation-card" id="evaluation_section_{id}">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">ارزیابی: {name}</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <label for="score_{id}" class="col-md-3 col-form-label">نمره (1 تا 7):</label>
                                <div class="col-md-9">
                                    <select class="form-select" id="score_{id}" name="score_{id}" required>
                                        <option value="">انتخاب کنید...</option>
                                        <option value="1">1 - بسیار ضعیف</option>
                                        <option value="2">2 - ضعیف</option>
                                        <option value="3">3 - زیر متوسط</option>
                                        <option value="4">4 - متوسط</option>
                                        <option value="5">5 - خوب</option>
                                        <option value="6">6 - بسیار خوب</option>
                                        <option value="7">7 - عالی</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <label for="notes_{id}" class="col-md-3 col-form-label">توضیحات:</label>
                                <div class="col-md-9">
                                    <textarea class="form-control" id="notes_{id}" name="notes_{id}" rows="4" required></textarea>
                                    <div class="form-text">توضیحات و ارزیابی خود را درباره عملکرد این فرد بنویسید.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
                
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const checkboxes = document.querySelectorAll('.personnel-checkbox');
                        const evaluationSections = document.getElementById('evaluation-sections');
                        const template = document.getElementById('evaluation-template').innerHTML;
                        
                        // Function to toggle evaluation section
                        function toggleEvaluationSection(checkbox) {
                            const personId = checkbox.dataset.personId;
                            const personName = checkbox.dataset.personName;
                            const sectionId = 'evaluation_section_' + personId;
                            
                            if (checkbox.checked) {
                                // Show evaluation section
                                const sectionHTML = template
                                    .replace(/{id}/g, personId)
                                    .replace(/{name}/g, personName);
                                    
                                evaluationSections.insertAdjacentHTML('beforeend', sectionHTML);
                            } else {
                                // Remove evaluation section
                                const section = document.getElementById(sectionId);
                                if (section) {
                                    section.remove();
                                }
                            }
                        }
                        
                        // Add change event to all checkboxes
                        checkboxes.forEach(function(checkbox) {
                            checkbox.addEventListener('change', function() {
                                toggleEvaluationSection(this);
                            });
                        });
                        
                        // Form validation
                        document.getElementById('coachReportForm').addEventListener('submit', function(event) {
                            const recipients = document.querySelectorAll('input[name="recipients[]"]:checked');
                            const involvedPersonnel = document.querySelectorAll('input[name="involved_personnel[]"]:checked');
                            
                            if (recipients.length === 0) {
                                event.preventDefault();
                                alert('لطفا حداقل یک دریافت کننده برای گزارش انتخاب کنید.');
                            } else if (involvedPersonnel.length === 0) {
                                event.preventDefault();
                                alert('لطفا حداقل یک نفر را به عنوان فرد دخیل در گزارش انتخاب کنید.');
                            }
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>