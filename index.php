<?php
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('Falta config.php. Sigue las instrucciones de README.md.');
}
$config = require $configPath;
require_once __DIR__ . '/includes/smtp_mailer.php';
require_once __DIR__ . '/includes/stripe.php';
session_start();
try {
    $pdo = new PDO('mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4', $config['db_user'], $config['db_pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo conectar a la base de datos. Revisa config.php y schema.sql.');
}
$_SESSION['cart'] ??= [];
function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function eventLog(PDO $db, string $name, array $data=[]): void {
    $orderId=isset($data['order_id'])?(int)$data['order_id']:null;
    $s=$db->prepare('INSERT INTO events(order_id,event_name,payload) VALUES(?,?,?)');
    $s->execute([$orderId,$name,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}
function go(string $to='?'){
    header('Location: '.$to);
    exit;
}
function money($n){
    return number_format((float)$n,2,',','.').' €';
}
function includedVat(float $gross, array $config): float {
    $rate=(float)$config['tax_rate'];
    return round($gross*$rate/(1+$rate),2);
}
function productImage(string $sku): string {
    $images = [
        'VG-001' => 'proyector.jpeg',
        'VG-002' => 'lampara.jpeg',
        'VG-003' => 'auriculares.jpeg',
        'VG-004' => 'soporte.jpeg',
        'VG-005' => 'botella.jpeg',
        'VG-006' => 'teclado.jpeg',
        'VG-007' => 'led.jpeg',
        'VG-008' => 'impresora.jpeg',
    ];
    return 'assets/images/' . ($images[$sku] ?? '');
}
function cartRows(PDO $db): array {
    $rows=[];
    foreach($_SESSION['cart'] as $lineKey=>$quantity){
        [$productId,$variantId]=array_pad(explode(':',(string)$lineKey,2),2,0);
        $productId=(int)$productId;
        $variantId=(int)$variantId;
        if($variantId){
            $s=$db->prepare("SELECT p.*, v.id AS variant_id, v.sku AS variant_sku, CONCAT(v.option_name, ': ', v.option_value, IF(v.option2_name IS NOT NULL, CONCAT(' / ', v.option2_name, ': ', v.option2_value), '')) AS variant_label, v.stock AS available_stock FROM products p JOIN product_variants v ON v.product_id=p.id WHERE p.id=? AND v.id=? AND p.active=1 AND v.active=1");
            $s->execute([$productId,$variantId]);
        }
        else{
            $s=$db->prepare("SELECT p.*, NULL AS variant_id, '' AS variant_sku, '' AS variant_label, p.stock AS available_stock FROM products p WHERE p.id=? AND p.active=1 AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.active=1)");
            $s->execute([$productId]);
        }
        $p=$s->fetch();
        if(!$p)continue;
        $p['qty']=(int)$quantity;
        $p['line_key']=(string)$lineKey;
        $rows[]=$p;
    }
    return $rows;
}
function cartWeightGrams(array $items): int {
    $weight = 0;
    foreach($items as $item){
        $weight += (int)$item['weight_grams'] * (int)$item['qty'];
    }
    return $weight;
}
function cartSubtotal(array $items): float {
    $subtotal = 0;
    foreach($items as $item){
        $subtotal += (float)$item['price'] * (int)$item['qty'];
    }
    return $subtotal;
}
function shippingCost(int $weightGrams, float $subtotal, array $config): float {
    if($subtotal >= (float)$config['free_shipping_from']){
        return 0.0;
    }
    $stepGrams = max(1, (int)$config['shipping_step_grams']);
    $extraSteps = max(0, (int)ceil($weightGrams / $stepGrams) - 1);
    return (float)$config['shipping_fee']
        + $extraSteps * (float)$config['shipping_extra_per_step'];
}
$action=$_POST['action']??$_GET['action']??'home';
$stripeActive=!empty($config['stripe']['enabled'])&&!empty($config['stripe']['secret_key']);
$liveStripe=$stripeActive&&!empty($config['stripe']['allow_live_payments'])&&str_starts_with((string)$config['stripe']['secret_key'],'sk_live_');
if($action==='update_stock'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $adminKey=is_string($_POST['admin_key']??null)?$_POST['admin_key']:'';
    $csrf=is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:'';
    if(!hash_equals((string)$config['admin_key'],$adminKey)||$adminKey==='CAMBIAR-ANTES-DE-PUBLICAR'
        ||empty($_SESSION['admin_csrf'])||!hash_equals((string)$_SESSION['admin_csrf'],$csrf)){
        http_response_code(403);
        exit('Acceso no autorizado.');
    }
    $productId=(int)($_POST['product_id']??0);
    $variantId=(int)($_POST['variant_id']??0);
    $newStock=filter_var($_POST['stock']??null,FILTER_VALIDATE_INT);
    if($productId<1||$newStock===false||$newStock<0||$newStock>100000){
        $_SESSION['error']='Indica un stock entero entre 0 y 100.000.';
    }
    else{
        try{
            $pdo->beginTransaction();
            $productQuery=$pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE');
            $productQuery->execute([$productId]);
            if(!$productQuery->fetch())throw new DomainException('Producto no encontrado.');
            if($variantId>0){
                $variantQuery=$pdo->prepare('SELECT id,stock FROM product_variants WHERE id=? AND product_id=? AND active=1 FOR UPDATE');
                $variantQuery->execute([$variantId,$productId]);
                $variant=$variantQuery->fetch();
                if(!$variant)throw new DomainException('Variante no encontrada.');
                $pdo->prepare('UPDATE product_variants SET stock=? WHERE id=?')->execute([$newStock,$variantId]);
                $sumQuery=$pdo->prepare('SELECT COALESCE(SUM(stock),0) FROM product_variants WHERE product_id=? AND active=1');
                $sumQuery->execute([$productId]);
                $pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([(int)$sumQuery->fetchColumn(),$productId]);
                eventLog($pdo,'inventory.stock_updated',['product_id'=>$productId,'variant_id'=>$variantId,'old_stock'=>(int)$variant['stock'],'new_stock'=>$newStock]);
            }
            else{
                $variantCount=$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=? AND active=1');
                $variantCount->execute([$productId]);
                if((int)$variantCount->fetchColumn()>0)throw new DomainException('Este producto se gestiona mediante el stock de sus variantes.');
                $stockQuery=$pdo->prepare('SELECT stock FROM products WHERE id=? FOR UPDATE');
                $stockQuery->execute([$productId]);
                $oldStock=(int)$stockQuery->fetchColumn();
                $pdo->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$newStock,$productId]);
                eventLog($pdo,'inventory.stock_updated',['product_id'=>$productId,'old_stock'=>$oldStock,'new_stock'=>$newStock]);
            }
            $pdo->commit();
            $_SESSION['flash']='Stock actualizado.';
        }
        catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            $_SESSION['error']=$e instanceof DomainException?$e->getMessage():'No se pudo actualizar el stock.';
        }
    }
    go('?page=admin&key='.rawurlencode($adminKey));
}
if($action==='add' && $_SERVER['REQUEST_METHOD']==='POST'){
    $id=(int)($_POST['id']??0);
    $optionValue=trim($_POST['option_value']??'');
    $option2Value=trim($_POST['option2_value']??'');
    $qty=max(1,min(10,(int)($_POST['qty']??1)));
    $s=$pdo->prepare('SELECT id,name,stock FROM products WHERE id=? AND active=1');
    $s->execute([$id]);
    $p=$s->fetch();
    if($p){
        $variant=null;
        if($optionValue!==''){
            $s=$pdo->prepare("SELECT id,sku,option_name,option_value,option2_name,option2_value,stock FROM product_variants WHERE product_id=? AND option_value=? AND COALESCE(option2_value,'')=? AND active=1");
            $s->execute([$id,$optionValue,$option2Value]);
            $variant=$s->fetch();
        }
        $s=$pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=? AND active=1');
        $s->execute([$id]);
        $hasVariants=(int)$s->fetchColumn()>0;
        $availableStock=$variant?(int)$variant['stock']:(int)$p['stock'];
        if($hasVariants && (!$variant || $availableStock<1)){
            $_SESSION['error']='La combinación seleccionada no está disponible.';
            go('?page=product&id='.$id);
        }
        if((!$hasVariants || $variant) && $availableStock>0){
            $lineKey=$variant ? $id.':'.$variant['id'] : (string)$id;
            $_SESSION['cart'][$lineKey]=min(10,$availableStock,($_SESSION['cart'][$lineKey]??0)+$qty);
            eventLog($pdo,'cart.item_added',['product_id'=>$id,'variant_id'=>$variant['id']??null,'variant_sku'=>$variant['sku']??null,'variant_value'=>$variant ? $variant['option_value'].(!empty($variant['option2_value']) ? ' / '.$variant['option2_value'] : '') : null,'name'=>$p['name'],'quantity'=>$qty]);
        $_SESSION['flash']='Añadido al carrito.';
        }
    }
    go('?page=cart');
}
if($action==='remove' && $_SERVER['REQUEST_METHOD']==='POST'){
    $lineKey=(string)($_POST['line_key']??'');
    if(array_key_exists($lineKey,$_SESSION['cart'])){
        unset($_SESSION['cart'][$lineKey]);
        $_SESSION['flash']='Producto eliminado de la cesta.';
    }
    go('?page=cart');
}
if($action==='update_quantity' && $_SERVER['REQUEST_METHOD']==='POST'){
    $lineKey=(string)($_POST['line_key']??'');
    $quantity=filter_var($_POST['qty']??null,FILTER_VALIDATE_INT);
    $cartItem=null;
    foreach(cartRows($pdo) as $item){
        if($item['line_key']===$lineKey){
            $cartItem=$item;
            break;
        }
    }
    if(!$cartItem || $quantity===false || $quantity<1 || $quantity>(int)$cartItem['available_stock'] || $quantity>10){
        $_SESSION['error']='La cantidad debe ser de 1 a 10 unidades y no superar el stock disponible.';
    }
    else{
        $_SESSION['cart'][$lineKey]=$quantity;
        $_SESSION['flash']='Cantidad actualizada.';
    }
    go('?page=cart');
}
if($action==='start_checkout'){
    eventLog($pdo,'checkout.started',['items'=>count($_SESSION['cart'])]);
    go('?page=checkout');
}
if($action==='checkout' && $_SERVER['REQUEST_METHOD']==='POST'){
    $stripeConfig=$config['stripe']??[];
    if(empty($stripeConfig['enabled'])||empty($stripeConfig['secret_key'])||empty($stripeConfig['webhook_secret'])){
        $_SESSION['error']='El pago con Stripe aún no está configurado. Contacta con la tienda.';
        go('?page=checkout');
    }
    $name=trim($_POST['name']??'');
    $emailInput=trim($_POST['email']??'');
    $email=filter_var($emailInput,FILTER_VALIDATE_EMAIL);
    $address=trim($_POST['address']??'');
    $postal=trim($_POST['postal_code']??'');
    $city=trim($_POST['city']??'');
    $items=cartRows($pdo);
    if(!$email||strlen($emailInput)>190){
        $_SESSION['error']='Introduce una dirección de correo electrónico válida.';
        go('?page=checkout');
    }
    if(!preg_match('/^(0[1-9]|[1-4][0-9]|5[0-2])[0-9]{3}$/D',$postal)){
        $_SESSION['error']='Introduce un código postal válido de España: cinco cifras y provincia del 01 al 52.';
        go('?page=checkout');
    }
    if(!$name||!$email||!$address||!$postal||!$city||!$items){
        $_SESSION['error']='Completa todos los campos y añade algún producto.';
        go('?page=checkout');
    }
    foreach($items as $p)if($p['qty']>10||$p['qty']>$p['available_stock']){
        $_SESSION['error']=$p['qty']>10?'Puedes pedir como máximo 10 unidades de cada producto.':'No hay stock suficiente de '.$p['name'].'.';
        go('?page=checkout');
    }
    $subtotal=0;
    foreach($items as $p)$subtotal+=$p['price']*$p['qty'];
    $shipping=shippingCost(cartWeightGrams($items), $subtotal, $config);
    $discount=isset($_POST['promo'])&&strtoupper(trim($_POST['promo']))==='GADGETSY10'?round($subtotal*.10,2):0;
    $tax=includedVat($subtotal-$discount+$shipping,$config);
    $total=$subtotal-$discount+$shipping;
    try{
        $pdo->beginTransaction();
        $s=$pdo->prepare('INSERT INTO customers(name,email,address,postal_code,city) VALUES(?,?,?,?,?)');
        $s->execute([$name,$email,$address,$postal,$city]);
        $cid=$pdo->lastInsertId();
        $code='VG-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        $s=$pdo->prepare("INSERT INTO orders(order_code,customer_id,status,subtotal,shipping,tax,discount,total) VALUES(?,?,'payment_pending',?,?,?,?,?)");
        $s->execute([$code,$cid,$subtotal,$shipping,$tax,$discount,$total]);
        $oid=$pdo->lastInsertId();
        foreach($items as $p){
            $s=$pdo->prepare('INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity,variant_id,variant_label,variant_sku) VALUES(?,?,?,?,?,?,?,?)');
            $s->execute([$oid,$p['id'],$p['name'],$p['price'],$p['qty'],$p['variant_id'],$p['variant_label'],$p['variant_sku']]);
            $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?')->execute([$p['qty'],$p['id']]);
            if($p['variant_id']){
                $pdo->prepare('UPDATE product_variants SET stock=stock-? WHERE id=?')->execute([$p['qty'],$p['variant_id']]);
            }
        }
        eventLog($pdo,'order.created',['order_id'=>$oid,'order_code'=>$code,'total'=>$total]);
        $pdo->commit();
    }
    catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        http_response_code(500);
        exit('No se pudo crear el pedido. Revisa la base de datos.');
    }
    $session=null;
    try{
        $order=['id'=>$oid,'order_code'=>$code,'total'=>$total,'email'=>$email];
        $session=createStripeCheckout($config,$order,$items);
        $pdo->prepare("INSERT INTO payments(order_id,method,status,amount,reference_code) VALUES(?,'stripe','pending',?,?)")
            ->execute([$oid,$total,$session['id']]);
        try{eventLog($pdo,'payment.stripe_checkout_started',['order_id'=>$oid,'order_code'=>$code,'session_id'=>$session['id']]);}
        catch(Throwable $ignore){error_log('[GadgetsY+] No se pudo registrar el evento de inicio de Stripe.');}
    }
    catch(Throwable $e){
        error_log('[GadgetsY+] No se pudo iniciar Stripe (' . get_class($e) . ').');
        $safeToRelease=!$session;
        if($session&&!empty($session['id'])){
            try{
                $expired=stripeApiRequest($stripeConfig,'POST','checkout/sessions/'.rawurlencode($session['id']).'/expire');
                $safeToRelease=($expired['status']??'')==='expired';
            }
            catch(Throwable $ignore){$safeToRelease=false;}
            if(!$safeToRelease){
                try{
                    $check=$pdo->prepare("SELECT id FROM payments WHERE method='stripe' AND reference_code=?");
                    $check->execute([$session['id']]);
                    if(!$check->fetchColumn()){
                        $pdo->prepare("INSERT INTO payments(order_id,method,status,amount,reference_code) VALUES(?,'stripe','pending',?,?)")
                            ->execute([$oid,$total,$session['id']]);
                    }
                }
                catch(Throwable $ignore){error_log('[GadgetsY+] No se pudo guardar la sesión Stripe pendiente; requiere revisión manual.');}
            }
        }
        if($safeToRelease){
            try{cancelUnstartedStripeOrder($pdo,(int)$oid);}
            catch(Throwable $ignore){error_log('[GadgetsY+] No se pudo liberar la reserva de stock tras fallar Stripe.');}
            $_SESSION['error']='No se pudo iniciar el pago. No se ha cobrado el pedido; inténtalo de nuevo.';
        }
        else{
            $_SESSION['error']='No se pudo confirmar el estado de la sesión de pago. No repitas el pedido; contacta con soporte indicando su código.';
        }
        go('?page=checkout');
    }
    $_SESSION['cart']=[];
    go($session['url']);
}
if($action==='support'&&$_SERVER['REQUEST_METHOD']==='POST'){
    $email=filter_var(trim($_POST['email']??''),FILTER_VALIDATE_EMAIL);
    $msg=trim($_POST['message']??'');
    if($email&&strlen($msg)>=8){
        eventLog($pdo,'support.requested',['email'=>$email,'message'=>mb_substr($msg,0,1000)]);
        $mailConfig=$config['mail']??[];
        $supportAddress=$mailConfig['support']['address']??'soporte@gadgetsymas.onl';
        $supportBody="Nueva consulta desde el formulario de GadgetsY+.\n\n"
            .'Email para responder: '.$email."\n\nConsulta:\n".mb_substr($msg,0,1000);
        $sent=sendStoreEmail($mailConfig,'support',$supportAddress,'Nueva consulta de ayuda',$supportBody,$email);
        if(!empty($mailConfig['enabled'])&&!$sent){
            $_SESSION['flash']='Solicitud registrada, pero no se pudo enviar el aviso por correo. Inténtalo de nuevo más tarde.';
        }
        else{
            if(!empty($mailConfig['enabled'])){
                $ack="Hemos recibido tu consulta de ayuda en GadgetsY+.\n\n"
                    .'Tu mensaje: '."\n".mb_substr($msg,0,1000)."\n\nTe responderemos a esta dirección cuando podamos.\n\nGadgetsY+";
                sendStoreEmail($mailConfig,'support',$email,'Hemos recibido tu consulta',$ack);
            }
            $_SESSION['flash']='Solicitud registrada. Te responderemos pronto.';
        }
    }
    else $_SESSION['error']='Indica un email válido y una consulta de al menos 8 caracteres.';
    go('?page=support');
}
$page=$_GET['page']??'home';
$flash=$_SESSION['flash']??null;
$error=$_SESSION['error']??null;
unset($_SESSION['flash'],$_SESSION['error']);
function headerView($title,$page){
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>
<?=h($title)?> · GadgetsY+</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
<a class="brand" href="?">
<img class="brand-logo" src="assets/images/logo.png" alt="" width="48" height="48">
<span class="brand-name">Gadgets<span>Y+</span></span>
</a>
<nav>
<a href="?">Tienda</a>
<a class="cart-link" href="?page=cart" aria-label="Carrito, <?=array_sum($_SESSION['cart'])?> artículos">
<img class="cart-icon" src="assets/images/carrito.png" alt="" width="24" height="24" onerror="this.hidden=true">
<span>Carrito (<?=array_sum($_SESSION['cart'])?>)</span>
</a>
<a class="support-link" href="?page=support">
<img class="support-icon" src="assets/images/soporte.png" alt="" width="24" height="24" onerror="this.hidden=true">
<span>Ayuda</span>
</a>
<a href="?page=about">Sobre nosotros</a>
<a href="?page=returns">Devoluciones</a>
</nav>
</header>
<main>
<?php if($GLOBALS['flash']):?><p class="notice">
<?=h($GLOBALS['flash'])?></p>
<?php endif?>
<?php if($GLOBALS['error']):?><p class="error">
<?=h($GLOBALS['error'])?></p>
<?php endif?>
<?php
}
function footerView(){
?></main>
<footer><span>GadgetsY+ · Envíos solo a España</span><span><a href="?page=about">Sobre nosotros</a> · <a href="?page=returns">Política de devoluciones</a> · <?=h($GLOBALS['liveStripe']?'Pagos con Stripe':($GLOBALS['stripeActive']?'Stripe · modo de pruebas':'Pagos Stripe pendientes de configuración'))?></span>
</footer>
</body>
</html>
<?php
}
if($page==='admin'){
    $key=$_GET['key']??'';
    if(!hash_equals((string)$config['admin_key'],(string)$key)||$key==='CAMBIAR-ANTES-DE-PUBLICAR'){
        http_response_code(403);
        exit('Acceso no autorizado. Configura admin_key en config.php.');
    }
    $_SESSION['admin_csrf']??=bin2hex(random_bytes(32));
    $adminCsrf=$_SESSION['admin_csrf'];
    $stockProducts=$pdo->query('SELECT id,sku,name,stock FROM products ORDER BY name')->fetchAll();
    $stockVariants=$pdo->query('SELECT id,product_id,sku,option_name,option_value,option2_name,option2_value,stock FROM product_variants WHERE active=1 ORDER BY product_id,option_value,option2_value')->fetchAll();
    $stockVariantsByProduct=[];
    foreach($stockVariants as $variant)$stockVariantsByProduct[$variant['product_id']][]=$variant;
    $orders=$pdo->query('SELECT o.*,c.name,c.email FROM orders o JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 100')->fetchAll();
    $orderItemsByOrder=[];
    $orderIds=array_column($orders,'id');
    if($orderIds){
        $placeholders=implode(',',array_fill(0,count($orderIds),'?'));
        $itemQuery=$pdo->prepare("SELECT order_id,product_name,variant_label,variant_sku,quantity FROM order_items WHERE order_id IN ($placeholders) ORDER BY id");
        $itemQuery->execute($orderIds);
        foreach($itemQuery as $item){
            $orderItemsByOrder[$item['order_id']][]=$item;
        }
    }
    headerView('Administración',$page);
?><h1>Administración</h1>
<section class="inventory-admin">
<h2>Gestionar stock</h2>
<p class="fine">El stock de productos con variantes se ajusta por variante; el total del producto se sincroniza automáticamente. El checkout reserva stock mientras se completa el pago.</p>
<div class="inventory-grid">
<?php foreach($stockProducts as $product):?><article class="inventory-card">
<h3><?=h($product['name'])?></h3>
<small><?=h($product['sku'])?> · Stock total: <?=h($product['stock'])?></small>
<?php if(!empty($stockVariantsByProduct[$product['id']])):?>
<?php foreach($stockVariantsByProduct[$product['id']] as $variant):
    $variantLabel=$variant['option_name'].': '.$variant['option_value'];
    if($variant['option2_name'])$variantLabel.=' / '.$variant['option2_name'].': '.$variant['option2_value'];
?><form method="post" class="stock-edit-form">
<input type="hidden" name="action" value="update_stock">
<input type="hidden" name="admin_key" value="<?=h($key)?>">
<input type="hidden" name="csrf_token" value="<?=h($adminCsrf)?>">
<input type="hidden" name="product_id" value="<?=h($product['id'])?>">
<input type="hidden" name="variant_id" value="<?=h($variant['id'])?>">
<span class="stock-option"><?=h($variantLabel)?><small>SKU <?=h($variant['sku'])?></small></span>
<label>Unidades<input type="number" name="stock" min="0" max="100000" value="<?=h($variant['stock'])?>" required></label>
<button type="submit" class="remove-button">Guardar</button>
</form>
<?php endforeach?>
<?php else:?><form method="post" class="stock-edit-form">
<input type="hidden" name="action" value="update_stock">
<input type="hidden" name="admin_key" value="<?=h($key)?>">
<input type="hidden" name="csrf_token" value="<?=h($adminCsrf)?>">
<input type="hidden" name="product_id" value="<?=h($product['id'])?>">
<input type="hidden" name="variant_id" value="0">
<span class="stock-option">Producto sin variantes</span>
<label>Unidades<input type="number" name="stock" min="0" max="100000" value="<?=h($product['stock'])?>" required></label>
<button type="submit" class="remove-button">Guardar</button>
</form><?php endif?>
</article><?php endforeach?>
</div>
</section>
<h2>Pedidos recientes</h2>
<div class="tablewrap">
<table>
<tr>
<th>Pedido</th>
<th>Cliente</th>
<th>Fecha</th>
<th>Total</th>
<th>Estado</th>
</tr>
<?php foreach($orders as $o):?><tr>
<td>
<?=h($o['order_code'])?><br><small><?php foreach($orderItemsByOrder[$o['id']]??[] as $item):?><?=h($item['product_name'])?><?php if($item['variant_sku']):?> · <?=h($item['variant_label'])?> · SKU <?=h($item['variant_sku'])?><?php endif?> × <?=h($item['quantity'])?><br>
<?php endforeach?></small></td>
<td>
<?=h($o['name'])?><br>
<?=h($o['email'])?></td>
<td>
<?=h($o['created_at'])?></td>
<td>
<?=money($o['total'])?></td>
<td>
<?=h($o['status'])?></td>
</tr>
<?php endforeach?></table>
</div>
<?php footerView();
    exit;
}
if($page==='home'){
    $cat=$_GET['cat']??'';
    $cats=$pdo->query('SELECT DISTINCT category FROM products WHERE active=1 ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
    if($cat){
        $s=$pdo->prepare('SELECT p.*, EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.active=1) AS has_variants FROM products p WHERE p.active=1 AND p.category=? ORDER BY p.id');
        $s->execute([$cat]);
        $products=$s->fetchAll();
    }
    else $products=$pdo->query('SELECT p.*, EXISTS(SELECT 1 FROM product_variants v WHERE v.product_id=p.id AND v.active=1) AS has_variants FROM products p WHERE p.active=1 ORDER BY p.id')->fetchAll();
    headerView('Gadgets virales',$page);
?><section class="hero">
<div>
<p class="eyebrow">Pequeños gadgets, grandes ideas</p>
<h1>Lo viste en todas partes.<br>
<em>Ahora está aquí.</em>
</h1>
<p>Una selección de accesorios curiosos, útiles y virales. Envío gratis desde 
<?=money($config['free_shipping_from'])?>.</p>
<a class="button" href="#catalogo">Explorar catálogo</a>
</div>
<div class="hero-art">
<span>DESCUBRE<br>LO NUEVO</span>
</div>
</section>
<section id="catalogo">
<div class="section-head">
<div>
<p class="eyebrow">La selección</p>
<h2>Encuentra tu próximo favorito</h2>
</div>
</div>
<div class="filters">
<a class="
<?=!$cat?'selected':''?>" href="?">Todos</a>
<?php foreach($cats as $c):?><a class="
<?=$cat===$c?'selected':''?>" href="?cat=
<?=urlencode($c)?>">
<?=h($c)?></a>
<?php endforeach?></div>
<div class="grid">
<?php foreach($products as $p):?><article class="card">
<a class="product-art" href="?page=product&amp;id=
<?=$p['id']?>">
<img src="<?=h(productImage($p['sku']))?>" alt="<?=h($p['name'])?>" loading="lazy" width="640" height="480">
<?php if($p['badge']):?><b>
<?=h($p['badge'])?></b>
<?php endif?></a>
<div class="card-info">
<small>
<?=h($p['category'])?></small>
<h3>
<a href="?page=product&amp;id=
<?=$p['id']?>">
<?=h($p['name'])?></a>
</h3>
<div class="price">
<?=money($p['price'])?></div>
<small class="vat-note">IVA <?=round($config['tax_rate']*100)?>% incluido</small>
<?php if($p['has_variants']):?>
<a class="button dark" href="?page=product&amp;id=<?=$p['id']?>">Elegir opción</a>
<?php else:?><form method="post">
<input type="hidden" name="action" value="add">
<input type="hidden" name="id" value="
<?=$p['id']?>">
<button class="button dark" 
<?=$p['stock']<1?'disabled':''?>>Añadir al carrito</button>
</form>
<?php endif?>
</div>
</article>
<?php endforeach?></div>
</section>
<?php footerView();
    exit;
}
if($page==='product'){
    $id=(int)($_GET['id']??0);
    $s=$pdo->prepare('SELECT * FROM products WHERE id=? AND active=1');
    $s->execute([$id]);
    $p=$s->fetch();
    if(!$p){
        http_response_code(404);
        exit('Producto no encontrado');
    }
    $variantQuery=$pdo->prepare('SELECT id,sku,option_name,option_value,option2_name,option2_value,stock FROM product_variants WHERE product_id=? AND active=1 ORDER BY option_value,option2_value');
    $variantQuery->execute([$id]);
    $variants=$variantQuery->fetchAll();
    $hasAvailableVariant=false;
    foreach($variants as $variant){
        if((int)$variant['stock']>0)$hasAvailableVariant=true;
    }
    $primaryOptionName=$variants[0]['option_name']??'';
    $secondaryOptionName=$variants[0]['option2_name']??'';
    $primaryOptions=[];
    $secondaryOptions=[];
    foreach($variants as $variant){
        $primaryOptions[$variant['option_value']]=true;
        if($variant['option2_value']!==null)$secondaryOptions[$variant['option2_value']]=true;
    }
    eventLog($pdo,'product.viewed',['product_id'=>$id,'name'=>$p['name']]);
    headerView($p['name'],$page);
?><a class="back" href="?">← Volver al catálogo</a>
<section class="detail">
<div class="detail-art">
<img src="<?=h(productImage($p['sku']))?>" alt="<?=h($p['name'])?>" width="900" height="700">
</div>
<div>
<p class="eyebrow">
<?=h($p['category'])?></p>
<h1>
<?=h($p['name'])?></h1>
<div class="price big">
<?=money($p['price'])?></div>
<small class="vat-note">Base: <?=money($p['price']-includedVat((float)$p['price'],$config))?> · IVA incluido (<?=round($config['tax_rate']*100)?>%): <?=money(includedVat((float)$p['price'],$config))?> · PVP: <?=money($p['price'])?></small>
<p>
<?=h($p['description'])?></p>
<p class="fine">Peso para el envío: <?=h($p['weight_grams'])?> g</p>
<?php if($variants):?>
<p class="stock">Elige las opciones; el stock se valida al añadir a la cesta.</p>
<?php else:?><p class="stock">
<?=$p['stock']>0?'Disponible · '.(int)$p['stock'].' unidades':'Agotado'?></p>
<?php endif?>
<form method="post" class="addform">
<input type="hidden" name="action" value="add">
<input type="hidden" name="id" value="
<?=$p['id']?>">
<?php if($variants):?><label class="variant-select"><?=h($primaryOptionName)?>
<select name="option_value" required>
<option value="">Elige una opción</option>
<?php foreach(array_keys($primaryOptions) as $optionValue):?><option value="<?=h($optionValue)?>"><?=h($optionValue)?></option>
<?php endforeach?></select>
</label>
<?php if($secondaryOptionName):?><label class="variant-select"><?=h($secondaryOptionName)?>
<select name="option2_value" required>
<option value="">Elige una opción</option>
<?php foreach(array_keys($secondaryOptions) as $option2Value):?><option value="<?=h($option2Value)?>"><?=h($option2Value)?></option>
<?php endforeach?></select>
</label>
<?php endif?>
<?php endif?>
<input type="number" name="qty" min="1" max="10" value="1">
<button class="button dark" 
<?=($variants ? !$hasAvailableVariant : $p['stock']<1)?'disabled':''?>>Añadir al carrito</button>
</form>
<p class="fine">El pago se completa en la página segura de Stripe; aquí no se introducen datos de tarjeta.</p>
</div>
</section>
<?php footerView();
    exit;
}
if($page==='cart'){
    $items=cartRows($pdo);
    $subtotal=cartSubtotal($items);
    $totalWeight=cartWeightGrams($items);
    $shipping=shippingCost($totalWeight, $subtotal, $config);
    headerView('Tu carrito',$page);
?><h1>Tu carrito</h1>
<?php if(!$items):?><div class="empty">
<p>Tu carrito está esperando un gadget.</p>
<a class="button" href="?">Ver productos</a>
</div>
<?php else:
?><div class="tablewrap">
<table>
<tr>
<th>Producto</th>
<th>Precio (IVA incl.)</th>
<th>Cantidad</th>
<th>Importe (IVA incl.)</th>
<th></th>
</tr>
<?php foreach($items as $p):
?><tr>
<td>
<?=h($p['name'])?><?php if($p['variant_label']):?><br><small><?=h($p['variant_label'])?> · SKU <?=h($p['variant_sku'])?></small><?php endif?></td>
<td>
<?=money($p['price'])?></td>
<td>
<form method="post" class="quantity-form">
<input type="hidden" name="action" value="update_quantity">
<input type="hidden" name="line_key" value="<?=h($p['line_key'])?>">
<label class="sr-only" for="qty-<?=h($p['line_key'])?>">Cantidad de <?=h($p['name'])?></label>
<input id="qty-<?=h($p['line_key'])?>" type="number" name="qty" min="1" max="<?=max(1,min(10,(int)$p['available_stock']))?>" value="<?=h($p['qty'])?>" required>
<button type="submit" class="remove-button">Actualizar</button>
</form></td>
<td>
<?=money($p['price']*$p['qty'])?></td>
<td><form method="post" class="remove-form">
<input type="hidden" name="action" value="remove">
<input type="hidden" name="line_key" value="<?=h($p['line_key'])?>">
<button type="submit" class="remove-button" aria-label="Quitar <?=h($p['name'])?> de la cesta">Quitar</button>
</form></td>
</tr>
<?php endforeach?></table>
</div>
<div class="summary">
<p>Subtotal (IVA incluido) <b>
<?=money($subtotal)?></b>
</p>
<p>Envío <b>
<?=$shipping===0.0?'Gratis':money($shipping)?></b>
</p>
<small>Base <?=money($config['shipping_fee'])?> hasta <?=number_format($config['shipping_step_grams'], 0, ',', '.')?> g; añade <?=money($config['shipping_extra_per_step'])?> por cada tramo adicional. Gratis desde <?=money($config['free_shipping_from'])?>. Código GADGETSY10: 10% de descuento.</small>
<a class="button dark" href="?action=start_checkout">Continuar al checkout</a>
</div>
<?php endif?>
<?php footerView();
    exit;
}
if($page==='checkout'){
    $items=cartRows($pdo);
    $subtotal=cartSubtotal($items);
    $totalWeight=cartWeightGrams($items);
    $shipping=shippingCost($totalWeight, $subtotal, $config);
    headerView('Finalizar pedido',$page);
?><h1>Finalizar pedido</h1>
<p class="fine">Realizamos envíos únicamente a España.</p>
<?php if(!$items):?><p>El carrito está vacío. <a href="?">Volver a la tienda</a>
</p>
<?php else:?><div class="checkout">
<form method="post" class="form">
<input type="hidden" name="action" value="checkout">
<label>Nombre completo<input required name="name" maxlength="120" autocomplete="name">
</label>
<label>Email<input required type="email" name="email" maxlength="190" autocomplete="email" placeholder="nombre@dominio.es">
</label>
<label>Dirección<input required name="address" maxlength="255" autocomplete="street-address">
</label>
<div class="twocol">
<label>Código postal<input required name="postal_code" inputmode="numeric" pattern="(0[1-9]|[1-4][0-9]|5[0-2])[0-9]{3}" minlength="5" maxlength="5" title="Cinco cifras; los dos primeros dígitos deben estar entre 01 y 52." placeholder="28001" autocomplete="postal-code">
</label>
<label>Ciudad<input required name="city" maxlength="100" autocomplete="address-level2">
</label>
</div>
<label>Código promocional (opcional)<input name="promo" placeholder="GADGETSY10">
</label>
<button class="button dark">Ir al pago seguro</button>
</form>
<aside class="summary">
<h3>Resumen</h3>
<?php foreach($items as $p):?><p>
<?=h($p['name'])?><?php if($p['variant_label']):?> · <?=h($p['variant_label'])?> · SKU <?=h($p['variant_sku'])?><?php endif?> × 
<?=$p['qty']?> · PVP (IVA incluido) <?=money($p['price']*$p['qty'])?> · IVA incluido <?=money(includedVat((float)$p['price']*$p['qty'],$config))?>
</p>
<?php endforeach?><hr>
<p>Subtotal (IVA incluido) <b>
<?=money($subtotal)?></b>
</p>
<p>Envío (IVA incluido) <b>
<?=$shipping===0.0?'Gratis':money($shipping)?></b>
</p>
<p>IVA total incluido (<?=round($config['tax_rate']*100)?>%) <b><?=money(includedVat((float)$subtotal+$shipping,$config))?></b></p>
<p class="checkout-total">Total estimado, IVA incluido <b><?=money($subtotal+$shipping)?></b></p>
<small>Envío: base <?=money($config['shipping_fee'])?> hasta <?=number_format($config['shipping_step_grams'], 0, ',', '.')?> g y <?=money($config['shipping_extra_per_step'])?> por cada tramo adicional. Gratis desde <?=money($config['free_shipping_from'])?>. Si aplicas GADGETSY10, el descuento reduce el PVP y el IVA incluido se recalcula al confirmar.</small>
</aside>
</div>
<?php endif?>
<?php footerView();
    exit;
}
if($page==='payment'){
    $orderCode=trim($_GET['order']??'');
    $s=$pdo->prepare("SELECT o.order_code,o.total,p.reference_code FROM orders o JOIN payments p ON p.order_id=o.id WHERE o.order_code=? AND o.status='payment_pending' AND p.method='stripe' AND p.status='pending'");
    $s->execute([$orderCode]);
    $o=$s->fetch();
    if(!$o)go('?page=cart');
    $checkoutUrl='';
    try{
        $session=stripeApiRequest($config['stripe'],'GET','checkout/sessions/'.rawurlencode($o['reference_code']));
        if(($session['status']??'')==='open'&&!empty($session['url']))$checkoutUrl=$session['url'];
    }
    catch(Throwable $e){error_log('[GadgetsY+] No se pudo recuperar la sesión Stripe (' . get_class($e) . ').');}
    headerView('Continuar con el pago',$page);
?><div class="center">
<p class="eyebrow">Pago seguro</p>
<h1>Continuar con el pago</h1>
<p>Pedido 
<?=h($o['order_code'])?> · Total <b>
<?=money($o['total'])?></b>
</p>
<?php if($checkoutUrl):?><p>Completa el pago en la página segura de Stripe.</p>
<a class="button dark" href="<?=h($checkoutUrl)?>">Volver a Stripe</a>
<?php else:?><p>La sesión de pago ya no está disponible. Si ya realizaste el pago, espera un momento a que se confirme; si no, vuelve a la tienda para crear otro pedido.</p>
<a class="button" href="?">Volver a la tienda</a><?php endif?>
</div>
<?php footerView();
    exit;
}
if($page==='success'){
    $sessionId=trim($_GET['session_id']??'');
    $verifiedOrder=null;
    if(preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/',$sessionId)){
        try{
            $session=stripeApiRequest($config['stripe'],'GET','checkout/sessions/'.rawurlencode($sessionId));
            $verifiedOrder=completeStripeOrder($pdo,$config,$sessionId,$session);
        }
        catch(Throwable $e){error_log('[GadgetsY+] No se pudo verificar el pago Stripe (' . get_class($e) . ').');}
    }
    $code=$verifiedOrder['code']??'';
    $emailNotice=$_SESSION['email_notice']??null;
    unset($_SESSION['email_notice']);
    headerView('Pedido confirmado',$page);
?><div class="center">
<?php if($code):?>
<div class="success-icon">✓</div>
<h1>¡Pedido registrado!</h1>
<?php if($emailNotice):?><p class="notice"><?=h($emailNotice)?></p><?php endif?>
<p>Tu identificador de pedido es <strong>
<?=h($code)?></strong>.</p>
<p>Pago confirmado mediante Stripe.</p>
<?php if(!$emailNotice&&!empty($config['mail']['enabled'])):?><p>Recibirás la confirmación del pedido por correo.</p><?php endif?>
<a class="button" href="?">Seguir explorando</a>
<?php else:?><h1>Estamos comprobando el pago</h1>
<p>El pago todavía no aparece confirmado. Si ya completaste el proceso, espera unos instantes y vuelve a cargar esta página.</p>
<a class="button" href="?page=cart">Volver a la tienda</a><?php endif?>
</div>
<?php footerView();
    exit;
}
if($page==='about'){
    headerView('Sobre nosotros',$page);
?><section class="policy-page">
<p class="eyebrow">GadgetsY+</p>
<h1>Sobre nosotros</h1>
<p>GadgetsY+ es una tienda de demostración dedicada a gadgets prácticos y curiosos, seleccionados para hacer más sencillas algunas tareas del día a día.</p>
<p>En este catálogo encontrarás productos de tecnología y accesorios para el hogar, con información de producto, variantes y precios de ejemplo.</p>
<?php if($liveStripe):?><p>Los pagos se procesan en la página segura de Stripe.</p>
<?php else:?><p>Este sitio forma parte de un proyecto académico. Stripe está en modo de pruebas o pendiente de configuración; no se cobran compras reales.</p><?php endif?>
<a class="button dark" href="?">Ver productos</a>
</section>
<?php footerView();
    exit;
}
if($page==='returns'){
    headerView('Política de devoluciones',$page);
?><section class="policy-page">
<p class="eyebrow">Información de compra</p>
<h1>Política de devoluciones</h1>
<?php if($liveStripe):?><p>Para solicitar ayuda sobre un pedido o una devolución, escribe a <a href="mailto:soporte@gadgetsymas.onl">soporte@gadgetsymas.onl</a> e incluye el identificador del pedido.</p>
<p class="fine">El titular de la tienda debe publicar aquí sus condiciones completas de devolución antes de aceptar pagos reales.</p>
<?php else:?><p><strong>GadgetsY+ es una demo académica.</strong> Stripe está en modo de pruebas o pendiente de configuración; no se cobran ventas reales ni se envían productos. Por ello, no se tramitan devoluciones de pedidos de prueba.</p><?php endif?>
<h2>¿Tienes una consulta sobre la demostración?</h2>
<p>Si has detectado un problema o quieres dejar constancia de una incidencia, puedes enviarnos una solicitud desde la página de ayuda.</p>
<a class="button dark" href="?page=support">Contactar con ayuda</a>
</section>
<?php footerView();
    exit;
}
if($page==='support'){
    headerView('Ayuda y soporte',$page);
?><h1>¿En qué podemos ayudarte?</h1>
<p>Registra una consulta postventa. Quedará almacenada como evento para su seguimiento.</p>
<form class="form narrow" method="post">
<input type="hidden" name="action" value="support">
<label>Tu email<input type="email" name="email" required maxlength="190">
</label>
<label>Consulta<textarea name="message" required minlength="8" maxlength="1000" rows="5">
</textarea>
</label>
<button class="button dark">Enviar consulta</button>
</form>
<?php footerView();
    exit;
}
http_response_code(404);
headerView('No encontrado',$page);
?><h1>Página no encontrada</h1>
<?php footerView();
