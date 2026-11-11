<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$user = requireAuthentication();
$pdo = database();

$pdo->exec("CREATE TABLE IF NOT EXISTS portal_records (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 record_type VARCHAR(32) NOT NULL,
 owner_user_id VARCHAR(64) NOT NULL,
 owner_name VARCHAR(120) NOT NULL,
 payload JSON NOT NULL,
 status VARCHAR(40) NOT NULL DEFAULT 'Pending',
 assigned_role VARCHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_type_role_status (record_type, assigned_role, status),
 INDEX idx_owner (owner_user_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 recipient_user_id VARCHAR(64) NOT NULL,
 title VARCHAR(180) NOT NULL,
 message TEXT NOT NULL,
 record_type VARCHAR(32) NULL,
 record_id BIGINT UNSIGNED NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_recipient_read (recipient_user_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS portal_audit (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_user_id VARCHAR(64) NOT NULL,
 action VARCHAR(60) NOT NULL,
 record_type VARCHAR(32) NOT NULL,
 record_id BIGINT UNSIGNED NOT NULL,
 details JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_record (record_type, record_id), INDEX idx_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function allowedRolesForType(string $type): array {
 return match ($type) {
  'achievement' => ['verifier','lecturer','hod','admin'],
  'recommendation' => ['lecturer','hod','admin'],
  'attachment' => ['supervisor','hod','admin'],
  'support' => ['chaplaincy','dean','admin'],
  'opportunity' => ['admin','hod','registry'],
  default => []
 };
}
function notifyRoles(PDO $pdo, array $roles, string $title, string $message, string $type, int $id): void {
 if (!$roles) return;
 $marks = implode(',', array_fill(0, count($roles), '?'));
 $q = $pdo->prepare("SELECT user_id FROM users WHERE status='Active' AND role IN ($marks)");
 $q->execute($roles);
 $ins = $pdo->prepare('INSERT INTO portal_notifications (recipient_user_id,title,message,record_type,record_id) VALUES (?,?,?,?,?)');
 foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) $ins->execute([$uid,$title,$message,$type,$id]);
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
 $sql = "SELECT r.* FROM portal_records r WHERE "; $args=[];
 if ($user['role'] === 'admin') { $sql .= '1=1'; }
 elseif ($user['role'] === 'student' || $user['role'] === 'alumni') { $sql .= 'r.owner_user_id=?'; $args[]=$user['user_id']; }
 else { $sql .= '(r.assigned_role=? OR r.owner_user_id=?)'; $args[]=$user['role']; $args[]=$user['user_id']; }
 $sql .= ' ORDER BY r.created_at DESC LIMIT 1000';
 $st=$pdo->prepare($sql); $st->execute($args); $rows=$st->fetchAll();
 foreach($rows as &$r){$r['payload']=json_decode((string)$r['payload'],true) ?: []; $r['id']=(int)$r['id'];} unset($r);
 $n=$pdo->prepare('SELECT id,title,message,record_type,record_id,is_read,created_at FROM portal_notifications WHERE recipient_user_id=? ORDER BY created_at DESC LIMIT 100');
 $n->execute([$user['user_id']]);
 jsonResponse(['success'=>true,'records'=>$rows,'notifications'=>$n->fetchAll()]);
}
if ($method !== 'POST') jsonResponse(['success'=>false,'message'=>'Method not allowed.'],405);
verifyCsrf();
$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)) jsonResponse(['success'=>false,'message'=>'Invalid request.'],400);
$action=(string)($in['action']??'');
if($action==='create'){
 $type=(string)($in['type']??''); $payload=$in['payload']??[];
 if(!in_array($type,['achievement','recommendation','attachment','support','opportunity'],true) || !is_array($payload)) jsonResponse(['success'=>false,'message'=>'Invalid record.'],422);
 if($user['role']!=='student' && !($type==='opportunity' && in_array($user['role'],['admin','hod','registry'],true))) jsonResponse(['success'=>false,'message'=>'Only authorised accounts can submit this record.'],403);
 $assigned=['achievement'=>'verifier','recommendation'=>'lecturer','attachment'=>'supervisor','support'=>'chaplaincy','opportunity'=>'student'][$type];
 $status=$type==='opportunity'?'Published':'Pending';
 $st=$pdo->prepare('INSERT INTO portal_records(record_type,owner_user_id,owner_name,payload,status,assigned_role) VALUES(?,?,?,?,?,?)');
 $st->execute([$type,$user['user_id'],$user['full_name'],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$status,$assigned]); $id=(int)$pdo->lastInsertId();
 $pdo->prepare('INSERT INTO portal_audit(actor_user_id,action,record_type,record_id,details) VALUES(?,?,?,?,?)')->execute([$user['user_id'],'created',$type,$id,json_encode(['status'=>$status])]);
 notifyRoles($pdo,allowedRolesForType($type),'New '.ucfirst($type).' submission',$user['full_name'].' submitted a '.$type.' for review.',$type,$id);
 jsonResponse(['success'=>true,'id'=>$id,'status'=>$status],201);
}
if($action==='decide'){
 $id=(int)($in['id']??0); $status=(string)($in['status']??''); $comment=trim((string)($in['comment']??''));
 if($id<1 || !in_array($status,['Verified','Needs changes','Approved','Rejected','Issued','In progress','Completed'],true)) jsonResponse(['success'=>false,'message'=>'Invalid decision.'],422);
 $st=$pdo->prepare('SELECT * FROM portal_records WHERE id=? FOR UPDATE'); $pdo->beginTransaction(); $st->execute([$id]); $r=$st->fetch();
 if(!$r){$pdo->rollBack();jsonResponse(['success'=>false,'message'=>'Record not found.'],404);}
 if(!in_array($user['role'],allowedRolesForType($r['record_type']),true) && $user['role']!=='admin'){$pdo->rollBack();jsonResponse(['success'=>false,'message'=>'Not authorised to review this record.'],403);}
 $payload=json_decode((string)$r['payload'],true) ?: []; $payload['review_comment']=$comment; $payload['reviewer']=$user['full_name']; $payload['reviewed_at']=date(DATE_ATOM);
 $up=$pdo->prepare('UPDATE portal_records SET status=?,payload=? WHERE id=?'); $up->execute([$status,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);
 $pdo->prepare('INSERT INTO portal_audit(actor_user_id,action,record_type,record_id,details) VALUES(?,?,?,?,?)')->execute([$user['user_id'],'status_changed',$r['record_type'],$id,json_encode(['status'=>$status,'comment'=>$comment])]);
 $pdo->prepare('INSERT INTO portal_notifications(recipient_user_id,title,message,record_type,record_id) VALUES(?,?,?,?,?)')->execute([$r['owner_user_id'],'Your '.ucfirst($r['record_type']).' was updated','Status: '.$status.($comment?' — '.$comment:''),$r['record_type'],$id]);
 $pdo->commit(); jsonResponse(['success'=>true,'status'=>$status]);
}
if($action==='read_notification'){
 $id=(int)($in['id']??0); $st=$pdo->prepare('UPDATE portal_notifications SET is_read=1 WHERE id=? AND recipient_user_id=?');$st->execute([$id,$user['user_id']]);jsonResponse(['success'=>true]);
}
jsonResponse(['success'=>false,'message'=>'Unknown action.'],400);
