<?php

namespace ScriptDevelop\InstagramApiManager\Services;

use ScriptDevelop\InstagramApiManager\InstagramApi\ApiClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Exception;
use ScriptDevelop\InstagramApiManager\Support\InstagramModelResolver;
use ScriptDevelop\InstagramApiManager\InstagramApi\Exceptions\ApiException;
use ScriptDevelop\InstagramApiManager\Services\InstagramAccountService; // Asegúrate de importar la clase

class InstagramMessageService
{
    protected ApiClient $apiClient;
    protected ?string $accessToken = null;
    protected ?string $instagramUserId = null;
    protected InstagramAccountService $accountService; // <-- DECLARACIÓN DE LA PROPIEDAD

    public function __construct(?InstagramAccountService $accountService = null)
    {
        // Cliente principal para mensajería (Graph API de Facebook)
        $this->apiClient = new ApiClient(
            config('instagram.api.graph_base_url', 'https://graph.facebook.com'),
            config('instagram.api.version', 'v23.0'),
            (int) config('instagram.api.timeout', 30)
        );

        // Inyectamos el servicio de cuentas para refrescar tokens
        $this->accountService = $accountService ?? app(InstagramAccountService::class);
    }

    // ------------------------------------------------------------------------
    // Configuración de credenciales
    // ------------------------------------------------------------------------
    public function withAccessToken(string $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function withInstagramUserId(string $instagramUserId): self
    {
        $this->instagramUserId = $instagramUserId;
        return $this;
    }

    protected function validateCredentials(): void
    {
        if (!$this->accessToken || !$this->instagramUserId) {
            throw new Exception('Access Token and Instagram User ID must be set.');
        }
    }

    // ------------------------------------------------------------------------
    // ENTRY POINT
    // ------------------------------------------------------------------------
    public function processWebhookMessage(array $messaging): void
    {
        Log::channel('instagram')->info('🔄 INICIANDO PROCESAMIENTO DE MENSAJE DEL WEBHOOK');
        try {
            $this->processMessage($messaging);
            Log::channel('instagram')->info('✅ MENSAJE DEL WEBHOOK PROCESADO EXITOSAMENTE');
        } catch (\Exception $e) {
            Log::channel('instagram')->error('❌ ERROR AL PROCESAR MENSAJE:', [
                'error' => $e->getMessage(),
                'messaging' => $messaging
            ]);
            throw $e;
        }
    }

    // ------------------------------------------------------------------------
    // PROCESAMIENTO PRINCIPAL
    // ------------------------------------------------------------------------
    protected function processMessage(array $messageData): void
    {
        Log::channel('instagram')->info('═══════════════════════════════════════════════════════');
        Log::channel('instagram')->info('🔄 INICIANDO PROCESAMIENTO DE MENSAJE');
        Log::channel('instagram')->debug('Datos completos:', $messageData);

        [$senderId, $recipientId, $isEcho] = $this->extractMessageContext($messageData);
        if (!$senderId || !$recipientId) {
            Log::channel('instagram')->warning('⚠️ Evento ignorado (sin sender/recipient)');
            return;
        }

        $businessIdToSearch = $isEcho ? $senderId : $recipientId;
        $contactUserId      = $isEcho ? $recipientId : $senderId;

        Log::channel('instagram')->info('🔎 BUSCANDO CUENTA DE NEGOCIO', [
            'business_id' => $businessIdToSearch,
            'contact_id'  => $contactUserId,
            'is_echo'     => $isEcho
        ]);

        $businessAccount = $this->findBusinessAccount($businessIdToSearch);
        if (!$businessAccount) {
            Log::channel('instagram')->error('❌ CUENTA DE NEGOCIO NO ENCONTRADA', [
                'business_id' => $businessIdToSearch,
                'hint'        => 'Conecta la cuenta primero'
            ]);
            return;
        }

        Log::channel('instagram')->info('✅ Cuenta de negocio encontrada', [
            'instagram_business_account_id' => $businessAccount->instagram_business_account_id
        ]);

        $this->withAccessToken($businessAccount->access_token)
             ->withInstagramUserId($businessAccount->instagram_business_account_id);

        $conversation = $this->findOrCreateConversation(
            $businessAccount->instagram_business_account_id,
            $contactUserId
        );

        $this->updateConversationStats($conversation, $isEcho);

        $this->handleEventByType($messageData, $conversation, $contactUserId, $businessAccount, $isEcho);

        if ($this->shouldUpdateContact($messageData)) {
            $this->updateOrCreateContact($contactUserId, $businessAccount);
        }

        Log::channel('instagram')->info('✅ PROCESAMIENTO COMPLETADO');
        Log::channel('instagram')->info('═══════════════════════════════════════════════════════');
    }

    // ------------------------------------------------------------------------
    // 1. Extraer contexto del mensaje
    // ------------------------------------------------------------------------
    protected function extractMessageContext(array $messageData): array
    {
        $isEcho = isset($messageData['message']['is_echo']) && $messageData['message']['is_echo'] === true;

        $senderId   = $messageData['sender']['id'] ?? null;
        $recipientId = $messageData['recipient']['id'] ?? null;

        if (!$senderId || !$recipientId) {
            $mid = $this->extractMid($messageData);
            if ($mid) {
                $originalMessage = InstagramModelResolver::instagram_message()
                    ->where('message_id', $mid)
                    ->first();

                if ($originalMessage) {
                    $senderId   = $originalMessage->message_from;
                    $recipientId = $originalMessage->message_to;
                    Log::channel('instagram')->info('✅ Contexto recuperado por MID', [
                        'mid'      => $mid,
                        'sender'   => $senderId,
                        'recipient'=> $recipientId
                    ]);
                }
            }
        }

        $senderId   = is_array($senderId)   ? ($senderId['id'] ?? (string) $senderId)   : (string) $senderId;
        $recipientId = is_array($recipientId) ? ($recipientId['id'] ?? (string) $recipientId) : (string) $recipientId;

        return [$senderId, $recipientId, $isEcho];
    }

    protected function extractMid(array $messageData): ?string
    {
        return $messageData['message_edit']['mid']
            ?? $messageData['read']['mid']
            ?? $messageData['reaction']['mid']
            ?? $messageData['message']['mid']
            ?? null;
    }

    // ------------------------------------------------------------------------
    // 2. Buscar cuenta de negocio
    // ------------------------------------------------------------------------
    protected function findBusinessAccount(string $businessId): ?Model
    {
        $profile = InstagramModelResolver::instagram_profile()
            ->where('user_id', $businessId)
            ->first();

        if ($profile) {
            Log::channel('instagram')->info('✅ Perfil encontrado por user_id');
            return InstagramModelResolver::instagram_business_account()
                ->where('instagram_business_account_id', $profile->instagram_business_account_id)
                ->first();
        }

        $profile = InstagramModelResolver::instagram_profile()
            ->where('instagram_scoped_id', $businessId)
            ->first();

        if ($profile) {
            Log::channel('instagram')->info('✅ Perfil encontrado por instagram_scoped_id');
            return InstagramModelResolver::instagram_business_account()
                ->where('instagram_business_account_id', $profile->instagram_business_account_id)
                ->first();
        }

        $businessAccount = InstagramModelResolver::instagram_business_account()
            ->where('instagram_business_account_id', $businessId)
            ->first();

        if ($businessAccount) {
            Log::channel('instagram')->info('✅ Cuenta encontrada por ID largo - autocuración');
            $this->ensureBusinessProfile($businessAccount, $businessId);
            return $businessAccount;
        }

        return null;
    }

    protected function ensureBusinessProfile(Model $businessAccount, string $businessId): void
    {
        $profile = InstagramModelResolver::instagram_profile()
            ->where('instagram_business_account_id', $businessAccount->instagram_business_account_id)
            ->first();

        if (!$profile) {
            $igId = $this->fetchIgId($businessAccount);
            if (!$igId) $igId = $businessId;

            InstagramModelResolver::instagram_profile()->create([
                'instagram_business_account_id' => $businessAccount->instagram_business_account_id,
                'user_id'                      => $igId,
                'instagram_scoped_id'          => $igId,
            ]);
            Log::channel('instagram')->info('✅ Perfil de negocio creado automáticamente', ['ig_id' => $igId]);
        } elseif (empty($profile->user_id)) {
            $profile->update(['user_id' => $businessId]);
            Log::channel('instagram')->info('✅ user_id actualizado en perfil existente');
        }
    }

    protected function fetchIgId(Model $businessAccount): ?string
    {
        try {
            $this->withAccessToken($businessAccount->access_token);
            $response = $this->apiClient->request(
                'GET',
                $businessAccount->instagram_business_account_id,
                [],
                null,
                [
                    'fields' => 'ig_id',
                    'access_token' => $this->accessToken
                ]
            );
            return $response['ig_id'] ?? null;
        } catch (Exception $e) {
            Log::channel('instagram')->warning('No se pudo obtener ig_id', [
                'account' => $businessAccount->instagram_business_account_id,
                'error'   => $e->getMessage()
            ]);
            return null;
        }
    }

    // ------------------------------------------------------------------------
    // 3. Conversación
    // ------------------------------------------------------------------------
    public function findOrCreateConversation(string $instagramBusinessAccountId, string $instagramUserId): Model
    {
        $conversation = InstagramModelResolver::instagram_conversation()
            ->where('instagram_business_account_id', $instagramBusinessAccountId)
            ->where('instagram_user_id', $instagramUserId)
            ->whereNull('deleted_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return InstagramModelResolver::instagram_conversation()->create([
            'instagram_business_account_id' => $instagramBusinessAccountId,
            'instagram_user_id'            => $instagramUserId,
            'updated_time'                => now(),
            'unread_count'               => 0,
        ]);
    }

