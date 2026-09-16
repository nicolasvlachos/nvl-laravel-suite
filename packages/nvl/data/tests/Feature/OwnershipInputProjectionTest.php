<?php

declare(strict_types=1);

use Nvl\Data\Tests\Fixtures\OwnershipProjectionData;

test('undeclared ownership input is absent from persistence projection', function (): void {
    $data = OwnershipProjectionData::from(['name' => 'safe', 'tenantId' => 'forged']);

    expect($data->toModelFiltered())->toBe(['name' => 'safe']);
});
