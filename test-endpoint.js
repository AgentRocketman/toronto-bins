/**
 * Test script for CurbIn Completion Email API
 * Usage: node test-endpoint.js
 */

const http = require('http');

// Test configuration
const API_HOST = process.env.API_HOST || 'localhost';
const API_PORT = process.env.API_PORT || 3000;
const API_ENDPOINT = '/api/send-completion-email';

/**
 * Make HTTP POST request
 */
function makeRequest(payload) {
  return new Promise((resolve, reject) => {
    const postData = JSON.stringify(payload);

    const options = {
      hostname: API_HOST,
      port: API_PORT,
      path: API_ENDPOINT,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(postData),
      },
    };

    const req = http.request(options, (res) => {
      let data = '';

      res.on('data', (chunk) => {
        data += chunk;
      });

      res.on('end', () => {
        resolve({
          statusCode: res.statusCode,
          headers: res.headers,
          body: JSON.parse(data),
        });
      });
    });

    req.on('error', (error) => {
      reject(error);
    });

    req.write(postData);
    req.end();
  });
}

/**
 * Test cases
 */
const testCases = [
  {
    name: 'Valid request with address and required fields',
    payload: {
      address: '123 Main St, Toronto, ON M1A 1A1',
      serviceType: 'roll-out',
      workerName: 'John Smith',
      completedDateTime: new Date().toISOString(),
    },
    expectedStatus: 200,
  },
  {
    name: 'Valid request with photo',
    payload: {
      stopId: 'stop_12345',
      address: '456 Oak Avenue, Toronto, ON M2B 2B2',
      serviceType: 'roll-in',
      workerName: 'Sarah Johnson',
      completedDateTime: new Date().toISOString(),
      imageUrl: 'https://example.com/photo.jpg',
    },
    expectedStatus: 200,
  },
  {
    name: 'Invalid request - missing address',
    payload: {
      serviceType: 'roll-out',
      workerName: 'John Smith',
      completedDateTime: new Date().toISOString(),
    },
    expectedStatus: 400,
  },
  {
    name: 'Invalid request - missing workerName',
    payload: {
      address: '789 Elm St, Toronto, ON M3C 3C3',
      serviceType: 'roll-out',
      completedDateTime: new Date().toISOString(),
    },
    expectedStatus: 400,
  },
  {
    name: 'Invalid request - missing completedDateTime',
    payload: {
      address: '789 Elm St, Toronto, ON M3C 3C3',
      serviceType: 'roll-out',
      workerName: 'John Smith',
    },
    expectedStatus: 400,
  },
  {
    name: 'Invalid request - bad serviceType',
    payload: {
      address: '789 Elm St, Toronto, ON M3C 3C3',
      serviceType: 'invalid-type',
      workerName: 'John Smith',
      completedDateTime: new Date().toISOString(),
    },
    expectedStatus: 400,
  },
  {
    name: 'Address not found in Airtable',
    payload: {
      address: 'NONEXISTENT ADDRESS, NOWHERE, ZZ 9Z9 9Z9',
      serviceType: 'roll-out',
      workerName: 'John Smith',
      completedDateTime: new Date().toISOString(),
    },
    expectedStatus: 404,
  },
];

/**
 * Run tests
 */
async function runTests() {
  console.log(`\n🧪 CurbIn Completion Email API - Test Suite`);
  console.log(`📍 Target: http://${API_HOST}:${API_PORT}${API_ENDPOINT}\n`);

  let passed = 0;
  let failed = 0;

  for (const test of testCases) {
    try {
      console.log(`\n📝 Test: ${test.name}`);
      console.log(`   Payload: ${JSON.stringify(test.payload)}`);

      const result = await makeRequest(test.payload);

      console.log(`   Status: ${result.statusCode} (expected: ${test.expectedStatus})`);
      console.log(`   Response: ${JSON.stringify(result.body)}`);

      if (result.statusCode === test.expectedStatus) {
        console.log(`   ✅ PASS`);
        passed++;
      } else {
        console.log(`   ❌ FAIL - Wrong status code`);
        failed++;
      }
    } catch (error) {
      console.log(`   ❌ FAIL - ${error.message}`);
      failed++;
    }
  }

  // Summary
  console.log(`\n${'='.repeat(60)}`);
  console.log(`📊 Test Results:`);
  console.log(`   ✅ Passed: ${passed}`);
  console.log(`   ❌ Failed: ${failed}`);
  console.log(`   📈 Total:  ${testCases.length}`);
  console.log(`${'='.repeat(60)}\n`);

  process.exit(failed > 0 ? 1 : 0);
}

// Run tests if executed directly
if (require.main === module) {
  runTests().catch((error) => {
    console.error('Test suite error:', error);
    process.exit(1);
  });
}

module.exports = { makeRequest, testCases };
