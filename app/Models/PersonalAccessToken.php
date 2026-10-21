<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // Sanctum hace un UPDATE de last_used_at en CADA petición autenticada (un viaje extra a la BD
    // remota por request). Solo se persiste si el valor anterior tiene más de 5 minutos.
    public function save(array $options = [])
    {
        if ($this->exists && array_keys($this->getDirty()) === ['last_used_at']) {
            $anterior = $this->getOriginal('last_used_at');

            if ($anterior && $anterior->gt(now()->subMinutes(5))) {
                return true;
            }
        }

        return parent::save($options);
    }
}
