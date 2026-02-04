<?php

namespace ScriptDevelop\InstagramApiManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ScriptDevelop\InstagramApiManager\Services\InstagramMessageService;
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
        $expectedToken = config('instagram.api.webhook_verify_token');

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

        Log::channel('instagram')->info('Instagram Webhook event received:', $data);

        try {
            $this->messageService->processWebhookPayload($data);
            return response('EVENT_RECEIVED', 200);
        } catch (\Exception $e) {
            Log::channel('instagram')->error('Error processing Instagram webhook:', [
                'error' => $e->getMessage(),
                'payload' => $data
            ]);
            return response('ERROR_PROCESSING', 500);
        }
    }
}
