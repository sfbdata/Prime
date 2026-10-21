<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * A resposta do "Cadastrar e-mail" (F3h): quantos pedidos de confirmação saíram, quantos endereços já estavam
 * confirmados na base e quantos foram barrados (inválidos ou segurados pelos limites da confirmação), com a frase da
 * tela no tom do dc (L644, "N contato(s) cadastrado(s) na base · X já existiam"), sem travessão.
 */
final class ContatosCadastradosOutput
{
    public readonly string $mensagem;

    public function __construct(
        public readonly int $pedidos,
        public readonly int $jaExistiam,
        public readonly int $barrados,
    ) {
        $this->mensagem = $pedidos . ' pedido(s) de confirmação enviado(s)'
            . ($jaExistiam > 0 ? ' · ' . $jaExistiam . ' já existiam na base' : '')
            . ($barrados > 0 ? ' · ' . $barrados . ' barrado(s) (endereço inválido, descadastrado ou pedido recente)' : '')
            . '. O contato entra na base quando confirmar pelo e-mail.';
    }

    /** @return array{pedidos: int, jaExistiam: int, barrados: int, mensagem: string} */
    public function paraArray(): array
    {
        return ['pedidos' => $this->pedidos, 'jaExistiam' => $this->jaExistiam, 'barrados' => $this->barrados, 'mensagem' => $this->mensagem];
    }
}
