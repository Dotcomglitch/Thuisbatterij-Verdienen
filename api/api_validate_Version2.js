// Vercel Serverless Function for DataBowl Validation
// Deploy to: /api/validate.js

const https = require('https');

// DataBowl API Configuration
const DATABOWL_API_URL = 'https://vapi.databowl.com/api/v1/validation';
const DATABOWL_PUBLIC_KEY = process.env.DATABOWL_PUBLIC_KEY || 'YOUR_PUBLIC_KEY';
const DATABOWL_PRIVATE_KEY = process.env.DATABOWL_PRIVATE_KEY || 'YOUR_PRIVATE_KEY';

/**
 * Generate HMAC-SHA256 signature for DataBowl API
 */
function generateSignature(timestamp, service, type, data = {}) {
    const crypto = require('crypto');
    
    let signatureString = `timestamp=${timestamp}&service=${service}&type=${type}`;
    
    // Append data parameters
    for (const [key, value] of Object.entries(data)) {
        signatureString += `&data[${key}]=${encodeURIComponent(value)}`;
    }
    
    console.log('Signature string:', signatureString);
    
    const signature = crypto
        .createHmac('sha256', DATABOWL_PRIVATE_KEY)
        .update(signatureString)
        .digest('hex');
    
    return signature;
}

/**
 * Make API request to DataBowl
 */
function makeDataBowlRequest(service, type, data) {
    return new Promise((resolve, reject) => {
        const timestamp = Math.floor(Date.now() / 1000);
        const signature = generateSignature(timestamp, service, type, data);
        
        const queryParams = new URLSearchParams({
            key: DATABOWL_PUBLIC_KEY,
            timestamp: timestamp,
            signature: signature,
            service: service,
            type: type,
            ...Object.fromEntries(Object.entries(data).map(([k, v]) => [`data[${k}]`, v]))
        });
        
        const url = `${DATABOWL_API_URL}?${queryParams.toString()}`;
        
        console.log('DataBowl API URL:', url.replace(DATABOWL_PUBLIC_KEY, '***').replace(DATABOWL_PRIVATE_KEY, '***'));
        
        https.get(url, (res) => {
            let responseData = '';
            
            res.on('data', (chunk) => {
                responseData += chunk;
            });
            
            res.on('end', () => {
                console.log('DataBowl Response:', responseData);
                
                if (res.statusCode !== 200) {
                    reject(new Error(`DataBowl API returned ${res.statusCode}: ${responseData}`));
                    return;
                }
                
                try {
                    const parsed = JSON.parse(responseData);
                    resolve(parsed);
                } catch (e) {
                    reject(new Error('Invalid JSON response from DataBowl'));
                }
            });
        }).on('error', (err) => {
            reject(err);
        });
    });
}

/**
 * Validate Dutch Mobile Phone Number
 */
async function validateDutchMobile(phone) {
    const normalized = normalizeDutchPhone(phone);
    
    if (!normalized.valid) {
        return {
            success: false,
            error: normalized.error
        };
    }
    
    try {
        const result = await makeDataBowlRequest('validate', 'hlr', {
            mobile: normalized.phone
        });
        
        const status = result.result;
        
        if (status === 'live') {
            return {
                success: true,
                status: 'live',
                network: result.network || 'Unknown',
                formatted_phone: normalized.phone,
                message: 'Telefoonnummer is geldig en actief'
            };
        } else if (status === 'dead') {
            return {
                success: false,
                error: 'Dit telefoonnummer is niet actief of ongeldig'
            };
        } else if (status === 'retry-later') {
            return {
                success: false,
                error: 'Telefoonnummer kan op dit moment niet worden geverifieerd. Probeer het later opnieuw.'
            };
        } else {
            return {
                success: false,
                error: 'Onbekende validatiestatus'
            };
        }
    } catch (error) {
        console.error('Phone validation error:', error.message);
        return {
            success: false,
            error: `Fout bij validatie: ${error.message}`
        };
    }
}

