<?php

declare(strict_types=1);

use Nvl\Csv\Enums\CSVTypeEnum;
use Nvl\Csv\Filters\CSVFilter;
use Nvl\Csv\Support\CSVMemoryManager;
use Nvl\Csv\Transformers\CSVTransformer;
use Nvl\Csv\Transformers\DateTransformer;
use Nvl\Csv\Transformers\NumericTransformer;
use Nvl\Csv\Transformers\StringTransformer;
use Nvl\Csv\Validators\CSVFieldValidator;
use Nvl\Csv\Validators\CSVRowValidator;
use Nvl\Csv\ValueObjects\CSVFieldMapping;

it('applies Unicode-safe string transformations and factories', function (): void {
    $transformer = (new StringTransformer)
        ->trim()
        ->replace('old', 'new')
        ->toCase(StringTransformer::CASE_TITLE)
        ->prefix('[')
        ->suffix(']')
        ->maxLength(12, '…');

    expect($transformer->transform('  old value here  '))->toBe('[New Value …')
        ->and((new StringTransformer)->default('fallback')->transform(''))->toBe('fallback')
        ->and(StringTransformer::lowercase()->transform('  ÄBC '))->toBe('äbc')
        ->and(StringTransformer::uppercase()->transform('hello'))->toBe('HELLO')
        ->and(StringTransformer::slug()->transform('Hello World_Name'))->toBe('hello-world-name')
        ->and(StringTransformer::sanitize()->transform(" A\tB\nC "))->toBe('A B C')
        ->and((new StringTransformer)->toCase(StringTransformer::CASE_CAMEL)->transform('hello_world'))->toBe('helloWorld')
        ->and((new StringTransformer)->toCase(StringTransformer::CASE_SNAKE)->transform('helloWorld'))->toBe('hello_world');

    expect(fn () => (new StringTransformer)->toCase('unknown'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new StringTransformer)->maxLength(-1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new StringTransformer)->transform([]))
        ->toThrow(InvalidArgumentException::class);
});

it('applies numeric extraction, arithmetic, bounds, and rounding modes', function (): void {
    $value = (new NumericTransformer)
        ->multiply(2)
        ->divide(4)
        ->add(3)
        ->subtract(1)
        ->absolute()
        ->min(0)
        ->max(10)
        ->precision(2)
        ->transform('$12.345');

    expect($value)->toBe(8.17)
        ->and((new NumericTransformer)->default(7)->transform('none'))->toBe(7.0)
        ->and((new NumericTransformer)->default(7)->transform([]))->toBe(7.0)
        ->and((new NumericTransformer)->precision(1)->round()->transform(1.26))->toBe(1.3)
        ->and((new NumericTransformer)->floor()->transform(1.9))->toBe(1.0)
        ->and((new NumericTransformer)->ceil()->transform(1.1))->toBe(2.0)
        ->and(NumericTransformer::percentage()->transform(0.125))->toBe(12.5)
        ->and(NumericTransformer::currency()->transform(-5))->toBe(0.0)
        ->and(NumericTransformer::integer()->transform(4.6))->toBe(5.0)
        ->and(NumericTransformer::positive()->transform(-8))->toBe(8.0);

    expect(fn () => (new NumericTransformer)->divide(0))
        ->toThrow(InvalidArgumentException::class);
});

it('parses, adjusts, formats, and defaults dates', function (): void {
    $adjusted = DateTransformer::format('d/m/Y', 'Y-m-d H:i:s')
        ->timezone('UTC')
        ->startOfDay()
        ->endOfDay(false)
        ->addDays(1)
        ->addMonths(1)
        ->addYears(1)
        ->transform('15/01/2025');

    expect($adjusted)->toBe('2026-02-16 00:00:00')
        ->and((new DateTransformer)->endOfDay()->outputFormat('H:i:s')->transform('2026-01-01'))->toBe('23:59:59')
        ->and(DateTransformer::iso()->transform('2026-01-01'))->toBe('2026-01-01T00:00:00Z')
        ->and(DateTransformer::timestamp()->transform('1970-01-02 UTC'))->toBe('86400')
        ->and(DateTransformer::age()->transform(now()->subYears(25)))->toBe(25)
        ->and(DateTransformer::age()->transform('invalid'))->toBeNull()
        ->and((new DateTransformer)->default('fallback')->transform([]))->toBe('fallback')
        ->and((new DateTransformer)->default('fallback')->inputFormat('Y-m-d')->transform('bad'))->toBe('fallback')
        ->and((new DateTransformer)->default('fallback')->inputFormat('Y-m-d')->transform('2026-02-31'))->toBe('fallback')
        ->and(DateTransformer::iso()->transform('2026-01-01 02:00:00 Europe/Sofia'))->toBe('2026-01-01T00:00:00Z');
});

