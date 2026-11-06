<?php

namespace App\Http\Controllers;

use App\Services\RagService;
use Illuminate\Http\Request;

class RagController extends Controller
{
    public function ask(Request $request, RagService $rag)
    {
        $request->validate([
            'question' => ['required', 'string'],
        ]);

        return response()->json(
            $rag->ask($request->question)
        );
    }
}
