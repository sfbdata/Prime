<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\AdicionarEnderecoPessoaInput;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Adiciona um endereço à lista de endereços de uma Pessoa cobrada (spec de qualificação §4).
 *
 * História: o gestor descobre (ou atualiza) o endereço de uma pessoa e clica "adicionar" —
 * nada do que já existia é apagado, o endereço entra na linha do tempo. O PRIMEIRO endereço de
 * uma pessoa nasce automaticamente `atual = true`; a partir do segundo, o novo item entra como
 * histórico (`atual = false`) — marcar um item existente como atual é uma ação separada
 * (MarcarEnderecoAtualUseCase). A pessoa é resolvida por id + tenant (guarda multi-tenant,
 * invariável 24); inexistente ou de outro escritório interrompe a operação.
 */
final class AdicionarEnderecoPessoaUseCase
{
    public function __construct(
        private readonly PessoaEnderecoRepository $enderecoRepository,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(AdicionarEnderecoPessoaInput $input, Tenant $tenant, User $criadoPor): PessoaEndereco
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        // Só o primeiro endereço da lista nasce atual automaticamente.
        $primeiroDaLista = !$this->enderecoRepository->existePeloMenosUmDaPessoa($pessoa);

        $endereco = new PessoaEndereco();
        $endereco->setTenant($tenant);
        $endereco->setPessoa($pessoa);
        $endereco->setLogradouro(trim((string) $input->logradouro));
        $endereco->setNumero(trim((string) $input->numero));
        $endereco->setComplemento($this->normalizar($input->complemento));
        $endereco->setBairro(trim((string) $input->bairro));
        $endereco->setCidade(trim((string) $input->cidade));
        $endereco->setUf(strtoupper(trim((string) $input->uf)));
        $endereco->setCep(trim((string) $input->cep));
        $endereco->setAtual($primeiroDaLista);
        $endereco->setCriadoPor($criadoPor);

        $pessoa->adicionarEndereco($endereco);

        $this->enderecoRepository->salvar($endereco, true);

        return $endereco;
    }

    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }
}
