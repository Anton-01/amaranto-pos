<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/password-reset/{user}', function () {
    return redirect('/login');
})->name('password.reset');
