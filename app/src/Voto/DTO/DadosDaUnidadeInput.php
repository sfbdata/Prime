<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Campos da unidade que o gestor digita para adicionar ou editar (os mesmos da planilha, menos o telefone, que só entra
 * pela importação). Texto como veio do formulário: a validação é a da importação (LeitorDaListaDeUnidades).
 */
final readonly class DadosDaUnidadeInput
{
    public function __construct(
        public string $identificacao = '',
        public string $bloco = '',
        public string $situacao = '',
        public string $fracao = '',
        public string $responsavel = '',
        public string $email = '',
    ) {
    }
}
