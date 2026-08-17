<?php

use Splicewire\Beam\Threads\Data\Reference;
use Splicewire\Beam\Threads\Data\Segment;
use Splicewire\Beam\Threads\Enums\SegmentKind;

/**
 * The message content model is a flat `Segment[]` union with a `kind` discriminator and per-kind fields
 * left null. That flatness is what lets migrate-on-read reshape payloads uniformly, and it is also what
 * makes the split between the POSITIONAL marker (a `reference` Segment) and the OUT-OF-BAND record (a
 * {@see Reference}) easy to accidentally re-merge — re-merging them is exactly the "floating tagalong"
 * double-model ADR-0174 was written to undo.
 *
 * Concretely: the marker carries `ref_id` + `label` and NO body; the record carries `id` + `type` + the
 * freeform `data` bag. If a future edit gave the marker a `uri`, or the record a position, the two
 * halves would start disagreeing about which one a renderer should read — and nothing would throw.
 *
 * The round-trip assertions matter for a separate reason: {@see \Splicewire\Beam\Threads\Turns\AssembledTurn}
 * rebuilds every segment from the raw persisted payload via `Segment::from($raw)`, so the serialized
 * key names ARE the storage contract. Renaming `ref_id` would silently null out every citation marker
 * in every stored message.
 */
it('builds a text segment carrying only prose', function () {
    $segment = Segment::text('Hello there');

    expect($segment->kind)->toBe(SegmentKind::Text);
    expect($segment->body)->toBe('Hello there');
    expect($segment->ref_id)->toBeNull();
    expect($segment->label)->toBeNull();
});

it('builds a reference segment as a pure positional marker with no body', function () {
    $segment = Segment::reference('ref-1', '[1]');

    expect($segment->kind)->toBe(SegmentKind::Reference);
    expect($segment->ref_id)->toBe('ref-1');
    expect($segment->label)->toBe('[1]');
    expect($segment->body)->toBeNull();
});

it('allows an unlabelled marker', function () {
    expect(Segment::reference('ref-1')->label)->toBeNull();
});

it('serializes the kind to its stable string token, not the enum object', function () {
    // The payload is JSON in a column; an enum leaking through would store as an object/array and fail
    // to rehydrate.
    expect(Segment::text('hi')->toArray()['kind'])->toBe('text');
    expect(Segment::reference('r')->toArray()['kind'])->toBe('reference');
});

it('round-trips a segment through the raw payload shape the turn assembler reads', function () {
    foreach ([Segment::text('prose'), Segment::reference('ref-9', '[9]')] as $original) {
        $rehydrated = Segment::from($original->toArray());

        expect($rehydrated->kind)->toBe($original->kind);
        expect($rehydrated->body)->toBe($original->body);
        expect($rehydrated->ref_id)->toBe($original->ref_id);
        expect($rehydrated->label)->toBe($original->label);
    }
});

it('builds a citation record as the out-of-band half a marker points at', function () {
    $reference = Reference::citation('ref-1', 'Doe 2020', 'https://example.test/doc');

    expect($reference->id)->toBe('ref-1');
    expect($reference->type)->toBe('citation');
    expect($reference->label)->toBe('Doe 2020');
    expect($reference->uri)->toBe('https://example.test/doc');
    expect($reference->data)->toBe([]);
});

it('keeps the freeform data bag open so a new reference kind needs no schema surgery', function () {
    // The `data` bag IS the extension injection point (media/embedding projections, future kinds). A
    // typed narrowing here would close the seam the ADR opened.
    $reference = new Reference(id: 'm-1', type: 'media', data: ['mime' => 'image/png', 'w' => 64]);

    $rehydrated = Reference::from($reference->toArray());

    expect($rehydrated->type)->toBe('media');
    expect($rehydrated->data)->toBe(['mime' => 'image/png', 'w' => 64]);
});

it('links a marker to its record by id, which is the only edge between the two halves', function () {
    $record = Reference::citation('ref-42', 'Source');
    $marker = Segment::reference($record->id, '[1]');

    expect($marker->ref_id)->toBe($record->id);

    // And the marker holds nothing the record holds — no uri, no type. The moment it did, a renderer
    // would have two disagreeing sources of truth for one citation.
    expect(get_object_vars($marker))->toHaveKeys(['kind', 'body', 'ref_id', 'label']);
    expect(get_object_vars($marker))->not->toHaveKey('uri');
});

it('admits only the neutral-base kinds — the AI segment kinds stay out of the substrate', function () {
    // `tool_call`/`tool_result` are participant-contributed schema extensions (TH-08); their appearance
    // in this enum would mean the AI vocabulary had been demoted into the AI-free package.
    expect(array_map(fn (SegmentKind $k) => $k->value, SegmentKind::cases()))->toBe(['text', 'reference']);
});
