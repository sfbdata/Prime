<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\PontoAjuste;

/**
 * Uma batida que o gestor (ou a análise da justificativa) tirou da conta, para a linha "Desconsideradas" da ficha do
 * colaborador, com o "Restaurar" (E10, plano-e10-ponto §4). A da análise não se restaura pela tela (decisão A): a
 * ficha mostra a batida sem o botão.
 */
final class BatidaDesconsideradaOutput
{
    public function __construct(
        public readonly int $ajusteId,
        public readonly string $tipo,
        public readonly string $dia,
        public readonly string $hora,
        public readonly bool $pelaAnalise,
        public readonly string $por,
        public readonly string $em,
    ) {
    }

    public static function fromAjuste(PontoAjuste $desconsideracao): self
    {
        $batida = $desconsideracao->getDataHoraAnterior() ?? throw new \LogicException('A desconsideração guarda a batida que tirou da conta.');

        return new self(
            ajusteId: $desconsideracao->getId() ?? throw new \LogicException('A desconsideração ainda não foi gravada.'),
            tipo: (string) $desconsideracao->getTipoMarcacaoAnterior(),
            dia: $batida->format('Y-m-d'),
            hora: $batida->format('H:i:s'),
            pelaAnalise: $desconsideracao->getJustificativa() !== null || $desconsideracao->getDesfaz() !== null,
            por: $desconsideracao->getAutorNome(),
            em: $desconsideracao->getCriadoEm()->format('d/m/Y'),
        );
    }

    /**
     * As desconsiderações agrupadas pelo dia da batida (a chave da linha da folha, 'Y-m-d'), na ordem em que vieram.
     *
     * @param list<PontoAjuste> $desconsideracoes
     *
     * @return array<string, list<self>>
     */
    public static function porDia(array $desconsideracoes): array
    {
        $porDia = [];
        foreach ($desconsideracoes as $desconsideracao) {
            $saida = self::fromAjuste($desconsideracao);
            $porDia[$saida->dia][] = $saida;
        }

        return $porDia;
    }
}
