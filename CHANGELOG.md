# Changelog

All notable changes to this package are listed here. It follows [Semantic Versioning](https://semver.org).

## 1.1.0 — Unreleased

- `Threadwire::events()`: poll for the webhook's events (`GET /v1/events`), oldest first after a cursor (`after`, `next_after`, `has_more`), with `types` and `instance_id` filters; for systems without a public webhook address, and to catch up on missed events.
- `Threadwire::chatHistory()`: a chat's history from the linked phone, newest first.

## 1.0.0 — 2026-10-04

First release.

- Send texts, files, locations, contact cards and polls through your linked WhatsApp numbers, to a phone or a group.
- Read, list and cancel messages; list numbers with their protection status; list chats.
- Verify phone numbers with WhatsApp: the link flow (the person sends the code to you) and the code flow.
- Receive Threadwire's signed webhooks on a ready-made route, verified (Standard Webhooks, with secret rotation), as Laravel events.
- Typed exceptions carrying the API's plain-words reason, field errors and `Retry-After`.
