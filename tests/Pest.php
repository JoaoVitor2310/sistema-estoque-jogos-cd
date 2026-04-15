<?php

/*
|--------------------------------------------------------------------------
| Pest Configuration
|--------------------------------------------------------------------------
|
| Feature tests use Laravel's TestCase + RefreshDatabase by default.
| Unit tests use Laravel's TestCase (app container available, no DB reset).
|
| Pure domain tests that need zero framework dependencies should extend
| PHPUnit\Framework\TestCase directly inside the test file.
|
*/

uses(
    Tests\TestCase::class,
    Illuminate\Foundation\Testing\RefreshDatabase::class,
)->in('Feature');

uses(
    Tests\TestCase::class,
)->in('Unit');
