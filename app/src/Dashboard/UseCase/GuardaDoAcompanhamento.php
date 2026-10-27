<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\Exception\AcompanhamentoInvalidoException;
use App\Dashboard\Exception\AcompanhamentoNaoPermitidoException;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * As guardas comuns de criar, renovar e encerrar o acompanhamento de uma pessoa, nesta ordem:
 * quem age é o Master ou um primário ativo (senão {@see AcompanhamentoNaoPermitidoException}, 403); a pessoa
 * é colaboradora ativa do escritório da sessão (senão {@see ColaboradorForaDoEscritorioException}, 404,
 * IDOR); e não é o próprio gestor nem o Master ({@see AcompanhamentoInvalidoException}, 400: a conta do
 * Master só ele mexe e ninguém se coloca em acompanhamento).
 */
final class GuardaDoAcompanhamento
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    /**
     * @throws AcompanhamentoNaoPermitidoException
     * @throws ColaboradorForaDoEscritorioException
     * @throws AcompanhamentoInvalidoException
     */
    public function exigirAlvo(Tenant $tenant, User $ator, int $pessoaId): User
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($ator, $tenant)) {
            throw new AcompanhamentoNaoPermitidoException('Seu perfil não pode registrar acompanhamentos.');
        }

        $pessoa = $this->userRepository->findColaboradorAtivoPorIdETenant($pessoaId, $tenant);
        if ($pessoa === null) {
            throw new ColaboradorForaDoEscritorioException();
        }
        if ($pessoa->getId() === $ator->getId() || $this->regraDosPrimarios->ehMaster($pessoa)) {
            throw new AcompanhamentoInvalidoException('Esta pessoa não pode ser colocada em acompanhamento.');
        }

        return $pessoa;
    }
}
