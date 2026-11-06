<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OllamaService
{
    protected string $baseUrl = 'http://localhost:11434';

    public function embed(string $text): array
    {
        $response = Http::timeout(120)->post(
            "{$this->baseUrl}/api/embed",
            [
                'model' => 'nomic-embed-text',
                'input' => $text,
            ]
        );

        $response->throw();

        return $response->json('embeddings.0');
    }

    public function generate(string $prompt): string
    {
        $response = Http::timeout(120)->post(
            "{$this->baseUrl}/api/generate",
            [
                'model' => 'qwen3:8b',
                'prompt' => $prompt,
                'stream' => false,
            ]
        );

        $response->throw();

        return $response->json('response');
    }
}
