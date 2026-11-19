<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\BuscarPastasFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Master\Service\RecursosDoSistema;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;

/**
 * A conferência da linha da {@see TabelaDeAbaEChave} para uma ação de uma ferramenta do conector
 * (`docs/specs/mcp-remoto-oauth.md`, 6.2, regras 1, 2 e 11, e a tabela da 6.6). O que a tela barra pela rota, o
 * conector confere aqui, num lugar só, nesta ordem:
 *
 *  1. a ação está na tabela; senão, "pedido inválido" (ferramenta ou ação fora da lista fechada);
 *  2. as chaves do Master estão ligadas ({@see RecursosDoSistema::estaAtivo()}, nunca `visivel`: o Master não passa
 *     com a chave desligada, porque o conector não tem a marcação vermelha da tela); senão, "função desligada";
 *  3. a pessoa tem um dos módulos da linha ({@see PermissionChecker::canAccessModule()}, com a pessoa e o escritório da
 *     chamada e o modo do conector já ligado pelo portão, 6.3); senão, "sem permissão";
 *  3b. no acesso restrito (spec acesso-restrito-e-compartilhar, fatia 3), as ações que a tela fecha pela barreira do
 *     restrito mesmo com o módulo liberado ({@see FECHADAS_NO_ACESSO_RESTRITO}: a lixeira do escritório); senão,
 *     "sem permissão". O escopo das pastas já vem pelo `PermissionChecker` e pelo {@see AcessoAPasta}, como na tela;
 *  4. com pasta: a pasta é do escritório da chamada e a pessoa pode vê-la ({@see AcessoAPasta::podeVer()}, com o cadeado);
 *     senão, "não encontrado", a mesma resposta de alheia e inexistente (6.2, regra 1);
 *  5. com pasta: nenhuma aba da linha está oculta para a pessoa ({@see VisibilidadeDasAbasDaPasta::abasOcultasPara()});
 *     senão, "não encontrado" (6.2, regra 11).
 *
 * Chave e módulo vêm antes da pasta: as duas recusas não dependem da pasta, e nenhuma diz se ela existe.
 *
 * Não confere: o segredo de justiça (a ferramenta, pelo `MontadorDeContextoDaPasta` ou pela {@see SegredoNasListas}),
 * o recurso de editar (a ferramenta, por {@see exigirEdicaoDaPasta()}, nas ações que a tela só abre a quem edita), a pasta na lixeira e o Financeiro (6.2, regra 5). Pessoa e escritório vêm só da
 * {@see ChamadaDoConector}: nada de sessão, Security, Request ou filtro do Doctrine.
 */
final class GuardaDaAcaoDoConector
{
    /** Ferramenta => ações que a barreira do restrito fecha na tela (`expediente_lixeira`). */
    public const FECHADAS_NO_ACESSO_RESTRITO = [BuscarPastasFerramenta::NOME => ['lixeira']];

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly RecursosDoSistema $recursos,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly EscopoDePastas $escopo,
    ) {
    }

    /** @throws ErroDaFerramenta */
    public function exigir(ChamadaDoConector $chamada, string $ferramenta, string $acao, ?Pasta $pasta = null): void
    {
        $linha = TabelaDeAbaEChave::linha($ferramenta, $acao) ?? throw ErroDaFerramenta::entradaInvalida();

        foreach ($linha->chavesExigidas($pasta !== null) as $chave) {
            if (!$this->recursos->estaAtivo($chave)) {
                throw ErroDaFerramenta::funcaoDesligada();
            }
        }

        if ($linha->modulos !== [] && !$this->temUmDosModulos($chamada, $linha->modulos)) {
            throw ErroDaFerramenta::semPermissao();
        }

        if (\in_array($acao, self::FECHADAS_NO_ACESSO_RESTRITO[$ferramenta] ?? [], true)
            && $this->escopo->ehRestritoNoEscritorio($chamada->contexto->pessoa, $chamada->contexto->escritorio)) {
            throw ErroDaFerramenta::semPermissao();
        }

        if ($pasta === null) {
            return;
        }

        $pessoa = $chamada->contexto->pessoa;
        $escritorioDaPasta = $pasta->getTenant()?->getId();
        $doEscritorio = $escritorioDaPasta !== null && $escritorioDaPasta === $chamada->contexto->escritorio->getId();
        if (!$doEscritorio || !$this->acessoAPasta->podeVer($pessoa, $pasta)) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        if ($linha->abas === []) {
            return;
        }
        $ocultas = $this->abas->abasOcultasPara($pasta, $pessoa);
        foreach ($linha->abas as $aba) {
            if (\in_array($aba, $ocultas, true)) {
                throw ErroDaFerramenta::naoEncontrado();
            }
        }
    }

    /**
     * O recurso `pasta` de editar, como a tela exige na lixeira e na busca para vincular (`PastaDocumentoController::lixeira`,
     * `PastaController::buscarProcessos`). Quem só vê recebe "não encontrado", sem revelar que a ação existe.
     *
     * @throws ErroDaFerramenta
     */
    public function exigirEdicaoDaPasta(ChamadaDoConector $chamada, Pasta $pasta): void
    {
        // Pela mesma porta da tela e do `podeVer` acima (decisão 26): o que o acesso restrito mudar no AcessoAPasta vale aqui também.
        $contexto = $chamada->contexto;
        $escritorioDaPasta = $pasta->getTenant()?->getId();
        $doEscritorio = $escritorioDaPasta !== null && $escritorioDaPasta === $contexto->escritorio->getId();
        if (!$doEscritorio || !$this->acessoAPasta->podeEditar($contexto->pessoa, $pasta)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
    }

    /** @param list<string> $modulos */
    private function temUmDosModulos(ChamadaDoConector $chamada, array $modulos): bool
    {
        foreach ($modulos as $modulo) {
            if ($this->permissoes->canAccessModule($chamada->contexto->pessoa, $chamada->contexto->escritorio, $modulo)) {
                return true;
            }
        }

        return false;
    }
}
