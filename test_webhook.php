<?php
$ch = curl_init('http://localhost/webhook/whatsapp');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
$payload = json_encode([
    'object' => 'whatsapp_business_account',
    'entry' => [
        [
            'id' => '12345',
            'changes' => [
                [
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '12345',
                            'phone_number_id' => '12345'
                        ],
                        'contacts' => [
                            [
                                'profile' => ['name' => 'John Doe'],
                                'wa_id' => '15551234567'
                            ]
                        ],
                        'messages' => [
                            [
                                'from' => '15551234567',
                                'id' => 'wamid.HBgLM...',
                                'timestamp' => time(),
                                'text' => ['body' => 'Hello from local test!'],
                                'type' => 'text'
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ]
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($payload)
]);
$result = curl_exec($ch);
echo "Response: " . $result . "\n";
echo "Error: " . curl_error($ch) . "\n";
curl_close($ch);
