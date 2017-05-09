<?php
/**
 * Copyright (c) 2017, Nosto Solutions Ltd
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Nosto Solutions Ltd <contact@nosto.com>
 * @copyright 2017 Nosto Solutions Ltd
 * @license http://opensource.org/licenses/BSD-3-Clause BSD 3-Clause
 *
 */

namespace Nosto\Tagging\Model\Product\Sku;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute as ConfigurableAttribute;
use Magento\Framework\Event\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Nosto\NostoException;
use Nosto\Tagging\Helper\Data as NostoHelperData;
use Nosto\Tagging\Helper\Price as NostoPriceHelper;
use Psr\Log\LoggerInterface;

class Builder
{
    private $nostoDataHelper;
    private $nostoPriceHelper;
    private $galleryReadHandler;
    private $eventManager;
    private $logger;

    /**
     * @param NostoHelperData $nostoHelperData
     * @param NostoPriceHelper $priceHelper
     * @param LoggerInterface $logger
     * @param ManagerInterface $eventManager
     * @param GalleryReadHandler $galleryReadHandler
     */
    public function __construct(
        NostoHelperData $nostoHelperData,
        NostoPriceHelper $priceHelper,
        LoggerInterface $logger,
        ManagerInterface $eventManager,
        GalleryReadHandler $galleryReadHandler
    ) {
        $this->nostoDataHelper = $nostoHelperData;
        $this->nostoPriceHelper = $priceHelper;
        $this->logger = $logger;
        $this->eventManager = $eventManager;
        $this->galleryReadHandler = $galleryReadHandler;
    }

    /**
     * @param Product $product
     * @param StoreInterface $store
     * @param ConfigurableAttribute[] $attributes
     * @return \Nosto\Object\Product\Sku
     */
    public function build(Product $product, StoreInterface $store, $attributes)
    {
        $nostoSku = new \Nosto\Object\Product\Sku();

        try {
            $nostoSku->setId($product->getId());
            $nostoSku->setName($product->getName());
            $nostoSku->setAvailability($product->isAvailable() ? 'InStock' : 'OutOfStock');
            $nostoSku->setImageUrl($this->buildImageUrl($product, $store));
            $nostoSku->setPrice($price = $this->nostoPriceHelper->getProductFinalPriceInclTax($product));
            $nostoSku->setListPrice($price = $this->nostoPriceHelper->getProductFinalPriceInclTax($product));

            $gtinAttribute = $this->nostoDataHelper->getGtinAttribute($store);
            if ($product->hasData($gtinAttribute)) {
                $nostoSku->setGtin($product->getData($gtinAttribute));
            }

            foreach ($attributes as $attribute) {
                try {
                    $code = $attribute->getProductAttribute()->getAttributeCode();
                    $nostoSku->addCustomAttribute($code, $product->getAttributeText($code));
                } catch (NostoException $e) {
                    $this->logger->error($e->__toString());
                }
            }

        } catch (NostoException $e) {
            $this->logger->error($e->__toString());
        }

        $this->eventManager->dispatch('nosto_sku_load_after', ['sku' => $nostoSku]);

        return $nostoSku;
    }

    /**
     * @param Product $product
     * @param StoreInterface $store
     * @return string|null
     */
    public function buildImageUrl(Product $product, StoreInterface $store)
    {
        $primary = $this->nostoDataHelper->getProductImageVersion($store);
        $secondary = 'image'; // The "base" image.
        $media = $product->getMediaAttributeValues();
        $image = (isset($media[$primary])
            ? $media[$primary]
            : (isset($media[$secondary]) ? $media[$secondary] : null)
        );

        if (empty($image)) {
            return null;
        }

        return $product->getMediaConfig()->getMediaUrl($image);
    }
}