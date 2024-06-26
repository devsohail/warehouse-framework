<?php

namespace Just\Warehouse\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Just\Warehouse\Jobs\TransitionOrderStatus;
use Just\Warehouse\Models\States\Order\Backorder;
use Just\Warehouse\Models\States\Order\Created;
use Just\Warehouse\Models\States\Order\Fulfilled;
use Just\Warehouse\Models\States\Order\Hold;
use Just\Warehouse\Models\States\Order\Open;
use Just\Warehouse\Models\States\Order\OrderState;
use Just\Warehouse\Models\Transitions\Order\OpenToFulfilled;
use Devsohail\EloquentExpirable\Expirable;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Spatie\ModelStates\HasStates;

/**
 * @property int $id
 * @property string $order_number
 * @property array $meta
 * @property \Just\Warehouse\Models\States\Order\OrderState $status
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $deleted_at
 * @property \Illuminate\Support\Carbon $fulfilled_at
 * @property \Illuminate\Database\Eloquent\Collection $lines
 */
class Order extends AbstractModel
{
    use Expirable,
        HasStates,
        SoftDeletes;

    protected $casts = [
        'meta' => 'array',
    ];

    protected $dates = [
        'fulfilled_at',
    ];

    protected function registerStates(): void
    {
        $this->addState('status', OrderState::class)
            ->default(Created::class)
            ->allowTransition([Created::class, Backorder::class], Open::class)
            ->allowTransition([Created::class, Open::class], Backorder::class)
            ->allowTransition(Open::class, Fulfilled::class, OpenToFulfilled::class)
            ->allowTransition([Created::class, Open::class, Backorder::class], Hold::class)
            ->allowTransition(Hold::class, Open::class)
            ->allowTransition(Hold::class, Backorder::class);
    }

    /**
     * Set the status attribute.
     *
     * @param  string  $value
     * @return void
     *
     * @throws \Just\Warehouse\Exceptions\InvalidStatusException
     */
    public function setStatusAttribute($value)
    {
        if (! $this->exists) {
            $value = new Created($this);
        }

        $this->attributes['status'] = $value;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * Add an order line.
     *
     * @param  string  $gtin
     * @param  int  $quantity
     * @return \Just\Warehouse\Models\OrderLine|\Illuminate\Database\Eloquent\Collection
     *
     * @throws \Just\Warehouse\Exceptions\InvalidGtinException
     */
    public function addLine($gtin, $quantity = 1)
    {
        if ($quantity < 1) {
            return $this->newCollection();
        }

        $instances = $this->newCollection(array_map(function () use ($gtin) {
            return $this->lines()->create([
                'gtin' => $gtin,
            ]);
        }, range(1, $quantity)));

        return $quantity === 1 ? $instances->first() : $instances;
    }

    /**
     * Mark the order as fulfilled.
     *
     * @return void
     *
     * @throws \Spatie\ModelStates\Exceptions\TransitionNotFound
     */
    public function markAsFulfilled()
    {
        $this->transitionTo(Fulfilled::class);
    }

    public function process(): void
    {
        TransitionOrderStatus::dispatch($this);
    }

    /**
     * Put the order on hold.
     *
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @return bool
     */
    public function hold($ttl = null): bool
    {
        if ($this->lines->isEmpty()) {
            return false;
        }

        try {
            $this->setExpiresAtAttribute($ttl);
            $this->transitionTo(Hold::class);
        } catch (TransitionNotFound $e) {
            return false;
        }

        return true;
    }

    public function unhold(): bool
    {
        if (! $this->status->is(Hold::class)) {
            return false;
        }

        $this->process();

        return true;
    }

    public function hasPickList(): bool
    {
        return $this->status->is(Open::class);
    }

    public function pickList(): Collection
    {
        if (! $this->hasPickList()) {
            return collect();
        }

        return $this->lines()
            ->select('id')
            ->with([
                'inventory' => function ($query) {
                    $query->select([
                        'inventories.id',
                        'inventories.gtin',
                        'inventories.location_id',
                    ]);
                },
                'inventory.location' => function ($query) {
                    $query->select([
                        'id',
                        'name',
                    ]);
                },
            ])
            ->get()
            ->map(function ($line) {
                return $line->inventory->makeHidden([
                    'id',
                    'location_id',
                    'reservation',
                ]);
            })
            ->groupBy([
                'gtin',
                'location.id',
            ])
            ->flatten(1)
            ->map(function ($item) {
                return collect($item->first())
                    ->forget('location')
                    ->put('location', $item->first()->location->name)
                    ->put('quantity', $item->count());
            })
            ->sortBy('location')
            ->values();
    }
}
