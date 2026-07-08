<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Cadastra uma Pessoa no domínio de cobranças de um escritório (SPEC §4/§7).
 *
 * História: um gestor cadastra alguém que participa de cobranças (proprietário, inquilino,
 * devedor, fiador, outro). CPF e CNPJ são opcionais — a ausência não impede o cadastro
 * (invariável 24). Os campos textuais são normalizados (trim; null quando vazio) para não
 * gravar strings em branco. A deduplicação NÃO é feita aqui (SugerirPessoasDuplicadas é
 * outro UseCase, fora deste piloto).
 */
final class CriarPessoaUseCase
{
    public function __construct(
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    public function executar(CriarPessoaInput $input, Tenant $tenant, User $criadoPor): Pessoa
    {
        $pessoa = new Pessoa();
        $pessoa->setTenant($tenant);
        $pessoa->setNome(trim((string) $input->nome));
        $pessoa->setCpf($this->normalizar($input->cpf));
        $pessoa->setCnpj($this->normalizar($input->cnpj));
        $pessoa->setEmail($this->normalizar($input->email));
        $pessoa->setTelefone($this->normalizar($input->telefone));
        $pessoa->setObservacao($this->normalizar($input->observacao));
        $pessoa->setCriadoPor($criadoPor);

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
