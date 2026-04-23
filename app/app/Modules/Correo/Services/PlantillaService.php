<?php

namespace App\Modules\Correo\Services;

use App\Modules\Correo\Models\CorreoPlantilla;

class PlantillaService
{
    public function getAll(): \Illuminate\Database\Eloquent\Collection
    {
        return CorreoPlantilla::where('is_active', true)->get();
    }

    public function getByName(string $name): ?CorreoPlantilla
    {
        return CorreoPlantilla::where('name', $name)->where('is_active', true)->first();
    }

    public function create(array $data): CorreoPlantilla
    {
        return CorreoPlantilla::create($data);
    }

    public function update(CorreoPlantilla $plantilla, array $data): CorreoPlantilla
    {
        $plantilla->update($data);
        return $plantilla->fresh();
    }

    public function delete(CorreoPlantilla $plantilla): void
    {
        $plantilla->update(['is_active' => false]);
    }

    public function renderTemplate(CorreoPlantilla $plantilla, array $variables): array
    {
        $subject = $this->replaceVariables($plantilla->subject, $variables);
        $body = $this->replaceVariables($plantilla->body_html, $variables);

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }

    private function replaceVariables(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
        return $template;
    }
}
