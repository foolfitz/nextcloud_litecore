/*
 * The editor route's host script: the page's host entry, for Nextcloud
 * (study_LiteCore specs/SPEC-M3-host-entry.md). It puts `globalThis.liteCoreHost`
 * in place and only then imports the shipped editor module, which reads it once:
 *
 *   load()  -- WebDAV GET of the user's file; the ETag is kept.
 *   save()  -- WebDAV PUT with If-Match on that ETag. A 412 means the file changed
 *              since it was opened (or was moved away): nothing is overwritten and
 *              the page is told so. On success the new ETag replaces the old one.
 *
 * No server-side document processing: the bytes go browser <-> WebDAV.
 * Everything observed is published on <html data-litecore> for a driver.
 */
(function () {
	'use strict'

	const ODT = 'application/vnd.oasis.opendocument.text'
	const out = { started: Date.now(), done: false, steps: [], saves: [] }
	const step = (name, detail) => { out.steps.push({ step: name, at: Date.now() - out.started, ...detail }); publish() }
	const publish = () => document.documentElement.setAttribute('data-litecore', JSON.stringify(out))

	function readConfig() {
		const state = document.getElementById('initial-state-litecore-config')
		return JSON.parse(atob(state.value))
	}

	function productState() {
		const pill = document.querySelector('#state-pill')
		return {
			state: pill ? pill.dataset.state : null,
			doc: (document.querySelector('#s-doc') || {}).textContent || null,
			revision: (document.querySelector('#s-revision') || {}).textContent || null,
			toast: (document.querySelector('#toast') || {}).textContent || null,
		}
	}

	async function until(predicate, timeoutMs) {
		const deadline = Date.now() + timeoutMs
		for (;;) {
			const s = productState()
			if (predicate(s) || ['stopped', 'expired', 'restart-required'].includes(s.state)) return s
			if (Date.now() > deadline) return { ...s, timedOut: true }
			await new Promise((resolve) => setTimeout(resolve, 200))
		}
	}

	async function hex(buffer) {
		const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))
		return Array.from(digest, (b) => b.toString(16).padStart(2, '0')).join('')
	}

	const token = () => document.head.dataset.requesttoken || ''
	let config = null
	let etag = null

	// Put in place by run(), before the product module is imported.
	const host = {
		async load() {
			const response = await fetch(config.file.davUrl, {
				credentials: 'same-origin', cache: 'no-store', headers: { requesttoken: token() },
			})
			const bytes = await response.arrayBuffer()
			etag = response.headers.get('etag')
			out.fetched = {
				status: response.status, contentType: response.headers.get('content-type'),
				etag, bytes: bytes.byteLength, sha256: await hex(bytes),
			}
			step('fetched')
			if (!response.ok) throw Object.assign(new Error(`WebDAV GET ${response.status}`), { code: 'HOST_LOAD_FAILED' })
			return { bytes, name: config.file.name }
		},

		async save(bytes, { name }) {
			const record = { at: Date.now() - out.started, name, bytes: bytes.byteLength, sha256: await hex(bytes), ifMatch: etag }
			out.saves.push(record)
			const headers = { requesttoken: token(), 'Content-Type': ODT }
			if (etag) headers['If-Match'] = etag
			const response = await fetch(config.file.davUrl, { method: 'PUT', credentials: 'same-origin', headers, body: bytes })
			record.status = response.status
			record.etag = response.headers.get('etag')
			publish()
			if (response.status === 412) {
				throw Object.assign(new Error('檔案在你開啟之後已被別處修改或移走，沒有覆寫。請重新開啟。'), { code: 'CONFLICT' })
			}
			if (!response.ok) throw Object.assign(new Error(`WebDAV PUT ${response.status}`), { code: 'HOST_SAVE_FAILED' })
			if (!record.etag) {
				// Without the new ETag the next save would be refused as a conflict.
				const head = await fetch(config.file.davUrl, { method: 'HEAD', credentials: 'same-origin', headers: { requesttoken: token() } })
				record.etag = head.headers.get('etag')
				record.etagFrom = 'HEAD'
				publish()
			}
			etag = record.etag
		},
	}

	async function run() {
		config = readConfig()
		globalThis.liteCoreHost = host
		out.file = { id: config.file.id, name: config.file.name, size: config.file.size, etag: config.file.etag }
		out.isolation = {
			crossOriginIsolated: self.crossOriginIsolated === true,
			sharedArrayBuffer: typeof SharedArrayBuffer === 'function',
		}
		out.href = location.href
		await import(new URL(config.editor.module, location.href).href)
		step('imported')
		const opened = await until((s) => s.state === 'ready' && s.doc === config.file.name, 240000)
		step('opened', { state: opened })
	}

	const start = () => run()
		.catch((error) => { out.error = `${error && error.name}: ${error && error.message}` })
		.finally(() => { out.done = true; out.finished = Date.now() - out.started; publish() })
	if (document.readyState === 'complete') start()
	else window.addEventListener('load', start, { once: true })
}())
