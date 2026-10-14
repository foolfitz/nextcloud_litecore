<?php

declare(strict_types=1);

namespace OCA\LiteCore\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\LiteCore\Listener\FileCreatedFromTemplateListener;
use OCA\LiteCore\Listener\FilesScriptsListener;
use OCA\LiteCore\Listener\RegisterTemplateCreatorListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\Files\Template\RegisterTemplateCreatorEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'litecore';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Files only: the action that opens an ODT here. No header is set on any
		// page this app does not own (nextcloud/richdocuments#4103).
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, FilesScriptsListener::class);
		// "+ New -> New document": the entry, and the blank document that states
		// its defaults per user (study_LiteCore specs/SPEC-M3-new-document.md).
		$context->registerEventListener(RegisterTemplateCreatorEvent::class, RegisterTemplateCreatorListener::class);
		$context->registerEventListener(FileCreatedFromTemplateEvent::class, FileCreatedFromTemplateListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
