<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\PontosDoRelatorioInicial;

/**
 * Os "Pontos do relatório inicial" da pasta (card da aba Detalhes, `pasta_detalhes_pontos_relatorio`, a folha impressa
 * e a parte `controladoria` da `ler_pasta` do conector de I.A.): os três pontos (objetivos, acordos e resultado esperado)
 * e se cada um aparece nas observações do relatório inicial.
 *
 * Quem: alguém que vê a pasta, com o cadeado ({@see AcessoAPasta::podeVer()}). Lê o relatório INTEIRO
 * (`conteudosDaPasta`), não a lista de exibição com teto. A aba Detalhes oculta é barrada por quem chama (a rota na tela,
 * a tabela de aba e chave no conector).
 *
 * Só leitura: nada é gravado.
 */
final class LerPontosDoRelatorioInicialUseCase
{
    public function __construct(
        private readonly PastaObservacaoDetalhesRepository $observacoes,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     */
    public function executar(Pasta $pasta, User $quem, Tenant $tenant): PontosDoRelatorioInicial
    {
        $doEscritorio = $pasta->getTenant() === $tenant
            || ($tenant->getId() !== null && $pasta->getTenant()?->getId() === $tenant->getId());
        if (!$doEscritorio) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        return PontosDoRelatorioInicial::dosTextos($this->observacoes->conteudosDaPasta($pasta, $tenant));
    }
}
