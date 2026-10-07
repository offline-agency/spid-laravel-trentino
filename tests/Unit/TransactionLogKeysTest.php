<?php

declare(strict_types=1);

use OfflineAgency\SpidLaravelTrentino\Support\TransactionLogKeys;

mutates(TransactionLogKeys::class);

it('resolves the app key id to APP_KEY exactly as 2.x signed rows', function () {
    expect(TransactionLogKeys::secrets('app'))->toBe([config('app.key')])
        ->and(TransactionLogKeys::secrets(null))->toBe([config('app.key')])
        ->and(TransactionLogKeys::currentId())->toBe('app')
        ->and(TransactionLogKeys::current())->toBe(['app', config('app.key')]);
});

it('parses id:secret pairs from the environment string, decoding base64 secrets', function () {
    config()->set('spid-laravel-trentino.transaction_log.keys', '2026a:plain-secret, 2026b:base64:'.base64_encode('binary-secret'));
    config()->set('spid-laravel-trentino.transaction_log.current_key', '2026b');

    expect(TransactionLogKeys::secrets('2026a'))->toBe(['plain-secret'])
        ->and(TransactionLogKeys::secrets('2026b'))->toBe(['binary-secret'])
        ->and(TransactionLogKeys::current())->toBe(['2026b', 'binary-secret']);
});

it('accepts an array of keys set in the configuration file', function () {
    config()->set('spid-laravel-trentino.transaction_log.keys', ['k1' => 'secret-1']);
    config()->set('spid-laravel-trentino.transaction_log.current_key', 'k1');

    expect(TransactionLogKeys::current())->toBe(['k1', 'secret-1']);
});

it('ignores malformed pairs and never lets a configured key replace app', function () {
    config()->set('spid-laravel-trentino.transaction_log.keys', 'no-separator,:missing-id,bad id:x,empty:,app:override,ok:fine,b64:base64:%%%');

    expect(TransactionLogKeys::secrets('ok'))->toBe(['fine'])
        ->and(TransactionLogKeys::secrets('empty'))->toBe([])
        ->and(TransactionLogKeys::secrets('bad id'))->toBe([])
        ->and(TransactionLogKeys::secrets('b64'))->toBe([])
        ->and(TransactionLogKeys::secrets('app'))->toBe([config('app.key')])
        ->and(TransactionLogKeys::secrets('unknown'))->toBe([]);
});

it('rejects a current key without a secret', function (mixed $currentKey) {
    config()->set('spid-laravel-trentino.transaction_log.current_key', $currentKey);

    expect(fn () => TransactionLogKeys::current())
        ->toThrow(LogicException::class, 'The transaction log key [missing] is not configured');
})->with([['missing']]);

it('falls back to the app key when current_key is empty', function (mixed $currentKey) {
    config()->set('spid-laravel-trentino.transaction_log.current_key', $currentKey);

    expect(TransactionLogKeys::currentId())->toBe('app');
})->with(['null' => null, 'empty string' => '']);

it('has no app secret when APP_KEY is missing', function () {
    config()->set('app.key', null);

    expect(TransactionLogKeys::secrets('app'))->toBe([]);
});
