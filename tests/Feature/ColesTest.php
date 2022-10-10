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
    expect(FacadesProductTrap::driver(ColesAustralia::IDENTIFIER)->getName())->toBe(ColesAustralia::IDENTIFIER);
});

it('can retrieve the Coles driver from ProductTrap', function () {
    expect($this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER))->toBeInstanceOf(ColesAustralia::class);
});

it('can call `find` on the Coles driver and handle failed connection', function () {
    getMockColes($this->app, '');

    $this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('7XX1000');
})->throws(ApiConnectionFailedException::class, 'The connection to https://coles.com.au/shop/productdetails/7XX1000 has failed for the Coles driver');

it('can call `find` on the Coles driver and handle a successful response', function () {
    $html = file_get_contents(__DIR__.'/../fixtures/successful_response.html');
    getMockColes($this->app, $html);

    $data = $this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('257360');
    unset($data->raw);

    expect($this->app->make(Factory::class)->driver(ColesAustralia::IDENTIFIER)->find('257360'))
        ->toBeInstanceOf(Product::class)
        ->identifier->toBe('257360')
        ->status->toEqual(Status::Available)
        ->name->toBe('John West Tuna Olive Oil Blend 95G')
        ->description->toBe('Succulent chunk style tuna in an olive oil blend.')
        ->ingredients->toBe('Purse seine caught skipjack *tuna* (Katsuwonus pelamis) (65%), water, olive oil (10%), sunflower oil, salt. Contains fish.')
        ->price->amount->toBe(2.7)
        ->unitAmount->unit->value->toBe('g')
        ->unitAmount->amount->toBe(95.0)
        ->unitPrice->unitAmount->unit->value->toBe('kg')
        ->unitPrice->unitAmount->amount->toBe(1.0)
        ->unitPrice->price->amount->toBe(28.42)
        ->brand->name->toBe('John West')
        ->images->toBe([
            'https://cdn0.coles.media/content/wowproductimages/large/257360.jpg',
            'https://cdn0.coles.media/content/wowproductimages/large/257360_1.jpg',
            'https://cdn0.coles.media/content/wowproductimages/large/257360_2.jpg',
            'https://cdn0.coles.media/content/wowproductimages/large/257360_5.jpg',
            'https://cdn0.coles.media/content/wowproductimages/large/257360_6.jpg',
        ]);
});