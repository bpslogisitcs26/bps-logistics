<?php
$config=require __DIR__.'/config.php';
session_name($config['session_name']??'BPSLOGISTICSSESSID');session_start();
if(empty($_SESSION['user_id'])){header('Location: login.php');exit;}
try{$dsn='mysql:host='.$config['host'].';dbname='.$config['database'].';charset='.$config['charset'];$pdo=new PDO($dsn,$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}catch(Throwable $e){http_response_code(500);exit('Database connection failed.');}
$tables=['bookings'=>'Bookings','customer_receipts'=>'Receipts','vendor_work'=>'Vendor Work','vendor_payments'=>'Vendor Payments','expenses'=>'Expenses','cash_transactions'=>'Cash Transactions','rate_master'=>'Rate Master','shipment_tracking'=>'Tracking','pickups'=>'Pickups','customers'=>'Customers','vendors'=>'Vendors'];
$export=[];foreach($tables as $table=>$label){foreach($pdo->query("SELECT * FROM `$table`")->fetchAll() as $row){$export[]=['Module'=>$label]+$row;}}
header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="bps-logistics-all-data.csv"');
$fp=fopen('php://output','w');fwrite($fp,"\xEF\xBB\xBF");if($export){fputcsv($fp,array_keys($export[0]));foreach($export as $row)fputcsv($fp,$row);}fclose($fp);exit;
