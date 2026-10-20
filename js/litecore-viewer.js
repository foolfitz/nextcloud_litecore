/*
 * The viewer route's host script: the read-only spreadsheet page's host entry,
 * for Nextcloud (study_LiteCore SPEC-M4-ods-in-nextcloud §3.4). It puts
 * `globalThis.liteCoreViewerHost = { load }` in place and only then imports the
 * shipped viewer module, which reads it once and checks its shape:
 *
 *   load()  -- ONE WebDAV GET of the file's bytes. No LOCK, no PUT, no MOVE,
 *              no PROPPATCH, no version, no save: a viewer has no way to write,
 *              and the page refuses a host that offers one (the editor's
 *              contract under this name would be a bug, not a feature).
 *
 * Everything observed is published on <html data-litecore-viewer> for a driver
 * (H2 reads the server's log for the absence of writes; H3 reads the page).
 */
(function () {
	'use strict'

	const out = { started: Date.now(), done: false, steps: [] }
	const step = (name, detail) => { out.steps.push({ step: name, at: Date.now() - out.started, ...detail }); publish() }
	const publish = () => document.documentElement.setAttribute('data-litecore-viewer', JSON.stringify(out))

	function readConfig() {
		const state = document.getElementById('initial-state-litecore-config')
		return JSON.parse(atob(state.value))
	}

	async function hex(buffer) {
		const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))
		return Array.from(digest, (b) => b.toString(16).padStart(2, '0')).join('')
	}

	// X-Requested-With: without it an expired session's 401 carries
	// `WWW-Authenticate: Basic` and the browser pops a credentials dialog.
	const headers = () => ({ 'X-Requested-With': 'XMLHttpRequest' })
	const failure = (code, message) => Object.assign(new Error(message), { code })
	let config = null

	// The page's own state, as the shipped page publishes it (window.__odsViewer).
	function pageState() {
		const record = globalThis.__odsViewer
		const s = record && record.state
		return {
			state: s ? s.state : null,
			parts: s ? s.parts : null,
			documentType: s ? s.documentType : null,
			currentPart: s ? s.currentPart : null,
			currentPartReadBack: s ? s.currentPartReadBack : null,
			adapterFallbacks: s ? s.adapterFallbacks : null,
			error: s && s.error ? s.error.code : null,
			hostError: record && record.hostError ? record.hostError.code : null,
		}
	}

	async function until(predicate, timeoutMs) {
		const deadline = Date.now() + timeoutMs
		for (;;) {
			const s = pageState()
			if (predicate(s)) return s
			if (Date.now() > deadline) return { ...s, timedOut: true }
			await new Promise((resolve) => setTimeout(resolve, 200))
		}
	}

	// The host's bar: the file's name and the way back to the file's folder in Files.
	function titleBar() {
		document.getElementById('litecore-host-title').textContent = config.file.name
		document.getElementById('litecore-host-close').addEventListener('click', () => { location.assign(config.filesUrl) })
	}

	// Put in place by run(), before the product module is imported. Frozen: the
	// page checks that nothing writable is on it, and nothing may be added later.
	const host = Object.freeze({
		async load() {
			const response = await fetch(config.file.davUrl, {
				credentials: 'same-origin', cache: 'no-store', headers: headers(),
			})
			const bytes = await response.arrayBuffer()
			out.fetched = {
				status: response.status, contentType: response.headers.get('content-type'),
				etag: response.headers.get('oc-etag') || response.headers.get('etag'),
				bytes: bytes.byteLength, sha256: await hex(bytes), at: Date.now() - out.started,
			}
			step('fetched')
			if (!response.ok) throw failure('HOST_LOAD_FAILED', `WebDAV GET ${response.status}`)
			return { bytes, name: config.file.name }
		},
	})

	async function run() {
		config = readConfig()
		// No @scope (Firefox before 146, ESR 140): the scoped sheet is dropped whole,
		// so the page's own unscoped copy goes on (templates/viewer.php).
		out.scope = typeof CSSScopeRule === 'function' ? 'scoped' : 'unscoped-fallback'
		if (out.scope !== 'scoped') document.getElementById('litecore-unscoped')?.setAttribute('media', 'all')
		globalThis.liteCoreViewerHost = host
		titleBar()
		out.file = { id: config.file.id, name: config.file.name, size: config.file.size, etag: config.file.etag }
		out.isolation = {
			crossOriginIsolated: self.crossOriginIsolated === true,
			sharedArrayBuffer: typeof SharedArrayBuffer === 'function',
		}
		out.href = location.href
		await import(new URL(config.page.module, location.href).href)
		step('imported')
		const opened = await until((s) => ['ready', 'refused', 'error'].includes(s.state) || s.hostError, 240000)
		step('opened', { state: opened })
	}

	const start = () => run()
		.catch((error) => { out.error = `${error && error.name}: ${error && error.message}` })
		.finally(() => { out.done = true; out.finished = Date.now() - out.started; publish() })
	if (document.readyState === 'complete') start()
	else window.addEventListener('load', start, { once: true })
}())
