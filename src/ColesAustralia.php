<?php

declare(strict_types=1);

namespace ProductTrap\ColesAustralia;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ProductTrap\Contracts\Driver;
use ProductTrap\DTOs\Brand;
use ProductTrap\DTOs\Price;
use ProductTrap\DTOs\Product;
use ProductTrap\DTOs\Results;
use ProductTrap\DTOs\UnitAmount;
use ProductTrap\DTOs\UnitPrice;
use ProductTrap\Enums\Currency;
use ProductTrap\Enums\Status;
use ProductTrap\Exceptions\ProductTrapDriverException;
use ProductTrap\Traits\DriverCache;
use ProductTrap\Traits\DriverCrawler;

class ColesAustralia implements Driver
{
    use DriverCache;
    use DriverCrawler;

    public const IDENTIFIER = 'coles_australia';

    public const BASE_URI = 'https://shop.coles.com.au';

    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    public function getName(): string
    {
        return 'Coles Australia';
    }

    private function getTextBetween(string $startNeedle, string $endNeedle, string $html): string
    {
        $start = strpos($html, $startNeedle) + strlen($startNeedle);
        $end = strpos($html, $endNeedle, $start);
        $length = $end - $start;
        $text = substr($html, $start, $length);

        return trim($text);
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ProductTrapDriverException
     */
    public function find(string $identifier, array $parameters = []): Product
    {
        $html = $this->remember($identifier, now()->addDay(), fn () => $this->scrape($this->url($identifier)));
        $crawler = $this->crawl($html);

        $json = $this->getTextBetween('<script type="application/ld+json">', '</script>', $html);
        $json = json_decode($json, true);
        if (isset($json['@type']) && (is_string($json['@type'])) && strtolower($json['@type']) === 'product') {
            $jsonld = $json;
        }
        /** @var array|null $jsonld */

        // Title
        $title = $jsonld['name'] ?? Str::of(
            $crawler->filter('title')->first()->html()
        )->trim()->before(' | ')->toString();

        // Description
        $description = $jsonld['description'] ?? Str::of(
            $crawler->filter('.long-desc-container')->first()->text()
        )->trim()->toString();

        // SKU
        try {
            $sku = $jsonld['sku'] ?? null;

            if ($sku === null) {
                $sku = $crawler->filter('[id^="PDP_proc_"]')->first()->attr('id');
                $sku = Str::of($sku)->after('PDP_proc_')->trim()->toString();
            }
        } catch (\Exception $e) {
            $sku = null;
        }

        // Gtin
        $gtin = $jsonld['gtin13'] ?? null;

        // Brand
        $brand = isset($jsonld['brand']) ? new Brand(
            name: $brandName = $jsonld['brand']['name'] ?? $jsonld['brand'],
            identifier: $brandName,
        ) : null;

        // Currency
        $currency = Currency::tryFrom($jsonld['offers']['priceCurrency'] ?? null) ?? Currency::AUD;

        // Price
        $price = null;
        try {
            $price = $jsonld['offers']['price'] ?? Str::of(
                $crawler->filter('.ar-product-price .price.price--large')->first()->text()
            )->replace(['$', ',', ' '], '')->toFloat();

            $saved = $crawler->filter('.product-save-value')->count() ? Str::of(
                $crawler->filter('.product-save-value')->first()->text()
            )->replace(['$', ',', ' '], '')->toFloat() : null;

            $wasPrice = $price + ($saved ?? 0);
        } catch (\Exception $e) {
        }
        $price = ($price !== null)
            ? new Price(
                amount: $price,
                wasAmount: $wasPrice ?? null,
                currency: $currency,
            )
            : null;

        // Images
        $images = [];
        $crawler->filter('.product-thumb-image-container img')->each(function ($node) use (&$images) {
            $images[] = static::BASE_URI . str_replace('-th.', '.', $node->attr('src'));
        });
        $images = array_values(array_unique($images));

        // Status
        $status = null;
        if (isset($jsonld['offers']['availability'])) {
            $availableMap = [
                'BackOrder' => Status::Unavailable,
                'Discontinued' => Status::Cancelled,
                'InStock' => Status::Available,
                'InStoreOnly' => Status::Available,
                'LimitedAvailability' => Status::Available,
                'OnlineOnly' => Status::Available,
                'OutOfStock' => Status::Unavailable,
                'PreOrder' => Status::Unavailable,
                'PreSale' => Status::Available,
                'SoldOut' => Status::Unavailable,
            ];

            /** @var string $schemaCode */
            $schemaCode = str_replace('http://schemma.org/', '', $jsonld['offers']['availability']);
            $status = $availableMap[$schemaCode] ?? null;
        }
        $status ??= ($crawler->filter('.ar-add-to-cart .hide a.cartControls-addButton')->count() === 0) ? Status::Available : Status::Unavailable;

        $additional = Collection::make($jsonld['additionalProperty'] ?? [])->map(function (array $data) {
            return [
                'name' => Str::snake($data['name']),
                'value' => $data['value'],
            ];
        })->pluck('value', 'name')->toArray();

        // Ingredients
        $ingredients = isset($additional['ingredients']) ? strip_tags($additional['ingredients']) : null;
        if (empty($ingredients)) {
            $ingredients = $crawler->filter('.long-desc-container')->count()
                ? Str::of($crawler->filter('.long-desc-container')->first()->text())->trim()->toString()
                : null;
        }

        // Unit Amount (e.g. 85g or 1kg)
        $unitAmount = UnitAmount::parse($additional['size'] ?? $title);

        // Unit Price (e.g. $2 per kg)
        $unitPrice = $additional['unit_price'] ?? null;
        // $unitPrice = $crawler->filter('.shelfProductTile-cupPrice')->count()
        //     ? Str::of(
        //         $crawler->filter('.shelfProductTile-cupPrice')->first()->text()
        //     )->trim()->toString()
        //     : null;
        $unitPrice = UnitPrice::determine(
            price: $price,
            unitAmount: $unitAmount,
            unitPrice: $unitPrice,
            currency: $currency,
        );

        // URL
        $url = 'https://shop.coles.com.au/a/national/product/' . $identifier;

        $product = new Product([
            'identifier' => $identifier,
            'sku' => $identifier,
            'name' => $title,
            'description' => $description,
            'url' => $url,
            'price' => $price,
            'status' => $status,
            'brand' => $brand,
            'gtin' => $gtin,
            'unitAmount' => $unitAmount,
            'unitPrice' => $unitPrice,
            'ingredients' => $ingredients,
            'images' => $images,
            'raw' => [
                'html' => $html,
            ],
        ]);


        return $product;
    }

    public function url(string $identifier): string
    {
        return self::BASE_URI . '/a/national/product/' . $identifier;
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ProductTrapDriverException
     */
    public function search(string $keywords, array $parameters = []): Results
    {
        return new Results();
    }
}
