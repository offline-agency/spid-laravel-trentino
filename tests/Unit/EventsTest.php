<?php

declare(strict_types=1);

use Illuminate\Queue\SerializesModels;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedIn;
use OfflineAgency\SpidLaravelTrentino\Events\SpidTrentinoLoggedOut;
use OfflineAgency\SpidLaravelTrentino\SpidTrentinoUser;

it('carries the SPID user without model serialization', function (string $event) {
    $user = SpidTrentinoUser::fromArray(['sub' => 'subject-123']);
    $instance = new $event($user);

    expect($instance->user)->toBe($user)
        ->and($instance->getUser())->toBe($user)
        ->and(class_uses($instance))->not->toContain(SerializesModels::class)
        ->and(unserialize(serialize($instance))->getUser()->getSub())->toBe('subject-123');
})->with([SpidTrentinoLoggedIn::class, SpidTrentinoLoggedOut::class]);
