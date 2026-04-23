<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="../assets/error.png" type="image/x-icon">
    <title>Upss | Sitio en Mantenimiento</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #2c2c2c;
            color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
            line-height: 1.6;
        }

        .maintenance-container {
            max-width: 600px;
            width: 100%;
            text-align: center;
            background-color: #3a3a3a;
            border-radius: 12px;
            padding: 40px 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        .maintenance-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4, #45b7d1, #96ceb4, #feca57, #ff9ff3, #54a0ff);
        }

        .error-code {
            position: absolute;
            top: 15px;
            right: 20px;
            font-family: monospace;
            font-size: 0.9rem;
            color: #888;
            background: rgba(0, 0, 0, 0.2);
            padding: 3px 8px;
            border-radius: 4px;
        }

        .dino-scene {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            height: 120px;
            margin: 20px 0 30px 0;
            position: relative;
        }

        .dino {
            width: 95px;
            height: 95px;
            background-image: url("../assets/svg/dinos.svg");
            background-repeat: no-repeat;
            background-position: center;
            animation: bounce 0.8s infinite alternate;
            transform-origin: center;
        }

        .ground {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: #555;
        }

        .ground::before {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: repeating-linear-gradient(
                to right,
                #666 0px,
                #666 4px,
                transparent 4px,
                transparent 8px
            );
        }

        .cactus {
            position: absolute;
            right: 25%;
            bottom: 0;
            width: 16px;
            height: 48px;
            background-color: #888;
        }

        .cactus::before {
            content: "";
            position: absolute;
            top: 0;
            left: 4px;
            width: 1px;
            height: 48px;
            background: repeating-linear-gradient(
                to bottom,
                #666 0px,
                #666 2px,
                transparent 2px,
                transparent 4px
            );
        }

        .title {
            font-size: 2.2rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 15px;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .subtitle {
            font-size: 1.2rem;
            color: #ccc;
            margin-bottom: 25px;
        }

        .message {
            background: rgba(255, 255, 255, 0.05);
            border-left: 4px solid #4ecdc4;
            padding: 15px;
            margin: 25px 0;
            text-align: left;
            border-radius: 0 8px 8px 0;
        }

        .suggestions {
            list-style: none;
            padding: 0;
            margin: 0 0 25px 0;
            text-align: left;
        }

        .suggestions li {
            font-size: 1rem;
            color: #ccc;
            margin-bottom: 12px;
            position: relative;
            padding-left: 25px;
        }

        .suggestions li::before {
            content: "→";
            position: absolute;
            left: 0;
            color: #4ecdc4;
            font-weight: bold;
        }

        .contact-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #555;
        }

        .contact-info p {
            margin-bottom: 10px;
            color: #999;
        }

        .email {
            color: #4ecdc4;
            font-weight: bold;
            text-decoration: none;
        }

        .email:hover {
            text-decoration: underline;
        }

        .countdown {
            margin: 20px 0;
            font-size: 1.1rem;
            color: #ff6b6b;
            font-weight: bold;
        }

        .progress-container {
            width: 100%;
            background-color: #555;
            border-radius: 10px;
            margin: 20px 0;
            overflow: hidden;
        }

        .progress-bar {
            height: 8px;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4);
            width: 65%;
            border-radius: 10px;
            animation: progress 2s infinite alternate;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }

        .social-link {
            color: #ccc;
            text-decoration: none;
            transition: color 0.3s;
        }

        .social-link:hover {
            color: #4ecdc4;
        }

        @keyframes bounce {
            from { transform: translateY(0); }
            to { transform: translateY(-10px); }
        }

        @keyframes progress {
            from { width: 65%; }
            to { width: 70%; }
        }

        /* Responsive adjustments */
        @media (max-width: 600px) {
            .maintenance-container {
                padding: 30px 20px;
            }
            
            .title {
                font-size: 1.8rem;
            }
            
            .subtitle {
                font-size: 1rem;
            }
            
            .dino-scene {
                height: 100px;
            }
            
            .dino {
                width: 60px;
                height: 60px;
            }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="error-code">EN MANTENIMIENTO</div>
        
        <div class="dino-scene">
            <div class="dino"></div>
            <div class="cactus"></div>
            <div class="ground"></div>
        </div>
        
        <h1 class="title">Sitio en Mantenimiento</h1>
        <p class="subtitle">Estamos trabajando para mejorar tu experiencia</p>
        
        <div class="message">
            <p>Nuestro equipo está realizando actualizaciones importantes en el sitio. Lamentamos las molestias y agradecemos tu paciencia.</p>
        </div>
        
        <div class="progress-container">
            <div class="progress-bar"></div>
        </div>
        
        <div class="countdown" id="countdown"></div>
        
        <p class="subtitle">Mientras tanto, puedes:</p>
        <ul class="suggestions">
            <li>Volver a intentarlo en unas horas</li>
            <li>Seguirnos en nuestras redes sociales para actualizaciones</li>
            <li>Contactarnos si necesitas asistencia urgente</li>
            <li>Explorar nuestro blog de noticias</li>
        </ul>
    </div>

    <script>
        // Ejemplo de contador regresivo (puedes ajustar la fecha)
        function updateCountdown() {
            const returnTime = new Date();
            returnTime.setHours(returnTime.getHours() + 6); // 6 horas desde ahora
            
            const now = new Date();
            const diff = returnTime - now;
            
            if (diff > 0) {
                const hours = Math.floor(diff / (1000 * 60 * 60));
                const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                
                document.getElementById('countdown').textContent = 
                    `Volvemos en: ${hours}h ${minutes}m`;
            } else {
                document.getElementById('countdown').textContent = 
                    '¡Casi listos! Vuelve a intentar en unos minutos.';
            }
        }
        
        // Actualizar cada minuto
        updateCountdown();
        setInterval(updateCountdown, 60000);
    </script>
</body>
</html>