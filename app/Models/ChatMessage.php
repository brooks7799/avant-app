<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'document_chat_id',
        'role',
        'content',
        'input_tokens',
        'output_tokens',
        'model_used',
        'cost',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    public function documentChat(): BelongsTo
    {
        return $this->belongsTo(DocumentChat::class);
    }
}
