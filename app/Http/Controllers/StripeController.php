<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StripeController extends Controller
{
    public function config()
    {
        return response()->json([
            'publishableKey' => config('services.stripe.key'),
        ]);
    }
}