/**
 * Normalize Dutch Phone Number to +31 format
 */
function normalizeDutchPhone(phone) {
    let cleaned = phone.replace(/[\s\-\(\)\.]/g, '');
    
    if (cleaned.startsWith('+31')) {
        if (cleaned.length < 11) {
            return { valid: false, error: 'Telefoonnummer is te kort' };
        }
        return { valid: true, phone: cleaned };
    } else if (cleaned.startsWith('0031')) {
        const converted = '+31' + cleaned.substring(4);
        if (converted.length < 11) {
            return { valid: false, error: 'Telefoonnummer is te kort' };
        }
        return { valid: true, phone: converted };
    } else if (cleaned.startsWith('06') && cleaned.length === 10) {
        return { valid: true, phone: '+31' + cleaned.substring(1) };
    } else if (cleaned.match(/^31\d{9,10}$/)) {
        return { valid: true, phone: '+' + cleaned };
    } else {
        return {
            valid: false,
            error: 'Ongeldig Nederlands telefoonnummerformaat. Gebruik 06XXXXXXXX, 0031XXXXXXXXX, of +31XXXXXXXXX'
        };
    }
}

/**
 * Validate Email Address
 */
async function validateEmail(email) {
    if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        return {
            success: false,
            error: 'Ongeldig e-mailadres'
        };
    }
    
    try {
        const result = await makeDataBowlRequest('validate', 'email', {
            email: email
        });
        
        if (result.result === true) {
            return {
                success: true,
                status: 'valid',
                email: email,
                message: 'E-mailadres is geldig'
            };
        } else {
            return {
                success: false,
                error: 'Dit e-mailadres is niet geldig of bereikbaar'
            };
        }
    } catch (error) {
        console.error('Email validation error:', error.message);
        // Fallback: accept if format is valid
        return {
            success: true,
            status: 'valid',
            email: email,
            message: 'E-mailadres format is geldig'
        };
    }
}

/**
 * Validate Dutch Postal Code
 */
function validateDutchPostcode(postcode, housenumber = null) {
    let cleaned = postcode.replace(/\s/g, '').toUpperCase();
    
    if (!cleaned.match(/^\d{4}[A-Z]{2}$/)) {
        return {
            success: false,
            error: 'Ongeldig Nederlands postcode formaat. Gebruik formaat: 1234 AB'
        };
    }
    
    if (housenumber) {
        let hnCleaned = String(housenumber).replace(/[\-\s]/g, '');
        if (!hnCleaned.match(/^\d+[A-Z]?$/)) {
            return {
                success: false,
                error: 'Ongeldig huisnummer'
            };
        }
    }
    
    return {
        success: true,
        postcode: cleaned,
        housenumber: housenumber,
        valid_format: true,
        message: 'Postcode en huisnummer zijn geldig'
    };
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
        const { action, phone, email, postcode, housenumber } = req.body;
        
        let response = {};
        
        switch (action) {
            case 'validate-phone':
                response = await validateDutchMobile(phone || '');
                break;
                
            case 'validate-email':
                response = await validateEmail(email || '');
                break;
                
            case 'validate-postcode':
                response = validateDutchPostcode(postcode || '', housenumber);
                break;
                
            case 'validate-all':
                response = {
                    phone: await validateDutchMobile(phone || ''),
                    email: await validateEmail(email || ''),
                    postcode: validateDutchPostcode(postcode || '', housenumber),
                    client_ip: req.headers['x-forwarded-for'] || req.socket.remoteAddress
                };
                break;
                
            default:
                return res.status(400).json({ success: false, error: 'Unknown action: ' + action });
        }
        
        return res.status(200).json({ success: true, data: response });
        
    } catch (error) {
        console.error('Validation error:', error.message);
        return res.status(400).json({ success: false, error: error.message });
    }
}