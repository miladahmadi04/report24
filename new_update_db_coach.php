<?php
// new_update_db_coach.php - One-time script to add coach report tables
// Run this script once to create necessary tables for coach reporting system
require_once 'database.php';
require_once 'functions.php';
require_once 'auth.php';

try {
    // Disable foreign key checks to avoid issues
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Coach reports table
    $pdo->exec("CREATE TABLE IF NOT EXISTS new_coach_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_date DATE NOT NULL,
        company_id INT NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        description TEXT,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES personnel(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

    // People involved in the report
    $pdo->exec("CREATE TABLE IF NOT EXISTS new_coach_report_personnel (
        id INT AUTO_INCREMENT PRIMARY KEY,
        coach_report_id INT NOT NULL,
        personnel_id INT NOT NULL,
        coach_score DECIMAL(3,1) NOT NULL,
        coach_notes TEXT,
        FOREIGN KEY (coach_report_id) REFERENCES new_coach_reports(id) ON DELETE CASCADE,
        FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

    // Report recipients
    $pdo->exec("CREATE TABLE IF NOT EXISTS new_coach_report_recipients (
        coach_report_id INT NOT NULL,
        personnel_id INT NOT NULL,
        PRIMARY KEY (coach_report_id, personnel_id),
        FOREIGN KEY (coach_report_id) REFERENCES new_coach_reports(id) ON DELETE CASCADE,
        FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

    // Report responses
    $pdo->exec("CREATE TABLE IF NOT EXISTS new_coach_report_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        coach_report_id INT NOT NULL,
        personnel_id INT NOT NULL,
        response TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (coach_report_id) REFERENCES new_coach_reports(id) ON DELETE CASCADE,
        FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // ایجاد نقش کوچ اگر وجود نداشته باشد
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = 'کوچ'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO roles (name, description, is_ceo) VALUES (?, ?, ?)");
        $stmt->execute(['کوچ', 'دسترسی به سیستم کوچینگ و ارزیابی پرسنل', 0]);
        echo "نقش کوچ با موفقیت ایجاد شد.\n";
    }
    
    // Add coach_report permissions (if not already there)
    $permissionCodes = ['view_coach_reports', 'add_coach_report', 'edit_coach_report', 'delete_coach_report'];
    $permissionNames = ['مشاهده گزارش‌های کوچ', 'افزودن گزارش کوچ', 'ویرایش گزارش کوچ', 'حذف گزارش کوچ'];
    $permissionDescs = [
        'مشاهده لیست گزارش‌های کوچ',
        'ثبت گزارش کوچ جدید',
        'ویرایش گزارش کوچ',
        'حذف گزارش کوچ'
    ];
    
    for ($i = 0; $i < count($permissionCodes); $i++) {
        $code = $permissionCodes[$i];
        $name = $permissionNames[$i];
        $desc = $permissionDescs[$i];
        
        // Check if permission already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM permissions WHERE code = ?");
        $stmt->execute([$code]);
        
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO permissions (name, code, description) VALUES (?, ?, ?)");
            $stmt->execute([$name, $code, $desc]);
            
            // Add to admin role automatically
            $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'مدیر سیستم' LIMIT 1");
            $stmt->execute();
            $adminRoleId = $stmt->fetchColumn();
            
            if ($adminRoleId) {
                $permId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                $stmt->execute([$adminRoleId, $permId]);
            }
        }
    }
    
    // افزودن دسترسی‌های coach_report به نقش کوچ
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'کوچ' LIMIT 1");
    $stmt->execute();
    $coachRoleId = $stmt->fetchColumn();

    if ($coachRoleId) {
        $permissionCodes = ['view_coach_reports', 'add_coach_report', 'edit_coach_report'];
        
        foreach ($permissionCodes as $code) {
            $stmt = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
            $stmt->execute([$code]);
            $permId = $stmt->fetchColumn();
            
            if ($permId) {
                // بررسی وجود قبلی این دسترسی
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
                $stmt->execute([$coachRoleId, $permId]);
                
                if ($stmt->fetchColumn() == 0) {
                    $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    $stmt->execute([$coachRoleId, $permId]);
                }
            }
        }
        
        echo "دسترسی‌های لازم به نقش کوچ اضافه شد.\n";
    }
    
    // اضافه کردن فیلد admin_id به جدول personnel اگر وجود نداشته باشد
    try {
        $pdo->query("SELECT admin_id FROM personnel LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE personnel ADD COLUMN admin_id INT NULL");
        echo "فیلد admin_id به جدول personnel اضافه شد.\n";
    }
    
    // ایجاد رکورد در جدول personnel برای مدیران سیستم
    // ابتدا نیاز به یک role و company معتبر داریم
    $stmt = $pdo->query("SELECT id FROM roles ORDER BY id LIMIT 1");
    $roleId = $stmt->fetchColumn();
    if (!$roleId) {
        // اگر هیچ نقشی پیدا نشد، یک نقش ایجاد می‌کنیم
        $stmt = $pdo->exec("INSERT INTO roles (name, description, is_ceo) VALUES ('مدیر', 'نقش مدیر', 0)");
        $roleId = $pdo->lastInsertId();
    }
    
    $stmt = $pdo->query("SELECT id FROM companies WHERE is_active = 1 ORDER BY id LIMIT 1");
    $companyId = $stmt->fetchColumn();
    if (!$companyId) {
        // اگر هیچ شرکت فعالی پیدا نشد، یک شرکت ایجاد می‌کنیم
        $stmt = $pdo->exec("INSERT INTO companies (name, is_active) VALUES ('شرکت پیش‌فرض', 1)");
        $companyId = $pdo->lastInsertId();
    }
    
    // دریافت لیست تمام مدیران از جدول admin_users
    $stmt = $pdo->query("SELECT * FROM admin_users");
    $adminUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($adminUsers as $admin) {
        // بررسی آیا این مدیر قبلاً در جدول personnel ثبت شده است
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM personnel WHERE admin_id = ?");
        $stmt->execute([$admin['id']]);
        
        if ($stmt->fetchColumn() == 0) {
            // ایجاد رکورد در جدول personnel برای این مدیر
            $stmt = $pdo->prepare("INSERT INTO personnel (admin_id, user_id, company_id, role_id, first_name, last_name, gender, email, mobile, username, password, is_active) 
                                  VALUES (?, 0, ?, ?, ?, ?, 'male', ?, ?, ?, ?, 1)");
            
            // استفاده از first_name و last_name از جدول admin_users
            $firstName = isset($admin['first_name']) && !empty($admin['first_name']) ? $admin['first_name'] : 'مدیر';
            $lastName = isset($admin['last_name']) && !empty($admin['last_name']) ? $admin['last_name'] : 'سیستم';
            $email = isset($admin['email']) && !empty($admin['email']) ? $admin['email'] : 'admin@example.com';
            $mobile = isset($admin['mobile']) && !empty($admin['mobile']) ? $admin['mobile'] : '0';
            
            $stmt->execute([
                $admin['id'],        // admin_id
                $companyId,          // company_id
                $roleId,             // role_id
                $firstName,          // first_name
                $lastName,           // last_name
                $email,              // email
                $mobile,             // mobile
                $admin['username'],  // username
                $admin['password']   // password
            ]);
            
            echo "رکورد personnel برای مدیر سیستم '{$firstName} {$lastName}' با موفقیت ایجاد شد.\n";
        } else {
            // به‌روزرسانی اطلاعات personnel برای مطابقت با اطلاعات جدید admin_users
            $stmt = $pdo->prepare("UPDATE personnel SET 
                                 first_name = ?, 
                                 last_name = ?, 
                                 email = ?, 
                                 mobile = ? 
                               WHERE admin_id = ?");
            
            $firstName = isset($admin['first_name']) && !empty($admin['first_name']) ? $admin['first_name'] : 'مدیر';
            $lastName = isset($admin['last_name']) && !empty($admin['last_name']) ? $admin['last_name'] : 'سیستم';
            $email = isset($admin['email']) && !empty($admin['email']) ? $admin['email'] : 'admin@example.com';
            $mobile = isset($admin['mobile']) && !empty($admin['mobile']) ? $admin['mobile'] : '0';
            
            $stmt->execute([
                $firstName,  // first_name
                $lastName,   // last_name
                $email,      // email
                $mobile,     // mobile
                $admin['id'] // admin_id
            ]);
            
            echo "رکورد personnel برای مدیر سیستم '{$firstName} {$lastName}' به‌روزرسانی شد.\n";
        }
    }
    
    echo "تمام جداول و مجوزهای مربوط به گزارشات کوچ با موفقیت ایجاد شدند.\n";
    
} catch (PDOException $e) {
    die("خطا در ایجاد جداول: " . $e->getMessage());
}
?>