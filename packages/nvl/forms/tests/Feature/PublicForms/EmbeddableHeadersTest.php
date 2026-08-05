<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Nvl\Forms\Enums\FormType;
use Nvl\Forms\Http\Middleware\ValidateFormHost;
use Nvl\Forms\Models\AllowedOrigin;
use Nvl\Forms\Models\Form;

test('public iframe form responses do not include x-frame-options', function (): void {
    $form = Form::factory()->create([
        'type' => FormType::IFRAME,
        'restrict_public_access' => true,
        'enable_rate_limiting' => false,
    ]);

    AllowedOrigin::factory()->for($form)->create([
        'origin' => 'allowed.test',
        'is_active' => true,
    ]);

    Route::middleware([ValidateFormHost::class])
        ->get('/public/forms/{form}', function (Request $request) {
            return response()->json([
                'frame_ancestors' => $request->attributes->get('forms.frame_ancestors'),
            ]);
        });

    $response = $this->withHeaders([
        'Referer' => 'https://allowed.test/pages/booking',
        'Sec-Fetch-Dest' => 'iframe',
    ])->get("/public/forms/{$form->id}");

    $response->assertOk();
    $response->assertHeaderMissing('X-Frame-Options');

    $csp = $response->json('frame_ancestors');
    expect($csp)->toContain('https://allowed.test');
});
