<?php
namespace MuseDockPanel\Services;

class MfaService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $length = max(16, min(64, $length));
        $secret = '';
        $max = strlen(self::BASE32_ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $max)];
        }
        return $secret;
    }

    public static function buildOtpAuthUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $secret = strtoupper(trim($secret));
        $code = preg_replace('/\s+/', '', trim($code));
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $slice = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = self::totpAt($secret, $slice + $i);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }
        return false;
    }

    private static function totpAt(string $secret, int $timeSlice): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') {
            return '000000';
        }

        $binaryTime = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $binaryTime, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7FFFFFFF;
        $otp = $value % 1000000;
        return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(trim($input));
        $input = preg_replace('/[^A-Z2-7]/', '', $input) ?? '';
        if ($input === '') {
            return '';
        }

        $alphabetMap = array_flip(str_split(self::BASE32_ALPHABET));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($input) as $char) {
            if (!isset($alphabetMap[$char])) {
                continue;
            }
            $buffer = ($buffer << 5) | $alphabetMap[$char];
            $bitsLeft += 5;

            while ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}

