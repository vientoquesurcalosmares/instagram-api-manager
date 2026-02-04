<?php

namespace ScriptDevelop\InstagramApiManager\Services;

use ScriptDevelop\InstagramApiManager\InstagramApi\ApiClient;
use ScriptDevelop\InstagramApiManager\Support\InstagramModelResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FacebookAccountService
{
    protected ApiClient $apiClient;

    public function __construct()
    {
        $this->apiClient = new ApiClient(
            config('facebook.api.base_url'),
            config('facebook.api.version'),
            (int) config('facebook.api.timeout', 30)
        );
    }

    public function getAuthorizationUrl(array $scopes = ['pages_show_list', 'pages_read_engagement', 'pages_messaging'], ?string $state = null): string
    {
        $clientId = config('facebook.meta_auth.client_id');
        $redirectUri = config('facebook.meta_auth.redirect_uri') ?: route('facebook.auth.callback');
        $state = $state ?? bin2hex(random_bytes(20));

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $scopes),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return "https://www.facebook.com/v19.0/dialog/oauth?" . $params;
    }

    public function handleCallback(string $code): bool
    {
        DB::beginTransaction();

        try {
            // Obtener access token de usuario
            $tokenResponse = $this->apiClient->request(
                'GET',
                'oauth/access_token',
                [],
                null,
                [
                    'client_id' => config('facebook.meta_auth.client_id'),
                    'client_secret' => config('facebook.meta_auth.client_secret'),
                    'redirect_uri' => config('facebook.meta_auth.redirect_uri') ?: route('facebook.auth.callback'),
                    'code' => $code,
                ]
            );

            if (!isset($tokenResponse['access_token'])) {
                Log::error('Facebook OAuth: Falta access_token', ['response' => $tokenResponse]);
                DB::rollBack();
                return false;
            }

            $accessToken = $tokenResponse['access_token'];

            // Obtener páginas del usuario
            $pagesResponse = $this->apiClient->request(
                'GET',
                'me/accounts',
                [],
                null,
                [
                    'fields' => 'id,name,access_token,tasks,instagram_business_account',
                    'access_token' => $accessToken
                ]
            );

            if (empty($pagesResponse['data'])) {
                Log::warning('No se obtuvieron páginas de Facebook');
                DB::rollBack();
                return false;
            }

            foreach ($pagesResponse['data'] as $page) {
                InstagramModelResolver::facebook_page()->updateOrCreate(
                    ['page_id' => $page['id']],
                    [
                        'name' => $page['name'] ?? '',
                        'access_token' => $page['access_token'] ?? '',
                        'tasks' => $page['tasks'] ?? [],
                        'instagram_business_account' => $page['instagram_business_account']['id'] ?? null,
                    ]
                );
            }

            DB::commit();
            return true;

        } catch (Exception $e) {
            Log::error('Error en OAuth Facebook:', ['error' => $e->getMessage()]);
            DB::rollBack();
            return false;
        }
    }
}