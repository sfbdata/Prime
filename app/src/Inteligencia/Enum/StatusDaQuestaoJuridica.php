<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Onde está cada questão jurídica do bloco "Questões jurídicas" da aba "Configurações da I.A." do Master. Questão do
 * catálogo sem linha no banco está pendente; enquanto houver questão não resolvida, o Master vê o aviso do topo.
 */
enum StatusDaQuestaoJuridica: string
{
    case Pendente = 'pendente';
    case EmAnalise = 'em_analise';
    case Resolvida = 'resolvida';

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::EmAnalise => 'Em análise',
            self::Resolvida => 'Resolvida',
        };
    }
}
