<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AdicionarTelefonePessoaInput;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Adiciona um telefone à lista de telefones de uma Pessoa cobrada (spec de qualificação §4).
 *
 * História: o gestor descobre (ou atualiza) o telefone de uma pessoa e clica "adicionar" — nada
 * do que já existia é apagado, o telefone entra na linha do tempo. O PRIMEIRO telefone de uma
 * pessoa nasce automaticamente `atual = true`; a partir do segundo, o novo item entra como
 * histórico (`atual = false`) — marcar um item existente como atual é uma ação separada
 * (MarcarTelefoneAtualUseCase). A pessoa é resolvida por id + tenant (guarda multi-tenant,
 * invariável 24); inexistente ou de outro escritório interrompe a operação.
 *
 * SPEC §5.4: quando o novo item nasce atual (é o primeiro da lista), a coluna-sombra da Pessoa é
 * sincronizada com ele no mesmo flush — mantém Pessoa::getTelefone() escalar (sem N+1) e coerente.
 */
final class AdicionarTelefonePessoaUseCase
{
    public function __construct(
        private readonly PessoaTelefoneRepository $telefoneRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(AdicionarTelefonePessoaInput $input, Tenant $tenant, User $criadoPor): PessoaTelefone
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Só o primeiro telefone da lista nasce atual automaticamente.
        $primeiroDaLista = !$this->telefoneRepository->existePeloMenosUmDaPessoa($pessoa);

        $telefone = new PessoaTelefone();
        $telefone->setTenant($tenant);
        $telefone->setPessoa($pessoa);
        $telefone->setNumero(trim((string) $input->numero));
        // WhatsApp ou fixo (2026-07-28). Nulo é aceito e significa "não declarado" — ver `TipoTelefone`.
        $telefone->setTipo($input->tipo);
        $telefone->setAtual($primeiroDaLista);
        $telefone->setCriadoPor($criadoPor);

        $pessoa->adicionarTelefone($telefone);

        if ($primeiroDaLista) {
            $pessoa->sincronizarTelefoneSombra($telefone->getNumero());
        }

        $this->telefoneRepository->salvar($telefone, true);

        return $telefone;
    }
}
