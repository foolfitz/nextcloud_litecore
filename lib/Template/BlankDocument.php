<?php

declare(strict_types=1);

namespace OCA\LiteCore\Template;

/**
 * Which blank document a user gets from "+ New -> New document", and its bytes
 * (study_LiteCore specs/SPEC-M3-new-document.md, sections 3.2 and 3.3).
 *
 * A style-less ODT is not neutral in the shipped engine: it comes out Letter,
 * with a browser-dependent Asian language and a Korean font name. So the host
 * ships blank documents that STATE their defaults, one file per
 * (western, asian, paper) triple, generated deterministically by
 * study_LiteCore's wasm_sdk_probe/tools/make_new_document_templates.py into
 * `blank/`, with every file's sha256 in `blank/MANIFEST.json`.
 *
 * No Nextcloud dependency on purpose: `php -r` can call choose() and read(),
 * and the runner does (its N2 and N12 take this class as the truth source).
 */
final class BlankDocument {
	public const DIR = __DIR__ . '/../../blank';

	/** Nextcloud language -> the western tag the blank document states. */
	public const WESTERN = [
		'en' => 'en-US', 'en_GB' => 'en-GB',
		'de' => 'de-DE', 'de_DE' => 'de-DE',
		'fr' => 'fr-FR',
		'es' => 'es-ES', 'es_MX' => 'es-MX', 'es_EC' => 'es-EC',
		'it' => 'it-IT',
		'nl' => 'nl-NL',
		'pt_BR' => 'pt-BR', 'pt_PT' => 'pt-PT',
	];
	/** Nextcloud language -> the Asian tag, for the users whose own language is Asian-script. */
	public const ASIAN = ['zh_TW' => 'zh-TW', 'zh_HK' => 'zh-HK', 'zh_CN' => 'zh-CN', 'ja' => 'ja-JP', 'ko' => 'ko-KR'];
	/** Everyone else: LibreOffice's own answer for a zh-TW system, and the product's deployment. */
	public const DEFAULT_WESTERN = 'en-US';
	public const DEFAULT_ASIAN = 'zh-TW';
	/** i18nutil/source/utility/paper.cxx:440-500: every other country is A4. */
	public const LETTER_COUNTRIES = ['US', 'PR', 'CA', 'VE', 'CL', 'MX', 'CO', 'PH', 'BZ', 'CR', 'GT', 'NI', 'PA', 'SV'];

	/**
	 * @param string $language the user's Nextcloud language (IFactory::getUserLanguage)
	 * @param string|null $locale the locale the user, or the admin, EXPLICITLY set
	 *                            (force_locale, core/locale, default_locale); null when none.
	 *                            Not IFactory::findLocale(): that fills zh_TW in as en_US.
	 * @return array{western: string, asian: string, paper: string, file: string, country: ?string, countryFrom: ?string}
	 */
	public static function choose(string $language, ?string $locale): array {
		$western = self::WESTERN[$language] ?? self::DEFAULT_WESTERN;
		$asian = self::ASIAN[$language] ?? self::DEFAULT_ASIAN;
		$country = self::country($locale);
		$countryFrom = $country !== null ? 'locale' : null;
		if ($country === null) {
			$country = self::country($language);
			$countryFrom = $country !== null ? 'language' : null;
		}
		$paper = ($country !== null && in_array($country, self::LETTER_COUNTRIES, true)) ? 'Letter' : 'A4';
		return [
			'western' => $western,
			'asian' => $asian,
			'paper' => $paper,
			'file' => "{$western}_{$asian}_{$paper}.odt",
			'country' => $country,
			'countryFrom' => $countryFrom,
		];
	}

	/** The country segment of a tag such as en_US, zh_Hant_TW or en-GB; null when it has none (en, zh, es_419). */
	public static function country(?string $tag): ?string {
		if ($tag === null || $tag === '') {
			return null;
		}
		$parts = preg_split('/[_-]/', $tag) ?: [];
		if (count($parts) < 2) {
			return null;
		}
		$last = (string)end($parts);
		return preg_match('/^[A-Z]{2}$/', $last) === 1 ? $last : null;
	}

	/** @return array{files: array<string, array{sha256: string, bytes: int}>} */
	public static function manifest(): array {
		$path = self::DIR . '/MANIFEST.json';
		$raw = @file_get_contents($path);
		if ($raw === false || $raw === '') {
			throw new \RuntimeException("blank documents: $path is missing or empty");
		}
		$manifest = json_decode($raw, true);
		if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
			throw new \RuntimeException("blank documents: $path is not the generator's manifest");
		}
		return $manifest;
	}

	/** The bytes of one blank document, verified against MANIFEST.json; throws rather than return anything else. */
	public static function read(string $file): string {
		$manifest = self::manifest();
		if (!isset($manifest['files'][$file]['sha256'])) {
			throw new \RuntimeException("blank documents: $file is not in MANIFEST.json");
		}
		$bytes = @file_get_contents(self::DIR . '/' . $file);
		if ($bytes === false || $bytes === '') {
			throw new \RuntimeException("blank documents: $file is missing or empty");
		}
		if (hash('sha256', $bytes) !== $manifest['files'][$file]['sha256']) {
			throw new \RuntimeException("blank documents: $file does not match MANIFEST.json");
		}
		return $bytes;
	}
}
