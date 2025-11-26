<?php
// GET /api/v1/reportes/ventas-diarias?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
if ($method === 'GET' && $path === '/api/v1/reportes/ventas-diarias') {
    $desde = $_GET['desde'] ?? null; $hasta = $_GET['hasta'] ?? null;
    $p = [];
    $where = [];
    if ($desde) { $where[] = 'f.fechafactura >= :d'; $p[':d'] = $desde; }
    if ($hasta) { $where[] = 'f.fechafactura <= :h'; $p[':h'] = $hasta; }
    $w = $where? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT f.fechafactura AS fecha, COUNT(DISTINCT f.idfactura) AS facturas,
                   SUM(d.cantidad * d.precioventa) AS total
            FROM tbfactura f
            JOIN tbdetallefactura d ON d.idfactura = f.idfactura          
            $w
            GROUP BY f.fechafactura
            ORDER BY f.fechafactura";
    $st = $pdo->prepare($sql); $st->execute($p);
    send_json(['resumen'=>$st->fetchAll()]);
}

// GET /api/v1/reportes/inventario-valorado?tienda=1
if ($method === 'GET' && $path === '/api/v1/reportes/inventario-valorado') {
    $idtienda = isset($_GET['tienda']) ? (int)$_GET['tienda'] : DEFAULT_TIENDA;
    $sql = "SELECT p.idproducto, p.codigo, p.descripcion, inv.inventario AS existencia,
                   p.costo AS costo_unitario, (inv.inventario * p.costo) AS total
            FROM tbinventario inv
            JOIN tbproducto p ON p.idproducto = inv.idproducto
            WHERE inv.idtienda = :t";
    $st = $pdo->prepare($sql); $st->execute([':t'=>$idtienda]);
    $rows = $st->fetchAll();
    $total = 0.0; foreach ($rows as $r) { $total += (float)$r['total']; }
    send_json(['items'=>$rows,'total_general'=>round($total,2)]);
}

// GET /api/v1/reportes/utilidad?desde=YYYY-MM-DD&hasta=YYYY-MM-DD
if ($method === 'GET' && $path === '/api/v1/reportes/utilidad') {
    $desde = $_GET['desde'] ?? null; $hasta = $_GET['hasta'] ?? null;
    $p = []; $where = [];
    if ($desde) { $where[] = 'f.fechafactura >= :d'; $p[':d'] = $desde; }
    if ($hasta) { $where[] = 'f.fechafactura <= :h'; $p[':h'] = $hasta; }
    $w = $where? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT SUM(d.cantidad * d.precioventa) AS ventas,
                   SUM(d.cantidad * p.costo)       AS costo
            FROM tbfactura f
            JOIN tbdetallefactura d ON d.idfactura = f.idfactura
            JOIN tbproducto p ON p.idproducto = d.idproducto
            $w";
    $st = $pdo->prepare($sql); $st->execute($p);
    $row = $st->fetch();
    $ventas = (float)($row['ventas'] ?? 0);
    $costo  = (float)($row['costo'] ?? 0);
    send_json(['resumen'=>['ventas'=>round($ventas,2),'costo'=>round($costo,2),'utilidad_bruta'=>round($ventas-$costo,2)]]);
}

// GET /api/v1/reportes/kardex?idproducto=3&desde=YYYY-MM-DD&hasta=YYYY-MM-DD
if ($method === 'GET' && $path === '/api/v1/reportes/kardex') {
    $idproducto = isset($_GET['idproducto']) ? (int)$_GET['idproducto'] : 0;
    if ($idproducto<=0) send_error(422,'idproducto requerido');
    $desde = $_GET['desde'] ?? null; $hasta = $_GET['hasta'] ?? null;

    // Entradas desde compras (bitácora)
    $p1 = [':p'=>$idproducto]; $w1 = ' WHERE d.idproducto = :p';
    if ($desde) { $w1 .= ' AND b.fechacompra >= :d'; $p1[':d'] = $desde; }
    if ($hasta) { $w1 .= ' AND b.fechacompra <= :h'; $p1[':h'] = $hasta; }
    $sql1 = "SELECT b.fechacompra AS fecha, 'Entrada' AS tipo, b.idfacturacompra AS documento, d.cantidad
             FROM tbdetallebitacoracompra d
             JOIN tbbitacoracompra b ON b.idbitacora = d.idfacturacompra
             $w1
             ORDER BY b.fechacompra";
    $st1 = $pdo->prepare($sql1); $st1->execute($p1); $entradas = $st1->fetchAll();

    // Salidas desde ventas
    $p2 = [':p'=>$idproducto]; $w2 = ' WHERE d.idproducto = :p';
    if ($desde) { $w2 .= ' AND f.fechafactura >= :d'; $p2[':d'] = $desde; }
    if ($hasta) { $w2 .= ' AND f.fechafactura <= :h'; $p2[':h'] = $hasta; }
    $sql2 = "SELECT f.fechafactura AS fecha, 'Salida' AS tipo, CONCAT('FAC-', f.idfactura) AS documento, d.cantidad
             FROM tbdetallefactura d
             JOIN tbfactura f ON f.idfactura = d.idfactura
             $w2
             ORDER BY f.fechafactura";
    $st2 = $pdo->prepare($sql2); $st2->execute($p2); $salidas = $st2->fetchAll();

    // Mezclar y calcular saldo (simple, sin costo)
    $movs = array_merge($entradas, $salidas);
    usort($movs, function($a,$b){ return strcmp($a['fecha'].$a['tipo'],$b['fecha'].$b['tipo']); });
    $saldo = 0; foreach ($movs as &$m) { $saldo += ($m['tipo']==='Entrada' ? (int)$m['cantidad'] : -(int)$m['cantidad']); $m['saldo']=$saldo; }

    // Info de producto
    $pinfo = $pdo->prepare('SELECT idproducto,codigo,descripcion FROM tbproducto WHERE idproducto=:p');
    $pinfo->execute([':p'=>$idproducto]);

    send_json(['producto'=>$pinfo->fetch(),'movimientos'=>$movs]);
}
?>
