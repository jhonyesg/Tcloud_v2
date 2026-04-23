<?php

namespace App\Modules\Correo\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorreoPlantilla extends Model
{
    protected $table = 'correo_plantillas';
    protected $fillable = ['name', 'display_name', 'subject', 'body_html', 'variables', 'is_active', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getVariablesArrayAttribute(): array
    {
        if (!$this->variables) {
            return [];
        }
        return array_map('trim', explode(',', $this->variables));
    }

    public function setVariablesArrayAttribute(array $vars): void
    {
        $this->variables = implode(', ', $vars);
    }
}
