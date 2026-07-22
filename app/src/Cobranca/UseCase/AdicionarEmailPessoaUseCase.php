<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AdicionarEmailPessoaInput;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Adiciona um e-mail à lista de e-mails de uma Pessoa cobrada (spec de qualificação §4).
 *
 * História: o gestor descobre (ou atualiza) o e-mail de uma pessoa e clica "adicionar" — nada
 * do que já existia é apagado, o e-mail entra na linha do tempo. O PRIMEIRO e-mail de uma
 * pessoa nasce automaticamente `atual = true`; a partir do segundo, o novo item entra como
 * histórico (`atual = false`) — marcar um item existente como atual é uma ação separada
 * (MarcarEmailAtualUseCase). A pessoa é resolvida por id + tenant (guarda multi-tenant,
 * invariável 24); inexistente ou de outro escritório interrompe a operação.
 *
 * SPEC §5.4: quando o novo item nasce atual (é o primeiro da lista), a coluna-sombra da Pessoa é
 * sincronizada com ele no mesmo flush — mantém Pessoa::getEmail() escalar (sem N+1) e coerente.
 */
final class AdicionarEmailPessoaUseCase
{
    public function __construct(
        private readonly PessoaEmailRepository $emailRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(AdicionarEmailPessoaInput $input, Tenant $tenant, User $criadoPor): PessoaEmail
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Só o primeiro e-mail da lista nasce atual automaticamente.
        $primeiroDaLista = !$this->emailRepository->existePeloMenosUmDaPessoa($pessoa);

        $email = new PessoaEmail();
        $email->setTenant($tenant);
        $email->setPessoa($pessoa);
        $email->setEmail(trim((string) $input->email));
        $email->setAtual($primeiroDaLista);
        $email->setCriadoPor($criadoPor);

        $pessoa->adicionarEmail($email);

        if ($primeiroDaLista) {
            $pessoa->sincronizarEmailSombra($email->getEmail());
        }

        $this->emailRepository->salvar($email, true);

        return $email;
    }
}
