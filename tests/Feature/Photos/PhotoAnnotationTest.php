<?php

use App\Models\PhotoAnnotation;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Services\AnnotationService;

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

    it('stores author type', function () {
        $annotation = PhotoAnnotation::factory()->create(['author_type' => 'crew']);

        expect($annotation->author_type)->toBe('crew');
    });

    it('defaults author type to internal', function () {
        $annotation = PhotoAnnotation::factory()->create();

        expect($annotation->author_type)->toBe('internal');
    });

    it('returns author type label', function () {
        $internal = PhotoAnnotation::factory()->create(['author_type' => 'internal']);
        $crew = PhotoAnnotation::factory()->create(['author_type' => 'crew']);
        $customer = PhotoAnnotation::factory()->create(['author_type' => 'customer']);

        expect($internal->author_type_label)->toBe('Team')
            ->and($crew->author_type_label)->toBe('Crew')
            ->and($customer->author_type_label)->toBe('Customer');
    });

    it('returns author color', function () {
        $internal = PhotoAnnotation::factory()->create(['author_type' => 'internal']);
        $crew = PhotoAnnotation::factory()->create(['author_type' => 'crew']);
        $customer = PhotoAnnotation::factory()->create(['author_type' => 'customer']);

        expect($internal->author_color)->toBe('#3b82f6')
            ->and($crew->author_color)->toBe('#f59e0b')
            ->and($customer->author_color)->toBe('#10b981');
    });

    it('resolves author type from user', function () {
        $internal = User::factory()->internal()->create();
        $crew = User::factory()->crew()->create();
        $customer = User::factory()->customer()->create();

        expect(PhotoAnnotation::resolveAuthorType($internal))->toBe('internal')
            ->and(PhotoAnnotation::resolveAuthorType($crew))->toBe('crew')
            ->and(PhotoAnnotation::resolveAuthorType($customer))->toBe('customer');
    });

    it('scopes by author type', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        expect(PhotoAnnotation::forAuthorType('internal')->count())->toBe(1)
            ->and(PhotoAnnotation::forAuthorType('crew')->count())->toBe(1)
            ->and(PhotoAnnotation::forAuthorType('customer')->count())->toBe(0);
    });

    it('enforces unique constraint per photo and author type', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
            'author_type' => 'internal',
        ]);

        expect(fn () => PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
            'author_type' => 'internal',
        ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    });

    it('allows different author types on the same photo', function () {
        $photo = PropertyPhoto::factory()->create();

        $internal = PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
            'author_type' => 'internal',
        ]);
        $crew = PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
            'author_type' => 'crew',
        ]);
        $customer = PhotoAnnotation::factory()->create([
            'property_photo_id' => $photo->id,
            'author_type' => 'customer',
        ]);

        expect($photo->fresh()->annotations)->toHaveCount(3)
            ->and($internal->id)->not->toBe($crew->id)
            ->and($crew->id)->not->toBe($customer->id);
    });
});

describe('PropertyPhoto multi-annotation support', function () {
    it('has annotations relationship (multiple)', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        expect($photo->fresh()->annotations)->toHaveCount(2);
    });

    it('returns annotation for specific type', function () {
        $photo = PropertyPhoto::factory()->create();
        $internal = PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        $photo = $photo->fresh()->load('annotations');
        $found = $photo->annotationForType('internal');

        expect($found)->not->toBeNull()
            ->and($found->id)->toBe($internal->id)
            ->and($photo->annotationForType('customer'))->toBeNull();
    });

    it('returns annotation types array', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        $photo = $photo->fresh()->load('annotations');

        expect($photo->annotation_types)->toBeArray()
            ->toHaveCount(2)
            ->toContain('internal')
            ->toContain('crew');
    });

    it('returns has_annotations attribute', function () {
        $photo = PropertyPhoto::factory()->create();

        expect($photo->fresh()->load('annotations')->has_annotations)->toBeFalse();

        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id]);

        expect($photo->fresh()->load('annotations')->has_annotations)->toBeTrue();
    });

    it('keeps backwards-compatible annotation() HasOne', function () {
        $photo = PropertyPhoto::factory()->create();
        $annotation = PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id]);

        expect($photo->fresh()->annotation)->toBeInstanceOf(PhotoAnnotation::class)
            ->and($photo->fresh()->annotation->id)->toBe($annotation->id);
    });
});

describe('AnnotationService multi-user', function () {
    it('saves annotation for correct author type', function () {
        $photo = PropertyPhoto::factory()->create();
        $user = User::factory()->internal()->create();

        $service = app(AnnotationService::class);
        $annotation = $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $user);

        expect($annotation->author_type)->toBe('internal')
            ->and($annotation->created_by)->toBe($user->id);
    });

    it('updates existing annotation for same author type', function () {
        $photo = PropertyPhoto::factory()->create();
        $user = User::factory()->internal()->create();

        $service = app(AnnotationService::class);
        $first = $service->save($photo, ['objects' => [['type' => 'path']], 'width' => 100, 'height' => 100], $user);
        $second = $service->save($photo, ['objects' => [['type' => 'rect']], 'width' => 100, 'height' => 100], $user);

        expect($first->id)->toBe($second->id)
            ->and($second->fresh()->canvas_json['objects'][0]['type'])->toBe('rect')
            ->and(PhotoAnnotation::where('property_photo_id', $photo->id)->count())->toBe(1);
    });

    it('creates separate annotations for different user types', function () {
        $photo = PropertyPhoto::factory()->create();
        $internal = User::factory()->internal()->create();
        $crew = User::factory()->crew()->create();

        $service = app(AnnotationService::class);
        $ann1 = $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $internal);
        $ann2 = $service->save($photo, ['objects' => [], 'width' => 100, 'height' => 100], $crew);

        expect($ann1->id)->not->toBe($ann2->id)
            ->and($ann1->author_type)->toBe('internal')
            ->and($ann2->author_type)->toBe('crew')
            ->and(PhotoAnnotation::where('property_photo_id', $photo->id)->count())->toBe(2);
    });

    it('clears only specific author type annotation', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        $service = app(AnnotationService::class);
        $service->clearAnnotation($photo, 'internal');

        expect(PhotoAnnotation::where('property_photo_id', $photo->id)->count())->toBe(1)
            ->and(PhotoAnnotation::where('property_photo_id', $photo->id)->first()->author_type)->toBe('crew');
    });

    it('clears all annotations when no author type specified', function () {
        $photo = PropertyPhoto::factory()->create();
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'internal']);
        PhotoAnnotation::factory()->create(['property_photo_id' => $photo->id, 'author_type' => 'crew']);

        $service = app(AnnotationService::class);
        $service->clearAnnotation($photo);

        expect(PhotoAnnotation::where('property_photo_id', $photo->id)->count())->toBe(0);
    });
});
