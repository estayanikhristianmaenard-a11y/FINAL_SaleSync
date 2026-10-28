<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use SaleSync\Core\{ApiError,Database};
use SaleSync\Customer\Module;

$root=dirname(__DIR__,2);
$config=require $root.'/app/bootstrap.php';
$directory=$root.'/storage/customer-audit-'.bin2hex(random_bytes(6));
$config=array_replace($config,['database'=>$directory.'/audit.sqlite','shipping_fee'=>0,'mail_driver'=>'log']);
$db=new Database($config['database']);
foreach(glob($root.'/database/migrations/*.sql') as $file) $db->migrate(fn()=>$db->pdo->exec(file_get_contents($file)));
$db->run("INSERT INTO users(name,email,role,password_hash,email_verified_at,phone,birth_date) VALUES ('Audit Customer','audit@example.test','customer','unused','2026-01-01T00:00:00Z','09171234567','2000-01-01')");
$db->run("INSERT INTO users(name,email,role,password_hash) VALUES ('Audit Seller','audit-seller@example.test','seller','unused')");
$db->run("INSERT INTO seller_profiles(user_id,store_name,approval_status) VALUES (2,'Audit Store','approved')");
$db->run("INSERT INTO categories(category_name) VALUES ('Audit')");
$db->run("INSERT INTO products(seller_id,category_id,sku,name,price,stock_qty,status) VALUES (1,1,'AUDIT-1','Audit Product',10000,20,'published')");
$db->run("INSERT INTO product_variants(product_id,variant_id,name) VALUES (1,'std','Standard')");
$db->run("INSERT INTO addresses(user_id,label,recipient_name,phone,address_text,is_default) VALUES (1,'Home','Audit Customer','09171234567','Audit address',1)");
$module=new Module($config,$db);
$findings=[];
$record=function(string $id,string $expected,array $actual,?bool $passed) use (&$findings): void {
    $findings[]=compact('id','expected','actual','passed');
    echo ($passed===null?'OBSERVE':($passed?'PASS':'FAIL')).' AUDIT '.$id.': '.json_encode($actual).PHP_EOL;
};

$module->cart->set(1,1,1);
$quote=$module->cart->quote(1);
$db->run('UPDATE products SET price=20000 WHERE product_id=1');
try {
    $order=$module->orders->checkout(1,['address_id'=>1,'quote_token'=>$quote['quote_token']],'adversarial-price-checkout');
    $record('price-changed-after-review','A higher total requires another customer review before creating/redirecting payment',['reviewed_total'=>$quote['total'],'accepted_total'=>$order['total'],'rejected_for_reconfirmation'=>false],false);
} catch(ApiError $error) {
    $actual=['status'=>$error->status,'reason'=>$error->fields['quote']??null,
        'orders_created'=>$db->one('SELECT COUNT(*) AS n FROM orders')['n'],
        'stock_remaining'=>$db->one('SELECT stock_qty FROM products WHERE product_id=1')['stock_qty']];
    $record('price-changed-after-review','Higher total is rejected for reconfirmation without creating an order or reserving stock',
        $actual,$error->status===409&&$actual['reason']==='changed'&&$actual['orders_created']===0&&$actual['stock_remaining']===20);
}

// Continue observations with a genuinely reviewed fresh quote after a rejected stale quote.
if (!isset($order)) {
    $fresh=$module->cart->quote(1);
    $order=$module->orders->checkout(1,['address_id'=>1,'quote_token'=>$fresh['quote_token']],'adversarial-fresh-checkout');
}

if(isset($order)) {
    $db->run("UPDATE orders SET created_at='2020-01-01T00:00:00Z' WHERE order_id=?",[$order['order_id']]);
    $old=$module->payments->refresh(1,$order['order_id']);
    $record('abandoned-unpaid-reservation','Observation: business needs an expiry policy and a job for orders without provider sessions',[
        'created_at'=>$old['created_at'],'status'=>$old['status'],'payment_status'=>$old['payment']['status'],
        'stock_remaining'=>$db->one('SELECT stock_qty FROM products WHERE product_id=1')['stock_qty'],
    ],null);
}

$module->profile->update(1,['name'=>'Audit Customer','phone'=>'09999999999','birth_date'=>'1990-01-01']);
$record('profile-field-corrections','Observation: Edit Profile currently updates only the name',
    $db->one('SELECT phone,birth_date FROM users WHERE user_id=1'),null);

// Ownership is an actual negative control, not a UI-only assertion.
try {
    $module->orders->get(2,$order['order_id']??1);
    $record('order-owner-isolation','Another user cannot read the order',['blocked'=>false],false);
} catch(ApiError $error) {
    $record('order-owner-isolation','Another user cannot read the order',['status'=>$error->status],$error->status===404);
}
file_put_contents($directory.'/results.json',json_encode(['findings'=>$findings],JSON_PRETTY_PRINT));
echo 'Audit evidence: '.$directory.'/results.json'.PHP_EOL;
echo 'Audit gates the reproduced defects; observations remain separate integration work.'.PHP_EOL;
exit(array_filter($findings,fn(array $finding): bool=>$finding['passed']===false)?1:0);
