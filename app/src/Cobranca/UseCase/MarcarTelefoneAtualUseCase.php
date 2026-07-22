<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\MarcarTelefoneAtualInput;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Entity\Tenant\Tenant;

/**
 * Marca um telefone já existente como o `atual` da Pessoa (spec de qualificação §4).
 *
 * História: quando a pessoa muda de telefone, o gestor não edita o antigo — marca o novo (já
 * cadastrado via AdicionarTelefonePessoaUseCase) como atual. O anterior PERMANECE na lista, só
 * deixa de ser o atual; a troca de flags ocorre em transação única (um único flush). Pessoa e
 * telefone são resolvidos por id + tenant (guarda multi-tenant, invariável 24); o telefone
 * também precisa pertencer à MESMA pessoa informada — item de outra pessoa é tratado como não
 * encontrado (evita vazamento de existência entre pessoas). Marcar o item que já é o atual é
 * idempotente (não gera erro nem toque desnecessário no banco).
 */
final class MarcarTelefoneAtualUseCase
{
    public function __construct(
        private readonly PessoaTelefoneRepository $telefoneRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(MarcarTelefoneAtualInput $input, Tenant $tenant): PessoaTelefone
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Guarda multi-tenant + posse: o telefone precisa ser do tenant E desta mesma pessoa.
        $telefone = $this->telefoneRepository->findOneByIdDoTenant((int) $input->telefoneId, $tenant);

        if ($telefone === null || $telefone->getPessoa()?->getId() !== $pessoa->getId()) {
            throw new PessoaTelefoneNaoEncontradoException((int) $input->telefoneId);
        }

        if ($telefone->isAtual()) {
            return $telefone;
        }

        // O antigo atual permanece na lista — só deixa de ser o atual (histórico preservado).
        $anterior = $this->telefoneRepository->buscarAtualDaPessoa($pessoa);
        $anterior?->setAtual(false);

        $telefone->setAtual(true);

        // Ambas as entidades já estão managed; um único flush troca as duas flags atomicamente.
        $this->telefoneRepository->salvar($telefone, true);

        return $telefone;
    }
}
