<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Support\Translator;

/**
 * Locale behaviour, including the part that used to be hardcoded.
 *
 * A site declares the languages it ships. Everything else — detection, the
 * switcher, the parity test — follows from that list, so a site that ships one
 * language must never advertise a second one.
 */
final class TranslatorLocaleTest extends TestCase
{
    /** @var array<int,string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testASingleLanguageSiteDoesNotDetectASecondLanguage(): void
    {
        $translator = $this->translator(['en'], ['en' => $this->messages(['common.save' => 'Save'])]);

        $this->assertSame('en', Translator::detect(null, 'es', 'es-ES,es;q=0.9'));
        $this->assertSame(['en'], $translator->supportedLocales());
        $this->assertFalse($translator->supports('es'));
    }

    public function testASpanishSiteDetectsSpanishFromTheBrowser(): void
    {
        $translator = $this->translator(['en', 'es'], [
            'en' => $this->messages(['common.save' => 'Save']),
            'es' => $this->messages(['common.save' => 'Guardar']),
        ]);

        $this->assertSame('es', Translator::detect(null, null, 'es-ES,es;q=0.9'));
        $this->assertSame('en', Translator::detect(null, null, 'fr-FR,fr'));
        $this->assertSame('es', Translator::detect('es', null, 'en'));
    }

    public function testAUserPreferenceWinsOverTheSessionAndTheBrowser(): void
    {
        $this->translator(['en', 'es'], ['en' => [], 'es' => []]);

        $this->assertSame('es', Translator::detect('es', 'en', 'en-GB,en'));
    }

    public function testATemporaryLocaleIsRestoredEvenWhenTheCallbackThrows(): void
    {
        $translator = $this->translator(['en', 'es'], [
            'en' => $this->messages(['common.save' => 'Save']),
            'es' => $this->messages(['common.save' => 'Guardar']),
        ]);

        try {
            $translator->withLocale('es', static function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('The exception must propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('en', $translator->getLocale());
    }

    public function testAMissingKeyFallsBackToTheDefaultLanguage(): void
    {
        $translator = $this->translator(['en', 'es'], [
            'en' => $this->messages(['common.save' => 'Save', 'only.en' => 'English only']),
            'es' => $this->messages(['common.save' => 'Guardar']),
        ]);
        $translator->setLocale('es');

        $this->assertSame('Guardar', $translator->trans('common.save'));
        $this->assertSame('English only', $translator->trans('only.en'));
        $this->assertSame('missing.key', $translator->trans('missing.key'));
    }

    public function testParametersAreReplacedAndDoNotOverlap(): void
    {
        $translator = $this->translator(['en'], [
            'en' => $this->messages(['hi' => 'Hello :name, you are :admin', 'field' => ':a is required.']),
        ]);

        $this->assertSame('Hello Jane, you are :admin', $translator->trans('hi', ['name' => 'Jane']));
        $this->assertSame('Email is required.', $translator->trans('field', ['a' => 'Email']));
    }

    /**
     * @param array<int,string> $locales
     * @param array<string,array<string,string>> $files
     */
    private function translator(array $locales, array $files): Translator
    {
        $directory = sys_get_temp_dir() . '/polaris_lang_' . bin2hex(random_bytes(4));

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->tempDirs[] = $directory;

        foreach ($files as $locale => $messages) {
            file_put_contents(
                $directory . '/' . $locale . '.php',
                "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($messages, true) . ";\n",
            );
        }

        $translator = new Translator($directory, 'en', null, $locales);
        $translator->setLocale('en');

        return $translator;
    }

    /** @param array<string,string> $messages */
    private function messages(array $messages): array
    {
        return $messages;
    }
}
