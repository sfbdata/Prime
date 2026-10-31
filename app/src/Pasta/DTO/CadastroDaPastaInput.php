<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** O que vem do formulário "Cadastro da pasta" da Controladoria (texto cru; o caso de uso limpa e valida). */
final readonly class CadastroDaPastaInput
{
    public function __construct(
        public string $reu = '',
        public string $cpf = '',
        public string $qualificacao = '',
        public string $email = '',
        public string $endereco = '',
        public string $contrato = '',
        public string $unidade = '',
        public string $titularExtrato = '',
        public string $prescricao = '',
    ) {
    }
}
