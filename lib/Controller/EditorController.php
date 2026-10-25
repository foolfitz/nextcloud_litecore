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
 * Since 0.2.29 the same route serves two pages through a page table (PAGES;
 * study_LiteCore specs/SPEC-M4-ods-in-nextcloud.md §3.4): the editor for an ODT
 * and the read-only spreadsheet viewer for an ODS, each with its own engine in
 * the same package.
 *
 * The engine is the app's own `engine/` (study_LiteCore
 * specs/SPEC-M3-asset-delivery.md): every file of both pages' closures, as zstd
 * and as gzip, listed in `engine/MANIFEST.json` (schema litecore-engine/3). All
 * of it is served here, under an address that carries the build id and a hash
 * of how the files are served, as packaged compressed bytes with
 * Content-Encoding and a year's immutable caching -- the one way the 115 MB
 * wasm fits in either browser's HTTP cache (sent as it is, Firefox never keeps
 * it and Chrome keeps it only on a roomy disk; step 0 of that spec). zstd
 * first, where the request takes it (specs/SPEC-M3-zstd.md): about a quarter
 * fewer bytes than gzip.
 *
 * This route never reads, parses or converts the document. It resolves the file
 * id to the user's own WebDAV URL; the browser fetches the bytes from Nextcloud's
 * WebDAV like any client would.
 */
class EditorController extends Controller {
	private const ODT = 'application/vnd.oasis.opendocument.text';
	private const ODS = 'application/vnd.oasis.opendocument.spreadsheet';
	private const ENGINE_SCHEMA = 'litecore-engine/3';
	/**
	 * The page table (SPEC-M4 §3.4): per shipped page, the one module script it
	 * carries (removed from the re-wrapped body and imported by the host script
	 * instead -- that script carries Nextcloud's CSP nonce, so strict-dynamic
	 * admits the module graph it loads), the ONE mime its route resolves, its
	 * template and its host script. A file of the other mime is refused on the
	 * wrong route: the viewer never shows a text document as sheets, the editor
	 * never opens a spreadsheet.
	 */
	private const PAGES = [
		'e2-editor.html' => ['module' => './e2-editor-app.js', 'mime' => self::ODT, 'kind' => 'ODT',
			'template' => 'editor', 'hostScript' => 'litecore-editor'],
		'ods-viewer.html' => ['module' => './ods-viewer-app.js', 'mime' => self::ODS, 'kind' => 'ODS',
			'template' => 'viewer', 'hostScript' => 'litecore-viewer'],
		'ods-editor.html' => ['module' => './ods-editor-app.js', 'mime' => self::ODS, 'kind' => 'ODS',
			'template' => 'editor', 'hostScript' => 'litecore-editor'],
	];

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
	 * `r/<page>?fileId=N` is what Files links to (`e2-editor.html` for an ODT,
	 * `ods-viewer.html` for an ODS): it sends the browser to this build's address.
	 * `r/<address>/<path>` is the page document and every file it loads -- the
	 * page's relative URLs (its modules, the worker, what the worker fetches) all
	 * land under the same address. The redirect names index.php outright, so no
	 * request depends on how a web server falls back for a path that is not a
	 * file on disk.
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
			return new DataDisplayResponse('the engine is not packaged for this host (engine/MANIFEST.json: '
				. self::ENGINE_SCHEMA . ' with both pages)', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if (isset(self::PAGES[$path])) {
			return new RedirectResponse($this->pageUrl($engine, $path, $fileId));
		}
		$parts = explode('/', $path, 2);
		if (count($parts) !== 2) {
			return new NotFoundResponse();
		}
		if ($parts[0] !== $this->address($engine)) {
			// An address from before an upgrade (a reload, a history entry, a restored
			// session): the document goes to the current one; the old files are gone.
			return isset(self::PAGES[$parts[1]])
				? new RedirectResponse($this->pageUrl($engine, $parts[1], $fileId))
				: new NotFoundResponse();
		}
		if (!isset($engine['files'][$parts[1]])) {
			return new NotFoundResponse();
		}
		if (isset(self::PAGES[$parts[1]])) {
			return $this->page($parts[1], $fileId);
		}
		return $this->serve($parts[1], $engine['files'][$parts[1]]);
	}

