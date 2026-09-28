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
 * specs/SPEC-M3-asset-delivery.md): every file of the editor's closure, gzip'd,
 * listed in `engine/MANIFEST.json`. All of it is served here, under an address
 * that carries the build id, as the packaged gzip bytes with Content-Encoding
 * and a year's immutable caching -- the one way the 115 MB wasm fits in either
 * browser's HTTP cache (sent as it is, Firefox never keeps it and Chrome keeps
 * it only on a roomy disk; step 0 of that spec).
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
	 * `r/e2-editor.html?fileId=N` is what Files links to: it sends the browser to
	 * this build's address. `r/<buildId>/<path>` is the editor document and every
	 * file it loads -- the page's relative URLs (its modules, the worker, what the
	 * worker fetches) all land under the same build id. The redirect names
	 * index.php outright, so no request depends on how a web server falls back
	 * for a path that is not a file on disk.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/r/{path}', requirements: ['path' => '.+'])]
	public function asset(string $path, int $fileId = 0): Response {
		$engine = $this->engine();
		if ($engine === null) {
			return new DataDisplayResponse('the engine is not packaged (engine/MANIFEST.json)', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		if ($path === 'e2-editor.html') {
			// getWebroot(), not linkTo('', 'index.php'): with pretty URLs on, the latter
			// is "/", and "/" . "/apps/..." is a scheme-relative URL to a host named apps.
			return new RedirectResponse($this->urls->getWebroot() . '/index.php/apps/' . Application::APP_ID
				. '/r/' . $engine['buildId'] . '/e2-editor.html?fileId=' . $fileId);
		}
		$parts = explode('/', $path, 2);
		if (count($parts) !== 2 || $parts[0] !== $engine['buildId'] || !isset($engine['files'][$parts[1]])) {
			return new NotFoundResponse();
		}
		if ($parts[1] === 'e2-editor.html') {
			return $this->editor($fileId);
		}
		return $this->serve($parts[1], $engine['files'][$parts[1]]);
	}

	private function editor(int $fileId): Response {
		$target = $this->resolve($fileId);
		if (is_string($target)) {
			return new DataDisplayResponse($target, Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$html = (string)gzdecode((string)file_get_contents($this->packed('e2-editor.html')));
		if (!preg_match('#<style>(.*?)</style>#s', $html, $style) || !preg_match('#<body>(.*)</body>#s', $html, $body)
			|| substr_count($body[1], self::PRODUCT_SCRIPT) !== 1) {
			return new DataDisplayResponse('the shipped page no longer has the shape this app re-wraps', Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$this->initialState->provideInitialState('config', [
			'user' => $this->userSession->getUser()?->getUID(),
			'userDisplayName' => $this->userSession->getUser()?->getDisplayName(),
			'file' => $target,
			'editor' => ['module' => './e2-editor-app.js', 'sourceSha256' => hash('sha256', $html)],
		]);
		Util::addScript(Application::APP_ID, 'litecore-editor');
		$response = new TemplateResponse(Application::APP_ID, 'editor', [
			'style' => $style[1],
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
	 * The file as the current user sees it, or why not. Only the user's own
	 * folder: shares received are in it, public links are not (out of scope).
	 *
	 * `writable` decides whether the host takes a lock at all: a read-only share
	 * answers every PUT with 403, so there is nothing a lock would protect.
	 *
	 * @return array{id: int, name: string, size: int, etag: string, writable: bool, davUrl: string}|string
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
		return [
			'id' => $node->getId(),
			'name' => $node->getName(),
			'size' => $node->getSize(),
			'etag' => $node->getEtag(),
			'writable' => $node->isUpdateable(),
			'davUrl' => $this->urls->linkTo('', 'remote.php') . '/dav/files/'
				. rawurlencode($user->getUID()) . '/' . implode('/', $segments),
		];
	}

	/**
	 * The packaged bytes as they are, when the browser takes gzip; decoded on the
	 * way otherwise. Either way streamed, never read whole into memory.
	 *
	 * A dedicated worker takes its CSP from its own response, and Nextcloud
	 * always sends one; this grants what the product's workers do (importScripts,
	 * fetch, nested pthread workers, wasm compile). Scripts carry COEP, which is
	 * what lets them run as workers of an isolated page.
	 *
	 * @param array{bytes: int, gzBytes: int} $entry
	 */
	private function serve(string $path, array $entry): Response {
		$extension = pathinfo($path, PATHINFO_EXTENSION);
		$type = match ($extension) {
			'js', 'mjs' => 'text/javascript; charset=utf-8',
			'json' => 'application/json; charset=utf-8',
			'wasm' => 'application/wasm',
			default => 'application/octet-stream',
		};
		$packed = $this->packed($path);
		if ($this->acceptsGzip()) {
			$response = new StreamResponse($packed, Http::STATUS_OK, ['Content-Type' => $type,
				'Content-Encoding' => 'gzip', 'Content-Length' => (string)$entry['gzBytes']]);
		} else {
			$stream = fopen('compress.zlib://' . $packed, 'rb');
			if ($stream === false) {
				return new NotFoundResponse();
			}
			$response = new StreamResponse($stream, Http::STATUS_OK, ['Content-Type' => $type,
				'Content-Length' => (string)$entry['bytes']]);
		}
		$response->addHeader('Vary', 'Accept-Encoding');
		// The address carries the build id: these bytes never change under it.
		$response->cacheFor(31536000, false, true);
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedConnectDomain('\'self\'');
		$policy->addAllowedWorkerSrcDomain('\'self\'');
		$policy->allowEvalWasm(true);
		$response->setContentSecurityPolicy($policy);
		if (in_array($extension, ['js', 'mjs'], true)) {
			$response->addHeader('Cross-Origin-Embedder-Policy', 'require-corp');
		}
		return $response;
	}

	private function acceptsGzip(): bool {
		foreach (explode(',', (string)$this->request->getHeader('Accept-Encoding')) as $coding) {
			$fields = array_map('trim', explode(';', $coding));
			if (strtolower($fields[0]) !== 'gzip') {
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
	 * @return array{buildId: string, files: array<string, array{bytes: int, sha256: string, gzBytes: int, gzSha256: string}>}|null
	 */
	private function engine(): ?array {
		$manifest = json_decode((string)@file_get_contents($this->engineDir() . '/MANIFEST.json'), true);
		if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'litecore-engine/1'
			|| !is_string($manifest['buildId'] ?? null) || !is_array($manifest['files'] ?? null)) {
			return null;
		}
		return $manifest;
	}

	/** Only ever called with a path the manifest lists. */
	private function packed(string $path): string {
		return $this->engineDir() . '/files/' . $path . '.gz';
	}

	private function engineDir(): string {
		return dirname(__DIR__, 2) . '/engine';
	}
}
