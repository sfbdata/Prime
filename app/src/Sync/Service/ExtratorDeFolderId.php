<?php

declare(strict_types=1);

namespace App\Sync\Service;

/**
 * Extrai o id de uma pasta do Google Drive a partir do que o admin colar na tela "Conectar meu Drive":
 * o id cru ou uma URL (`.../folders/<id>`, `?id=<id>`). Ids do Drive são [A-Za-z0-9_-] (>= 10 chars).
 */
final class ExtratorDeFolderId
{
    /**
     * @throws \InvalidArgumentException se não houver um id extraível na entrada.
     */
    public function extrair(string $entrada): string
    {
        $entrada = trim($entrada);
        if ($entrada === '') {
            throw new \InvalidArgumentException('Informe a pasta-raiz do Drive.');
        }

        // .../folders/<id>  ou  ?id=<id>  (URL do Drive).
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $entrada, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $entrada, $m) === 1) {
            return $m[1];
        }

        // Id cru (entrada inteira é um id válido).
        if (preg_match('#^[A-Za-z0-9_-]{10,}$#', $entrada) === 1) {
            return $entrada;
        }

        throw new \InvalidArgumentException('Não reconheci um id/URL de pasta do Google Drive.');
    }
}
