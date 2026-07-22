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
 * encontrado (evita vazamento de existência entre pessoas).
 *
 * Self-healing (achado da revisão adversarial): a normalização não desmarca só o "antigo atual"
 * apontado pela leitura — percorre TODOS os itens da lista da pessoa e desmarca quem não é o
 * alvo. Isso corrige sozinho qualquer duplicidade pré-existente (janela de concorrência,
 * duplo-submit) sem precisar de uma operação de correção separada. `setAtual(false)` em quem já
 * é `false` e `setAtual(true)` em quem já é `true` geram changeset vazio no Doctrine, então
 * repetir a normalização em cima do item que já é o atual continua idempotente (sem UPDATE
 * desnecessário), mesmo chamando `salvar(..., true)` sempre.
 *
 * SPEC §5.4: ao trocar o atual, a coluna-sombra da Pessoa é sincronizada com o telefone do NOVO
 * atual no mesmo flush — mantém Pessoa::getTelefone() escalar (sem N+1) e coerente com a troca.
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

        // Normaliza a lista inteira: qualquer item que não seja o alvo vira não-atual, mesmo que
        // já houvesse mais de um `atual = true` por corrupção pré-existente.
        foreach ($pessoa->getTelefones() as $item) {
            if ($item !== $telefone) {
                $item->setAtual(false);
            }
        }

        $telefone->setAtual(true);
        $pessoa->sincronizarTelefoneSombra($telefone->getNumero());

        // Todas as entidades já estão managed; um único flush troca todas as flags atomicamente.
        $this->telefoneRepository->salvar($telefone, true);

        return $telefone;
    }
}
