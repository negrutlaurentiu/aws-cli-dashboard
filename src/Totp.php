<?php

declare(strict_types=1);

namespace AwsDash;

/**
 * RFC 6238 TOTP generator (RFC 4648 base32 secret, HMAC-SHA1, 6 digits, 30s period).
 * This matches the virtual-MFA codes shown by Authy / Google Authenticator for an
 * AWS IAM virtual MFA device, so a stored base32 seed lets us produce --token-code
 * automatically.
 */
final class Totp
{
    /**
     * @return array{code:string,valid_for:int,period:int,valid_until:int}
     */
    public static function generate(string $base32Secret, ?int $now = null, int $period = 30, int $digits = 6): array
    {
        $now ??= time();
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            throw new \InvalidArgumentException('Empty or invalid base32 MFA secret.');
        }

        $counter = intdiv($now, $period);
        $binCounter = pack('J', $counter); // 64-bit unsigned, big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % (10 ** $digits);
        $code = str_pad((string) $otp, $digits, '0', STR_PAD_LEFT);

        $validFor = $period - ($now % $period);

        return [
            'code' => $code,
            'valid_for' => $validFor,
            'period' => $period,
            'valid_until' => $now + $validFor,
        ];
    }

    /** Decode an RFC 4648 base32 string (ignoring spaces, padding and case). */
    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') {
            return '';
        }

        $bits = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos($alphabet, $b32[$i]);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    /** Quick sanity check that a string looks like a usable base32 MFA seed. */
    public static function looksLikeSecret(string $candidate): bool
    {
        $clean = preg_replace('/[^A-Za-z2-7]/', '', $candidate);
        return is_string($clean) && strlen($clean) >= 16;
    }
}
