<?php
/**
 * DataBowl Validation API Wrapper
 * Handles:
 * - Dutch phone number validation (HLR)
 * - Dutch address validation
 * - Email validation
 * - IP geolocation check (Netherlands only)
 */

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_log("Validation request received");

// ===== DATABOWL CREDENTIALS =====
// Replace these with your actual DataBowl API keys
const DATABOWL_API_URL = 'https://vapi.databowl.com/api/v1/validation';
const DATABOWL_PUBLIC_KEY = process.env.DATABOWL_PUBLIC_KEY || 'e485a81d2b79056fe7d46b24e95c3cf8';
const DATABOWL_PRIVATE_KEY = process.env.DATABOWL_PRIVATE_KEY || 'db01497200b397e8a4f14ad78699a07a';

// ===== DUTCH MOBILE PREFIXES =====
const DUTCH_PREFIXES = ['0031', '31', '+31'];
const DUTCH_MOBILE_CODES = ['06'];

class DataBowlValidator {
    private $publicKey;
    private $privateKey;
    private $apiUrl;

    public function __construct($publicKey, $privateKey, $apiUrl) {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->apiUrl = $apiUrl;
    }

    /**
     * Generate HMAC-SHA256 signature for DataBowl API
     */
    private function generateSignature($timestamp, $service, $type, $data = []) {
        $signatureString = "timestamp=$timestamp&service=$service&type=$type";
        
        // Append data parameters
        foreach ($data as $key => $value) {
            $signatureString .= "&data[$key]=" . urlencode($value);
        }
        
        error_log("Signature string: " . $signatureString);
        
        $signature = hash_hmac('sha256', $signatureString, $this->privateKey);
        return $signature;
    }

    /**
     * Make API request to DataBowl
     */
    private function apiRequest($service, $type, $data) {
        $timestamp = time();
        $signature = $this->generateSignature($timestamp, $service, $type, $data);
        
        $queryParams = [
            'key' => $this->publicKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'service' => $service,
            'type' => $type
        ];
        
        // Add data parameters
        foreach ($data as $key => $value) {
            $queryParams["data[$key]"] = $value;
        }
        
        $url = $this->apiUrl . '?' . http_build_query($queryParams);
        error_log("API URL: " . str_replace([$this->publicKey, $this->privateKey], ['***', '***'], $url));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DataBowl-Validator/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        error_log("DataBowl Response Code: " . $httpCode);
        error_log("DataBowl Response: " . substr($response, 0, 500));
        
        if ($curlError) {
            throw new Exception("CURL Error: " . $curlError);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("DataBowl API returned HTTP $httpCode: " . substr($response, 0, 200));
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from DataBowl");
        }
        
        return $decoded;
    }

