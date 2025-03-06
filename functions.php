<?php
// functions.php - Helper functions

// Clean user input
function clean($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}





// Comment out redirect function since it's already defined in auth.php
// function redirect($page) {
//     header("Location: $page");
//     exit;
// }

// Display success message
function showSuccess($message) {
    return '<div class="alert alert-success">' . $message . '</div>';
}

// Display error message
function showError($message) {
    return '<div class="alert alert-danger">' . $message . '</div>';
}







// Check if company is active
function isCompanyActive($companyId, $pdo) {
    $stmt = $pdo->prepare("SELECT is_active FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    return $company && $company['is_active'] == 1;
}

// Check if personnel is active
function isPersonnelActive($personnelId, $pdo) {
    $stmt = $pdo->prepare("SELECT p.is_active, c.is_active as company_active 
                          FROM personnel p 
                          JOIN companies c ON p.company_id = c.id 
                          WHERE p.id = ?");
    $stmt->execute([$personnelId]);
    $personnel = $stmt->fetch();
    return $personnel && $personnel['is_active'] == 1 && $personnel['company_active'] == 1;
}

// Format number with thousands separator
function formatNumber($number) {
    return number_format($number, 0, '.', ',');
}

// Format date
function formatDate($date, $format = 'Y/m/d') {
    if (!$date) return '';
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

// Get all active social networks
function getAllSocialNetworks($pdo) {
    $stmt = $pdo->query("SELECT * FROM social_networks ORDER BY name");
    return $stmt->fetchAll();
}

// Get social network by ID
function getSocialNetwork($socialNetworkId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM social_networks WHERE id = ?");
    $stmt->execute([$socialNetworkId]);
    return $stmt->fetch();
}

// Get fields for a social network
function getSocialNetworkFields($socialNetworkId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM social_network_fields 
                          WHERE social_network_id = ? 
                          ORDER BY sort_order, id");
    $stmt->execute([$socialNetworkId]);
    return $stmt->fetchAll();
}

// Get social network field by ID
function getSocialNetworkField($fieldId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM social_network_fields WHERE id = ?");
    $stmt->execute([$fieldId]);
    return $stmt->fetch();
}

// Get social page by ID
function getSocialPage($pageId, $pdo) {
    $stmt = $pdo->prepare("SELECT p.*, s.name as network_name, s.icon as network_icon, c.name as company_name 
                          FROM social_pages p 
                          JOIN social_networks s ON p.social_network_id = s.id 
                          JOIN companies c ON p.company_id = c.id 
                          WHERE p.id = ?");
    $stmt->execute([$pageId]);
    return $stmt->fetch();
}

// Get field values for a social page
function getSocialPageFieldValues($pageId, $pdo) {
    $stmt = $pdo->prepare("SELECT spf.*, snf.field_label, snf.field_type, snf.is_kpi 
                          FROM social_page_fields spf 
                          JOIN social_network_fields snf ON spf.field_id = snf.id 
                          WHERE spf.page_id = ? 
                          ORDER BY snf.sort_order, snf.id");
    $stmt->execute([$pageId]);
    return $stmt->fetchAll();
}

// Get KPIs for a social page
function getPageKPIs($pageId, $pdo) {
    $stmt = $pdo->prepare("SELECT pk.*, snf.field_label, km.name as model_name, km.model_type, 
                          related.field_label as related_field_label 
                          FROM page_kpis pk 
                          JOIN social_network_fields snf ON pk.field_id = snf.id 
                          JOIN kpi_models km ON pk.kpi_model_id = km.id 
                          LEFT JOIN social_network_fields related ON pk.related_field_id = related.id 
                          WHERE pk.page_id = ? 
                          ORDER BY snf.sort_order, snf.id");
    $stmt->execute([$pageId]);
    return $stmt->fetchAll();
}

// Get all KPI models
function getAllKPIModels($pdo) {
    $stmt = $pdo->query("SELECT * FROM kpi_models ORDER BY id");
    return $stmt->fetchAll();
}

// Get KPI model by ID
function getKPIModel($modelId, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM kpi_models WHERE id = ?");
    $stmt->execute([$modelId]);
    return $stmt->fetch();
}

// Calculate expected values based on KPIs
// اصلاح تابع محاسبه مقادیر مورد انتظار برای KPIهای درصدی
function calculateExpectedValues($pageId, $reportDate, $pdo) {
    // Get page details
    $page = getSocialPage($pageId, $pdo);
    if (!$page) return null;
    
    // Get page start date
    $startDate = new DateTime($page['start_date']);
    $currentDate = new DateTime($reportDate);
    $daysDiff = $startDate->diff($currentDate)->days;
    
    // Get page KPIs
    $kpis = getPageKPIs($pageId, $pdo);
    
    // Get initial field values
    $fieldValues = getSocialPageFieldValues($pageId, $pdo);
    
    // Prepare results array
    $expectedValues = [];
    
    // مرحله اول: محاسبه مقادیر مورد انتظار برای فیلدهای اصلی (growth_over_time)
    foreach ($kpis as $kpi) {
        if ($kpi['model_type'] == 'growth_over_time') {
            $fieldId = $kpi['field_id'];
            $fieldLabel = $kpi['field_label'];
            
            // Find initial field value
            $initialValue = 0;
            foreach ($fieldValues as $value) {
                if ($value['field_id'] == $fieldId) {
                    $initialValue = floatval($value['field_value']);
                    break;
                }
            }
            
            // Growth over time model
            if ($kpi['growth_period_days'] > 0) {
                $periods = $daysDiff / $kpi['growth_period_days'];
                $growthValue = floatval($kpi['growth_value']);
                
                // Check if growth is percentage (less than 100) or absolute value
                if ($growthValue < 100) {
                    // Percentage growth
                    $expectedValue = $initialValue * pow(1 + ($growthValue / 100), $periods);
                } else {
                    // Absolute growth
                    $expectedValue = $initialValue + ($growthValue * $periods);
                }
                
                $expectedValues[$fieldId] = [
                    'field_id' => $fieldId,
                    'field_label' => $fieldLabel,
                    'expected_value' => round($expectedValue, 2)
                ];
            }
        }
    }
    
    // مرحله دوم: محاسبه مقادیر مورد انتظار برای فیلدهای وابسته (percentage_of_field)
    foreach ($kpis as $kpi) {
        if ($kpi['model_type'] == 'percentage_of_field') {
            $fieldId = $kpi['field_id'];
            $fieldLabel = $kpi['field_label'];
            $relatedFieldId = $kpi['related_field_id'];
            $percentageValue = floatval($kpi['percentage_value']);
            
            // مهم: ابتدا باید مقدار فیلد مرجع رو محاسبه کنیم
            // این مقدار میتونه یا از مقادیر انتظاری باشه (اگر فیلد مرجع هم KPI داره)
            // یا از مقادیر اولیه (اگر فیلد مرجع KPI نداره)
            
            $relatedExpectedValue = 0;
            
            // اولویت با مقادیر انتظاری از قبل محاسبه شده است
            if (isset($expectedValues[$relatedFieldId])) {
                $relatedExpectedValue = $expectedValues[$relatedFieldId]['expected_value'];
            } else {
                // اگر مقدار انتظاری از قبل محاسبه نشده، از مقدار اولیه استفاده میکنیم
                foreach ($fieldValues as $value) {
                    if ($value['field_id'] == $relatedFieldId) {
                        $relatedExpectedValue = floatval($value['field_value']);
                        break;
                    }
                }
            }
            
            // محاسبه مقدار انتظاری بر اساس درصد از فیلد مرجع
            $expectedValue = $relatedExpectedValue * ($percentageValue / 100);
            
            $expectedValues[$fieldId] = [
                'field_id' => $fieldId,
                'field_label' => $fieldLabel,
                'expected_value' => round($expectedValue, 2)
            ];
        }
    }
    
    return $expectedValues;
}

// Calculate performance score (1-7 scale)
function calculatePerformanceScore($actualValue, $expectedValue) {
    if ($expectedValue <= 0) return 0;
    
    $achievementPercentage = ($actualValue / $expectedValue) * 100;
    $score = min(7, ($achievementPercentage / 100) * 7);
    
    return round($score, 1);
}

// Get performance score color class
function getScoreColorClass($score) {
    if ($score >= 6) return 'success';
    if ($score >= 4) return 'warning';
    return 'danger';
}

// Calculate report performance
function calculateReportPerformance($reportId, $pdo) {
    // Get report details
    $stmt = $pdo->prepare("SELECT * FROM monthly_reports WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();
    if (!$report) return null;
    
    // Get page ID and report date
    $pageId = $report['page_id'];
    $reportDate = $report['report_date'];
    
    // Calculate expected values
    $expectedValues = calculateExpectedValues($pageId, $reportDate, $pdo);
    if (!$expectedValues) return null;
    
    // Get actual field values from report
    $stmt = $pdo->prepare("SELECT mrv.*, snf.field_label, snf.is_kpi 
                          FROM monthly_report_values mrv 
                          JOIN social_network_fields snf ON mrv.field_id = snf.id 
                          WHERE mrv.report_id = ?");
    $stmt->execute([$reportId]);
    $actualValues = $stmt->fetchAll();
    
    // Calculate scores for each field
    $scores = [];
    $totalScore = 0;
    $kpiCount = 0;
    
    foreach ($actualValues as $value) {
        $fieldId = $value['field_id'];
        $fieldLabel = $value['field_label'];
        $actualValue = floatval($value['field_value']);
        
        // Only calculate scores for KPI fields
        if ($value['is_kpi'] && isset($expectedValues[$fieldId])) {
            $expectedValue = $expectedValues[$fieldId]['expected_value'];
            $score = calculatePerformanceScore($actualValue, $expectedValue);
            
            $scores[$fieldId] = [
                'field_id' => $fieldId,
                'field_label' => $fieldLabel,
                'actual_value' => $actualValue,
                'expected_value' => $expectedValue,
                'score' => $score
            ];
            
            $totalScore += $score;
            $kpiCount++;
            
            // Store score in database
            $stmt = $pdo->prepare("INSERT INTO report_scores 
                                  (report_id, field_id, expected_value, actual_value, score) 
                                  VALUES (?, ?, ?, ?, ?)
                                  ON DUPLICATE KEY UPDATE 
                                  expected_value = VALUES(expected_value),
                                  actual_value = VALUES(actual_value),
                                  score = VALUES(score)");
            $stmt->execute([$reportId, $fieldId, $expectedValue, $actualValue, $score]);
        }
    }
    
    // Calculate overall score
    $overallScore = $kpiCount > 0 ? round($totalScore / $kpiCount, 1) : 0;
    
    return [
        'scores' => $scores,
        'overall_score' => $overallScore
    ];
}

// Check user access to social page
function canAccessSocialPage($pageId, $pdo) {
    if (isAdmin()) {
        return true;
    }
    
    $companyId = $_SESSION['company_id'];
    
    $stmt = $pdo->prepare("SELECT company_id FROM social_pages WHERE id = ?");
    $stmt->execute([$pageId]);
    $page = $stmt->fetch();
    
    return $page && $page['company_id'] == $companyId;
}



// Get all permissions for a role
function getRolePermissions($roleId, $pdo) {
    $stmt = $pdo->prepare("SELECT p.* 
                          FROM permissions p
                          JOIN role_permissions rp ON p.id = rp.permission_id
                          WHERE rp.role_id = ?
                          ORDER BY p.name");
    $stmt->execute([$roleId]);
    return $stmt->fetchAll();
}

// Get creator info for a report
function getReportCreator($creatorId, $pdo) {
    if (!$creatorId) {
        return 'نامشخص';
    }
    
    // Try to find in admin users
    $stmt = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
    $stmt->execute([$creatorId]);
    $admin = $stmt->fetch();
    
    if ($admin) {
        return $admin['username'] . ' (مدیر)';
    }
    
    // Try to find in personnel
    $stmt = $pdo->prepare("SELECT full_name FROM personnel WHERE id = ?");
    $stmt->execute([$creatorId]);
    $personnel = $stmt->fetch();
    
    if ($personnel) {
        return $personnel['full_name'];
    }
    
    return 'نامشخص';
}

// Get performance level name and class
function getPerformanceLevel($score) {
    if ($score >= 6) {
        return ['level' => 'عالی', 'class' => 'success'];
    } elseif ($score >= 5) {
        return ['level' => 'خوب', 'class' => 'primary'];
    } elseif ($score >= 3.5) {
        return ['level' => 'متوسط', 'class' => 'warning'];
    } else {
        return ['level' => 'ضعیف', 'class' => 'danger'];
    }
}

// Format date to Jalali
function toJalali($date) {
    // Add your Jalali date conversion logic here
    return $date; // Temporary return as-is
}

// Get user's full name with prefix
function getFullNameWithPrefix($userId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT CONCAT(COALESCE(prefix, ''), ' ', full_name) as full_title 
                          FROM personnel WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn();
}

// Check if user has access to coach reports
function hasCoachReportAccess($companyId, $personnelId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT can_view FROM coach_report_access 
                          WHERE company_id = ? AND personnel_id = ?");
    $stmt->execute([$companyId, $personnelId]);
    return $stmt->fetch()['can_view'] ?? false;
}

// Get all active personnel for a company
function getActivePersonnel($companyId) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT p.*, CONCAT(COALESCE(p.prefix, ''), ' ', p.full_name) as full_title 
                          FROM personnel p 
                          WHERE p.company_id = ? AND p.is_active = 1 
                          ORDER BY p.full_name");
    $stmt->execute([$companyId]);
    return $stmt->fetchAll();
}

// Get social reports for a date range
function getSocialReports($companyId, $dateFrom, $dateTo) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT mr.*, sp.page_name 
                          FROM monthly_reports mr 
                          JOIN social_pages sp ON mr.page_id = sp.id 
                          WHERE sp.company_id = ? AND mr.report_date BETWEEN ? AND ?");
    $stmt->execute([$companyId, $dateFrom, $dateTo]);
    return $stmt->fetchAll();
}

// Get report categories for a personnel
function getReportCategories($personnelId, $dateFrom, $dateTo) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT DISTINCT c.name 
                          FROM categories c 
                          JOIN report_item_categories ric ON c.id = ric.category_id 
                          JOIN report_items ri ON ric.item_id = ri.id 
                          JOIN reports r ON ri.report_id = r.id 
                          WHERE r.personnel_id = ? AND r.report_date BETWEEN ? AND ?");
    $stmt->execute([$personnelId, $dateFrom, $dateTo]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get top categories for a personnel
function getTopCategories($personnelId, $dateFrom, $dateTo, $limit = 5) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT c.name, COUNT(*) as count 
                          FROM categories c 
                          JOIN report_item_categories ric ON c.id = ric.category_id 
                          JOIN report_items ri ON ric.item_id = ri.id 
                          JOIN reports r ON ri.report_id = r.id 
                          WHERE r.personnel_id = ? AND r.report_date BETWEEN ? AND ? 
                          GROUP BY c.id 
                          ORDER BY count DESC 
                          LIMIT ?");
    $stmt->execute([$personnelId, $dateFrom, $dateTo, $limit]);
    return $stmt->fetchAll();
}