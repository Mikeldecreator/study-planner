<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
$userId = currentUserId();
$db = getDb();

function timerError(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
function timerBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $_POST ?: [];
    $body = json_decode($raw, true);
    if (!is_array($body)) timerError('Invalid JSON request body.', 400);
    return $body;
}
function validItemType($value): string {
    $type = (string)$value;
    if (!in_array($type, ['task','course','session'], true)) timerError('Invalid work item type.', 422);
    return $type;
}
function ownedItem(PDO $db, string $type, int $id, int $userId): ?array {
    if ($id < 1) return null;
    if ($type === 'task') {
        $sql = 'SELECT id, status, progress_percent, duration_hours AS estimate_hours, title FROM tasks WHERE id=? AND user_id=? LIMIT 1';
    } elseif ($type === 'course') {
        $sql = 'SELECT id, status, progress_percent, estimated_hours AS estimate_hours, CONCAT(code, " — ", name) AS title FROM courses WHERE id=? AND user_id=? LIMIT 1';
    } else {
        $sql = 'SELECT id, IF(is_completed=1,"completed","pending") AS status, progress_percent, TIMESTAMPDIFF(SECOND,start_time,end_time)/3600 AS estimate_hours, title FROM schedule_events WHERE id=? AND user_id=? LIMIT 1';
    }
    $stmt=$db->prepare($sql); $stmt->execute([$id,$userId]); $row=$stmt->fetch();
    return $row ?: null;
}
function secondsNow(array $timer): int {
    $seconds=(int)$timer['total_work_seconds'];
    if ($timer['status']==='running' && !empty($timer['started_at'])) {
        $started=strtotime($timer['started_at']);
        if ($started!==false) $seconds += max(0,time()-$started);
    }
    return $seconds;
}
function progressFromTime(array $timer,float $estimateHours): int {
    $base=max(0,min(100,(int)$timer['base_progress']));
    if ($estimateHours<=0) return $base;
    $fraction=secondsNow($timer)/($estimateHours*3600);
    return (int)min(100,round($base+(100-$base)*$fraction));
}
function syncItem(PDO $db,string $type,int $id,int $userId,int $progress,bool $complete): void {
    $progress=max(0,min(100,$progress));
    if($type==='task') {
        $stmt=$db->prepare('UPDATE tasks SET progress_percent=?, status=?, completed_at=? WHERE id=? AND user_id=?');
        $stmt->execute([$progress,$complete?'completed':($progress>0?'in_progress':'pending'),$complete?date('Y-m-d H:i:s'):null,$id,$userId]);
    } elseif($type==='course') {
        $stmt=$db->prepare('UPDATE courses SET progress_percent=?, status=?, completed_at=? WHERE id=? AND user_id=?');
        $stmt->execute([$progress,$complete?'completed':($progress>0?'in_progress':'pending'),$complete?date('Y-m-d H:i:s'):null,$id,$userId]);
    } else {
        $stmt=$db->prepare('UPDATE schedule_events SET progress_percent=?, is_completed=?, completed_at=? WHERE id=? AND user_id=?');
        $stmt->execute([$progress,$complete?1:0,$complete?date('Y-m-d H:i:s'):null,$id,$userId]);
    }
}
function normalizeTimer(PDO $db,array $timer,string $type,int $id,int $userId,array $item): array {
    $total=secondsNow($timer); $progress=progressFromTime($timer,(float)$item['estimate_hours']);
    $complete=$progress>=100 || $timer['status']==='completed';
    if($complete && $timer['status']!=='completed') {
        $stmt=$db->prepare('UPDATE work_timers SET total_work_seconds=?, status="completed", started_at=NULL, paused_at=NOW(), completed_at=NOW() WHERE id=? AND user_id=?');
        $stmt->execute([$total,$timer['id'],$userId]);
    }
    if($complete || $progress>0) syncItem($db,$type,$id,$userId,$complete?100:$progress,$complete);
    $timer['total_seconds_live']=$total; $timer['progress_percent']=$complete?100:$progress; $timer['status']=$complete?'completed':$timer['status'];
    return $timer;
}

$method=$_SERVER['REQUEST_METHOD'];
if(!in_array($method,['GET','POST'],true)) timerError('Method not allowed.',405);

if($method==='GET') {
    $type=validItemType($_GET['item_type']??''); $id=(int)($_GET['item_id']??0); $item=ownedItem($db,$type,$id,$userId);
    if(!$item) timerError('Work item not found.',404);
    $stmt=$db->prepare('SELECT * FROM work_timers WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1'); $stmt->execute([$userId,$type,$id]); $timer=$stmt->fetch();
    if(!$timer) $timer=['id'=>null,'user_id'=>$userId,'item_type'=>$type,'item_id'=>$id,'total_work_seconds'=>0,'base_progress'=>(int)$item['progress_percent'],'status'=>$item['status']==='completed'?'completed':'paused','started_at'=>null,'paused_at'=>null,'completed_at'=>null,'total_seconds_live'=>0,'progress_percent'=>(int)$item['progress_percent']];
    else $timer=normalizeTimer($db,$timer,$type,$id,$userId,$item);
    $timer['item_title']=$item['title']; $timer['estimate_hours']=(float)$item['estimate_hours'];
    echo json_encode(['ok'=>true,'timer'=>$timer]); exit;
}