    // ------------------------------------------------------------------------
    // 4. Actualizar conversación
    // ------------------------------------------------------------------------
    protected function updateConversationStats(Model $conversation, bool $isEcho): void
    {
        $conversation->update([
            'last_message_at' => now(),
            'updated_time'    => now(),
            'unread_count'    => $isEcho ? $conversation->unread_count : $conversation->unread_count + 1
        ]);
        Log::channel('instagram')->info('✅ Conversación actualizada');
    }

    // ------------------------------------------------------------------------
    // 5. Manejar tipo de evento
    // ------------------------------------------------------------------------
    protected function handleEventByType(array $messageData, Model $conversation, string $contactUserId, Model $businessAccount, bool $isEcho): void
    {
        Log::channel('instagram')->info('📋 Determinando tipo de evento...');

        if (isset($messageData['message'])) {
            if ($isEcho) {
                Log::channel('instagram')->info('→ Mensaje ECO (ignorado)');
            } else {
                Log::channel('instagram')->info('→ Mensaje entrante');
                $this->processIncomingMessage($conversation, $messageData['message'], $contactUserId, $businessAccount->instagram_business_account_id);
            }
            return;
        }

        if (isset($messageData['postback'])) {
            Log::channel('instagram')->info('→ Postback');
            $this->processPostback($conversation, $messageData['postback'], $contactUserId, $businessAccount->instagram_business_account_id, $messageData['timestamp'] ?? null);
            return;
        }

        if (isset($messageData['reaction'])) {
            Log::channel('instagram')->info('→ Reacción');
            $this->processReaction($conversation, $messageData['reaction'], $contactUserId, $businessAccount->instagram_business_account_id);
            return;
        }

        if (isset($messageData['optin'])) {
            Log::channel('instagram')->info('→ Opt-in');
            $this->processOptin($conversation, $messageData['optin'], $contactUserId, $businessAccount->instagram_business_account_id);
            return;
        }

        if (isset($messageData['referral'])) {
            Log::channel('instagram')->info('→ Referral');
            $this->processReferral($conversation, $messageData['referral'], $contactUserId, $businessAccount->instagram_business_account_id);
            return;
        }

        if (isset($messageData['read'])) {
            Log::channel('instagram')->info('→ Evento de lectura');
            $this->processRead($conversation, $messageData['read'], $contactUserId, $businessAccount->instagram_business_account_id);
            return;
        }

        if (isset($messageData['message_edit'])) {
            Log::channel('instagram')->info('→ Edición de mensaje');
            $this->processMessageEdit($conversation, $messageData['message_edit'], $contactUserId, $businessAccount->instagram_business_account_id);
            return;
        }

        Log::channel('instagram')->warning('⚠️ Tipo de evento desconocido', $messageData);
    }

