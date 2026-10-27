<?php

namespace App\Http\Controllers;

class EmailsController extends Controller
{
    public function welcomeEmail()
    {
        abort(410, 'The sample email endpoint has been retired.');
    }
}
