<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Master\Entity\MetaDeDivulgacao;

/**
 * O que o bloco "Metas de divulgação" mostra: a configuração gravada e os advogados por estado da tabela da OAB (a
 * fonte do mercado e da meta proporcional). O resto (usuários por estado e por cidade) o painel já tem do mapa.
 */
final readonly class MetasDeDivulgacaoOutput
{
    /** @param array<string, int> $advogadosPorUf sigla da UF => advogados regulares na OAB */
    public function __construct(
        private MetaDeDivulgacao $meta,
        private array $advogadosPorUf,
        private string $urlDoQuadro,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        $m = $this->meta;

        return [
            'mercado' => $m->getMercado(),
            'alvo' => $m->getAlvo(),
            'fonte' => $m->getFonte(),
            'referencia' => $m->getReferencia(),
            'automatica' => $m->isAutomatica(),
            'frequencia' => $m->getFrequencia(),
            'avancarDeFase' => $m->isAvancarDeFase(),
            'mercadoManual' => $m->isMercadoManual(),
            'ultimaAutomatica' => $m->getUltimaAutomatica()?->format(\DATE_ATOM),
            'proximaAutomatica' => $m->proximaAtualizacao()->format(\DATE_ATOM),
            'destaques' => (object) $m->getDestaques(),
            'metasPorUf' => (object) $m->getMetasPorUf(),
            'metasPorCidade' => (object) array_map(static fn (array $c): object => (object) $c, $m->getMetasPorCidade()),
            'advogadosPorUf' => (object) $this->advogadosPorUf,
            'urlDoQuadro' => $this->urlDoQuadro,
        ];
    }
}
