<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Confirmed = 'confirmed';
    case Preparing = 'preparing';
    case ReadyForPickup = 'ready_for_pickup'; // for delivery orders: ready for dispatch
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Reserved, self::Failed],
            self::Reserved => [self::Confirmed, self::Cancelled, self::Expired],
            self::Confirmed => [self::Preparing, self::Cancelled, self::Expired],
            self::Preparing => [self::ReadyForPickup, self::Cancelled],
            self::ReadyForPickup => [self::Fulfilled, self::Cancelled, self::Expired],
            self::Fulfilled, self::Cancelled, self::Expired, self::Failed => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Stock is held (reserved) while the order is in one of these states. */
    public function holdsStock(): bool
    {
        return in_array($this, [self::Reserved, self::Confirmed, self::Preparing, self::ReadyForPickup], true);
    }

    /** Customers may only cancel before the store starts preparing. */
    public function customerCancellable(): bool
    {
        return in_array($this, [self::Reserved, self::Confirmed], true);
    }
}
