<?php
// No database connection: exercise the actual service against an in-memory PDO double.
require_once __DIR__.'/../includes/face-kiosk-service.php';
class QueueStatement extends PDOStatement {
    public function __construct(private Closure $run) {}
    private mixed $value = null;
    public function execute(?array $params = null): bool { $this->value = ($this->run)($params ?? []); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return $this->value; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
    public function getIterator(): Iterator { return new ArrayIterator($this->value); }
}
class QueuePDO extends PDO {
    public array $logs = [];
    public array $rows = [];
    public function __construct() {}
    public function inTransaction(): bool { return true; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $s = new QueueStatement(function() use ($query) {
            if ($query === 'SELECT DATABASE()') return 'gei_test';
            if (str_contains($query, 'employee_face_templates')) return [
                ['employee_id'=>1,'descriptor'=>json_encode(array_fill(0,128,0))],
                ['employee_id'=>2,'descriptor'=>json_encode(array_fill(0,128,1))]
            ];
            throw new RuntimeException('Unexpected query');
        });
        $s->execute(); return $s;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new QueueStatement(function(array $p) use ($query) {
            if (str_starts_with($query,'INSERT INTO biometric_logs')) { if ($p[0] !== null) $this->logs[$p[0]] = $p[3]; return true; }
            if (str_contains($query,'FROM employees WHERE')) return ['employee_status'=>'ACTIVE','first_name'=>'Employee','last_name'=>(string)$p[0]];
            if (str_contains($query,'MAX(captured_at)')) return $this->logs[$p[0]] ?? false;
            if (str_contains($query,'FROM attendance_records')) return $this->rows[$p[0]] ?? false;
            throw new RuntimeException('Unexpected statement');
        });
    }
}
$count=0;
function expectResult(array $result, string $expected): void {
    global $count;
    if ($result['result'] !== $expected) throw new RuntimeException('Expected '.$expected.', got '.$result['result']);
    $count++;
}
$db=new QueuePDO();
$a=array_fill(0,128,0); $b=array_fill(0,128,1);
expectResult(kioskScan($db,$a,'time_out'),'missing_time_in');
expectResult(kioskScan($db,$b,'time_out'),'missing_time_in'); // A does not cool down B.
expectResult(kioskScan($db,$a,'time_in'),'cooldown'); // Across actions, rejected match counts.
$db->logs[1]=date('Y-m-d H:i:s',time()-31);
$db->rows[1]=['is_archived'=>0,'time_in'=>'08:00:00','time_out'=>null];
expectResult(kioskScan($db,$a,'time_in'),'duplicate_time_in');
expectResult(kioskScan($db,$a,'time_out'),'cooldown'); // Duplicate restarted interval.
$db->logs[1]=date('Y-m-d H:i:s',time()-20);
expectResult(kioskScan($db,$a,'time_out'),'cooldown');
if (time()-strtotime($db->logs[1]) > 2) throw new RuntimeException('Cooldown attempt did not refresh matched timestamp');
$count++;
echo $count." cooldown assertions passed (no database connection).\n";

