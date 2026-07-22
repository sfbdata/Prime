<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\MarcarEnderecoAtualInput;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Exception\PessoaEnderecoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Marca um endereço já existente como o `atual` da Pessoa (spec de qualificação §4).
 *
 * História: quando a pessoa muda de endereço, o gestor não edita o antigo — marca o novo (já
 * cadastrado via AdicionarEnderecoPessoaUseCase) como atual. O anterior PERMANECE na lista, só
 * deixa de ser o atual; a troca de flags ocorre em transação única (um único flush). Pessoa e
 * endereço são resolvidos por id + tenant (guarda multi-tenant, invariável 24); o endereço
 * também precisa pertencer à MESMA pessoa informada — item de outra pessoa é tratado como não
 * encontrado (evita vazamento de existência entre pessoas). Marcar o item que já é o atual é
 * idempotente (não gera erro nem toque desnecessário no banco).
 */
final class MarcarEnderecoAtualUseCase
{
    public function __construct(
        private readonly PessoaEnderecoRepository $enderecoRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(MarcarEnderecoAtualInput $input, Tenant $tenant): PessoaEndereco
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Guarda multi-tenant + posse: o endereço precisa ser do tenant E desta mesma pessoa.
        $endereco = $this->enderecoRepository->findOneByIdDoTenant((int) $input->enderecoId, $tenant);

        if ($endereco === null || $endereco->getPessoa()?->getId() !== $pessoa->getId()) {
            throw new PessoaEnderecoNaoEncontradoException((int) $input->enderecoId);
        }

        if ($endereco->isAtual()) {
            return $endereco;
        }

        // O antigo atual permanece na lista — só deixa de ser o atual (histórico preservado).
        $anterior = $this->enderecoRepository->buscarAtualDaPessoa($pessoa);
        $anterior?->setAtual(false);

        $endereco->setAtual(true);

        // Ambas as entidades já estão managed; um único flush troca as duas flags atomicamente.
        $this->enderecoRepository->salvar($endereco, true);

        return $endereco;
    }
}
