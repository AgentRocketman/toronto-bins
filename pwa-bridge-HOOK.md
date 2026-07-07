# pwa-bridge

Forward outbound Telegram replies to the CurbIn voice-assistant PWA backend.

## Subscribes

- message:received
- message:sent

## Description

When the user sends a voice request from `https://agentrocketman.com/assistant/`, the PWA backend posts the transcription to Telegram as `[PWA <request_id>] "<transcription>"`. This hook listens for those inbound messages to remember the request_id, and listens for outbound agent replies so it can forward the reply text to the PWA backend's `reply.php` endpoint.

Matching order:
1. If the agent's outbound message is a reply to the PWA request message, use that request_id.
2. Otherwise, fall back to the most recent unanswered PWA request in the same chat (within a 5-minute window).

## Files

- handler.ts — hook implementation