$body=timerBody(); verifyCsrf($body['csrf_token']??null); $type=validItemType($body['item_type']??''); $id=(int)($body['item_id']??0); $action=(string)($body['action']??''); $item=ownedItem($db,$type,$id,$userId);
if(!$item) timerError('Work item not found.',404);

$stmt=$db->prepare('SELECT * FROM work_timers WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1'); $stmt->execute([$userId,$type,$id]); $timer=$stmt->fetch();

if($action==='start' || $action==='resume') {
    if($item['status']==='completed') timerError('This item is already completed.',422);
    $now=date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $runningStmt=$db->prepare('SELECT * FROM work_timers WHERE user_id=? AND status="running"');
        $runningStmt->execute([$userId]);
        foreach($runningStmt->fetchAll() as $runningTimer){
            $runningType=(string)$runningTimer['item_type']; $runningId=(int)$runningTimer['item_id'];
            $runningItem=ownedItem($db,$runningType,$runningId,$userId);
            if($runningItem){
                $runningTotal=secondsNow($runningTimer); $runningProgress=progressFromTime($runningTimer,(float)$runningItem['estimate_hours']);
                $saveRunning=$db->prepare('UPDATE work_timers SET total_work_seconds=?, status="paused", started_at=NULL, paused_at=NOW() WHERE id=? AND user_id=?');
                $saveRunning->execute([$runningTotal,$runningTimer['id'],$userId]);
                syncItem($db,$runningType,$runningId,$userId,$runningProgress,false);
            }
        }
        if(!$timer) {
            $stmt=$db->prepare('INSERT INTO work_timers(user_id,item_type,item_id,total_work_seconds,base_progress,status,started_at) VALUES(?,?,?,?,?,"running",?)');
            $stmt->execute([$userId,$type,$id,0,(int)$item['progress_percent'],$now]);
        } else {
            // On resume, the saved progress becomes the new baseline while total work remains cumulative.
            $stmt=$db->prepare('UPDATE work_timers SET status="running", started_at=?, paused_at=NULL WHERE id=? AND user_id=?');
            $stmt->execute([$now,$timer['id'],$userId]);
        }
        if($type==='task'){ $db->prepare('UPDATE tasks SET status="in_progress" WHERE id=? AND user_id=? AND status<>"completed"')->execute([$id,$userId]); }
        elseif($type==='course'){ $db->prepare('UPDATE courses SET status="in_progress" WHERE id=? AND user_id=? AND status<>"completed"')->execute([$id,$userId]); }
        $db->commit();
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
} elseif($action==='pause') {
    if($timer) {
        $total=secondsNow($timer); $progress=progressFromTime($timer,(float)$item['estimate_hours']);
        $stmt=$db->prepare('UPDATE work_timers SET total_work_seconds=?, status="paused", started_at=NULL, paused_at=NOW() WHERE id=? AND user_id=?'); $stmt->execute([$total,$timer['id'],$userId]);
        syncItem($db,$type,$id,$progress,false);
    }
} elseif($action==='complete') {
    $total=$timer?secondsNow($timer):0;
    if($timer) {
        $stmt=$db->prepare('UPDATE work_timers SET total_work_seconds=?, status="completed", started_at=NULL, paused_at=NOW(), completed_at=NOW() WHERE id=? AND user_id=?'); $stmt->execute([$total,$timer['id'],$userId]);
    } else {
        $stmt=$db->prepare('INSERT INTO work_timers(user_id,item_type,item_id,total_work_seconds,base_progress,status,paused_at,completed_at) VALUES(?,?,?,?,?,"completed",NOW(),NOW())'); $stmt->execute([$userId,$type,$id,0,(int)$item['progress_percent']]);
    }
    syncItem($db,$type,$id,100,true); logActivity($userId,"Completed {$item['title']}",'success');
} elseif($action==='reopen') {
    if($timer) { $stmt=$db->prepare('UPDATE work_timers SET status="paused", started_at=NULL, completed_at=NULL, paused_at=NOW() WHERE id=? AND user_id=?'); $stmt->execute([$timer['id'],$userId]); }
    syncItem($db,$type,$id,min(99,(int)$item['progress_percent']),false);
} else timerError('Unknown timer action.',422);

$stmt=$db->prepare('SELECT * FROM work_timers WHERE user_id=? AND item_type=? AND item_id=? LIMIT 1'); $stmt->execute([$userId,$type,$id]); $timer=$stmt->fetch(); $item=ownedItem($db,$type,$id,$userId)?:$item;
if(!$timer){ $timer=['id'=>null,'user_id'=>$userId,'item_type'=>$type,'item_id'=>$id,'total_work_seconds'=>0,'base_progress'=>(int)$item['progress_percent'],'status'=>$item['status']==='completed'?'completed':'paused','started_at'=>null,'paused_at'=>null,'completed_at'=>null]; }
$timer=normalizeTimer($db,$timer,$type,$id,$userId,$item); $timer['item_title']=$item['title']; $timer['estimate_hours']=(float)$item['estimate_hours'];
echo json_encode(['ok'=>true,'timer'=>$timer,'item'=>$item]);
