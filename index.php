<?php
declare(strict_types=1);

/* ----------------------------------------------------------
   CONFIG MySQL
---------------------------------------------------------- */
$MYSQL = [
    'host'     => '172.18.8.34',
    'port'     => 3306,
    'database' => 'nemoq',
    'user'     => 'nemoq',
    'password' => 'nemoq',
    'charset'  => 'utf8mb4',
];

/* ----------------------------------------------------------
   CONFIG ORACLE
---------------------------------------------------------- */
$ORACLE = [
    'host'     => '172.31.254.85',
    'port'     => 1521,
    'service'  => 'SFEREPDB',
    'user'     => 'SF_AUX_SEVILLA',
    'password' => 'qS4H5iPlmg@Z'
];

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ----------------------------------------------------------
   PARÁMETROS UI
---------------------------------------------------------- */
$limit = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 200;
$autorefresh = isset($_GET['refresh']) ? max(0, min(3600, (int)$_GET['refresh'])) : 0;

/* ----------------------------------------------------------
   CONSULTA MySQL ORIGINAL
---------------------------------------------------------- */
$sql_mysql = "
SELECT
    bt.icu,
    bt.idactivity,
    bt.ticketnumber,
    bt.customername,
    bt.customer1lastname,
    bt.customer2lastname,
    bt.attenderid,
    bt.datum,
    bt.bookedtime,
    bt.service,
    bt.cip,
    bt.dni,
    bt.nhc,
    bt.printed,
    bt.waitingarea,
    bt.status,
    bt.room,
    bt.agenda,
    bt.printedfrom,

    -- Impreso por (kiosco / mostrador / integración)
    CASE
        WHEN SUBSTRING_INDEX(COALESCE(bt.printedfrom, ''), ':', 1) LIKE '172.31.148.%'
            THEN 'Kiosco'
        WHEN SUBSTRING_INDEX(COALESCE(bt.printedfrom, ''), ':', 1) LIKE '172.31.131.%'
            THEN 'Mostrador'
        ELSE 'Integracion'
    END AS ImpresoPor,

    -- Descripción del estado
    CASE bt.status
        WHEN 0  THEN 'No impreso'
        WHEN 1  THEN 'En espera'
        WHEN 2  THEN 'En tránsito'
        WHEN 3  THEN 'Llamado'
        WHEN 4  THEN 'Rellamado'
        WHEN 5  THEN 'Reenviado'
        WHEN 6  THEN 'Finalizado'
        WHEN 7  THEN 'No presentado'
        WHEN 8  THEN 'Reenviado a SE'
        WHEN 9  THEN 'Llamar a R.S.E.'
        WHEN 10 THEN 'Admitido'
        WHEN 11 THEN 'Devuelto'
        WHEN 12 THEN 'Episodio Eliminado'
        WHEN 13 THEN 'Reenviado a RAD'
        WHEN 14 THEN 'Prueba RAD finalizada'
        WHEN 15 THEN 'Triado'
        ELSE 'Desconocido'
    END AS status_descripcion,

    -- Datos del ticket
    t.id        AS ticket_id,
    t.number    AS ticket_number,
    t.status    AS ticket_status,
    t.datum_status,

    -- Tiempo de espera en minutos
    TIMESTAMPDIFF(MINUTE, t.datum_status, NOW()) AS minutos_espera

FROM booked_today bt

LEFT JOIN ticket t
       ON t.idbooked = bt.id
      AND t.status = 1

WHERE bt.printedfrom LIKE '172.31.148.%'
  AND bt.idcenter IN (4,6)
  AND bt.printed = 1

ORDER BY minutos_espera DESC
LIMIT $limit";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ----------------------------------------------------------
                            ORDER BY minutos_espera DESC
   EJECUTAR MySQL
---------------------------------------------------------- */
try {
    $m = new mysqli();
    $m->real_connect(
        $MYSQL['host'], $MYSQL['user'], $MYSQL['password'],
        $MYSQL['database'], $MYSQL['port']
    );
    $m->set_charset($MYSQL['charset']);

    $res = $m->query($sql_mysql);
    $mysql_rows = $res->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    die("<pre>Error MySQL: " . h($e->getMessage()) . "</pre>");
}

/* ----------------------------------------------------------
   CONEXIÓN ORACLE
---------------------------------------------------------- */
$connStr = "
(DESCRIPTION=
 (ADDRESS=(PROTOCOL=TCP)(HOST={$ORACLE['host']})(PORT={$ORACLE['port']}))
 (CONNECT_DATA=(SERVICE_NAME={$ORACLE['service']}))
)";

$ora = @oci_connect($ORACLE['user'], $ORACLE['password'], $connStr, 'AL32UTF8');
if (!$ora) {
    $e = oci_error();
    die("<pre>Error Oracle: " . h($e['message']) . "</pre>");
}

