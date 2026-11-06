<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class RagService
{
    public function __construct(
        protected OllamaService $ollama
    ) {
    }

    public function ask(string $question): array
    {
        // 1. Convert question to embedding
        $embedding = $this->ollama->embed($question);

        $vector = '[' . implode(',', $embedding) . ']';

        // 2. Search relevant documents
        $documents = DB::select(
            "
            SELECT
                id,
                title,
                content,
                1 - (embedding <=> ?::vector) AS similarity
            FROM documents
            ORDER BY embedding <=> ?::vector
            LIMIT 3
            ",
            [$vector, $vector]
        );

        // 3. Build context
        $context = collect($documents)
            ->map(function ($document) {
                return "Title: {$document->title}\n"
                    . "Content: {$document->content}";
            })
            ->implode("\n\n");

        // 4. Build prompt
        $prompt = <<<PROMPT
        You are a helpful AI assistant.
        Answer the user's question using ONLY the provided context.
        If the answer cannot be found in the context, say:
        "I don't have enough information in the provided documents."
        Context:

        {$context}

        Question:

        {$question}

        Answer:
        PROMPT;

        // 5. Generate answer using Qwen
        $answer = $this->ollama->generate($prompt);

        return [
            'question' => $question,
            'documents' => $documents,
            'answer' => $answer,
        ];
    }
}
