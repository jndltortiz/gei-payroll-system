<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ✅ Generate employee_no
        $stmtCount = $pdo->query("SELECT COUNT(*) as total FROM employees");
        $count = $stmtCount->fetch()['total'] + 1;

        $year = date('Y');
        $employee_no = 'EMP-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        
        // ✅ Generate employee_no
        $stmtCount = $pdo->query("SELECT COUNT(*) as total FROM employees");
        $count = $stmtCount->fetch()['total'] + 1;

        $year = date('Y');
        $employee_no = 'EMP-' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        
        $contact = $_POST['contact_no'] ?? null;

        if ($contact) {
            $c = trim(preg_replace('/\s+/', '', $contact)); // strip spaces
            if (preg_match('/^\+639\d{9}$/', $c))     $contact = $c;           // already +639XXXXXXXXX
            elseif (preg_match('/^639\d{9}$/', $c))   $contact = '+' . $c;     // 639XXXXXXXXX
            elseif (preg_match('/^09\d{9}$/', $c))    $contact = '+63' . substr($c, 1); // 09XXXXXXXXX
            else                                       $contact = $c;           // fallback
        }
        
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
        INSERT INTO employees (
            employee_no,
            first_name,
            middle_name,
            last_name,
            suffix,
            sex,
            birth_date,
            civil_status,
            address,
            email,
            contact_no,
            hire_date,
            employment_type,
            employee_status,
            department_id,
            position_id,
            shift_id
        ) VALUES (
            :employee_no,
            :first_name,
            :middle_name,
            :last_name,
            :suffix,
            :sex,
            :birth_date,
            :civil_status,
            :address,
            :email,
            :contact_no,
            :hire_date,
            :employment_type,
            :employee_status,
            :department_id,
            :position_id,
            :shift_id
        )
    ");

        $stmt->execute([
            ':employee_no'      => $employee_no,
            ':first_name'       => $_POST['first_name'],
            ':middle_name'      => $_POST['middle_name'] ?? null,
            ':last_name'        => $_POST['last_name'],

            ':suffix'           => $_POST['suffix'] ?? null,
            ':sex'              => $_POST['sex'] ?? null,
            ':birth_date'       => $_POST['birth_date'] ?? null,
            ':civil_status'     => $_POST['civil_status'] ?? null,
            ':address'          => $_POST['address'] ?? null,

            ':email'            => $_POST['email'] ?? null,
            ':contact_no'       => $_POST['contact_no'] ?? null,

            ':hire_date'        => $_POST['hire_date'],
            ':employment_type'  => $_POST['employment_type'],
            ':employee_status'  => $_POST['employee_status'],
            ':department_id'    => $_POST['department_id'],
            ':position_id'      => $_POST['position_id'],
            ':shift_id'         => $_POST['shift_id'] ?? null,
        ]);

        // ✅ Get inserted employee_id
        $employee_id = $pdo->lastInsertId();

        // ✅ 2. INSERT INTO employee_compensations
        $monthly = $_POST['basic_salary'];
        $daily   = $monthly / 22; // adjust if needed

        $stmt2 = $pdo->prepare("
            INSERT INTO employee_compensations (
                employee_id,
                effective_date,
                monthly_salary,
                daily_rate,
                pay_basis,
                is_active
            ) VALUES (?, CURDATE(), ?, ?, 'MONTHLY', 1)
        ");

        $stmt2->execute([
            $employee_id,
            $monthly,
            $daily
        ]);

        $username = $_POST['username'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        // ✅ FIX: map role FIRST
        $roleMap = [
            'Admin' => 1,
            'Accounting' => 2,
            'Employee' => 3
        ];

        $role_id = $roleMap[$_POST['role']] ?? 3;

        $stmt3 = $pdo->prepare("
            INSERT INTO users (
                username,
                password_hash,
                role_id,
                employee_id,
                is_active
            ) VALUES (?, ?, ?, ?, 1)
        ");

        $stmt3->execute([
            $username,
            $password,
            $role_id,   // ✅ NOW CORRECT
            $employee_id
        ]);

        $role_id = $roleMap[$_POST['role']] ?? 3;

        $pdo->commit();


        header("Location: ../modules/employees/index.php");
        exit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "Error: " . $e->getMessage();
    }
}