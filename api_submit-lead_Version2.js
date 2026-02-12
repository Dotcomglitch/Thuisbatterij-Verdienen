// Vercel Serverless Function for DataBowl Lead Submission
// Deploy to: /api/submit-lead.js

const https = require('https');

// DataBowl Configuration
const DATABOWL_PUBLIC_KEY = process.env.DATABOWL_PUBLIC_KEY || 'e485a81d2b79056fe7d46b24e95c3cf8';
const DATABOWL_PRIVATE_KEY = process.env.DATABOWL_PRIVATE_KEY || 'db01497200b397e8a4f14ad78699a07a';

/**
 * Submit lead to DataBowl via HTTP POST
 */
function submitLeadToDataBowl(leadData, campaignId, supplierId) {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({
            campaign_id: campaignId,
            supplier_id: supplierId,
            f_18_title: leadData.title || '',
            f_19_firstname: leadData.firstname || '',
            f_20_lastname: leadData.lastname || '',
            f_17_email: leadData.email || '',
            f_street_name: leadData.street || '',
            f_house_number: leadData.housenumber || '',
            f_postal_code: leadData.postcode || '',
            f_city: leadData.city || '',
            f_phone: leadData.phone || '',
            f_question_1_solar: leadData.funnel_answers?.question_1 || '',
            f_question_2_location: leadData.funnel_answers?.question_2 || '',
            f_question_3_consumption: leadData.funnel_answers?.question_3 || '',
            f_question_4_space: leadData.funnel_answers?.question_4 || '',
            f_question_5_property_type: leadData.funnel_answers?.question_5 || '',
            f_source: 'Web Form',
            f_timestamp: new Date().toISOString()
        });
        
        console.log('Submitting lead:', payload);
        
        // Replace with your actual DataBowl submission endpoint
        const submissionUrl = 'https://your-databowl-instance.databowl.com/api/leads/submit';
        
        const options = {
            hostname: new URL(submissionUrl).hostname,
            path: new URL(submissionUrl).pathname,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Content-Length': Buffer.byteLength(payload),
                'Authorization': `Bearer ${DATABOWL_PUBLIC_KEY}`
            }
        };
        
        const req = https.request(options, (res) => {
            let data = '';
            
            res.on('data', (chunk) => {
                data += chunk;
            });
            
            res.on('end', () => {
                console.log('DataBowl Response:', data);
                
                if (res.statusCode >= 200 && res.statusCode < 300) {
                    resolve({
                        success: true,
                        message: 'Lead successfully submitted to DataBowl',
                        http_code: res.statusCode
                    });
                } else {
                    reject(new Error(`DataBowl API returned ${res.statusCode}: ${data}`));
                }
            });
        });
        
        req.on('error', (error) => {
            reject(error);
        });
        
        req.write(payload);
        req.end();
    });
}

/**
 * Main Handler
 */
export default async function handler(req, res) {
    // Set CORS headers
    res.setHeader('Access-Control-Allow-Credentials', 'true');
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET,OPTIONS,PATCH,DELETE,POST,PUT');
    res.setHeader('Access-Control-Allow-Headers', 'X-CSRF-Token, X-Requested-With, Accept, Accept-Version, Content-Length, Content-MD5, Content-Type, Date, X-Api-Version');
    
    if (req.method === 'OPTIONS') {
        res.status(200).end();
        return;
    }
    
    if (req.method !== 'POST') {
        return res.status(405).json({ success: false, error: 'Method not allowed' });
    }
    
    try {
        const { leadData, campaign_id, supplier_id } = req.body;
        
        if (!leadData || !campaign_id || !supplier_id) {
            return res.status(400).json({
                success: false,
                error: 'Missing required fields: leadData, campaign_id, supplier_id'
            });
        }
        
        const result = await submitLeadToDataBowl(leadData, campaign_id, supplier_id);
        
        return res.status(200).json(result);
        
    } catch (error) {
        console.error('Lead submission error:', error.message);
        return res.status(400).json({
            success: false,
            error: error.message
        });
    }
}
