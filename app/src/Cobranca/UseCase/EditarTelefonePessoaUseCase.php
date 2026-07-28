<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarTelefonePessoaInput;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\PessoaTelefoneNaoEncontradoException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Entity\Tenant\Tenant;

/**
 * Corrige o NÚMERO de um telefone já cadastrado da Pessoa.
 *
 * História: o gestor digitou errado (dígito trocado, DDD faltando) ou o número foi importado torto.
 * Ele corrige a linha no lugar — a posição na linha do tempo e a flag `atual` não mudam, porque não
 * houve troca de telefone: houve conserto de um registro errado. Quem de fato MUDOU de número
 * continua ganhando linha nova (`AdicionarTelefonePessoaUseCase`) + "marcar como atual", que é o que
 * preserva o histórico.
 *
 * Pessoa e telefone são resolvidos por id + tenant (guarda multi-tenant, invariável 24), e o
 * telefone precisa pertencer à MESMA pessoa informada — item de outra pessoa é tratado como não
 * encontrado (evita vazamento de existência entre pessoas). Mesma guarda do MarcarTelefoneAtual.
 *
 * SPEC §5.4: se o item corrigido for o `atual`, a coluna-sombra da Pessoa vai junto no mesmo flush —
 * senão `Pessoa::getTelefone()` (usada nas listagens, sem N+1) continuaria devolvendo o número
 * errado, e a correção seria invisível justamente onde mais se lê o telefone.
 */
final class EditarTelefonePessoaUseCase
{
    public function __construct(
        private readonly PessoaTelefoneRepository $telefoneRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(EditarTelefonePessoaInput $input, Tenant $tenant): PessoaTelefone
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

        $telefone->setNumero(trim((string) $input->numero));

        // Tipo NULO no input significa "não mexer", e não "apagar o tipo" (2026-07-28). O telefone
        // legado não tem tipo e o formulário dele nasce com os dois rádios em branco: quem só corrige
        // um dígito não pode acabar declarando "isto é fixo" sem ter dito nada. E requisição antiga,
        // sem o campo, deixa de apagar em silêncio um tipo que alguém já tinha marcado.
        if ($input->tipo !== null) {
            $telefone->setTipo($input->tipo);
        }

        if ($telefone->isAtual()) {
            $pessoa->sincronizarTelefoneSombra($telefone->getNumero());
        }

        $this->telefoneRepository->salvar($telefone, true);

        return $telefone;
    }
}
