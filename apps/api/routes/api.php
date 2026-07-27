<?php

use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['status' => 'ok', 'service' => 'qayd-api']));
