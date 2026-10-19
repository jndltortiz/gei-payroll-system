<?php
// Database-free service tests. Synthetic descriptors only; no application bootstrap.
require_once __DIR__.'/../includes/face-enrollment-service.php';
class EnrollmentStatement extends PDOStatement {
    private mixed $value=null;
    public function __construct(private Closure $fn) {}
    public function execute(?array $params=null): bool {$this->value=($this->fn)($params??[]);return true;}
    public function fetchColumn(int $column=0): mixed {return $this->value;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->value;}
}
class EnrollmentPDO extends PDO {
    public array $templates=[], $audits=[];
    public bool $admin=true,$exists=true,$lock=true,$auditFailure=false,$released=false;
    public string $database='gei_test';
    private bool $transaction=false;
    private array $snapshot=[];
    public function __construct() {}
    public function beginTransaction(): bool {$this->snapshot=[$this->templates,$this->audits];$this->transaction=true;return true;}
    public function inTransaction(): bool {return $this->transaction;}
    public function commit(): bool {$this->transaction=false;return true;}
    public function rollBack(): bool {[$this->templates,$this->audits]=$this->snapshot;$this->transaction=false;return true;}
    public function query(string $sql,?int $fetchMode=null,mixed ...$args): PDOStatement|false {$s=$this->prepare($sql);$s->execute();return $s;}
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        return new EnrollmentStatement(function(array $p) use($sql) {
            if($sql==='SELECT DATABASE()')return $this->database;
            if(str_contains($sql,'GET_LOCK'))return (int)$this->lock;
            if(str_contains($sql,'RELEASE_LOCK')){$this->released=true;return 1;}
            if(str_contains($sql,'FROM users'))return $this->admin?1:false;
            if(str_contains($sql,'FROM employees'))return $this->exists?1:false;
            if(str_starts_with($sql,'SELECT employee_id,descriptor'))return $this->templates;
            if(str_starts_with($sql,'DELETE FROM employee_face_templates')){$this->templates=array_values(array_filter($this->templates,fn($r)=>$r['employee_id']!==$p[0]));return true;}
            if(str_starts_with($sql,'INSERT INTO employee_face_templates')){$this->templates[]=['employee_id'=>$p[0],'descriptor'=>$p[1]];return true;}
            if(str_starts_with($sql,'INSERT INTO audit_logs')){if($this->auditFailure)throw new RuntimeException('Simulated audit failure');$this->audits[]=$p;return true;}
            throw new RuntimeException('Unexpected SQL in test');
        });
    }
}
$n=0;
function checkEnrollment(bool $ok,string $label): void {global $n;if(!$ok)throw new RuntimeException($label);$n++;}
function rejectsEnrollment(Closure $fn,string $class): void {
    try {$fn();}catch(Throwable $e){checkEnrollment($e instanceof $class,'Expected '.$class);return;}
    throw new RuntimeException('Expected rejection');
}
$samples=[array_fill(0,128,0.0),array_fill(0,128,0.001),array_fill(0,128,0.002)];
$db=new EnrollmentPDO;
$r=manageFaceEnrollment($db,1,10,'add',$samples,false);
checkEnrollment($r['count']===3&&count($db->audits)===1&&$db->released,'Registration and audit');
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,11,'add',$samples,false),DomainException::class);
checkEnrollment(count($db->templates)===3&&count($db->audits)===1,'Cross employee duplicate rollback');
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'replace',$samples,false),InvalidArgumentException::class);
$r=manageFaceEnrollment($db,1,10,'replace',$samples,true);
checkEnrollment($r['count']===3&&count($db->templates)===3,'Replace instead of append');
$r=manageFaceEnrollment($db,1,10,'add',$samples,false);
checkEnrollment($r['count']===6,'Existing append support');
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'remove',[],false),InvalidArgumentException::class);
$db->auditFailure=true;
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'remove',[],true),RuntimeException::class);
checkEnrollment(count($db->templates)===6,'Removal rolls back if audit fails');
$db->auditFailure=false;
$r=manageFaceEnrollment($db,1,10,'remove',[],true);
checkEnrollment($r['count']===0&&!$db->templates&&count($db->audits)===4,'Removal audit and count');
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'remove',[],true),DomainException::class);
$db->admin=false;
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'add',$samples,false),DomainException::class);
checkEnrollment(!$db->templates&&!$db->inTransaction(),'Authorization failure writes nothing');
$db->admin=true;$db->database='production';
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'add',$samples,false),DomainException::class);
$db->database='gei_test';$db->lock=false;
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'add',$samples,false),DomainException::class);
$db->lock=true;$db->exists=false;
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'add',$samples,false),InvalidArgumentException::class);
$db->exists=true;$db->templates=[['employee_id'=>99,'descriptor'=>'invalid']];
rejectsEnrollment(fn()=>manageFaceEnrollment($db,1,10,'add',$samples,false),DomainException::class);
rejectsEnrollment(fn()=>enrollmentSamples([$samples[0],$samples[0],$samples[0]]),InvalidArgumentException::class);
rejectsEnrollment(fn()=>enrollmentSamples([$samples[0],$samples[1],array_fill(0,128,1)]),InvalidArgumentException::class);
rejectsEnrollment(fn()=>enrollmentDescriptor(array_fill(0,128,11)),InvalidArgumentException::class);
rejectsEnrollment(fn()=>enrollmentDescriptor(array_fill(0,128,NAN)),InvalidArgumentException::class);
enrollmentCheckDuplicates($samples,[['employee_id'=>99,'descriptor'=>json_encode(array_fill(0,128,1))]],10);
checkEnrollment(true,'Different face permitted');
$endpoint=file_get_contents(__DIR__.'/../actions/face-enrollment-save.php');
checkEnrollment(str_contains($endpoint,'requireCsrfToken();')&&str_contains($endpoint,"['127.0.0.1','::1']")&&str_contains($endpoint,"currentRole()!=='admin'"),'Endpoint guard wiring (static)');
foreach($db->audits as $audit)checkEnrollment(!str_contains($audit[4],'descriptor'),'Audit contains no descriptor');
echo "$n enrollment assertions passed; no database connection.\n";

