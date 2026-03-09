<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');

    //redirect to the filament admin
    return redirect()->to('admin');
});
