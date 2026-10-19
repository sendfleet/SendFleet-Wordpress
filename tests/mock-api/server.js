#!/usr/bin/env node
/*
 * Mock SendFleet API for local and CI testing of the WordPress plugin.
 *
 * Mirrors the public contract of api.sendfleet.net (POST /v1/send, GET /v1/me,
 * GET /health): the same response shapes, error codes, the Free plan Shared
 * smoke-test gate, the monthly quota and the 60/min per-key rate limit.
 * No dependencies. Never point the plugin at this in production.
 *
 * `to`, `cc` and `bcc` take one address or a list, at most 50 together, none
 * repeated; each recipient counts as one send. MULTI=0 in the environment
 * answers a multi-recipient request the way the API does while its
 * MULTI_RECIPIENT_ENABLED flag is off.
 *
 * Special recipients (local part of the first `to`) trigger failure paths:
 *   fail502@…    502 queue_error, always
 *   flaky502@…   502 queue_error on the first attempt, then 200
 *   fail500@…    500 internal_error, always
 *   throttle@…   429 rate limit (Retry-After: 2) on the first attempt, then 200
 *   slow@…       answers after SLOW_MS (default 25 s)
 *
 * Test helpers:
 *   GET  /__requests   every request received (API key redacted)
 *   POST /__reset      clears requests, usage and attempt counters
 */
'use strict';

const http = require('node:http');

const PORT = Number(process.env.PORT || 8080);
const SLOW_MS = Number(process.env.SLOW_MS || 25000);
let MULTI = process.env.MULTI !== '0';
const RECIPIENTS = new WeakMap();
const pad = (s) => s + 'a'.repeat(32);

const ACCOUNTS = {
	[pad('free0001.')]: { name: 'Free Smoke', email: 'Owner@example.com', is_verified: true, plan: 'starter', byoc_configured: false, byoc: [], shared: ['smoke.example.com'] },
	[pad('byoc0001.')]: { name: 'Free BYOC', email: 'owner@byoc.example.com', is_verified: true, plan: 'starter', byoc_configured: true, byoc: ['byoc.example.com'], shared: ['smoke.example.com'] },
	[pad('paid0001.')]: { name: 'Paid BYOC', email: 'owner@paid.example.com', is_verified: true, plan: 'byoc', byoc_configured: true, byoc: ['paid.example.com'], shared: [] },
	[pad('mngd0001.')]: { name: 'Managed', email: 'owner@mail.example.com', is_verified: true, plan: 'growth', byoc_configured: false, byoc: [], shared: ['mail.example.com'] },
	[pad('quota001.')]: { name: 'Quota', email: 'owner@quota.example.com', is_verified: true, plan: 'starter', byoc_configured: true, byoc: ['quota.example.com'], shared: [], preset_usage: 50 },
	[pad('bqta0001.')]: { name: 'Paid BYOC Quota', email: 'owner@bqta.example.com', is_verified: true, plan: 'byoc', byoc_configured: true, byoc: ['bqta.example.com'], shared: [], preset_usage: 1000000 },
	[pad('unvr0001.')]: { name: 'Unverified', email: 'owner@unverified.example.com', is_verified: false, plan: 'growth', byoc_configured: false, byoc: [], shared: ['unverified.example.com'] },
	[pad('susp0001.')]: { name: 'Suspended', email: 'owner@susp.example.com', is_verified: true, plan: 'growth', byoc_configured: false, byoc: [], shared: ['susp.example.com'], suspended: true },
};

