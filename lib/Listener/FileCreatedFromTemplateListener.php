<?php

declare(strict_types=1);

namespace OCA\LiteCore\Listener;

use OCA\LiteCore\Template\BlankDocument;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\GenericFileException;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * Writes the blank document into the file core just created from "+ New ->
 * New document" (study_LiteCore specs/SPEC-M3-new-document.md, sections 3.2
 * and 3.4). Core creates the file EMPTY when no template was picked and then
 * dispatches this event; a user's own .odt template is copied by core itself
 * and left alone here.
 *
 * Which blank document: BlankDocument::choose() from the user's Nextcloud
 * language and the locale that was explicitly set (force_locale, the user's
 * own, default_locale) -- not IFactory::findLocale(), which fills zh_TW in as
 * en_US and would hand a Taiwanese user Letter.
 *
 * Nothing is produced silently: if the template is missing, does not match
 * its recorded sha256, cannot be written, or the written size differs, the
 * just-created empty file is removed and the exception is rethrown, so core
 * answers with its own "Failed to create file from template" and the folder
 * holds no 0-byte file.
 *
 * @template-implements IEventListener<Event|FileCreatedFromTemplateEvent>
 */
class FileCreatedFromTemplateListener implements IEventListener {
	private const ODT = 'application/vnd.oasis.opendocument.text';

	public function __construct(
		private IUserSession $userSession,
		private IFactory $l10nFactory,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof FileCreatedFromTemplateEvent) {
			return;
		}
		$target = $event->getTarget();
		if ($target->getMimeType() !== self::ODT && strtolower($target->getExtension()) !== 'odt') {
			return;
		}
		if ($event->getTemplate() !== null) {
			// The user's own template: core already wrote its bytes.
			return;
		}

		$user = $this->userSession->getUser();
		$language = $this->l10nFactory->getUserLanguage($user);
		$locale = $this->explicitLocale($user);
		$choice = BlankDocument::choose($language, $locale);
		try {
			$bytes = BlankDocument::read($choice['file']);
			$target->putContent($bytes);
			$size = $target->getSize();
			if ($size !== strlen($bytes)) {
				throw new \RuntimeException('wrote ' . strlen($bytes) . " bytes, the file reports $size");
			}
		} catch (\Throwable $error) {
			$this->logger->error('LiteCore: the blank document could not be written; the new file was removed', [
				'exception' => $error, 'language' => $language, 'locale' => $locale, 'choice' => $choice,
			]);
			try {
				$target->delete();
			} catch (\Throwable $deleteError) {
				$this->logger->error('LiteCore: and the empty file could not be removed either', ['exception' => $deleteError]);
			}
			throw new GenericFileException('LiteCore could not write the blank document: ' . $error->getMessage(), 0, $error);
		}
	}

	/** The locale somebody set on purpose, or null: force_locale, then the user's own, then default_locale. */
	private function explicitLocale(?IUser $user): ?string {
		$forced = $this->config->getSystemValue('force_locale', false);
		if (is_string($forced) && $forced !== '') {
			return $forced;
		}
		if ($user !== null) {
			$own = $this->config->getUserValue($user->getUID(), 'core', 'locale', '');
			if (is_string($own) && $own !== '') {
				return $own;
			}
		}
		$default = $this->config->getSystemValue('default_locale', false);
		return (is_string($default) && $default !== '') ? $default : null;
	}
}
