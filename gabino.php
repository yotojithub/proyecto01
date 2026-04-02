<?php
// ================================================================
//  GABINO BARBERÍA — Sistema completo con roles y login
//  Un solo archivo PHP + SQLite. No requiere nada más.
//  Dueño por defecto: usuario=admin  contraseña=admin
// ================================================================
session_start();

// ── Base de datos ─────────────────────────────────────────────
$db_file = __DIR__ . '/gabino.db';
try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");

    // Usuarios del sistema
    $db->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre    TEXT NOT NULL,
        usuario   TEXT NOT NULL UNIQUE,
        password  TEXT NOT NULL,
        rol       TEXT NOT NULL DEFAULT 'personal',
        activo    INTEGER DEFAULT 1,
        creado    TEXT DEFAULT (date('now'))
    )");

    // Cortes/servicios
    $db->exec("CREATE TABLE IF NOT EXISTS cortes (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id    INTEGER NOT NULL,
        fecha         TEXT NOT NULL,
        hora          TEXT NOT NULL,
        modelo        TEXT NOT NULL,
        precio        REAL NOT NULL,
        productos     TEXT,
        metodo_pago   TEXT NOT NULL,
        notas         TEXT,
        FOREIGN KEY(usuario_id) REFERENCES usuarios(id)
    )");

    // Crear dueño admin por defecto si no existe
    $exists = $db->query("SELECT COUNT(*) FROM usuarios WHERE rol='dueno'")->fetchColumn();
    if (!$exists) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO usuarios (nombre,usuario,password,rol) VALUES (?,?,?,?)")
           ->execute(['Dueño Gabino', 'admin', $hash, 'dueno']);
    }
} catch (Exception $e) { die("Error DB: " . $e->getMessage()); }

// ── Helpers ───────────────────────────────────────────────────
function sol($n)  { return 'S/ ' . number_format((float)$n, 2); }
function ini($n)  { $p = explode(' ', $n); return strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($p,0,2)))); }
function esDueno(){ return ($_SESSION['rol'] ?? '') === 'dueno'; }
function esLogueado(){ return isset($_SESSION['uid']); }
function requireLogin(){ if(!esLogueado()){ header('Location: ?'); exit; } }
function requireDueno(){ requireLogin(); if(!esDueno()){ header('Location: ?view=hoy'); exit; } }

// ── Logout ────────────────────────────────────────────────────
if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }

// ── Login ─────────────────────────────────────────────────────
$login_error = '';
if (!esLogueado() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_login'])) {
    $uname = trim($_POST['uname'] ?? '');
    $upass = trim($_POST['upass'] ?? '');
    $row = $db->prepare("SELECT * FROM usuarios WHERE usuario=? AND activo=1");
    $row->execute([$uname]);
    $u = $row->fetch(PDO::FETCH_ASSOC);
    if ($u && password_verify($upass, $u['password'])) {
        $_SESSION['uid']    = $u['id'];
        $_SESSION['nombre'] = $u['nombre'];
        $_SESSION['rol']    = $u['rol'];
        $_SESSION['usuario']= $u['usuario'];
        header('Location: ?view=hoy'); exit;
    } else {
        $login_error = 'Usuario o contraseña incorrectos.';
    }
}

// ── Mostrar login si no hay sesión ────────────────────────────
if (!esLogueado()) { mostrarLogin($login_error, $db); exit; }

// ── Parámetros ────────────────────────────────────────────────
$view    = $_GET['view']  ?? 'hoy';
$f_fecha = $_GET['fecha'] ?? date('Y-m-d');
$f_mes   = $_GET['mes']   ?? date('Y-m');
$uid     = (int)$_SESSION['uid'];

