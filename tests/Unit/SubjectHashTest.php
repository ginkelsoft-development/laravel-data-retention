<?php

declare(strict_types=1);

use Ginkelsoft\DataRetention\Support\SubjectHash;

it('produces a 64-character hex SHA-256 digest', function (): void {
    $hash = SubjectHash::compute('01HXYZ', 'test-secret');

    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});

it('is deterministic for the same subject and secret', function (): void {
    $a = SubjectHash::compute('user@example.com', 'secret');
    $b = SubjectHash::compute('user@example.com', 'secret');

    expect($a)->toBe($b);
});

it('differs for different subjects with the same secret', function (): void {
    $a = SubjectHash::compute('user-1', 'secret');
    $b = SubjectHash::compute('user-2', 'secret');

    expect($a)->not->toBe($b);
});

it('differs for the same subject with different secrets', function (): void {
    $a = SubjectHash::compute('user-1', 'secret-a');
    $b = SubjectHash::compute('user-1', 'secret-b');

    expect($a)->not->toBe($b);
});

it('cannot be reversed by accidentally matching the subject id', function (): void {
    $subject = 'user-1';
    $hash = SubjectHash::compute($subject, 'secret');

    expect($hash)->not->toContain($subject);
});
