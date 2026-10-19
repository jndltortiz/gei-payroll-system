<?php
require_once __DIR__.'/face-kiosk-service.php';

// Use the kiosk's exact descriptor shape, bounds and Euclidean distance threshold.
function enrollmentDescriptor(mixed $sample): array {
    if (!is_array($sample) || !array_is_list($sample) || count($sample)!==128) throw new InvalidArgumentException('Each sample must contain 128 numbers.');
    foreach ($sample as $v) if ((!is_int($v)&&!is_float($v)) || !is_finite((float)$v) || abs($v)>10) throw new InvalidArgumentException('Invalid descriptor values.');
    return $sample;
}
function enrollmentSamples(mixed $samples): array {
    if (!is_array($samples)||!array_is_list($samples)||count($samples)<3||count($samples)>5) throw new InvalidArgumentException('Capture 3 to 5 samples.');
    $encoded=[];
    foreach ($samples as $sample) $encoded[]=json_encode(enrollmentDescriptor($sample),JSON_THROW_ON_ERROR);
    if (count(array_unique($encoded))!==count($encoded)) throw new InvalidArgumentException('Capture distinct samples with small pose changes.');
    foreach ($samples as $a) foreach ($samples as $b) if (kioskDistance($a,$b)>=FACE_KIOSK_THRESHOLD) throw new InvalidArgumentException('Samples are inconsistent. Clear them and capture the same employee again.');
    return $encoded;
}
function enrollmentCheckDuplicates(array $samples, array $stored, int $employeeId): void {
    foreach ($stored as $row) {
        try { $descriptor=enrollmentDescriptor(json_decode($row['descriptor'],true,512,JSON_THROW_ON_ERROR)); }
        catch (Throwable $e) { throw new DomainException('An existing template is invalid. Admin review is required before saving enrollments.'); }
        if ((int)$row['employee_id']===$employeeId) continue;
        foreach ($samples as $sample) if (kioskDistance($sample,$descriptor)<FACE_KIOSK_THRESHOLD) {
            throw new DomainException('Face is too similar to an enrollment for another employee. No changes saved. Verify identity and ask the admin to review existing enrollments.');
        }
    }
}
function manageFaceEnrollment(PDO $pdo, int $actorId, int $employeeId, string $mode, array $samples, bool $confirmed): array {
    if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='gei_test') throw new DomainException('Enrollment is enabled only on gei_test.',403);
    if ($employeeId<1 || !in_array($mode,['add','replace','remove'],true)) throw new InvalidArgumentException('Invalid employee or operation.');
    if ($mode!=='add' && !$confirmed) throw new InvalidArgumentException('Explicit confirmation is required.');
    $encoded=$mode==='remove'?[]:enrollmentSamples($samples);
    // Connection-scoped mutex covers all employees, including an empty template table.
    // Every enrollment mutation must use this service; release even after rollback.
    if ((int)$pdo->query("SELECT GET_LOCK('gei_test_face_enrollment',5)")->fetchColumn()!==1) throw new DomainException('Another enrollment is being saved. Please retry shortly.');
    try {
        $pdo->beginTransaction();
        $actor=$pdo->prepare("SELECT u.user_id FROM users u JOIN roles r ON r.role_id=u.role_id WHERE u.user_id=? AND u.is_active=1 AND u.must_change_password=0 AND LOWER(r.role_name)='admin' FOR UPDATE");
        $actor->execute([$actorId]);
        if (!$actor->fetchColumn()) throw new DomainException('Active Admin account with an updated password required.',403);
        $employee=$pdo->prepare('SELECT employee_id FROM employees WHERE employee_id=? FOR UPDATE');
        $employee->execute([$employeeId]);
        if (!$employee->fetchColumn()) throw new InvalidArgumentException('Employee does not exist.');
        $stored=$pdo->query('SELECT employee_id,descriptor FROM employee_face_templates ORDER BY template_id FOR UPDATE')->fetchAll();
        $before=count(array_filter($stored,static fn($r)=>(int)$r['employee_id']===$employeeId));
        if ($mode!=='remove') enrollmentCheckDuplicates($samples,$stored,$employeeId);
        if ($mode==='remove' && !$before) throw new DomainException('This employee has no enrollment to remove.');
        if ($mode!=='add') $pdo->prepare('DELETE FROM employee_face_templates WHERE employee_id=?')->execute([$employeeId]);
        $timestamp=date('Y-m-d H:i:s');
        $insert=$pdo->prepare('INSERT INTO employee_face_templates(employee_id,descriptor,created_by,created_at,consented_at) VALUES (?,?,?,?,?)');
        foreach ($encoded as $descriptor) $insert->execute([$employeeId,$descriptor,$actorId,$timestamp,$timestamp]);
        $total=($mode==='add'?$before:0)+count($encoded);
        $pdo->prepare('INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES (?,?,?,?,?)')
            ->execute([$actorId,'FACE_ENROLLMENT_'.strtoupper($mode),'employees',$employeeId,'Face enrollment '.$mode.'; samples '.$before.' -> '.$total.'.'.($mode==='remove'?'':' Employee consent confirmed.')]);
        $pdo->commit();
        return ['count'=>$total,'consented_at'=>$mode==='remove'?'':$timestamp];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gei_test_face_enrollment')");
    }
}