it('chains and conditionally applies reusable transformers', function (): void {
    $chain = CSVTransformer::chain([
        StringTransformer::sanitize(),
    ])->add(StringTransformer::uppercase());

    $conditional = CSVTransformer::when(
        fn (mixed $value, array $context): bool => $context['enabled'] === true && is_string($value),
        StringTransformer::uppercase(),
    )->else(StringTransformer::lowercase());

    $reversible = new class extends CSVTransformer
    {
        public function transform(mixed $value, array $context = []): mixed
        {
            return is_int($value) ? $value + 1 : $value;
        }

        public function reverseTransform(mixed $value, array $context = []): mixed
        {
            return is_int($value) ? $value - 1 : $value;
        }

        public function supportsReverseTransform(): bool
        {
            return true;
        }
    };

    $reversibleChain = CSVTransformer::chain([$reversible]);

    expect($chain->transform(" hello\nworld "))->toBe('HELLO WORLD')
        ->and($chain->supportsReverseTransform())->toBeFalse()
        ->and($conditional->transform('Mixed', ['enabled' => true]))->toBe('MIXED')
        ->and($conditional->transform('Mixed', ['enabled' => false]))->toBe('mixed')
        ->and(CSVTransformer::when(fn (): bool => false, StringTransformer::uppercase())->transform('Same'))->toBe('Same')
        ->and($reversibleChain->transform(1))->toBe(2)
        ->and($reversibleChain->supportsReverseTransform())->toBeTrue()
        ->and($reversibleChain->reverseTransform(2))->toBe(1)
        ->and($reversible->reverseTransform(2))->toBe(1);
});

it('composes field, custom, boolean, and regex filters', function (): void {
    $row = ['name' => 'Jane Doe', 'age' => 30, 'status' => 'active', 'empty' => '', 'nullable' => null];

    expect(CSVFilter::field('age')->equals(30)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->notEquals(20)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->greaterThan(20)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->greaterThanOrEqual(30)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->lessThan(40)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->lessThanOrEqual(30)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('name')->contains('jane', true)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('name')->startsWith('jane', true)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('name')->endsWith('DOE', true)->passes($row))->toBeTrue()
        ->and(CSVFilter::field('status')->in(['active'])->passes($row))->toBeTrue()
        ->and(CSVFilter::field('status')->notIn(['archived'])->passes($row))->toBeTrue()
        ->and(CSVFilter::field('nullable')->isNull()->passes($row))->toBeTrue()
        ->and(CSVFilter::field('status')->isNotNull()->passes($row))->toBeTrue()
        ->and(CSVFilter::field('empty')->isEmpty()->passes($row))->toBeTrue()
        ->and(CSVFilter::field('name')->isNotEmpty()->passes($row))->toBeTrue()
        ->and(CSVFilter::field('name')->matches('/^Jane/')->passes($row))->toBeTrue()
        ->and(CSVFilter::field('age')->between(20, 40)->passes($row))->toBeTrue()
        ->and(CSVFilter::custom(fn (array $data): bool => $data['age'] === 30)->passes($row))->toBeTrue();

    $adult = CSVFilter::field('age')->greaterThanOrEqual(18);
    $active = CSVFilter::field('status')->equals('active');

    expect(CSVFilter::all([$adult])->add($active)->passes($row))->toBeTrue()
        ->and(CSVFilter::any([CSVFilter::field('age')->lessThan(18)])->add($active)->passes($row))->toBeTrue()
        ->and($active->not()->passes($row))->toBeFalse()
        ->and($adult->and($active)->passes($row))->toBeTrue()
        ->and($adult->or(CSVFilter::field('age')->lessThan(18))->passes($row))->toBeTrue();

    expect(fn () => CSVFilter::field('name')->matches('/[/'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => CSVFilter::field('name')->passes($row))
        ->toThrow(LogicException::class);
});

it('validates fields with types, constraints, patterns, values, and callbacks', function (): void {
    $validator = (new CSVFieldValidator)
        ->required()
        ->nullable(false)
        ->type(CSVTypeEnum::STRING)
        ->length(3, 10)
        ->pattern('/^[a-z]+$/')
        ->in(['allowed'])
        ->custom(fn (mixed $value): bool|string => $value === 'allowed' ?: 'Custom mismatch');

    expect($validator->validate('allowed'))->toBeTrue()
        ->and($validator->validate('NO'))->toBeFalse()
        ->and($validator->getErrors())->not->toBeEmpty()
        ->and($validator->validate(null))->toBeFalse()
        ->and(CSVFieldValidator::email()->validate('person@example.com'))->toBeTrue()
        ->and(CSVFieldValidator::email()->validate('invalid'))->toBeFalse()
        ->and(CSVFieldValidator::url()->validate('https://example.com'))->toBeTrue()
        ->and(CSVFieldValidator::url()->validate('bad'))->toBeFalse()
        ->and(CSVFieldValidator::phone()->validate('+359 888 123 456'))->toBeTrue()
        ->and(CSVFieldValidator::date()->validate('2026-01-01'))->toBeTrue()
        ->and(CSVFieldValidator::date()->validate('2026-02-31'))->toBeFalse()
        ->and(CSVFieldValidator::numeric(1, 5)->validate('3.5'))->toBeTrue()
        ->and(CSVFieldValidator::integer(1, 5)->validate('3'))->toBeTrue()
        ->and(CSVFieldValidator::boolean()->validate('yes'))->toBeTrue();

    expect(fn () => (new CSVFieldValidator)->length(-1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new CSVFieldValidator)->length(5, 1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new CSVFieldValidator)->range(5, 1))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new CSVFieldValidator)->pattern('/[/'))
        ->toThrow(InvalidArgumentException::class);
});

