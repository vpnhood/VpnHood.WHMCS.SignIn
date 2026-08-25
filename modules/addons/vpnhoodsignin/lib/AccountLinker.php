<?php

namespace WHMCS\Module\Addon\VpnHoodSignIn;

use WHMCS\Database\Capsule;

require_once __DIR__ . '/GoogleToken.php';
require_once __DIR__ . '/SignInGate.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Turns a verified Google identity into a signed-in WHMCS session.
 *
 * Three cases, decided from OUR OWN data rather than from anything the browser
 * claims:
 *
 *   already linked  -> sign in
 *   email known     -> link it (notify the owner), then sign in
 *   email unknown   -> create the client, link it, then sign in
 *
 * The account link is stored in WHMCS's own `tblauthn_account_links`, keyed by
 * the Google `sub`. That table is reused rather than replaced on purpose: it is
 * where WHMCS itself records these links, its (provider, remote_user_id) unique
 * key is exactly the constraint we need, and keeping the data there means
 * enabling WHMCS's built-in Sign-In Integration later would find every link
 * already in place. This module keeps no private copy of it.
 *
 * The one hard refusal: a client with WHMCS two-factor authentication enabled
 * is never signed in this way. Signing in through CreateSsoToken lands straight
 * in the client area, which would silently cancel a second factor the client
 * deliberately turned on — so those accounts are told to use the password form.
 */
class AccountLinker
{
    public const STATUS_LOGGED_IN  = 'logged_in';
    public const STATUS_TWO_FACTOR = 'two_factor';
    public const STATUS_REFUSED    = 'refused';

    /**
     * @param array{subject:string, email:string, firstName:string, lastName:string, claims:array} $identity
     * @return array{status:string, redirectUrl?:string, message?:string, clientId?:int, created?:bool}
     */
    public function signIn(array $identity): array
    {
        $subject = $identity['subject'];
        $email = $identity['email'];

        $linkedUserId = $this->linkedUserId($subject);
        if ($linkedUserId > 0) {
            $clientId = $this->clientIdForUser($linkedUserId);
            if ($clientId <= 0) {
                // The link points at a user who owns no client. Nothing sane to
                // sign in to; leave it alone rather than guess.
                SignInGate::log('signIn.orphanLink', ['sub' => $subject, 'userId' => $linkedUserId], 'no owned client');

                return ['status' => self::STATUS_REFUSED, 'message' => $this->genericRefusal()];
            }

            return $this->completeSignIn($clientId, $linkedUserId, false);
        }

        $existingUserId = $this->userIdForEmail($email);
        if ($existingUserId > 0) {
            return $this->linkExisting($existingUserId, $identity);
        }

        return $this->createAndLink($identity);
    }

    // ------------------------------------------------------------- existing

    /**
     * Attach the Google identity to an account that already uses this address.
     *
     * Safe because the token carried a Google-verified email: whoever signed in
     * demonstrably controls the mailbox, which is the same proof a password
     * reset would have required. The owner is told either way when the setting
     * asks for it, so a link they did not expect is visible rather than silent.
     *
     * @param array{subject:string, email:string, firstName:string, lastName:string, claims:array} $identity
     * @return array{status:string, redirectUrl?:string, message?:string, clientId?:int, created?:bool}
     */
    private function linkExisting(int $userId, array $identity): array
    {
        $action = SignInGate::settings()['existingAction'];
        if ($action === SignInGate::EXISTING_REFUSE) {
            return [
                'status'  => self::STATUS_REFUSED,
                'message' => 'An account already uses this email address. Please sign in with your password.',
            ];
        }

        $clientId = $this->clientIdForUser($userId);
        if ($clientId <= 0) {
            SignInGate::log('linkExisting.noClient', ['userId' => $userId], 'user owns no client');

            return ['status' => self::STATUS_REFUSED, 'message' => $this->genericRefusal()];
        }

        if (!$this->clientIsActive($clientId)) {
            return [
                'status'  => self::STATUS_REFUSED,
                'message' => 'This account is closed. Please contact support.',
            ];
        }

        $this->storeLink($identity, $userId);

        if ($action === SignInGate::EXISTING_LINK_NOTIFY) {
            $this->notifyLinked($clientId, $identity['email']);
        }
        $this->logActivity($clientId, 'VpnHood Sign-In: Google account linked to client #' . $clientId);

        return $this->completeSignIn($clientId, $userId, false);
    }

    // ------------------------------------------------------------ brand new

