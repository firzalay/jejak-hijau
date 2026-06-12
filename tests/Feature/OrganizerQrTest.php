<?php

use App\Models\Checkpoint;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('authorization rules', function () {
    it('redirects guests to login', function () {
        $checkpoint = Checkpoint::factory()->create();

        $this->get(route('organizer.checkpoints.qr.show', $checkpoint->id))->assertRedirect(route('login'));
        $this->post(route('organizer.checkpoints.generate-qr', $checkpoint->id))->assertRedirect(route('login'));
        $this->post(route('organizer.checkpoints.regenerate-qr', $checkpoint->id))->assertRedirect(route('login'));
        $this->get(route('organizer.checkpoints.download-qr', $checkpoint->id))->assertRedirect(route('login'));
        $this->get(route('organizer.checkpoints.print-qr', $checkpoint->id))->assertRedirect(route('login'));
    });

    it('returns 403 forbidden for participants', function () {
        $user = User::factory()->create(['role' => 'participant']);
        $checkpoint = Checkpoint::factory()->create();

        $this->actingAs($user)->get(route('organizer.checkpoints.qr.show', $checkpoint->id))->assertStatus(403);
        $this->actingAs($user)->post(route('organizer.checkpoints.generate-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($user)->post(route('organizer.checkpoints.regenerate-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($user)->get(route('organizer.checkpoints.download-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($user)->get(route('organizer.checkpoints.print-qr', $checkpoint->id))->assertStatus(403);
    });

    it('prevents organizers from accessing other organizers checkpoint QRs', function () {
        $organizer1 = User::factory()->create(['role' => 'organizer']);
        $organizer2 = User::factory()->create(['role' => 'organizer']);

        $event = Event::factory()->create(['organizer_id' => $organizer1->id]);
        $checkpoint = Checkpoint::factory()->create(['event_id' => $event->id]);

        $this->actingAs($organizer2)->get(route('organizer.checkpoints.qr.show', $checkpoint->id))->assertStatus(403);
        $this->actingAs($organizer2)->post(route('organizer.checkpoints.generate-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($organizer2)->post(route('organizer.checkpoints.regenerate-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($organizer2)->get(route('organizer.checkpoints.download-qr', $checkpoint->id))->assertStatus(403);
        $this->actingAs($organizer2)->get(route('organizer.checkpoints.print-qr', $checkpoint->id))->assertStatus(403);
    });
});

describe('organizer qr code generation and lifecycle', function () {
    it('allows organizer to generate QR Code for active checkpoints', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'status' => 'active',
            'qr_token' => null,
        ]);

        $response = $this->actingAs($organizer)->post(route('organizer.checkpoints.generate-qr', $checkpoint->id));

        $response->assertRedirect(route('organizer.checkpoints.qr.show', $checkpoint->id));
        $checkpoint->refresh();
        expect($checkpoint->qr_token)->not->toBeNull();
        expect(Str::isUuid($checkpoint->qr_token))->toBeTrue();
    });

    it('does not allow generating QR Code for inactive checkpoints', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'status' => 'inactive',
            'qr_token' => null,
        ]);

        $response = $this->actingAs($organizer)->post(route('organizer.checkpoints.generate-qr', $checkpoint->id));

        $response->assertSessionHas('error');
        $checkpoint->refresh();
        expect($checkpoint->qr_token)->toBeNull();
    });

    it('allows organizer to view QR Code details page', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'qr_token' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($organizer)->get(route('organizer.checkpoints.qr.show', $checkpoint->id));

        $response->assertOk()
            ->assertSee($checkpoint->name)
            ->assertSee($event->name)
            ->assertSee('Generated');
    });

    it('allows organizer to download the QR Code file', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'qr_token' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($organizer)->get(route('organizer.checkpoints.download-qr', $checkpoint->id));

        $response->assertOk();
        $response->assertHeader('Content-Disposition');
        $contentDisposition = $response->headers->get('Content-Disposition');
        expect($contentDisposition)->toContain('attachment');
        expect($contentDisposition)->toContain(Str::slug($event->name.'-'.$checkpoint->name));
    });

    it('allows organizer to render print layout for QR Code', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'qr_token' => (string) Str::uuid(),
        ]);

        $response = $this->actingAs($organizer)->get(route('organizer.checkpoints.print-qr', $checkpoint->id));

        $response->assertOk()
            ->assertSee($event->name)
            ->assertSee($checkpoint->name)
            ->assertSee('Scan QR ini untuk mendapatkan poin checkpoint.')
            ->assertSee('window.print()');
    });

    it('allows organizer to regenerate QR Code changing the token', function () {
        $organizer = User::factory()->create(['role' => 'organizer']);
        $event = Event::factory()->create(['organizer_id' => $organizer->id]);
        $oldToken = (string) Str::uuid();
        $checkpoint = Checkpoint::factory()->create([
            'event_id' => $event->id,
            'status' => 'active',
            'qr_token' => $oldToken,
        ]);

        $response = $this->actingAs($organizer)->post(route('organizer.checkpoints.regenerate-qr', $checkpoint->id));

        $response->assertRedirect(route('organizer.checkpoints.qr.show', $checkpoint->id));
        $checkpoint->refresh();
        expect($checkpoint->qr_token)->not->toBe($oldToken);
        expect(Str::isUuid($checkpoint->qr_token))->toBeTrue();
    });
});
