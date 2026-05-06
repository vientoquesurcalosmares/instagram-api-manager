<?php

namespace ScriptDevelop\InstagramApiManager\Tests;

use Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * Test para verificar el flujo de recepción de mensajes de Instagram
 * Ejecutar: php artisan test --filter="InstagramWebhookMessagesTest"
 */
class InstagramWebhookMessagesTest extends TestCase
{
    /**
     * Test de recepción de mensaje de texto
     */
    public function test_recibir_mensaje_de_texto()
    {
        Log::channel('instagram')->info('🧪 TEST: Simulando recepción de mensaje de texto');

        $payload = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => '123456789',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '987654321'],
                            'recipient' => ['id' => '123456789'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'mid.' . uniqid(),
                                'text' => '¡Hola! Este es un mensaje de prueba'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Log::channel('instagram')->info('📨 Payload simulado:', $payload);

        // POST al webhook
        $response = $this->postJson(route('instagram.webhook'), $payload);

        $response->assertStatus(200);
        $response->assertSeeText('EVENT_RECEIVED');

        Log::channel('instagram')->info('✅ Test completado');
    }

    /**
     * Test de recepción de postback
     */
    public function test_recibir_postback()
    {
        Log::channel('instagram')->info('🧪 TEST: Simulando recepción de postback');

        $payload = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => '123456789',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '987654321'],
                            'recipient' => ['id' => '123456789'],
                            'timestamp' => time() * 1000,
                            'postback' => [
                                'mid' => 'postback_' . uniqid(),
                                'title' => 'Botón de prueba',
                                'payload' => 'BUTTON_CLICKED'
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson(route('instagram.webhook'), $payload);

        $response->assertStatus(200);
        Log::channel('instagram')->info('✅ Test postback completado');
    }

    /**
     * Test de recepción de mensaje con adjuntos
     */
    public function test_recibir_mensaje_con_imagen()
    {
        Log::channel('instagram')->info('🧪 TEST: Simulando recepción de mensaje con imagen');

        $payload = [
            'object' => 'instagram',
            'entry' => [
                [
                    'id' => '123456789',
                    'time' => time(),
                    'messaging' => [
                        [
                            'sender' => ['id' => '987654321'],
                            'recipient' => ['id' => '123456789'],
                            'timestamp' => time() * 1000,
                            'message' => [
                                'mid' => 'mid.' . uniqid(),
                                'text' => 'Mira esta imagen',
                                'attachments' => [
                                    [
                                        'type' => 'image',
                                        'payload' => [
                                            'url' => 'https://example.com/image.jpg'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson(route('instagram.webhook'), $payload);

        $response->assertStatus(200);
        Log::channel('instagram')->info('✅ Test mensaje con imagen completado');
    }

    /**
     * Test para verificar que rechaza webhook sin token
     */
    public function test_webhook_sin_token_es_rechazado()
    {
        Log::channel('instagram')->info('🧪 TEST: Verificando validación de token');

        $response = $this->call('GET', route('instagram.webhook'), [
            'hub_mode' => 'subscribe',
            'hub_challenge' => 'test_challenge',
            'hub_verify_token' => 'token_invalido'
        ]);

        $response->assertStatus(403);
        Log::channel('instagram')->info('✅ Test de token invalido completado');
    }

    /**
     * Test para verificar que acepta webhook con token válido
     */
    public function test_webhook_con_token_valido_es_aceptado()
    {
        Log::channel('instagram')->info('🧪 TEST: Verificando validación de token válido');

        $challenge = 'test_challenge_' . uniqid();
        $validToken = config('instagram.webhook.verify_token');

        $response = $this->call('GET', route('instagram.webhook'), [
            'hub_mode' => 'subscribe',
            'hub_challenge' => $challenge,
            'hub_verify_token' => $validToken
        ]);

        $response->assertStatus(200);
        $response->assertSeeText($challenge);
        Log::channel('instagram')->info('✅ Test de token válido completado');
    }
}
