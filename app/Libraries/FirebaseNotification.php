<?php
namespace App\Libraries;
class FirebaseNotification
{
    private string $projectId = '';
    private string $credentialsPath;
    private ?string $errorConfig = null; // si hay error, enviar() no hace nada (no tumba la petición)

    public function __construct()
    {
        $this->credentialsPath = APPPATH . 'Config/firebase-credentials.json';

        if (!file_exists($this->credentialsPath)) {
            $this->errorConfig = 'No existe app/Config/firebase-credentials.json';
            log_message('error', 'Firebase: ' . $this->errorConfig);
            return;
        }

        // Setear credenciales una sola vez
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->credentialsPath);

        $credentials = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (!$credentials || !isset($credentials['project_id'])) {
            $this->errorConfig = 'Credenciales de Firebase inválidas';
            log_message('error', 'Firebase: ' . $this->errorConfig);
            return;
        }

        $this->projectId = $credentials['project_id'];
    }

    private function getAccessToken(): string
    {
        $credentials = \Google\Auth\ApplicationDefaultCredentials::getCredentials(
            'https://www.googleapis.com/auth/firebase.messaging'
        );

        $token = $credentials->fetchAuthToken();

        if (!isset($token['access_token'])) {
            throw new \Exception('No se pudo obtener access_token de Firebase');
        }

        return $token['access_token'];
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $data = []): array
    {
        if (empty($tokens)) {
            return ['enviados' => 0, 'errores' => 0];
        }

        if ($this->errorConfig !== null) {
            return ['enviados' => 0, 'errores' => count($tokens), 'error' => $this->errorConfig];
        }

        // Si Firebase falla, no debe tumbar el registro de asistencia/aviso/etc.
        try {
            $accessToken = $this->getAccessToken();
        } catch (\Throwable $e) {
            log_message('error', 'Firebase auth error: ' . $e->getMessage());
            return ['enviados' => 0, 'errores' => count($tokens)];
        }
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $enviados = 0;
        $errores  = 0;

        foreach ($tokens as $token) {

            // Todo en prioridad ALTA para que llegue aunque el teléfono esté
            // en reposo (Doze) y se muestre con la app abierta o cerrada.
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $titulo,
                        'body'  => $cuerpo,
                    ],
                    'data' => array_map('strval', $data + ['title' => $titulo, 'body' => $cuerpo]),
                    'android' => [
                        'priority' => 'high',
                        'ttl'      => '86400s', // si el teléfono está apagado, se entrega al prenderlo (hasta 24 h)
                        'notification' => [
                            'channel_id'              => 'vincula_alertas',
                            'sound'                   => 'default',
                            'notification_priority'   => 'PRIORITY_MAX',
                            'visibility'              => 'PUBLIC',
                            'default_vibrate_timings' => true,
                        ],
                    ],
                    'apns' => [
                        'headers' => [
                            'apns-priority'  => '10',
                            'apns-push-type' => 'alert',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                            ],
                        ],
                    ],
                    'webpush' => [
                        'headers' => [
                            'Urgency' => 'high',
                            'TTL'     => '86400',
                        ],
                        'notification' => [
                            'icon' => 'https://app.vincula365.com/assets/icons/icon-192.webp',
                        ],
                        'fcm_options' => [
                            'link' => 'https://app.vincula365.com/',
                        ],
                    ],
                ],
            ];

            // Reintento si FCM responde 429 (saturado) o 5xx (error temporal)
            $intentos = 0;
            do {
                $intentos++;

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                $reintentar = ($httpCode === 429 || $httpCode >= 500 || $httpCode === 0) && $intentos < 3;
                if ($reintentar) {
                    usleep(500000 * $intentos); // 0.5 s, luego 1 s
                }
            } while ($reintentar);

            if ($httpCode === 200) {
                $enviados++;
            } else {
                $errores++;

                log_message('error', 'Firebase error: ' . $response);

                // Token que ya no existe (app desinstalada, etc.) → desactivarlo
                if ($httpCode === 404 || str_contains((string) $response, 'UNREGISTERED')) {
                    \Config\Database::connect()->table('dispositivos')
                        ->where('fcm_token', $token)
                        ->update(['activo' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
                }
            }
        }

        return ['enviados' => $enviados, 'errores' => $errores];
    }
}