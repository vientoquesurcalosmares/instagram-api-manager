<?php

namespace ScriptDevelop\InstagramApiManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ScriptDevelop\InstagramApiManager\Services\InstagramMessageService;
use ScriptDevelop\InstagramApiManager\Models\InstagramMessage;
use Illuminate\Support\Facades\Log;

class InstagramWebhookController extends Controller
{
    protected InstagramMessageService $messageService;

    public function __construct(InstagramMessageService $messageService)
    {
        $this->messageService = $messageService;
    }

    public function handle(Request $request)
    {
        // Verificación del webhook (GET)
        if ($request->isMethod('get')) {
            return $this->handleVerification($request);
        }

        // Manejo de eventos (POST)
        if ($request->isMethod('post')) {
            return $this->handleEvent($request);
        }

        return response('Method Not Allowed', 405);
    }

    protected function handleVerification(Request $request)
    {
        $challenge = $request->get('hub_challenge');
        $verifyToken = $request->get('hub_verify_token');
        $expectedToken = config('instagram.webhook.verify_token');

        if ($verifyToken === $expectedToken && $challenge) {
            Log::info('Instagram webhook verified successfully');
            return response($challenge, 200);
        }

        Log::warning('Instagram webhook verification failed', [
            'expected_token' => $expectedToken,
            'received_token' => $verifyToken
        ]);

        return response('Forbidden', 403);
    }

    protected function handleEvent(Request $request)
    {
        $data = $request->all();

        Log::channel('instagram')->info('=== WEBHOOK DE INSTAGRAM RECIBIDO ===');
        Log::channel('instagram')->info('Datos brutos del webhook:', $data);

        try {
            // PROCESAR CADA ENTRADA DEL WEBHOOK
            if (isset($data['entry']) && is_array($data['entry'])) {
                foreach ($data['entry'] as $entry) {
                    Log::channel('instagram')->info('Procesando entrada del webhook', [
                        'entry_id' => $entry['id'] ?? 'unknown'
                    ]);

                    // PROCESAR CADA MENSAJE EN LA ENTRADA
                    if (isset($entry['messaging']) && is_array($entry['messaging'])) {
                        foreach ($entry['messaging'] as $messaging) {
                            Log::channel('instagram')->info('📨 MENSAJE RECIBIDO EN EL WEBHOOK', [
                                'sender_id' => $messaging['sender']['id'] ?? null,
                                'recipient_id' => $messaging['recipient']['id'] ?? null,
                                'timestamp' => $messaging['timestamp'] ?? null,
                                'has_message' => isset($messaging['message']),
                                'message_type' => $this->determineMessageType($messaging)
                            ]);

                            // AQUÍ SE PROCESA Y ALMACENA EN BD
                            $this->messageService->processWebhookMessage($messaging);
                        }
                    } else {
                        Log::channel('instagram')->warning('No hay mensajes en esta entrada del webhook');
                    }
                }
            } else {
                Log::channel('instagram')->warning('Webhook sin entradas (entry)');
            }

            Log::channel('instagram')->info('=== WEBHOOK PROCESADO EXITOSAMENTE ===');
            return response('EVENT_RECEIVED', 200);

        } catch (\Exception $e) {
            Log::channel('instagram')->error('❌ ERROR PROCESANDO WEBHOOK:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => $data
            ]);
            return response('ERROR_PROCESSING', 500);
        }
    }

    /**
     * Determinar el tipo de mensaje para logging
     */
    protected function determineMessageType(array $messaging): string
    {
        if (isset($messaging['message'])) {
            if (isset($messaging['message']['text'])) {
                return 'text_message';
            } elseif (isset($messaging['message']['attachments'])) {
                return 'attachment_message';
            }
            return 'message';
        } elseif (isset($messaging['postback'])) {
            return 'postback';
        } elseif (isset($messaging['reaction'])) {
            return 'reaction';
        } elseif (isset($messaging['read'])) {
            return 'read_event';
        } elseif (isset($messaging['referral'])) {
            return 'referral';
        } elseif (isset($messaging['optin'])) {
            return 'optin';
        }
        return 'unknown';
    }
}
