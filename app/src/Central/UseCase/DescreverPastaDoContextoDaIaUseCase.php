<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\PastaDoContextoDaIaOutput;
use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;

/**
 * O rótulo da barra "Pasta N" da conversa com o BlueJus IA (PR 4b, `docs/specs/ia-chat-central.md`). A tela só sabe o
 * id da pasta aberta (dica de UI); aqui o servidor confere tudo antes de dar nome a ela: vínculo ativo, pasta por id +
 * escritório (explícito), não excluída e {@see AcessoAPasta::podeVer}. Qualquer falha de pasta é o MESMO
 * {@see PastaDaIaNaoEncontradaException} (404), sem confirmar que ela existe.
 *
 * Só leitura e só o rótulo: NUP e nome do cliente principal. Segredo de justiça NÃO é recusado aqui (quem recusa é a
 * pergunta, com 409): a barra serve para a pessoa ver QUAL pasta está em jogo.
 *
 * @throws SemAcessoACentralException
 * @throws PastaDaIaNaoEncontradaException
 */
final class DescreverPastaDoContextoDaIaUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $pastaId): PastaDoContextoDaIaOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $pasta = $pastaId > 0 ? $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]) : null;
        if ($pasta === null || $pasta->estaExcluida() || !$this->acessoAPasta->podeVer($eu, $pasta)) {
            throw new PastaDaIaNaoEncontradaException();
        }

        $cliente = trim((string) $pasta->getClientePrincipal()?->getNomeExibicao());

        return new PastaDoContextoDaIaOutput((int) $pasta->getId(), (string) $pasta->getNup(), $cliente !== '' ? $cliente : null);
    }
}
