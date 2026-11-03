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
 * section 3.1). Only the .odt mimetype: the picker then offers "Blank" plus the
 * user's own .odt files from their Templates folder, never an .ott (core would
 * copy an .ott's bytes into the .odt as they are).
 *
 * "+ New -> New spreadsheet" the same way (specs/SPEC-P-demo-batch-6.md 1.1),
 * registered as richdocuments registers its own, .ods only. Both icons are
 * LibreOffice's document icons, img/README.md.
 *
 * @template-implements IEventListener<Event|RegisterTemplateCreatorEvent>
 */
class RegisterTemplateCreatorListener implements IEventListener {
	private const ODT = 'application/vnd.oasis.opendocument.text';
	private const ODS = 'application/vnd.oasis.opendocument.spreadsheet';

	public function __construct(
		private IL10N $l10n,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof RegisterTemplateCreatorEvent) {
			return;
		}
		$manager = $event->getTemplateManager();
		$manager->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New document'), '.odt');
			$creator->addMimetype(self::ODT);
			$creator->setIconSvgInline(self::icon('oasis-text.svg'));
			$creator->setRatio(21 / 29.7);
			return $creator;
		});
		$manager->registerTemplateFileCreator(function (): TemplateFileCreator {
			$creator = new TemplateFileCreator(Application::APP_ID, $this->l10n->t('New spreadsheet'), '.ods');
			$creator->addMimetype(self::ODS);
			$creator->setIconSvgInline(self::icon('oasis-spreadsheet.svg'));
			$creator->setRatio(16 / 9);
			return $creator;
		});
	}

	/** One of the app's icons, inline; throws rather than register a creator with no icon. */
	private static function icon(string $name): string {
		$svg = @file_get_contents(__DIR__ . '/../../img/' . $name);
		if ($svg === false || $svg === '') {
			throw new \RuntimeException("LiteCore: img/$name is missing");
		}
		return $svg;
	}
}
