<?php
/**
 * Webhook Handler for City Selection Form
 * Sends city selection data to n8n webhook
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit();
}

// Get raw POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate input
if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit();
}

// Log received data (for debugging)
logData('City selection received:', $data);

// CONFIGURATION - SET YOUR N8N WEBHOOK URL HERE
$n8n_webhook_url = 'https://aistudio.didbi.com/webhook/form/route'; // ← CHANGE THIS!

// Validate webhook URL
if (empty($n8n_webhook_url) || strpos($n8n_webhook_url, 'your-n8n-domain') !== false) {
    $response = [
        'success' => false,
        'error' => 'N8N webhook URL not configured',
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    echo json_encode($response);
    exit();
}

// Prepare data for n8n
$payload = prepareN8NPayload($data);

// Send to n8n webhook
$result = sendToN8N($n8n_webhook_url, $payload);

// Return response
if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => 'City route data sent to n8n successfully',
        'n8n_response' => $result['response'],
        'timestamp' => date('Y-m-d H:i:s'),
        'route_id' => uniqid('ROUTE_')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send city route to n8n',
        'n8n_error' => $result['error'],
        'received_data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Prepare payload for n8n
 */
function prepareN8NPayload($data) {
    $payload = [
        'event_type' => 'city_route_selection',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => time(),
        'source' => $data['source'] ?? 'telegram_web_app',
        'action' => $data['action'] ?? 'unknown',
        'route_type' => $data['route_type'] ?? 'unknown'
    ];
    
    // Add route information
    if (isset($data['origin']) && isset($data['destination'])) {
        $payload['route'] = [
            'origin_country' => $data['origin']['country']['name'] ?? 'unknown',
            'origin_country_code' => $data['origin']['country']['code'] ?? 'unknown',
            'origin_city' => $data['origin']['city']['name'] ?? 'unknown',
            'origin_city_code' => $data['origin']['city']['code'] ?? 'unknown',
            'origin_city_population' => $data['origin']['city']['population'] ?? 'unknown',
            
            'destination_country' => $data['destination']['country']['name'] ?? 'unknown',
            'destination_country_code' => $data['destination']['country']['code'] ?? 'unknown',
            'destination_city' => $data['destination']['city']['name'] ?? 'unknown',
            'destination_city_code' => $data['destination']['city']['code'] ?? 'unknown',
            'destination_city_population' => $data['destination']['city']['population'] ?? 'unknown',
            
            'display_text' => $data['display_text'] ?? '',
            'display_text_en' => $data['display_text_en'] ?? ''
        ];
        
        // Calculate route type
        $originCountry = $data['origin']['country']['code'] ?? '';
        $destCountry = $data['destination']['country']['code'] ?? '';
        
        if ($originCountry === $destCountry) {
            $payload['route_type_detailed'] = 'domestic';
        } else {
            $payload['route_type_detailed'] = 'international';
        }
    }
    
    // Add Telegram user info
    if (isset($data['telegram_user'])) {
        $payload['telegram_user'] = $data['telegram_user'];
    }
    
    // Add metadata
    $payload['metadata'] = [
        'ip_address' => $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'processed_at' => date('Y-m-d H:i:s')
    ];
    
    return $payload;
}

/**
 * Send data to n8n webhook
 */
function sendToN8N($url, $payload) {
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'User-Agent: Telegram-City-Selection-Webhook/1.0'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FAILONERROR => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Log the request
    logData('City data sent to n8n:', [
        'url' => $url,
        'payload_size' => strlen(json_encode($payload)),
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ]);
    
    if ($error) {
        return ['success' => false, 'error' => $error];
    }
    
    // Consider 2xx and 3xx status codes as success
    if ($httpCode >= 200 && $httpCode < 400) {
        return [
            'success' => true,
            'response' => json_decode($response, true) ?: $response,
            'http_code' => $httpCode
        ];
    }
    
    return [
        'success' => false,
        'error' => "HTTP $httpCode",
        'response' => $response
    ];
}

/**
 * Log data for debugging
 */
function logData($message, $data) {
    $logFile = __DIR__ . '/webhook_city_log.txt';
    $logEntry = date('Y-m-d H:i:s') . " - $message\n";
    $logEntry .= print_r($data, true) . "\n";
    $logEntry .= str_repeat('-', 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// Create a simple HTML test page if accessed via browser
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <title>Webhook Test Page - City Selection</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; direction: rtl; }
            .container { max-width: 800px; margin: 0 auto; }
            .status { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background: #d4edda; color: #155724; }
            .error { background: #f8d7da; color: #721c24; }
            .test-form { background: #f8f9fa; padding: 20px; border-radius: 10px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>وب‌هوک انتخاب شهر - صفحه تست</h1>
            
            <div class="status success">
                <strong>وضعیت:</strong> وب‌هوک در حال اجراست
            </div>
            
            <div class="test-form">
                <h2>تست وب‌هوک</h2>
                <p>از این فرم برای تست دستی وب‌هوک استفاده کنید:</p>
                
                <form id="testForm">
                    <div>
                        <label>مبدا کشور:</label>
                        <select id="originCountry">
                            <option value="IR">ایران</option>
                            <option value="OM">عمان</option>
                        </select>
                    </div>
                    
                    <div>
                        <label>مبدا شهر:</label>
                        <input type="text" id="originCity" value="تهران">
                    </div>
                    
                    <div>
                        <label>مقصد کشور:</label>
                        <select id="destinationCountry">
                            <option value="OM">عمان</option>
                            <option value="IR">ایران</option>
                        </select>
                    </div>
                    
                    <div>
                        <label>مقصد شهر:</label>
                        <input type="text" id="destinationCity" value="مسقط">
                    </div>
                    
                    <button type="button" onclick="testWebhook()">تست وب‌هوک</button>
                </form>
                
                <div id="testResult"></div>
            </div>
        </div>
        
        <script>
            async function testWebhook() {
                const data = {
                    action: 'city_route_selected',
                    route_type: 'international',
                    origin: {
                        country: {
                            code: document.getElementById('originCountry').value,
                            name: document.getElementById('originCountry').value === 'IR' ? 'ایران' : 'عمان',
                            name_en: document.getElementById('originCountry').value === 'IR' ? 'Iran' : 'Oman'
                        },
                        city: {
                            code: 'THR',
                            name: document.getElementById('originCity').value,
                            name_en: 'Tehran',
                            population: '8.7M'
                        }
                    },
                    destination: {
                        country: {
                            code: document.getElementById('destinationCountry').value,
                            name: document.getElementById('destinationCountry').value === 'IR' ? 'ایران' : 'عمان',
                            name_en: document.getElementById('destinationCountry').value === 'IR' ? 'Iran' : 'Oman'
                        },
                        city: {
                            code: 'MCT',
                            name: document.getElementById('destinationCity').value,
                            name_en: 'Muscat',
                            population: '1.3M'
                        }
                    },
                    display_text: `از ${document.getElementById('originCity').value} به ${document.getElementById('destinationCity').value}`,
                    source: 'web_test',
                    timestamp: new Date().toISOString()
                };
                
                try {
                    const response = await fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    document.getElementById('testResult').innerHTML = 
                        '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
                } catch (error) {
                    document.getElementById('testResult').innerHTML = 
                        'خطا: ' + error.message;
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit();
}
?>