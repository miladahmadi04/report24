<?php
// new_coach_report_edit.php - Edit coach report
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

// Check access permissions - Only admins with edit permission or coaches who created the report should edit
// Recipients should NOT be able to edit reports
if (!hasPermission('edit_coach_report') && !isCoach()) {
    redirect('index.php');
}

$message = '';
$reportId = isset($_GET['id']) && is_numeric($_GET['id']) ? clean($_GET['id']) : null;

if (!$reportId) {
    redirect('new_coach_report_list.php');
}

// Check if coach is the creator of the report
if (isCoach() && !isAdmin()) {
    $stmt = $pdo->prepare("SELECT created_by FROM new_coach_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $createdBy = $stmt->fetchColumn();
    
    if ($createdBy != $_SESSION['user_id']) {
        redirect('new_coach_report_list.php');
    }
}

// Check if user is just a recipient and not the creator or admin
$isRecipientOnly = false;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM new_coach_report_recipients WHERE coach_report_id = ? AND personnel_id = ?");
$stmt->execute([$reportId, $_SESSION['user_id']]);
$isRecipient = ($stmt->fetchColumn() > 0);

if ($isRecipient && !isAdmin() && !isCoach()) {
    // If user is just a recipient, redirect to view page
    redirect('new_coach_report_view.php?id=' . $reportId);
}

// Get report data
$report = null;
$companies = [];
$personnel = [];
$selectedRecipients = [];
$selectedPersonnel = [];
$evaluations = [];

// Get all active companies
$stmt = $pdo->query("SELECT id, name FROM companies WHERE is_active = 1 ORDER BY name");
$companies = $stmt->fetchAll();

// Get report data
$stmt = $pdo->prepare("SELECT * FROM new_coach_reports WHERE id = ?");
$stmt->execute([$reportId]);
$report = $stmt->fetch();

if (!$report) {
    redirect('new_coach_report_list.php');
}

// Get personnel for the company
$stmt = $pdo->prepare("SELECT p.id, CONCAT(p.first_name, ' ', p.last_name) as full_name, r.name as role_name 
                     FROM personnel p 
                     JOIN roles r ON p.role_id = r.id 
                     WHERE p.is_active = 1 AND 
                     (p.company_id = ? OR 
                      p.id IN (SELECT personnel_id FROM personnel_companies WHERE company_id = ?)) 
                     ORDER BY p.first_name, p.last_name");
$stmt->execute([$report['company_id'], $report['company_id']]);
$personnel = $stmt->fetchAll();

// Get selected recipients
$stmt = $pdo->prepare("SELECT personnel_id FROM new_coach_report_recipients WHERE coach_report_id = ?");
$stmt->execute([$reportId]);
$selectedRecipients = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get selected personnel and their evaluations
$stmt = $pdo->prepare("SELECT rp.*, CONCAT(p.first_name, ' ', p.last_name) as full_name
                     FROM new_coach_report_personnel rp
                     JOIN personnel p ON rp.personnel_id = p.id
                     WHERE rp.coach_report_id = ?");
$stmt->execute([$reportId]);
$evaluations = $stmt->fetchAll();

// Extract personnel IDs
$selectedPersonnel = array_map(function ($evaluation) {
    return $evaluation['personnel_id'];
}, $evaluations);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    // Clean and validate inputs
    $report_date = clean($_POST['report_date']);
    $date_from = clean($_POST['date_from']);
    $date_to = clean($_POST['date_to']);
    $description = clean($_POST['description']);
    $recipients = isset($_POST['recipients']) ? $_POST['recipients'] : [];
    $involved_personnel = isset($_POST['involved_personnel']) ? $_POST['involved_personnel'] : [];
    
    // Validate required fields
    if (empty($report_date) || empty($date_from) || empty($date_to) || empty($description)) {
        $message = showError('لطفا تمام فیلدهای ضروری را پر کنید.');
    } elseif (empty($recipients)) {
        $message = showError('لطفا حداقل یک دریافت کننده برای گزارش انتخاب کنید.');
    } elseif (empty($involved_personnel)) {
        $message = showError('لطفا حداقل یک نفر را به عنوان فرد دخیل در گزارش انتخاب کنید.');
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update coach report
            $stmt = $pdo->prepare("UPDATE new_coach_reports SET 
                                 report_date = ?, 
                                 date_from = ?, 
                                 date_to = ?, 
                                 description = ? 
                                 WHERE id = ?");
            $stmt->execute([$report_date, $date_from, $date_to, $description, $reportId]);
            
            // Delete existing recipients
            $stmt = $pdo->prepare("DELETE FROM new_coach_report_recipients WHERE coach_report_id = ?");
            $stmt->execute([$reportId]);
            
            // Insert new recipients
            $recipientStmt = $pdo->prepare("INSERT INTO new_coach_report_recipients (coach_report_id, personnel_id) VALUES (?, ?)");
            foreach ($recipients as $recipient) {
                $recipientStmt->execute([$reportId, $recipient]);
            }
            
            // Get existing personnel
            $stmt = $pdo->prepare("SELECT personnel_id FROM new_coach_report_personnel WHERE coach_report_id = ?");
            $stmt->execute([$reportId]);
            $existingPersonnel = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Personnel to remove
            $personnelToRemove = array_diff($existingPersonnel, $involved_personnel);
            if (!empty($personnelToRemove)) {
                $placeholders = implode(',', array_fill(0, count($personnelToRemove), '?'));
                $stmt = $pdo->prepare("DELETE FROM new_coach_report_personnel 
                                     WHERE coach_report_id = ? AND personnel_id IN ($placeholders)");
                $params = array_merge([$reportId], $personnelToRemove);
                $stmt->execute($params);
            }
            
            // Update/Insert personnel evaluations
            foreach ($involved_personnel as $personId) {
                $scoreKey = 'score_' . $personId;
                $notesKey = 'notes_' . $personId;
                
                $score = isset($_POST[$scoreKey]) ? clean($_POST[$scoreKey]) : null;
                $notes = isset($_POST[$notesKey]) ? clean($_POST[$notesKey]) : '';
                
                if (empty($score) || $score < 1 || $score > 7) {
                    throw new Exception('لطفا نمره معتبر بین 1 تا 7 وارد کنید.');
                }
                
                // Check if person already exists
                if (in_array($personId, $existingPersonnel)) {
                    // Update
                    $stmt = $pdo->prepare("UPDATE new_coach_report_personnel 
                                         SET coach_score = ?, coach_notes = ? 
                                         WHERE coach_report_id = ? AND personnel_id = ?");
                    $stmt->execute([$score, $notes, $reportId, $personId]);
                } else {
                    // Insert
                    $stmt = $pdo->prepare("INSERT INTO new_coach_report_personnel 
                                         (coach_report_id, personnel_id, coach_score, coach_notes) 
                                         VALUES (?, ?, ?, ?)");
                    $stmt->execute([$reportId, $personId, $score, $notes]);
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Success message and redirect
            $_SESSION['success_message'] = 'گزارش با موفقیت به‌روزرسانی شد.';
            redirect('new_coach_report_view.php?id=' . $reportId);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = showError('خطا در به‌روزرسانی گزارش: ' . $e->getMessage());
        }
    }
}

include 'header.php';
?>

<div class="container">
    <div class="row">
        <div class="col-md-10 ms-auto">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>ویرایش گزارش کوچ</h1>
                <div>
                    <a href="new_coach_report_list.php" class="btn btn-secondary me-2">
                        <i class="fas fa-list"></i> لیست گزارش‌ها
                    </a>
                    <a href="new_coach_report_view.php?id=<?php echo $reportId; ?>" class="btn btn-info">
                        <i class="fas fa-eye"></i> مشاهده گزارش
                    </a>
                </div>
            </div>
            
            <?php echo $message; ?>
            
            <form method="POST" action="" id="coachReportForm">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">اطلاعات پایه گزارش</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <label for="report_date" class="col-md-3 col-form-label">تاریخ گزارش:</label>
                            <div class="col-md-9">
                                <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo $report['report_date']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label">شرکت:</label>
                            <div class="col-md-9">
                                <?php 
                                $companyName = '';
                                foreach ($companies as $company) {
                                    if ($company['id'] == $report['company_id']) {
                                        $companyName = $company['name'];
                                        break;
                                    }
                                }
                                ?>
                                <div class="form-control-plaintext">
                                    <strong><?php echo $companyName; ?></strong>
                                    <div class="form-text text-muted">شرکت گزارش قابل تغییر نیست.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <label for="date_from" class="col-md-3 col-form-label">از تاریخ:</label>
                            <div class="col-md-9">
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $report['date_from']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <label for="date_to" class="col-md-3 col-form-label">تا تاریخ:</label>
                            <div class="col-md-9">
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $report['date_to']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <label for="description" class="col-md-3 col-form-label">توضیحات کلی:</label>
                            <div class="col-md-9">
                                <textarea class="form-control" id="description" name="description" rows="5" required><?php echo $report['description']; ?></textarea>
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
                                        <input class="form-check-input" type="checkbox" name="recipients[]" 
                                               id="recipient_<?php echo $person['id']; ?>" 
                                               value="<?php echo $person['id']; ?>"
                                               <?php echo in_array($person['id'], $selectedRecipients) ? 'checked' : ''; ?>>
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
                                            data-person-name="<?php echo $person['full_name']; ?>"
                                            <?php echo in_array($person['id'], $selectedPersonnel) ? 'checked' : ''; ?>>
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
                
                <!-- Personnel Evaluation Sections -->
                <div id="evaluation-sections">
                    <?php foreach ($evaluations as $evaluation): ?>
                        <div class="card mb-4 evaluation-card" id="evaluation_section_<?php echo $evaluation['personnel_id']; ?>">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">ارزیابی: <?php echo $evaluation['full_name']; ?></h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <label for="score_<?php echo $evaluation['personnel_id']; ?>" class="col-md-3 col-form-label">نمره (1 تا 7):</label>
                                    <div class="col-md-9">
                                        <select class="form-select" id="score_<?php echo $evaluation['personnel_id']; ?>" name="score_<?php echo $evaluation['personnel_id']; ?>" required>
                                            <option value="">انتخاب کنید...</option>
                                            <option value="1" <?php echo $evaluation['coach_score'] == 1 ? 'selected' : ''; ?>>1 - بسیار ضعیف</option>
                                            <option value="2" <?php echo $evaluation['coach_score'] == 2 ? 'selected' : ''; ?>>2 - ضعیف</option>
                                            <option value="3" <?php echo $evaluation['coach_score'] == 3 ? 'selected' : ''; ?>>3 - زیر متوسط</option>
                                            <option value="4" <?php echo $evaluation['coach_score'] == 4 ? 'selected' : ''; ?>>4 - متوسط</option>
                                            <option value="5" <?php echo $evaluation['coach_score'] == 5 ? 'selected' : ''; ?>>5 - خوب</option>
                                            <option value="6" <?php echo $evaluation['coach_score'] == 6 ? 'selected' : ''; ?>>6 - بسیار خوب</option>
                                            <option value="7" <?php echo $evaluation['coach_score'] == 7 ? 'selected' : ''; ?>>7 - عالی</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <label for="notes_<?php echo $evaluation['personnel_id']; ?>" class="col-md-3 col-form-label">توضیحات:</label>
                                    <div class="col-md-9">
                                        <textarea class="form-control" id="notes_<?php echo $evaluation['personnel_id']; ?>" name="notes_<?php echo $evaluation['personnel_id']; ?>" rows="4" required><?php echo $evaluation['coach_notes']; ?></textarea>
                                        <div class="form-text">توضیحات و ارزیابی خود را درباره عملکرد این فرد بنویسید.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                    <a href="new_coach_report_view.php?id=<?php echo $reportId; ?>" class="btn btn-secondary">انصراف</a>
                    <button type="submit" name="submit_report" class="btn btn-primary">به‌روزرسانی گزارش</button>
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
                            // Check if section already exists
                            if (!document.getElementById(sectionId)) {
                                // Show evaluation section
                                const sectionHTML = template
                                    .replace(/{id}/g, personId)
                                    .replace(/{name}/g, personName);
                                    
                                evaluationSections.insertAdjacentHTML('beforeend', sectionHTML);
                            }
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
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>