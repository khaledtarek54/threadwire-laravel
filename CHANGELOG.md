# Changelog

All notable changes to this package are listed here. It follows [Semantic Versioning](https://semver.org).

## 2.1.0 — 2026-10-19

- `ThreadwireException::$errorCode`: Threadwire's fixed `code` for a refusal (`not_connected`, `limit_reached`, `backlog_full`…), beside the message, on every exception the package throws. Nothing else changes.
- The README says what is true since Threadwire made sending protection a per-number choice: it is off until you turn it on (`protection_settings.enabled`).

## 2.0.1 — 2026-10-12

- Threadwire's address is `threadwire.net`: the default `THREADWIRE_URL` is now `https://threadwire.net/api/v1`. The former address, `https://threadwire.tri-tech.net/api/v1`, keeps answering with the same keys, so nothing changes for an app that sets it or stays on 2.0.0.

## 2.0.0 — 2026-10-05

Threadwire renamed "verification" to "OTP" across its API, so this release is not backwards compatible. The 1.x methods below call addresses that no longer exist.

- `createVerification()`, `verification()`, `checkVerification()`, `resendVerification()` and `cancelVerification()` are now `createOtp()`, `otp()`, `checkOtp()`, `resendOtp()` and `cancelOtp()`; `sendVerificationCode()` is `sendOtpCode()`. `verifyByLink()` keeps its name.
- The API paths are `/v1/otps…`, and the webhook events `otp.completed` and `otp.failed`, as `Threadwire\Events\OtpCompleted` and `Threadwire\Events\OtpFailed` (were `VerificationCompleted` and `VerificationFailed`).
- A message's `origin` for a code is `otp` (was `verification`).

## 1.2.0 — 2026-10-05

- Broadcasts: `Threadwire::createBroadcast()` (to everyone who wrote recently, a chat label, or your own list filtered to people who wrote, with placeholders), `broadcast()`, `broadcasts()`, `pauseBroadcast()`, `resumeBroadcast()` and `cancelBroadcast()`.
- Webhook events `broadcast.paused` and `broadcast.completed`, as `Threadwire\Events\BroadcastPaused` and `Threadwire\Events\BroadcastCompleted`.

## 1.1.0 — 2026-10-04

- `Threadwire::events()`: poll for the webhook's events (`GET /v1/events`), oldest first after a cursor (`after`, `next_after`, `has_more`), with `types` and `instance_id` filters; for systems without a public webhook address, and to catch up on missed events.
- `Threadwire::chatHistory()`: a chat's history from the linked phone, newest first.

## 1.0.0 — 2026-10-04

First release.

- Send texts, files, locations, contact cards and polls through your linked WhatsApp numbers, to a phone or a group.
- Read, list and cancel messages; list numbers with their protection status; list chats.
- Verify phone numbers with WhatsApp: the link flow (the person sends the code to you) and the code flow.
- Receive Threadwire's signed webhooks on a ready-made route, verified (Standard Webhooks, with secret rotation), as Laravel events.
- Typed exceptions carrying the API's plain-words reason, field errors and `Retry-After`.
