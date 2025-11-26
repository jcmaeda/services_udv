<?php
// GET /api/v1/contabilidad/diario?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&tipopartida=#
if ($method === 'GET' && $path === '/api/v1/contabilidad/diario') {
    $desde = $_GET['desde'] ?? null; $hasta = $_GET['hasta'] ?? null; $tipo = $_GET['tipopartida'] ?? null;
    $sql = "SELECT * FROM tbdiario WHERE 1=1";
    $p = [];
    if ($desde) { $sql .= " AND fecha >= :d"; $p[':d'] = $desde; }
    if ($hasta) { $sql .= " AND fecha <= :h"; $p[':h'] = $hasta; }
    if ($tipo)  { $sql .= " AND tipopartida = :t"; $p[':t'] = (int)$tipo; }
    $sql .= " ORDER BY fecha, iddiario";
    $st = $pdo->prepare($sql); $st->execute($p);
    send_json(['items'=>$st->fetchAll()]);
}

// POST /api/v1/contabilidad/diario
if ($method === 'POST' && $path === '/api/v1/contabilidad/diario') {
    $b = get_body();
    require_fields($b, ['fecha','cuentadebe','cuentahaber','debe','haber','referencia','tipopartida']);
    if (round((float)$b['debe'],2) !== round((float)$b['haber'],2)) send_error(422,'Debe y Haber deben ser iguales');
    $id = create_asiento($pdo, $b['fecha'], (int)$b['cuentadebe'], (int)$b['cuentahaber'], (float)$b['debe'], $b['referencia'], (int)$b['tipopartida']);
    send_json(['iddiario'=>$id], 201);
}

// GET /api/v1/contabilidad/cuentas
if ($method === 'GET' && $path === '/api/v1/contabilidad/cuentas') {
    $st = $pdo->query("SELECT idcuenta, cuenta, nombre, idtipo, idpadre FROM tbnomenclatura ORDER BY idcuenta");
    send_json(['cuentas'=>$st->fetchAll()]);
}


// GET /api/v1/contabilidad/ventas
if ($method === 'GET' && $path === '/api/v1/contabilidad/ventas') {
    $st = $pdo->query("SELECT fac.idfactura,fechafactura,idcajero,idvendedor,det.idproducto,det.precioventa,det.cantidad,est.estado,pro.descripcion,pro.codigo
                            FROM `tbfactura` fac 
                            inner join tbdetallefactura det on det.idfactura = fac.idfactura
                            inner join tbproducto pro on pro.idproducto = det.idproducto
                            inner join tbestado est on est.idestado = fac.idestado");
    send_json(['ventas'=>$st->fetchAll()]);
}
?>