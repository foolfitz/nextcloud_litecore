/*
 * The editor route's host script: the page's host entry, for Nextcloud
 * (study_LiteCore specs/SPEC-M3-host-entry.md, and SPEC-M3-locks-and-permissions.md
 * for the lock and the ways out). It puts `globalThis.liteCoreHost` in place and
 * only then imports the shipped editor module, which reads it once:
 *
 *   load()  -- a WebDAV token LOCK first (300 s, refreshed every 60 s), then the
 *              GET, so the ETag kept is the one after locking: taking a lock
 *              moves the file's ETag, refreshing it does not. A refresh asks
 *              for elapsed + 300 s, because files_lock counts the timeout from
 *              when the lock was taken, not from the refresh (finding 101).
 *   save()  -- WebDAV PUT with If-Match on that ETag, never unconditional, plus
 *              the lock token while one is held. Not within a second of our
 *              previous PUT's answer, and only after reading the file back:
 *              the same version with other bytes is a write If-Match cannot
 *              see (study_LiteCore findings 103, 104). A 412 while carrying the token
 *              may only mean the lock died; if the token it carried is gone
 *              from the server, it is retried once without it.
 *              Any other refusal, or no network: the bytes go next to the file
 *              as a copy; if even that fails they stay here, behind a download
 *              button, and leaving the page asks first. So does a save that
 *              throws for any other reason: every save ends saved, copied or
 *              stranded.
 *   leaving  -- UNLOCK, keepalive, at beforeunload (unless leaving is being
 *              asked about) and again at pagehide. A crashed tab's lock
 *              lapses by itself. Leaving is asked about when bytes are
 *              stranded, a save is in flight, or the page says it holds edits
 *              nobody saved (watchUnsaved, study_LiteCore finding 107): this
 *              listener is the only one that decides, because it is the one
 *              that would release the lock.
 *   readOnly -- a file the user may not write is handed to the page with
 *              `readOnly: true` and opens view-only; no lock is taken.
 *
 * No server-side document processing: the bytes go browser <-> WebDAV.
 * Everything observed is published on <html data-litecore> for a driver.
 */
