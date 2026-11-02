<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Passport\ClientRepository;

final class OAuthClientController extends Controller
{
    public function metadata(): JsonResponse
    {
        $issuer = rtrim(config('app.url'), '/');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['api'],
        ]);
    }

    public function register(Request $request, ClientRepository $clients): JsonResponse
    {
        $validator = Validator::make($request->json()->all(), [
            'client_name' => ['sometimes', 'string', 'max:80'],
            'redirect_uris' => ['required', 'array', 'list', 'min:1', 'max:5'],
            'redirect_uris.*' => ['required', 'string', 'max:2048', 'distinct', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! $this->validRedirect($value)) {
                    $fail('The redirect URI is not supported.');
                }
            }],
            'token_endpoint_auth_method' => ['required', Rule::in(['none'])],
            'grant_types' => ['sometimes', 'array', 'list', 'min:1', 'max:2'],
            'grant_types.*' => ['string', 'distinct', Rule::in(['authorization_code', 'refresh_token'])],
            'response_types' => ['sometimes', 'array', 'list', 'size:1'],
            'response_types.*' => [Rule::in(['code'])],
            'scope' => ['sometimes', 'nullable', Rule::in(['', 'api'])],
        ]);
        if ($validator->fails()) {
            $redirectError = collect($validator->errors()->keys())->contains(fn ($key) => str_starts_with($key, 'redirect_uris'));

            return response()->json([
                'error' => $redirectError ? 'invalid_redirect_uri' : 'invalid_client_metadata',
                'error_description' => $redirectError ? 'A valid redirect URI is required.' : 'Only public Authorization Code clients are supported.',
            ], 400);
        }
        $values = $validator->validated();
        $grants = $values['grant_types'] ?? ['authorization_code'];
        if (! in_array('authorization_code', $grants, true)) {
            return response()->json(['error' => 'invalid_client_metadata', 'error_description' => 'The authorization_code grant is required.'], 400);
        }
        $client = $clients->createAuthorizationCodeGrantClient($values['client_name'] ?? 'OpenBook client', $values['redirect_uris'], confidential: false);

        return response()->json([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at->timestamp,
            'client_name' => $client->name,
            'redirect_uris' => $client->redirect_uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'scope' => 'api',
        ], 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    private function validRedirect(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme === 'https') {
            return ! empty($parts['host']);
        }
        if ($scheme === 'http') {
            return in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', 'localhost'], true);
        }

        // Native callbacks use a private scheme based on a reversed domain name.
        return (bool) preg_match('/^[a-z][a-z0-9+.-]*\.[a-z0-9+.-]+$/D', $scheme)
            && (! empty($parts['path']) || ! empty($parts['host']));
    }
}
