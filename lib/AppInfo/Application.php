<?php

declare(strict_types=1);

namespace OCA\LiteCore\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\LiteCore\Listener\FilesScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'litecore';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Files only: the action that opens an ODT here. No header is set on any
		// page this app does not own (nextcloud/richdocuments#4103).
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptsListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
