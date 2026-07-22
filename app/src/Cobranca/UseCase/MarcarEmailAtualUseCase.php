<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\MarcarEmailAtualInput;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Exception\PessoaEmailNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Marca um e-mail já existente como o `atual` da Pessoa (spec de qualificação §4).
 *
 * História: quando a pessoa muda de e-mail, o gestor não edita o antigo — marca o novo (já
 * cadastrado via AdicionarEmailPessoaUseCase) como atual. O anterior PERMANECE na lista, só
 * deixa de ser o atual; a troca de flags ocorre em transação única (um único flush). Pessoa e
 * e-mail são resolvidos por id + tenant (guarda multi-tenant, invariável 24); o e-mail também
 * precisa pertencer à MESMA pessoa informada — item de outra pessoa é tratado como não
 * encontrado (evita vazamento de existência entre pessoas). Marcar o item que já é o atual é
 * idempotente (não gera erro nem toque desnecessário no banco).
 */
final class MarcarEmailAtualUseCase
{
    public function __construct(
        private readonly PessoaEmailRepository $emailRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(MarcarEmailAtualInput $input, Tenant $tenant): PessoaEmail
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Guarda multi-tenant + posse: o e-mail precisa ser do tenant E desta mesma pessoa.
        $email = $this->emailRepository->findOneByIdDoTenant((int) $input->emailId, $tenant);

        if ($email === null || $email->getPessoa()?->getId() !== $pessoa->getId()) {
            throw new PessoaEmailNaoEncontradoException((int) $input->emailId);
        }

        if ($email->isAtual()) {
            return $email;
        }

        // O antigo atual permanece na lista — só deixa de ser o atual (histórico preservado).
        $anterior = $this->emailRepository->buscarAtualDaPessoa($pessoa);
        $anterior?->setAtual(false);

        $email->setAtual(true);

        // Ambas as entidades já estão managed; um único flush troca as duas flags atomicamente.
        $this->emailRepository->salvar($email, true);

        return $email;
    }
}
