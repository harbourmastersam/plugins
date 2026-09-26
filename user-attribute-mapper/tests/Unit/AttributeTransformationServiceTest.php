<?php

use HarbourmasterSam\UserAttributeMapper\Services\AttributeTransformationService;

it('applies the safe literal transformations in order', function (): void {
    $result = (new AttributeTransformationService())->transform(' SAM@EXAMPLE.COM ', [
        ['type' => 'trim'], ['type' => 'lowercase'], ['type' => 'prefix', 'value' => 'school-'],
        ['type' => 'suffix', 'value' => '!'], ['type' => 'replace', 'from' => '@example.com', 'to' => '@example.org'],
    ]);
    expect($result)->toBe('school-sam@example.org!');
});

it('rejects unknown transforms and malformed arguments', function (): void {
    $service = new AttributeTransformationService();
    expect(fn () => $service->transform('x', [['type' => 'regex']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->transform('x', [['type' => 'prefix']]))->toThrow(InvalidArgumentException::class);
});
