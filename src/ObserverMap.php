<?php

namespace Just\Warehouse;

trait ObserverMap
{
    /**
     * All of the Warehouse model / oberserver mappings.
     *
     * @var array
     */
    protected $observers = [
        Models\Inventory::class => Observers\InventoryObserver::class,
    ];
}
