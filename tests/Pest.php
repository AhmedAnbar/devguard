<?php

declare(strict_types=1);

uses()->in('Unit', 'Feature');

function fixturePath(string $name): string
{
    return realpath(__DIR__ . '/Fixtures/' . $name) ?: __DIR__ . '/Fixtures/' . $name;
}
