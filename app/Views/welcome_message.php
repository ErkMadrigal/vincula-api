    
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vincula API</title>
    <meta name="description" content="Vincula API - Servicio backend para gestión de Alumnos, asistencias y notificaciones en tiempo real. Plataforma segura y escalable.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/png" href="./favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-950 text-white">

    <div class="min-h-screen flex items-center justify-center px-6">

        <div class="max-w-2xl w-full text-center">

            <!-- Logo / Title -->
            <h1 class="text-5xl font-bold text-cyan-400 mb-4">
                Vincula365.com API
            </h1>

            <p class="text-slate-400 mb-8">
                Backend de la plataforma Vincula365 — Servicio activo y listo para integraciones.
            </p>

            <!-- Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">

                <h2 class="text-xl font-semibold mb-4 text-cyan-300">
                    Estado del servicio
                </h2>

                <div class="flex items-center justify-center gap-3 mb-6">
                    <span class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></span>
                    <span class="text-green-400 font-medium">API Activa</span>
                </div>

                <!-- Endpoint -->
                <div class="bg-slate-800 rounded-lg p-4 text-left mb-4">
                    <p class="text-sm text-slate-400 mb-1">Endpoint de prueba</p>
                    <code class="text-cyan-300 text-sm">
                        https://api.vincula365.com/api/ping
                    </code>
                </div>

                <a href="/api/ping"
                   class="inline-block mt-2 px-6 py-3 bg-cyan-500 hover:bg-cyan-400 text-slate-900 font-semibold rounded-lg transition">
                    Probar API
                </a>

            </div>

            <!-- Footer -->
            <p class="text-xs text-slate-600 mt-8">
                © <?= date('Y') ?> Vincula • API v1.0
            </p>

        </div>

    </div>

</body>
</html>