<?php
declare(strict_types=1);

final class Clock
{
    public static ?string $hoy = null;
}

function hoy(): string
{
    return Clock::$hoy ?? date('Y-m-d');
}

function clock_set(?string $fecha): void
{
    Clock::$hoy = $fecha;
}

function dias_entre(string $desde, string $hasta): int
{
    return (int)(new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->format('%r%a');
}
