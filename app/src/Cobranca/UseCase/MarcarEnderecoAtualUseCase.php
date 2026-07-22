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
 * encontrado (evita vazamento de existência entre pessoas).
 *
 * Self-healing (achado da revisão adversarial): a normalização não desmarca só o "antigo atual"
 * apontado pela leitura — percorre TODOS os itens da lista da pessoa e desmarca quem não é o
 * alvo. Isso corrige sozinho qualquer duplicidade pré-existente (janela de concorrência,
 * duplo-submit) sem precisar de uma operação de correção separada. `setAtual(false)` em quem já
 * é `false` e `setAtual(true)` em quem já é `true` geram changeset vazio no Doctrine, então
 * repetir a normalização em cima do item que já é o atual continua idempotente (sem UPDATE
 * desnecessário), mesmo chamando `salvar(..., true)` sempre.
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

        // Normaliza a lista inteira: qualquer item que não seja o alvo vira não-atual, mesmo que
        // já houvesse mais de um `atual = true` por corrupção pré-existente.
        foreach ($pessoa->getEnderecos() as $item) {
            if ($item !== $endereco) {
                $item->setAtual(false);
            }
        }

        $endereco->setAtual(true);

        // Todas as entidades já estão managed; um único flush troca todas as flags atomicamente.
        $this->enderecoRepository->salvar($endereco, true);

        return $endereco;
    }
}
