<?php
// POST /api/v1/venta/caja/apertura
if ($method === 'POST' && $path === '/api/v1/venta/caja/apertura') {
    $b = get_body();
    require_fields($b, ['idcaja','fecha','montoapertura','idestado']);
    begin_tx($pdo);
    try {
        $sql = "INSERT INTO tbcontrolcaja (idcaja, fecha, idestado, montoapertura, montocierre)
                VALUES (:c,:f,:e,:m,0)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':c'=>$b['idcaja'], ':f'=>$b['fecha'], ':e'=>$b['idestado'], ':m'=>$b['montoapertura']]);
        // Asiento apertura (puedes ajustar a tu política interna)
        $idd = create_asiento($pdo, date('Y-m-d'), ACC_CAJA, ACC_BANCOS, (float)$b['montoapertura'], 'Apertura caja', 10);
        commit_tx($pdo);
        send_json(['ok'=>true,'iddiario'=>$idd], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error al abrir caja'); }
}

// POST /api/v1/venta/facturas
if ($method === 'POST' && $path === '/api/v1/venta/facturas') {
    $b = get_body();
    require_fields($b, ['fechafactura','nit','serie','idestado','idcajero','idvendedor','direccion','detalle','pagos','idtienda']);
    if (!is_array($b['detalle']) || count($b['detalle'])===0) send_error(422,'Detalle vacío');
    if (!is_array($b['pagos']) || count($b['pagos'])===0) send_error(422,'Pagos vacío');
    begin_tx($pdo);
    try {
        // Cliente (si no existe, lo crea—útil para CF)
        $q = $pdo->prepare("SELECT nit FROM tbcliente WHERE nit=:nit");
        $q->execute([':nit'=>$b['nit']]);
        if (!$q->fetch()) {
            $insC = $pdo->prepare("INSERT INTO tbcliente (nit, nombrecompleto, telefono, direccion) VALUES (:nit, :nom, '', :dir)");
            $insC->execute([':nit'=>$b['nit'], ':nom'=>($b['cliente_nombre'] ?? 'Consumidor Final'), ':dir'=>$b['direccion']]);
        }

        // Maestro factura
        $stmtF = $pdo->prepare("INSERT INTO tbfactura (fechafactura, nit, serie, idestado, idcajero, idvendedor, direccion)
                                VALUES (:fec,:nit,:ser,:est,:caj,:ven,:dir)");
        $stmtF->execute([
            ':fec'=>$b['fechafactura'], ':nit'=>$b['nit'], ':ser'=>$b['serie'], ':est'=>$b['idestado'],
            ':caj'=>$b['idcajero'], ':ven'=>$b['idvendedor'], ':dir'=>$b['direccion']
        ]);
        $idfac = (int)$pdo->lastInsertId();

        $total = 0.0; $costoTotal = 0.0;
        foreach ($b['detalle'] as $it) {
            foreach (['idproducto','cantidad','precioventa'] as $f) if (!isset($it[$f])) send_error(422, "Falta $f en detalle");

            // Stock por tienda (FOR UPDATE evita carreras)
            $sel = $pdo->prepare("SELECT inventario FROM tbinventario WHERE idproducto=:p AND idtienda=:t FOR UPDATE");
            $sel->execute([':p'=>$it['idproducto'], ':t'=>$b['idtienda']]);
            $row = $sel->fetch();
            $cur = $row ? (int)$row['inventario'] : 0;
            if ($cur < (int)$it['cantidad']) { rollback_tx($pdo); send_error(409,'Stock insuficiente para producto '.$it['idproducto']); }
            $new = $cur - (int)$it['cantidad'];
            $upd = $pdo->prepare("UPDATE tbinventario SET inventario=:n WHERE idproducto=:p AND idtienda=:t");
            $upd->execute([':n'=>$new, ':p'=>$it['idproducto'], ':t'=>$b['idtienda']]);

            // Detalle de factura
            $insD = $pdo->prepare("INSERT INTO tbdetallefactura (idfactura, idproducto, cantidad, precioventa)
                                   VALUES (:f,:p,:c,:pv)");
            $insD->execute([':f'=>$idfac, ':p'=>$it['idproducto'], ':c'=>$it['cantidad'], ':pv'=>$it['precioventa']]);

            $total += (float)$it['precioventa'] * (int)$it['cantidad'];

            // costo unitario para costo de ventas
            $c = $pdo->prepare("SELECT costo FROM tbproducto WHERE idproducto=:p");
            $c->execute([':p'=>$it['idproducto']]);
            $costo = (float)($c->fetch()['costo'] ?? 0);
            $costoTotal += $costo * (int)$it['cantidad'];
        }

        // Pagos (validamos forma de pago exista)
        $sumPagos = 0.0;
        foreach ($b['pagos'] as $pg) {
            foreach (['idformapago','monto'] as $f) if (!isset($pg[$f])) send_error(422, "Falta $f en pagos");
            $selFp = $pdo->prepare("SELECT idformapago FROM tbformapago WHERE idformapago=:id");
            $selFp->execute([':id'=>$pg['idformapago']]);
            if (!$selFp->fetch()) { rollback_tx($pdo); send_error(422,'Forma de pago inválida'); }
            $insP = $pdo->prepare("INSERT INTO tbfacturaformapago (idfactura, idformapago, monto) VALUES (:f,:fp,:m)");
            $insP->execute([':f'=>$idfac, ':fp'=>$pg['idformapago'], ':m'=>$pg['monto']]);
            $sumPagos += (float)$pg['monto'];
        }
        if (round($sumPagos,2) != round($total,2)) {
            rollback_tx($pdo); send_error(422, 'La suma de pagos no coincide con el total de la factura');
        }

        // Asientos contables
        $ref = 'FAC-'.$idfac;
        $a1 = create_asiento($pdo, $b['fechafactura'], ACC_CAJA, ACC_VENTAS, $total, $ref.' Ventas', 2);
        $a2 = create_asiento($pdo, $b['fechafactura'], ACC_COSTO_VENTAS, ACC_INVENTARIOS, $costoTotal, $ref.' Costo', 3);

        commit_tx($pdo);
        send_json(['idfactura'=>$idfac,'total'=>round($total,2),'asientos'=>[$a1,$a2]], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error al crear factura'); }
}

// GET /api/v1/venta/facturas/{id}
{
    $params = [];
    if ($method === 'GET' && path_match('/api/v1/venta/facturas/{id}', $path, $params)) {
        $id = (int)$params['id'];
        $fac = $pdo->prepare("SELECT * FROM tbfactura WHERE idfactura=:id");
        $fac->execute([':id'=>$id]);
        $f = $fac->fetch();
        if (!$f) send_error(404,'Factura no encontrada');
        $det = $pdo->prepare("SELECT idproducto,cantidad,precioventa FROM tbdetallefactura WHERE idfactura=:id");
        $det->execute([':id'=>$id]);
        $pago = $pdo->prepare("SELECT idformapago,monto FROM tbfacturaformapago WHERE idfactura=:id");
        $pago->execute([':id'=>$id]);
        send_json(['factura'=>$f,'detalle'=>$det->fetchAll(),'pagos'=>$pago->fetchAll()]);
    }
}

// POST /api/v1/venta/caja/cierre
if ($method === 'POST' && $path === '/api/v1/venta/caja/cierre') {
    $b = get_body();
    require_fields($b, ['idcaja','fecha','montocierre','idestado']);
    begin_tx($pdo);
    try {
        $sql = "UPDATE tbcontrolcaja SET montocierre=:m, idestado=:e WHERE idcaja=:c AND fecha=:f";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':m'=>$b['montocierre'], ':e'=>$b['idestado'], ':c'=>$b['idcaja'], ':f'=>$b['fecha']]);
        // Asiento de cierre (si depositan a bancos)
        $idd = create_asiento($pdo, date('Y-m-d'), ACC_BANCOS, ACC_CAJA, (float)$b['montocierre'], 'Cierre caja', 11);
        commit_tx($pdo);
        send_json(['ok'=>true,'iddiario'=>$idd], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error al cerrar caja'); }
}

// POST /api/v1/venta/anular_facturas/{id}
{
    $params = [];
    if ($method === 'POST' && path_match('/api/v1/venta/anular_facturas/{id}', $path, $params)) 
    {
        $id = (int)$params['id'];
        $fac = $pdo->prepare("SELECT * FROM tbfactura WHERE idfactura=:id");
        $fac->execute([':id'=>$id]);
        $f = $fac->fetch();
        if (!$f) send_error(404,'Factura no encontrada');
        $det = $pdo->prepare("UPDATE tbfactura set idestado = 4 WHERE idfactura=:id");
        $det->execute([':id'=>$id]);        
        send_json(['factura'=>$f,'detalle'=>'Factura Anulada']);
    }
}


//POST /api/v1/venta/clientes
/*


{
  "nit": "1234567-8",
  "nombrecompleto": "Juan Pérez",
  "telefono": "44556677",
  "direccion": "Zona 10, Ciudad de Guatemala"
}
*/

if ($method === 'POST' && $path === '/api/v1/venta/clientes') {
    $b = get_body();
    require_fields($b, ['nit','nombrecompleto','telefono','direccion']);

    try {
        $stmt = $pdo->prepare("INSERT INTO tbcliente (nit, nombrecompleto, telefono, direccion)
                               VALUES (:nit,:nom,:tel,:dir)");
        $stmt->execute([
            ':nit' => $b['nit'],
            ':nom' => $b['nombrecompleto'],
            ':tel' => $b['telefono'],
            ':dir' => $b['direccion']
        ]);
        send_json(['ok' => true, 'nit' => $b['nit']], 201);
    } catch (Throwable $e) {
        send_error(500, 'Error al registrar cliente');
    }
}

//POST /api/v1/venta/cajeros
/*
{
  "nombrecompleto": "Carlos López",
  "fechaingreso": "2025-11-26",
  "idestadoempleado": 1,
  "telefono": "44556677",
  "direccion": "Zona 1, Ciudad de Guatemala"
}


*/

if ($method === 'POST' && $path === '/api/v1/venta/cajeros') {
    $b = get_body();
    require_fields($b, ['nombrecompleto','fechaingreso','idestadoempleado','telefono','direccion']);

    try {
        $stmt = $pdo->prepare("INSERT INTO tbempleado (nombrecompleto, fechaingreso, idestadoempleado, telefono, direccion)
                               VALUES (:nom,:fec,:est,:tel,:dir)");
        $stmt->execute([
            ':nom' => $b['nombrecompleto'],
            ':fec' => $b['fechaingreso'],
            ':est' => $b['idestadoempleado'],
            ':tel' => $b['telefono'],
            ':dir' => $b['direccion']
        ]);
        $idemp = (int)$pdo->lastInsertId();
        send_json(['ok' => true, 'idempleado' => $idemp], 201);
    } catch (Throwable $e) {
        send_error(500, 'Error al registrar cajero');
    }
}

?>