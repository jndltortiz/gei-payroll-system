<?php
// Starting threshold, not a calibrated accuracy claim. Lower distances are better.
const FACE_KIOSK_THRESHOLD = 0.5;
const FACE_KIOSK_COOLDOWN_SECONDS = 30;
// NO LIVENESS: photos, video replay, or fabricated client descriptors can spoof scans.
function kioskDistance(array $a, array $b): float {
    $sum=0.0; foreach ($a as $i=>$v) $sum+=($v-$b[$i])**2;
    return sqrt($sum);
}
// Caller owns the transaction. No device is registered: device_id stays NULL.
function kioskScan(PDO $pdo, array $descriptor, string $action): array {
    if (!in_array($action, ['time_in', 'time_out'], true)) throw new InvalidArgumentException('Invalid attendance action.');
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'gei_test' || !$pdo->inTransaction()) throw new RuntimeException('Test transaction required.');
    $best=null; $distance=INF;
    foreach ($pdo->query("SELECT f.employee_id,f.descriptor FROM employee_face_templates f JOIN employees e ON e.employee_id=f.employee_id WHERE e.employee_status='ACTIVE' ORDER BY f.template_id") as $sample) {
        $stored=json_decode($sample['descriptor'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($stored)||count($stored)!==128) throw new RuntimeException('Invalid stored template');
        $d=kioskDistance($descriptor,$stored);
        if($d<$distance){$distance=$d;$best=(int)$sample['employee_id'];}
    }
    $now=date('Y-m-d H:i:s');$date=substr($now,0,10);$time=substr($now,11);
    $matched=$best!==null && $distance<FACE_KIOSK_THRESHOLD;
    $log=$pdo->prepare("INSERT INTO biometric_logs(employee_id,device_id,biometric_type,confidence_score,match_distance,is_match,captured_at) VALUES (?,NULL,'FACE',NULL,?,?,?)");
    if(!$matched){$log->execute([null,is_finite($distance)?$distance:null,0,$now]);return ['result'=>'not_recognized','message'=>'Not recognized.'];}
    // Serialize all kiosk attendance operations for this employee across sessions.
    $stmt=$pdo->prepare('SELECT * FROM employees WHERE employee_id=? FOR UPDATE');$stmt->execute([$best]);$employee=$stmt->fetch();
    if(!$employee||$employee['employee_status']!=='ACTIVE')throw new RuntimeException('Employee unavailable');
    $name=trim($employee['first_name'].' '.$employee['last_name']);
    $last=$pdo->prepare("SELECT MAX(captured_at) FROM biometric_logs WHERE employee_id=? AND biometric_type='FACE' AND is_match=1");$last->execute([$best]);$previous=$last->fetchColumn();
    $log->execute([$best,$distance,1,$now]);
    // Matched attempts, including cooldown attempts, restart the quiet interval.
    if($previous && time()-strtotime($previous)<FACE_KIOSK_COOLDOWN_SECONDS)return ['result'=>'cooldown','message'=>'Recent scan detected. No attendance change. Wait 30 seconds without scanning before trying again.','employee'=>$name];
    $stmt=$pdo->prepare('SELECT * FROM attendance_records WHERE employee_id=? AND attendance_date=? FOR UPDATE');$stmt->execute([$best,$date]);$row=$stmt->fetch();
    if ($row && $row['is_archived']) return ['result'=>'review','message'=>'Archived attendance needs admin review. No attendance change.','employee'=>$name];
    if ($action === 'time_in' && $row) {
        return $row['time_in']
            ? ['result'=>'duplicate_time_in','message'=>'Time In is already recorded for today. No attendance change.','employee'=>$name]
            : ['result'=>'review','message'=>'Existing attendance needs admin review before Time In.','employee'=>$name];
    }
    if ($action === 'time_out') {
        if (!$row || !$row['time_in']) return ['result'=>'missing_time_in','message'=>'No valid Time In exists for today. Ask the guard or admin for assistance.','employee'=>$name];
        if ($row['time_out'] !== null) return ['result'=>'duplicate_time_out','message'=>'Time Out is already recorded for today. No attendance change.','employee'=>$name];
    }
    $shift=$pdo->prepare('SELECT * FROM shifts WHERE shift_id=?');$shift->execute([$employee['shift_id']]);$s=$shift->fetch();
    $mins=static fn($v)=>(int)substr($v,0,2)*60+(int)substr($v,3,2);
    if($action === 'time_in'){
        $leave=$pdo->prepare("SELECT COUNT(*) FROM leave_requests lr JOIN leave_request_dates d ON d.leave_id=lr.leave_id WHERE lr.employee_id=? AND d.leave_date=? AND lr.status='APPROVED' AND d.status='APPROVED'");$leave->execute([$best,$date]);
        if($leave->fetchColumn())return ['result'=>'review','message'=>'Approved leave exists. Please contact admin.','employee'=>$name];
        $holiday=$pdo->prepare('SELECT holiday_name FROM holidays WHERE holiday_date=?');$holiday->execute([$date]);$holidayName=$holiday->fetchColumn();
        $status='PRESENT';
        // Matches existing admin time-in classification, including its 09:00 full-time cutoff.
        if($holidayName)$status='HOLIDAY';
        elseif($s && $mins($time)>$mins($s['start_time']))$status=$employee['employment_type']==='PART_TIME'?'LATE':($mins($time)<540?'LATE':'HALF_DAY');
        $pdo->prepare("INSERT INTO attendance_records(employee_id,attendance_date,time_in,attendance_status,attendance_source,remarks) VALUES (?,?,?,?,'FACE',?)")->execute([$best,$date,$time,$status,$holidayName?:null]);
        $id=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO attendance_audit_log(attendance_id,changed_by,action_type,new_time_in,new_status,new_method,reason) VALUES (?,NULL,'CREATE',?,?,'FACE','Local face kiosk time-in')")->execute([$id,$time,$status]);
        return ['result'=>'time_in','message'=>'Time-in recorded at '.$time.'.','employee'=>$name];
    }
    if($time<$row['time_in'])return ['result'=>'review','message'=>'Time-out precedes time-in; contact admin.','employee'=>$name];
    $ot=$s?max(0,$mins($time)-$mins($s['end_time'])):0;
    // Preserve original source for mixed-method rows; this FACE scan is logged separately.
    $update = $pdo->prepare('UPDATE attendance_records SET time_out=?,overtime_minutes=? WHERE attendance_id=? AND time_out IS NULL');
    $update->execute([$time,$ot,$row['attendance_id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Attendance changed during scan; admin review required.');
    $pdo->prepare("INSERT INTO attendance_audit_log(attendance_id,changed_by,action_type,old_time_in,new_time_in,old_time_out,new_time_out,old_status,new_status,old_method,new_method,reason) VALUES (?,NULL,'TIMEOUT',?,?,NULL,?,?,?,?,?,'Local face kiosk time-out')")->execute([$row['attendance_id'],$row['time_in'],$row['time_in'],$time,$row['attendance_status'],$row['attendance_status'],$row['attendance_source'],$row['attendance_source']]);
    return ['result'=>'time_out','message'=>'Time-out recorded at '.$time.'.','employee'=>$name];
}
