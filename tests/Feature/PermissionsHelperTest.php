<?php

use Infocyph\Pathwise\Utils\PermissionsHelper;

beforeEach(function () {
    $this->tempFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('test_file_', true) . '.txt';
    file_put_contents($this->tempFilePath, 'Test content');
});

afterEach(function () {
    if (file_exists($this->tempFilePath)) {
        unlink($this->tempFilePath);
    }
});

// Test getPermissions
test('it retrieves file permissions', function () {
    $permissions = PermissionsHelper::getPermissions($this->tempFilePath);
    expect($permissions)->toBeString();
})->skip(PHP_OS_FAMILY === 'Windows');

// Test setPermissions
test('it sets file permissions', function () {
    PermissionsHelper::setPermissions($this->tempFilePath, 0644);
    expect(PermissionsHelper::getPermissions($this->tempFilePath))->toBe('0644');
})->skip(PHP_OS_FAMILY === 'Windows');

// Test canRead
test('it checks if file is readable', function () {
    expect(PermissionsHelper::canRead($this->tempFilePath))->toBeTrue();
});

// Test canWrite
test('it checks if file is writable', function () {
    expect(PermissionsHelper::canWrite($this->tempFilePath))->toBeTrue();
});

// Test canExecute
test('it checks if file is executable', function () {
    expect(PermissionsHelper::canExecute($this->tempFilePath))->toBeFalse();
});

// Test getOwnership (POSIX only)
test('it retrieves file ownership', function () {
    $ownership = PermissionsHelper::getOwnership($this->tempFilePath);
    expect($ownership)->toHaveKeys(['owner', 'group']);
})->skip(!function_exists('posix_getpwuid'));

// Test setOwnership (POSIX only)
test('it sets file ownership', function () {
    $originalOwner = posix_getpwuid(fileowner($this->tempFilePath))['name'] ?? null;
    PermissionsHelper::setOwnership($this->tempFilePath, $originalOwner);
    $ownership = PermissionsHelper::getOwnership($this->tempFilePath);
    expect($ownership['owner'])->toBe($originalOwner);
})->skip(!function_exists('posix_getpwuid'));

// Test isOwnedByCurrentUser (POSIX only)
test('it checks if file is owned by current user', function () {
    expect(PermissionsHelper::isOwnedByCurrentUser($this->tempFilePath))->toBeTrue();
})->skip(!function_exists('posix_geteuid'));

// Test getHumanReadablePermissions
test('it retrieves human-readable file permissions', function () {
    $permissions = PermissionsHelper::getHumanReadablePermissions($this->tempFilePath);
    expect($permissions)->toBeString()->toMatch('/^[r-][w-][x-]{9}$/');
})->skip(PHP_OS_FAMILY === 'Windows');

// Test resetPermissions
test('it resets file permissions', function () {
    PermissionsHelper::setPermissions($this->tempFilePath, 0700);
    PermissionsHelper::resetPermissions($this->tempFilePath);
    $defaultPermissions = is_dir($this->tempFilePath) ? '0755' : '0644';
    expect(PermissionsHelper::getPermissions($this->tempFilePath))->toBe($defaultPermissions);
})->skip(PHP_OS_FAMILY === 'Windows');

// Test formatPermissions
test('it formats permissions as human-readable string', function () {
    $permissions = PermissionsHelper::formatPermissions(0755);
    expect($permissions)->toBe('rwxr-xr-x');
});
