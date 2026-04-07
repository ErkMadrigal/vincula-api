<?php
namespace App\Libraries;

class FirebaseNotification
{
    private string $projectId;
    private string $credentialsPath;

    public function __construct()
    {
        $this->credentialsPath = APPPATH . 'Config/firebase-credentials.json';
        $credentials           = json_decode(file_get_contents($this->credentialsPath), true);
        $this->projectId       = $credentials['project_id'];
    }

    private function getAccessToken(): string
    {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->credentialsPath);

        $credentials = \Google\Auth\ApplicationDefaultCredentials::getCredentials(
            'https://www.googleapis.com/auth/firebase.messaging'
        );

        $token = $credentials->fetchAuthToken();
        return $token['access_token'];
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $data = []): array
    {
        if (empty($tokens)) return ['enviados' => 0, 'errores' => 0];

        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->credentialsPath);

        $accessToken = $this->getAccessToken();
        $url         = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $enviados = 0;
        $errores  = 0;

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => $titulo,
                        'body'  => $cuerpo,
                    ],
                    'data'    => array_map('strval', $data),
                    'android' => [
                        'notification' => [
                            'sound'        => 'default',
                            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        ],
                    ],
                    'apns'    => [
                        'payload' => [
                            'aps' => ['sound' => 'default'],
                        ],
                    ],
                ],
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $httpCode === 200 ? $enviados++ : $errores++;
        </s>

        return ['enviados' => $enviados, 'errores' => $errores];
    }
}