<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Enum\PeriodoDaConsulta;
use App\Inteligencia\DTO\FerramentaDeLinguagem;
use App\Tarefa\Enum\AbaMetas;

/**
 * As ferramentas de CONSULTA (só leitura) que o Chat I.A oferece ao modelo (spec `docs/specs/chat-ia-prazos-e-metas.md`). Hoje
 * uma: `consultar_prazos_e_metas`. Ela não grava nada: o servidor roda a consulta da tela Minhas Metas da pessoa da sessão e
 * devolve o resultado ao modelo numa segunda chamada. Quem pode usar: {@see GuardaDaConsultaDePrazos}.
 */
final class FerramentasDaConsulta
{
    public const PRAZOS_E_METAS = 'consultar_prazos_e_metas';

    public static function prazosEMetas(): FerramentaDeLinguagem
    {
        return new FerramentaDeLinguagem(
            self::PRAZOS_E_METAS,
            'Lê os prazos e metas da própria pessoa que está conversando, os mesmos da tela Minhas Metas dela. Só lê: não cria, não altera '
            . 'e não conclui nada. Use quando ela perguntar o que vence hoje, o que vence nos próximos dias ou o que está atrasado. '
            . 'Não traz agenda nem pastas, nem metas de outras pessoas.',
            [
                'type' => 'object',
                'properties' => [
                    'periodo' => [
                        'type' => 'string',
                        'enum' => array_map(static fn (PeriodoDaConsulta $p): string => $p->value, PeriodoDaConsulta::cases()),
                        'description' => 'hoje = vencem hoje; proximos_7_dias = vencem de hoje até daqui a 7 dias (use para "esta semana"); atrasadas = prazo já passou',
                    ],
                    'papel' => [
                        'type' => 'string',
                        'enum' => array_map(static fn (AbaMetas $a): string => $a->value, AbaMetas::cases()),
                        'description' => 'responsavel (padrão) = as que estão com a pessoa para fazer; criei = as que ela delegou; acompanhando = as que ela acompanha; todas = as três',
                    ],
                ],
                'required' => ['periodo'],
                'additionalProperties' => false,
            ],
        );
    }
}
