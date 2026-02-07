<?php

use App\Models\PhotoComment;
use App\Models\PropertyPhoto;
use App\Models\User;

describe('PhotoComment model', function () {
    it('belongs to a property photo', function () {
        $photo = PropertyPhoto::factory()->create();
        $comment = PhotoComment::factory()->create([
            'property_photo_id' => $photo->id,
        ]);

        expect($comment->photo)->toBeInstanceOf(PropertyPhoto::class)
            ->and($comment->photo->id)->toBe($photo->id);
    });

    it('belongs to a user', function () {
        $user = User::factory()->internal()->create();
        $comment = PhotoComment::factory()->create([
            'user_id' => $user->id,
        ]);

        expect($comment->user)->toBeInstanceOf(User::class)
            ->and($comment->user->id)->toBe($user->id);
    });

    it('supports threaded replies', function () {
        $photo = PropertyPhoto::factory()->create();
        $parent = PhotoComment::factory()->create([
            'property_photo_id' => $photo->id,
            'body' => 'Parent comment',
        ]);

        $reply1 = PhotoComment::factory()->replyTo($parent)->create([
            'body' => 'First reply',
        ]);
        $reply2 = PhotoComment::factory()->replyTo($parent)->create([
            'body' => 'Second reply',
        ]);

        $parentFresh = $parent->fresh();

        expect($parentFresh->replies)->toHaveCount(2)
            ->and($reply1->parent->id)->toBe($parent->id)
            ->and($reply2->parent->id)->toBe($parent->id)
            ->and($reply1->property_photo_id)->toBe($photo->id)
            ->and($reply2->property_photo_id)->toBe($photo->id);
    });

    it('has null parent when it is a top-level comment', function () {
        $comment = PhotoComment::factory()->create();

        expect($comment->parent_id)->toBeNull()
            ->and($comment->parent)->toBeNull();
    });

    it('stores body text', function () {
        $comment = PhotoComment::factory()->create([
            'body' => 'This wall needs extra prep work.',
        ]);

        expect($comment->body)->toBe('This wall needs extra prep work.');
    });

    it('can be created via photo relationship', function () {
        $photo = PropertyPhoto::factory()->create();
        $user = User::factory()->internal()->create();

        $comment = $photo->comments()->create([
            'user_id' => $user->id,
            'body' => 'Looks good!',
        ]);

        expect($comment)->toBeInstanceOf(PhotoComment::class)
            ->and($comment->property_photo_id)->toBe($photo->id)
            ->and($photo->fresh()->comments)->toHaveCount(1);
    });
});
