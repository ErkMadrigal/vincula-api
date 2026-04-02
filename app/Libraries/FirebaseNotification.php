<?php
namespace App\Libraries;

class FirebaseNotification
{
    private string $serverKey;

    public function __construct()
    {
        $this->serverKey = $_ENV['FIREBASE_SERVER_KEY'] ?? '';
    }

    public function enviar(array $tokens, string $titulo, string $cuerpo, array $data = []): void
    {
        if (empty($tokens) || empty($this->serverKey)) return;

        $payload = json_encode([
            'registration_ids' => $tokens,
            'notification'     => [
                'title' => $titulo,
                'body'  => $cuerpo,
                'sound' => 'default',
            ],
            'data' => $data,
        ]);

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: key=' . $this->serverKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}