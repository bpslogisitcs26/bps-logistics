<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Kolkata');
session_name($config['session_name'] ?? 'BPSLOGISTICSSESSID');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
try {
    $dsn='mysql:host='.$config['host'].';dbname='.$config['database'].';charset='.$config['charset'];
    $pdo=new PDO($dsn,$config['username'],$config['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
} catch(Throwable $e) { http_response_code(500); exit('Database connection failed. Please check config.php.'); }
$uid=(int)$_SESSION['user_id']; $username=$_SESSION['username']; $role=$_SESSION['role'];
function h($v){return htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');}
function money($v){return '₹'.number_format((float)$v,2,'.',',');}
function csrf(){if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function verify_csrf(){if(!hash_equals($_SESSION['csrf']??'',$_POST['csrf']??'')){http_response_code(419);exit('Session token invalid. Reload and try again.');}}
function log_action($pdo,$userId,$action,$table,$id,$description=''){
    $q=$pdo->prepare('INSERT INTO audit_logs(user_id,action,table_name,record_id,description,ip_address) VALUES(?,?,?,?,?,?)');
    $q->execute([$userId,$action,$table,$id,$description,$_SERVER['REMOTE_ADDR']??'']);
}
function can_edit_row($row,$role){
    if($role==='ADMIN') return true;
    $created=$row['created_at']??null;
    if(!$created) return false;
    return (time()-strtotime($created)) <= 7*24*60*60 && (time()-strtotime($created)) >= 0;
}
function db_row($pdo,$table,$id){$allowed=['customers','vendors','courier_companies','bookings','rate_master','customer_receipts','vendor_work','vendor_payments','expenses','cash_transactions','shipment_tracking','pickups'];if(!in_array($table,$allowed,true))return null;$q=$pdo->prepare("SELECT * FROM `$table` WHERE id=?");$q->execute([$id]);return $q->fetch()?:null;}
function insert_row($pdo,$table,$data){$cols=array_keys($data);$sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')';$s=$pdo->prepare($sql);$s->execute(array_values($data));return (int)$pdo->lastInsertId();}
function get_options($pdo,$sql){return $pdo->query($sql)->fetchAll();}
function redirect_page($page,$extra=''){header('Location: index.php?page='.urlencode($page).$extra);exit;}

/* Customer autocomplete endpoint: type any first 3+ letters. */
if(isset($_GET['ajax']) && $_GET['ajax']==='customers'){
    header('Content-Type: application/json; charset=utf-8');
    $q=trim($_GET['q']??''); if(mb_strlen($q)<3){echo json_encode([]);exit;}
    $s=$pdo->prepare('SELECT id,customer_name,address,pincode,city,district,state,phone FROM customers WHERE is_active=1 AND customer_name LIKE ? ORDER BY customer_name LIMIT 15');
    $s->execute([$q.'%']); echo json_encode($s->fetchAll()); exit;
}
if(isset($_GET['ajax']) && $_GET['ajax']==='rates'){
    header('Content-Type: application/json; charset=utf-8');
    $cid=(int)($_GET['customer_id']??0);$dest=trim($_GET['destination']??'');
    $s=$pdo->prepare('SELECT rate_type,rate,amount FROM rate_master WHERE customer_id=? AND destination=? ORDER BY id DESC LIMIT 1');$s->execute([$cid,$dest]);echo json_encode($s->fetch()?:[]);exit;
}

/* Save / update actions. */
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf(); $action=$_POST['action']??'';
    try{
        if($action==='save_customer'){
            $id=(int)($_POST['id']??0);$data=[
                'customer_name'=>trim($_POST['customer_name']??''),'address'=>trim($_POST['address']??''),
                'pincode'=>trim($_POST['pincode']??''),'city'=>trim($_POST['city']??''),
                'district'=>trim($_POST['district']??''),'state'=>trim($_POST['state']??''),'phone'=>trim($_POST['phone']??''),'email'=>trim($_POST['email']??'')
            ];
            if($data['customer_name']==='')throw new Exception('Customer Name required.');
            if($id){$old=db_row($pdo,'customers',$id);if(!$old||!can_edit_row($old,$role))throw new Exception('Edit সময় শেষ হয়েছে বা অনুমতি নেই.');$s=$pdo->prepare('UPDATE customers SET customer_name=?,address=?,pincode=?,city=?,district=?,state=?,phone=?,email=? WHERE id=?');$s->execute([...array_values($data),$id]);log_action($pdo,$uid,'UPDATE','customers',$id);}
            else{$id=insert_row($pdo,'customers',$data);log_action($pdo,$uid,'CREATE','customers',$id);}
            redirect_page('customers');
        }
        if($action==='save_vendor'){
            $id=(int)($_POST['id']??0);$data=['vendor_name'=>trim($_POST['vendor_name']??''),'address'=>trim($_POST['address']??''),'phone'=>trim($_POST['phone']??''),'remarks'=>trim($_POST['remarks']??'')];
            if(!$data['vendor_name'])throw new Exception('Vendor Name required.');
            if($id){$old=db_row($pdo,'vendors',$id);if(!$old||!can_edit_row($old,$role))throw new Exception('Edit time expired.');$s=$pdo->prepare('UPDATE vendors SET vendor_name=?,address=?,phone=?,remarks=? WHERE id=?');$s->execute([...array_values($data),$id]);}
            else{$id=insert_row($pdo,'vendors',$data);}log_action($pdo,$uid,$id?'SAVE':'CREATE','vendors',$id);redirect_page('vendors');
        }
        if($action==='save_courier'){
            $name=trim($_POST['company_name']??'');if(!$name)throw new Exception('Courier Company required.');$s=$pdo->prepare('INSERT INTO courier_companies(company_name) VALUES(?) ON DUPLICATE KEY UPDATE is_active=1');$s->execute([$name]);redirect_page('couriers');
        }
        if($action==='save_booking'){
            $id=(int)($_POST['id']??0);$customerId=(int)($_POST['customer_id']??0);$courierId=(int)($_POST['courier_company_id']??0);$vendorId=(int)($_POST['vendor_id']??0);
            if(!$customerId)throw new Exception('Customer select করো অথবা আগে Customer Details-এ Add করো.');
            $docket=trim($_POST['docket_number']??'');if(!$docket)throw new Exception('Docket Number required.');
            $type=$_POST['rate_type']??'BY_BOX';$weight=(float)($_POST['weight_kg']??0);$boxes=(int)($_POST['total_box']??0);$rate=(float)($_POST['customer_rate']??0);
            $amount=isset($_POST['total_amount'])?(float)$_POST['total_amount']:0;if($amount<=0)$amount=$type==='BY_BOX'?$boxes*$rate:($type==='BY_WEIGHT'?$weight*$rate:(float)($_POST['manual_amount']??0));
            $data=['customer_id'=>$customerId,'courier_company_id'=>$courierId?:null,'vendor_id'=>$vendorId?:null,'receiver_name'=>trim($_POST['receiver_name']??''),'docket_number'=>$docket,'booking_date'=>$_POST['booking_date']?:date('Y-m-d'),'destination'=>trim($_POST['destination']??''),'pincode'=>trim($_POST['pincode']??''),'city'=>trim($_POST['city']??''),'district'=>trim($_POST['district']??''),'state'=>trim($_POST['state']??''),'rate_type'=>$type,'weight_kg'=>$weight,'total_box'=>$boxes,'customer_rate'=>$rate,'total_amount'=>$amount,'vendor_amount'=>(float)($_POST['vendor_amount']??0),'remarks'=>trim($_POST['remarks']??'')];
            $pdo->beginTransaction();
            if($id){$old=db_row($pdo,'bookings',$id);if(!$old||!can_edit_row($old,$role))throw new Exception('এই Booking-এর ৭ দিনের Edit সময় শেষ।');$s=$pdo->prepare('UPDATE bookings SET customer_id=?,courier_company_id=?,vendor_id=?,receiver_name=?,docket_number=?,booking_date=?,destination=?,pincode=?,city=?,district=?,state=?,rate_type=?,weight_kg=?,total_box=?,customer_rate=?,total_amount=?,vendor_amount=?,remarks=? WHERE id=?');$s->execute([...array_values($data),$id]);$s=$pdo->prepare("UPDATE customer_ledger_entries SET debit=?,entry_date=?,customer_id=? WHERE booking_id=? AND entry_type='BOOKING_DUE'");$s->execute([$amount,$data['booking_date'],$customerId,$id]);$s=$pdo->prepare('SELECT id FROM vendor_work WHERE booking_id=? LIMIT 1');$s->execute([$id]);$vw=$s->fetchColumn();if($vw){if($vendorId && $data['vendor_amount']>0){$s=$pdo->prepare('UPDATE vendor_work SET vendor_id=?,customer_id=?,work_date=?,docket_number=?,weight_kg=?,total_box=?,destination=?,total_amount=? WHERE id=?');$s->execute([$vendorId,$customerId,$data['booking_date'],$docket,$weight,$boxes,$data['destination'],$data['vendor_amount'],$vw]);$s=$pdo->prepare("UPDATE vendor_ledger_entries SET vendor_id=?,debit=?,entry_date=? WHERE vendor_work_id=? AND entry_type='VENDOR_WORK'");$s->execute([$vendorId,$data['vendor_amount'],$data['booking_date'],$vw]);}else{$pdo->prepare('DELETE FROM vendor_ledger_entries WHERE vendor_work_id=?')->execute([$vw]);$pdo->prepare('DELETE FROM vendor_work WHERE id=?')->execute([$vw]);}}elseif($vendorId && $data['vendor_amount']>0){$vw=insert_row($pdo,'vendor_work',['booking_id'=>$id,'vendor_id'=>$vendorId,'customer_id'=>$customerId,'docket_number'=>$docket,'work_date'=>$data['booking_date'],'receiver_name'=>$data['receiver_name'],'total_box'=>$boxes,'weight_kg'=>$weight,'destination'=>$data['destination'],'rate_type'=>'MANUAL','manual_amount'=>$data['vendor_amount'],'total_amount'=>$data['vendor_amount'],'created_by'=>$uid]);insert_row($pdo,'vendor_ledger_entries',['vendor_id'=>$vendorId,'vendor_work_id'=>$vw,'entry_date'=>$data['booking_date'],'entry_type'=>'VENDOR_WORK','debit'=>$data['vendor_amount'],'credit'=>0,'remarks'=>'Booking vendor work']);}}
            else{$data['created_by']=$uid;$id=insert_row($pdo,'bookings',$data);insert_row($pdo,'customer_ledger_entries',['customer_id'=>$customerId,'booking_id'=>$id,'entry_date'=>$data['booking_date'],'entry_type'=>'BOOKING_DUE','debit'=>$amount,'credit'=>0,'remarks'=>'Booking due created']);if($vendorId && $data['vendor_amount']>0){$vw=insert_row($pdo,'vendor_work',['booking_id'=>$id,'vendor_id'=>$vendorId,'customer_id'=>$customerId,'docket_number'=>$docket,'work_date'=>$data['booking_date'],'receiver_name'=>$data['receiver_name'],'total_box'=>$boxes,'weight_kg'=>$weight,'destination'=>$data['destination'],'rate_type'=>'MANUAL','manual_amount'=>$data['vendor_amount'],'total_amount'=>$data['vendor_amount'],'created_by'=>$uid]);insert_row($pdo,'vendor_ledger_entries',['vendor_id'=>$vendorId,'vendor_work_id'=>$vw,'entry_date'=>$data['booking_date'],'entry_type'=>'VENDOR_WORK','debit'=>$data['vendor_amount'],'credit'=>0,'remarks'=>'Booking vendor work']);}}
            $pdo->commit();log_action($pdo,$uid,$id?'SAVE':'CREATE','bookings',$id,'Docket '.$docket);redirect_page('bookings');
        }
        if($action==='save_rate'){
            $id=(int)($_POST['id']??0);$data=['customer_id'=>(int)$_POST['customer_id'],'pincode'=>trim($_POST['pincode']??''),'city'=>trim($_POST['city']??''),'destination'=>trim($_POST['destination']??''),'rate_type'=>$_POST['rate_type']??'BY_BOX','rate'=>(float)($_POST['rate']??0),'amount'=>(float)($_POST['amount']??0)];if(!$data['customer_id']||!$data['destination'])throw new Exception('Customer and Destination required.');if($id){$old=db_row($pdo,'rate_master',$id);if(!$old||!can_edit_row($old,$role))throw new Exception('Edit time expired.');$s=$pdo->prepare('UPDATE rate_master SET customer_id=?,pincode=?,city=?,destination=?,rate_type=?,rate=?,amount=? WHERE id=?');$s->execute([...array_values($data),$id]);}else{$data['created_by']=$uid;$id=insert_row($pdo,'rate_master',$data);}redirect_page('rates');
        }
        if($action==='save_receipt'){
            $data=['receipt_date'=>$_POST['receipt_date']?:date('Y-m-d'),'customer_id'=>(int)$_POST['customer_id'],'transaction_type'=>$_POST['transaction_type']==='LESS'?'LESS':'ADD','amount'=>(float)$_POST['amount'],'received_in_account_id'=>(int)$_POST['received_in_account_id'],'payment_mode'=>$_POST['payment_mode']==='ONLINE'?'ONLINE':'CASH','remarks'=>trim($_POST['remarks']??'')];if(!$data['customer_id']||$data['amount']<=0)throw new Exception('Customer এবং Amount সঠিকভাবে দাও.');$pdo->beginTransaction();$data['created_by']=$uid;$rid=insert_row($pdo,'customer_receipts',$data);$debit=$data['transaction_type']==='LESS'?$data['amount']:0;$credit=$data['transaction_type']==='ADD'?$data['amount']:0;insert_row($pdo,'customer_ledger_entries',['customer_id'=>$data['customer_id'],'receipt_id'=>$rid,'entry_date'=>$data['receipt_date'],'entry_type'=>$data['transaction_type']==='ADD'?'PAYMENT_RECEIVED':'PAYMENT_REVERSED','debit'=>$debit,'credit'=>$credit,'remarks'=>$data['remarks']]);insert_row($pdo,'cash_transactions',['transaction_date'=>$data['receipt_date'],'account_id'=>$data['received_in_account_id'],'transaction_type'=>$data['transaction_type']==='ADD'?'DEPOSIT':'EXPENSE','direction'=>$data['transaction_type']==='ADD'?'IN':'OUT','amount'=>$data['amount'],'source_type'=>'customer_receipts','source_id'=>$rid,'remarks'=>'Customer payment received']);$pdo->commit();redirect_page('receipts');
        }
        if($action==='save_vendor_work'){
            $vendorId=(int)($_POST['vendor_id']??0);$customerId=(int)($_POST['customer_id']??0);$type=$_POST['rate_type']??'MANUAL';$rate=(float)($_POST['rate']??0);$amount=(float)($_POST['manual_amount']??0);if($type==='BY_BOX')$amount=(int)($_POST['total_box']??0)*$rate;elseif($type==='BY_WEIGHT')$amount=(float)($_POST['weight_kg']??0)*$rate;if(!$vendorId||$amount<=0)throw new Exception('Vendor এবং Work Amount সঠিকভাবে দাও.');$data=['vendor_id'=>$vendorId,'customer_id'=>$customerId?:null,'docket_number'=>trim($_POST['docket_number']??''),'work_date'=>$_POST['work_date']?:date('Y-m-d'),'receiver_name'=>trim($_POST['receiver_name']??''),'total_box'=>(int)($_POST['total_box']??0),'weight_kg'=>(float)($_POST['weight_kg']??0),'destination'=>trim($_POST['destination']??''),'rate_type'=>$type,'rate'=>$rate,'manual_amount'=>(float)($_POST['manual_amount']??0),'total_amount'=>$amount,'created_by'=>$uid];$pdo->beginTransaction();$wid=insert_row($pdo,'vendor_work',$data);insert_row($pdo,'vendor_ledger_entries',['vendor_id'=>$vendorId,'vendor_work_id'=>$wid,'entry_date'=>$data['work_date'],'entry_type'=>'VENDOR_WORK','debit'=>$amount,'credit'=>0,'remarks'=>'Manual vendor work']);$pdo->commit();redirect_page('vendor-work');
        }
        if($action==='save_vendor_payment'){
            $data=['payment_date'=>$_POST['payment_date']?:date('Y-m-d'),'vendor_id'=>(int)$_POST['vendor_id'],'payment_amount'=>(float)$_POST['payment_amount'],'paid_from_account_id'=>(int)$_POST['paid_from_account_id'],'payment_mode'=>$_POST['payment_mode']==='ONLINE'?'ONLINE':'CASH','remarks'=>trim($_POST['remarks']??'')];if(!$data['vendor_id']||$data['payment_amount']<=0)throw new Exception('Vendor এবং Payment Amount দাও.');$pdo->beginTransaction();$data['created_by']=$uid;$pid=insert_row($pdo,'vendor_payments',$data);insert_row($pdo,'vendor_ledger_entries',['vendor_id'=>$data['vendor_id'],'vendor_payment_id'=>$pid,'entry_date'=>$data['payment_date'],'entry_type'=>'VENDOR_PAYMENT','debit'=>0,'credit'=>$data['payment_amount'],'remarks'=>$data['remarks']]);insert_row($pdo,'cash_transactions',['transaction_date'=>$data['payment_date'],'account_id'=>$data['paid_from_account_id'],'transaction_type'=>'VENDOR_PAYMENT','direction'=>'OUT','amount'=>$data['payment_amount'],'source_type'=>'vendor_payments','source_id'=>$pid,'remarks'=>'Vendor payment']);$pdo->commit();redirect_page('vendor-payments');
        }
        if($action==='save_expense'){
            $paid=$_POST['paid_by']??'PAYEL_CASH';$account=null;if($paid!=='VENDOR'){$account=(int)($_POST['cash_account_id']??0);if(!$account){$s=$pdo->prepare('SELECT id FROM cash_accounts WHERE holder_name=? AND account_type=? LIMIT 1');$holder=str_contains($paid,'BABU')?'Babu Shaw':'Payel Shaw';$type=str_contains($paid,'ONLINE')?'BANK':'CASH';$s->execute([$holder,$type]);$account=(int)$s->fetchColumn();}}
            $data=['expense_date'=>$_POST['expense_date']?:date('Y-m-d'),'category'=>$_POST['category'],'amount'=>(float)$_POST['amount'],'paid_by'=>$paid,'cash_account_id'=>$account?:null,'remarks'=>trim($_POST['remarks']??''),'created_by'=>$uid];$pdo->beginTransaction();$eid=insert_row($pdo,'expenses',$data);if($paid!=='VENDOR' && $account){insert_row($pdo,'cash_transactions',['transaction_date'=>$data['expense_date'],'account_id'=>$account,'transaction_type'=>'EXPENSE','direction'=>'OUT','amount'=>$data['amount'],'source_type'=>'expenses','source_id'=>$eid,'remarks'=>$data['remarks']]);}$pdo->commit();redirect_page('expenses');
        }
        if($action==='save_cash'){
            $account=(int)$_POST['account_id'];$type=$_POST['transaction_type'];$amount=(float)$_POST['amount'];if(!$account||$amount<=0)throw new Exception('Account এবং Amount required.');$pdo->beginTransaction();$ref=($type==='TRANSFER')?'TR-'.bin2hex(random_bytes(5)):null;if($type==='TRANSFER'){$to=(int)($_POST['transfer_to']??0);if(!$to||$to===$account)throw new Exception('Different Transfer To account নির্বাচন করো.');insert_row($pdo,'cash_transactions',['transaction_date'=>$_POST['transaction_date']?:date('Y-m-d'),'account_id'=>$account,'transaction_type'=>'TRANSFER_OUT','direction'=>'OUT','amount'=>$amount,'transfer_reference'=>$ref,'source_type'=>'manual_transfer','remarks'=>trim($_POST['remarks']??'')]);insert_row($pdo,'cash_transactions',['transaction_date'=>$_POST['transaction_date']?:date('Y-m-d'),'account_id'=>$to,'transaction_type'=>'TRANSFER_IN','direction'=>'IN','amount'=>$amount,'transfer_reference'=>$ref,'source_type'=>'manual_transfer','remarks'=>trim($_POST['remarks']??'')]);}else{$direction=in_array($type,['OPENING_BALANCE','DEPOSIT'])?'IN':'OUT';insert_row($pdo,'cash_transactions',['transaction_date'=>$_POST['transaction_date']?:date('Y-m-d'),'account_id'=>$account,'transaction_type'=>$type,'direction'=>$direction,'amount'=>$amount,'source_type'=>'manual','remarks'=>trim($_POST['remarks']??'')]);}$pdo->commit();redirect_page('cash');
        }
        if($action==='save_tracking'){
            $booking=(int)$_POST['booking_id'];$s=$pdo->prepare("SELECT COUNT(*) FROM shipment_tracking WHERE booking_id=? AND status='DELIVERED'");$s->execute([$booking]);if((int)$s->fetchColumn()>0)throw new Exception('Delivered docket is permanently locked.');$status=$_POST['status'];$allowed=['NOT_PICK','IN_TRANSIT','REACHED_DESTINATION_BRANCH','OUT_FOR_DELIVERY','RTO','REATTEMPT','DELIVERED'];if(!in_array($status,$allowed,true))throw new Exception('Invalid status.');insert_row($pdo,'shipment_tracking',['booking_id'=>$booking,'tracking_date'=>($_POST['tracking_date']?:date('Y-m-d')).' '.date('H:i:s'),'status'=>$status,'location'=>trim($_POST['location']??''),'remarks'=>trim($_POST['remarks']??''),'created_by'=>$uid]);redirect_page('tracking');
        }
        if($action==='save_pickup'){
            $cid=(int)($_POST['customer_id']??0);$data=['customer_id'=>$cid?:null,'pickup_date'=>$_POST['pickup_date']?:date('Y-m-d'),'pickup_address'=>trim($_POST['pickup_address']??''),'pincode'=>trim($_POST['pincode']??''),'vehicle'=>trim($_POST['vehicle']??''),'pickup_fare'=>(float)($_POST['pickup_fare']??0),'pickup_status'=>$_POST['pickup_status']??'PENDING','remarks'=>trim($_POST['remarks']??''),'created_by'=>$uid];insert_row($pdo,'pickups',$data);redirect_page('pickups');
        }
        if($action==='delete'){
            $table=$_POST['table']??'';$id=(int)($_POST['id']??0);$row=db_row($pdo,$table,$id);if(!$row||!can_edit_row($row,$role))throw new Exception('৭ দিনের Edit/Delete সময় শেষ হয়েছে বা অনুমতি নেই.');$pdo->beginTransaction();
            if($table==='bookings'){$pdo->prepare('DELETE FROM customer_ledger_entries WHERE booking_id=?')->execute([$id]);$s=$pdo->prepare('SELECT id FROM vendor_work WHERE booking_id=?');$s->execute([$id]);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $vw){$pdo->prepare('DELETE FROM vendor_ledger_entries WHERE vendor_work_id=?')->execute([$vw]);}$pdo->prepare('DELETE FROM vendor_work WHERE booking_id=?')->execute([$id]);}
            if($table==='customer_receipts'){$pdo->prepare('DELETE FROM customer_ledger_entries WHERE receipt_id=?')->execute([$id]);$pdo->prepare("DELETE FROM cash_transactions WHERE source_type='customer_receipts' AND source_id=?")->execute([$id]);}
            if($table==='vendor_payments'){$pdo->prepare('DELETE FROM vendor_ledger_entries WHERE vendor_payment_id=?')->execute([$id]);$pdo->prepare("DELETE FROM cash_transactions WHERE source_type='vendor_payments' AND source_id=?")->execute([$id]);}
            if($table==='vendor_work')$pdo->prepare('DELETE FROM vendor_ledger_entries WHERE vendor_work_id=?')->execute([$id]);
            if($table==='expenses')$pdo->prepare("DELETE FROM cash_transactions WHERE source_type='expenses' AND source_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);$pdo->commit();log_action($pdo,$uid,'DELETE',$table,$id);redirect_page($_POST['return_page']??'dashboard');
        }
        if($action==='update_generic'){
            $table=$_POST['table']??'';$id=(int)($_POST['id']??0);$row=db_row($pdo,$table,$id);if(!$row||!can_edit_row($row,$role))throw new Exception('৭ দিনের Edit সময় শেষ হয়েছে বা অনুমতি নেই.');
            $map=[
              'vendors'=>['vendor_name','address','phone','remarks'],
              'courier_companies'=>['company_name'],
              'bookings'=>['customer_id','courier_company_id','vendor_id','receiver_name','docket_number','booking_date','destination','pincode','city','district','state','rate_type','weight_kg','total_box','customer_rate','total_amount','vendor_amount','remarks'],
              'rate_master'=>['customer_id','pincode','city','destination','rate_type','rate','amount'],
              'customer_receipts'=>['receipt_date','customer_id','transaction_type','amount','received_in_account_id','payment_mode','remarks'],
              'vendor_work'=>['vendor_id','customer_id','docket_number','work_date','receiver_name','total_box','weight_kg','destination','rate_type','rate','manual_amount','total_amount'],
              'vendor_payments'=>['payment_date','vendor_id','payment_amount','paid_from_account_id','payment_mode','remarks'],
              'expenses'=>['expense_date','category','amount','paid_by','cash_account_id','remarks'],
              'cash_transactions'=>['transaction_date','account_id','transaction_type','direction','amount','remarks'],
              'shipment_tracking'=>['status','location','remarks'],
              'pickups'=>['customer_id','pickup_date','pickup_address','pincode','vehicle','pickup_fare','pickup_status','remarks'],
              'customers'=>['customer_name','address','pincode','city','district','state','phone','email']
            ];
            if(!isset($map[$table]))throw new Exception('Edit not supported for this record.');$sets=[];$vals=[];foreach($map[$table] as $col){if(array_key_exists($col,$_POST)){$sets[]="`$col`=?";$vals[]=($_POST[$col]===''?null:$_POST[$col]);}}if(!$sets)throw new Exception('No changes supplied.');$vals[]=$id;$pdo->prepare("UPDATE `$table` SET ".implode(',',$sets).' WHERE id=?')->execute($vals);log_action($pdo,$uid,'UPDATE',$table,$id);redirect_page($_POST['return_page']??'dashboard');
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

$page=$_GET['page']??'dashboard';
if($page==='print-customer-all' || $page==='print-vendor-all'){$printAll=true;}else{$printAll=false;}
$nav=[
'dashboard'=>'Dashboard','customers'=>'Customer Details','bookings'=>'All Bookings','new-booking'=>'New Booking','customer-ledger'=>'Customer Ledger','vendors'=>'Vendor Details','vendor-work'=>'Vendor Work','vendor-ledger'=>'Vendor Ledger','vendor-payments'=>'Vendor Payment','receipts'=>'Receipts','expenses'=>'Expenses','tracking'=>'Shipment Tracking','cash'=>'Cash Accounts','rates'=>'Rate Master','pickups'=>'Pickups','reports'=>'Reports & Backup','couriers'=>'Booking By / Courier Companies'
];
$title=$nav[$page]??'BPS LOGISTICS';
$editTable=$_GET['edit_table']??'';$editId=(int)($_GET['edit']??0);$editRow=$editTable&&$editId?db_row($pdo,$editTable,$editId):null;
$from=$_GET['from']??'';$to=$_GET['to']??'';$search=trim($_GET['q']??'');
function date_filter_sql($column,$from,$to,&$params){$sql='';if($from){$sql.=" AND DATE($column)>=?";$params[]=$from;}if($to){$sql.=" AND DATE($column)<=?";$params[]=$to;}return $sql;}
function fetch_all($pdo,$sql,$params=[]){$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchAll();}
function action_buttons($row,$table,$return,$role){$editable=can_edit_row($row,$role);$out='';if($editable){$out.='<a class="btn sm secondary" href="index.php?page='.h($return).'&edit_table='.h($table).'&edit='.(int)$row['id'].'">Edit</a> ';$out.='<form class="inline" method="post" onsubmit="return confirm(\'Delete this entry?\')"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="table" value="'.h($table).'"><input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="return_page" value="'.h($return).'"><button class="btn sm danger">Delete</button></form>';}else{$out.='<span class="lock">7-Day Lock</span>';}return $out;}
function module_form($page,$pdo,$editRow,$editTable){
    $val=function($key,$default='')use($editRow){return h($editRow[$key]??$default);};$isEdit=(bool)$editRow;
    $customers=get_options($pdo,'SELECT id,customer_name FROM customers WHERE is_active=1 ORDER BY customer_name');
    $vendors=get_options($pdo,'SELECT id,vendor_name FROM vendors WHERE is_active=1 ORDER BY vendor_name');
    $couriers=get_options($pdo,'SELECT id,company_name FROM courier_companies WHERE is_active=1 ORDER BY company_name');
    $accounts=get_options($pdo,'SELECT id,account_name FROM cash_accounts WHERE is_active=1 ORDER BY id');
    $rates=get_options($pdo,'SELECT id,customer_name FROM customers WHERE is_active=1 ORDER BY customer_name');
    $select=function($name,$options,$selected='',$placeholder='Select') { $html='<select name="'.h($name).'">';$html.='<option value="">'.$placeholder.'</option>';foreach($options as $o){$id=$o['id'];$label=$o['label'];$html.='<option value="'.(int)$id.'" '.((string)$selected===(string)$id?'selected':'').'>'.h($label).'</option>';}$html.='</select>';return $html;};
    $field=function($label,$name,$type='text',$default='',$required=false)use($val){$v=$val($name,$default);$req=$required?'required':'';return '<label>'.h($label).'<input type="'.h($type).'" name="'.h($name).'" value="'.$v.'" '.$req.'></label>';};
    $start='<form method="post" class="dataform"><input type="hidden" name="csrf" value="'.h(csrf()).'">';$end='<div class="form-actions"><button class="btn">'.($isEdit?'Update':'Save Entry').'</button></div></form>';
    $hidden='<input type="hidden" name="id" value="'.(int)($editRow['id']??0).'">';
    if($page==='customers')return $start.'<input type="hidden" name="action" value="save_customer">'.$hidden.'<div class="grid">'.$field('Customer Name','customer_name','text','',true).$field('Address','address').$field('PIN Code','pincode').$field('City','city').$field('District','district').$field('State','state').$field('Phone Number','phone').$field('Email','email','email').'</div>'.$end;
    if($page==='vendors')return $start.'<input type="hidden" name="action" value="save_vendor">'.$hidden.'<div class="grid">'.$field('Vendor Name','vendor_name','text','',true).$field('Phone','phone').'<label>Address<textarea name="address">'.$val('address').'</textarea></label><label>Remarks<textarea name="remarks">'.$val('remarks').'</textarea></label></div>'.$end;
    if($page==='couriers')return $start.'<input type="hidden" name="action" value="save_courier"><div class="grid">'.$field('New Booking By / Courier Company','company_name','text','',true).'</div>'.$end;
    if($page==='new-booking'){
      $courierOpts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['company_name']],$couriers);$vendorOpts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['vendor_name']],$vendors);
      $customerSel='<label>Customer Name (type 3 letters)<input id="customerSearch" list="customerOptions" autocomplete="off" placeholder="Type e.g. BAB" value=""><datalist id="customerOptions"></datalist><input type="hidden" name="customer_id" id="customerId" value="'.(int)($editRow['customer_id']??0).'"><small id="customerHint">Suggestion নির্বাচন করলে customer details auto-fill হবে.</small></label>';
      $courierSel='<label>Booking By / Courier Company>'.$select('courier_company_id',$courierOpts,$val('courier_company_id'),'Select courier').'</label>';
      $vendorSel='<label>Actual Vendor Name>'.$select('vendor_id',$vendorOpts,$val('vendor_id'),'Select your vendor').'</label>';
      return $start.'<input type="hidden" name="action" value="save_booking">'.$hidden.'<div class="grid">'.$customerSel.$field('Receiver Name','receiver_name').$field('Docket Number','docket_number','text','',true).$field('Booking Date','booking_date','date',date('Y-m-d')).$courierSel.$vendorSel.$field('Destination','destination').$field('PIN Code','pincode').$field('City','city').$field('District','district').$field('State','state').$field('Receiver Address (display only)','receiver_address').$field('Receiver Phone (display only)','receiver_phone').'<label>Rate Type<select name="rate_type" id="rateType"><option value="BY_BOX">By Box</option><option value="BY_WEIGHT">By Weight</option><option value="MANUAL">Manual</option></select></label>'.$field('Weight KG','weight_kg','number','0').$field('Total Box','total_box','number','0').$field('Customer Rate','customer_rate','number','0').$field('Total Amount','total_amount','number','0').$field('Vendor Amount','vendor_amount','number','0').'</div><div class="form-actions"><button class="btn">'.($isEdit?'Update Booking':'Save Booking').'</button></div></form>';
    }
    if($page==='vendor-work'){
      $vopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['vendor_name']],$vendors);$copts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['customer_name']],$customers);
      return $start.'<input type="hidden" name="action" value="save_vendor_work"><div class="grid"><label>Vendor Name>'.$select('vendor_id',$vopts,'','Select vendor').'</label><label>Customer Name>'.$select('customer_id',$copts,'','Select customer').'</label>'.$field('Docket Number','docket_number').$field('Work Date','work_date','date',date('Y-m-d')).$field('Receiver Name','receiver_name').$field('Total Box','total_box','number','0').$field('Weight KG','weight_kg','number','0').$field('Destination','destination').'<label>Rate Type><select name="rate_type"><option value="BY_BOX">By Box</option><option value="BY_WEIGHT">By Weight</option><option value="MANUAL">Manual</option></select></label>'.$field('Rate','rate','number','0').$field('Manual Amount','manual_amount','number','0').$field('Total Amount','total_amount','number','0').'</div>'.$end;
    }
    if($page==='rates'){
      $customerOpts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['customer_name']],$rates);
      return $start.'<input type="hidden" name="action" value="save_rate">'.$hidden.'<div class="grid"><label>Customer>'.$select('customer_id',$customerOpts,$val('customer_id'),'Select customer').'</label>'.$field('PIN Code','pincode').$field('City','city').$field('Destination','destination','text','',true).'<label>Rate Type><select name="rate_type"><option value="BY_BOX">By Box</option><option value="BY_WEIGHT">By Weight</option><option value="MANUAL">Manual</option></select></label>'.$field('Rate','rate','number','0').$field('Amount (optional)','amount','number','0').'</div>'.$end;
    }
    if($page==='receipts'){
      $copts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['customer_name']],$customers);$aopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['account_name']],$accounts);
      return $start.'<input type="hidden" name="action" value="save_receipt"><div class="grid">'.$field('Receipt Date','receipt_date','date',date('Y-m-d')).'<label>Customer Name>'.$select('customer_id',$copts,'','Select customer').'</label><label>ADD / LESS><select name="transaction_type"><option value="ADD">ADD - Payment received</option><option value="LESS">LESS - Reverse payment</option></select></label>'.$field('Amount','amount','number','0',true).'<label>Received In Account>'.$select('received_in_account_id',$aopts,'','Select account').'</label><label>Payment Mode><select name="payment_mode"><option value="CASH">Cash</option><option value="ONLINE">Online</option></select></label><label>Remarks<textarea name="remarks"></textarea></label></div><p class="hint">Receipt-এ Docket Number নেই। Payment history-তে Customer Name এবং কোন Account-এ টাকা এসেছে দেখা যাবে.</p>'.$end;
    }
    if($page==='vendor-payments'){
      $vopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['vendor_name']],$vendors);$aopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['account_name']],$accounts);
      return $start.'<input type="hidden" name="action" value="save_vendor_payment"><div class="grid">'.$field('Payment Date','payment_date','date',date('Y-m-d')).'<label>Vendor Name>'.$select('vendor_id',$vopts,'','Select vendor').'</label>'.$field('Payment Amount','payment_amount','number','0',true).'<label>Paid From Account>'.$select('paid_from_account_id',$aopts,'','Select account').'</label><label>Payment Mode><select name="payment_mode"><option value="CASH">Cash</option><option value="ONLINE">Online</option></select></label><label>Remarks<textarea name="remarks"></textarea></label></div><p class="hint">Vendor Payment-এ Docket Number রাখা হয়নি.</p>'.$end;
    }
    if($page==='expenses'){
      $aopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['account_name']],$accounts);
      return $start.'<input type="hidden" name="action" value="save_expense"><div class="grid">'.$field('Expense Date','expense_date','date',date('Y-m-d')).'<label>Category><select name="category"><option value="FUEL">Fuel</option><option value="SALARY">Salary</option><option value="REPAIR">Repair</option><option value="RENT">Rent</option><option value="VEHICLE_FARE">Vehicle Fare</option><option value="OTHER">Other</option></select></label>'.$field('Amount','amount','number','0',true).'<label>Paid By><select name="paid_by"><option value="PAYEL_CASH">Payel Shaw (Cash)</option><option value="PAYEL_ONLINE">Payel Shaw (Online)</option><option value="BABU_CASH">Babu Shaw (Cash)</option><option value="BABU_ONLINE">Babu Shaw (Online)</option><option value="VENDOR">Paid by Vendor</option></select></label><label>Cash Account (override optional)>'.$select('cash_account_id',$aopts,'','Auto by Paid By').'</label><label>Remarks<textarea name="remarks"></textarea></label></div>'.$end;
    }
    if($page==='cash'){
      $aopts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['account_name']],$accounts);
      return $start.'<input type="hidden" name="action" value="save_cash"><div class="grid">'.$field('Transaction Date','transaction_date','date',date('Y-m-d')).'<label>Account>'.$select('account_id',$aopts,'','Select account').'</label><label>Transaction Type><select name="transaction_type"><option value="OPENING_BALANCE">Opening Balance</option><option value="DEPOSIT">Deposit</option><option value="EXPENSE">Expense</option><option value="TRANSFER">Transfer</option></select></label>'.$field('Amount','amount','number','0',true).'<label>Transfer To>'.$select('transfer_to',$aopts,'','Select destination account').'</label><label>Remarks<textarea name="remarks"></textarea></label></div>'.$end;
    }
    if($page==='tracking'){
      $book=get_options($pdo,'SELECT id,docket_number FROM bookings ORDER BY id DESC LIMIT 500');$opts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['docket_number']],$book);
      return $start.'<input type="hidden" name="action" value="save_tracking"><div class="grid"><label>Docket Number>'.$select('booking_id',$opts,'','Select docket').'</label>'.$field('Date','tracking_date','date',date('Y-m-d')).'<label>Status><select name="status"><option value="NOT_PICK">Not Pick</option><option value="IN_TRANSIT">In Transit</option><option value="REACHED_DESTINATION_BRANCH">Reached Destination Branch</option><option value="OUT_FOR_DELIVERY">Out for Delivery</option><option value="RTO">RTO</option><option value="REATTEMPT">Reattempt</option><option value="DELIVERED">Delivered</option></select></label>'.$field('Location','location').'<label>Remarks<textarea name="remarks"></textarea></label></div>'.$end;
    }
    if($page==='pickups'){
      $copts=array_map(fn($r)=>['id'=>$r['id'],'label'=>$r['customer_name']],$customers);
      return $start.'<input type="hidden" name="action" value="save_pickup"><div class="grid">'.$field('Pickup Date','pickup_date','date',date('Y-m-d')).'<label>Customer>'.$select('customer_id',$copts,'','Select customer').'</label>'.$field('Pickup Address','pickup_address').$field('PIN Code','pincode').$field('Vehicle','vehicle').$field('Pickup Fare','pickup_fare','number','0').'<label>Pickup Status><select name="pickup_status"><option value="PENDING">Pending</option><option value="PICKED">Picked</option><option value="CANCELLED">Cancelled</option></select></label><label>Remarks<textarea name="remarks"></textarea></label></div>'.$end;
    }
    return '<p class="hint">এই পেজের জন্য Entry form নেই।</p>';
}

