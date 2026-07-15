<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * "Nova pessoa" dentro do objeto (ajuste 2, aba Pessoas): cadastra a pessoa e já a vincula ao objeto.
 *
 * História: trabalhando a cobrança de um objeto, o gestor adiciona um novo envolvido (devedor, avalista,
 * fiador...) informando o nome e o tipo de vínculo. Numa passada, o sistema cadastra a Pessoa (só o nome
 * basta) e cria o vínculo pessoa↔objeto — que a faz aparecer na aba Pessoas. O objeto é resolvido por
 * id + tenant DENTRO do VincularPessoaAObjetoUseCase (guarda multi-tenant); objeto inexistente/de outro
 * escritório interrompe com exceção de domínio. NÃO define a pessoa cobrada — isso é a ação separada de
 * "trocar cobrada".
 */
final class CriarPessoaVinculadaAoObjetoUseCase
{
    public function __construct(
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincularPessoa,
    ) {
    }

    public function executar(CriarPessoaVinculadaInput $input, int $objetoId, Tenant $tenant, User $criadoPor): VinculoPessoaObjeto
    {
        $pessoaInput = new CriarPessoaInput();
        $pessoaInput->nome = $input->nome;
        $pessoaInput->cpf = $input->cpf;
        $pessoaInput->cnpj = $input->cnpj;
        $pessoaInput->email = $input->email;
        $pessoaInput->telefone = $input->telefone;
        $pessoa = $this->criarPessoa->executar($pessoaInput, $tenant, $criadoPor);

        $vinculoInput = new VincularPessoaAObjetoInput();
        $vinculoInput->objetoId = $objetoId;
        $vinculoInput->pessoaId = $pessoa->getId();
        $vinculoInput->tipoVinculo = $input->tipoVinculo;

        return $this->vincularPessoa->executar($vinculoInput, $tenant, $criadoPor);
    }
}