    // ------------------------------------------------------------------------
    // 6. Procesar mensaje entrante
    // ------------------------------------------------------------------------
    protected function processIncomingMessage(Model $conversation, array $message, string $senderId, string $recipientId): void
    {
        $messageId = $message['mid'] ?? uniqid();

        if (InstagramModelResolver::instagram_message()->where('message_id', $messageId)->exists()) {
            Log::channel('instagram')->info('⚠️ Mensaje duplicado ignorado', ['message_id' => $messageId]);
            return;
        }

        $messageType = $this->determineMessageType($message);

        $messageData = [
            'conversation_id' => $conversation->id,
            'message_id'      => $messageId,
            'message_method'  => 'incoming',
            'message_type'    => $messageType,
            'message_from'    => $senderId,
            'message_to'      => $recipientId,
            'message_content' => $message['text'] ?? null,
            'attachments'     => $message['attachments'] ?? null,
            'json_content'    => $message,
            'json'            => $message,
            'status'          => 'received',
            'created_time'    => now(),
            'sent_at'         => isset($message['timestamp']) ? date('Y-m-d H:i:s', $message['timestamp'] / 1000) : now()
        ];

        if (isset($message['quick_reply'])) {
            $messageData['message_type']        = 'quick_reply';
            $messageData['message_context']     = 'quick_reply_response';
            $messageData['message_context_id']  = $message['quick_reply']['payload'] ?? null;
            $messageData['quick_reply_payload'] = $message['quick_reply']['payload'] ?? null;
            $messageData['context_message_text'] = $message['text'] ?? null;
        }

        $savedMessage = InstagramModelResolver::instagram_message()->create($messageData);
        Log::channel('instagram')->info('✅ Mensaje guardado en BD', [
            'id'         => $savedMessage->id,
            'message_id' => $savedMessage->message_id,
            'type'       => $savedMessage->message_type,
            'from'       => $savedMessage->message_from
        ]);

        $this->processAttachments($message, $savedMessage);
    }

