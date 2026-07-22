<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarQualificacaoPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;

/**
 * Edita a qualificação da Pessoa cobrada (spec de qualificação §3/§7).
 *
 * História: o gestor corrige ou completa o cadastro da pessoa — nome, documentos, data de
 * nascimento, estado civil, profissão, RG — na ficha (`PessoaController::show`). Edita SOMENTE os
 * campos ÚNICOS da Pessoa; `email`/`telefone` NUNCA são tocados aqui — são derivados do item
 * `atual` das respectivas listas (SPEC §6) e geridos por AdicionarEmailPessoa/MarcarEmailAtual
 * (idem telefone). Editar direto sobrescreveria o histórico via o bridge `setEmail()`/
 * `setTelefone()` — por isso este UseCase não os toca. A pessoa é resolvida por id + tenant
 * (guarda multi-tenant, invariável 24); inexistente ou de outro escritório interrompe a operação.
 */
final class EditarQualificacaoPessoaUseCase
{
    public function __construct(
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(EditarQualificacaoPessoaInput $input, Tenant $tenant): Pessoa
    {
        // Guarda multi-tenant: a pessoa precisa existir DENTRO do próprio escritório.
        $pessoa = $this->pessoaRepository->findOneByIdDoTenant((int) $input->pessoaId, $tenant);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException((int) $input->pessoaId);
        }

        $pessoa->setNome(trim((string) $input->nome));
        $pessoa->setCpf($this->normalizar($input->cpf));
        $pessoa->setCnpj($this->normalizar($input->cnpj));
        $pessoa->setObservacao($this->normalizar($input->observacao));
        $pessoa->setDataNascimento($input->dataNascimento);
        $pessoa->setEstadoCivil($input->estadoCivil);
        $pessoa->setProfissao($this->normalizar($input->profissao));
        $pessoa->setRg($this->normalizar($input->rg));
        $pessoa->setOrgaoEmissorRg($this->normalizar($input->orgaoEmissorRg));

        $this->pessoaRepository->salvar($pessoa, true);

        return $pessoa;
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