    /**
     * Create the WHMCS client for an address WHMCS has never seen.
     *
     * `skipvalidation` waives the core required fields — this install already
     * makes address/city/state/postcode/phone optional, and Google supplies
     * none of them. It does NOT waive email or password2, which is why a
     * password is generated below. `noemail` is left off so the client still
     * gets the normal welcome mail — it would suppress that too, and the welcome
     * mail is the one piece of post-signup mail that IS wanted.
     *
     * The "Email Address Verification" mail is dropped instead, by the window
     * opened around this one call — which the welcome mail also falls inside,
     * and survives only because the hook tests the message name. See
     * SignInGate::suppressVerificationEmail() and the EmailPreSend hook.
     *
     * @param array{subject:string, email:string, firstName:string, lastName:string, claims:array} $identity
     * @return array{status:string, redirectUrl?:string, message?:string, clientId?:int, created?:bool}
     */
    private function createAndLink(array $identity): array
    {
        $params = [
            'firstname'      => $identity['firstName'],
            'lastname'       => $identity['lastName'],
            'email'          => $identity['email'],
            // WHMCS requires a password even for an identity-provider signup, and
            // skipvalidation explicitly does not waive it. Native WHMCS does the
            // same thing in the browser (prefillPassword -> simpleRNG); doing it
            // here with a CSPRNG is the same behaviour with better entropy. It is
            // never returned, mailed or displayed, so the only ways in remain
            // Google and password-reset — and reset proves the same mailbox
            // ownership Google just proved.
            'password2'      => bin2hex(random_bytes(16)),
            'country'        => SignInGate::resolveCountry(),
            'skipvalidation' => true,
        ];

        $ip = SignInGate::clientIp();
        if ($ip !== '') {
            $params['clientip'] = $ip;
        }

        $settings = SignInGate::settings();
        if ($settings['fieldMode'] === SignInGate::MODE_DEFAULT_VALUE && SignInGate::defaultValueIsValid()) {
            $fieldId = SignInGate::customFieldId();
            if ($fieldId > 0) {
                $params['customfields'] = base64_encode(serialize([$fieldId => $settings['fieldDefault']]));
            }
        }

        // WHMCS mails the verification link from inside AddClient. Google has
        // already proved this mailbox — verify() refuses a token without
        // email_verified — so that mail asks the client to confirm something
        // already confirmed, and its link is spent before it arrives.
        SignInGate::suppressVerificationEmail(true);
        try {
            $result = localAPI('AddClient', $params);
        } finally {
            SignInGate::suppressVerificationEmail(false);
        }
        if (($result['result'] ?? '') !== 'success' || (int) ($result['clientid'] ?? 0) <= 0) {
            SignInGate::log('createClient.failed', ['email' => $identity['email']], $result);

            return [
                'status'  => self::STATUS_REFUSED,
                'message' => 'We could not create your account. Please register with the form below.',
            ];
        }

        $clientId = (int) $result['clientid'];
        $userId = $this->userIdForEmail($identity['email']);

        // Google already proved mailbox ownership, so WHMCS should not treat the
        // address as unconfirmed. Best-effort, but it matters more than it looks:
        // the verification mail has just been suppressed, so if this ALSO fails
        // the client is left unverified with no link — and vpnhoodverify, where
        // that addon is installed, would then hold them out of the client area.
        // A failure here is logged loudly for exactly that reason.
        try {
            \WHMCS\User\User::where('email', $identity['email'])->first()?->setEmailVerificationCompleted();
        } catch (\Throwable $e) {
            SignInGate::log('markVerified.failed', ['email' => $identity['email']], $e->getMessage());
        }

        if ($userId > 0) {
            $this->storeLink($identity, $userId);
        }
        $this->logActivity($clientId, 'VpnHood Sign-In: client #' . $clientId . ' created from Google sign-in');

        return $this->completeSignIn($clientId, $userId, true);
    }

    // --------------------------------------------------------------- log in

    /**
     * Mint the session, unless the account is protected by a second factor.
     *
     * CreateSsoToken puts the visitor straight into the client area. For an
     * account with WHMCS 2FA enrolled that would quietly defeat the second
     * factor, so those accounts are refused here and sent to the password form,
     * where WHMCS runs its own challenge.
     *
     * @return array{status:string, redirectUrl?:string, message?:string, clientId?:int, created?:bool}
     */
    private function completeSignIn(int $clientId, int $userId, bool $created): array
    {
        if ($userId > 0 && $this->userHasTwoFactor($userId)) {
            return [
                'status'   => self::STATUS_TWO_FACTOR,
                'clientId' => $clientId,
                'created'  => $created,
                'message'  => 'Your account uses two-step verification. Please sign in with your email and password.',
            ];
        }

        $result = localAPI('CreateSsoToken', [
            'client_id'         => $clientId,
            'destination'       => 'sso:custom_redirect',
            'sso_redirect_path' => '/clientarea.php',
        ]);

        if (($result['result'] ?? '') !== 'success' || ($result['redirect_url'] ?? '') === '') {
            SignInGate::log('createSsoToken.failed', ['clientId' => $clientId], $result);

            return [
                'status'   => self::STATUS_REFUSED,
                'clientId' => $clientId,
                'created'  => $created,
                'message'  => $created
                    ? 'Your account was created, but we could not sign you in automatically. Please use "Forgot Password" to set one.'
                    : $this->genericRefusal(),
            ];
        }

        return [
            'status'      => self::STATUS_LOGGED_IN,
            'redirectUrl' => (string) $result['redirect_url'],
            'clientId'    => $clientId,
            'created'     => $created,
        ];
    }

