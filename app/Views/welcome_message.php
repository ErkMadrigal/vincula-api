<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vincula365 API</title>
    <meta name="description" content="Vincula API - Servicio backend para gestión de Alumnos, asistencias y notificaciones en tiempo real. Plataforma segura y escalable.">
    <meta property="og:description" content="Backend activo de la plataforma Vincula.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://api.vincula365.com">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="Vincula API">
    <link rel="shortcut icon" type="image/png" href="./favicon.png">

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            background: radial-gradient(circle at center, #0B0533 0%, #020617 100%);
        }

        .logo {
            animation: float 4s ease-in-out infinite;
            transform-origin: center;
        }

        @keyframes float {
            0%,100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }

        .neon-text {
            text-shadow:
                0 0 6px #22d3ee,
                0 0 12px #22d3ee,
                0 0 25px #22d3ee;
        }

        .pulse-ring {
            position: absolute;
            width: 140px;
            height: 140px;
            border: 2px solid rgba(34,211,238,0.3);
            border-radius: 999px;
            animation: pulse 2.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.8); opacity: 0.6; }
            100% { transform: scale(1.6); opacity: 0; }
        }
    </style>
</head>

<body class="text-white">

<div class="min-h-screen flex items-center justify-center">

    <div class="text-center relative flex flex-col items-center">

        <!-- círculo animado -->
        <div class="pulse-ring mx-auto"></div>

        <!-- SVG INLINE (IMPORTANTE) -->
        <div class="logo w-64 md:w-80 mx-auto mb-6">

            <svg viewBox="0 90 512 300">

                <defs>
                    <style>
                        .pulse1 { animation: pulse 2s ease-out infinite; transform-origin: 256px 230px; }
                        .pulse2 { animation: pulse 2s ease-out 0.5s infinite; transform-origin: 256px 230px; }
                        .pulse3 { animation: pulse 2s ease-out 1s infinite; transform-origin: 256px 230px; }

                        .dash-l { animation: dashMove 1.8s linear infinite; }
                        .dash-r { animation: dashMove 1.8s linear 0.6s infinite; }

                        @keyframes pulse {
                            0% { opacity: 0; transform: scale(0.85); }
                            30% { opacity: 0.7; }
                            100% { opacity: 0; transform: scale(1.2); }
                        }

                        @keyframes dashMove {
                            0% { stroke-dashoffset: 120; opacity: 1; }
                            100% { stroke-dashoffset: 0; opacity: 0.3; }
                        }
                    </style>
                </defs>

                <!-- rings -->
                <circle class="pulse1" cx="256" cy="230" r="36" fill="none" stroke="#22d3ee" stroke-width="2"/>
                <circle class="pulse2" cx="256" cy="230" r="54" fill="none" stroke="#8b5cf6" stroke-width="1.5"/>
                <circle class="pulse3" cx="256" cy="230" r="72" fill="none" stroke="#a78bfa" stroke-width="1"/>

                <!-- líneas -->
                <polyline class="dash-l" points="256,230 196,110" fill="none" stroke="#22d3ee" stroke-width="4" stroke-dasharray="8 6"/>
                <polyline class="dash-r" points="256,230 316,110" fill="none" stroke="#8b5cf6" stroke-width="4" stroke-dasharray="8 6"/>

                <!-- nodos -->
                <circle cx="256" cy="230" r="6" fill="#22d3ee"/>
                <circle cx="196" cy="110" r="6" fill="#22d3ee"/>
                <circle cx="316" cy="110" r="6" fill="#8b5cf6"/>

            </svg>

        </div>

        <!-- texto -->
        <h1 class="text-5xl tracking-widest font-light neon-text text-cyan-300">
            VINCULA365
        </h1>

        <p class="text-purple-300 text-sm mt-3 tracking-widest">
            educación · conexión · comunidad
        </p>

        <!-- card -->
        <div class="mt-10 bg-[#1B0F5C]/60 border border-cyan-400/20 rounded-2xl p-6 backdrop-blur">

            <p class="text-purple-200 mb-4">API Status</p>

            <div class="flex justify-center items-center gap-2 mb-4">
                <span class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></span>
                <span class="text-green-400">Online</span>
            </div>

            <a href="/api/ping"
               class="px-6 py-2 bg-cyan-400 text-black font-semibold rounded-lg hover:bg-cyan-300 transition">
               Probar API
            </a>

        </div>

    </div>

</div>

</body>
</html>