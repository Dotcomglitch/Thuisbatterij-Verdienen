<?php
/**
 * DataBowl Lead Submission API
 * Receives form data and submits to DataBowl
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_log("DataBowl submission received");

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Headers
header('Content-Type: application/json');

try {
    // Option 1: If using DataBowl PHP Library (recommended)
    // require __DIR__ . '/../vendor/autoload.php';
    // use Databowl\Client;
    // use Databowl\Leads\Lead;
    
    // $client = new Client($input['instance_name']);
    // $lead = new Lead($input['campaign_id'], $input['supplier_id']);
    // $lead->getData()->exchangeArray([...]);
    // $newLead = $client->submitLead($lead);
    
    // Option 2: Direct HTTP POST to DataBowl (alternative)
    $leadData = $input['leadData'];
    
    // Prepare data in DataBowl format
    $databowlPayload = [
        'f_18_title' => $leadData['title'] ?? '',
        'f_19_firstname' => $leadData['firstname'] ?? '',
        'f_20_lastname' => $leadData['lastname'] ?? '',
        'f_17_email' => $leadData['email'] ?? '',
        'f_street_name' => $leadData['street'] ?? '',
        'f_house_number' => $leadData['housenumber'] ?? '',
        'f_postal_code' => $leadData['postcode'] ?? '',
        'f_city' => $leadData['city'] ?? '',
        'f_phone' => $leadData['phone'] ?? '',
        // Add custom fields for funnel answers
        'f_question_1' => $leadData['funnel_answers']['question_1'] ?? '',
        'f_question_2' => $leadData['funnel_answers']['question_2'] ?? '',
        'f_question_3' => $leadData['funnel_answers']['question_3'] ?? '',
        'f_question_4' => $leadData['funnel_answers']['question_4'] ?? '',
        'f_question_5' => $leadData['funnel_answers']['question_5'] ?? ''
    ];
    
    // Get your Integration Document URL from DataBowl dashboard
    $integrationDocUrl = 'https://your-databowl-instance.com/api/leads'; // Update this
    
    // Send to DataBowl
    $ch = curl_init($integrationDocUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($databowlPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 || $httpCode === 201) {
        echo json_encode([
            'success' => true,
            'message' => 'Lead submitted to DataBowl',
            'data' => json_decode($response)
        ]);
    } else {
        throw new Exception("DataBowl API returned HTTP $httpCode");
    }
    
} catch (Exception $e) {
    error_log("DataBowl error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error submitting lead',
        'error' => $e->getMessage()
    ]);
    http_response_code(400);
}
?>