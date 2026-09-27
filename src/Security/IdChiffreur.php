<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les identifiants numériques exposés dans les URL.
 *
 * Bloc AES-128 unique = identifiant (8 octets) + signature HMAC (8 octets), encodé en base64url :
 * 22 caractères opaques, impossibles à deviner ou à modifier sans la clé (dérivée d'APP_SECRET).
 * Un identifiant falsifié est rejeté (decode() renvoie null).
 */
final class IdChiffreur
{
    private const LONGUEUR = 22;

    private readonly string $cleChiffrement;
    private readonly string $cleSignature;
    /** @var array<int, string> */
    private array $cache = [];

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        if (\strlen($secret) < 16) {
            throw new \RuntimeException('APP_SECRET absent ou trop court (32 caractères conseillés) : définissez-le dans .env.local, par exemple avec « php -r \'echo bin2hex(random_bytes(16));\' ».');
        }
        $this->cleChiffrement = hash_hkdf('sha256', $secret, 16, 'sotrama-id-chiffrement');
        $this->cleSignature = hash_hkdf('sha256', $secret, 32, 'sotrama-id-signature');
    }

    public function encode(int $id): string
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $clair = pack('J', $id);
        $bloc = $clair.substr(hash_hmac('sha256', $clair, $this->cleSignature, true), 0, 8);
        $chiffre = openssl_encrypt($bloc, 'aes-128-ecb', $this->cleChiffrement, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);

        return $this->cache[$id] = rtrim(strtr(base64_encode($chiffre), '+/', '-_'), '=');
    }

    public function decode(string $valeur): ?int
    {
        if (self::LONGUEUR !== \strlen($valeur) || !preg_match('/^[A-Za-z0-9_-]+$/', $valeur)) {
            return null;
        }
        $chiffre = base64_decode(strtr($valeur, '-_', '+/').'==', true);
        if (false === $chiffre || 16 !== \strlen($chiffre)) {
            return null;
        }
        $bloc = openssl_decrypt($chiffre, 'aes-128-ecb', $this->cleChiffrement, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if (false === $bloc) {
            return null;
        }
        $clair = substr($bloc, 0, 8);
        if (!hash_equals(substr(hash_hmac('sha256', $clair, $this->cleSignature, true), 0, 8), substr($bloc, 8))) {
            return null;
        }
        $id = unpack('J', $clair)[1];

        return $id > 0 ? $id : null;
    }

    /** Déchiffre un identifiant facultatif (paramètre de requête) ; null si absent ou invalide. */
    public function decodeOptionnel(mixed $valeur): ?int
    {
        return \is_string($valeur) && '' !== $valeur ? $this->decode($valeur) : null;
    }
}
