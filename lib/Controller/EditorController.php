<?php

declare(strict_types=1);

namespace OCA\LiteCore\Controller;

use OCA\LiteCore\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

/**
 * The editor route, in the shape M3 gate 0 passed (study_LiteCore
 * findings/evidence/m3-gate-0/RESULT.md, shape E1h): the shipped page re-wrapped
 * under Nextcloud's header, COOP + COEP require-corp on the page, every engine
 * script served with COEP (a static script from the app directory has no COEP
 * and the engine dies at boot -- E1).
 *
 * The engine is the app's own `engine/` (study_LiteCore
 * specs/SPEC-M3-asset-delivery.md): every file of the editor's closure, as zstd
 * and as gzip, listed in `engine/MANIFEST.json`. All of it is served here, under
 * an address that carries the build id and a hash of how the files are served,
 * as packaged compressed bytes with Content-Encoding and a year's immutable
 * caching -- the one way the 115 MB wasm fits in either
 * browser's HTTP cache (sent as it is, Firefox never keeps it and Chrome keeps
 * it only on a roomy disk; step 0 of that spec). zstd first, where the request
 * takes it (specs/SPEC-M3-zstd.md): about a quarter fewer bytes than gzip.
 *
 * This route never reads, parses or converts the document. It resolves the file
 * id to the user's own WebDAV URL; the browser fetches the bytes from Nextcloud's
 * WebDAV like any client would.
 */
class EditorController extends Controller {
	private const ODT = 'application/vnd.oasis.opendocument.text';
	// The shipped page's one module script. Removed from the re-wrapped body and
	// imported by js/litecore-editor.js instead: that script carries Nextcloud's
	// CSP nonce, so strict-dynamic admits the module graph it loads.
	private const PRODUCT_SCRIPT = '<script type="module" src="./e2-editor-app.js"></script>';

