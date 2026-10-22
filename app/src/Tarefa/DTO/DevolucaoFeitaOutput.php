<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Resultado da devolução, para o aviso (toast) da tela: o nome de quem recebeu a meta de volta e se o aviso chegou
 * a ser mandado (não vai a quem perdeu o vínculo com o escritório ou não vê mais a pasta).
 */
final readonly class DevolucaoFeitaOutput
{
    public function __construct(
        public string $para,
        public bool $avisado,
    ) {
    }
}
