<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\ContentController;
use App\Http\Controllers\Api\Admin\HomeCurationController;
use App\Http\Controllers\Api\Admin\OfferController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\TaxonomyController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\InvitationController;
use App\Http\Controllers\Api\PublicCatalogController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\StorefrontProfileController;
use Illuminate\Support\Facades\Route;

    Route::prefix('v1')->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('register/verify', [AuthController::class, 'verifyRegistration']);
            Route::post('register/resend', [AuthController::class, 'resendRegistrationCode']);
            Route::post('login', [AuthController::class, 'login']);
        });

    Route::prefix('invites')->group(function (): void {
        Route::get('{token}', [InvitationController::class, 'show']);
        Route::post('{token}/accept', [InvitationController::class, 'accept']);
    });

    Route::prefix('public')->group(function (): void {
        Route::get('home', [PublicCatalogController::class, 'home']);
        Route::get('catalog', [PublicCatalogController::class, 'catalog']);
        Route::get('content/{slug}', [PublicCatalogController::class, 'show']);
    });

    Route::middleware('api.token')->group(function (): void {
        Route::prefix('auth')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });

        Route::prefix('settings')->group(function (): void {
            Route::put('profile', [SettingsController::class, 'updateProfile']);
            Route::put('password', [SettingsController::class, 'updatePassword']);
        });

        Route::prefix('storefront')->middleware('permission:storefront.access')->group(function (): void {
            Route::get('account', [StorefrontController::class, 'account']);
            Route::post('profiles', [StorefrontProfileController::class, 'store'])->middleware('permission:profile.manage');
            Route::patch('profiles/{profile}', [StorefrontProfileController::class, 'update'])->middleware('permission:profile.manage');
            Route::delete('profiles/{profile}', [StorefrontProfileController::class, 'destroy'])->middleware('permission:profile.manage');
            Route::put('profiles/{profile}/favorites/{identifier}', [StorefrontProfileController::class, 'favorite'])->middleware('permission:profile.manage');
            Route::delete('profiles/{profile}/favorites/{identifier}', [StorefrontProfileController::class, 'unfavorite'])->middleware('permission:profile.manage');
            Route::post('offers/{offer}/purchase', [StorefrontController::class, 'purchase'])->middleware('permission:content.purchase');
            Route::get('content/{identifier}/playback', [StorefrontController::class, 'playback'])->middleware('permission:content.watch');
        });

        Route::prefix('admin')->middleware('admin.panel')->group(function (): void {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('home-curation', [HomeCurationController::class, 'index'])->middleware('permission:settings.edit_home_curation');
            Route::put('home-curation', [HomeCurationController::class, 'update'])->middleware('permission:settings.edit_home_curation');

            Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
            Route::post('users/invite', [UserController::class, 'invite'])->middleware('permission:users.invite');
            Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.edit');

            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:settings.manage_roles');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:settings.manage_roles');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:settings.manage_roles');

            Route::get('content', [ContentController::class, 'index'])->middleware('permission:content.view');
            Route::get('content/options', [ContentController::class, 'options'])->middleware('permission:content.view');
            Route::get('content/{content}', [ContentController::class, 'show'])->middleware('permission:content.view');
            Route::post('content', [ContentController::class, 'store'])->middleware('permission:content.create');
            Route::patch('content/{content}', [ContentController::class, 'update'])->middleware('permission:content.edit');
            Route::delete('content/{content}', [ContentController::class, 'destroy'])->middleware('permission:content.delete');

            Route::get('offers', [OfferController::class, 'index'])->middleware('permission:commerce.view');
            Route::post('offers', [OfferController::class, 'store'])->middleware('permission:commerce.create_offers');
            Route::patch('offers/{offer}', [OfferController::class, 'update'])->middleware('permission:commerce.edit_offers');
            Route::delete('offers/{offer}', [OfferController::class, 'destroy'])->middleware('permission:commerce.edit_offers');

            Route::get('taxonomies', [TaxonomyController::class, 'index'])->middleware('permission:taxonomies.view');
            Route::post('taxonomies', [TaxonomyController::class, 'store'])->middleware('permission:taxonomies.create');
            Route::patch('taxonomies/{taxonomy}', [TaxonomyController::class, 'update'])->middleware('permission:taxonomies.edit');
            Route::delete('taxonomies/{taxonomy}', [TaxonomyController::class, 'destroy'])->middleware('permission:taxonomies.delete');
        });
    });
});
