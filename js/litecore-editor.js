/*
 * The editor route's host script: the page's host entry, for Nextcloud
 * (study_LiteCore specs/SPEC-M3-host-entry.md). It puts `globalThis.liteCoreHost`
 * in place and only then imports the shipped editor module, which reads it once:
 *
 *   load()  -- WebDAV GET of the user's file; the ETag is kept.
 *   save()  -- WebDAV PUT with If-Match on that ETag; never unconditional. A 412
 *              means the file changed since it was opened (or was moved away):
 *              nothing is overwritten, the bytes are saved next to it as a
 *              conflict copy, and the page is told both. On success the new ETag
 *              replaces the old one, so the next save chains on it.
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

	// X-Requested-With: without it an expired session's 401 carries
	// `WWW-Authenticate: Basic` and the browser pops a credentials dialog.
	const headers = (extra) => ({ requesttoken: document.head.dataset.requesttoken || '', 'X-Requested-With': 'XMLHttpRequest', ...extra })
	// OC-ETag first: Nextcloud copies the ETag into it for setups whose proxy
	// strips or rewrites ETag (apps/dav CopyEtagHeaderPlugin).
	const versionOf = (response) => response.headers.get('oc-etag') || response.headers.get('etag')
	const conflict = (message) => Object.assign(new Error(message), { code: 'CONFLICT' })
	let config = null
	let etag = null

	// The user's bytes never get stranded by a conflict: they go next to the
	// file as a new one, which is created only if that name is free.
	async function saveConflictCopy(bytes) {
		const slash = config.file.davUrl.lastIndexOf('/')
		const folder = config.file.davUrl.slice(0, slash + 1)
		const stem = config.file.name.replace(/\.odt$/i, '')
		const now = new Date()
		const pad = (n) => String(n).padStart(2, '0')
		const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
		for (let attempt = 1; attempt <= 5; attempt++) {
			const name = `${stem}（衝突副本 ${stamp}${attempt > 1 ? `-${attempt}` : ''}）.odt`
			const response = await fetch(folder + encodeURIComponent(name), {
				method: 'PUT', credentials: 'same-origin', body: bytes,
				headers: headers({ 'Content-Type': ODT, 'If-None-Match': '*' }),
			})
			if (response.ok) return { name, status: response.status }
			if (response.status !== 412) return { name, status: response.status, failed: true }
		}
		return { failed: true, status: 412 }
	}

	// Put in place by run(), before the product module is imported.
	const host = {
		async load() {
			const response = await fetch(config.file.davUrl, {
				credentials: 'same-origin', cache: 'no-store', headers: headers(),
			})
			const bytes = await response.arrayBuffer()
			etag = versionOf(response)
			out.fetched = {
				status: response.status, contentType: response.headers.get('content-type'),
				etag, bytes: bytes.byteLength, sha256: await hex(bytes),
			}
			step('fetched')
			if (!response.ok) throw Object.assign(new Error(`WebDAV GET ${response.status}`), { code: 'HOST_LOAD_FAILED' })
			// Without a version there is no conditional write, and an unconditional
			// one overwrites whatever changed since. Refuse rather than fail open.
			if (!etag) throw Object.assign(new Error('伺服器沒有給這個檔案的版本標記，無法安全存回。'), { code: 'HOST_LOAD_FAILED' })
			return { bytes, name: config.file.name }
		},

		async save(bytes, { name }) {
			const record = { at: Date.now() - out.started, name, bytes: bytes.byteLength }
			out.saves.push(record)
			try {
				await saveOnce(bytes, record)
			} finally {
				// Everything this save will do is done, conflict copy included.
				record.settled = true
				publish()
			}
		},
	}

	async function saveOnce(bytes, record) {
		record.sha256 = await hex(bytes)
		if (!etag) throw Object.assign(new Error('不知道伺服器上的版本，不做無條件覆寫。'), { code: 'HOST_SAVE_FAILED' })
		record.ifMatch = etag
		const response = await fetch(config.file.davUrl, {
			method: 'PUT', credentials: 'same-origin', body: bytes,
			headers: headers({ 'Content-Type': ODT, 'If-Match': etag }),
		})
		record.status = response.status
		publish()
		if (response.status === 412) {
			record.responseEtag = versionOf(response)
			record.conflictCopy = await saveConflictCopy(bytes)
			publish()
			if (record.conflictCopy.failed) {
				throw conflict(`檔案在你開啟之後已被別處修改或移走，沒有覆寫；另存副本也失敗（HTTP ${record.conflictCopy.status}）。`)
			}
			throw conflict(`檔案在你開啟之後已被別處修改或移走，沒有覆寫。你的內容另存為「${record.conflictCopy.name}」。`)
		}
		if (!response.ok) throw Object.assign(new Error(`WebDAV PUT ${response.status}`), { code: 'HOST_SAVE_FAILED' })
		record.etag = versionOf(response)
		if (!record.etag) {
			// Without the new version the next save would be refused as a conflict.
			const head = await fetch(config.file.davUrl, { method: 'HEAD', credentials: 'same-origin', headers: headers() })
			record.etag = versionOf(head)
			record.etagFrom = 'HEAD'
		}
		etag = record.etag
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
