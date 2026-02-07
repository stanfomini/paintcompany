<?php

use App\Models\PhotoAnnotation;
use App\Models\PropertyPhoto;
use App\Models\User;

describe('PhotoAnnotation model', function () {
    it('belongs to a property photo', function () {
        $photo = PropertyPhoto::factory()->create();
        $annotation = PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
        ]);

        expect($annotation->photo)->toBeInstanceOf(PropertyPhoto::class)
            ->and($annotation->photo->id)->toBe($photo->id);
    });

    it('casts canvas_json as array', function () {
        $canvasData = [
            'objects' => [
                ['type' => 'rect', 'x' => 10, 'y' => 20],
                ['type' => 'circle', 'x' => 50, 'y' => 60],
            ],
            'version' => '1.0',
        ];

        $annotation = PhotoAnnotation::factory()->create([
            'canvas_json' => $canvasData,
        ]);

        $fresh = $annotation->fresh();

        expect($fresh->canvas_json)->toBeArray()
            ->and($fresh->canvas_json['objects'])->toHaveCount(2)
            ->and($fresh->canvas_json['version'])->toBe('1.0');
    });

    it('belongs to a creator', function () {
        $user = User::factory()->internal()->create();
        $annotation = PhotoAnnotation::factory()->create([
            'created_by' => $user->id,
        ]);

        expect($annotation->createdBy)->toBeInstanceOf(User::class)
            ->and($annotation->createdBy->id)->toBe($user->id);
    });

    it('stores canvas version', function () {
        $annotation = PhotoAnnotation::factory()->create([
            'canvas_version' => '2.5',
        ]);

        expect($annotation->canvas_version)->toBe('2.5');
    });

    it('stores rendered path', function () {
        $annotation = PhotoAnnotation::factory()->create([
            'rendered_path' => 'annotations/rendered/abc123.png',
        ]);

        expect($annotation->rendered_path)->toBe('annotations/rendered/abc123.png');
    });

    it('defaults rendered path to null', function () {
        $annotation = PhotoAnnotation::factory()->create();

        expect($annotation->rendered_path)->toBeNull();
    });
});