function list_rows($pdo,$page,$from,$to,$search){$params=[];$q=trim($search);$like='%'.$q.'%';
 switch($page){
 case 'customers':$sql='SELECT * FROM customers WHERE 1=1';$col='created_at';break;
 case 'vendors':$sql='SELECT * FROM vendors WHERE 1=1';$col='created_at';break;
 case 'couriers':$sql='SELECT * FROM courier_companies WHERE 1=1';$col='created_at';break;
 case 'bookings':$sql="SELECT b.*,c.customer_name,cc.company_name,v.vendor_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN courier_companies cc ON cc.id=b.courier_company_id LEFT JOIN vendors v ON v.id=b.vendor_id WHERE 1=1";$col='b.booking_date';break;
 case 'rates':$sql='SELECT r.*,c.customer_name FROM rate_master r JOIN customers c ON c.id=r.customer_id WHERE 1=1';$col='r.created_at';break;
 case 'receipts':$sql='SELECT r.*,c.customer_name,a.account_name,u.username FROM customer_receipts r JOIN customers c ON c.id=r.customer_id JOIN cash_accounts a ON a.id=r.received_in_account_id LEFT JOIN app_users u ON u.id=r.created_by WHERE 1=1';$col='r.receipt_date';break;
 case 'vendor-payments':$sql='SELECT p.*,v.vendor_name,a.account_name FROM vendor_payments p JOIN vendors v ON v.id=p.vendor_id JOIN cash_accounts a ON a.id=p.paid_from_account_id WHERE 1=1';$col='p.payment_date';break;
 case 'vendor-work':$sql='SELECT w.*,v.vendor_name,c.customer_name FROM vendor_work w JOIN vendors v ON v.id=w.vendor_id LEFT JOIN customers c ON c.id=w.customer_id WHERE 1=1';$col='w.work_date';break;
 case 'expenses':$sql='SELECT e.*,a.account_name FROM expenses e LEFT JOIN cash_accounts a ON a.id=e.cash_account_id WHERE 1=1';$col='e.expense_date';break;
 case 'cash':$sql='SELECT t.*,a.account_name FROM cash_transactions t JOIN cash_accounts a ON a.id=t.account_id WHERE 1=1';$col='t.transaction_date';break;
 case 'tracking':$sql='SELECT t.*,b.docket_number FROM shipment_tracking t JOIN bookings b ON b.id=t.booking_id WHERE 1=1';$col='DATE(t.tracking_date)';break;
 case 'pickups':$sql='SELECT p.*,c.customer_name FROM pickups p LEFT JOIN customers c ON c.id=p.customer_id WHERE 1=1';$col='p.pickup_date';break;
 default:return [];
 }
 if($from)$sql.=' AND '.$col.'>=?';if($from)$params[]=$from;
 if($to)$sql.=' AND '.$col.'<=?';if($to)$params[]=$to;
 if($q){$searchCols=[
 'customers'=>'customer_name','vendors'=>'vendor_name','couriers'=>'company_name',
 'bookings'=>"CONCAT_WS(' ',b.docket_number,c.customer_name,b.receiver_name,b.destination,cc.company_name,v.vendor_name)",
 'rates'=>"CONCAT_WS(' ',c.customer_name,r.destination,r.pincode,r.city)",
 'receipts'=>"CONCAT_WS(' ',c.customer_name,r.remarks,a.account_name)",
 'vendor-payments'=>"CONCAT_WS(' ',v.vendor_name,p.remarks,a.account_name)",
 'vendor-work'=>"CONCAT_WS(' ',v.vendor_name,c.customer_name,w.docket_number,w.destination)",
 'expenses'=>"CONCAT_WS(' ',e.category,e.paid_by,e.remarks)",
 'cash'=>"CONCAT_WS(' ',a.account_name,t.remarks,t.transaction_type)",
 'tracking'=>"CONCAT_WS(' ',b.docket_number,t.status,t.location,t.remarks)",
 'pickups'=>"CONCAT_WS(' ',c.customer_name,p.pincode,p.vehicle,p.pickup_address)"
 ];$sql.=' AND '.$searchCols[$page].' LIKE ?';$params[]=$like;}
 $sql.=' ORDER BY '.$col.' DESC LIMIT 1000';return fetch_all($pdo,$sql,$params);
}
function data_table($rows,$headers,$table,$page,$role,$idField='id'){
 if(!$rows)return '<p class="muted">No records found. উপরের Form থেকে নতুন Entry যোগ করো।</p>';
 $out='<div class="table-wrap"><table><thead><tr>';foreach($headers as $key=>$label)$out.='<th>'.h($label).'</th>';$out.='<th>Actions</th></tr></thead><tbody>';
 foreach($rows as $row){$out.='<tr>';foreach($headers as $key=>$label){$value=$row[$key]??'';$out.='<td>'.h($value).'</td>';}$out.='<td>'.action_buttons($row,$table,$page,$role).'</td></tr>';}$out.='</tbody></table></div>';return $out;
}
?>
<!doctype html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> | BPS LOGISTICS</title><style>
:root{--nav:#101c36;--blue:#2563eb;--bg:#f3f6fb;--ink:#172033;--muted:#718096;--line:#e2e8f0;--green:#168653;--red:#c43d4c}*{box-sizing:border-box}body{margin:0;background:var(--bg);font:14px Arial,sans-serif;color:var(--ink)}.layout{display:flex;min-height:100vh}.side{width:245px;background:var(--nav);color:#fff;padding:15px 11px;flex-shrink:0}.brand{font-size:20px;font-weight:800;padding:10px}.brand small{display:block;font-size:10px;letter-spacing:2px;color:#aebbd3;margin-top:5px}.nav a{display:block;text-decoration:none;color:#d0d9e8;padding:10px;border-radius:8px;margin:2px 0}.nav a:hover,.nav a.active{background:#293e67;color:#fff}.nav .group{font-size:10px;color:#8092b3;padding:12px 10px 3px;text-transform:uppercase;letter-spacing:1.3px}main{flex:1;min-width:0;padding:22px}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:17px}.top h1{margin:0;font-size:24px}.muted,.hint{color:var(--muted)}.panel,.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:15px;margin-bottom:15px;box-shadow:0 2px 8px #17203308}.cards{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:12px;margin-bottom:15px}.card{margin:0;text-decoration:none;color:var(--ink)}.card small{color:var(--muted)}.card strong{display:block;font-size:22px;margin-top:8px}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.dataform label{display:block;font-size:12px;color:#56647b}.dataform input,.dataform select,.dataform textarea,.filter input,.filter select{display:block;width:100%;margin-top:5px;padding:10px;border:1px solid #d8e0eb;border-radius:7px;background:#fff;color:var(--ink)}.dataform textarea{min-height:65px}.btn{display:inline-block;background:var(--blue);color:white;text-decoration:none;border:0;border-radius:7px;padding:9px 13px;cursor:pointer;font-size:13px}.btn.secondary{background:#e9eef7;color:#26334b}.btn.danger{background:var(--red)}.btn.sm{padding:6px 8px;font-size:11px}.form-actions{margin-top:13px}.inline{display:inline}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px}.filter{display:flex;gap:7px;flex-wrap:wrap;align-items:end}.filter label{font-size:11px;color:#59677e}.filter input{min-width:135px}.filter .search{min-width:190px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;min-width:720px}th,td{text-align:left;padding:9px;border-bottom:1px solid var(--line);white-space:nowrap}th{background:#f7f9fd;color:#64728a;font-size:12px}td{font-size:12px}.lock{font-size:11px;color:#88651a;background:#fff5d9;border-radius:20px;padding:5px 8px}.error{background:#ffebed;color:#9d2535;padding:11px;border-radius:8px;margin-bottom:12px}.notice{background:#fff8e7;border:1px solid #f0dfb1;color:#735b21;padding:11px;border-radius:8px;margin:12px 0}.summary{display:grid;grid-template-columns:repeat(3,minmax(130px,1fr));gap:10px}.summary div{background:#f7f9fd;border:1px solid var(--line);padding:12px;border-radius:8px}.summary small{color:var(--muted)}.summary b{display:block;font-size:20px;margin-top:6px}.click{color:#2563eb;font-weight:bold;text-decoration:underline}.hidden{display:none}.footer{font-size:11px;color:var(--muted);padding:8px}.suggestbox{position:relative}.suggestions{position:absolute;z-index:20;top:100%;left:0;right:0;background:white;border:1px solid #d8e0eb;border-radius:7px;max-height:220px;overflow:auto;box-shadow:0 6px 18px #0002}.suggestions button{display:block;text-align:left;width:100%;padding:9px;border:0;background:white;cursor:pointer}.suggestions button:hover{background:#edf3ff}.customer-details{grid-column:1/-1;background:#f8faff;border:1px dashed #b8c8e5;border-radius:8px;padding:12px}.customer-details b{display:block;margin-bottom:8px}@media(max-width:900px){.side{width:205px}.cards{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:620px){.layout{display:block}.side{width:100%;padding:8px}.brand{padding:7px}.nav{display:flex;overflow:auto;gap:3px}.nav .group{display:none}.nav a{white-space:nowrap}.nav a:not(.active){padding:9px}main{padding:12px}.grid,.summary{grid-template-columns:1fr}.top h1{font-size:20px}}@media print{.side,.no-print,.form-actions,.filter{display:none!important}main{padding:0}.panel,.card{box-shadow:none;border:0}}
</style></head><body><div class="layout"><aside class="side"><div class="brand">BPS LOGISTICS<small>ONLINE MANAGEMENT</small></div><nav class="nav">
<div class="group">Main</div><?php foreach($nav as $key=>$label): ?><a class="<?=($page===$key?'active':'')?>" href="index.php?page=<?=h($key)?>"><?=h($label)?></a><?php endforeach; ?>
</nav></aside><main><div class="top"><div><h1><?=h($title)?></h1><div class="muted">Logged in: <b><?=h($username)?></b> (<?=h($role)?>)</div></div><div class="no-print"><a class="btn secondary" href="index.php?page=reports">Reports & Backup</a> <a class="btn danger" href="logout.php">Logout</a></div></div>
<?php if(!empty($error)): ?><div class="error"><?=h($error)?></div><?php endif; ?>
<?php if($page==='dashboard'):
$book=(float)$pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM bookings')->fetchColumn();$received=(float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN transaction_type='ADD' THEN amount ELSE -amount END),0) FROM customer_receipts")->fetchColumn();$vendorWork=(float)$pdo->query('SELECT COALESCE(SUM(total_amount),0) FROM vendor_work')->fetchColumn();$vendorPaid=(float)$pdo->query('SELECT COALESCE(SUM(payment_amount),0) FROM vendor_payments')->fetchColumn();$expenses=(float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE paid_by<>'VENDOR'")->fetchColumn();$count=(int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn();$cashRows=fetch_all($pdo,'SELECT a.account_name,COALESCE(SUM(CASE WHEN t.direction=\'IN\' THEN t.amount ELSE -t.amount END),0) balance FROM cash_accounts a LEFT JOIN cash_transactions t ON t.account_id=a.id GROUP BY a.id,a.account_name ORDER BY a.id');
?>
<div class="cards"><a class="card" href="?page=bookings"><small>Total Bookings</small><strong><?=$count?></strong></a><a class="card" href="?page=bookings"><small>Booking Amount</small><strong><?=money($book)?></strong></a><a class="card" href="?page=receipts"><small>Customer Received</small><strong><?=money($received)?></strong></a><a class="card" href="?page=customer-ledger"><small>Customer Due</small><strong><?=money($book-$received)?></strong></a><a class="card" href="?page=vendor-work"><small>Vendor Work</small><strong><?=money($vendorWork)?></strong></a><a class="card" href="?page=vendor-payments"><small>Vendor Paid</small><strong><?=money($vendorPaid)?></strong></a><a class="card" href="?page=vendor-ledger"><small>Vendor Due</small><strong><?=money($vendorWork-$vendorPaid)?></strong></a><a class="card" href="?page=expenses"><small>Expenses</small><strong><?=money($expenses)?></strong></a></div>
<div class="panel"><div class="toolbar"><h3>Cash & Bank Balance</h3><a class="btn secondary" href="?page=cash">Manage Accounts</a></div><div class="table-wrap"><table><thead><tr><th>Account Name</th><th>Current Balance</th></tr></thead><tbody><?php foreach($cashRows as $r): ?><tr><td><?=h($r['account_name'])?></td><td><?=money($r['balance'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="panel"><h3>Recent Bookings</h3><?php $recent=fetch_all($pdo,'SELECT b.booking_date,b.docket_number,c.customer_name,b.destination,b.total_amount FROM bookings b JOIN customers c ON c.id=b.customer_id ORDER BY b.id DESC LIMIT 10');?><div class="table-wrap"><table><thead><tr><th>Date</th><th>Docket</th><th>Customer</th><th>Destination</th><th>Amount</th></tr></thead><tbody><?php foreach($recent as $r):?><tr><td><?=h($r['booking_date'])?></td><td><a href="?page=bookings&docket=<?=urlencode($r['docket_number'])?>"><?=h($r['docket_number'])?></a></td><td><?=h($r['customer_name'])?></td><td><?=h($r['destination'])?></td><td><?=money($r['total_amount'])?></td></tr><?php endforeach;?></tbody></table></div></div>
<?php elseif($page==='customer-ledger'):
$customers=fetch_all($pdo,'SELECT c.id,c.customer_name,COALESCE(b.total_booking,0) total_booking,COALESCE(r.total_payment,0) total_payment,COALESCE(b.total_booking,0)-COALESCE(r.total_payment,0) due FROM customers c LEFT JOIN (SELECT customer_id,SUM(total_amount) total_booking FROM bookings GROUP BY customer_id) b ON b.customer_id=c.id LEFT JOIN (SELECT customer_id,SUM(CASE WHEN transaction_type=\'ADD\' THEN amount ELSE -amount END) total_payment FROM customer_receipts GROUP BY customer_id) r ON r.customer_id=c.id WHERE c.is_active=1 ORDER BY c.customer_name');
?><div class="toolbar"><h3>Customer Summary — one row per customer</h3><div class="actions no-print"><a class="btn secondary" href="?page=print-customer-all">Print All Details / PDF</a></div></div><div class="table-wrap"><table><thead><tr><th>Customer Name</th><th>Total Booking</th><th>Total Payment</th><th>Total Due</th><th>Details</th></tr></thead><tbody><?php foreach($customers as $c):?><tr><td><a class="click" href="?page=customer-ledger&customer=<?=(int)$c['id']?>"><?=h($c['customer_name'])?></a></td><td><?=money($c['total_booking'])?></td><td><?=money($c['total_payment'])?></td><td><?=money($c['due'])?></td><td><a class="btn sm" href="?page=customer-ledger&customer=<?=(int)$c['id']?>">Details</a></td></tr><?php endforeach;?></tbody></table></div>
<?php if(isset($_GET['customer'])):$cid=(int)$_GET['customer'];$cst=db_row($pdo,'customers',$cid);if($cst):$tx=fetch_all($pdo,"SELECT entry_date,entry_type,debit,credit,remarks,booking_id,receipt_id FROM customer_ledger_entries WHERE customer_id=? ORDER BY entry_date,id",[$cid]);$running=0;$monthly=[];foreach($tx as &$t){$running+=(float)$t['debit']-(float)$t['credit'];$t['running_due']=$running;$m=substr($t['entry_date'],0,7);if(!isset($monthly[$m]))$monthly[$m]=['opening'=>$running-(float)$t['debit']+(float)$t['credit'],'booking'=>0,'payment'=>0,'closing'=>0];if($t['entry_type']==='BOOKING_DUE')$monthly[$m]['booking']+=(float)$t['debit'];else $monthly[$m]['payment']+=(float)$t['credit']-(float)$t['debit'];$monthly[$m]['closing']=$running;}unset($t);?>
<div class="panel section-gap"><h3><?=h($cst['customer_name'])?> — Complete Account</h3><div class="summary"><div><small>Total Due / Credit</small><b><?=money($running)?></b></div><div><small>Customer Phone</small><b><?=h($cst['phone'])?></b></div><div><small>Address</small><b><?=h($cst['address'])?></b></div></div><h3 class="section-gap">Monthly Old Due Carry Forward</h3><div class="table-wrap"><table><tr><th>Month</th><th>Old Due / Opening</th><th>New Booking</th><th>Net Payment</th><th>Closing Due</th></tr><?php foreach($monthly as $m=>$v):?><tr><td><?=h($m)?></td><td><?=money($v['opening'])?></td><td><?=money($v['booking'])?></td><td><?=money($v['payment'])?></td><td><?=money($v['closing'])?></td></tr><?php endforeach;?></table></div><h3 class="section-gap">Date-wise Transaction History</h3><div class="table-wrap"><table><tr><th>Date</th><th>Type</th><th>Debit</th><th>Credit</th><th>Running Due</th><th>Remarks</th></tr><?php foreach($tx as $t):?><tr><td><?=h($t['entry_date'])?></td><td><?=h($t['entry_type'])?></td><td><?=money($t['debit'])?></td><td><?=money($t['credit'])?></td><td><?=money($t['running_due'])?></td><td><?=h($t['remarks'])?></td></tr><?php endforeach;?></table></div></div><?php endif;endif;?>
<?php elseif($page==='vendor-ledger'):
$vendors=fetch_all($pdo,'SELECT v.id,v.vendor_name,COALESCE(w.total_work,0) total_work,COALESCE(p.total_paid,0) total_paid,COALESCE(w.total_work,0)-COALESCE(p.total_paid,0) due FROM vendors v LEFT JOIN (SELECT vendor_id,SUM(total_amount) total_work FROM vendor_work GROUP BY vendor_id) w ON w.vendor_id=v.id LEFT JOIN (SELECT vendor_id,SUM(payment_amount) total_paid FROM vendor_payments GROUP BY vendor_id) p ON p.vendor_id=v.id WHERE v.is_active=1 ORDER BY v.vendor_name');
?><div class="toolbar"><h3>Vendor Summary — only actual Vendor Name</h3><a class="btn secondary no-print" href="?page=print-vendor-all">Print All Details / PDF</a></div><div class="table-wrap"><table><tr><th>Vendor Name</th><th>Total Work</th><th>Total Paid</th><th>Total Due</th><th>Details</th></tr><?php foreach($vendors as $v):?><tr><td><a class="click" href="?page=vendor-ledger&vendor=<?=(int)$v['id']?>"><?=h($v['vendor_name'])?></a></td><td><?=money($v['total_work'])?></td><td><?=money($v['total_paid'])?></td><td><?=money($v['due'])?></td><td><a class="btn sm" href="?page=vendor-ledger&vendor=<?=(int)$v['id']?>">Details</a></td></tr><?php endforeach;?></table></div>
<?php if(isset($_GET['vendor'])):$vid=(int)$_GET['vendor'];$vst=db_row($pdo,'vendors',$vid);if($vst):$works=fetch_all($pdo,'SELECT w.work_date,w.docket_number,c.customer_name,w.receiver_name,w.total_box,w.weight_kg,w.destination,w.total_amount FROM vendor_work w LEFT JOIN customers c ON c.id=w.customer_id WHERE w.vendor_id=? ORDER BY w.work_date,w.id',[$vid]);$payments=fetch_all($pdo,'SELECT p.payment_date,p.payment_amount,p.payment_mode,p.remarks,a.account_name FROM vendor_payments p JOIN cash_accounts a ON a.id=p.paid_from_account_id WHERE p.vendor_id=? ORDER BY p.payment_date,p.id',[$vid]);$tw=array_sum(array_column($works,'total_amount'));$tp=array_sum(array_column($payments,'payment_amount'));?>
<div class="panel section-gap"><h3><?=h($vst['vendor_name'])?> — Complete Vendor Account</h3><div class="summary"><div><small>Total Work</small><b><?=money($tw)?></b></div><div><small>Total Paid</small><b><?=money($tp)?></b></div><div><small>Total Due</small><b><?=money($tw-$tp)?></b></div></div><h3 class="section-gap">Which Customer's Work</h3><div class="table-wrap"><table><tr><th>Date</th><th>Docket</th><th>Customer</th><th>Receiver</th><th>Box</th><th>Weight KG</th><th>Destination</th><th>Work Amount</th></tr><?php foreach($works as $w):?><tr><?php foreach(['work_date','docket_number','customer_name','receiver_name','total_box','weight_kg','destination','total_amount'] as $k):?><td><?=h($w[$k])?></td><?php endforeach;?></tr><?php endforeach;?></table></div><h3 class="section-gap">Vendor Payment History</h3><div class="table-wrap"><table><tr><th>Date</th><th>Amount</th><th>Payment Mode</th><th>Paid From Account</th><th>Remarks</th></tr><?php foreach($payments as $p):?><tr><td><?=h($p['payment_date'])?></td><td><?=money($p['payment_amount'])?></td><td><?=h($p['payment_mode'])?></td><td><?=h($p['account_name'])?></td><td><?=h($p['remarks'])?></td></tr><?php endforeach;?></table></div></div><?php endif;endif;?>
<?php elseif($page==='print-customer-all'):
?><h2>All Customer Ledger Details</h2><?php foreach(fetch_all($pdo,'SELECT id,customer_name FROM customers WHERE is_active=1 ORDER BY customer_name') as $c):$entries=fetch_all($pdo,'SELECT entry_date,entry_type,debit,credit,remarks FROM customer_ledger_entries WHERE customer_id=? ORDER BY entry_date,id',[$c['id']]);$due=0;?><div class="panel"><h3><?=h($c['customer_name'])?></h3><table><tr><th>Date</th><th>Type</th><th>Debit</th><th>Credit</th><th>Running Due</th><th>Remarks</th></tr><?php foreach($entries as $e):$due+=(float)$e['debit']-(float)$e['credit'];?><tr><td><?=h($e['entry_date'])?></td><td><?=h($e['entry_type'])?></td><td><?=money($e['debit'])?></td><td><?=money($e['credit'])?></td><td><?=money($due)?></td><td><?=h($e['remarks'])?></td></tr><?php endforeach;?></table><b>Current Due: <?=money($due)?></b></div><?php endforeach;?>
<?php elseif($page==='print-vendor-all'):
?><h2>All Vendor Ledger Details</h2><?php foreach(fetch_all($pdo,'SELECT id,vendor_name FROM vendors WHERE is_active=1 ORDER BY vendor_name') as $v):$works=fetch_all($pdo,'SELECT work_date,docket_number,customer_id,total_amount FROM vendor_work WHERE vendor_id=? ORDER BY work_date',[$v['id']]);$payments=fetch_all($pdo,'SELECT payment_date,payment_amount,remarks FROM vendor_payments WHERE vendor_id=? ORDER BY payment_date',[$v['id']]);$wtotal=array_sum(array_column($works,'total_amount'));$ptotal=array_sum(array_column($payments,'payment_amount'));?><div class="panel"><h3><?=h($v['vendor_name'])?></h3><p>Total Work: <?=money($wtotal)?> | Paid: <?=money($ptotal)?> | Due: <?=money($wtotal-$ptotal)?></p><table><tr><th>Date</th><th>Type</th><th>Docket</th><th>Amount</th><th>Remarks</th></tr><?php foreach($works as $w):?><tr><td><?=h($w['work_date'])?></td><td>Work</td><td><?=h($w['docket_number'])?></td><td><?=money($w['total_amount'])?></td><td>Vendor Work</td></tr><?php endforeach;?><?php foreach($payments as $p):?><tr><td><?=h($p['payment_date'])?></td><td>Payment</td><td>—</td><td><?=money($p['payment_amount'])?></td><td><?=h($p['remarks'])?></td></tr><?php endforeach;?></table></div><?php endforeach;?>
<?php elseif($page==='reports'):
?><div class="panel"><h3>Reports & Backup</h3><p>যেকোনো Module-এর Start Date ও End Date দিয়ে Filter করে Excel/CSV Download করো। Browser Print থেকে PDF Save করা যাবে।</p><div class="actions no-print"><a class="btn" href="export.php">Download All CSV</a><a class="btn secondary" href="?page=print-customer-all">Print All Customer Ledger</a><a class="btn secondary" href="?page=print-vendor-all">Print All Vendor Ledger</a></div></div>
<?php elseif($page==='export-all'):
?><div class="panel"><h3>Export All Data</h3><p>নিচের Button চাপলে সব Module-এর data CSV আকারে download হবে।</p><a class="btn" href="export.php">Download All CSV</a></div>
<?php else:
  $formPages=['customers','vendors','couriers','new-booking','vendor-work','rates','receipts','vendor-payments','expenses','cash','tracking','pickups'];
  if(in_array($page,$formPages,true)):
?><div class="panel"><div class="toolbar"><h3><?= $editRow?'Edit Entry':'New Entry' ?></h3><?php if($editRow && !can_edit_row($editRow,$role)):?><span class="lock">7-Day Lock</span><?php endif;?></div><?php if($editRow && !can_edit_row($editRow,$role)):?><div class="notice">এই Entry-র ৭ দিনের Edit সময় শেষ হয়েছে। ADMIN User Edit করতে পারবে।</div><?php else:?><?=module_form($page,$pdo,$editRow,$editTable)?><?php endif;?></div><?php endif;
  if($page==='cash'):
    $rows=$pdo->query('SELECT a.id,a.account_name,COALESCE(SUM(CASE WHEN t.direction=\'IN\' THEN t.amount ELSE -t.amount END),0) balance FROM cash_accounts a LEFT JOIN cash_transactions t ON t.account_id=a.id GROUP BY a.id,a.account_name ORDER BY a.id')->fetchAll();
?><div class="panel"><h3>Current Account Balances</h3><table><tr><th>Account Name</th><th>Current Balance</th></tr><?php foreach($rows as $r):?><tr><td><?=h($r['account_name'])?></td><td><?=money($r['balance'])?></td></tr><?php endforeach;?></table></div><?php endif;
  $hasList=in_array($page,['customers','vendors','couriers','bookings','rates','receipts','vendor-payments','vendor-work','expenses','cash','tracking','pickups'],true);
  if($hasList):
    $rows=list_rows($pdo,$page,$from,$to,$search);
    $headMap=[
      'customers'=>['id'=>'ID','customer_name'=>'Customer','phone'=>'Phone','pincode'=>'PIN','city'=>'City','state'=>'State'],
      'vendors'=>['id'=>'ID','vendor_name'=>'Vendor','phone'=>'Phone','address'=>'Address'],
      'couriers'=>['id'=>'ID','company_name'=>'Booking By / Courier'],
      'bookings'=>['booking_date'=>'Date','docket_number'=>'Docket','customer_name'=>'Customer','receiver_name'=>'Receiver','company_name'=>'Booking By','vendor_name'=>'Actual Vendor','destination'=>'Destination','weight_kg'=>'Weight KG','total_box'=>'Box','total_amount'=>'Customer Amount','vendor_amount'=>'Vendor Amount'],
      'rates'=>['customer_name'=>'Customer','pincode'=>'PIN','city'=>'City','destination'=>'Destination','rate_type'=>'Rate Type','rate'=>'Rate'],
      'receipts'=>['receipt_date'=>'Date','customer_name'=>'Customer','transaction_type'=>'ADD / LESS','amount'=>'Amount','account_name'=>'Received In Account','payment_mode'=>'Mode','remarks'=>'Remarks'],
      'vendor-payments'=>['payment_date'=>'Date','vendor_name'=>'Vendor','payment_amount'=>'Payment Amount','account_name'=>'Paid From Account','payment_mode'=>'Mode','remarks'=>'Remarks'],
      'vendor-work'=>['work_date'=>'Date','vendor_name'=>'Vendor','customer_name'=>'Customer','docket_number'=>'Docket','weight_kg'=>'Weight KG','total_box'=>'Box','destination'=>'Destination','total_amount'=>'Work Amount'],
      'expenses'=>['expense_date'=>'Date','category'=>'Category','amount'=>'Amount','paid_by'=>'Paid By','account_name'=>'Cash Account','remarks'=>'Remarks'],
      'cash'=>['transaction_date'=>'Date','account_name'=>'Account','transaction_type'=>'Type','direction'=>'Direction','amount'=>'Amount','remarks'=>'Remarks'],
      'tracking'=>['tracking_date'=>'Date','docket_number'=>'Docket','status'=>'Status','location'=>'Location','remarks'=>'Remarks'],
      'pickups'=>['pickup_date'=>'Date','customer_name'=>'Customer','pickup_address'=>'Address','pincode'=>'PIN','vehicle'=>'Vehicle','pickup_fare'=>'Fare','pickup_status'=>'Status']
    ];
    $tableMap=['customers'=>'customers','vendors'=>'vendors','couriers'=>'courier_companies','bookings'=>'bookings','rates'=>'rate_master','receipts'=>'customer_receipts','vendor-payments'=>'vendor_payments','vendor-work'=>'vendor_work','expenses'=>'expenses','cash'=>'cash_transactions','tracking'=>'shipment_tracking','pickups'=>'pickups'];
?><div class="panel"><div class="toolbar"><h3><?=h($title)?> — Records</h3><form class="filter no-print" method="get"><input type="hidden" name="page" value="<?=h($page)?>"><label>Start Date<input type="date" name="from" value="<?=h($from)?>"></label><label>End Date<input type="date" name="to" value="<?=h($to)?>"></label><label>Search<input class="search" name="q" value="<?=h($search)?>" placeholder="Search..."></label><button class="btn">Apply</button><a class="btn secondary" href="?page=<?=h($page)?>">Clear</a><button class="btn secondary" type="button" onclick="window.print()">PDF / Print</button><button class="btn secondary" type="button" onclick="exportTableCSV()">Excel / CSV</button></form></div>
<?php
if($page==='bookings'){
 echo '<div class="table-wrap"><table><tr>';foreach($headMap[$page] as $label)echo '<th>'.h($label).'</th>';echo '<th>Actions</th></tr>';
 foreach($rows as $r){echo '<tr>';foreach($headMap[$page] as $key=>$label){$value=$r[$key]??'';if($key==='docket_number')$value='<a href="?page=bookings&docket='.urlencode($r['docket_number']).'">'.h($r['docket_number']).'</a>';else $value=h($value);echo '<td>'.$value.'</td>';}$base=db_row($pdo,'bookings',$r['id']);echo '<td>'.action_buttons($base,'bookings','bookings',$role).' <a class="btn sm secondary" target="_blank" href="?page=print-docket&id='.(int)$r['id'].'">Print Docket</a></td></tr>';}
 echo '</table></div>';
} else {
 echo data_table($rows,$headMap[$page],$tableMap[$page],$page,$role);
}
?></div>
<?php if($page==='bookings' && isset($_GET['docket'])):$s=$pdo->prepare('SELECT b.*,c.customer_name,cc.company_name,v.vendor_name FROM bookings b JOIN customers c ON c.id=b.customer_id LEFT JOIN courier_companies cc ON cc.id=b.courier_company_id LEFT JOIN vendors v ON v.id=b.vendor_id WHERE b.docket_number=?');$s->execute([$_GET['docket']]);$d=$s->fetch();if($d):?><div class="panel"><div class="toolbar"><h3>Docket Details: <?=h($d['docket_number'])?></h3><a class="btn secondary" target="_blank" href="?page=print-docket&id=<?=(int)$d['id']?>">Print Docket</a></div><div class="grid"><?php foreach($d as $k=>$v):if($k==='id'||$k==='created_by'||$k==='created_at'||$k==='updated_at')continue;?><div><small class="muted"><?=h($k)?></small><div><b><?=h($v)?></b></div></div><?php endforeach;?></div></div><?php endif;endif;?>
<?php endif;endif;?>
<?php if($page==='print-docket' && isset($_GET['id'])):$d=db_row($pdo,'bookings',(int)$_GET['id']);if($d):$s=$pdo->prepare('SELECT customer_name,address,pincode,city,district,state,phone FROM customers WHERE id=?');$s->execute([$d['customer_id']]);$c=$s->fetch();?><div class="panel"><h1>BPS LOGISTICS</h1><h2>DOCKET / BOOKING RECEIPT</h2><p><b>Docket:</b> <?=h($d['docket_number'])?> &nbsp; <b>Date:</b> <?=h($d['booking_date'])?></p><hr><h3>Customer / Sender</h3><p><?=h($c['customer_name'])?><br><?=h($c['address'])?><br><?=h($c['city'])?>, <?=h($c['district'])?>, <?=h($c['state'])?> - <?=h($c['pincode'])?><br><?=h($c['phone'])?></p><h3>Receiver</h3><p><?=h($d['receiver_name'])?><br><?=h($d['destination'])?> - <?=h($d['pincode'])?></p><table><tr><th>Booking By</th><td><?php $s=$pdo->prepare('SELECT company_name FROM courier_companies WHERE id=?');$s->execute([$d['courier_company_id']]);echo h($s->fetchColumn());?></td></tr><tr><th>Weight</th><td><?=h($d['weight_kg'])?> KG</td></tr><tr><th>Box</th><td><?=h($d['total_box'])?></td></tr><tr><th>Amount</th><td><?=money($d['total_amount'])?></td></tr></table><p style="margin-top:50px">Customer Signature: ___________________</p></div><script>window.print()</script><?php endif;endif;?>
</main></div>
<script>
// Customer autocomplete: suggestions start after three characters.
const customerSearch=document.getElementById('customerSearch');
if(customerSearch){
 let timer;
 customerSearch.addEventListener('input',()=>{
   clearTimeout(timer);const q=customerSearch.value.trim();
   if(q.length<3)return;
   timer=setTimeout(async()=>{
     try{const res=await fetch('index.php?ajax=customers&q='+encodeURIComponent(q));const list=await res.json();const dl=document.getElementById('customerOptions');dl.innerHTML='';list.forEach(c=>{const o=document.createElement('option');o.value=c.customer_name;dl.appendChild(o);});window.bpsCustomers=list;}catch(e){}
   },200);
 });
 customerSearch.addEventListener('change',()=>{
   const list=window.bpsCustomers||[];const c=list.find(x=>x.customer_name.toLowerCase()===customerSearch.value.trim().toLowerCase());
   if(!c)return;
   document.getElementById('customerId').value=c.id;
   const form=document.getElementById('entryForm');
   ['receiver_address','receiver_phone'].forEach((id)=>{const el=form.elements[id];if(el)el.value=id==='receiver_address'?c.address:c.phone;});
   ['pincode','city','district','state'].forEach(k=>{if(form.elements[k])form.elements[k].value=c[k]||'';});
   document.getElementById('customerHint').textContent='Customer details loaded: '+[c.address,c.pincode,c.city,c.state,c.phone].filter(Boolean).join(' • ');
 });
}
// PIN lookup on New Booking / Customer forms (India Post API).
document.querySelectorAll('input[name="pincode"]').forEach(pin=>pin.addEventListener('change',async()=>{
 if(!/^\d{6}$/.test(pin.value))return;
 try{const r=await fetch('https://api.postalpincode.in/pincode/'+encodeURIComponent(pin.value));const j=await r.json();if(j[0]?.Status==='Success'){const p=j[0].PostOffice[0];const f=pin.form;if(f.elements.city)f.elements.city.value=p.Block||p.Name||'';if(f.elements.district)f.elements.district.value=p.District||'';if(f.elements.state)f.elements.state.value=p.State||'';if(f.elements.destination)f.elements.destination.value=p.District||'';}}catch(e){}
}));
// Rate Master auto-fill in booking form.
const bookingForm=document.getElementById('entryForm');
if(bookingForm && bookingForm.elements['customer_id']){
 const updateRate=async()=>{
  const cid=bookingForm.elements['customer_id'].value,dest=bookingForm.elements['destination'].value;
  if(!cid||!dest)return;
  try{const r=await fetch('index.php?ajax=rates&customer_id='+cid+'&destination='+encodeURIComponent(dest));const rate=await r.json();if(rate.rate){bookingForm.elements['rate_type'].value=rate.rate_type;bookingForm.elements['customer_rate'].value=rate.rate;calcAmount();}}catch(e){}
 };
 const calcAmount=()=>{const t=bookingForm.elements['rate_type'].value;const r=Number(bookingForm.elements['customer_rate'].value||0);const qty=t==='BY_BOX'?Number(bookingForm.elements['total_box'].value||0):Number(bookingForm.elements['weight_kg'].value||0);bookingForm.elements['total_amount'].value=(qty*r).toFixed(2);};
 ['destination','customer_rate','weight_kg','total_box','rate_type'].forEach(k=>bookingForm.elements[k]?.addEventListener('change',()=>{if(k==='destination')updateRate();else calcAmount();}));
}
function exportTableCSV(){
 const table=document.querySelector('#tableHost table');if(!table){alert('No records to export');return;}
 const rows=[...table.querySelectorAll('tr')].map(tr=>[...tr.children].slice(0,-1).map(td=>td.innerText));
 const csv=rows.map(row=>row.map(v=>'"'+v.replace(/"/g,'""')+'"').join(',')).join('\r\n');
 const blob=new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8'});const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download='BPS-LOGISTICS-REPORT.csv';a.click();URL.revokeObjectURL(url);
}
</script>
<?php if($printAll || $page==='print-docket'): ?><script>window.addEventListener('load',()=>window.print());</script><?php endif; ?>
</body></html>
