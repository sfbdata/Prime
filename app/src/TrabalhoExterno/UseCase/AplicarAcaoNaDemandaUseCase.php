<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Psr\Log\LoggerInterface;

/**
 * Uma ação do fluxo da demanda (spec §4): iniciar análise, recusar, aceitar proposta, concluir…
 *
 * Quem: Master, Equipe (conforme a configuração) ou Solicitante, cada um com o seu conjunto.
 * Fluxo: localiza a demanda no escopo do perfil; confere na política que a ação está entre os
 * botões que ESTE perfil vê agora; aplica na entidade (que confere a regra de estado e o motivo);
 * grava. "Excluir rascunho" apaga a demanda e, depois do COMMIT, os arquivos dela.
 * Alternativos: ação fora do perfil → AcaoNaoPermitida (403); fora do estado ou sem motivo →
 * TransicaoInvalida (409); demanda de outro escritório → DemandaNaoEncontrada (404).
 */
final class AplicarAcaoNaDemandaUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, AcaoDemanda $acao, ?string $motivo): ?DemandaExterna
    {
        $demanda = $this->localizador->localizar($ctx, $codigo);

        if (!$this->politica->podeExecutar($ctx, $demanda, $acao)) {
            throw new AcaoNaoPermitida('Você não pode executar esta ação nesta demanda.');
        }

        if ($acao === AcaoDemanda::ExcluirRascunho) {
            $this->excluirRascunho($demanda);

            return null;
        }

        $demanda->aplicar($acao, $ctx->autor(), $motivo);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }

    /** Arquivo só sai DEPOIS do COMMIT (INV-6): se o DELETE falhar, nada some do disco. */
    private function excluirRascunho(DemandaExterna $demanda): void
    {
        $chaves = array_map(ChavesDeTrabalhoExterno::documento(...), $demanda->getDocumentos());

        $this->repositorio->remover($demanda, flush: true);

        foreach ($chaves as $chave) {
            try {
                $this->armazenamento->excluir($chave);
            } catch (\Throwable $e) {
                $this->logger->warning('Trabalho Externo: arquivo de rascunho excluído ficou no disco.', ['arquivo' => $chave->nome, 'erro' => $e->getMessage()]);
            }
        }
    }
}
