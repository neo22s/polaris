<?php

declare(strict_types=1);

namespace Polaris\Tests;

use Polaris\Support\Validator;
use PHPUnit\Framework\TestCase;
use Polaris\Tests\Support\RestoresTheTranslator;

final class ValidatorTest extends TestCase
{
    use RestoresTheTranslator;

    public function testRequiredFieldFails(): void
    {
        $validator = new Validator(['email' => ''], ['email' => 'required|email']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email', $validator->errors());
    }

    public function testOptionalEmptyFieldPasses(): void
    {
        $validator = new Validator(['phone' => ''], ['phone' => 'max:40']);

        $this->assertTrue($validator->passes());
    }

    public function testEmailValidation(): void
    {
        $validator = new Validator(['email' => 'not-an-email'], ['email' => 'required|email']);

        $this->assertTrue($validator->fails());
    }

    public function testHandleFormat(): void
    {
        $valid = new Validator(['handle' => 'jane-doe'], ['handle' => 'handle']);
        $this->assertTrue($valid->passes());

        foreach (['ab', '-jane', 'jane-', 'Jane', 'jane_doe'] as $bad) {
            $check = new Validator(['handle' => $bad], ['handle' => 'handle']);
            $this->assertTrue($check->fails(), "Expected '{$bad}' to fail handle validation.");
        }
    }

    public function testBetweenRule(): void
    {
        $this->assertTrue((new Validator(['height_cm' => '175'], ['height_cm' => 'between:100,230']))->passes());
        $this->assertTrue((new Validator(['height_cm' => '300'], ['height_cm' => 'between:100,230']))->fails());
    }

    public function testYoutubeRule(): void
    {
        foreach (['https://youtu.be/dQw4w9WgXcQ', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'] as $url) {
            $this->assertTrue((new Validator(['selftape_url' => $url], ['selftape_url' => 'youtube']))->passes());
        }

        $this->assertTrue((new Validator(['selftape_url' => 'https://vimeo.com/123'], ['selftape_url' => 'youtube']))->fails());
    }

    public function testCustomLabelInMessage(): void
    {
        $validator = new Validator(['contact_email' => 'nope'], ['contact_email' => 'email'], ['contact_email' => 'Contact email']);

        $this->assertStringContainsString('Contact email', (string) $validator->firstError());
    }

    /**
     * Every rule the application uses, with a value it accepts and one it
     * refuses.
     *
     * @return array<string,array{0:string,1:mixed,2:mixed}>
     */
    public static function rules(): array
    {
        return [
            'email' => ['email', 'jane@example.com', 'not-an-email'],
            'url' => ['url', 'https://example.com/x', 'not a url'],
            'numeric' => ['numeric', '42', 'many'],
            'integer' => ['integer', '42', '4.2'],
            'min' => ['min:3', 'abcd', 'ab'],
            'max' => ['max:3', 'abc', 'abcd'],
            'between' => ['between:10,20', '15', '25'],
            'in' => ['in:es,en', 'es', 'fr'],
            'date' => ['date', '2026-01-31', 'not a date'],
            'handle' => ['handle', 'jane-doe', 'Jane_Doe'],
            'youtube' => ['youtube', 'https://youtu.be/dQw4w9WgXcQ', 'https://vimeo.com/1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rules')]
    public function testEveryRuleAcceptsAGoodValueAndRefusesABadOne(string $rule, mixed $good, mixed $bad): void
    {
        $this->assertTrue((new Validator(['f' => $good], ['f' => $rule]))->passes(), $rule . ' should accept ' . var_export($good, true));
        $this->assertTrue((new Validator(['f' => $bad], ['f' => $rule]))->fails(), $rule . ' should refuse ' . var_export($bad, true));
    }

    public function testAnUnknownRuleIsRefused(): void
    {
        // A misspelled rule would silently turn the field into an unvalidated
        // one, so it has to be loud.
        $this->expectException(\InvalidArgumentException::class);
        new Validator(['email' => 'nope'], ['email' => 'requred|email']);
    }
}