// ── Acciones POST ─────────────────────────────────────────────
$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -- Registrar corte (personal y dueño)
    if ($action === 'add_corte') {
        requireLogin();
        $autor_id = esDueno() ? (int)($_POST['para_usuario'] ?? $uid) : $uid;
        $modelo = trim($_POST['modelo'] ?? '');
        $precio = (float)($_POST['precio'] ?? 0);
        $prods  = trim($_POST['productos'] ?? '');
        $metodo = $_POST['metodo_pago'] ?? 'efectivo';
        $notas  = trim($_POST['notas'] ?? '');
        if ($modelo && $precio >= 20) {
            $db->prepare("INSERT INTO cortes (usuario_id,fecha,hora,modelo,precio,productos,metodo_pago,notas) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$autor_id, date('Y-m-d'), date('H:i'), $modelo, $precio, $prods, $metodo, $notas]);
            $msg = 'Corte registrado correctamente'; $msg_type = 'ok';
        } else { $msg = 'Verifica los datos. Precio mínimo S/ 20.'; $msg_type = 'err'; }
    }

    // -- Crear empleado (solo dueño)
    if ($action === 'add_empleado') {
        requireDueno();
        $nombre  = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $pass    = trim($_POST['password'] ?? '');
        if ($nombre && $usuario && $pass) {
            $existe = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario=?");
            $existe->execute([$usuario]);
            if ($existe->fetchColumn() > 0) {
                $msg = 'Ese nombre de usuario ya existe.'; $msg_type = 'err';
            } else {
                $db->prepare("INSERT INTO usuarios (nombre,usuario,password,rol) VALUES (?,?,?,?)")
                   ->execute([$nombre, $usuario, password_hash($pass, PASSWORD_DEFAULT), 'personal']);
                $msg = 'Empleado creado correctamente.'; $msg_type = 'ok';
            }
        } else { $msg = 'Completa todos los campos.'; $msg_type = 'err'; }
    }

    // -- Editar empleado (solo dueño)
    if ($action === 'edit_empleado') {
        requireDueno();
        $eid    = (int)($_POST['eid'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario= trim($_POST['usuario'] ?? '');
        $pass   = trim($_POST['password'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($eid && $nombre && $usuario) {
            // Verificar que el usuario no lo use otro
            $dup = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario=? AND id!=?");
            $dup->execute([$usuario, $eid]);
            if ($dup->fetchColumn() > 0) {
                $msg = 'Ese usuario ya lo usa otra persona.'; $msg_type = 'err';
            } else {
                if ($pass) {
                    $db->prepare("UPDATE usuarios SET nombre=?,usuario=?,password=?,activo=? WHERE id=?")
                       ->execute([$nombre, $usuario, password_hash($pass, PASSWORD_DEFAULT), $activo, $eid]);
                } else {
                    $db->prepare("UPDATE usuarios SET nombre=?,usuario=?,activo=? WHERE id=?")
                       ->execute([$nombre, $usuario, $activo, $eid]);
                }
                $msg = 'Empleado actualizado.'; $msg_type = 'ok';
            }
        }
    }

    // -- Eliminar corte (dueño siempre, personal solo el suyo)
    if ($action === 'delete_corte') {
        requireLogin();
        $cid = (int)($_POST['corte_id'] ?? 0);
        if (esDueno()) {
            $db->prepare("DELETE FROM cortes WHERE id=?")->execute([$cid]);
        } else {
            $db->prepare("DELETE FROM cortes WHERE id=? AND usuario_id=?")->execute([$cid, $uid]);
        }
        $msg = 'Corte eliminado.'; $msg_type = 'ok';
    }
}

// ── Exportar CSV (solo dueño) ─────────────────────────────────
if (isset($_GET['export']) && esDueno()) {
    $emp_id  = (int)($_GET['emp'] ?? 0);
    $exp_mes = $_GET['exp_mes'] ?? date('Y-m');
    $q = "SELECT c.fecha,c.hora,u.nombre,c.modelo,c.precio,c.productos,c.metodo_pago,c.notas
          FROM cortes c JOIN usuarios u ON c.usuario_id=u.id
          WHERE strftime('%Y-%m',c.fecha)=?";
    $p = [$exp_mes];
    if ($emp_id) { $q .= " AND c.usuario_id=?"; $p[] = $emp_id; }
    $q .= " ORDER BY c.fecha DESC,c.hora DESC";
    $rows = $db->prepare($q); $rows->execute($p);
    $data = $rows->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gabino_reporte_'.$exp_mes.'.csv"');
    echo "\xEF\xBB\xBF"; // BOM para Excel
    echo "Fecha,Hora,Trabajador,Modelo,Precio,Comision 50%,Productos,Metodo Pago,Notas\n";
    foreach ($data as $r) {
        echo implode(',', [
            $r['fecha'], $r['hora'], '"'.$r['nombre'].'"', '"'.$r['modelo'].'"',
            $r['precio'], number_format($r['precio']*0.5,2),
            '"'.($r['productos']??'').'"', $r['metodo_pago'], '"'.($r['notas']??'').'"'
        ]) . "\n";
    }
    exit;
}

// ── Datos para vistas ─────────────────────────────────────────
$empleados = $db->query("SELECT * FROM usuarios WHERE rol='personal' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$todos_usuarios = $db->query("SELECT * FROM usuarios WHERE activo=1 AND rol='personal' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Cortes del día — dueño ve todos, personal solo los suyos
$dq = "SELECT c.*,u.nombre as unombre FROM cortes c JOIN usuarios u ON c.usuario_id=u.id WHERE c.fecha=?";
$dp = [$f_fecha];
if (!esDueno()) { $dq .= " AND c.usuario_id=?"; $dp[] = $uid; }
$dq .= " ORDER BY c.hora DESC";
$st = $db->prepare($dq); $st->execute($dp);
$cortes_dia = $st->fetchAll(PDO::FETCH_ASSOC);

// Stats del día por empleado (solo dueño necesita todos)
$stats_dia = [];
$lista_stats = esDueno() ? $empleados : [['id'=>$uid,'nombre'=>$_SESSION['nombre']]];
foreach ($lista_stats as $e) {
    $r = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(precio),0) s FROM cortes WHERE usuario_id=? AND fecha=?");
    $r->execute([$e['id'], $f_fecha]); $row = $r->fetch(PDO::FETCH_ASSOC);
    $stats_dia[$e['id']] = ['cortes'=>(int)$row['c'], 'ventas'=>(float)$row['s'], 'comision'=>(float)$row['s']*0.5, 'nombre'=>$e['nombre']];
}

// Stats mensuales
$stats_mes = [];
foreach ($lista_stats as $e) {
    $r = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(precio),0) s FROM cortes WHERE usuario_id=? AND strftime('%Y-%m',fecha)=?");
    $r->execute([$e['id'], $f_mes]); $row = $r->fetch(PDO::FETCH_ASSOC);
    $stats_mes[$e['id']] = ['cortes'=>(int)$row['c'], 'ventas'=>(float)$row['s'], 'comision'=>(float)$row['s']*0.5, 'nombre'=>$e['nombre']];
}

$total_dia = array_sum(array_column($stats_dia,'ventas'));
$total_mes = array_sum(array_column($stats_mes,'ventas'));

$mpago_st = $db->prepare("SELECT metodo_pago, COUNT(*) cnt, COALESCE(SUM(precio),0) total FROM cortes WHERE fecha=?" . (!esDueno() ? " AND usuario_id=$uid" : "") . " GROUP BY metodo_pago");
$mpago_st->execute([$f_fecha]);
$metodos_pago = $mpago_st->fetchAll(PDO::FETCH_ASSOC);

// Historial
$hq = "SELECT c.*,u.nombre as unombre FROM cortes c JOIN usuarios u ON c.usuario_id=u.id";
$hp = [];
if (!esDueno()) { $hq .= " WHERE c.usuario_id=?"; $hp[] = $uid; }
$hq .= " ORDER BY c.fecha DESC,c.hora DESC LIMIT 300";
$hs = $db->prepare($hq); $hs->execute($hp);
$hist = $hs->fetchAll(PDO::FETCH_ASSOC);

// Empleado a editar
$edit_emp = null;
if (esDueno() && isset($_GET['edit_id'])) {
    $ee = $db->prepare("SELECT * FROM usuarios WHERE id=? AND rol='personal'");
    $ee->execute([(int)$_GET['edit_id']]);
    $edit_emp = $ee->fetch(PDO::FETCH_ASSOC);
}

// ── Render ────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gabino Barbería</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#0f0d0b; --ink2:#1a1714; --ink3:#231f1b;
  --gold:#c9a84c; --gold-l:#e8d5a0;
  --cream:#f5f0e8; --muted:#6b6257;
  --border:rgba(201,168,76,.18);
  --green:#4caf7d; --red:#c9534c; --blue:#5b9bd5;
}
html{font-size:16px;scroll-behavior:smooth}
body{background:var(--ink);color:var(--cream);font-family:'DM Sans',sans-serif;min-height:100vh;
  background-image:radial-gradient(ellipse 60% 40% at 10% 0,rgba(201,168,76,.07) 0,transparent 70%),
                   radial-gradient(ellipse 50% 40% at 90% 100%,rgba(184,76,42,.05) 0,transparent 70%)}

/* HEADER */
.hdr{position:sticky;top:0;z-index:200;background:rgba(15,13,11,.96);backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);padding:0 2rem;height:58px;display:flex;align-items:center;justify-content:space-between}
.logo{display:flex;align-items:baseline;gap:10px}
.logo-n{font-family:'Bebas Neue',sans-serif;font-size:1.9rem;color:var(--gold);letter-spacing:3px;line-height:1}
.logo-s{font-size:10px;color:var(--muted);letter-spacing:2px;text-transform:uppercase}
.hdr-r{display:flex;align-items:center;gap:12px}
.hdr-user{font-size:12px;color:var(--muted)}
.hdr-user b{color:var(--cream);font-weight:600}
.rol-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.5px}
.rol-dueno{background:rgba(201,168,76,.2);color:var(--gold);border:1px solid rgba(201,168,76,.3)}
.rol-personal{background:rgba(91,155,213,.15);color:var(--blue);border:1px solid rgba(91,155,213,.25)}
.btn-logout{background:transparent;border:1px solid rgba(201,168,76,.2);color:var(--muted);border-radius:6px;
  padding:5px 12px;font-size:12px;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-logout:hover{color:var(--cream);border-color:var(--border)}

/* NAV */
.nav{background:rgba(15,13,11,.9);border-bottom:1px solid var(--border);padding:0 2rem;display:flex;gap:2px;overflow-x:auto}
.nav a{display:block;padding:13px 15px;text-decoration:none;font-size:13px;font-weight:500;color:var(--muted);
  border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s}
.nav a:hover{color:var(--cream)}
.nav a.on{color:var(--gold);border-bottom-color:var(--gold)}
.nav a.dueno-only{color:rgba(201,168,76,.5)}
.nav a.dueno-only:hover{color:var(--gold)}

/* LAYOUT */
.wrap{max-width:1160px;margin:0 auto;padding:1.5rem 2rem 5rem}

/* ALERT */
.alert{padding:11px 16px;border-radius:8px;margin-bottom:1.5rem;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px}
.alert.ok{background:rgba(76,175,125,.1);border:1px solid rgba(76,175,125,.3);color:var(--green)}
.alert.err{background:rgba(201,83,76,.1);border:1px solid rgba(201,83,76,.3);color:var(--red)}

/* SECTION TITLE */
.stitle{font-family:'Bebas Neue',sans-serif;font-size:1.15rem;letter-spacing:2px;color:var(--gold);
  margin-bottom:1rem;display:flex;align-items:center;gap:10px}
.stitle::after{content:'';flex:1;height:1px;background:var(--border)}

/* STAT CARDS */
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin-bottom:1.5rem}
.sc{background:var(--ink2);border:1px solid var(--border);border-radius:12px;padding:1.1rem 1.3rem;position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;inset:0 0 auto 0;height:2px;background:linear-gradient(90deg,var(--gold),transparent)}
.sc-l{font-size:10px;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;margin-bottom:6px}
.sc-v{font-family:'Bebas Neue',sans-serif;font-size:1.7rem;line-height:1;color:var(--gold)}
.sc-v.wh{color:var(--cream)} .sc-v.gr{color:var(--green)} .sc-v.bl{color:var(--blue)}
.sc-s{font-size:11px;color:var(--muted);margin-top:3px}

