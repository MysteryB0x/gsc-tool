<?php

namespace App\Google;

use App\Google\Contracts\CredentialProvider;
use App\Models\Property;

/** Picks the credential provider: the app's service account, or (later) the property's OAuth login. */
class Credentials
{
    public function __construct(protected ServiceAccountProvider $serviceAccount) {}

    public function app(): ServiceAccountProvider
    {
        return $this->serviceAccount;
    }

    public function forProperty(Property $property): CredentialProvider
    {
        return $property->credential_provider === 'oauth'
            ? new OAuthProvider   // the stored refresh token is wired in when OAuth is switched on
            : $this->serviceAccount;
    }
}
