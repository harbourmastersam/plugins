# Database Viewer AI Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Studio's SQL assistant work inside the Pelican Database Viewer through an authenticated, server-mediated Cloudflare Workers AI path.

**Architecture:** Studio sends a bounded `ai` postMessage envelope through the existing channel-bound iframe bridge. Pelican reauthorizes the viewer and forwards a strict request to a protected internal route on the Studio Worker; that route verifies a shared bearer secret with timing-safe comparison and invokes a fixed Workers AI model through an `AI` binding.

**Tech Stack:** Next.js/TypeScript/Jest, OpenNext Cloudflare, Workers AI, Pelican PHP/Laravel, PHPUnit, browser JavaScript tests.

**Spec:** Approved conversation design from 2026-09-28; schema metadata and prompts may be sent to Cloudflare Workers AI.

## Global Constraints

- Database credentials and AI broker credentials never enter Studio, iframe URLs, HTML, logs, or postMessage payloads.
- Every Pelican AI request repeats existing user/server/database/channel authorization.
- The model is fixed to `@cf/meta/llama-3.3-70b-instruct-fp8-fast` in the Worker.
- Only SQL-generation message roles and bounded strings are accepted; errors remain generic.
- Existing database query and schema-bootstrap policy remains unchanged.

## Review Focus

- Forged, expired, or cross-database channels must fail before an outbound AI request.
- Duplicate, sparse, oversized, malformed, or extra-key AI envelopes must fail closed.
- Missing or incorrect Worker bearer secrets must not invoke Workers AI.
- Worker model failures and malformed outputs must not disclose secrets or prompt contents.
- Existing iframe reload/window/document binding must still prevent stale replies.

---

### Task 1: Studio AI transport and managed agent

**Files:**
- Create: `E:/Users/Admin/Documents/ChatGPT/studio/src/drivers/agent/pelican.ts`
- Create: `E:/Users/Admin/Documents/ChatGPT/studio/src/drivers/agent/pelican.test.ts`
- Modify: `E:/Users/Admin/Documents/ChatGPT/studio/src/drivers/agent/list.tsx`
- Modify: `E:/Users/Admin/Documents/ChatGPT/studio/src/app/(theme)/embed/[driver]/page-client.tsx`
- Modify: `E:/Users/Admin/Documents/ChatGPT/studio/src/drivers/iframe-driver.ts`
- Modify: `E:/Users/Admin/Documents/ChatGPT/studio/src/drivers/iframe-driver.test.ts`

**Interfaces:**
- Produces an iframe AI request `{type:"ai", id, channel, document, messages}` and expects `{type:"ai", ..., data:{response:string}}`.
- Exposes one available managed model named `llama-3.3-70b` in embedded MySQL mode.

- [ ] Add failing transport tests for exact envelopes, response validation, stale-document rejection, timeout cleanup, and generic errors.
- [ ] Run focused Jest tests and confirm the missing AI transport fails.
- [ ] Implement the minimal iframe AI transport and Pelican agent driver.
- [ ] Run focused tests and the full Studio Jest suite.

### Task 2: Pelican bridge and authenticated AI broker

**Files:**
- Create: `database-viewer/src/Services/AiBroker.php`
- Create: `database-viewer/tests/AiBrokerTest.php`
- Modify: `database-viewer/resources/js/bridge.mjs`
- Modify: `database-viewer/tests/bridge.test.mjs`
- Modify: `database-viewer/src/Http/ViewerController.php`
- Modify: `database-viewer/routes/web.php`
- Modify: `database-viewer/config/database-viewer.php`
- Modify: `database-viewer/tests/ViewerTest.php`

**Interfaces:**
- Accepts exact `{type:"ai", channel, messages}` JSON at the selected database route.
- Forwards only roles `system`, `user`, and `assistant`, with at most 12 messages, 32 KiB request body, 24 KiB combined content, and a 16 KiB response.

- [ ] Add failing browser bridge tests for valid AI forwarding and malformed/oversized/reload cases.
- [ ] Add failing PHP tests proving reauthorization, exact shape validation, limits, safe errors, and no prompt logging.
- [ ] Implement bridge support, the controller endpoint, configuration, and injectable AI broker.
- [ ] Run JS tests, PHPUnit, and package tests.

### Task 3: Protected Workers AI endpoint

**Files:**
- Create: `E:/Users/Admin/Documents/ChatGPT/studio/worker.ts`
- Create: `E:/Users/Admin/Documents/ChatGPT/studio/src/lib/internal-ai.ts`
- Create: `E:/Users/Admin/Documents/ChatGPT/studio/src/lib/internal-ai.test.ts`
- Modify: `E:/Users/Admin/Documents/ChatGPT/studio/wrangler.jsonc`

**Interfaces:**
- Accepts only `POST /internal/ai` with `Authorization: Bearer <DATABASE_VIEWER_AI_TOKEN>`.
- Returns `{response:string}` after fixed-model `env.AI.run`; all other requests delegate to OpenNext.

- [ ] Add failing unit tests for method/path, constant-time auth behavior, content types, request limits, AI invocation, output limits, and safe failures.
- [ ] Implement the handler and custom Worker wrapper, then add the `AI` binding and secret type generation.
- [ ] Run focused tests, typecheck, lint, Cloudflare build, and Wrangler dry run.

### Task 4: Release, deployment, and verification

**Files:**
- Modify: `database-viewer/plugin.json`
- Modify: `database-viewer/README.md`
- Modify: `database-viewer/verification.md`
- Modify: `tools/package-database-viewer.py`
- Modify: Studio embedding/Cloudflare documentation as needed.

- [ ] Bump the plugin to `0.3.0`, update documentation, package allowlist, deterministic ZIP, and checksum.
- [ ] Generate a cryptographically random shared token; store it as the Cloudflare Worker secret and report the corresponding Pelican environment setting without printing the value.
- [ ] Deploy the Worker and verify denied public/internal requests plus unchanged iframe behavior.
- [ ] Commit and push Studio `develop` and plugins `main` after clean full-suite verification.
- [ ] Report changed files, tests, deployment version, artifact path/checksum, configuration action still required on Pelican, and limitations.
