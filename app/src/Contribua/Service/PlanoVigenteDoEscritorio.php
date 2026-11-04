<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;

/**
 * Põe no `EspacoDoEscritorio` o plano que as assinaturas dão hoje: o maior entre as vigentes (pelo GB já
 * multiplicado, desenho 08 VS 03.11.26), com o multiplicador dela, ou o Free quando nenhuma vale mais ("o espaço vale até o fim do mês pago"; nada é apagado, só a cota volta).
 *
 * Não grava (quem chama dá o flush, junto com a mudança da assinatura). O limite próprio do Master
 * (`definirLimite`, "sem limite") continua por cima do plano, como na C1.
 */
final class PlanoVigenteDoEscritorio
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly PlanoRepository $planos,
        private readonly ConfiguracaoDePagamentoRepository $configuracao,
    ) {
    }

    /** Devolve o plano que ficou valendo. */
    public function atualizar(int $tenantId, \DateTimeImmutable $agora): Plano
    {
        $vigente = $this->maiorVigente($tenantId, $agora);
        $plano = $vigente?->getPlano() ?? $this->planos->free();
        $multiplicador = $vigente?->getMultiplicador() ?? 1;
        $espaco = $this->espacos->doTenant($tenantId);
        if ($espaco === null && $plano->ehGratuito()) {
            return $plano;
        }

        $espaco ??= $this->espacos->doTenantOuNovo($tenantId, $plano);
        if ($espaco->getPlano()->getId() !== $plano->getId() || $espaco->getMultiplicador() !== $multiplicador) {
            $espaco->mudarDePlano($plano, $multiplicador);
        }
        $this->espacos->salvar($espaco);

        return $plano;
    }

    private function maiorVigente(int $tenantId, \DateTimeImmutable $agora): ?AssinaturaDeEspaco
    {
        $carencia = $this->configuracao->atual()->getDiasDeCarencia();
        $vigentes = array_filter(
            $this->assinaturas->doTenant($tenantId),
            static fn (AssinaturaDeEspaco $a): bool => $a->vigenteEm($agora, $carencia),
        );

        $maior = null;
        foreach ($vigentes as $assinatura) {
            if ($maior === null || $assinatura->gigabytes() > $maior->gigabytes()) {
                $maior = $assinatura;
            }
        }

        return $maior;
    }
}