it('validates complete rows, dependencies, uniqueness, and reusable error state', function (): void {
    $validator = (new CSVRowValidator)
        ->addFieldValidator('email', CSVFieldValidator::email()->required())
        ->addFieldMapping('age', CSVFieldMapping::typed('age', 'age', CSVTypeEnum::INTEGER, true))
        ->addRowValidator(fn (array $row): bool|string => $row['age'] >= 18 ?: 'Must be an adult');

    expect($validator->validate(['email' => 'person@example.com', 'age' => '30']))->toBeTrue()
        ->and($validator->validate(['email' => 'bad', 'age' => '17']))->toBeFalse()
        ->and($validator->getErrors())->not->toBeEmpty()
        ->and($validator->validate('not-an-array'))->toBeFalse()
        ->and($validator->getErrors())->toBe(['Row must be an array'])
        ->and($validator->validateDependencies([]))->toBeTrue()
        ->and($validator->validateCompleteness(['id' => 1], ['id']))->toBeTrue()
        ->and($validator->validateCompleteness([], ['id']))->toBeFalse()
        ->and($validator->validateUniqueness(
            ['email' => 'new@example.com'],
            [['email' => 'old@example.com']],
            ['email'],
        ))->toBeTrue()
        ->and($validator->validateUniqueness(
            ['email' => 'old@example.com'],
            [['email' => 'old@example.com']],
            ['email'],
        ))->toBeFalse();
});

it('monitors memory without mutating global opcode state', function (): void {
    $manager = new CSVMemoryManager(memory_get_usage(true) + 64 * 1024 * 1024);
    $monitored = $manager->monitor(fn (): string => str_repeat('x', 10));

    expect($monitored)->toBe('xxxxxxxxxx')
        ->and($manager->getLimit())->toBeGreaterThan(0)
        ->and($manager->getInitialUsage())->toBeGreaterThan(0)
        ->and($manager->getCurrentUsage())->toBeGreaterThan(0)
        ->and($manager->getPeakUsage())->toBeGreaterThan(0)
        ->and($manager->getAvailable())->toBeGreaterThanOrEqual(0)
        ->and($manager->getUsagePercentage())->toBeGreaterThan(0)
        ->and($manager->canAllocate(1))->toBeTrue()
        ->and($manager->estimateMemoryForRows(10))->toBe(12288)
        ->and($manager->calculateOptimalChunkSize())->toBeBetween(10, 10000)
        ->and($manager->getStatistics())->toHaveKeys([
            'limit', 'current', 'peak', 'available', 'percentage', 'is_critical', 'auto_cleanup',
        ]);

    $manager->disableAutoCleanup();
    $manager->enableAutoCleanup(90);
    $manager->cleanup();
    $manager->setLimit(-1);

    expect($manager->getAvailable())->toBe(PHP_INT_MAX)
        ->and($manager->getUsagePercentage())->toBe(0.0)
        ->and($manager->canAllocate(PHP_INT_MAX))->toBeTrue()
        ->and($manager->calculateOptimalChunkSize())->toBe(10000);

    expect(fn () => $manager->estimateMemoryForRows(-1))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new CSVMemoryManager(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->setLimit(-2))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->canAllocate(-1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->enableAutoCleanup(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $manager->calculateOptimalChunkSize(0))->toThrow(InvalidArgumentException::class);
});
