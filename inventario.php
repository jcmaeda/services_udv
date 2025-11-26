<?php
// GET /api/v1/inventario/productos?tienda=1
if ($method === 'GET' && $path === '/api/v1/inventario/productos') {
    $idtienda = isset($_GET['tienda']) ? (int)$_GET['tienda'] : DEFAULT_TIENDA;
    $sql = "SELECT pr.idproducto, pr.codigo, pr.descripcion, pr.preciounitario, pr.preciomayoreo, pr.idestado, pr.costo,
                   inv.inventario
            FROM tbproducto pr
            INNER JOIN tbinventario inv ON inv.idproducto = pr.idproducto
            WHERE pr.idestado = 1 AND  inv.idtienda = :idtienda";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':idtienda' => $idtienda]);
    $rows = $stmt->fetchAll();
    send_json(['items'=>$rows,'count'=>count($rows)]);
}
// POST /api/v1/inventario/productos
if ($method === 'POST' && $path === '/api/v1/inventario/productos') {
    $b = get_body();
    require_fields($b, ['codigo','descripcion','precio_unitario','precio_mayoreo','idestado','idproveedor','costo']);
    begin_tx($pdo);
    try {
        $stmt = $pdo->prepare("INSERT INTO tbproducto (codigo, descripcion, preciounitario, preciomayoreo, idestado, idproveedor, costo)
                               VALUES (:codigo,:desc,:pu,:pm,:estado,:prov,:costo)");
        $stmt->execute([
            ':codigo'=>$b['codigo'], ':desc'=>$b['descripcion'], ':pu'=>$b['precio_unitario'], ':pm'=>$b['precio_mayoreo'],
            ':estado'=>$b['idestado'], ':prov'=>$b['idproveedor'], ':costo'=>$b['costo']
        ]);
        $idp = (int)$pdo->lastInsertId();
        if (!empty($b['inventario_inicial'])) {
            $inv = $b['inventario_inicial'];
            require_fields($inv, ['idtienda','cantidad']);
            $stmtI = $pdo->prepare("INSERT INTO tbinventario (idproducto, idtienda, inventario) VALUES (:p,:t,:q)");
            $stmtI->execute([':p'=>$idp, ':t'=>$inv['idtienda'], ':q'=>$inv['cantidad']]);
        }
        commit_tx($pdo);
        send_json(['idproducto'=>$idp], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error al crear producto'); }
}

// POST /api/v1/inventario/ingresos-compra
if ($method === 'POST' && $path === '/api/v1/inventario/ingresos-compra') {
    $b = get_body();
    require_fields($b, ['idproveedor','idtienda','idfacturacompra','fechacompra','detalle']);
    if (!is_array($b['detalle']) || count($b['detalle'])===0) send_error(422,'Detalle vacío');
    begin_tx($pdo);
    try {
        // Master bitácora
        $stmtB = $pdo->prepare("INSERT INTO tbbitacoracompra (idfacturacompra, idproveedor, fechacompra)
                                VALUES (:doc,:prov,:fec)");
        $stmtB->execute([':doc'=>$b['idfacturacompra'], ':prov'=>$b['idproveedor'], ':fec'=>$b['fechacompra']]);
        $idbit = (int)$pdo->lastInsertId();

        $total = 0.0;
        foreach ($b['detalle'] as $it) {
            foreach (['idproducto','costo','cantidad'] as $f) if (!isset($it[$f])) send_error(422, "Falta $f en detalle");
            $stmtD = $pdo->prepare("INSERT INTO tbdetallebitacoracompra (idfacturacompra, idproducto, costo, cantidad)
                                    VALUES (:idbit,:p,:costo,:cant)");
            // OJO: detalle.idfacturacompra (INT) lo usaremos como idbitacora para mantener consistencia con tu esquema actual
            $stmtD->execute([':idbit'=>$idbit, ':p'=>$it['idproducto'], ':costo'=>$it['costo'], ':cant'=>$it['cantidad']]);

            // Inventario: sumar (upsert)
            $selInv = $pdo->prepare("SELECT inventario FROM tbinventario WHERE idproducto=:p AND idtienda=:t FOR UPDATE");
            $selInv->execute([':p'=>$it['idproducto'], ':t'=>$b['idtienda']]);
            $row = $selInv->fetch();
            if ($row) {
                $new = (int)$row['inventario'] + (int)$it['cantidad'];
                $upd = $pdo->prepare("UPDATE tbinventario SET inventario=:n WHERE idproducto=:p AND idtienda=:t");
                $upd->execute([':n'=>$new, ':p'=>$it['idproducto'], ':t'=>$b['idtienda']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO tbinventario (idproducto,idtienda,inventario) VALUES (:p,:t,:q)");
                $ins->execute([':p'=>$it['idproducto'], ':t'=>$b['idtienda'], ':q'=>$it['cantidad']]);
            }
            $total += (float)$it['costo'] * (int)$it['cantidad'];
        }
        // Asiento: Inventarios (Debe) a Caja/Bancos (Haber)
        $iddiario = create_asiento($pdo, $b['fechacompra'], ACC_INVENTARIOS, ACC_CAJA, $total, 'Compra '.$b['idfacturacompra'], 1);
        commit_tx($pdo);
        send_json(['idbitacora'=>$idbit,'total_compra'=>round($total,2),'iddiario'=>$iddiario], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error al registrar compra'); }
}

// POST /api/v1/inventario/ajustes
if ($method === 'POST' && $path === '/api/v1/inventario/ajustes') {
    $b = get_body();
    require_fields($b, ['idtienda','idproducto','cantidad_ajuste','motivo','fecha']);
    begin_tx($pdo);
    try {
        $sel = $pdo->prepare("SELECT inventario FROM tbinventario WHERE idproducto=:p AND idtienda=:t FOR UPDATE");
        $sel->execute([':p'=>$b['idproducto'], ':t'=>$b['idtienda']]);
        $row = $sel->fetch();
        $cur = $row ? (int)$row['inventario'] : 0;
        $new = $cur + (int)$b['cantidad_ajuste'];
        if ($row) {
            $upd = $pdo->prepare("UPDATE tbinventario SET inventario=:n WHERE idproducto=:p AND idtienda=:t");
            $upd->execute([':n'=>$new, ':p'=>$b['idproducto'], ':t'=>$b['idtienda']]);
        } else {
            $ins = $pdo->prepare("INSERT INTO tbinventario (idproducto,idtienda,inventario) VALUES (:p,:t,:q)");
            $ins->execute([':p'=>$b['idproducto'], ':t'=>$b['idtienda'], ':q'=>$new]);
        }
        // Si el ajuste es negativo, registramos merma (Gasto a Inventarios)
        if ((int)$b['cantidad_ajuste'] < 0) {
            $monto = abs((int)$b['cantidad_ajuste']);
            $c = $pdo->prepare("SELECT costo FROM tbproducto WHERE idproducto=:p");
            $c->execute([':p'=>$b['idproducto']]);
            $costo = (float)($c->fetch()['costo'] ?? 0);
            $total = $monto * $costo;
            create_asiento($pdo, $b['fecha'], ACC_MERMAS, ACC_INVENTARIOS, $total, 'Ajuste: '.$b['motivo'], 4);
        }
        commit_tx($pdo);
        send_json(['ok'=>true,'inventario_final'=>$new], 201);
    } catch (Throwable $e) { rollback_tx($pdo); send_error(500,'Error en ajuste'); }
}
//GET /api/v1/inventario/productos/buscar-nombre?nombre=texto&idtienda=1

if ($method === 'GET' && $path === '/api/v1/inventario/productos/buscar-nombre') {
    $nombre = isset($_GET['nombre']) ? trim($_GET['nombre']) : '';
    $idtienda = isset($_GET['idtienda']) ? (int)$_GET['idtienda'] : DEFAULT_TIENDA;
    if ($nombre === '') send_error(422, 'Debe indicar el parámetro nombre');

    $sql = "SELECT pr.idproducto, pr.codigo, pr.descripcion, pr.preciounitario, pr.preciomayoreo, pr.idestado, inv.inventario
            FROM tbproducto pr
            INNER JOIN tbinventario inv ON inv.idproducto = pr.idproducto
            WHERE pr.idestado = 1 AND inv.idtienda = :idtienda AND pr.descripcion LIKE :nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':idtienda' => $idtienda, ':nombre' => "%$nombre%"]);
    $rows = $stmt->fetchAll();
    send_json(['items' => $rows, 'count' => count($rows)]);
}

//GET /api/v1/inventario/productos/buscar-codigo?codigo=ABC123&idtienda=1

if ($method === 'GET' && $path === '/api/v1/inventario/productos/buscar-codigo') {
    $codigo = isset($_GET['codigo']) ? trim($_GET['codigo']) : '';
    $idtienda = isset($_GET['idtienda']) ? (int)$_GET['idtienda'] : DEFAULT_TIENDA;
    if ($codigo === '') send_error(422, 'Debe indicar el parámetro codigo');

    $sql = "SELECT pr.idproducto, pr.codigo, pr.descripcion, pr.preciounitario, pr.preciomayoreo, pr.idestado, inv.inventario
            FROM tbproducto pr
            INNER JOIN tbinventario inv ON inv.idproducto = pr.idproducto
            WHERE pr.idestado = 1 AND inv.idtienda = :idtienda AND pr.codigo = :codigo
            ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':idtienda' => $idtienda, ':codigo' => $codigo]);
    $rows = $stmt->fetchAll();
    send_json(['items' => $rows, 'count' => count($rows)]);
}
//POST /api/v1/inventario/productos/anular


if ($method === 'POST' && $path === '/api/v1/inventario/productos/anular') {
    $b = get_body();
    require_fields($b, ['idproducto']);
    $idproducto = (int)$b['idproducto'];

    $stmt = $pdo->prepare("UPDATE tbproducto SET idestado = 4 WHERE idproducto = :id");
    $stmt->execute([':id' => $idproducto]);

    if ($stmt->rowCount() > 0) {
        send_json(['ok' => true, 'idproducto' => $idproducto, 'nuevo_estado' => 4]);
    } else {
        send_error(404, 'Producto no encontrado');
    }
}


?>