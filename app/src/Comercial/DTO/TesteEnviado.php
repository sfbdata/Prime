<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O resultado de um "Enviar teste": quem recebeu e quem o Carteiro bloqueou por descadastro da lista.
 */
final class TesteEnviado
{
    /**
     * @param list<string> $enviados
     * @param list<string> $bloqueados
     */
    public function __construct(public readonly array $enviados, public readonly array $bloqueados)
    {
    }

    public function mensagem(): string
    {
        $texto = 'Teste enviado para ' . implode(', ', $this->enviados) . '.';
        if ($this->bloqueados !== []) {
            $texto .= ' ' . $this->aviso();
        }

        return $texto;
    }

    /** "Não enviado para c (descadastrado da lista)."; vazio se ninguém foi bloqueado. */
    public function aviso(): string
    {
        return $this->bloqueados === [] ? '' : 'Não enviado para ' . implode(', ', $this->bloqueados) . ' (descadastrado da lista).';
    }
}