    // -------------------------------------------------------------- storage

    /** The user id linked to this Google subject, or 0. */
    private function linkedUserId(string $subject): int
    {
        try {
            return (int) (Capsule::table('tblauthn_account_links')
                ->where('provider', GoogleToken::PROVIDER)
                ->where('remote_user_id', $subject)
                ->value('user_id') ?? 0);
        } catch (\Throwable $e) {
            SignInGate::log('linkedUserId.failed', ['sub' => $subject], $e->getMessage());

            return 0;
        }
    }

    /**
     * Record the link, storing the claims as metadata exactly as WHMCS does.
     *
     * Prefers WHMCS's own model so any future column or behaviour comes along
     * for free; falls back to a plain insert when the class is unavailable. The
     * (provider, remote_user_id) unique key makes a concurrent duplicate throw
     * rather than fork the identity, and that is treated as success — somebody
     * else just wrote the row we wanted.
     *
     * @param array{subject:string, claims:array} $identity
     */
    private function storeLink(array $identity, int $userId): void
    {
        $row = [
            'provider'       => GoogleToken::PROVIDER,
            'remote_user_id' => $identity['subject'],
            'user_id'        => $userId,
            'metadata'       => json_encode($identity['claims'], JSON_UNESCAPED_SLASHES),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ];

        try {
            if (class_exists('\WHMCS\Authentication\Remote\AccountLink')) {
                // Attributes are assigned one at a time rather than through
                // ::create(): the core model declares no $fillable, so mass
                // assignment throws "Add [provider] to fillable property". This
                // form still goes through the model (timestamps, events, any
                // future column behaviour) without tripping that guard.
                $link = new \WHMCS\Authentication\Remote\AccountLink();
                foreach ($row as $column => $value) {
                    $link->{$column} = $value;
                }
                $link->save();

                return;
            }
        } catch (\Throwable $e) {
            SignInGate::log('storeLink.modelFailed', $identity['subject'], $e->getMessage());
        }

        try {
            Capsule::table('tblauthn_account_links')->insert($row);
        } catch (\Throwable $e) {
            if ($this->linkedUserId($identity['subject']) > 0) {
                return; // a concurrent request won the unique key; nothing to do
            }
            SignInGate::log('storeLink.failed', $identity['subject'], $e->getMessage());
        }
    }

    private function userIdForEmail(string $email): int
    {
        try {
            return (int) (Capsule::table('tblusers')->whereRaw('LOWER(email) = ?', [strtolower($email)])->value('id') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** The client this user owns. Owner rows win over shared-access rows. */
    private function clientIdForUser(int $userId): int
    {
        try {
            return (int) (Capsule::table('tblusers_clients')
                ->where('auth_user_id', $userId)
                ->orderByDesc('owner')
                ->orderBy('id')
                ->value('client_id') ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function clientIsActive(int $clientId): bool
    {
        try {
            return (string) Capsule::table('tblclients')->where('id', $clientId)->value('status') === 'Active';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function userHasTwoFactor(int $userId): bool
    {
        try {
            return trim((string) Capsule::table('tblusers')->where('id', $userId)->value('second_factor')) !== '';
        } catch (\Throwable $e) {
            // Unreadable state must not silently downgrade someone's security.
            return true;
        }
    }

    // --------------------------------------------------------- notification

    private function notifyLinked(int $clientId, string $email): void
    {
        try {
            localAPI('SendEmail', [
                'messagename'   => '',
                'id'            => $clientId,
                'customtype'    => 'general',
                'customsubject' => 'A Google account was linked to your account',
                'custommessage' => '<p>Hi {$client_name},</p>'
                    . '<p>The Google account <strong>' . htmlspecialchars($email, ENT_QUOTES) . '</strong> '
                    . 'was just linked to your {$company_name} account, and can now be used to sign in.</p>'
                    . '<p>If this was you, there is nothing to do. If it was not, please '
                    . 'change your password and contact our support team straight away.</p>',
            ]);
        } catch (\Throwable $e) {
            // A notification that cannot be sent must never fail the sign-in.
            SignInGate::log('notifyLinked.failed', ['clientId' => $clientId], $e->getMessage());
        }
    }

    private function logActivity(int $clientId, string $description): void
    {
        try {
            localAPI('LogActivity', ['description' => $description, 'clientid' => $clientId]);
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /**
     * Deliberately vague: a stranger probing this endpoint must not learn
     * whether an address is registered, closed, or merely misconfigured.
     */
    private function genericRefusal(): string
    {
        return 'We could not sign you in with Google. Please sign in with your email and password.';
    }
}
