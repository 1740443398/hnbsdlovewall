<?php

class TOTP {
    private $secret;
    private $digits = 6;
    private $period = 30;
    private $algorithm = 'sha1';

    public function __construct($secret = null) {
        $this->secret = $secret ?: $this->generateSecret();
    }

    public static function generateSecret($length = 20) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public function getSecret() {
        return $this->secret;
    }

    public function getProvisioningUri($label, $issuer = 'CampusWall') {
        $params = [
            'secret' => $this->secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ];
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?' . http_build_query($params);
    }

    public function getQRCodeUrl($label, $issuer = 'CampusWall') {
        $uri = $this->getProvisioningUri($label, $issuer);
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);
    }

    public function verify($code, $discrepancy = 1) {
        if (strlen($code) !== $this->digits) {
            return false;
        }

        $currentTimeSlice = floor(time() / $this->period);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = $this->calculateCode($currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    public function getCurrentCode() {
        $timeSlice = floor(time() / $this->period);
        return $this->calculateCode($timeSlice);
    }

    private function calculateCode($timeSlice) {
        $secretKey = $this->base32Decode($this->secret);
        $time = pack('J', $timeSlice);
        $hash = hash_hmac($this->algorithm, $time, $secretKey, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $hashPart = substr($hash, $offset, 4);
        $value = unpack('N', $hashPart)[1];
        $value = $value & 0x7FFFFFFF;
        return str_pad($value % pow(10, $this->digits), $this->digits, '0', STR_PAD_LEFT);
    }

    private function base32Decode($input) {
        $input = strtoupper(rtrim($input, '='));
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        for ($i = 0; $i < strlen($binary); $i += 8) {
            $byte = substr($binary, $i, 8);
            if (strlen($byte) < 8) break;
            $result .= chr(bindec($byte));
        }

        return $result;
    }

    public static function generateRecoveryCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5));
        }
        return $codes;
    }

    public static function verifyRecoveryCode($code, $storedCodes) {
        $codes = json_decode($storedCodes, true) ?: [];
        $index = array_search($code, $codes);
        if ($index !== false) {
            unset($codes[$index]);
            return ['valid' => true, 'remaining_codes' => array_values($codes)];
        }
        return ['valid' => false, 'remaining_codes' => $codes];
    }
}