const LIMITS = { starter: 50, byoc: 1000000, managed_starter: 5000, growth: 25000, pro: 100000 };
const MANAGED = new Set(['managed_starter', 'growth', 'pro']);
const BLOCKED = new Set('exe bat cmd com msi vbs vbe js jse wsf wsh ps1 ps2 psc1 psc2 reg scr dll hta inf jar lnk pif url ade adp app bas chm cpl crt hlp mde msc msp mst pcd prg scf shs sys vxd wsc xnk'.split(' '));
const ALLOWED = new Set(['application/pdf', 'text/plain', 'text/csv', 'text/html', 'image/png', 'image/jpeg', 'image/gif', 'application/zip', 'application/json', 'application/xml', 'text/calendar', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

let requests = [];
let usage = {};
let attempts = {};
let windows = {};

const json = (res, status, body, headers = {}) => {
	res.writeHead(status, { 'Content-Type': 'application/json', 'x-request-id': 'mock-' + Math.random().toString(16).slice(2, 10), ...headers });
	res.end(JSON.stringify(body));
};
const sendError = (res, status, error, detail) => json(res, status, { success: false, error, detail });
const isEmail = (v) => typeof v === 'string' && /^[^\s@<>()\[\],;:"]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/.test(v.trim());
const norm = (v) => {
	const s = String(v || '').trim();
	const at = s.lastIndexOf('@');
	return at <= 0 || at === s.length - 1 ? null : s.slice(0, at) + '@' + s.slice(at + 1).toLowerCase();
};
const domainOf = (v) => {
	const s = String(v || '').trim().toLowerCase();
	const at = s.indexOf('@');
	return at === -1 ? '' : s.slice(at + 1);
};

function rateLimited(key) {
	const minute = Math.floor(Date.now() / 60000);
	const w = windows[key] && windows[key].minute === minute ? windows[key] : (windows[key] = { minute, count: 0 });
	w.count += 1;
	return w.count > 60 ? 60 - Math.floor((Date.now() / 1000) % 60) : 0;
}

function me(account, key) {
	const u = usage[key] || { byoc: 0, shared: 0 };
	return {
		name: account.name,
		email: account.email,
		is_verified: account.is_verified,
		current_plan: account.plan,
		byoc_usage_current_month: u.byoc + (account.preset_usage || 0),
		shared_usage_current_month: u.shared,
		shared_delivered_current_month: 0,
		shared_bounced_current_month: 0,
		shared_complained_current_month: 0,
		shared_rejected_current_month: 0,
		byoc_configured: account.byoc_configured,
		verified_byoc_domains: account.byoc,
		verified_shared_domains: account.shared,
	};
}

function validate(body) {
	const errors = {};
	const str = (k, max) => {
		if (body[k] === undefined) return;
		if (body[k] === null) { errors[k] = ['This field may not be null.']; return; }
		if (typeof body[k] !== 'string') { errors[k] = ['Not a valid string.']; return; }
		if (max && body[k].trim().length > max) errors[k] = [`Ensure this field has no more than ${max} characters.`];
	};
	const list = (k, required) => {
		const v = body[k];
		if (v === undefined || v === '' ) {
			if (required) errors[k] = [v === '' ? 'This field may not be blank.' : 'This field is required.'];
			return [];
		}
		if (Array.isArray(v)) {
			if (!v.length) { if (required) errors[k] = ['This list may not be empty.']; return []; }
			if (v.length > 50) { errors[k] = ['Ensure this field has no more than 50 elements.']; return []; }
			const per = {};
			v.forEach((a, i) => { if (!isEmail(a)) per[i] = ['Enter a valid email address.']; });
			if (Object.keys(per).length) errors[k] = per;
			return v.map((a) => String(a).trim());
		}
		if (!isEmail(v)) { errors[k] = ['Enter a valid email address.']; return []; }
		return [v.trim()];
	};
	const to = list('to', true);
	const cc = list('cc', false);
	const bcc = list('bcc', false);
	if (body.from_email !== undefined && body.from_email !== '' && !isEmail(body.from_email)) errors.from_email = ['Enter a valid email address.'];
	if (body.reply_to !== undefined && body.reply_to !== '' && !isEmail(body.reply_to)) errors.reply_to = ['Enter a valid email address.'];
	str('from_name', 156); str('subject', 256); str('message'); str('html');
	if (body.attachments !== undefined) {
		if (!Array.isArray(body.attachments)) errors.attachments = ['Expected a list of items but got type "' + typeof body.attachments + '".'];
		else if (body.attachments.length > 20) errors.attachments = ['Ensure this field has no more than 20 elements.'];
		else {
			const per = {};
			body.attachments.forEach((a, i) => {
				const name = String((a && a.filename) || '').trim();
				const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
				if (!name) per[i] = ["Each attachment must have a 'filename'."];
				else if (/[\x00-\x1f\x7f]/.test(name)) per[i] = ['Attachment filename may not contain control characters.'];
				else if (BLOCKED.has(ext)) per[i] = [`File type '.${ext}' is not permitted for security reasons.`];
				else if (!ALLOWED.has(String(a.content_type || '').toLowerCase())) per[i] = [`MIME type '${a.content_type}' for attachment '${name}' is not allowed.`];
				else if (typeof a.content !== 'string' || !/^[A-Za-z0-9+/]+={0,2}$/.test(a.content) || a.content.length % 4 !== 0) per[i] = [`Attachment '${name}' content is not valid base64.`];
			});
			if (Object.keys(per).length) errors.attachments = per;
		}
	}
	if (Object.keys(errors).length) return errors;
	const all = [...to, ...cc, ...bcc];
	if (all.length > 50) return { non_field_errors: ["Provide no more than 50 recipients across 'to', 'cc' and 'bcc' together."] };
	const seen = new Set();
	for (const a of all) {
		if (seen.has(a.toLowerCase())) return { non_field_errors: [`Each recipient may appear only once across 'to', 'cc' and 'bcc'; ${a} appears more than once.`] };
		seen.add(a.toLowerCase());
	}
	RECIPIENTS.set(body, { to, cc, bcc, all });
	const message = typeof body.message === 'string' ? body.message.trim() : '';
	const html = typeof body.html === 'string' ? body.html.trim() : '';
	if (!message && !html && !(body.attachments || []).length) return { non_field_errors: ["Provide at least one of 'message', 'html', or 'attachments'."] };
	return null;
}

async function handleSend(req, res, key, account, body) {
	const errors = validate(body);
	if (errors) return json(res, 400, errors);

	if (account.suspended) return sendError(res, 403, 'sending_suspended', 'Your account\'s email sending has been suspended. Please contact support@sendfleet.net to discuss reinstatement.');

	const { to: toList, cc: ccList, bcc: bccList, all } = RECIPIENTS.get(body);
	if (all.length > 1 && !MULTI) return json(res, 400, { non_field_errors: ['Sending one email to more than one recipient is not available yet. Send one request per recipient.'] });
	const n = all.length;
	const to = toList[0];
	const local = to.split('@')[0].toLowerCase();
	const from = (body.from_email && body.from_email.trim()) || account.email;
	const domain = domainOf(from);
	const isByoc = account.byoc.includes(domain);
	const isShared = account.shared.includes(domain);
	const managed = MANAGED.has(account.plan);

	if (!isByoc && !isShared) return sendError(res, 403, 'domain_not_registered', `Domain '${domain}' is not registered in your account. Add it in the dashboard → Domains.`);
	const mode = managed ? (isShared ? 'shared' : 'byoc') : (isByoc ? 'byoc' : 'shared');
	if (mode === 'shared' && !managed && account.plan !== 'starter') return sendError(res, 403, 'domain_mode_mismatch', `Domain '${domain}' is configured for Shared SES sending but your current plan only allows BYOC.`);
	if (mode === 'byoc' && managed) return sendError(res, 403, 'domain_mode_mismatch', `Domain '${domain}' is configured for BYOC, but your plan uses Shared SES.`);
	if (!account.is_verified) return sendError(res, 403, 'unauthorized', 'Your email address is not verified. Check your inbox for a verification link.');
	if (mode === 'byoc' && !account.byoc_configured) return sendError(res, 403, 'unauthorized', 'BYOC is not configured for this account. Add an IAM Role ARN in account settings first.');

	const smoke = mode === 'shared' && account.plan === 'starter';
	if (smoke) {
		const own = norm(account.email);
		const refusal = `On the Free plan, Shared SES is a smoke test, not a sending path: it only delivers to your account's own verified address (${account.email}).`;
		if (toList.length !== 1 || norm(to) !== own) return sendError(res, 403, 'unauthorized', refusal);
		if (ccList.length || bccList.length) return sendError(res, 403, 'unauthorized', "Cc and Bcc are not available on the Free plan's Shared SES smoke test. " + refusal);
		if (body.reply_to && norm(body.reply_to) !== own && norm(body.reply_to) !== norm(from)) return sendError(res, 403, 'unauthorized', 'On the Free plan\'s Shared SES smoke test, Reply-To can only be your own verified address or the sender address. ' + refusal);
		if (/[\x00-\x1f\x7f]/.test(body.from_name || '') || /[\x00-\x1f\x7f]/.test(body.subject || '')) return sendError(res, 403, 'unauthorized', 'Line breaks and control characters are not allowed in from_name or subject on the Free plan\'s Shared SES smoke test.');
		if (/[<>@,;"]/.test(body.from_name || '')) return sendError(res, 403, 'unauthorized', 'from_name cannot contain an address or the characters < > @ , ; " on the Free plan\'s Shared SES smoke test.');
	}

	const u = usage[key] || (usage[key] = { byoc: 0, shared: 0 });
	const limit = LIMITS[account.plan];
	const used = account.plan === 'starter' || account.plan === 'byoc' ? u.byoc + (account.preset_usage || 0) : u.shared;
	if (limit !== null && limit !== undefined && used >= limit) {
		return sendError(res, 429, 'usage_limit_exceeded', `Monthly limit of ${limit} emails reached for this calendar month (UTC).`);
	}
	if (limit !== null && limit !== undefined && used + n > limit) {
		return sendError(res, 429, 'usage_limit_exceeded', `This email has ${n} recipients and each counts as one send, but only ${limit - used} of your plan's ${limit} emails are left this calendar month (UTC).`);
	}

	const tries = (attempts[to.toLowerCase()] = (attempts[to.toLowerCase()] || 0) + 1);
	if (local === 'fail502' || (local === 'flaky502' && tries === 1)) return sendError(res, 502, 'queue_error', 'Could not queue email for delivery. Please try again.');
	if (local === 'fail500') return sendError(res, 500, 'internal_error', 'An unexpected error occurred. Please try again.');
	if (local === 'throttle' && tries === 1) return json(res, 429, { detail: 'Request was throttled. Expected available in 2 seconds.' }, { 'Retry-After': '2' });
	if (local === 'slow') await new Promise((r) => setTimeout(r, SLOW_MS));

	if (account.plan === 'starter' || mode === 'byoc') u.byoc += n; else u.shared += n;

	const id = require('node:crypto').randomUUID();
	const out = { success: true, message: mode === 'shared' ? 'Email queued for delivery.' : 'Email queued for delivery via your SES account.', message_id: id, mode };
	if (mode === 'shared') {
		out.idempotency_key = 'client:' + require('node:crypto').randomUUID();
		out.warnings = ['DMARC record not found. Add \'v=DMARC1; p=none;\' as a TXT record on _dmarc.' + domain + ' to improve inbox placement.'];
		if (smoke) out.warnings.push('Free plan Shared smoke test: delivered only to your own verified address, no webhooks fired. To send to anyone else, use a BYOC domain or a Managed plan.');
	}
	if (Array.isArray(body.attachments) && body.attachments.length) out.attachments = body.attachments.length;
	if (n > 1) out.recipients = n;
	return json(res, 200, out);
}

const server = http.createServer((req, res) => {
	const chunks = [];
	req.on('data', (c) => chunks.push(c));
	req.on('end', async () => {
		const raw = Buffer.concat(chunks).toString('utf8');
		const url = new URL(req.url, 'http://mock');
		const path = url.pathname.replace(/\/$/, '');

		if (path === '/__requests') return json(res, 200, requests);
		if (path === '/__reset' && req.method === 'POST') { requests = []; usage = {}; attempts = {}; windows = {}; MULTI = process.env.MULTI !== '0'; return json(res, 200, { ok: true }); }
		if (path === '/__multi' && req.method === 'POST') { MULTI = raw.trim() !== '0'; return json(res, 200, { multi: MULTI }); }
		if (path === '/health') return json(res, 200, { status: 'ok', version: 'mock' });

		const key = req.headers['x-api-key'] || '';
		let body = null;
		try { body = raw ? JSON.parse(raw) : {}; } catch (e) { body = undefined; }
		requests.push({ time: new Date().toISOString(), method: req.method, path, key_prefix: String(key).split('.')[0], user_agent: req.headers['user-agent'], content_type: req.headers['content-type'], body: body === undefined ? raw.slice(0, 500) : body });

		if (path !== '/v1/send' && path !== '/v1/me') return json(res, 404, { detail: 'Not found.' });
		const account = ACCOUNTS[key];
		if (!account) return json(res, 403, { detail: 'Authentication credentials were not provided.' });
		const wait = rateLimited(key);
		if (wait) return json(res, 429, { detail: `Request was throttled. Expected available in ${wait} seconds.` }, { 'Retry-After': String(wait) });

		if (path === '/v1/me') {
			if (req.method !== 'GET') return json(res, 405, { detail: `Method "${req.method}" not allowed.` });
			return json(res, 200, me(account, key));
		}
		if (req.method !== 'POST') return json(res, 405, { detail: `Method "${req.method}" not allowed.` });
		if (body === undefined) return json(res, 400, { detail: 'JSON parse error - invalid JSON' });
		return handleSend(req, res, key, account, body);
	});
});

server.listen(PORT, () => console.log(`mock SendFleet API listening on :${PORT}`));
