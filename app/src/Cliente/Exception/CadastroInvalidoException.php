<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * Cadastro recusado ao salvar (nome vazio, CPF/CNPJ inválido…). A mensagem é para o usuário; `aba` diz para onde
 * a janela volta (desenho 1.2.3: "Informe o nome completo." volta para a aba Cliente).
 */
final class CadastroInvalidoException extends \DomainException
{
    public function __construct(string $mensagem, public readonly string $aba = 'pessoais')
    {
        parent::__construct($mensagem);
    }
}
