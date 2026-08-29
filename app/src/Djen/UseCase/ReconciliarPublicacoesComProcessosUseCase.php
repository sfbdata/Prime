<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;

/**
 * Religa ao Processo as publicações que ficaram AVULSAS por ordem de chegada.
 *
 * A sincronização grava a FK olhando o cadastro no instante da captura. Se o processo entra no
 * sistema depois — o caso normal quando o escritório assume um caso já em andamento —, a
 * publicação fica órfã para sempre e a tela do módulo a carimba de "Avulsa", que é falso. Em
 * produção eram 8 publicações assim, todas com o processo criado depois da captura.
 *
 * A aba Push Processual da pasta NÃO depende disto (lá o casamento é por número). Isto conserta o
 * dado — e, chamado ao fim de cada sincronização, impede o buraco de reabrir amanhã.
 *
 * Duas consultas, não uma por publicação: as avulsas do escritório, e os processos cujos números
 * casam. Em produção seriam 161 idas ao banco contra 2.
 */
final readonly class ReconciliarPublicacoesComProcessosUseCase
{
    public function __construct(
        private PublicacaoDjenRepository $publicacaoRepository,
        private ProcessoRepository $processoRepository,
    ) {
    }

    /**
     * @param bool $simular conta o que faria sem gravar (e sem sujar a entidade para outro flush)
     *
     * @return int quantas publicações foram (ou seriam) religadas
     */
    public function executar(Tenant $tenant, bool $simular = false): int
    {
        $avulsas = $this->publicacaoRepository->listarAvulsasDoTenant($tenant);
        if ($avulsas === []) {
            return 0;
        }

        $numeros = [];
        foreach ($avulsas as $publicacao) {
            $numero = $publicacao->getNumeroProcesso();
            if ($numero !== '') {
                $numeros[$numero] = true;
            }
        }
        if ($numeros === []) {
            return 0;
        }

        $processosPorNumero = $this->processoRepository->findPorNumerosDoTenant(array_keys($numeros), $tenant);

        $religadas = 0;
        foreach ($avulsas as $publicacao) {
            $processo = $processosPorNumero[$publicacao->getNumeroProcesso()] ?? null;
            if ($processo === null) {
                continue;
            }

            ++$religadas;
            if (!$simular) {
                $publicacao->setProcesso($processo);
            }
        }

        if ($religadas > 0 && !$simular) {
            $this->publicacaoRepository->flush();
        }

        return $religadas;
    }
}
