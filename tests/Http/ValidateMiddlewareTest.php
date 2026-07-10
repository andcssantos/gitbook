<?php

namespace Tests\Http;

use App\Middlewares\ValidateMiddleware;
use App\Validation\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ValidateMiddlewareTest extends TestCase
{
    public function testSplitFieldRulesPreservesInListCommas(): void
    {
        $rules = $this->splitFieldRules([
            'mode=nullable|string|in:type',
            'rarity',
            'size',
            'name',
            'compact',
        ]);

        $this->assertSame(['mode=nullable|string|in:type,rarity,size,name,compact'], $rules);
    }

    public function testOrganizeModeValidationAcceptsCompact(): void
    {
        $rules = $this->splitFieldRules(['mode=nullable|string|in:type,rarity,size,name,compact']);
        $parsed = [];
        foreach ($rules as $rule) {
            [$field, $definition] = array_pad(explode('=', $rule, 2), 2, '');
            if ($field !== '' && $definition !== '') {
                $parsed[$field] = $definition;
            }
        }

        $validated = Validator::make(['mode' => 'compact'], $parsed)->validate();

        $this->assertSame('compact', $validated['mode']);
    }

    /** @param list<string> $rules */
    private function splitFieldRules(array $rules): array
    {
        $middleware = new ValidateMiddleware();
        $method = new ReflectionMethod(ValidateMiddleware::class, 'normalizeRules');
        $method->setAccessible(true);

        return $method->invoke($middleware, $rules);
    }
}