    protected function processAttachments(array $message, Model $savedMessage): void
    {
        if (!isset($message['attachments']) || !is_array($message['attachments'])) {
            return;
        }

        foreach ($message['attachments'] as $attachment) {
            if (isset($attachment['type'], $attachment['payload']['url'])) {
                $savedMessage->update(['media_url' => $attachment['payload']['url']]);
                Log::channel('instagram')->info('📎 Adjunto procesado', [
                    'type' => $attachment['type'],
                    'url'  => $attachment['payload']['url']
                ]);
                break;
            }
        }
    }

    protected function determineMessageType(array $message): string
    {
        if (isset($message['quick_reply'])) {
            return 'quick_reply';
        }
        if (isset($message['attachments'])) {
            $attachment = $message['attachments'][0] ?? [];
            return $attachment['type'] ?? 'text';
        }
        return 'text';
    }

    // ------------------------------------------------------------------------
    // 7. Contacto: obtener perfil y guardar (con reintento y refresco de token)
    // ------------------------------------------------------------------------
    protected function shouldUpdateContact(array $messageData): bool
    {
        return !isset($messageData['read'])
            && !isset($messageData['message_edit'])
            && !isset($messageData['reaction']);
    }

    protected function updateOrCreateContact(string $instagramUserId, Model $businessAccount): Model
    {
        $profileData = $this->fetchContactProfile($instagramUserId, $businessAccount);

        $data = [
            'instagram_business_account_id' => $businessAccount->instagram_business_account_id,
            'instagram_user_id'            => $instagramUserId,
            'last_interaction_at'         => now(),
        ];

        $synced = false;
        if (!empty($profileData)) {
            $synced = true;
            $data = array_merge($data, [
                'username'                => $profileData['username'] ?? null,
                'name'                   => $profileData['name'] ?? null,
                'profile_picture'        => $profileData['profile_pic'] ?? null,
                'follows_count'          => $profileData['follower_count'] ?? null,
                'is_verified'           => $profileData['is_verified_user'] ?? false,
                'is_user_follow_business' => $profileData['is_user_follow_business'] ?? false,
                'is_business_follow_user' => $profileData['is_business_follow_user'] ?? false,
                'profile_synced_at'      => now(),
            ]);
        } else {
            Log::channel('instagram')->warning('⚠️ Contacto guardado sin datos de perfil (sync falló)', [
                'user_id' => $instagramUserId,
                'business_account_id' => $businessAccount->instagram_business_account_id
            ]);
        }

        $contact = InstagramModelResolver::instagram_contact()->updateOrCreate(
            [
                'instagram_business_account_id' => $businessAccount->instagram_business_account_id,
                'instagram_user_id'            => $instagramUserId,
            ],
            $data
        );

        Log::channel('instagram')->info('✅ Contacto actualizado/creado', [
            'user_id' => $instagramUserId,
            'synced'  => $synced
        ]);

        return $contact;
    }