	/** The build id, and the hash of how its files are served. */
	private function address(array $engine): string {
		return $engine['buildId'] . '-' . substr(hash('sha256', (string)json_encode(self::SERVE_POLICY)), 0, 8);
	}

	private function pageUrl(array $engine, string $page, int $fileId): string {
		// getWebroot(), not linkTo('', 'index.php'): with pretty URLs on, the latter
		// is "/", and "/" . "/apps/..." is a scheme-relative URL to a host named apps.
		return $this->urls->getWebroot() . '/index.php/apps/' . Application::APP_ID
			. '/r/' . $this->address($engine) . '/' . $page . '?fileId=' . $fileId;
	}

	/** The page document: the shipped page re-wrapped under Nextcloud's header. */
	private function page(string $name, int $fileId): Response {
		$page = self::PAGES[$name];
		$target = $this->resolve($fileId, $page['mime'], $page['kind']);
		if (is_string($target)) {
			return new DataDisplayResponse($target, Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$html = (string)gzdecode((string)file_get_contents($this->packed($name, '.gz')));
		$script = '<script type="module" src="' . $page['module'] . '"></script>';
		if (!preg_match('#<style>(.*?)</style>#s', $html, $style) || !preg_match('#<body>(.*)</body>#s', $html, $body)
			|| substr_count($body[1], $script) !== 1) {
			return new DataDisplayResponse('the shipped page no longer has the shape this app re-wraps', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$scoped = $this->scopedStyle($style[1]);
		if ($scoped === null) {
			return new DataDisplayResponse('the shipped page\'s style no longer has the shape this app scopes', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$source = ['module' => $page['module'], 'sourceSha256' => hash('sha256', $html)];
		$config = [
			'user' => $this->userSession->getUser()?->getUID(),
			'userDisplayName' => $this->userSession->getUser()?->getDisplayName(),
			'file' => $target,
			'page' => ['name' => $name] + $source,
			// Where the close button goes: the file's folder in Files, the file
			// highlighted, not opened again -- the route Files' own showFile()
			// redirects to, without its `openfile`. Built by the router, not by
			// hand: a hand-made `/index.php/apps/files/...` under pretty URLs landed
			// on `/apps/files/index.php/apps/files/...`.
			'filesUrl' => $this->urls->linkToRoute('files.view.indexViewFileid',
				['view' => 'files', 'fileid' => $target['id'], 'dir' => $target['dir']]),
		];
		if ($page['template'] === 'editor') {
			// js/litecore-editor.js reads `editor.module` (0.2.28 and before).
			$config['editor'] = $source;
		}
		$this->initialState->provideInitialState('config', $config);
		Util::addScript(Application::APP_ID, $page['hostScript']);
		$response = new TemplateResponse(Application::APP_ID, $page['template'], [
			'style' => $scoped,
			'unscoped' => $style[1],
			'body' => str_replace($script, '', $body[1]),
		], 'user');
		$policy = new ContentSecurityPolicy();
		$policy->allowEvalWasm(true);
		$response->setContentSecurityPolicy($policy);
		$response->addHeader('Cross-Origin-Opener-Policy', 'same-origin');
		$response->addHeader('Cross-Origin-Embedder-Policy', 'require-corp');
		return $response;
	}

	/**
	 * The shipped page's stylesheet, confined to the page's container
	 * (study_LiteCore finding 110). Printed as it was, its `body` rule replaced
	 * Nextcloud's themed background -- the header's white icons ended up on light
	 * grey -- and its `button`/`select`/`input` rules restyled Nextcloud's own
	 * controls. Wrapped in `@scope (#litecore-…-root)` (the templates) every
	 * selector matches only inside the container; the page's two document-level
	 * rules, `:root` (its colours) and `body` (its column layout), become
	 * `:scope`, the container itself. Exactly one of each, at the start of a
	 * rule, or the page has changed shape and this refuses rather than guess
	 * (SPEC-M4 review C2: a second `:root` is a 500, not a guess).
	 */
	private function scopedStyle(string $css): ?string {
		// Comments carry no rules: the count is taken without them (a selector quoted
		// in a comment is not a rule), and a comment may sit between a rule's end and
		// the next selector -- the viewer page explains its one `:root` rule in a
		// comment right above it, and 0.2.30's anchored replacement, which allowed
		// only whitespace there, refused that page (study_LiteCore finding 162). The
		// output is unchanged for a stylesheet the old form accepted.
		$bare = preg_replace('#/\*.*?\*/#s', '', $css);
		if ($bare === null) {
			return null;
		}
		foreach ([':root', 'body'] as $selector) {
			// Every occurrence, anywhere (study_LiteCore review 2026-10-06 item 9): a
			// rule nested in an at-rule (`@media … { :root {`) is not at the start of a
			// rule, and the anchored replacement below would leave it unrewritten
			// rather than refuse.
			if (preg_match_all('/(?<![\w-])' . preg_quote($selector, '/') . '\s*\{/', $bare) !== 1) {
				return null;
			}
			$css = preg_replace('/(^|\})((?:\s|\/\*.*?\*\/)*)' . preg_quote($selector, '/') . '(\s*)\{/s',
				'$1$2:scope$3{', $css, -1, $count);
			if ($css === null || $count !== 1) {
				return null;
			}
		}
		return $css;
	}

	/**
	 * The file as the current user sees it, or why not. Only the user's own
	 * folder: shares received are in it, public links are not (out of scope).
	 * Only the page's own mime: the editor route resolves ODT, the viewer route ODS.
	 *
	 * `writable` decides whether the editor's host takes a lock at all: a
	 * read-only share answers every PUT with 403, so there is nothing a lock
	 * would protect. The viewer's host never takes one.
	 *
	 * @return array{id: int, name: string, dir: string, size: int, etag: string, writable: bool, davUrl: string}|string
	 */
	private function resolve(int $fileId, string $mime, string $kind): array|string {
		$user = $this->userSession->getUser();
		if ($user === null || $fileId <= 0) {
			return 'no such file';
		}
		$folder = $this->rootFolder->getUserFolder($user->getUID());
		$node = $folder->getFirstNodeById($fileId);
		if (!$node instanceof File || !$node->isReadable()) {
			return 'no such file';
		}
		if ($node->getMimetype() !== $mime) {
			return 'not an ' . $kind . ' file';
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
	 * The manifest, or null when it is not one this host serves: schema 3, a
	 * build id, files, and a page table naming every page of PAGES with its
	 * module, both packaged (the packager's validate_manifest() says the same).
	 *
	 * @return array{buildId: string, files: array<string, array{bytes: int, sha256: string, gzBytes: int, gzSha256: string, zstBytes: int, zstSha256: string}>}|null
	 */
	private function engine(): ?array {
		$manifest = json_decode((string)@file_get_contents($this->engineDir() . '/MANIFEST.json'), true);
		if (!is_array($manifest) || ($manifest['schema'] ?? null) !== self::ENGINE_SCHEMA
			|| !is_string($manifest['buildId'] ?? null) || !is_array($manifest['files'] ?? null)) {
			return null;
		}
		$pages = $manifest['source']['pages'] ?? null;
		if (!is_array($pages)) {
			return null;
		}
		foreach (self::PAGES as $name => $page) {
			$entry = $pages[$name] ?? null;
			// The manifest names the module the way it keys files: by path, without
			// the page's `./` (0.2.29 compared against the `./` form and refused its
			// own package -- every route 500, SPEC-M4 Phase H run 1).
			$module = substr($page['module'], 2);
			if (!is_array($entry) || ($entry['module'] ?? null) !== $module
				|| !isset($manifest['files'][$name]) || !isset($manifest['files'][$module])) {
				return null;
			}
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