/* ----------------------------------------------------------
   CONSULTA ORACLE
---------------------------------------------------------- */
$sql_oracle = "
SELECT 
    ah.history_number AS nhc,
    CASE
        WHEN cs.end_date IS NOT NULL AND NVL(sc.attended,0) = 0 THEN 'NO REALIZADO'
        WHEN cs.admission_date IS NULL AND cs.end_date IS NULL THEN 'PROGRAMADO'
        WHEN cs.admission_date IS NOT NULL AND cs.end_date IS NULL THEN 'ADMITIDO'
        WHEN cs.end_date IS NOT NULL AND NVL(sc.attended,0) = 1 THEN 'REALIZADO'
        ELSE 'OTRO'
    END AS estado
FROM com_clinical_acts     cca
JOIN com_subencounters     cs ON cs.sid       = cca.subencounter
JOIN com_encounters        ce ON ce.sid       = cs.encounter
JOIN arc_histories         ah ON ah.sid       = ce.history
LEFT JOIN sch_consultations sc ON sc.sid     = cca.sid
WHERE cca.sid = :cca_sid";

$ora_stmt = oci_parse($ora, $sql_oracle);

/* ----------------------------------------------------------
   PROCESO: Comparar MySQL vs Oracle + Filtrar PROGRAMADO
---------------------------------------------------------- */
$result_rows = [];

foreach ($mysql_rows as $row) {

    // ICU → quitar prefijo SF
    $icu = trim($row['icu']);
    $cca_sid = preg_replace('/^SF/i', '', $icu);
    if (!ctype_digit($cca_sid)) continue;

    // Ejecutar Oracle
    oci_bind_by_name($ora_stmt, ":cca_sid", $cca_sid);
    oci_execute($ora_stmt);
    $ora_row = oci_fetch_assoc($ora_stmt);

    if (!$ora_row) continue;

    // Solo dejamos PROGRAMADO
   if ($ora_row['ESTADO'] === 'PROGRAMADO') {
        $row['estado_oracle'] = 'PROGRAMADO';
        $result_rows[] = $row;
    }
}

$count = count($result_rows);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Lista de Espera</title>

<?php if ($autorefresh > 0): ?>
<meta http-equiv="refresh" content="<?=h($autorefresh)?>">
<?php endif; ?>

<link rel="stylesheet" href="style.css">
</head>

<body>

<header>
    <h1>Lista de Esperaa</h1>
    <div class="actions">
      <form method="get" style="display:flex; gap:8px; align-items:center">
        <label class="muted">Límite</label>
        <input type="number" name="limit" value="<?= h($limit) ?>" min="1" max="5000"
               style="width:90px; padding:6px 8px; border-radius:8px; border:1px solid rgba(148,163,184,.25); background:#0b1220; color:var(--text)">

        <label class="muted">Auto-refresh (s)</label>
        <input type="number" name="refresh" value="<?= h($autorefresh) ?>" min="0" max="3600"
               style="width:110px; padding:6px 8px; border-radius:8px; border:1px solid rgba(148,163,184,.25); background:#0b1220; color:var(--text)">

        <button class="btn" type="submit">Aplicar</button>
        <a class="btn" href="?">Reset</a>
      </form>
    </div>
</header>

<main>
    <div class="toolbar">
      <div class="meta">Mostrando <strong><?=h($count)?></strong> filas</div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ICU</th>
            <th>Id actividad</th>
            <th>Nº Ticket</th>
            <th>Nombre</th>
            <th>1º Apellido</th>
            <th>2º Apellido</th>
            <th>attenderid</th>
            <th>Fecha</th>
            <th>Hora cita</th>
            <th>DNI</th>
            <th>NHC</th>
            <th>Área espera</th>
            <th>Estado OGS</th>
            <th>Agenda</th>
            <th>Impreso por</th>
            <th>Tiempo espera</th>
            <th>Estado (Oracle)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$result_rows): ?>
            <tr><td colspan="20" class="muted">Sin resultados</td></tr>
          <?php else: ?>
            <?php foreach ($result_rows as $r): ?>
              <tr>
                <td><?=h($r['icu'])?></td>
                <td><?=h($r['idactivity'])?></td>
                <td><?=h($r['ticketnumber'])?></td>
                <td><?=h($r['customername'])?></td>
                <td><?=h($r['customer1lastname'])?></td>
                <td><?=h($r['customer2lastname'])?></td>
                <td><?=h($r['attenderid'])?></td>
                <td><?=h($r['datum'])?></td>
                <td><?=h($r['bookedtime'])?></td>
                <td><?=h($r['dni'])?></td>
                <td><?=h($r['nhc'])?></td>
                <td><?=h($r['waitingarea'])?></td>
                <td><?=h($r['status_descripcion'])?></td>
                <td><?=h($r['agenda'])?></td>
                <td><?=h($r['ImpresoPor'])?></td>
                <td><?=h($r['minutos_espera'])?> min</td>
                <td><span class="pill warn">PROGRAMADO</span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
</main>

</body>
</html>