(function () {
	'use strict'

	const ODT = 'application/vnd.oasis.opendocument.text'
	const LOCK_SECONDS = 300
	const REFRESH_MS = 60000
	// Only these say the lock is gone or someone else has it; anything else
	// (5xx, 401, 429, no answer) leaves a live lock alone.
	const LOST = [400, 404, 409, 412, 423]
	const xml = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
	const STRANDED = '存回失敗，而且沒有地方可以另存：內容還在這個頁面裡，請按「下載這份內容」。'
	const DOWNLOADED = '已交給瀏覽器下載。請確認檔案真的存好了；在下一次存回成功之前，離開這頁都會先問你。'
	const out = { started: Date.now(), done: false, steps: [], saves: [], lock: { state: 'none' }, stranded: null, notice: '' }
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
	const failure = (code, message) => Object.assign(new Error(message), { code })
	// A request that never got an answer has a status too: 'network'.
	async function send(url, init) {
		try {
			return await fetch(url, { credentials: 'same-origin', ...init })
		} catch (error) {
			return { ok: false, status: 'network', error: String(error), headers: new Headers() }
		}
	}
	let config = null
	let etag = null
	let token = null
	let refreshTimer = null
	let stranded = null
	let lockNotice = ''
	let lockedAt = 0
	let inFlight = 0
	// The bytes `etag` belongs to: what load() read, or the last save that landed.
	let lastSha = null
	// performance.now() when our previous PUT to the file was answered.
	let lastAnswered = null
	// The lock token our latest PUT carried, if any. Not `token`: a refresh
	// answered while that PUT was out can drop `token` to null (loseLock).
	let sentToken = null
	// The page's answer to "are there edits nobody saved?" (watchUnsaved).
	let pageUnsaved = null

	// The host's own line above the product: what the lock or the permissions
	// mean for saving, and -- while bytes have nowhere to go -- the way out.
	function render() {
		out.notice = stranded ? (stranded.downloaded ? DOWNLOADED : STRANDED) : lockNotice
		document.getElementById('litecore-host-notice').textContent = out.notice
		const button = document.getElementById('litecore-host-download')
		button.hidden = !stranded
		button.textContent = stranded && stranded.downloaded ? '再下載一次' : '下載這份內容'
		document.getElementById('litecore-host-bar').hidden = !out.notice
		publish()
	}
	const setLockNotice = (text) => { lockNotice = text; render() }

	// The title (study_LiteCore finding 110): the file's name where the page's own
	// brand line was (hidden by templates/editor.php), whether the page holds
	// edits nobody saved, and a way back to the file's folder in Files. Closing is
	// an ordinary navigation, so leaving asks exactly as any other way out does
	// (beforeunload below decides). The page's answer is read twice a second
	// rather than pushed: watchUnsaved hands over a question, not a stream, and
	// asking it costs a property read.
	function titleBar() {
		const bar = document.querySelector('#litecore-editor-root .titlebar')
		if (!bar) return
		const title = document.createElement('span')
		title.id = 'litecore-host-title'
		title.textContent = config.file.name
		const unsaved = document.createElement('span')
		unsaved.id = 'litecore-host-unsaved'
		unsaved.textContent = '尚未儲存'
		unsaved.hidden = true
		bar.prepend(title, unsaved)
		const close = document.createElement('button')
		close.type = 'button'
		close.id = 'litecore-host-close'
		close.title = '關閉'
		close.setAttribute('aria-label', '關閉')
		close.textContent = '✕'
		close.addEventListener('click', () => { location.assign(config.filesUrl) })
		bar.append(close)
		setInterval(() => {
			let holding = false
			try {
				holding = pageUnsaved !== null && pageUnsaved() === true
			} catch (error) {
				holding = true
			}
			unsaved.hidden = !(holding || stranded)
		}, 500)
	}

	// -- the lock (spec §3 O2, K1, C1)
	async function lockHolder() {
		const response = await send(config.file.davUrl, {
			method: 'PROPFIND',
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop>'
				+ '<nc:lock-owner/><nc:lock-owner-displayname/><nc:lock-owner-type/></d:prop></d:propfind>',
			headers: headers({ Depth: '0', 'Content-Type': 'application/xml; charset=utf-8' }),
		})
		if (!response.ok) return { status: response.status }
		const xml = new DOMParser().parseFromString(await response.text(), 'application/xml')
		const read = (name) => (xml.getElementsByTagNameNS('http://nextcloud.org/ns', name)[0] || {}).textContent || null
		return { owner: read('lock-owner'), displayName: read('lock-owner-displayname'), type: read('lock-owner-type') }
	}

	// true / false, or null when the server did not say.
	async function tokenOnServer(held) {
		if (!held) return false
		const response = await send(config.file.davUrl, {
			method: 'PROPFIND',
			body: '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:lockdiscovery/></d:prop></d:propfind>',
			headers: headers({ Depth: '0', 'Content-Type': 'application/xml; charset=utf-8' }),
		})
		if (!response.ok) return null
		return (await response.text()).includes(held.replace(/^<|>$/g, ''))
	}

	async function takeLock() {
		if (!config.file.writable) {
			out.lock = { state: 'readonly' }
			setLockNotice('唯讀：只能檢視，不能修改或存檔。')
			return
		}
		// The owner text is what others see as the holder (files_lock shows it
		// for token locks), so it is the user's name, not the app's.
		const lockinfo = '<?xml version="1.0" encoding="utf-8"?><d:lockinfo xmlns:d="DAV:">'
			+ '<d:lockscope><d:exclusive/></d:lockscope><d:locktype><d:write/></d:locktype>'
			+ `<d:owner>${xml(config.userDisplayName || config.user || 'LiteCore')}</d:owner></d:lockinfo>`
		lockedAt = Date.now()
		const response = await send(config.file.davUrl, {
			method: 'LOCK', body: lockinfo,
			headers: headers({ 'Content-Type': 'application/xml; charset=utf-8', Timeout: `Second-${LOCK_SECONDS}` }),
		})
		out.lock = { state: 'failed', status: response.status, at: Date.now() - out.started, refreshes: [] }
		if (response.status === 200 && response.headers.get('lock-token')) {
			// A 200 can carry a token that was never stored (the user already
			// holds a user lock of their own); only one the server lists counts.
			const held = response.headers.get('lock-token')
			out.lock.verified = await tokenOnServer(held)
			if (out.lock.verified === false) {
				out.lock.state = 'not-stored'
			} else {
				token = held
				out.lock.state = 'held'
				refreshTimer = setInterval(refreshLock, REFRESH_MS)
			}
			publish()
		} else if (response.status === 423) {
			out.lock.state = 'refused'
			out.lock.holder = await lockHolder()
			const holder = out.lock.holder
			setLockNotice(holder.owner && holder.owner === config.user
				? '你在另一個視窗或裝置開著這個檔案：這裡的存檔會另存副本。'
				: `正被 ${holder.displayName || holder.owner || '別人'} 鎖定編輯：你的存檔會另存副本。`)
		} else {
			setLockNotice(`無法上鎖（${response.status}）：存檔前仍會確認檔案沒被別人改過。`)
		}
	}

	function loseLock(by, status) {
		if (!token) return
		token = null
		clearInterval(refreshTimer)
		Object.assign(out.lock, { state: 'lost', lostBy: by, lostStatus: status, lostAt: Date.now() - out.started })
		setLockNotice('鎖已失效：別人現在可以寫這個檔案；存檔前仍會確認檔案沒被別人改過。')
	}

	async function refreshLock() {
		if (!token) return
		const seconds = Math.ceil((Date.now() - lockedAt) / 1000) + LOCK_SECONDS
		const response = await send(config.file.davUrl, {
			method: 'LOCK', headers: headers({ If: `(${token})`, Timeout: `Second-${seconds}` }),
		})
		out.lock.refreshes.push({ at: Date.now() - out.started, status: response.status, seconds })
		// No answer, or a server error, is not a refusal: the lock may well
		// still be there, and a dead token is caught by the next save anyway.
		if (LOST.includes(response.status)) loseLock('refresh', response.status)
		publish()
	}

	// -- leaving (spec §3 of SPEC-M3-locks-and-permissions; study_LiteCore finding 105)
	// Sent at beforeunload, and again at pagehide. Firefox 156 with its HTTP
	// cache on let the pagehide UNLOCK reach the server 6 times in 13 (the
	// page's pagehide ran every time): the next open of the same file then got
	// 423 and saved to copies for five minutes. beforeunload runs before the
	// navigation starts. Twice each time, on purpose: in Chrome 151 a single
	// keepalive UNLOCK at pagehide reached the server 0 times in 4, two of them
	// 4 in 4 (study_LiteCore findings/evidence/m3-locks/unload/README.md). A
	// copy that finds no lock is answered 204 or 500 and releases nothing else
	// (Sabre compares tokens).
	let released = null
	function release(by) {
		clearInterval(refreshTimer)
		if (token) {
			released = token
			token = null
			out.lock.state = 'released'
			out.lock.releasedBy = by
			publish()
		}
		if (!released) return
		for (let copy = 0; copy < 2; copy++) {
			fetch(config.file.davUrl, {
				method: 'UNLOCK', keepalive: true, credentials: 'same-origin', headers: headers({ 'Lock-Token': released }),
			}).catch(() => {})
		}
	}

	window.addEventListener('beforeunload', (event) => {
		// -- the way out (spec §3 S4-S6): asked about, so the page may stay; keep the lock.
		// The page's own question counts too; an answer that throws counts as
		// "yes" -- asking once too often loses nothing, not asking loses edits.
		const reasons = []
		if (stranded) reasons.push('stranded')
		if (inFlight > 0) reasons.push('inFlight')
		if (pageUnsaved) {
			try {
				if (pageUnsaved() === true) reasons.push('page')
			} catch (error) {
				reasons.push('page-error')
			}
		}
		if (reasons.length > 0) {
			out.guarded = (out.guarded || 0) + 1
			out.guards = out.guards || []
			out.guards.push({ at: Date.now() - out.started, reasons })
			publish()
			event.preventDefault()
			event.returnValue = ''
			return
		}
		release('beforeunload')
		// Still here a moment later: nothing unloaded the page (a link that turned
		// out to be a download, say). The lock is gone, and releasing it moved the
		// ETag, so the next save goes to a copy; say so rather than pretend.
		setTimeout(() => {
			// Also while hidden: a tab switched away from after a cancelled navigation
			// is still a page holding no lock. (A navigation slower than 3 s shows
			// this too, just before the page goes.)
			if (out.lock.state !== 'released') return
			Object.assign(out.lock, { state: 'lost', lostBy: 'stayed-after-beforeunload', lostAt: Date.now() - out.started })
			setLockNotice('鎖已釋放：別人現在可以寫這個檔案；存檔前仍會確認檔案沒被別人改過。')
		}, 3000)
	})
	window.addEventListener('pagehide', () => release('pagehide'))

	function strand(bytes, sha256) {
		stranded = { bytes, sha256 }
		out.stranded = { sha256, bytes: bytes.byteLength, at: Date.now() - out.started }
		render()
	}

	// Each save carries the whole document, so any later save that lands
	// somewhere supersedes bytes kept from an earlier one. Nothing else does:
	// not even a download, whose completion the page cannot see.
	function unstrand(how) {
		if (!stranded) return
		out.unstranded = { how, sha256: stranded.sha256, at: Date.now() - out.started }
		stranded = null
		out.stranded = null
		render()
	}

	// Only from the button: a download needs the user's gesture (spec §3 S6).
	// The bytes and the guard stay: a cancelled "Save as" must not lose them.
	function download() {
		if (!stranded) return
		const url = URL.createObjectURL(new Blob([stranded.bytes], { type: ODT }))
		const link = document.createElement('a')
		link.href = url
		link.download = config.file.name
		document.body.appendChild(link)
		link.click()
		link.remove()
		// Firefox reads the blob after click() returns; revoking at once can
		// cancel the download.
		setTimeout(() => URL.revokeObjectURL(url), 60000)
		stranded.downloaded = true
		out.downloaded = (out.downloaded || []).concat({ sha256: stranded.sha256, at: Date.now() - out.started })
		out.stranded.downloaded = true
		render()
	}

	// The copy is created only if that name is free (If-None-Match: *).
	async function saveCopy(bytes, label) {
		const slash = config.file.davUrl.lastIndexOf('/')
		const folder = config.file.davUrl.slice(0, slash + 1)
		const stem = config.file.name.replace(/\.odt$/i, '')
		const now = new Date()
		const pad = (n) => String(n).padStart(2, '0')
		const stamp = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
		for (let attempt = 1; attempt <= 5; attempt++) {
			const name = `${stem}（${label} ${stamp}${attempt > 1 ? `-${attempt}` : ''}）.odt`
			const response = await send(folder + encodeURIComponent(name), {
				method: 'PUT', body: bytes, headers: headers({ 'Content-Type': ODT, 'If-None-Match': '*' }),
			})
			if (response.ok) return { name, status: response.status }
			if (response.status !== 412) return { name, status: response.status, failed: true }
		}
		return { failed: true, status: 412 }
	}

	// Put in place by run(), before the product module is imported.
	const host = {
		async load() {
			await takeLock()
			const response = await fetch(config.file.davUrl, {
				credentials: 'same-origin', cache: 'no-store', headers: headers(),
			})
			const bytes = await response.arrayBuffer()
			etag = versionOf(response)
			out.fetched = {
				status: response.status, contentType: response.headers.get('content-type'),
				etag, bytes: bytes.byteLength, sha256: await hex(bytes), at: Date.now() - out.started,
			}
			lastSha = out.fetched.sha256
			step('fetched')
			if (!response.ok) throw failure('HOST_LOAD_FAILED', `WebDAV GET ${response.status}`)
			// Without a version there is no conditional write, and an unconditional
			// one overwrites whatever changed since. Refuse rather than fail open.
			if (!etag) throw failure('HOST_LOAD_FAILED', '伺服器沒有給這個檔案的版本標記，無法安全存回。')
			// The page opens view-only on this (study_LiteCore SPEC-M3-page-honesty).
			out.handedReadOnly = !config.file.writable
			return { bytes, name: config.file.name, readOnly: !config.file.writable }
		},

		// The page asks here instead of registering its own beforeunload: this
		// host's listener runs first and releases the lock when it does not
		// guard, so a page that asked on its own would leave a user who chose to
		// stay without a lock (study_LiteCore SPEC-M3-page-honesty §7.1).
		watchUnsaved(check) {
			pageUnsaved = typeof check === 'function' ? check : null
			out.watchingPage = pageUnsaved !== null
			publish()
		},

		async save(bytes, { name }) {
			const record = { at: Date.now() - out.started, name, bytes: bytes.byteLength, attempts: [] }
			out.saves.push(record)
			inFlight++
			try {
				await saveOnce(bytes, record)
			} catch (error) {
				// Every save ends one of three ways: the original written, a copy
				// written, or the bytes kept here with leaving guarded -- the page
				// counts on it (study_LiteCore SPEC-M3-page-honesty §7.1). A throw
				// that is none of those (a bug, an answer nobody expected) keeps
				// the bytes rather than let them go.
				if (record.outcome) throw error
				record.unexpected = `${error && error.name}: ${error && error.message}`
				strand(bytes, record.sha256 || null)
				record.outcome = 'stranded'
				throw failure('HOST_SAVE_FAILED', '存回時發生未預期的錯誤：內容還在這個頁面裡，請按「下載這份內容」。')
			} finally {
				// Everything this save will do is done, copy included.
				inFlight--
				record.settled = true
				publish()
			}
		},
	}

	// -- two writes in one second (study_LiteCore findings 103, 104)
	// Nextcloud names a version after the file's mtime in seconds, and keeps the
	// ETag for a second write in the same second. Two PUTs of ours in one second
	// leave the first in no version, and a restore after them loses the second
	// for good (104). So a PUT waits until a second has passed since our previous
	// one was answered; a save that does not follow another that closely does
	// not wait at all.
	// If-Match cannot see someone else's write in the same second as the version
	// it names either (103), so the file is read back before each PUT: the same
	// version with other bytes is such a write, and it is not overwritten. The
	// read comes after the wait, so a write landing after it is in a later
	// second than ours and does move the ETag (if the server stamps its mtime).
	// 1.1 s rather than 1.0: a coarsened clock (Firefox's resistFingerprinting)
	// can read 1000 ms after less than that really passed. Concurrent save()
	// calls are not queued here -- the page queues its saves.
	const SPACING_MS = 1100
	async function put(bytes, record) {
		const attempt = {}
		record.attempts.push(attempt)
		const waitFrom = performance.now()
		while (lastAnswered !== null && lastAnswered + SPACING_MS > performance.now()) {
			await new Promise((resolve) => setTimeout(resolve, lastAnswered + SPACING_MS - performance.now()))
		}
		attempt.waited = Math.round(performance.now() - waitFrom)
		const check = { at: Date.now() - out.started }
		const read = await send(config.file.davUrl, { cache: 'no-store', headers: headers() })
		check.status = read.status
		if (read.ok) {
			check.etag = versionOf(read)
			// A body that breaks off is no answer: the PUT below decides, as
			// before, so a refused save still gets its way out.
			try {
				check.sha256 = await hex(await read.arrayBuffer())
			} catch (error) {
				check.error = String(error)
			}
		}
		check.ms = Date.now() - out.started - check.at
		Object.assign(attempt, { check, ifMatch: etag, withToken: token !== null })
		if (check.sha256 && check.etag === etag && lastSha !== null && check.sha256 !== lastSha) {
			attempt.status = 'changed'
			publish()
			return { ok: false, status: 'changed', headers: new Headers() }
		}
		attempt.at = Date.now() - out.started
		const extra = { 'Content-Type': ODT, 'If-Match': etag }
		if (token) extra.If = `(${token})`
		sentToken = token
		const response = await send(config.file.davUrl, { method: 'PUT', body: bytes, headers: headers(extra) })
		lastAnswered = performance.now()
		attempt.respondedAt = Date.now() - out.started
		attempt.status = response.status
		if (response.error) attempt.error = response.error
		publish()
		return response
	}

	async function saveOnce(bytes, record) {
		record.sha256 = await hex(bytes)
		let response = { ok: false, status: 'no-version', headers: new Headers() }
		// Without a version there is no conditional write; the bytes still get
		// the way out below, just never an unconditional overwrite.
		if (etag) {
			record.ifMatch = etag
			response = await put(bytes, record)
		}
		// A token that died (expired, or removed from Files) fails the PUT with
		// 412 even when nothing changed. But a live lock can meet a 412 too:
		// someone's refused unlock still moves the ETag. So ask the server.
		if (response.status === 412 && record.attempts[0].withToken) {
			// The token that went out, not `token`: if a refresh lost the lock
			// while the PUT was on the wire, `token` is null here, and asking
			// about null threw -- the save ended with no copy and nothing
			// stranded, and leaving was not asked about (study_LiteCore
			// probe_m3_save_throw.py).
			record.tokenOnServer = await tokenOnServer(sentToken)
			if (record.tokenOnServer === false) {
				loseLock('save', 412)
				response = await put(bytes, record)
			}
		}
		record.status = response.status
		if (response.ok) {
			record.etag = versionOf(response)
			if (!record.etag) {
				// Without the new version the next save would be refused as a conflict.
				const head = await send(config.file.davUrl, { method: 'HEAD', headers: headers() })
				record.etag = versionOf(head)
				record.etagFrom = 'HEAD'
			}
			etag = record.etag
			lastSha = record.sha256
			unstrand('saved')
			record.outcome = 'saved'
			return
		}
		const conflict = response.status === 412 || response.status === 423 || response.status === 'changed'
		const why = {
			412: '檔案在你開啟之後已被別處修改或移走，沒有覆寫。',
			changed: '檔案在你開啟之後已被別處修改，沒有覆寫。',
			423: '檔案正被別人鎖定，沒有覆寫。',
			403: '你沒有這個檔案的寫入權限。',
			507: '儲存空間已滿。',
			network: '連不上伺服器。',
			'no-version': '不知道伺服器上的版本，不做無條件覆寫。',
		}[response.status] || `伺服器拒絕寫入（HTTP ${response.status}）。`
		record.responseEtag = versionOf(response)
		record.conflictCopy = await saveCopy(bytes, conflict ? '衝突副本' : '另存副本')
		publish()
		const code = conflict ? 'CONFLICT' : 'HOST_SAVE_FAILED'
		if (!record.conflictCopy.failed) {
			unstrand('copy')
			record.outcome = 'copy'
			throw failure(code, `${why}你的內容另存為「${record.conflictCopy.name}」。`)
		}
		strand(bytes, record.sha256)
		record.outcome = 'stranded'
		throw failure(code, `${why}另存副本也失敗（${record.conflictCopy.status}）：內容還在這個頁面裡，請按「下載這份內容」。`)
	}

	async function run() {
		config = readConfig()
		// No @scope (Firefox before 146, ESR 140): the scoped sheet is dropped whole,
		// so the page's own unscoped copy goes on (templates/editor.php).
		out.scope = typeof CSSScopeRule === 'function' ? 'scoped' : 'unscoped-fallback'
		if (out.scope !== 'scoped') document.getElementById('litecore-unscoped')?.setAttribute('media', 'all')
		globalThis.liteCoreHost = host
		document.getElementById('litecore-host-download').addEventListener('click', download)
		titleBar()
		out.file = { id: config.file.id, name: config.file.name, size: config.file.size, etag: config.file.etag, writable: config.file.writable }
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
