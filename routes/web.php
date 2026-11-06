<?php

use Illuminate\Support\Facades\Route;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\RagController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/search-chunks', function () {

    $question = request('question', 'What is Laravel?');

    // 1. Generate embedding for the question
    $response = Http::post('http://localhost:11434/api/embeddings', [
        'model' => 'nomic-embed-text',
        'prompt' => $question,
    ]);

    if (!$response->successful()) {
        return response()->json([
            'message' => 'Failed to generate question embedding.',
            'error' => $response->body(),
        ], 500);
    }

    $embedding = $response->json('embedding');

    // 2. Convert embedding array to PostgreSQL vector format
    $vector = '[' . implode(',', $embedding) . ']';

    // 3. Search the most similar chunks
    $results = DB::select("
        SELECT
            document_chunks.id,
            document_chunks.document_id,
            documents.title,
            document_chunks.chunk_index,
            document_chunks.content,
            1 - (document_chunks.embedding <=> ?::vector) AS similarity
        FROM document_chunks
        INNER JOIN documents
            ON documents.id = document_chunks.document_id
        ORDER BY document_chunks.embedding <=> ?::vector
        LIMIT 5
    ", [
        $vector,
        $vector,
    ]);

    return response()->json([
        'question' => $question,
        'results' => $results,
    ]);
});

Route::get('/create-chunks', function () {

    $document = \App\Models\Document::find(1);

    if (!$document) {
        return response()->json([
            'message' => 'Document not found.'
        ], 404);
    }

    // Delete existing chunks
    $document->chunks()->delete();

    $text = trim($document->content);

    $chunkSize = 500;
    $overlap = 100;
    $minChunkSize = 50;

    $chunks = [];

    $start = 0;
    $length = strlen($text);

    while ($start < $length) {

        $end = min($start + $chunkSize, $length);

        // Get temporary chunk
        $chunk = substr($text, $start, $end - $start);

        // If not the last chunk,
        // move backwards until we find whitespace.
        if ($end < $length) {

            $lastSpace = strrpos($chunk, ' ');

            if ($lastSpace !== false) {
                $chunk = substr($chunk, 0, $lastSpace);
                $end = $start + $lastSpace;
            }
        }

        $chunk = trim($chunk);

        // Ignore chunks that are too small
        if (strlen($chunk) < $minChunkSize) {
            break;
        }

        $chunks[] = $chunk;

        // Move forward while keeping overlap
        $start = max(
            $end - $overlap,
            $start + 1
        );
    }

    foreach ($chunks as $index => $chunk) {

        $response = \Illuminate\Support\Facades\Http::post(
            'http://localhost:11434/api/embeddings',
            [
                'model' => 'nomic-embed-text',
                'prompt' => $chunk,
            ]
        );

        if (!$response->successful()) {
            return response()->json([
                'message' => 'Failed to generate embedding.',
                'chunk_index' => $index,
                'error' => $response->body(),
            ], 500);
        }

        $embedding = $response->json('embedding');

        \App\Models\DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => $index,
            'content' => $chunk,
            'embedding' => '[' . implode(',', $embedding) . ']',
        ]);
    }

    return response()->json([
        'message' => 'Smart chunks created successfully!',
        'document_id' => $document->id,
        'chunk_size' => $chunkSize,
        'overlap' => $overlap,
        'minimum_chunk_size' => $minChunkSize,
        'chunks' => count($chunks),
    ]);
});


Route::get('/test-ollama', function () {
    $response = Http::timeout(120)->post('http://localhost:11434/api/generate', [
        'model' => 'qwen3:8b',
        'prompt' => 'Explain RAG in one simple sentence.',
        'stream' => false,
    ]);

    return $response->json();
});

Route::get('/test-embedding', function () {
    $response = Http::timeout(120)->post('http://localhost:11434/api/embed', [
        'model' => 'nomic-embed-text',
        'input' => 'Laravel 13 is a PHP framework for building web applications.',
    ]);

    return $response->json();
});

Route::get('/test-embedding-dimension', function () {
    $response = Http::timeout(120)->post('http://localhost:11434/api/embed', [
        'model' => 'nomic-embed-text',
        'input' => 'Laravel 13 is a PHP framework for building web applications.',
    ]);

    $data = $response->json();

    return [
        'model' => $data['model'],
        'dimensions' => count($data['embeddings'][0]),
    ];
});

Route::get('/create-document', function () {
    $title = 'Laravel 13';

    $content = 'Laravel 13 is a modern PHP framework used for building web applications. It provides routing, database access, authentication, queues, caching, and many other features for modern PHP development.';

    // Generate embedding using Ollama
    $response = Http::timeout(120)->post('http://localhost:11434/api/embed', [
        'model' => 'nomic-embed-text',
        'input' => $content,
    ]);

    $embedding = $response->json('embeddings.0');

    // Save document + embedding
    $document = Document::create([
        'title' => $title,
        'content' => $content,
        'embedding' => '[' . implode(',', $embedding) . ']',
    ]);

    return [
        'message' => 'Document created successfully!',
        'document_id' => $document->id,
        'title' => $document->title,
        'embedding_dimensions' => count($embedding),
    ];
});

