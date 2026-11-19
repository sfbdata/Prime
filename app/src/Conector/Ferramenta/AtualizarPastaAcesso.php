<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Entity\Permission\AccessRequest;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Service\PermissionChecker;

/**
 * As recusas de acesso da `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.2 e 6.6), num lugar só, na mesma ordem
 * ao propor e ao reconferir:
 *
 *  1. a linha da tabela sem a pasta (chave do Master e módulo, {@see GuardaDaAcaoDoConector::exigir()});
 *  2. a pasta por id E escritório; alheia ou inexistente: "não encontrado";
 *  3. a linha com a pasta (ver a pasta, com o cadeado);
 *  4. a pasta na lixeira: "não encontrado" (salvo em {@see naLixeira()}, do restaurar);
 *  5. o segredo de justiça: a frase do segredo;
 *  6. quando a tela só abre a quem edita, o recurso `pasta` de editar ({@see GuardaDaAcaoDoConector::exigirEdicaoDaPasta()}).
 *
 * Além disso, o que a tela de cada ação exige a mais: o recurso `pasta` com a ação `delete` (excluir e restaurar a
 * pasta, `pasta_delete` e `pasta_restaurar`) e o módulo `expediente` (os marcadores do escritório,
 * `ExpedienteController::assertAccess`). Só lê; pessoa e escritório vêm só da {@see ChamadaDoConector}.
 */
final class AtualizarPastaAcesso
{
    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly ProcessoRepository $processos,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** @throws ErroDaFerramenta */
    public function exigirLinha(ChamadaDoConector $chamada, string $acao): void
    {
        $this->guarda->exigir($chamada, AtualizarPastaFerramenta::NOME, $acao);
    }

    /**
     * A pasta viva, conferida da linha ao segredo; com `$editar`, também o recurso de editar.
     *
     * @throws ErroDaFerramenta
     */
    public function viva(ChamadaDoConector $chamada, string $acao, int $pastaId, bool $editar): Pasta
    {
        $pasta = $this->daLinha($chamada, $acao, $pastaId);
        if ($pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        try {
            $this->liberacao->liberar($chamada->contexto->pessoa, $chamada->contexto->escritorio, $pastaId);
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }
        if ($editar) {
            $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);
        }

        return $pasta;
    }

    /**
     * A pasta da lixeira, para o restaurar: a mesma ordem, com o segredo conferido pelos processos vinculados (o
     * contexto da I.A. não abre pasta excluída) e o recurso `pasta` com `delete`, como a tela.
     *
     * @throws ErroDaFerramenta
     */
    public function naLixeira(ChamadaDoConector $chamada, int $pastaId, string $naoEstaNaLixeira): Pasta
    {
        $pasta = $this->daLinha($chamada, 'restaurar', $pastaId);
        if (!$pasta->estaExcluida()) {
            throw ErroDaFerramenta::recusada($naoEstaNaLixeira);
        }
        if ($this->processos->idsDasPastasEmSegredoDeJustica([$pastaId], $chamada->contexto->escritorio) !== []) {
            throw ErroDaFerramenta::emSegredo();
        }
        $this->exigirExclusao($chamada, $pasta);

        return $pasta;
    }

    /**
     * O recurso `pasta` com a ação `delete` (`pasta_delete`, `PastaController.php:1792`, e `pasta_restaurar`): quem vê e
     * edita a pasta, mas não exclui, recebe "sem permissão", como a tela que pede o acesso.
     *
     * @throws ErroDaFerramenta
     */
    public function exigirExclusao(ChamadaDoConector $chamada, Pasta $pasta): void
    {
        $contexto = $chamada->contexto;
        if (!$this->permissoes->canAccessResource($contexto->pessoa, $contexto->escritorio, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_DELETE)) {
            throw ErroDaFerramenta::semPermissao();
        }
    }

    /**
     * O módulo `expediente`, que a tela dos marcadores do escritório exige (`ExpedienteController::assertAccess`).
     *
     * @throws ErroDaFerramenta
     */
    public function exigirModuloExpediente(ChamadaDoConector $chamada): void
    {
        if (!$this->permissoes->canAccessModule($chamada->contexto->pessoa, $chamada->contexto->escritorio, 'expediente')) {
            throw ErroDaFerramenta::semPermissao();
        }
    }

    /** @throws ErroDaFerramenta */
    private function daLinha(ChamadaDoConector $chamada, string $acao, int $pastaId): Pasta
    {
        $this->exigirLinha($chamada, $acao);
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $chamada->contexto->escritorio]);
        if (!$pasta instanceof Pasta) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, AtualizarPastaFerramenta::NOME, $acao, $pasta);

        return $pasta;
    }
}
