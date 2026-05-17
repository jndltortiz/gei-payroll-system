<?php
require '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $pdo->prepare("
        UPDATE employees SET
            first_name        = :first_name,
            middle_name       = :middle_name,
            last_name         = :last_name,
            suffix            = :suffix,
            sex               = :sex,
            birth_date        = :birth_date,
            civil_status      = :civil_status,
            contact_no        = :contact_no,
            email             = :email,
            address           = :address,
            employee_no       = :employee_no,
            department_id     = :department_id,
            position_id       = :position_id,
            employment_type   = :employment_type,
            employee_status   = :employee_status,
            hire_date         = :hire_date,
            sss_no            = :sss_no,
            philhealth_no     = :philhealth_no,
            pagibig_no        = :pagibig_no,
            tin_no            = :tin_no,
            peraa_no          = :peraa_no,
            rfid_uid          = :rfid_uid
        WHERE employee_id = :employee_id
    ");

    $stmt->execute([
        ':employee_id'      => $_POST['employee_id'],
        ':first_name'       => $_POST['first_name'],
        ':middle_name'      => $_POST['middle_name'] ?? null,
        ':last_name'        => $_POST['last_name'],
        ':suffix'           => $_POST['suffix'] ?? null,
        ':sex'              => $_POST['sex'],
        ':birth_date'       => $_POST['birth_date'] ?? null,
        ':civil_status'     => $_POST['civil_status'] ?? null,
        ':contact_no'       => $_POST['contact_no'] ?? null,
        ':email'            => $_POST['email'] ?? null,
        ':address'          => $_POST['address'] ?? null,
        ':employee_no'      => $_POST['employee_no'],
        ':department_id'    => $_POST['department_id'],
        ':position_id'      => $_POST['position_id'],
        ':employment_type'  => $_POST['employment_type'],
        ':employee_status'  => $_POST['employee_status'],
        ':hire_date'        => $_POST['hire_date'],
        ':sss_no'           => $_POST['sss_no'] ?? null,
        ':philhealth_no'    => $_POST['philhealth_no'] ?? null,
        ':pagibig_no'       => $_POST['pagibig_no'] ?? null,
        ':tin_no'           => $_POST['tin_no'] ?? null,
        ':peraa_no'         => $_POST['peraa_no'] ?? null,
        ':rfid_uid'         => $_POST['rfid_uid'] ?? null,
    ]);

    header("Location: ../modules/employees/index.php");
    exit();
}