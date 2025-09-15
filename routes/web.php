<?php

// routes/web.php
use Illuminate\Support\Facades\Route;

Route::get('/videocall/{role}', function ($role) {
    if (!in_array($role, ['presenter', 'viewer'])) {
        abort(404);
    }
    return view('videocall', ['role' => $role]);
});
