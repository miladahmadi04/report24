<?php
// auth.php - Authentication and authorization functions
session_start();

// Check if the user is logged in
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
}

// Check if user has admin privileges
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin';
    }
}

// Check if user is a CEO
if (!function_exists('isCEO')) {
    function isCEO() {
        return isset($_SESSION['is_ceo']) && $_SESSION['is_ceo'] === true;
    }
}

// Check if user is regular personnel
if (!function_exists('isPersonnel')) {
    function isPersonnel() {
        return isLoggedIn() && !isAdmin() && !isCEO();
    }
}

// تابع بررسی دسترسی کوچ (میلاد احمدی)
if (!function_exists('isCoach')) {
    function isCoach() {
        global $pdo;
        
        // اگر کاربر مدیر سیستم است، همه دسترسی‌ها را دارد
        if (isAdmin()) {
            return true;
        }
        
        // بررسی نقش کاربر فعلی
        if (isset($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("SELECT r.name 
                                FROM personnel p 
                                JOIN roles r ON p.role_id = r.id 
                                WHERE p.id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $role = $stmt->fetchColumn();
            
            return ($role == 'کوچ');
        }
        
        return false;
    }
}

// Check if user is a CEO with Coach role
if (!function_exists('isCEOCoach')) {
    function isCEOCoach() {
        return isCEO() && isCoach();
    }
}

// Check if user has access to view a report
if (!function_exists('canViewReport')) {
    function canViewReport($reportId, $pdo) {
        // Admin can view all reports
        if (isAdmin()) {
            return true;
        }
        
        $userId = $_SESSION['user_id'];
        $companyId = $_SESSION['company_id'];
        
        // Get report details
        $stmt = $pdo->prepare("SELECT r.*, p.company_id 
                             FROM reports r 
                             JOIN personnel p ON r.personnel_id = p.id 
                             WHERE r.id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        
        if (!$report) {
            return false;
        }
        
        // The creator of the report can view it
        if ($report['personnel_id'] == $userId) {
            return true;
        }
        
        // Check if report belongs to the user's active company
        if ($report['company_id'] != $companyId) {
            return false;
        }
        
        // CEO with the "Coach" role can view reports from their company
        if (isCEO() && isCoach()) {
            return true;
        }
        
        // User with the "Coach" role can view reports from their company
        if (isCoach()) {
            return true;
        }
        
        return false;
    }
}

// Check if user has access to edit or delete a report
if (!function_exists('canModifyReport')) {
    function canModifyReport($reportId, $pdo) {
        // Admin can modify all reports
        if (isAdmin()) {
            return true;
        }
        
        $userId = $_SESSION['user_id'];
        $companyId = $_SESSION['company_id'];
        
        // Get report details
        $stmt = $pdo->prepare("SELECT r.*, p.company_id 
                             FROM reports r 
                             JOIN personnel p ON r.personnel_id = p.id 
                             WHERE r.id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        
        if (!$report) {
            return false;
        }
        
        // Check if report belongs to the user's active company
        if ($report['company_id'] != $companyId) {
            return false;
        }
        
        // CEO with the "Coach" role can modify reports from their company
        if (isCEO() && isCoach()) {
            return true;
        }
        
        // User with the "Coach" role can modify reports from their company
        if (isCoach()) {
            return true;
        }
        
        return false;
    }
}

// Check if user has access to add feedback to a report
if (!function_exists('canAddFeedback')) {
    function canAddFeedback($reportId, $pdo) {
        // Admin can add feedback to all reports
        if (isAdmin()) {
            return true;
        }
        
        $userId = $_SESSION['user_id'];
        $companyId = $_SESSION['company_id'];
        
        // Get report details
        $stmt = $pdo->prepare("SELECT r.*, p.company_id 
                             FROM reports r 
                             JOIN personnel p ON r.personnel_id = p.id 
                             WHERE r.id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();
        
        if (!$report) {
            return false;
        }
        
        // The creator of the report can add feedback
        if ($report['personnel_id'] == $userId) {
            return true;
        }
        
        // Check if report belongs to the user's active company
        if ($report['company_id'] != $companyId) {
            return false;
        }
        
        // CEO with the "Coach" role can add feedback to reports from their company
        if (isCEO() && isCoach()) {
            return true;
        }
        
        // User with the "Coach" role can add feedback to reports from their company
        if (isCoach()) {
            return true;
        }
        
        return false;
    }
}

// Check if user has access to modify feedback
if (!function_exists('canModifyFeedback')) {
    function canModifyFeedback($feedbackId, $pdo) {
        // Admin can modify all feedback
        if (isAdmin()) {
            return true;
        }
        
        $userId = $_SESSION['user_id'];
        $companyId = $_SESSION['company_id'];
        
        // Get feedback details
        $stmt = $pdo->prepare("SELECT f.*, r.personnel_id as report_owner_id, p.company_id 
                              FROM report_feedback f 
                              JOIN reports r ON f.report_id = r.id 
                              JOIN personnel p ON r.personnel_id = p.id 
                              WHERE f.id = ?");
        $stmt->execute([$feedbackId]);
        $feedback = $stmt->fetch();
        
        if (!$feedback) {
            return false;
        }
        
        // Check if feedback belongs to the user's active company
        if ($feedback['company_id'] != $companyId) {
            return false;
        }
        
        // CEO with the "Coach" role can modify feedback in their company
        if (isCEO() && isCoach()) {
            return true;
        }
        
        // User with the "Coach" role can modify feedback in their company
        if (isCoach()) {
            return true;
        }
        
        return false;
    }
}

// Check if user has access to reports from a specific company
if (!function_exists('hasCompanyAccess')) {
    function hasCompanyAccess($companyId) {
        // Admin has access to all companies
        if (isAdmin()) {
            return true;
        }
        
        // Check if user has access to the specified company
        if (isset($_SESSION['companies'])) {
            foreach ($_SESSION['companies'] as $company) {
                if ($company['company_id'] == $companyId) {
                    return true;
                }
            }
        }
        
        return false;
    }
}

// Require admin access or redirect
if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        if (!isAdmin()) {
            redirect('index.php');
        }
    }
}

// Require CEO or admin access
if (!function_exists('requireCEOorAdmin')) {
    function requireCEOorAdmin() {
        if (!isAdmin() && !isCEO()) {
            redirect('index.php');
        }
    }
}

// Require logged in user (any type)
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isLoggedIn()) {
            redirect('login.php');
        }
    }
}

// Require personnel access only
if (!function_exists('requirePersonnel')) {
    function requirePersonnel() {
        if (!isLoggedIn() || isAdmin()) {
            redirect('index.php');
        }
    }
}

// Admin login function
if (!function_exists('adminLogin')) {
    function adminLogin($username, $password, $pdo) {
        // Clean inputs should be already done in login.php
        
        // Check admin credentials
        $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        // Verify password
        if ($admin && verifyPassword($password, $admin['password'])) {
            // Set session data
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['username'] = $admin['username'];
            $_SESSION['user_type'] = 'admin';
            $_SESSION['logged_in'] = true;
            
            return true;
        }
        
        return false;
    }
}

// Personnel login function
if (!function_exists('personnelLogin')) {
    function personnelLogin($username, $password, $pdo) {
        // Get personnel info
        $stmt = $pdo->prepare("SELECT p.*, r.is_ceo 
                             FROM personnel p 
                             JOIN roles r ON p.role_id = r.id 
                             WHERE p.username = ? AND p.is_active = 1");
        $stmt->execute([$username]);
        $personnel = $stmt->fetch();
        
        // Verify password
        if ($personnel && verifyPassword($password, $personnel['password'])) {
            // دریافت شرکت‌های کاربر
            $companies = [];
            
            // روش مستقیم برای دریافت شرکت‌ها
            $stmt = $pdo->prepare("SELECT 
                                 c.id as company_id, 
                                 pc.is_primary, 
                                 c.name as company_name, 
                                 c.is_active
                               FROM 
                                 personnel_companies pc
                               JOIN 
                                 companies c ON pc.company_id = c.id
                               WHERE 
                                 pc.personnel_id = ? AND c.is_active = 1
                               ORDER BY 
                                 pc.is_primary DESC");
            $stmt->execute([$personnel['id']]);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // اگر هیچ شرکتی پیدا نشد، از شرکت اصلی در جدول personnel استفاده کن
            if (empty($companies)) {
                $stmt = $pdo->prepare("SELECT 
                                     id as company_id, 
                                     1 as is_primary, 
                                     name as company_name, 
                                     is_active
                                   FROM 
                                     companies
                                   WHERE 
                                     id = ? AND is_active = 1");
                $stmt->execute([$personnel['company_id']]);
                $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($companies)) {
                    return false;
                }
            }
            
            // تنظیم شرکت اصلی
            $primaryCompany = $companies[0];
            
            // ذخیره در session
            $_SESSION['companies'] = $companies;
            $_SESSION['user_id'] = $personnel['id'];
            $_SESSION['username'] = $personnel['username'];
            $_SESSION['user_type'] = 'user';
            $_SESSION['company_id'] = $primaryCompany['company_id'];
            $_SESSION['company_name'] = $primaryCompany['company_name'];
            $_SESSION['role_id'] = $personnel['role_id'];
            $_SESSION['is_ceo'] = $personnel['is_ceo'] ? true : false;
            $_SESSION['logged_in'] = true;
            
            return true;
        }
        
        return false;
    }
}
if (!function_exists('switchCompany')) {
    function switchCompany($companyId) {
        // بررسی اینکه کاربر به این شرکت دسترسی دارد
        $hasAccess = false;
        
        if (isset($_SESSION['companies'])) {
            foreach ($_SESSION['companies'] as $company) {
                if ($company['company_id'] == $companyId && $company['is_active']) {
                    $hasAccess = true;
                    $_SESSION['company_id'] = $company['company_id'];
                    $_SESSION['company_name'] = $company['company_name'];
                    break;
                }
            }
        }
        
        // اگر شرکت تغییر کرد، اطلاعات همگام شود
        if ($hasAccess && function_exists('syncActiveCompany')) {
            syncActiveCompany();
        }
        
        return $hasAccess;
    }
}
// Check if user has specific permission
if (!function_exists('hasPermission')) {
    function hasPermission($permissionCode) {
        global $pdo;
        
        // کوچ (میلاد احمدی) دسترسی ویژه برای ثبت گزارش کوچ دارد
        if (isCoach() && $permissionCode == 'add_coach_report') {
            return true;
        }
        
        // Admin has all permissions
        if (isAdmin()) {
            return true;
        }
        
        // Get user's role_id
        if (!isset($_SESSION['role_id'])) {
            return false;
        }
        
        $roleId = $_SESSION['role_id'];
        
        // Check if the permission exists for the role
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON rp.permission_id = p.id
             WHERE rp.role_id = ? AND p.code = ?"
        );
        $stmt->execute([$roleId, $permissionCode]);
        $hasPermission = $stmt->fetchColumn() > 0;
        
        // Special case: CEOs always have access to view_coach_reports
        if ($permissionCode == 'view_coach_reports' && isCEO()) {
            return true;
        }
        
        return $hasPermission;
    }
}

// Generate password hash
if (!function_exists('generateHash')) {
    function generateHash($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

// Verify password against hash
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

// Generate a random password
if (!function_exists('generateRandomPassword')) {
    function generateRandomPassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
}

// Check if user can access reports
if (!function_exists('canAccessReports')) {
    function canAccessReports($personnelId, $pdo) {
        // Admin can access all reports
        if (isAdmin()) {
            return true;
        }
        
        // CEO can access reports from their company
        if (isCEO()) {
            // Get company ID of the personnel
            $stmt = $pdo->prepare("SELECT company_id FROM personnel WHERE id = ?");
            $stmt->execute([$personnelId]);
            $personnelCompanyId = $stmt->fetchColumn();
            
            // Check if CEO's company matches personnel's company
            return $personnelCompanyId == $_SESSION['company_id'];
        }
        
        // Coach can access reports from their company
        if (isCoach()) {
            // Get company ID of the personnel
            $stmt = $pdo->prepare("SELECT company_id FROM personnel WHERE id = ?");
            $stmt->execute([$personnelId]);
            $personnelCompanyId = $stmt->fetchColumn();
            
            // Check if Coach's company matches personnel's company
            return $personnelCompanyId == $_SESSION['company_id'];
        }
        
        // Regular personnel can only access their own reports
        return $personnelId == $_SESSION['user_id'];
    }
}

// Redirect helper function
if (!function_exists('redirect')) {
    function redirect($location) {
        header("Location: $location");
        exit;
    }
}
// تابع خروج از سیستم
if (!function_exists('logout')) {
    function logout() {
        // پاک کردن تمام داده‌های جلسه
        $_SESSION = array();
        
        // اگر از کوکی جلسه استفاده می‌شود، آن را حذف کنید
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // در نهایت، جلسه را نابود کنید
        session_destroy();
    }
}
// همگام‌سازی شرکت فعال در جدول personnel
if (!function_exists('syncActiveCompany')) {
    function syncActiveCompany() {
        global $pdo;
        
        if (isLoggedIn() && !isAdmin() && isset($_SESSION['user_id']) && isset($_SESSION['company_id'])) {
            try {
                // به‌روزرسانی فیلد company_id در جدول personnel برای سازگاری با coach_report.php
                $stmt = $pdo->prepare("UPDATE personnel SET company_id = ? WHERE id = ?");
                $stmt->execute([$_SESSION['company_id'], $_SESSION['user_id']]);
            } catch (PDOException $e) {
                // خطا را نادیده بگیرید (پیوستگی داده‌ها مهمتر است)
            }
        }
    }
}