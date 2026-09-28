/*
 * The editor route's host script. Imports the shipped editor module, then opens
 * the user's file in it: the browser fetches the bytes over WebDAV (no server-side
 * document processing) and hands them to the product through the product's
 * own "open a file" path -- its hidden <input type=file> -- because the page
 * exposes no other entry. That is the skeleton's bridge, not the design: the
 * product boots a bundled sample first, and saving back needs an entry the page
 * does not have (study_LiteCore handoff, M3 skeleton).
 *
 * Everything observed is published on <html data-litecore> for a driver.
 */
(function () {
	'use strict'

	const out = { started: Date.now(), done: false, steps: [] }
	const step = (name, detail) => out.steps.push({ step: name, at: Date.now() - out.started, ...detail })
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

	const settled = (s) => ['stopped', 'expired', 'restart-required'].includes(s.state)

	async function until(predicate, timeoutMs) {
		const deadline = Date.now() + timeoutMs
		for (;;) {
			const s = productState()
			if (predicate(s) || settled(s)) return s
			if (Date.now() > deadline) return { ...s, timedOut: true }
			await new Promise((resolve) => setTimeout(resolve, 200))
		}
	}

	async function hex(buffer) {
		const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))
		return Array.from(digest, (b) => b.toString(16).padStart(2, '0')).join('')
	}

	async function run() {
		const config = readConfig()
		out.file = { id: config.file.id, name: config.file.name, size: config.file.size, etag: config.file.etag }
		out.isolation = {
			crossOriginIsolated: self.crossOriginIsolated === true,
			sharedArrayBuffer: typeof SharedArrayBuffer === 'function',
		}
		out.href = location.href

		await import(new URL(config.editor.module, location.href).href)
		step('imported')

		// The product opens its bundled sample on boot. Opening ours while that
		// open is still in flight would race two sessions, so wait for it.
		const booted = await until((s) => s.state === 'ready' && s.doc && s.doc !== '—', 240000)
		step('product-booted', { state: booted })
		if (booted.state !== 'ready') throw new Error(`product did not boot: ${booted.state}`)

		const response = await fetch(config.file.davUrl, {
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { requesttoken: document.head.dataset.requesttoken || '' },
		})
		const bytes = await response.arrayBuffer()
		out.fetched = {
			status: response.status,
			contentType: response.headers.get('content-type'),
			etag: response.headers.get('etag'),
			bytes: bytes.byteLength,
			sha256: await hex(bytes),
		}
		step('fetched')
		if (!response.ok) throw new Error(`WebDAV GET ${response.status}`)

		const input = document.querySelector('#file')
		const transfer = new DataTransfer()
		transfer.items.add(new File([bytes], config.file.name, { type: 'application/vnd.oasis.opendocument.text' }))
		input.files = transfer.files
		input.dispatchEvent(new Event('change'))
		const opened = await until((s) => s.state === 'ready' && s.doc === config.file.name, 120000)
		step('opened', { state: opened })
		out.opened = opened.state === 'ready' && opened.doc === config.file.name
	}

	const start = () => run()
		.catch((error) => { out.error = `${error && error.name}: ${error && error.message}` })
		.finally(() => { out.done = true; out.finished = Date.now() - out.started; publish() })
	if (document.readyState === 'complete') start()
	else window.addEventListener('load', start, { once: true })
}())
