<?php

namespace App\Http\Controllers\Social;

use App\Exceptions\Social\MetaGraphException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\StoreSocialAccountRequest;
use App\Models\MediaAuditLog;
use App\Models\SocialAccount;
use App\Services\Media\MediaAuditLogger;
use App\Services\Social\MetaGraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration of the stored network credentials.
 *
 * ADMIN ONLY, AND THE ROUTE FILE ENFORCES IT. A Page access token publishes,
 * deletes and reads the inbox of the page; there is no read-only tier being
 * stored here. This is the same classification the media module gives the Drive
 * credentials, for the same reason.
 *
 * NO ENDPOINT OF THIS CONTROLLER EVER RETURNS A TOKEN. The model hides the
 * column and the browser is shown only `has_access_token` — enough to recognize
 * which connection is loaded, useless for posting as the business.
 */
class SocialAccountController extends Controller
{
    public function __construct(
        private readonly MetaGraphService $meta,
        private readonly MediaAuditLogger $audit,
    ) {}

    /** Every stored connection, one per network at most in practice. */
    public function index(): JsonResponse
    {
        $accounts = SocialAccount::query()
            ->with('updatedByUser:id,name')
            ->orderBy('provider')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $accounts,
            'metadata' => [
                'providers' => collect(SocialAccount::PROVIDERS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'graph_version' => config('social.meta.version'),
            ],
        ]);
    }

    /**
     * Creates or updates the connection of one network.
     *
     * ONE LIVE CONNECTION PER NETWORK. Saving replaces the active row for that
     * provider instead of accumulating rows, because `activeFor()` has to
     * resolve to a single answer and "the most recently updated one" is a rule
     * an operator cannot see or reason about.
     *
     * An omitted token keeps the stored one. That is what makes it possible to
     * fix a mistyped page id without pasting a 200-character secret again —
     * and, since the browser never received the old value, the only alternative
     * would be to force a rotation on every edit.
     */
    public function store(StoreSocialAccountRequest $request): JsonResponse
    {
        $provider = $request->string('provider')->toString();
        $account = SocialAccount::activeFor($provider) ?? new SocialAccount(['provider' => $provider]);

        $account->fill($request->safe()->except('access_token'));

        if (filled($request->input('access_token'))) {
            $account->access_token = $request->string('access_token')->toString();
        }

        $account->provider = $provider;
        $account->is_active = $request->boolean('is_active', true);
        $account->updated_by = $request->user()->id;
        $account->save();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_UPDATED,
            'Conexión de '.$account->provider_label,
            [
                'social_account_id' => $account->id,
                'provider' => $account->provider,
                // The token itself is never written to the trail — only whether
                // one is present. An audit row that leaks the secret it audits
                // defeats the encryption in the column it came from.
                'token_rotated' => filled($request->input('access_token')),
                'is_active' => $account->is_active,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $account->fresh('updatedByUser'),
            'metadata' => ['message' => 'Conexión guardada.'],
        ]);
    }

    /**
     * Asks Meta who the stored token actually is.
     *
     * Run against the SAVED credential and not against a payload, so what is
     * tested is exactly what will publish. An administrator learns that a token
     * is dead, or points at the wrong page, at the moment they configure it —
     * not hours later from a failed publication whose only trace is a 190.
     */
    public function test(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        if ($socialAccount->provider === SocialAccount::PROVIDER_WHATSAPP) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_SOCIAL_TEST_UNAVAILABLE',
                'message' => 'El canal de WhatsApp aún es una simulación: no hay conexión que probar.',
            ], 422);
        }

        try {
            $node = $this->meta->describeNode($socialAccount);
        } catch (MetaGraphException $e) {
            $socialAccount->forceFill([
                'last_tested_at' => now(),
                'last_test_status' => 'failed',
                'last_test_message' => $e->getMessage(),
            ])->save();

            return response()->json([
                'status' => 'error',
                'code' => MetaGraphException::ERROR_CODE,
                'message' => $e->getMessage(),
                'metadata' => ['graph_code' => $e->graphCode],
            ], 422);
        }

        $identity = $node['username'] ?? $node['name'] ?? $node['id'] ?? 'desconocida';

        $socialAccount->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => 'success',
            'last_test_message' => 'Conectado como '.$identity,
        ])->save();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_TESTED,
            'Conexión de '.$socialAccount->provider_label,
            ['social_account_id' => $socialAccount->id, 'identity' => $identity],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $socialAccount->fresh(),
            'metadata' => ['message' => 'Conexión verificada como '.$identity.'.'],
        ]);
    }

    /**
     * Disables a connection without destroying it.
     *
     * The row survives so the publications that reference it keep a name; what
     * is destroyed is the token, because a disabled connection that still holds
     * a live secret is a secret nobody is watching.
     */
    public function destroy(Request $request, SocialAccount $socialAccount): JsonResponse
    {
        $socialAccount->forceFill([
            'access_token' => null,
            'is_active' => false,
            'updated_by' => $request->user()->id,
        ])->save();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_CREDENTIALS_UPDATED,
            'Conexión de '.$socialAccount->provider_label,
            ['social_account_id' => $socialAccount->id, 'disabled' => true],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $socialAccount->fresh(),
            'metadata' => ['message' => 'Conexión desactivada y token eliminado.'],
        ]);
    }
}