    /**
     * Obtiene perfil del contacto usando Instagram Basic Display API.
     * Si ocurre error 190 (token inválido), intenta refrescar el token y reintenta una vez.
     */
    protected function fetchContactProfile(string $instagramUserId, Model $businessAccount): array
    {
        $maxAttempts = 2;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $token = $businessAccount->access_token;
                if (empty($token)) {
                    Log::channel('instagram')->warning('🚫 Token vacío para la cuenta', [
                        'account_id' => $businessAccount->instagram_business_account_id
                    ]);
                    return [];
                }

                $baseUrl = config('instagram.api.graph_base_url', 'https://graph.instagram.com');
                $version = config('instagram.api.version', 'v19.0');

                $basicClient = new ApiClient(
                    $baseUrl,
                    $version,
                    (int) config('instagram.api.timeout', 30)
                );

                $fields = 'id,username,name,profile_pic,follower_count,is_verified_user,is_user_follow_business,is_business_follow_user';
                $query = [
                    'fields' => $fields,
                    'access_token' => $token,
                ];

                $fullUrl = "{$baseUrl}/{$version}/{$instagramUserId}?" . http_build_query($query);

                Log::channel('instagram')->info('🔍 FETCH CONTACT PROFILE - Detalles completos', [
                    'user_id' => $instagramUserId,
                    'business_account_id' => $businessAccount->instagram_business_account_id,
                    'base_url' => $baseUrl,
                    'version' => $version,
                    'fields' => $fields,
                    'token_preview' => substr($token, 0, 30) . '...',
                    'full_url' => $fullUrl,
                    'attempt' => $attempt
                ]);

                if (app()->environment('local')) {
                    Log::channel('instagram')->debug('🔐 Token completo (solo local)', ['access_token' => $token]);
                }

                $response = $basicClient->request(
                    'GET',
                    $instagramUserId,
                    [],
                    null,
                    $query
                );

                Log::channel('instagram')->info('📥 RESPUESTA CRUDA DE API', [
                    'response' => $response
                ]);

                if (is_array($response) && !isset($response['error'])) {
                    Log::channel('instagram')->info('✅ Perfil obtenido correctamente', [
                        'user_id' => $instagramUserId,
                        'username' => $response['username'] ?? null,
                        'name' => $response['name'] ?? null,
                    ]);
                    return $response;
                }

                // La API devolvió un error en el cuerpo de la respuesta
                $errorMsg = $response['error']['message'] ?? 'Error desconocido';
                $errorCode = $response['error']['code'] ?? 0;
                Log::channel('instagram')->error('❌ Error en respuesta de API (código ' . $errorCode . ')', [
                    'error' => $errorMsg,
                    'response' => $response
                ]);

                if ($errorCode == 190 && $attempt < $maxAttempts) {
                    Log::channel('instagram')->warning('🔄 Token inválido, intentando refrescar...', [
                        'business_account_id' => $businessAccount->instagram_business_account_id
                    ]);
                    $refreshed = $this->accountService->refreshAndStoreLongLivedToken($businessAccount);
                    if ($refreshed) {
                        Log::channel('instagram')->info('✅ Token refrescado exitosamente, reintentando obtención de perfil');
                        $businessAccount->refresh();
                        continue;
                    }
                    Log::channel('instagram')->error('❌ No se pudo refrescar el token');
                    return [];
                }

                return [];

            } catch (Exception $e) {
                $httpCode = null;
                $internalErrorCode = null;
                $errorMsg = $e->getMessage();

                // Obtener código HTTP si está disponible
                if (method_exists($e, 'getCode')) {
                    $httpCode = $e->getCode();
                }

                // Si es una ApiException, intentar obtener detalles
                if ($e instanceof ApiException) {
                    $details = $e->getDetails();
                    $internalErrorCode = $details['error']['code'] ?? null;
                    $errorMsg = $details['error']['message'] ?? $errorMsg;
                }

                // Si aún no tenemos código interno, intentar extraer de la respuesta si es una excepción de Guzzle
                if (!$internalErrorCode && $e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
                    try {
                        $responseBody = $e->getResponse()->getBody()->getContents();
                        $responseData = json_decode($responseBody, true);
                        $internalErrorCode = $responseData['error']['code'] ?? null;
                        $errorMsg = $responseData['error']['message'] ?? $errorMsg;
                    } catch (\Exception $ex) {
                        // Ignorar errores de lectura
                    }
                }

                // Fallback: si el mensaje contiene la frase característica, asumimos código 190
                if (!$internalErrorCode && str_contains($errorMsg, 'Error validating access token')) {
                    $internalErrorCode = 190;
                    Log::channel('instagram')->warning('⚠️ Detectado error de token por mensaje, asumiendo código 190');
                }

                Log::channel('instagram')->error('❌ Excepción en fetchContactProfile', [
                    'user_id' => $instagramUserId,
                    'exception_type' => get_class($e),
                    'http_code' => $httpCode,
                    'internal_code' => $internalErrorCode,
                    'error' => $errorMsg,
                    'attempt' => $attempt
                ]);

                // Reintentar si es error 190 y estamos en el primer intento
                if ($internalErrorCode == 190 && $attempt < $maxAttempts) {
                    Log::channel('instagram')->warning('🔄 Token inválido detectado, intentando refrescar...', [
                        'business_account_id' => $businessAccount->instagram_business_account_id
                    ]);
                    $refreshed = $this->accountService->refreshAndStoreLongLivedToken($businessAccount);
                    if ($refreshed) {
                        Log::channel('instagram')->info('✅ Token refrescado exitosamente, reintentando obtención de perfil');
                        $businessAccount->refresh();
                        continue;
                    }
                    Log::channel('instagram')->error('❌ No se pudo refrescar el token');
                    return [];
                }

                return [];
            }
        }

        return [];
    }

    // ------------------------------------------------------------------------
    // 8. Procesadores de eventos específicos (sin cambios)
    // ------------------------------------------------------------------------
    protected function processPostback(Model $conversation, array $postback, string $senderId, string $recipientId, $timestamp = null): void
    {
        $messageId = $postback['mid'] ?? 'postback_' . uniqid();
        if (InstagramModelResolver::instagram_message()->where('message_id', $messageId)->exists()) {
            Log::info('Postback duplicado ignorado', ['message_id' => $messageId]);
            return;
        }

        $messageData = [
            'conversation_id' => $conversation->id,
            'message_id'      => $messageId,
            'message_method'  => 'incoming',
            'message_type'    => 'postback',
            'message_from'    => $senderId,
            'message_to'      => $recipientId,
            'message_content' => $postback['title'] ?? $postback['payload'] ?? null,
            'message_context' => 'button_postback',
            'message_context_id' => $postback['payload'] ?? null,
            'postback_payload'   => $postback['payload'] ?? null,
            'context_message_text' => $postback['title'] ?? null,
            'json_content'    => $postback,
            'status'          => 'received',
            'created_time'    => now(),
            'sent_at'         => $timestamp ? date('Y-m-d H:i:s', $timestamp / 1000) : now()
        ];

        InstagramModelResolver::instagram_message()->create($messageData);
        Log::info('Instagram postback processed', ['conversation_id' => $conversation->id, 'postback' => $postback]);
    }

    protected function processReaction(Model $conversation, array $reaction, string $senderId, string $recipientId): void
    {
        $reactedMessage = InstagramModelResolver::instagram_message()
            ->where('message_id', $reaction['mid'] ?? '')
            ->first();

        if ($reactedMessage) {
            $currentReactions = $reactedMessage->reactions ?? [];
            $currentReactions[] = [
                'user_id'   => $senderId,
                'reaction'  => $reaction['reaction'] ?? 'like',
                'emoji'     => $reaction['emoji'] ?? '❤️',
                'action'    => $reaction['action'] ?? 'react',
                'timestamp' => now()
            ];
            $reactedMessage->update(['reactions' => $currentReactions]);
        }

        Log::info('Instagram reaction processed', ['conversation_id' => $conversation->id, 'reaction' => $reaction]);
    }

    protected function processOptin(Model $conversation, array $optin, string $senderId, string $recipientId): void
    {
        Log::info('Instagram optin processed', ['conversation_id' => $conversation->id, 'optin' => $optin]);
    }

    protected function processReferral(Model $conversation, array $referral, string $senderId, string $recipientId): void
    {
        Log::info('Instagram referral processed', ['conversation_id' => $conversation->id, 'referral' => $referral]);
        if (isset($referral['source']) && $referral['source'] === 'SHORTLINKS') {
            $this->processIgMeReferral($conversation, $referral, $senderId, $recipientId);
        }
    }

    protected function processIgMeReferral(Model $conversation, array $referral, string $senderId, string $recipientId): void
    {
        try {
            $ref = $referral['ref'] ?? null;
            $source = $referral['source'] ?? null;
            $type = $referral['type'] ?? null;

            $conversation->update([
                'last_referral'      => $ref,
                'referral_source'    => $source,
                'referral_type'      => $type,
                'referral_timestamp' => now()
            ]);

            InstagramModelResolver::instagram_referral()->create([
                'conversation_id'                 => $conversation->id,
                'instagram_user_id'               => $senderId,
                'instagram_business_account_id'   => $recipientId,
                'ref_parameter'                  => $ref,
                'source'                         => $source,
                'type'                           => $type,
                'processed_at'                   => now()
            ]);

            Log::info('ig.me referral processed', [
                'conversation_id' => $conversation->id,
                'ref' => $ref,
                'source' => $source,
                'type' => $type
            ]);
        } catch (Exception $e) {
            Log::error('Error processing ig.me referral:', ['error' => $e->getMessage(), 'referral' => $referral]);
        }
    }

    protected function processRead(Model $conversation, array $read, string $senderId, string $recipientId): void
    {
        if (isset($read['watermark'])) {
            InstagramModelResolver::instagram_message()
                ->where('conversation_id', $conversation->id)
                ->where('created_time', '<=', date('Y-m-d H:i:s', $read['watermark'] / 1000))
                ->where('status', 'sent')
                ->update(['status' => 'read', 'read_at' => now()]);
        }
        if (isset($read['mid'])) {
            InstagramModelResolver::instagram_message()
                ->where('message_id', $read['mid'])
                ->update(['status' => 'read', 'read_at' => now()]);
        }
        Log::info('Instagram read receipt processed', ['conversation_id' => $conversation->id, 'read' => $read]);
    }

    protected function processMessageEdit(Model $conversation, array $messageEdit, string $senderId, string $recipientId): void
    {
        $mid = $messageEdit['mid'] ?? null;
        if (!$mid) {
            Log::warning('Edición de mensaje sin ID (mid)', $messageEdit);
            return;
        }
        $message = InstagramModelResolver::instagram_message()->where('message_id', $mid)->first();
        if ($message) {
            $message->update(['is_edited' => true, 'edited_at' => now()]);
            Log::channel('instagram')->info('✅ Mensaje marcado como editado en BD');
        } else {
            Log::channel('instagram')->warning('⚠️ Mensaje original no encontrado al procesar edición');
        }
    }

    // ------------------------------------------------------------------------
    // 9. Métodos para enviar mensajes (sin cambios)
    // ------------------------------------------------------------------------
    public function markAsRead(string $messageId): bool
    {
        try {
            $message = InstagramModelResolver::instagram_message()->where('message_id', $messageId)->first();
            if ($message) {
                $message->update(['status' => 'read', 'read_at' => now()]);
                return true;
            }
            return false;
        } catch (Exception $e) {
            Log::error('Error marking message as read:', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function sendMessageGeneric(string $recipientId, array $payload, string $messageType, ?string $conversationId = null, ?string $mediaUrl = null, ?string $postId = null): ?array
    {
        $this->validateCredentials();
        $conversation = $conversationId
            ? InstagramModelResolver::instagram_conversation()->find($conversationId)
            : $this->findOrCreateConversation($this->instagramUserId, $recipientId);

        $messageData = [
            'conversation_id' => $conversation->id,
            'message_id'      => 'temp_' . uniqid(),
            'message_method'  => 'outgoing',
            'message_type'    => $messageType,
            'message_from'    => $this->instagramUserId,
            'message_to'      => $recipientId,
            'json_content'    => $payload,
            'json'            => $payload,
            'status'          => 'pending',
            'created_time'    => now(),
        ];

        if ($messageType === 'text') {
            $messageData['message_content'] = $payload['message']['text'];
        } elseif (in_array($messageType, ['image', 'audio', 'video'])) {
            $messageData['media_url'] = $mediaUrl;
            $messageData['message_content'] = $payload['message']['attachment']['type'];
        } elseif ($messageType === 'sticker') {
            $messageData['message_content'] = 'sticker';
        } elseif ($messageType === 'post') {
            $messageData['message_context_id'] = $postId;
            $messageData['message_content'] = 'shared_post';
        } elseif ($messageType === 'reaction') {
            $messageData['message_content'] = $payload['payload']['reaction'] ?? 'reaction';
        } elseif ($messageType === 'quick_reply') {
            $messageData['message_content'] = $payload['message']['text'];
            $messageData['json_content'] = ['quick_replies' => $payload['message']['quick_replies']];
        } elseif ($messageType === 'generic_template') {
            $messageData['message_content'] = 'Generic Template';
            $messageData['json_content'] = ['elements' => $payload['message']['attachment']['payload']['elements']];
        } elseif ($messageType === 'button_template') {
            $messageData['message_content'] = $payload['message']['attachment']['payload']['text'] ?? null;
            $messageData['json_content'] = ['buttons' => $payload['message']['attachment']['payload']['buttons'] ?? []];
        }

        $message = InstagramModelResolver::instagram_message()->create($messageData);

        try {
            $response = $this->apiClient->request(
                'POST',
                $this->instagramUserId . '/messages',
                [],
                $payload,
                ['access_token' => $this->accessToken]
            );

            $message->update([
                'message_id'   => $response['message_id'] ?? $response['id'] ?? uniqid(),
                'status'       => 'sent',
                'sent_at'      => now(),
                'json_content' => $response
            ]);

            $conversation->update(['last_message_at' => now(), 'updated_time' => now()]);

            return $response;
        } catch (Exception $e) {
            $message->update([
                'status'        => 'failed',
                'failed_at'     => now(),
                'message_error' => $e->getMessage()
            ]);
            Log::error("Error enviando mensaje de {$messageType}:", ['error' => $e->getMessage(), 'recipient_id' => $recipientId]);
            return null;
        }
    }

    public function sendTextMessage(string $recipientId, string $text, ?string $conversationId = null): ?array
    {
        return $this->sendMessageGeneric($recipientId, ['recipient' => ['id' => $recipientId], 'message' => ['text' => $text]], 'text', $conversationId);
    }

    public function sendImageMessage(string $recipientId, string $imageUrl, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'image', 'payload' => ['url' => $imageUrl]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'image', $conversationId, $imageUrl);
    }

    public function sendAudioMessage(string $recipientId, string $audioUrl, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'audio', 'payload' => ['url' => $audioUrl]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'audio', $conversationId, $audioUrl);
    }

    public function sendVideoMessage(string $recipientId, string $videoUrl, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'video', 'payload' => ['url' => $videoUrl]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'video', $conversationId, $videoUrl);
    }

    public function sendStickerMessage(string $recipientId, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'like_heart']]];
        return $this->sendMessageGeneric($recipientId, $payload, 'sticker', $conversationId);
    }

    public function sendGenericTemplate(string $recipientId, array $elements, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'generic', 'elements' => $elements]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'generic_template', $conversationId);
    }

    public function sendButtonTemplate(string $recipientId, string $text, array $buttons, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => $text, 'buttons' => $buttons]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'button_template', $conversationId);
    }

    public function sendSharedPost(string $recipientId, string $postId, ?string $conversationId = null): ?array
    {
        $payload = ['recipient' => ['id' => $recipientId], 'message' => ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'media', 'elements' => [['media_type' => 'image', 'url' => 'https://www.facebook.com/' . $postId]]]]]];
        return $this->sendMessageGeneric($recipientId, $payload, 'post', $conversationId, null, $postId);
    }

    public function sendReadReceipt(string $recipientId): ?array
    {
        $this->validateCredentials();
        try {
            return $this->apiClient->request(
                'POST',
                $this->instagramUserId . '/messages',
                [],
                ['recipient' => ['id' => $recipientId], 'sender_action' => 'mark_seen'],
                ['access_token' => $this->accessToken]
            );
        } catch (Exception $e) {
            Log::error('Error sending read receipt:', ['error' => $e->getMessage(), 'recipient_id' => $recipientId]);
            return null;
        }
    }
}