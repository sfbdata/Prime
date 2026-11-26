<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Entity\UnidadeDaAssembleia;

/** A qualificação do eleitor (nome do responsável e rótulo da unidade) como a ocorrência a congela. */
final class QualificacaoDoEleitor
{
    private function __construct()
    {
    }

    /** Nome do responsável pela unidade; vazio se o cadastro não tem. */
    public static function nome(UnidadeDaAssembleia $unidade): string
    {
        return trim((string) $unidade->getResponsavel());
    }

    /** "Bloco · identificação" (ou só a identificação, sem bloco). CPF de 11 dígitos sai mascarado. */
    public static function rotulo(UnidadeDaAssembleia $unidade): string
    {
        $id = $unidade->getIdentificacao();
        if (1 === preg_match('/^\d{11}$/', $id)) {
            $id = '***.' . substr($id, 3, 3) . '.' . substr($id, 6, 3) . '-**';
        }

        return '' === $unidade->getBloco() ? $id : $unidade->getBloco() . ' · ' . $id;
    }
}
