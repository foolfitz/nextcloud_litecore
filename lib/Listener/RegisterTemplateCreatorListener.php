<?php

declare(strict_types=1);

namespace OCA\LiteCore\Listener;

use OCA\LiteCore\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Template\RegisterTemplateCreatorEvent;
use OCP\Files\Template\TemplateFileCreator;
use OCP\IL10N;

/**
 * "+ New -> New document" in Files (study_LiteCore specs/SPEC-M3-new-document.md
 * section 3.1). Text documents only, and only the .odt mimetype: the picker then
 * offers "Blank" plus the user's own .odt files from their Templates folder,
 * never an .ott (core would copy an .ott's bytes into the .odt as they are).
 *
 * @template-implements IEventListener<Event|RegisterTemplateCreatorEvent>
 */
class RegisterTemplateCreatorListener implements IEventListener {
	private const ODT = 'application/vnd.oasis.opendocument.text';
	// The same glyph as the Files action in src/files.js.
	private const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm-1 7V3.5L18.5 9zM7 13h10v2H7zm0 4h7v2H7z"/></svg>';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterTemplateCreatorEvent) {
			return;
		}
		$event->getTemplateManager()->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New document'), '.odt');
			$creator->addMimetype(self::ODT);
			$creator->setIconSvgInline(self::ICON);
			$creator->setRatio(21 / 29.7);
			return $creator;
		});
	}
}