Route::get('/create-test-documents', function () {
    $documents = [
        [
            'title' => 'Vue 3',
            'content' => 'Vue 3 is a progressive JavaScript framework used for building interactive user interfaces and single-page applications.'
        ],
        [
            'title' => 'PostgreSQL',
            'content' => 'PostgreSQL is an open-source relational database system known for reliability, SQL support, extensibility, and advanced data management features.'
        ],
        [
            'title' => 'PHP 8.4',
            'content' => 'PHP 8.4 is a modern version of the PHP programming language with improved performance, new language features, and better developer experience.'
        ],
        [
            'title' => 'Inertia.js',
            'content' => 'Inertia.js allows developers to build modern single-page applications using classic server-side routing and controllers without requiring a separate API.'
        ],
    ];

    $created = [];

    foreach ($documents as $item) {
        $response = Http::timeout(120)->post(
            'http://localhost:11434/api/embed',
            [
                'model' => 'nomic-embed-text',
                'input' => $item['content'],
            ]
        );

        $embedding = $response->json('embeddings.0');

        $document = Document::create([
            'title' => $item['title'],
            'content' => $item['content'],
            'embedding' => '[' . implode(',', $embedding) . ']',
        ]);

        $created[] = [
            'id' => $document->id,
            'title' => $document->title,
            'dimensions' => count($embedding),
        ];
    }

    return [
        'message' => 'Test documents created successfully!',
        'documents' => $created,
    ];
});

Route::get('/search-documents', function () {
    $question = 'What is Laravel?';

    // Convert question into a vector
    $response = Http::timeout(120)->post(
        'http://localhost:11434/api/embed',
        [
            'model' => 'nomic-embed-text',
            'input' => $question,
        ]
    );

    $embedding = $response->json('embeddings.0');

    $vector = '[' . implode(',', $embedding) . ']';

    // Search for the most similar documents
    $results = DB::select(
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

    return [
        'question' => $question,
        'results' => $results,
    ];
});


Route::get('/ask-rag', function () {

    $question = request('question');

    if (!$question) {
        return response()->json([
            'message' => 'Please provide a question.'
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Create embedding for the question
    |--------------------------------------------------------------------------
    */

    $embeddingResponse = Http::post(
        'http://localhost:11434/api/embeddings',
        [
            'model' => 'nomic-embed-text',
            'prompt' => $question,
        ]
    );

    if (!$embeddingResponse->successful()) {
        return response()->json([
            'message' => 'Failed to generate question embedding.',
            'error' => $embeddingResponse->body(),
        ], 500);
    }

    $embedding = $embeddingResponse->json('embedding');

    $vector = '[' . implode(',', $embedding) . ']';


    /*
    |--------------------------------------------------------------------------
    | 2. Search relevant chunks using pgvector
    |--------------------------------------------------------------------------
    */

    $chunks = DB::select("
        SELECT
            document_chunks.id,
            document_chunks.document_id,
            documents.title,
            document_chunks.chunk_index,
            document_chunks.content,
            1 - (document_chunks.embedding <=> ?::vector) AS similarity
        FROM document_chunks
        INNER JOIN documents
            ON documents.id = document_chunks.document_id
        ORDER BY document_chunks.embedding <=> ?::vector
        LIMIT 3
    ", [
        $vector,
        $vector,
    ]);


    /*
    |--------------------------------------------------------------------------
    | 3. Build context from retrieved chunks
    |--------------------------------------------------------------------------
    */

    $context = collect($chunks)
        ->map(function ($chunk) {
            return "Source: {$chunk->title}\n{$chunk->content}";
        })
        ->implode("\n\n");


    /*
    |--------------------------------------------------------------------------
    | 4. Send context + question to Qwen
    |--------------------------------------------------------------------------
    */

    $prompt = <<<PROMPT
    You are a helpful AI assistant.

    Answer the user's question using ONLY the information provided in the context below.

    If the answer cannot be found in the context, say:
    "I don't have enough information in the provided documents."

    Context:
    {$context}

    Question:
    {$question}

    Answer:
    PROMPT;


    $ollamaResponse = Http::post(
        'http://localhost:11434/api/generate',
        [
            'model' => 'qwen3:8b',
            'prompt' => $prompt,
            'stream' => false,
        ]
    );

    if (!$ollamaResponse->successful()) {
        return response()->json([
            'message' => 'Failed to generate answer.',
            'error' => $ollamaResponse->body(),
        ], 500);
    }

    $answer = $ollamaResponse->json('response');


    /*
    |--------------------------------------------------------------------------
    | 5. Return complete RAG response
    |--------------------------------------------------------------------------
    */

    return response()->json([
        'question' => $question,
        'retrieved_chunks' => $chunks,
        'context' => $context,
        'answer' => $answer,
    ]);
});
