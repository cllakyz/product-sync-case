<?php

namespace App\Enums;

enum ProviderType: string
{
    case DummyJson = 'dummyjson';
    case FakeStore = 'fakestore';

    /**
     * Human-readable label for dashboards and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::DummyJson => 'DummyJSON',
            self::FakeStore => 'FakeStore',
        };
    }
}