	public function __construct(
		string $appName,
		IRequest $request,
		private IInitialState $initialState,
		private IURLGenerator $urls,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * How every engine file is served, as data: serve() builds each response from
	 * this and nothing else, and its hash is part of the address (address()). A
	 * cached response keeps its headers as well as its body -- for a worker script
	 * the cached CSP and COEP are the ones that apply -- so a change here has to
	 * move the address, or warm browsers keep the old headers for as long as they
	 * keep the files. (Not covered: headers Nextcloud itself adds.)
	 */
	private const SERVE_POLICY = [
		'types' => ['js' => 'text/javascript; charset=utf-8', 'mjs' => 'text/javascript; charset=utf-8',
			'json' => 'application/json; charset=utf-8', 'wasm' => 'application/wasm', '' => 'application/octet-stream'],
		'coep' => ['js' => 'require-corp', 'mjs' => 'require-corp'],
		'csp' => ['script' => ["'self'"], 'connect' => ["'self'"], 'worker' => ["'self'"], 'evalWasm' => true],
		'cache' => ['seconds' => 31536000, 'public' => false, 'immutable' => true],
		// In order of preference; each coding's packaged file and manifest fields.
		'encodings' => [
			'zstd' => ['suffix' => '.zst', 'bytes' => 'zstBytes', 'sha256' => 'zstSha256'],
			'gzip' => ['suffix' => '.gz', 'bytes' => 'gzBytes', 'sha256' => 'gzSha256'],
		],
		'vary' => 'Accept-Encoding',
	];

	/**
	 * `r/e2-editor.html?fileId=N` is what Files links to: it sends the browser to
	 * this build's address. `r/<address>/<path>` is the editor document and every
	 * file it loads -- the page's relative URLs (its modules, the worker, what the
	 * worker fetches) all land under the same address. The redirect names
	 * index.php outright, so no request depends on how a web server falls back
	 * for a path that is not a file on disk.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/r/{path}', requirements: ['path' => '.+'])]
	public function asset(string $path, int $fileId = 0): Response {
		// The manifest lookup below is exact; this is the second line of defence.
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			return new NotFoundResponse();
		}
		$engine = $this->engine();
		if ($engine === null) {
			return new DataDisplayResponse('the engine is not packaged (engine/MANIFEST.json)', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($path === 'e2-editor.html') {
			return new RedirectResponse($this->editorUrl($engine, $fileId));
		}
		$parts = explode('/', $path, 2);
		if (count($parts) !== 2) {
			return new NotFoundResponse();
		}
		if ($parts[0] !== $this->address($engine)) {
			// An address from before an upgrade (a reload, a history entry, a restored
			// session): the document goes to the current one; the old files are gone.
			return $parts[1] === 'e2-editor.html'
				? new RedirectResponse($this->editorUrl($engine, $fileId))
				: new NotFoundResponse();
		}
		if (!isset($engine['files'][$parts[1]])) {
			return new NotFoundResponse();
		}
		if ($parts[1] === 'e2-editor.html') {
			return $this->editor($fileId);
		}
		return $this->serve($parts[1], $engine['files'][$parts[1]]);
	}

	/** The build id, and the hash of how its files are served. */
	private function address(array $engine): string {
		return $engine['buildId'] . '-' . substr(hash('sha256', (string)json_encode(self::SERVE_POLICY)), 0, 8);
	}

	private function editorUrl(array $engine, int $fileId): string {
		// getWebroot(), not linkTo('', 'index.php'): with pretty URLs on, the latter
		// is "/", and "/" . "/apps/..." is a scheme-relative URL to a host named apps.
		return $this->urls->getWebroot() . '/index.php/apps/' . Application::APP_ID
			. '/r/' . $this->address($engine) . '/e2-editor.html?fileId=' . $fileId;
	}

	private function editor(int $fileId): Response {
		$target = $this->resolve($fileId);
		if (is_string($target)) {
			return new DataDisplayResponse($target, Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$html = (string)gzdecode((string)file_get_contents($this->packed('e2-editor.html', '.gz')));
		if (!preg_match('#<style>(.*?)</style>#s', $html, $style) || !preg_match('#<body>(.*)</body>#s', $html, $body)
			|| substr_count($body[1], self::PRODUCT_SCRIPT) !== 1) {
			return new DataDisplayResponse('the shipped page no longer has the shape this app re-wraps', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$scoped = $this->scopedStyle($style[1]);
		if ($scoped === null) {
			return new DataDisplayResponse('the shipped page\'s style no longer has the shape this app scopes', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$this->initialState->provideInitialState('config', [
			'user' => $this->userSession->getUser()?->getUID(),
			'userDisplayName' => $this->userSession->getUser()?->getDisplayName(),
			'file' => $target,
			'editor' => ['module' => './e2-editor-app.js', 'sourceSha256' => hash('sha256', $html)],
			// Where the close button goes: the file's folder in Files, the file
			// highlighted, not opened again -- the route Files' own showFile()
			// redirects to, without its `openfile`. Built by the router, not by
			// hand: a hand-made `/index.php/apps/files/...` under pretty URLs landed
			// on `/apps/files/index.php/apps/files/...`.
			'filesUrl' => $this->urls->linkToRoute('files.view.indexViewFileid',
				['view' => 'files', 'fileid' => $target['id'], 'dir' => $target['dir']]),
		]);
		Util::addScript(Application::APP_ID, 'litecore-editor');
		$response = new TemplateResponse(Application::APP_ID, 'editor', [
			'style' => $scoped,
			'unscoped' => $style[1],
			'body' => str_replace(self::PRODUCT_SCRIPT, '', $body[1]),
		], 'user');
		$policy = new ContentSecurityPolicy();
		$policy->allowEvalWasm(true);
		$response->setContentSecurityPolicy($policy);
		$response->addHeader('Cross-Origin-Opener-Policy', 'same-origin');
		$response->addHeader('Cross-Origin-Embedder-Policy', 'require-corp');
		return $response;
	}

	/**
	 * The shipped page's stylesheet, confined to the editor's container
	 * (study_LiteCore finding 110). Printed as it was, its `body` rule replaced
	 * Nextcloud's themed background -- the header's white icons ended up on light
	 * grey -- and its `button`/`select`/`input` rules restyled Nextcloud's own
	 * controls. Wrapped in `@scope (#litecore-editor-root)` (templates/editor.php)
	 * every selector matches only inside the container; the page's two
	 * document-level rules, `:root` (its colours) and `body` (its column layout),
	 * become `:scope`, the container itself. Exactly one of each, at the start of
	 * a rule, or the page has changed shape and this refuses rather than guess.
	 */
	private function scopedStyle(string $css): ?string {
		foreach ([':root', 'body'] as $selector) {
			// Every occurrence, anywhere (study_LiteCore review 2026-10-06 item 9): a
			// rule nested in an at-rule (`@media … { :root {`) is not at the start of a
			// rule, and the anchored replacement below would leave it unrewritten
			// rather than refuse.
			if (preg_match_all('/(?<![\w-])' . preg_quote($selector, '/') . '\s*\{/', $css) !== 1) {
				return null;
			}
			$css = preg_replace('/(^|\})(\s*)' . preg_quote($selector, '/') . '(\s*)\{/', '$1$2:scope$3{', $css, -1, $count);
			if ($css === null || $count !== 1) {
				return null;
			}
		}
		return $css;
	}

	/**
	 * The file as the current user sees it, or why not. Only the user's own
	 * folder: shares received are in it, public links are not (out of scope).
	 *
	 * `writable` decides whether the host takes a lock at all: a read-only share
	 * answers every PUT with 403, so there is nothing a lock would protect.
	 *
	 * @return array{id: int, name: string, dir: string, size: int, etag: string, writable: bool, davUrl: string}|string
	 */
	private function resolve(int $fileId): array|string {
		$user = $this->userSession->getUser();
		if ($user === null || $fileId <= 0) {
			return 'no such file';
		}
		$folder = $this->rootFolder->getUserFolder($user->getUID());
		$node = $folder->getFirstNodeById($fileId);
		if (!$node instanceof File || !$node->isReadable()) {
			return 'no such file';
		}
		if ($node->getMimetype() !== self::ODT) {
			return 'not an ODT file';
		}
		$relative = (string)$folder->getRelativePath($node->getPath());
		$segments = array_map('rawurlencode', explode('/', ltrim($relative, '/')));
		$dir = dirname('/' . ltrim($relative, '/'));
		return [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'dir' => $dir === '.' || $dir === '\\' ? '/' : $dir,
			'size' => $node->getSize(),
			'etag' => $node->getEtag(),
			'writable' => $node->isUpdateable(),
			'davUrl' => $this->urls->linkTo('', 'remote.php') . '/dav/files/'
				. rawurlencode($user->getUID()) . '/' . implode('/', $segments),
		];
	}

	/**
	 * The packaged bytes as they are, in the first coding of the policy the
	 * browser takes; decoded from the gzip on the way when it takes none. Either
	 * way streamed, never read whole into memory.
	 *
	 * A dedicated worker takes its CSP from its own response, and Nextcloud
	 * always sends one; this grants what the product's workers do (importScripts,
	 * fetch, nested pthread workers, wasm compile). Scripts carry COEP, which is
	 * what lets them run as workers of an isolated page.
	 *
	 * @param array{bytes: int, sha256: string, gzBytes: int, gzSha256: string, zstBytes: int, zstSha256: string} $entry
	 */
	private function serve(string $path, array $entry): Response {
		$serving = self::SERVE_POLICY;
		$coding = null;
		foreach (array_keys($serving['encodings']) as $candidate) {
			if ($this->accepts($candidate)) {
				$coding = $candidate;
				break;
			}
		}
		// Identity is decoded from the gzip file.
		$packed = $this->packed($path, $serving['encodings'][$coding ?? 'gzip']['suffix']);
		if (!is_file($packed)) {
			// Listed but missing: a plain 404, not one carrying a year of caching.
			return new NotFoundResponse();
		}
		$extension = pathinfo($path, PATHINFO_EXTENSION);
		$type = $serving['types'][$extension] ?? $serving['types'][''];
		if ($coding !== null) {
			$fields = $serving['encodings'][$coding];
			$response = new StreamResponse($packed, Http::STATUS_OK, ['Content-Type' => $type,
				'Content-Encoding' => $coding, 'Content-Length' => (string)$entry[$fields['bytes']]]);
		} else {
			$stream = fopen('compress.zlib://' . $packed, 'rb');
			if ($stream === false) {
				return new NotFoundResponse();
			}
			$response = new StreamResponse($stream, Http::STATUS_OK, ['Content-Type' => $type,
				'Content-Length' => (string)$entry['bytes']]);
		}
		$response->addHeader('Vary', $serving['vary']);
		// The address carries the build id and this policy's hash: neither these
		// bytes nor these headers change under it.
		$response->cacheFor($serving['cache']['seconds'], $serving['cache']['public'], $serving['cache']['immutable']);
		// For a client that asks anyway: the worker fetches its manifest with
		// cache: "no-cache" (sdk-worker.js), and Chrome sends that to the server
		// every open. With an ETag, Nextcloud's NotModifiedMiddleware answers it
		// 304 with no body. One per representation: the hash of the bytes sent.
		$response->setETag($coding !== null ? $entry[$serving['encodings'][$coding]['sha256']] : $entry['sha256']);
		$policy = new EmptyContentSecurityPolicy();
		foreach ($serving['csp']['script'] as $source) {
			$policy->addAllowedScriptDomain($source);
		}
		foreach ($serving['csp']['connect'] as $source) {
			$policy->addAllowedConnectDomain($source);
		}
		foreach ($serving['csp']['worker'] as $source) {
			$policy->addAllowedWorkerSrcDomain($source);
		}
		$policy->allowEvalWasm($serving['csp']['evalWasm']);
		$response->setContentSecurityPolicy($policy);
		if (isset($serving['coep'][$extension])) {
			$response->addHeader('Cross-Origin-Embedder-Policy', $serving['coep'][$extension]);
		}
		return $response;
	}

	/** Whether Accept-Encoding names this coding with a q other than 0 ("*" does not count). */
	private function accepts(string $wanted): bool {
		foreach (explode(',', (string)$this->request->getHeader('Accept-Encoding')) as $coding) {
			$fields = array_map('trim', explode(';', $coding));
			if (strtolower($fields[0]) !== $wanted) {
				continue;
			}
			foreach (array_slice($fields, 1) as $parameter) {
				if (preg_match('/^q=0(\.0*)?$/i', $parameter)) {
					return false;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * @return array{buildId: string, files: array<string, array{bytes: int, sha256: string, gzBytes: int, gzSha256: string, zstBytes: int, zstSha256: string}>}|null
	 */
	private function engine(): ?array {
		$manifest = json_decode((string)@file_get_contents($this->engineDir() . '/MANIFEST.json'), true);
		if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'litecore-engine/2'
			|| !is_string($manifest['buildId'] ?? null) || !is_array($manifest['files'] ?? null)) {
			return null;
		}
		return $manifest;
	}

	/** Only ever called with a path the manifest lists. */
	private function packed(string $path, string $suffix): string {
		return $this->engineDir() . '/files/' . $path . $suffix;
	}

	private function engineDir(): string {
		return dirname(__DIR__, 2) . '/engine';
	}
}
