// OpenClaw internal hook: pwa-bridge
// Forwards outbound Telegram replies to the PWA backend.
//
// Matching strategy:
// 1. If the outbound message is a reply to a PWA request message, use that request_id.
// 2. Otherwise, fall back to the most recent unanswered PWA request in the same chat.
//
// Install: copy to /data/.openclaw/hooks/pwa-bridge/handler.ts
// HOOK.md should subscribe to ["message:received", "message:sent"].
// Ensure openclaw.json has hooks.internal.enabled = true.

import * as fs from 'fs';
import * as https from 'https';
import * as http from 'http';
import { URL } from 'url';

const LOG_PATH = '/data/.openclaw/pwa-bridge-debug.log';
const REPLY_ENDPOINT = 'https://agentrocketman.com/assistant/api/reply.php';
const HOOK_AUTH = 'curbin-hook-auth-2026';
const REQUEST_ID_RE = /\[PWA\s+([a-f0-9]{16})\]/i;
const MATCH_WINDOW_MS = 5 * 60 * 1000; // 5 minutes

interface PendingRequest {
  requestId: string;
  chatId: string | number;
  messageId: number;
  timestamp: number;
  replied: boolean;
}

const pending: PendingRequest[] = [];

function log(msg: string) {
  try {
    fs.appendFileSync(LOG_PATH, `${new Date().toISOString()} ${msg}\n`);
  } catch (e) {
    // ignore logging failures
  }
}

function get(obj: any, ...paths: string[]): any {
  for (const path of paths) {
    const parts = path.split('.');
    let cur = obj;
    for (const p of parts) {
      if (cur == null || typeof cur !== 'object') {
        cur = undefined;
        break;
      }
      cur = cur[p];
    }
    if (cur !== undefined) return cur;
  }
  return undefined;
}

function extractRequestId(text: string): string | null {
  const m = REQUEST_ID_RE.exec(text || '');
  return m ? m[1] : null;
}

function getChatId(event: any): string | number | null {
  return (
    get(event, 'context.chat.id', 'context.message.chat.id') ??
    get(event, 'context.metadata.chatId') ??
    null
  );
}

function getOutboundMessageId(event: any): number | null {
  return get(event, 'context.message_id', 'context.message.message_id', 'context.id') ?? null;
}

function getOutboundText(event: any): string {
  return (
    get(event, 'context.text', 'context.message.text', 'context.content') ||
    ''
  );
}

function getReplyToText(event: any): string {
  return (
    get(event, 'context.reply_to_message.text', 'context.replyToMessage.text', 'context.metadata.replyToMessage.text', 'context.metadata.replyToMessageText') ||
    ''
  );
}

function postReply(requestId: string, replyText: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const payload = JSON.stringify({ request_id: requestId, reply: replyText });
    const url = new URL(REPLY_ENDPOINT);
    const mod = url.protocol === 'https:' ? https : http;
    const req = mod.request(
      {
        hostname: url.hostname,
        port: url.port || (url.protocol === 'https:' ? 443 : 80),
        path: url.pathname + url.search,
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Hook-Auth': HOOK_AUTH,
          'Content-Length': Buffer.byteLength(payload),
        },
      },
      (res) => {
        let body = '';
        res.on('data', (chunk) => (body += chunk));
        res.on('end', () => {
          if (res.statusCode && res.statusCode >= 200 && res.statusCode < 300) {
            log(`forwarded ${requestId} -> ${res.statusCode} ${body.slice(0, 200)}`);
            resolve();
          } else {
            log(`forward failed ${requestId} -> ${res.statusCode} ${body.slice(0, 200)}`);
            reject(new Error(`HTTP ${res.statusCode}`));
          }
        });
      }
    );
    req.on('error', (err) => {
      log(`forward error ${requestId} -> ${err.message}`);
      reject(err);
    });
    req.write(payload);
    req.end();
  });
}

function cleanupOldRequests() {
  const cutoff = Date.now() - MATCH_WINDOW_MS;
  for (let i = pending.length - 1; i >= 0; i--) {
    if (pending[i].timestamp < cutoff) {
      pending.splice(i, 1);
    }
  }
}

function findPendingByChat(chatId: string | number): PendingRequest | null {
  cleanupOldRequests();
  // Return most recent unanswered request in this chat
  for (let i = pending.length - 1; i >= 0; i--) {
    if (String(pending[i].chatId) === String(chatId) && !pending[i].replied) {
      return pending[i];
    }
  }
  return null;
}

export default async function handler(event: any, ctx: any) {
  log(`event=${event?.type} action=${event?.action} session=${event?.sessionKey}`);

  if (event?.type === 'message:received') {
    const text = getOutboundText(event);
    const chatId = getChatId(event);
    const messageId = getOutboundMessageId(event);
    const requestId = extractRequestId(text);
    log(`received chat=${chatId} msg=${messageId} requestId=${requestId} text=${text.slice(0, 100)}`);

    if (requestId && chatId != null && messageId != null) {
      pending.push({
        requestId,
        chatId,
        messageId,
        timestamp: Date.now(),
        replied: false,
      });
      log(`tracked ${requestId} for chat ${chatId}`);
    }
    return;
  }

  if (event?.type !== 'message:sent') {
    return;
  }

  const replyText = getOutboundText(event);
  const replyToText = getReplyToText(event);
  const chatId = getChatId(event);
  const messageId = getOutboundMessageId(event);

  log(`sent chat=${chatId} msg=${messageId} replyText=${replyText.slice(0, 100)} replyToText=${replyToText.slice(0, 100)}`);

  let requestId = extractRequestId(replyToText);
  let matchedBy: string = 'reply-to';

  if (!requestId && chatId != null) {
    const fallback = findPendingByChat(chatId);
    if (fallback) {
      requestId = fallback.requestId;
      matchedBy = 'fallback';
    }
  }

  if (!requestId) {
    log('no request_id found; skipping');
    return;
  }

  // Mark as replied
  const entry = pending.find((p) => p.requestId === requestId);
  if (entry) entry.replied = true;

  log(`matched request_id=${requestId} via ${matchedBy}`);
  await postReply(requestId, replyText);
}
