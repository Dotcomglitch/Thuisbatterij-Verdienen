// At the top of the file, replace these lines:
const DATABOWL_PUBLIC_KEY = process.env.DATABOWL_PUBLIC_KEY || 'e485a81d2b79056fe7d46b24e95c3cf8';
const DATABOWL_PRIVATE_KEY = process.env.DATABOWL_PRIVATE_KEY || 'db01497200b397e8a4f14ad78699a07a';

// Update the submission URL with your Campaign ID and Supplier ID:
const submissionUrl = `https://api.databowl.com/v1/leads/${campaignId}/${supplierId}/submit`;
// Or use the endpoint from your Integration Document