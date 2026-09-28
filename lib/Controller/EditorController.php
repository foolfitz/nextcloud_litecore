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
 * script served by PHP with COEP (a static script from the app directory has no
 * COEP and the engine dies at boot -- E1), `.wasm` static, data files via PHP.
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

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/r/{path}', requirements: ['path' => '.+'])]
	public function asset(string $path, int $fileId = 0): Response {
		if (str_contains($path, '..') || str_contains($path, "\0")) {
			return new NotFoundResponse();
		}
		if ($path === 'e2-editor.html') {
			return $this->editor($fileId);
		}
		$file = $this->dist() . '/' . $path;
		if (!is_file($file)) {
			return new NotFoundResponse();
		}
		$extension = pathinfo($path, PATHINFO_EXTENSION);
		if ($extension === 'wasm') {
			// Apache serves it (whitelisted, application/wasm); same-origin, so
			// COEP does not apply to it.
			return new RedirectResponse($this->urls->linkTo(Application::APP_ID, 'dist/' . $path));
		}
		$script = in_array($extension, ['js', 'mjs'], true);
		$type = match ($extension) {
			'js', 'mjs' => 'text/javascript; charset=utf-8',
			'json' => 'application/json; charset=utf-8',
			'odt' => self::ODT,
			default => 'application/octet-stream',
		};
		return $this->serve((string)file_get_contents($file), $type, $script);
	}

	private function editor(int $fileId): Response {
		$target = $this->resolve($fileId);
		if (is_string($target)) {
			return new DataDisplayResponse($target, Http::STATUS_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
		}
		$source = $this->dist() . '/e2-editor.html';
		$html = (string)file_get_contents($source);
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
	 * A dedicated worker takes its CSP from its own response, and Nextcloud
	 * always sends one; this grants what the product's workers do (importScripts,
	 * fetch, nested pthread workers, wasm compile). Scripts carry COEP, which is
	 * what lets them run as workers of an isolated page.
	 */
	private function serve(string $bytes, string $type, bool $coep): Response {
		$response = new DataDisplayResponse($bytes, Http::STATUS_OK, ['Content-Type' => $type, 'Cache-Control' => 'no-cache']);
		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedScriptDomain('\'self\'');
		$policy->addAllowedConnectDomain('\'self\'');
		$policy->addAllowedWorkerSrcDomain('\'self\'');
		$policy->allowEvalWasm(true);
		$response->setContentSecurityPolicy($policy);
		if ($coep) {
			$response->addHeader('Cross-Origin-Embedder-Policy', 'require-corp');
		}
		return $response;
	}

	private function dist(): string {
		return dirname(__DIR__, 2) . '/dist';
	}
}
