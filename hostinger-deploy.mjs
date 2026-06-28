#!/usr/bin/env node
/**
 * Standalone Hostinger static-website deployer.
 *
 * Replaces the cron+agent indirection. Called directly from the Builder Runner:
 *   node hostinger-deploy.mjs <domain> <archivePath>
 *
 * Replicates `hosting_deployStaticWebsite` from hostinger-api-mcp:
 *   1. Resolve username from domain
 *   2. Fetch TUS upload credentials
 *   3. Upload archive via TUS protocol
 *   4. Trigger deploy
 *
 * Reuses axios + tus-js-client already installed under hostinger-api-mcp.
 */

import { createRequire } from 'module';
import fs from 'fs';
import path from 'path';

const require = createRequire('/data/.npm-global/lib/node_modules/hostinger-api-mcp/');
const axios = require('axios').default || require('axios');
const tus = require('tus-js-client');

const API_TOKEN = process.env.HOSTINGER_API_TOKEN || 'B4V2bxKyjkRgso0JS9CkiCqkqUZ32PhAzA16cxcB87d7b57e';
const BASE_URL = 'https://developers.hostinger.com';

const log = (msg) => console.error(`[${new Date().toISOString()}] ${msg}`);

async function resolveUsername(domain) {
  const url = `${BASE_URL}/api/hosting/v1/websites?domain=${encodeURIComponent(domain)}`;
  const resp = await axios.get(url, {
    headers: { Authorization: `Bearer ${API_TOKEN}` },
    timeout: 60000,
  });
  const websites = resp.data?.data || [];
  if (!websites.length) throw new Error(`No website for domain ${domain}`);
  const username = websites[0].username;
  if (!username) throw new Error('Username missing in API response');
  return username;
}

async function fetchUploadCreds(username, domain) {
  const url = `${BASE_URL}/api/hosting/v1/files/upload-urls`;
  const resp = await axios.post(url, { username, domain }, {
    headers: {
      Authorization: `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json',
    },
    timeout: 60000,
  });
  const { url: uploadUrl, auth_key, rest_auth_key } = resp.data;
  if (!uploadUrl || !auth_key || !rest_auth_key) {
    throw new Error('Invalid upload credentials: ' + JSON.stringify(resp.data));
  }
  return { uploadUrl, authToken: auth_key, authRestToken: rest_auth_key };
}

async function uploadArchive(filePath, uploadUrl, authToken, authRestToken) {
  const archiveName = path.basename(filePath);
  const stats = fs.statSync(filePath);
  const cleanUploadUrl = uploadUrl.replace(/\/$/, '');
  const uploadUrlWithFile = `${cleanUploadUrl}/${archiveName}?override=true`;

  const headers = {
    'X-Auth': authToken,
    'X-Auth-Rest': authRestToken,
    'upload-length': stats.size.toString(),
    'upload-offset': '0',
  };

  // Pre-upload POST (TUS creation)
  await axios.post(uploadUrlWithFile, '', {
    headers,
    timeout: 60000,
    validateStatus: (s) => s === 201,
  });

  // TUS PATCH upload
  return new Promise((resolve, reject) => {
    const stream = fs.createReadStream(filePath);
    const upload = new tus.Upload(stream, {
      uploadUrl: uploadUrlWithFile,
      retryDelays: [1000, 2000, 4000, 8000],
      uploadDataDuringCreation: false,
      parallelUploads: 1,
      chunkSize: 10 * 1024 * 1024,
      headers,
      removeFingerprintOnSuccess: true,
      onError: (err) => reject(new Error(`TUS upload failed: ${err.message || err}`)),
      onSuccess: () => resolve({ filename: archiveName }),
      uploadSize: stats.size,
    });
    upload.start();
  });
}

async function triggerDeploy(username, domain, archivePath) {
  const archiveBasename = path.basename(archivePath);
  const url = `${BASE_URL}/api/hosting/v1/accounts/${username}/websites/${domain}/deploy`;
  const resp = await axios.post(url, { archive_path: archiveBasename }, {
    headers: {
      Authorization: `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json',
    },
    timeout: 60000,
  });
  if (resp.status !== 200) {
    throw new Error(`Deploy API returned ${resp.status}: ${JSON.stringify(resp.data)}`);
  }
  return resp.data;
}

async function main() {
  const [domain, archivePath] = process.argv.slice(2);
  if (!domain || !archivePath) {
    console.error('Usage: node hostinger-deploy.mjs <domain> <archivePath>');
    process.exit(2);
  }
  if (!fs.existsSync(archivePath)) {
    console.error(`Archive not found: ${archivePath}`);
    process.exit(2);
  }

  const t0 = Date.now();
  log(`Resolving username for ${domain}...`);
  const username = await resolveUsername(domain);
  log(`Username: ${username}`);

  log('Fetching upload credentials...');
  const creds = await fetchUploadCreds(username, domain);

  const sizeMb = (fs.statSync(archivePath).size / 1024 / 1024).toFixed(2);
  log(`Uploading archive (${sizeMb} MB)...`);
  const uploadRes = await uploadArchive(archivePath, creds.uploadUrl, creds.authToken, creds.authRestToken);
  log(`Upload complete: ${uploadRes.filename}`);

  log(`Triggering deploy for ${domain}...`);
  const deployRes = await triggerDeploy(username, domain, archivePath);
  const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
  log(`✅ Deploy triggered in ${elapsed}s`);

  // Output structured result on stdout for the runner to parse
  console.log(JSON.stringify({
    success: true,
    elapsed_seconds: parseFloat(elapsed),
    upload: uploadRes,
    deploy: deployRes,
  }));
}

main().catch((err) => {
  log(`❌ ${err.message || err}`);
  console.log(JSON.stringify({ success: false, error: err.message || String(err) }));
  process.exit(1);
});
