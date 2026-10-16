<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

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
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, AcaoDemanda $acao, ?string $motivo): ?DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        // Estado antes de permissão: com a tela velha aberta, "já mudou de situação" é 409, não 403.
        if (!in_array($demanda->getSituacao(), $acao->origens(), true)) {
            throw new TransicaoInvalida(sprintf('A demanda está %s: a ação não se aplica mais. Recarregue a tela.', mb_strtolower($demanda->getSituacao()->rotulo())));
        }
        if (!$this->politica->podeExecutar($ctx, $demanda, $acao)) {
            throw new AcaoNaoPermitida('Você não pode executar esta ação nesta demanda.');
        }

        if ($acao === AcaoDemanda::ExcluirRascunho) {
            $this->excluirRascunho($demanda);

            return null;
        }

        // O papel da autoria: o Master que age pelo escritório dele fica registrado como Solicitante.
        $demanda->aplicar($acao, Autor::de($ctx->usuario, $this->politica->papelNaAcao($ctx, $demanda, $acao)), $motivo);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }

    /** Arquivo só sai DEPOIS do COMMIT (INV-6): se o DELETE falhar, nada some do disco. */
    private function excluirRascunho(DemandaExterna $demanda): void
    {
        $chaves = array_map(ChavesDeTrabalhoExterno::documento(...), $demanda->getDocumentos());
        $contexto = 'rascunho excluído do Trabalho Externo ' . $demanda->getCodigo();

        $this->repositorio->remover($demanda, flush: true);
        $this->remocao->remover($chaves, $contexto);
    }
}
