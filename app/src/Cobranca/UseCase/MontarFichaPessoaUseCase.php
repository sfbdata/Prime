<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\PessoaEmailItemOutput;
use App\Cobranca\DTO\PessoaEnderecoItemOutput;
use App\Cobranca\DTO\PessoaFichaOutput;
use App\Cobranca\DTO\PessoaTelefoneItemOutput;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Repository\PessoaEmailRepository;
use App\Cobranca\Repository\PessoaEnderecoRepository;
use App\Cobranca\Repository\PessoaTelefoneRepository;

/**
 * Leitura: monta a ficha completa da Pessoa (spec de qualificação §7) para `PessoaController::show`
 * — os campos únicos de qualificação mais as três listas (endereços/telefones/e-mails), cada uma na
 * ordem de linha do tempo (`criadoEm ASC`) devolvida por `listarPorPessoa`. Nada recalcula regra de
 * negócio — só lê e formata via Output DTOs (o controller nunca recebe entidade Doctrine).
 */
final class MontarFichaPessoaUseCase
{
    public function __construct(
        private readonly PessoaEnderecoRepository $enderecoRepository,
        private readonly PessoaTelefoneRepository $telefoneRepository,
        private readonly PessoaEmailRepository $emailRepository,
    ) {
    }

    public function executar(Pessoa $pessoa): PessoaFichaOutput
    {
        $enderecos = array_map(
            static fn (PessoaEndereco $e): PessoaEnderecoItemOutput => PessoaEnderecoItemOutput::fromEntity($e),
            $this->enderecoRepository->listarPorPessoa($pessoa),
        );

        $telefones = array_map(
            static fn (PessoaTelefone $t): PessoaTelefoneItemOutput => PessoaTelefoneItemOutput::fromEntity($t),
            $this->telefoneRepository->listarPorPessoa($pessoa),
        );

        $emails = array_map(
            static fn (PessoaEmail $e): PessoaEmailItemOutput => PessoaEmailItemOutput::fromEntity($e),
            $this->emailRepository->listarPorPessoa($pessoa),
        );

        return PessoaFichaOutput::fromEntity($pessoa, $enderecos, $telefones, $emails);
    }
}