    /**
     * Validate Dutch Mobile Phone Number (HLR)
     */
    public function validateDutchMobile($phoneNumber) {
        // Normalize phone number
        $normalized = $this->normalizeDutchPhone($phoneNumber);
        
        if (!$normalized['valid']) {
            return [
                'success' => false,
                'error' => $normalized['error']
            ];
        }
        
        try {
            $result = $this->apiRequest('validate', 'hlr', [
                'mobile' => $normalized['phone']
            ]);
            
            $status = $result['result'] ?? null;
            
            if ($status === 'live') {
                return [
                    'success' => true,
                    'status' => 'live',
                    'network' => $result['network'] ?? 'Unknown',
                    'formatted_phone' => $normalized['phone'],
                    'message' => 'Telefoonnummer is geldig en actief'
                ];
            } elseif ($status === 'dead') {
                return [
                    'success' => false,
                    'error' => 'Dit telefoonnummer is niet actief of ongeldig'
                ];
            } elseif ($status === 'retry-later') {
                return [
                    'success' => false,
                    'error' => 'Telefoonnummer kan op dit moment niet worden geverifieerd. Probeer het later opnieuw.'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Onbekende validatiestatus'
                ];
            }
        } catch (Exception $e) {
            error_log("Phone validation error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Fout bij validatie van telefoonnummer: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate Email Address
     */
    public function validateEmail($email) {
        // Basic email validation first
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Ongeldig e-mailadres'
            ];
        }
        
        try {
            $result = $this->apiRequest('validate', 'email', [
                'email' => $email
            ]);
            
            $status = $result['result'] ?? null;
            
            if ($status === true) {
                return [
                    'success' => true,
                    'status' => 'valid',
                    'email' => $email,
                    'message' => 'E-mailadres is geldig'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Dit e-mailadres is niet geldig of bereikbaar'
                ];
            }
        } catch (Exception $e) {
            error_log("Email validation error: " . $e->getMessage());
            // If API fails, accept the email if format is valid
            return [
                'success' => true,
                'status' => 'valid',
                'email' => $email,
                'message' => 'E-mailadres format is geldig'
            ];
        }
    }

    /**
     * Normalize Dutch Phone Number to +31 format
     */
    private function normalizeDutchPhone($phoneNumber) {
        $phone = trim(str_replace([' ', '-', '(', ')', '.'], '', $phoneNumber));
        
        // Check if it's a Dutch number format
        if (strpos($phone, '+31') === 0) {
            // Already in +31 format
            if (strlen($phone) < 11) {
                return ['valid' => false, 'error' => 'Telefoonnummer is te kort'];
            }
            return ['valid' => true, 'phone' => $phone];
        } elseif (strpos($phone, '0031') === 0) {
            // 0031 format - convert to +31
            $converted = '+31' . substr($phone, 4);
            if (strlen($converted) < 11) {
                return ['valid' => false, 'error' => 'Telefoonnummer is te kort'];
            }
            return ['valid' => true, 'phone' => $converted];
        } elseif (strpos($phone, '06') === 0 && strlen($phone) === 10) {
            // Dutch mobile starting with 06 - convert to +31 6
            return ['valid' => true, 'phone' => '+31' . substr($phone, 1)];
        } elseif (preg_match('/^31\d{9,10}$/', $phone)) {
            // 31 format without + - add it
            return ['valid' => true, 'phone' => '+' . $phone];
        } else {
            return [
                'valid' => false,
                'error' => 'Ongeldig Nederlands telefoonnummerformaat. Gebruik 06XXXXXXXX, 0031XXXXXXXXX, of +31XXXXXXXXX'
            ];
        }
    }

    /**
     * Validate Dutch Postal Code
     */
    public function validateDutchPostcode($postcode, $houseNumber = null) {
        $postcode = strtoupper(str_replace(' ', '', $postcode));
        
        // Dutch postcode format: NNNN AA (4 digits, 2 letters)
        if (!preg_match('/^[0-9]{4}[A-Z]{2}$/', $postcode)) {
            return [
                'success' => false,
                'error' => 'Ongeldig Nederlands postcode formaat. Gebruik formaat: 1234 AB'
            ];
        }
        
        // House number validation
        if (!empty($houseNumber)) {
            $houseNumber = trim(str_replace(['-', ' '], '', $houseNumber));
            if (!preg_match('/^\d+[A-Z]?$/', $houseNumber)) {
                return [
                    'success' => false,
                    'error' => 'Ongeldig huisnummer'
                ];
            }
        }
        
        return [
            'success' => true,
            'postcode' => $postcode,
            'housenumber' => $houseNumber,
            'valid_format' => true,
            'message' => 'Postcode en huisnummer zijn geldig'
        ];
    }

    /**
     * Validate IP is from Netherlands
     */
    public function validateNetherlandsIP($ipAddress) {
        // For production, use MaxMind GeoIP2 or similar service
        // This is a placeholder - you should integrate with a real GeoIP service
        
        $localIPs = ['127.0.0.1', '::1', 'localhost'];
        
        if (in_array($ipAddress, $localIPs)) {
            return [
                'success' => true,
                'ip' => $ipAddress,
                'country' => 'NL',
                'message' => 'Lokaal IP adres (development)'
            ];
        }
        
        // In production, call GeoIP API here
        // For now, return warning
        return [
            'success' => true,
            'ip' => $ipAddress,
            'warning' => 'IP validatie vereist GeoIP service'
        ];
    }
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Cloudflare
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Load balancer or proxy
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        // Client IP
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    return trim($ip);
}

// ===== REQUEST HANDLER =====
try {
    $request = json_decode(file_get_contents('php://input'), true);
    
    if (!$request) {
        throw new Exception('Invalid JSON request');
    }
    
    $validator = new DataBowlValidator(
        DATABOWL_PUBLIC_KEY,
        DATABOWL_PRIVATE_KEY,
        DATABOWL_API_URL
    );
    
    $action = $request['action'] ?? null;
    $response = [];
    
    switch ($action) {
        case 'validate-phone':
            $response = $validator->validateDutchMobile($request['phone'] ?? '');
            break;
            
        case 'validate-email':
            $response = $validator->validateEmail($request['email'] ?? '');
            break;
            
        case 'validate-postcode':
            $response = $validator->validateDutchPostcode(
                $request['postcode'] ?? '',
                $request['housenumber'] ?? null
            );
            break;
            
        case 'validate-all':
            // Validate phone, email, and postcode
            $response = [
                'phone' => $validator->validateDutchMobile($request['phone'] ?? ''),
                'email' => $validator->validateEmail($request['email'] ?? ''),
                'postcode' => $validator->validateDutchPostcode(
                    $request['postcode'] ?? '',
                    $request['housenumber'] ?? null
                ),
                'client_ip' => getClientIP()
            ];
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $response
    ]);
    
} catch (Exception $e) {
    error_log("Validation error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
