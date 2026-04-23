<?php
/**
 * Página de inicio de sesión
 * Ubicación: /auth/login.php
 */

require_once __DIR__ . '/../config.php';

// Si ya está logueado, redirigir al sistema
if (isset($_SESSION['user_id']) && $_SESSION['logged_in'] === true) {
    redirect('public/web.php');
}

$error = '';
$username = '';

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip_address = get_client_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Validar CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Error de validación. Intenta nuevamente.';
        log_security_event('csrf_intento', null, $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Intento de CSRF en login', 'alto');
    } else {
        require_once CONFIG_PATH . '/conn.php';
        
        $stmt = $mysqli->prepare("SELECT COUNT(*) as intentos FROM seguridad_logs 
                                   WHERE ip_address = ? AND tipo_evento = 'login_fallido' 
                                   AND fecha_evento > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->bind_param("s", $ip_address);
        $stmt->execute();
        $intentos_recientes = $stmt->get_result()->fetch_assoc()['intentos'];
        if ($intentos_recientes >= 5) {
            $error = 'Demasiados intentos fallidos. Espera 15 minutos.';
            log_security_event('intento_sospechoso', null, $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'IP bloqueada temporalmente por muchos intentos', 'alto');
        } else {
            $stmt = $mysqli->prepare("SELECT id_usuario, username, password, nombre, apellido, rol, activo, bloqueado_hasta, intentos_fallidos 
                                       FROM usuarios WHERE username = ? AND activo = 1");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time()) {
                    $error = 'Cuenta bloqueada temporalmente. Intenta más tarde.';
                    log_security_event('bloqueo_cuenta', $user['id_usuario'], $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Intento de acceso a cuenta bloqueada', 'alto');
                } else {
                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id_usuario'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['user_name'] = $user['nombre'] . ' ' . $user['apellido'];
                        $_SESSION['user_role'] = $user['rol'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['login_time'] = time();
                        $_SESSION['ip_address'] = $ip_address;
                        
                        session_regenerate_id(true);
                        
                        $update = $mysqli->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), ultimo_ip = ?, intentos_fallidos = 0 WHERE id_usuario = ?");
                        $update->bind_param("si", $ip_address, $user['id_usuario']);
                        $update->execute();
                        
                        $session_id = session_id();
                        $expira = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
                        $sesion = $mysqli->prepare("INSERT INTO sesiones_activas (id_usuario, session_id, ip_address, user_agent, expira) VALUES (?, ?, ?, ?, ?)");
                        $sesion->bind_param("issss", $user['id_usuario'], $session_id, $ip_address, $user_agent, $expira);
                        $sesion->execute();
                        
                        log_security_event('login_exitoso', $user['id_usuario'], $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Login exitoso', 'bajo');
                        
                        redirect('public/web.php');
                    } else {
                        $error = 'Usuario o contraseña incorrectos';
                        
                        $intentos = $user['intentos_fallidos'] + 1;
                        $update = $mysqli->prepare("UPDATE usuarios SET intentos_fallidos = ? WHERE id_usuario = ?");
                        $update->bind_param("ii", $intentos, $user['id_usuario']);
                        $update->execute();
                        
                        if ($intentos >= 3) {
                            $bloqueo = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                            $update_bloqueo = $mysqli->prepare("UPDATE usuarios SET bloqueado_hasta = ? WHERE id_usuario = ?");
                            $update_bloqueo->bind_param("si", $bloqueo, $user['id_usuario']);
                            $update_bloqueo->execute();
                            
                            log_security_event('bloqueo_cuenta', $user['id_usuario'], $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Cuenta bloqueada por 3 intentos fallidos', 'alto');
                        }
                        
                        log_security_event('login_fallido', $user['id_usuario'], $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Contraseña incorrecta', 'medio');
                    }
                }
            } else {
                $error = 'Usuario o contraseña incorrectos';
                log_security_event('login_fallido', null, $username, $ip_address, $user_agent, $_SERVER['REQUEST_URI'], 'POST', 'Usuario no existe', 'medio');
            }
        }
    }
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Sistema de Transporte</title>
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --c1: #0f2027;
            --c2: #1a4a6e;
            --c3: #00b4d8;
            --c4: #0077b6;
            --c5: #023e8a;
            --accent: #00e5ff;
            --glass: rgba(255,255,255,0.06);
            --glass-border: rgba(255,255,255,0.12);
            --text: #ffffff;
            --text-muted: rgba(255,255,255,0.5);
            --input-bg: rgba(255,255,255,0.07);
            --input-border: rgba(255,255,255,0.15);
            --input-focus: rgba(0,229,255,0.4);
            --error-bg: rgba(255,77,77,0.15);
            --error-border: rgba(255,77,77,0.4);
        }
        /*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #050d18;
        }

        /* ── Fondo animado ── */
        .bg-canvas {
            position: fixed;
            inset: 0;
            z-index: 0;
            background: linear-gradient(135deg, var(--c1), var(--c5), var(--c2), var(--c4), var(--c3));
            background-size: 400% 400%;
            animation: gradShift 12s ease infinite;
        }

        @keyframes gradShift {
            0%   { background-position: 0% 50%; }
            50%  { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Orbs flotantes */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.35;
            animation: orbFloat linear infinite;
            z-index: 0;
        }
        .orb-1 { width: 500px; height: 500px; background: #00b4d8; top: -150px; left: -100px; animation-duration: 18s; }
        .orb-2 { width: 400px; height: 400px; background: #5e60ce; bottom: -100px; right: -80px; animation-duration: 22s; animation-delay: -6s; }
        .orb-3 { width: 300px; height: 300px; background: #00e5ff; top: 40%; left: 40%; animation-duration: 15s; animation-delay: -3s; }

        @keyframes orbFloat {
            0%   { transform: translate(0, 0) scale(1); }
            33%  { transform: translate(40px, -30px) scale(1.08); }
            66%  { transform: translate(-20px, 50px) scale(0.95); }
            100% { transform: translate(0, 0) scale(1); }
        }

        /* Grid pattern overlay */
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(0,229,255,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,229,255,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        /* ── Layout principal ── */
        .page-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 960px;
            min-height: 520px;
            margin: 20px;
            display: flex;
            border-radius: 24px;
            overflow: hidden;
            backdrop-filter: blur(20px);
            box-shadow:
                0 0 0 1px var(--glass-border),
                0 40px 100px rgba(0,0,0,0.6),
                0 0 80px rgba(0,180,216,0.15);
            animation: cardIn 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: scale(0.94) translateY(20px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }

        /* ── Panel izquierdo (branding) ── */
        .panel-brand {
            flex: 1.1;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 50px 40px;
            background: rgba(0,0,0,0.25);
            border-right: 1px solid var(--glass-border);
            overflow: hidden;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        .panel-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(160deg, rgba(0,229,255,0.12) 0%, transparent 60%);
            pointer-events: none;
        }

        .brand-icon {
            font-size: 72px;
            margin-bottom: 24px;
            animation: iconBounce 3s ease-in-out infinite;
            filter: drop-shadow(0 0 30px rgba(0,229,255,0.6));
        }

        @keyframes iconBounce {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-8px); }
        }

        .brand-title {
            font-family: 'Syne', sans-serif;
            font-size: 26px;
            font-weight: 800;
            color: #fff;
            text-align: center;
            line-height: 1.2;
            letter-spacing: -0.5px;
            margin-bottom: 12px;
            text-shadow: 0 0 40px rgba(0,229,255,0.4);
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        .brand-title span {
            color: var(--accent);
        }

        .brand-sub {
            font-size: 14px;
            color: var(--text-muted);
            text-align: center;
            line-height: 1.6;
            max-width: 240px;
        }

        .brand-divider {
            width: 48px;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), transparent);
            border-radius: 2px;
            margin: 20px auto;
        }

        .brand-stats {
            display: flex;
            gap: 24px;
            margin-top: 32px;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        .stat {
            text-align: center;
        }

        .stat-num {
            font-family: 'Syne', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--accent);
            display: block;
        }

        .stat-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Líneas decorativas animadas */
        .deco-lines {
            position: absolute;
            bottom: 30px;
            left: 0; right: 0;
            display: flex;
            justify-content: center;
            gap: 6px;
        }

        .deco-line {
            height: 3px;
            border-radius: 2px;
            background: var(--accent);
            animation: lineGrow 2s ease-in-out infinite alternate;
        }
        .deco-line:nth-child(1) { width: 30px; animation-delay: 0s; }
        .deco-line:nth-child(2) { width: 50px; animation-delay: 0.3s; }
        .deco-line:nth-child(3) { width: 20px; animation-delay: 0.6s; }

        @keyframes lineGrow {
            from { opacity: 0.3; transform: scaleX(0.6); }
            to   { opacity: 1;   transform: scaleX(1); }
        }

        /* ── Panel derecho (formulario) ── */
        .panel-form {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 50px 44px;
            background: rgba(5, 13, 24, 0.7);
        }

        .form-header {
            margin-bottom: 32px;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        .form-eyebrow {
            font-size: 11px;
            font-weight: 500;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 8px;
        }

        .form-title {
            font-family: 'Syne', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #fff;
            line-height: 1.1;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        /* Error */
        .error-box {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: #ff9999;
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25%       { transform: translateX(-6px); }
            75%       { transform: translateX(6px); }
        }

        /* Grupos de campo */
        .field-group {
            margin-bottom: 20px;
        }

        .field-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 500;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
/*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
        .field-label .icon { font-size: 14px; }

        .field-input {
            width: 100%;
            padding: 13px 16px;
            background: var(--input-bg);
            border: 1px solid var(--input-border);
            border-radius: 12px;
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 15px;
            transition: all 0.25s ease;
            outline: none;
        }

        .field-input::placeholder { color: rgba(255,255,255,0.25); }

        .field-input:focus {
            border-color: var(--accent);
            background: rgba(0,229,255,0.06);
            box-shadow: 0 0 0 3px var(--input-focus);
        }

        /* Botón */
        .btn-login {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-family: 'Syne', sans-serif;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #050d18;
            background: linear-gradient(90deg, #00e5ff, #00b4d8, #0077b6, #00e5ff);
            background-size: 250% 100%;
            animation: btnGrad 4s linear infinite;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        @keyframes btnGrad {
            0%   { background-position: 0% 50%; }
            100% { background-position: 250% 50%; }
        }

        .btn-login::after {
            content: '';
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0);
            transition: background 0.2s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,229,255,0.45);
        }

        .btn-login:hover::after { background: rgba(255,255,255,0.1); }
        .btn-login:active { transform: translateY(0); }

        /* Footer del form */
        .form-footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--glass-border);
        }

        .test-creds {
            display: flex;
            gap: 10px;
            margin-bottom: 14px;
        }

        .cred-pill {
            flex: 1;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 8px;
            padding: 8px 12px;
            text-align: center;
        }

        .cred-role {
            font-size: 10px;
            color: var(--accent);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .cred-info {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
            font-family: monospace;
        }

        .forgot-link {
            text-align: center;
        }

        .forgot-link a {
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
        }

        .forgot-link a:hover { color: var(--accent); }

        /* ── Responsive ── */
        @media (max-width: 680px) {
            .page-wrapper { flex-direction: column; max-width: 420px; min-height: auto; }
            .panel-brand { padding: 36px 30px 28px; border-right: none; border-bottom: 1px solid var(--glass-border); }
            .brand-stats { display: none; }
            .panel-form { padding: 32px 28px 36px; }
            .brand-icon { font-size: 52px; }
            .brand-title { font-size: 20px; }
        }
        /*
        ░░░░░░░░░░▐▐░░░░░┌───┐
        ░▐░░░░░░░▄██▄▄───┤ARF.│
        ░░▀▀██████▀░░░░▓▓└───┘
        ░░░░▐▐░░▐▐░W00F░▓▓▓▓╝░
        ▒▒▒▒▐▐▒▒▐▐▒▒▒▒▒▒▓▒▒▓▒▒
    CREADO POR EL MÁS PERRON DE AQUI
        */
    </style>
</head>
<body>

    <div class="bg-canvas"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="bg-grid"></div>

    <div class="page-wrapper">

        <!-- Panel izquierdo: Branding -->
        <div class="panel-brand">
            <div class="brand-icon">🚛</div>
            <div class="brand-title">Sistema de<br><span>Gestión</span> de<br>Transporte</div>
            <div class="brand-divider"></div>
            <p class="brand-sub">Control total de tu flota desde un solo lugar, seguro y eficiente.</p>

            <div class="brand-stats">
                <div class="stat">
                    <span class="stat-num">24/7</span>
                    <span class="stat-label">Disponible</span>
                </div>
                <div class="stat">
                    <span class="stat-num">100%</span>
                    <span class="stat-label">Seguro</span>
                </div>
                <div class="stat">
                    <span class="stat-num">RT</span>
                    <span class="stat-label">Tiempo real</span>
                </div>
            </div>

            <div class="deco-lines">
                <div class="deco-line"></div>
                <div class="deco-line"></div>
                <div class="deco-line"></div>
            </div>
        </div>

        <!-- Panel derecho: Formulario -->
        <div class="panel-form">
            <div class="form-header">
                <div class="form-eyebrow">Portal de acceso</div>
                <div class="form-title">Bienvenido<br>de vuelta</div>
            </div>

            <?php if ($error): ?>
                <div class="error-box">
                    ⚠️ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                <div class="field-group">
                    <div class="field-label">
                        <span class="icon">👤</span> Usuario
                    </div>
                    <input
                        type="text"
                        name="username"
                        class="field-input"
                        value="<?php echo htmlspecialchars($username); ?>"
                        placeholder="Ingresa tu usuario"
                        required
                        autofocus
                        autocomplete="username"
                    >
                </div>

                <div class="field-group">
                    <div class="field-label">
                        <span class="icon">🔒</span> Contraseña
                    </div>
                    <input
                        type="password"
                        name="password"
                        class="field-input"
                        placeholder="••••••••"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button type="submit" class="btn-login">Iniciar Sesión →</button>
            </form>

            <div class="form-footer">
                <div class="test-creds">
                    <div class="cred-pill">
                        <div class="cred-role">Ingresa tu contraseña</div>
                        <div class="cred-info"></div>
                    </div>
                    </div>
                </div>
                <div class="forgot-link">
                    <a href="#">¿Olvidaste tu contraseña?</a>
                </div>
            </div>
        </div>

    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const u = this.querySelector('input[name="username"]').value.trim();
            const p = this.querySelector('input[name="password"]').value.trim();
            if (!u || !p) {
                e.preventDefault();
                alert('Por favor, completa todos los campos');
            }
        });
    </script>
</body>
</html>