<?php

declare(strict_types=1);

use ProductTrap\Contracts\Factory;
use ProductTrap\DTOs\Product;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ApiConnectionFailedException;
use ProductTrap\Facades\ProductTrap as FacadesProductTrap;
use ProductTrap\ProductTrap;
use ProductTrap\Spider;
use ProductTrap\ColesAustralia\ColesAustralia;
use ProductTrap\Enums\Currency;

function getMockColes($app, string $response): void
{
    Spider::fake([
        '*' => $response,
    ]);
}

it('can add the Coles driver to ProductTrap', function () {
    /** @var ProductTrap $client */
    $client = $this->app->make(Factory::class);

    $client->extend('coles_other', fn () => new ColesAustralia(
        cache: $this->app->make('cache.store'),
    ));

    expect($client)->driver(ColesAustralia::IDENTIFIER)->toBeInstanceOf(ColesAustralia::class)
        ->and($client)->driver('coles_other')->toBeInstanceOf(ColesAustralia::class);
});

it('can call the ProductTrap facade', function () {
    expect(FacadesProductTrap::driver(ColesAustralia::IDENTIFIER)->getName())->toBe('Coles Australia');
});

it('can retrieve the Coles driver from ProductTrap', function () {
    expect($this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER))->toBeInstanceOf(ColesAustralia::class);
});

it('can call `find` on the Coles driver and handle failed connection', function () {
    getMockColes($this->app, '');

    $this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('abc123');
})->throws(ApiConnectionFailedException::class, 'The connection to https://shop.coles.com.au/a/national/product/abc123 has failed for the Coles Australia driver');

it('can call `find` on the Coles driver and handle a successful response', function () {
    $html = file_get_contents(__DIR__.'/../fixtures/successful_response.html');
    getMockColes($this->app, $html);

    $data = $this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('john-west-tempters-tuna-in-olive-oil');
    unset($data->raw);

    expect($this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('john-west-tempters-tuna-in-olive-oil'))
        ->toBeInstanceOf(Product::class)
        ->identifier->toBe('john-west-tempters-tuna-in-olive-oil')
        ->status->toEqual(Status::Available)
        ->name->toBe('Tempters Tuna in Olive Oil')
        ->description->toBe('Succulent chunk style tuna in an olive oil blend.')
        ->ingredients->toBe('Purse seine caught skipjack *tuna* (Katsuwonus pelamis) (65%), water, olive oil (10%), sunflower oil, salt. *Contains fish.*')
        ->price->amount->toBe(1.35)
        ->price->currency->toBe(Currency::AUD)
        ->unitAmount->unit->value->toBe('g')
        ->unitAmount->amount->toBe(95.0)
        ->unitPrice->unitAmount->unit->value->toBe('kg')
        ->unitPrice->unitAmount->amount->toBe(1.0)
        ->unitPrice->price->amount->toBe(14.21)
        ->brand->name->toBe('John West')
        ->images->toBe([
            'https://shop.coles.com.au/wcsstore/Coles-CAS/images/5/5/5/5558736.jpg',
            'https://shop.coles.com.au/wcsstore/Coles-CAS/images/5/5/5/5558736_B.jpg',
        ]);
});