/* WORKER CARDS */
.wg{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:1.5rem}
.wc{background:var(--ink2);border:1px solid var(--border);border-radius:12px;padding:1.1rem;transition:border-color .15s}
.wc:hover{border-color:rgba(201,168,76,.4)}
.wc-h{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.avatar{width:36px;height:36px;border-radius:50%;background:rgba(201,168,76,.12);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;font-family:'Bebas Neue',sans-serif;font-size:13px;color:var(--gold);flex-shrink:0}
.wname{font-size:14px;font-weight:600}
.wsub{font-size:10px;color:var(--muted);margin-top:1px}
.wsg{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.ws{background:var(--ink3);border-radius:7px;padding:8px 10px}
.ws.full{grid-column:1/-1}
.ws-l{font-size:9px;color:var(--muted);letter-spacing:.8px;text-transform:uppercase}
.ws-v{font-family:'DM Mono',monospace;font-size:13px;font-weight:500;margin-top:2px;color:var(--cream)}
.ws-v.g{color:var(--gold)} .ws-v.gr{color:var(--green)}

/* TABLE */
.tbl-w{border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:1.5rem;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:520px}
thead{background:var(--ink3)}
th{padding:9px 13px;text-align:left;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;
  color:var(--muted);font-weight:600;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:9px 13px;border-bottom:1px solid rgba(201,168,76,.05);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(201,168,76,.03)}
.mono{font-family:'DM Mono',monospace;font-size:11px;color:var(--muted)}
.price{font-family:'DM Mono',monospace;font-size:12px;font-weight:500;color:var(--gold)}
.price.gr{color:var(--green)}
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:600}
.by{background:rgba(108,63,160,.22);color:#c89de8}
.bp{background:rgba(0,166,81,.15);color:#5ddea0}
.be{background:rgba(201,168,76,.15);color:var(--gold)}
.empty{text-align:center;padding:3rem;color:var(--muted)}
.empty b{display:block;font-family:'Bebas Neue',sans-serif;font-size:3.5rem;color:rgba(201,168,76,.08);letter-spacing:4px}

/* FORMS */
.fcard{background:var(--ink2);border:1px solid var(--border);border-radius:14px;padding:1.4rem;margin-bottom:1.5rem}
.fg{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem}
.fi{display:flex;flex-direction:column;gap:6px}
.fi.full{grid-column:1/-1}
label{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:600}
input,select,textarea{background:var(--ink3);border:1px solid rgba(201,168,76,.15);border-radius:8px;
  color:var(--cream);font-family:'DM Sans',sans-serif;font-size:14px;padding:9px 13px;transition:border-color .15s;outline:none;width:100%}
input:focus,select:focus,textarea:focus{border-color:var(--gold);background:rgba(201,168,76,.04)}
select option{background:var(--ink2)}
textarea{resize:vertical;min-height:60px}
.pago-g{display:flex;gap:8px}
.pago-b{flex:1;padding:9px 6px;border-radius:8px;border:1px solid var(--border);background:var(--ink3);
  color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;text-align:center;transition:all .15s;user-select:none}
.pago-b:hover{border-color:var(--gold-l);color:var(--cream)}
.pago-b.sel-ef{background:rgba(201,168,76,.15);border-color:var(--gold);color:var(--gold)}
.pago-b.sel-yp{background:rgba(108,63,160,.22);border-color:#6c3fa0;color:#c89de8}
.pago-b.sel-pl{background:rgba(0,166,81,.15);border-color:#00a651;color:#5ddea0}
input[name="metodo_pago"]{display:none}

/* BUTTONS */
.btn{background:var(--gold);color:var(--ink);border:none;border-radius:8px;padding:11px 24px;
  font-family:'DM Sans',sans-serif;font-size:13px;font-weight:700;letter-spacing:1px;cursor:pointer;
  transition:all .15s;text-transform:uppercase;margin-top:.3rem;display:inline-block;text-decoration:none}
.btn:hover{background:var(--gold-l);transform:translateY(-1px)}
.btn.sec{background:transparent;color:var(--gold);border:1px solid rgba(201,168,76,.3)}
.btn.sec:hover{background:rgba(201,168,76,.08)}
.btn.danger{background:transparent;color:var(--red);border:1px solid rgba(201,83,76,.3)}
.btn.danger:hover{background:rgba(201,83,76,.1)}
.btn-sm{background:transparent;color:var(--red);border:1px solid rgba(201,83,76,.3);border-radius:6px;
  padding:3px 9px;font-size:11px;cursor:pointer;transition:all .15s}
.btn-sm:hover{background:rgba(201,83,76,.12)}
.btn-edit{background:transparent;color:var(--blue);border:1px solid rgba(91,155,213,.3);border-radius:6px;
  padding:3px 9px;font-size:11px;cursor:pointer;text-decoration:none;display:inline-block;transition:all .15s}
.btn-edit:hover{background:rgba(91,155,213,.1)}
.btns-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:.5rem}

/* FILTER BAR */
.fbar{background:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:1rem;
  display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem}
.fbar .fi{min-width:130px}

/* METODOS PAGO */
.mprow{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:1.5rem}
.mpc{flex:1;min-width:110px;background:var(--ink2);border:1px solid var(--border);border-radius:9px;padding:10px 13px}
.mpc-l{font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:4px}
.mpc-v{font-size:12px;font-weight:500}

/* EXPORT BAR */
.export-bar{background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:10px;
  padding:1rem;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem}
.export-bar .fi{min-width:140px}

/* CHECKBOX */
.check-row{display:flex;align-items:center;gap:8px;margin-top:4px}
.check-row input[type=checkbox]{width:auto;padding:0}
.check-row label{font-size:13px;letter-spacing:0;text-transform:none;color:var(--cream);font-weight:400}

/* TOGGLE */
.toggle{width:36px;height:20px;background:var(--ink3);border:1px solid var(--border);border-radius:20px;
  cursor:pointer;position:relative;transition:background .2s;flex-shrink:0;display:inline-block}
.toggle.on{background:var(--gold)}
.toggle::after{content:'';position:absolute;top:3px;left:3px;width:12px;height:12px;
  border-radius:50%;background:var(--muted);transition:all .2s}
.toggle.on::after{left:19px;background:var(--ink)}

/* EDIT FORM */
.edit-panel{background:rgba(91,155,213,.06);border:1px solid rgba(91,155,213,.2);border-radius:14px;
  padding:1.4rem;margin-bottom:1.5rem}
.edit-panel .stitle{color:var(--blue)}
.edit-panel .stitle::after{background:rgba(91,155,213,.2)}

/* DIVIDER */
.div{height:1px;background:var(--border);margin:1.5rem 0}

/* RESPONSIVE */
@media(max-width:640px){
  .wrap{padding:1rem 1rem 4rem}
  .hdr,.nav{padding-left:1rem;padding-right:1rem}
  .sg{grid-template-columns:repeat(2,1fr)}
  .wg{grid-template-columns:1fr}
  .pago-g{flex-wrap:wrap}
  .hdr-user{display:none}
}

/* ─── LOGIN PAGE ─── */
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.login-box{background:var(--ink2);border:1px solid var(--border);border-radius:16px;padding:2.5rem;width:100%;max-width:380px}
.login-logo{font-family:'Bebas Neue',sans-serif;font-size:2.5rem;color:var(--gold);letter-spacing:4px;text-align:center;margin-bottom:.3rem}
.login-sub{text-align:center;font-size:11px;color:var(--muted);letter-spacing:2px;text-transform:uppercase;margin-bottom:2rem}
.login-box .fi{margin-bottom:1rem}
.login-btn{background:var(--gold);color:var(--ink);border:none;border-radius:8px;padding:12px;
  width:100%;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;letter-spacing:1px;
  cursor:pointer;text-transform:uppercase;transition:all .15s;margin-top:.5rem}
.login-btn:hover{background:var(--gold-l)}
.login-err{background:rgba(201,83,76,.12);border:1px solid rgba(201,83,76,.3);color:var(--red);
  padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:1.2rem}
</style>
</head>
<body>

<!-- ====== HEADER ====== -->
<header class="hdr">
  <div class="logo">
    <span class="logo-n">&#9988; Gabino</span>
    <span class="logo-s">Barbería</span>
  </div>
  <div class="hdr-r">
    <span class="hdr-user">
      <b><?= htmlspecialchars($_SESSION['nombre']) ?></b>
    </span>
    <span class="rol-badge <?= esDueno() ? 'rol-dueno' : 'rol-personal' ?>">
      <?= esDueno() ? 'Dueño' : 'Personal' ?>
    </span>
    <a href="?logout=1" class="btn-logout">Salir</a>
  </div>
</header>

<!-- ====== NAV ====== -->
<nav class="nav">
  <a href="?view=registrar" class="<?= $view==='registrar'?'on':'' ?>">&#10022; Registrar corte</a>
  <a href="?view=hoy"       class="<?= $view==='hoy'      ?'on':'' ?>">Hoy</a>
  <a href="?view=mensual"   class="<?= $view==='mensual'  ?'on':'' ?>">Mensual</a>
  <a href="?view=historial" class="<?= $view==='historial'?'on':'' ?>">Historial</a>
  <?php if(esDueno()): ?>
  <a href="?view=empleados" class="<?= $view==='empleados'?'on':'' ?> dueno-only">&#9733; Empleados</a>
  <a href="?view=exportar"  class="<?= $view==='exportar' ?'on':'' ?> dueno-only">&#8659; Exportar</a>
  <?php endif ?>
</nav>

<main class="wrap">

<?php if($msg): ?>
<div class="alert <?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif ?>

<?php
// ======================================================
//  REGISTRAR CORTE
// ======================================================
if($view==='registrar'):
?>
<div class="stitle">Nuevo corte</div>
<div class="fcard">
<form method="POST">
<input type="hidden" name="action" value="add_corte">
<input type="hidden" name="metodo_pago" id="mpago" value="efectivo">
<div class="fg">

  <?php if(esDueno()): ?>
  <div class="fi">
    <label>Registrar para</label>
    <select name="para_usuario">
      <option value="<?= $uid ?>">Yo mismo (dueño)</option>
      <?php foreach($todos_usuarios as $eu): ?>
      <option value="<?= $eu['id'] ?>"><?= htmlspecialchars($eu['nombre']) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <?php endif ?>

  <div class="fi">
    <label>Modelo / Tipo de corte</label>
    <select name="modelo" required>
      <option value="">Seleccionar</option>
      <?php foreach(['Clásico','Fade bajo','Fade medio','Fade alto','Skin fade','Degradado',
        'Taper','Undercut','Texturizado','Cabello largo','Navaja / Perfilado',
        'Corte + Barba','Solo barba','Otro'] as $m): ?>
      <option><?= $m ?></option>
      <?php endforeach ?>
    </select>
  </div>

  <div class="fi">
    <label>Precio (S/)</label>
    <input type="number" name="precio" min="20" step="0.5" placeholder="20.00" required>
  </div>

  <div class="fi">
    <label>Productos adicionales</label>
    <input type="text" name="productos" placeholder="Cera, tónico, pomada…">
  </div>

  <div class="fi full">
    <label>Método de pago</label>
    <div class="pago-g">
      <div class="pago-b sel-ef" onclick="selPago('efectivo',this)">Efectivo</div>
      <div class="pago-b"        onclick="selPago('yape',this)">Yape</div>
      <div class="pago-b"        onclick="selPago('plin',this)">Plin</div>
    </div>
  </div>

  <div class="fi full">
    <label>Notas (opcional)</label>
    <textarea name="notas" placeholder="Referencias, observaciones…"></textarea>
  </div>

</div>
<button type="submit" class="btn">&#10022; Registrar corte</button>
</form>
</div>

<?php
// ======================================================
//  HOY
// ======================================================
elseif($view==='hoy'):
?>

<div class="fbar">
  <div class="fi">
    <label>Fecha</label>
    <input type="date" value="<?= $f_fecha ?>"
      onchange="location='?view=hoy&fecha='+this.value">
  </div>
</div>

<div class="sg">
  <div class="sc">
    <div class="sc-l">Total ventas</div>
    <div class="sc-v wh"><?= sol($total_dia) ?></div>
    <div class="sc-s"><?= count($cortes_dia) ?> corte<?= count($cortes_dia)!=1?'s':'' ?></div>
  </div>
  <?php if(esDueno()): ?>
  <div class="sc">
    <div class="sc-l">Comisiones (50%)</div>
    <div class="sc-v"><?= sol($total_dia*0.5) ?></div>
    <div class="sc-s">a trabajadores</div>
  </div>
  <div class="sc">
    <div class="sc-l">Ganancia Gabino</div>
    <div class="sc-v gr"><?= sol($total_dia*0.5) ?></div>
    <div class="sc-s">tu 50%</div>
  </div>
  <?php else: ?>
  <div class="sc">
    <div class="sc-l">Mi comisión hoy</div>
    <div class="sc-v gr"><?= sol($total_dia*0.5) ?></div>
    <div class="sc-s">50% de tus ventas</div>
  </div>
  <?php endif ?>
</div>

<?php if($metodos_pago): ?>
<div class="mprow">
  <?php foreach($metodos_pago as $mp):
    $bc = ['yape'=>'by','plin'=>'bp','efectivo'=>'be'][$mp['metodo_pago']] ?? 'be'; ?>
  <div class="mpc">
    <div class="mpc-l"><?= ucfirst($mp['metodo_pago']) ?></div>
    <div class="mpc-v <?= $bc ?>"><?= $mp['cnt'] ?> cobro<?= $mp['cnt']!=1?'s':'' ?> &middot; <?= sol($mp['total']) ?></div>
  </div>
  <?php endforeach ?>
</div>
<?php endif ?>

<?php if(esDueno() && $empleados): ?>
<div class="stitle">Por trabajador</div>
<div class="wg">
<?php foreach($empleados as $e): if(!isset($stats_dia[$e['id']])) continue; $s=$stats_dia[$e['id']]; ?>
<div class="wc">
  <div class="wc-h">
    <div class="avatar"><?= ini($e['nombre']) ?></div>
    <div>
      <div class="wname"><?= htmlspecialchars($e['nombre']) ?></div>
      <div class="wsub"><?= htmlspecialchars($e['usuario']) ?></div>
    </div>
  </div>
  <div class="wsg">
    <div class="ws"><div class="ws-l">Cortes</div><div class="ws-v g"><?= $s['cortes'] ?></div></div>
    <div class="ws"><div class="ws-l">Producción</div><div class="ws-v g"><?= sol($s['ventas']) ?></div></div>
    <div class="ws full"><div class="ws-l">Comisión (50%)</div><div class="ws-v gr"><?= sol($s['comision']) ?></div></div>
  </div>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<div class="stitle">Detalle de cortes</div>
<div class="tbl-w">
<table>
<thead><tr>
  <th>Hora</th>
  <?php if(esDueno()): ?><th>Trabajador</th><?php endif ?>
  <th>Modelo</th><th>Productos</th><th>Precio</th><th>Comisión</th><th>Pago</th><th></th>
</tr></thead>
<tbody>
<?php if(!$cortes_dia): ?>
<tr><td colspan="<?= esDueno()?8:7 ?>"><div class="empty"><b>GABINO</b>Sin cortes este día</div></td></tr>
<?php else: foreach($cortes_dia as $c):
  $bc = ['yape'=>'by','plin'=>'bp','efectivo'=>'be'][$c['metodo_pago']] ?? 'be'; ?>
<tr>
  <td class="mono"><?= $c['hora'] ?></td>
  <?php if(esDueno()): ?><td><?= htmlspecialchars($c['unombre']) ?></td><?php endif ?>
  <td><?= htmlspecialchars($c['modelo']) ?></td>
  <td style="color:var(--muted);font-size:12px"><?= $c['productos'] ? htmlspecialchars($c['productos']) : '&mdash;' ?></td>
  <td class="price"><?= sol($c['precio']) ?></td>
  <td class="price gr"><?= sol($c['precio']*0.5) ?></td>
  <td><span class="badge <?= $bc ?>"><?= ucfirst($c['metodo_pago']) ?></span></td>
  <td>
    <form method="POST" onsubmit="return confirm('¿Eliminar este corte?')">
      <input type="hidden" name="action" value="delete_corte">
      <input type="hidden" name="corte_id" value="<?= $c['id'] ?>">
      <button type="submit" class="btn-sm">x</button>
    </form>
  </td>
</tr>
<?php endforeach; endif ?>
</tbody>
</table>
</div>

<?php
// ======================================================
//  MENSUAL
// ======================================================
elseif($view==='mensual'):
?>

<div class="fbar">
  <div class="fi">
    <label>Mes</label>
    <input type="month" value="<?= $f_mes ?>" onchange="location='?view=mensual&mes='+this.value">
  </div>
</div>

<div class="sg">
  <div class="sc"><div class="sc-l">Ventas del mes</div><div class="sc-v wh"><?= sol($total_mes) ?></div></div>
  <?php if(esDueno()): ?>
  <div class="sc"><div class="sc-l">Comisiones</div><div class="sc-v"><?= sol($total_mes*0.5) ?></div></div>
  <div class="sc"><div class="sc-l">Ganancia Gabino</div><div class="sc-v gr"><?= sol($total_mes*0.5) ?></div></div>
  <?php else: ?>
  <div class="sc"><div class="sc-l">Mi comisión</div><div class="sc-v gr"><?= sol($total_mes*0.5) ?></div><div class="sc-s">50% de tus ventas</div></div>
  <?php endif ?>
</div>

<?php if(esDueno()): ?>
<div class="stitle">Producción por trabajador</div>
<div class="tbl-w">
<table>
<thead><tr><th>Trabajador</th><th>Usuario</th><th>Cortes</th><th>Producción</th><th>Comisión (50%)</th><th>Promedio / corte</th></tr></thead>
<tbody>
<?php foreach($empleados as $e):
  $s   = $stats_mes[$e['id']] ?? ['cortes'=>0,'ventas'=>0,'comision'=>0];
  $avg = $s['cortes'] > 0 ? $s['ventas']/$s['cortes'] : 0; ?>
<tr>
  <td><?= htmlspecialchars($e['nombre']) ?><?php if(!$e['activo']): ?> <span style="font-size:10px;color:var(--muted)">(inactivo)</span><?php endif ?></td>
  <td class="mono"><?= htmlspecialchars($e['usuario']) ?></td>
  <td style="font-family:'DM Mono',monospace;color:var(--gold)"><?= $s['cortes'] ?></td>
  <td class="price"><?= sol($s['ventas']) ?></td>
  <td class="price gr"><?= sol($s['comision']) ?></td>
  <td class="mono" style="color:var(--cream)"><?= sol($avg) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="stitle">Mis cortes del mes</div>
<div class="tbl-w">
<table>
<thead><tr><th>Fecha</th><th>Modelo</th><th>Precio</th><th>Comisión</th><th>Pago</th></tr></thead>
<tbody>
<?php
$mc = $db->prepare("SELECT * FROM cortes WHERE usuario_id=? AND strftime('%Y-%m',fecha)=? ORDER BY fecha DESC,hora DESC");
$mc->execute([$uid, $f_mes]); $mis_cortes = $mc->fetchAll(PDO::FETCH_ASSOC);
if(!$mis_cortes): ?>
<tr><td colspan="5"><div class="empty"><b>GABINO</b>Sin cortes este mes</div></td></tr>
<?php else: foreach($mis_cortes as $c):
  $bc = ['yape'=>'by','plin'=>'bp','efectivo'=>'be'][$c['metodo_pago']] ?? 'be'; ?>
<tr>
  <td class="mono"><?= date('d/m/Y',strtotime($c['fecha'])) ?></td>
  <td><?= htmlspecialchars($c['modelo']) ?></td>
  <td class="price"><?= sol($c['precio']) ?></td>
  <td class="price gr"><?= sol($c['precio']*0.5) ?></td>
  <td><span class="badge <?= $bc ?>"><?= ucfirst($c['metodo_pago']) ?></span></td>
</tr>
<?php endforeach; endif ?>
</tbody>
</table>
</div>
<?php endif ?>

<?php
// ======================================================
//  HISTORIAL
// ======================================================
elseif($view==='historial'):
?>
<div class="stitle">Historial <?= esDueno() ? 'completo' : 'mis cortes' ?></div>
<div class="tbl-w">
<table>
<thead><tr>
  <th>Fecha</th><th>Hora</th>
  <?php if(esDueno()): ?><th>Trabajador</th><?php endif ?>
  <th>Modelo</th><th>Productos</th><th>Precio</th><th>Comisión</th><th>Pago</th><th>Notas</th>
</tr></thead>
<tbody>
<?php if(!$hist): ?>
<tr><td colspan="<?= esDueno()?9:8 ?>"><div class="empty"><b>GABINO</b>Sin registros aún</div></td></tr>
<?php else: foreach($hist as $c):
  $bc = ['yape'=>'by','plin'=>'bp','efectivo'=>'be'][$c['metodo_pago']] ?? 'be'; ?>
<tr>
  <td class="mono"><?= date('d/m/Y',strtotime($c['fecha'])) ?></td>
  <td class="mono"><?= $c['hora'] ?></td>
  <?php if(esDueno()): ?><td><?= htmlspecialchars($c['unombre']) ?></td><?php endif ?>
  <td><?= htmlspecialchars($c['modelo']) ?></td>
  <td style="color:var(--muted);font-size:12px"><?= $c['productos'] ? htmlspecialchars($c['productos']) : '&mdash;' ?></td>
  <td class="price"><?= sol($c['precio']) ?></td>
  <td class="price gr"><?= sol($c['precio']*0.5) ?></td>
  <td><span class="badge <?= $bc ?>"><?= ucfirst($c['metodo_pago']) ?></span></td>
  <td style="color:var(--muted);font-size:12px"><?= $c['notas'] ? htmlspecialchars($c['notas']) : '&mdash;' ?></td>
</tr>
<?php endforeach; endif ?>
</tbody>
</table>
</div>

<?php
// ======================================================
//  EMPLEADOS (solo dueño)
// ======================================================
elseif($view==='empleados' && esDueno()):
?>

<?php if($edit_emp): ?>
<div class="edit-panel">
  <div class="stitle">Editando empleado</div>
  <form method="POST">
    <input type="hidden" name="action" value="edit_empleado">
    <input type="hidden" name="eid" value="<?= $edit_emp['id'] ?>">
    <div class="fg">
      <div class="fi">
        <label>Nombre completo</label>
        <input type="text" name="nombre" value="<?= htmlspecialchars($edit_emp['nombre']) ?>" required>
      </div>
      <div class="fi">
        <label>Usuario (login)</label>
        <input type="text" name="usuario" value="<?= htmlspecialchars($edit_emp['usuario']) ?>" required>
      </div>
      <div class="fi">
        <label>Nueva contraseña (dejar vacío = no cambiar)</label>
        <input type="password" name="password" placeholder="••••••••">
      </div>
      <div class="fi full">
        <div class="check-row">
          <input type="checkbox" name="activo" id="chk-activo" value="1" <?= $edit_emp['activo']?'checked':'' ?>>
          <label for="chk-activo">Empleado activo (puede ingresar al sistema)</label>
        </div>
      </div>
    </div>
    <div class="btns-row">
      <button type="submit" class="btn">Guardar cambios</button>
      <a href="?view=empleados" class="btn sec">Cancelar</a>
    </div>
  </form>
</div>
<div class="div"></div>
<?php endif ?>

<div class="stitle">Crear nuevo empleado</div>
<div class="fcard">
<form method="POST">
<input type="hidden" name="action" value="add_empleado">
<div class="fg">
  <div class="fi">
    <label>Nombre completo</label>
    <input type="text" name="nombre" placeholder="Ej: Juan Carlos" required>
  </div>
  <div class="fi">
    <label>Usuario (para el login)</label>
    <input type="text" name="usuario" placeholder="Ej: juan123" required>
  </div>
  <div class="fi">
    <label>Contraseña</label>
    <input type="password" name="password" placeholder="Mínimo 4 caracteres" required>
  </div>
</div>
<button type="submit" class="btn">+ Crear empleado</button>
</form>
</div>

<div class="stitle">Empleados registrados</div>
<?php if(!$empleados): ?>
<div class="empty" style="padding:2rem;border:1px solid var(--border);border-radius:12px">
  <b>GABINO</b>Aún no hay empleados creados
</div>
<?php else: ?>
<div class="wg">
<?php foreach($empleados as $e):
  $stats_e = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(precio),0) s FROM cortes WHERE usuario_id=?");
  $stats_e->execute([$e['id']]); $se = $stats_e->fetch(PDO::FETCH_ASSOC);
?>
<div class="wc" style="<?= !$e['activo'] ? 'opacity:.55' : '' ?>">
  <div class="wc-h">
    <div class="avatar"><?= ini($e['nombre']) ?></div>
    <div>
      <div class="wname"><?= htmlspecialchars($e['nombre']) ?></div>
      <div class="wsub">@<?= htmlspecialchars($e['usuario']) ?> &middot; <?= $e['activo'] ? 'Activo' : 'Inactivo' ?></div>
    </div>
  </div>
  <div class="wsg">
    <div class="ws"><div class="ws-l">Total cortes</div><div class="ws-v g"><?= $se['c'] ?></div></div>
    <div class="ws"><div class="ws-l">Producción</div><div class="ws-v g"><?= sol($se['s']) ?></div></div>
    <div class="ws full"><div class="ws-l">Comisión acumulada</div><div class="ws-v gr"><?= sol($se['s']*0.5) ?></div></div>
  </div>
  <div style="margin-top:10px;display:flex;gap:6px">
    <a href="?view=empleados&edit_id=<?= $e['id'] ?>" class="btn-edit">&#9998; Editar</a>
  </div>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php
// ======================================================
//  EXPORTAR (solo dueño)
// ======================================================
elseif($view==='exportar' && esDueno()):
?>

<div class="stitle">Exportar reportes CSV</div>
<p style="color:var(--muted);font-size:13px;margin-bottom:1.5rem;line-height:1.6">
  Descarga un archivo CSV que puedes abrir en Excel o Google Sheets. Selecciona el mes y el empleado que quieres exportar.
</p>

<div class="export-bar">
  <div class="fi">
    <label>Mes a exportar</label>
    <input type="month" id="exp-mes" value="<?= date('Y-m') ?>">
  </div>
  <div class="fi">
    <label>Empleado</label>
    <select id="exp-emp">
      <option value="0">Todos los empleados</option>
      <?php foreach($empleados as $e): ?>
      <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
      <?php endforeach ?>
    </select>
  </div>
  <div class="fi">
    <label>&nbsp;</label>
    <button class="btn" onclick="doExport()">&#8659; Descargar CSV</button>
  </div>
</div>

<div class="stitle">Vista previa del mes actual</div>
<div class="tbl-w">
<table>
<thead><tr><th>Trabajador</th><th>Cortes este mes</th><th>Producción</th><th>Comisión (50%)</th><th>Promedio / corte</th><th>Acción</th></tr></thead>
<tbody>
<?php foreach($empleados as $e):
  $sm = $db->prepare("SELECT COUNT(*) c, COALESCE(SUM(precio),0) s FROM cortes WHERE usuario_id=? AND strftime('%Y-%m',fecha)=?");
  $sm->execute([$e['id'], date('Y-m')]); $sme = $sm->fetch(PDO::FETCH_ASSOC);
  $avg = $sme['c'] > 0 ? $sme['s']/$sme['c'] : 0;
?>
<tr>
  <td><?= htmlspecialchars($e['nombre']) ?><?php if(!$e['activo']): ?> <span style="font-size:10px;color:var(--muted)">(inactivo)</span><?php endif ?></td>
  <td style="font-family:'DM Mono',monospace;color:var(--gold)"><?= $sme['c'] ?></td>
  <td class="price"><?= sol($sme['s']) ?></td>
  <td class="price gr"><?= sol($sme['s']*0.5) ?></td>
  <td class="mono" style="color:var(--cream)"><?= sol($avg) ?></td>
  <td>
    <a href="?export=1&emp=<?= $e['id'] ?>&exp_mes=<?= date('Y-m') ?>" class="btn-edit">&#8659; CSV</a>
  </td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>

<?php endif ?>

</main>

<script>
function selPago(m, el) {
  document.querySelectorAll('.pago-b').forEach(b => b.className = 'pago-b');
  var map = {efectivo:'ef', yape:'yp', plin:'pl'};
  el.classList.add('sel-' + map[m]);
  document.getElementById('mpago').value = m;
}
function doExport() {
  var mes = document.getElementById('exp-mes').value;
  var emp = document.getElementById('exp-emp').value;
  window.location = '?export=1&emp=' + emp + '&exp_mes=' + mes;
}
</script>
</body>
</html>
<?php
// ======================================================
//  FUNCIÓN: PÁGINA DE LOGIN
// ======================================================
function mostrarLogin($error, $db) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gabino Barbería — Ingresar</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--ink:#0f0d0b;--ink2:#1a1714;--ink3:#231f1b;--gold:#c9a84c;--gold-l:#e8d5a0;--cream:#f5f0e8;--muted:#6b6257;--border:rgba(201,168,76,.18);--red:#c9534c}
body{background:var(--ink);color:var(--cream);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;
  background-image:radial-gradient(ellipse 70% 50% at 50% 0,rgba(201,168,76,.08) 0,transparent 70%)}
.box{background:var(--ink2);border:1px solid var(--border);border-radius:18px;padding:2.5rem;width:100%;max-width:360px}
.logo{font-family:'Bebas Neue',sans-serif;font-size:2.8rem;color:var(--gold);letter-spacing:4px;text-align:center;line-height:1}
.sub{text-align:center;font-size:10px;color:var(--muted);letter-spacing:2.5px;text-transform:uppercase;margin:4px 0 2rem}
.fi{display:flex;flex-direction:column;gap:6px;margin-bottom:1rem}
label{font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:600}
input{background:var(--ink3);border:1px solid rgba(201,168,76,.15);border-radius:8px;color:var(--cream);
  font-family:'DM Sans',sans-serif;font-size:14px;padding:10px 13px;outline:none;width:100%;transition:border-color .15s}
input:focus{border-color:var(--gold);background:rgba(201,168,76,.04)}
.btn{background:var(--gold);color:var(--ink);border:none;border-radius:8px;padding:12px;
  width:100%;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:700;letter-spacing:1px;
  cursor:pointer;text-transform:uppercase;transition:all .15s;margin-top:.4rem}
.btn:hover{background:var(--gold-l)}
.err{background:rgba(201,83,76,.12);border:1px solid rgba(201,83,76,.3);color:var(--red);
  padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:1.2rem;text-align:center}
.hint{margin-top:1.2rem;text-align:center;font-size:11px;color:var(--muted);line-height:1.6;border-top:1px solid var(--border);padding-top:1rem}
</style>
</head>
<body>
<div class="box">
  <div class="logo">&#9988; Gabino</div>
  <div class="sub">Sistema de barbería</div>
  <?php if($error): ?>
  <div class="err"><?= htmlspecialchars($error) ?></div>
  <?php endif ?>
  <form method="POST">
    <input type="hidden" name="do_login" value="1">
    <div class="fi">
      <label>Usuario</label>
      <input type="text" name="uname" placeholder="tu usuario" autofocus required>
    </div>
    <div class="fi">
      <label>Contraseña</label>
      <input type="password" name="upass" placeholder="••••••••" required>
    </div> 
    <button type="submit" class="btn">Ingresar</button>
  </form>
  <div class="hint">Dueño: <b style="color:var(--gold)">admin</b> / <b style="color:var(--gold)">admin</b></div>
</div>
</body>
</html>
<?php
}
?>