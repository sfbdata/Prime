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
        public readonly int $descadastrados = 0,
    ) {
        $this->mensagem = $pedidos . ' pedido(s) de confirmação enviado(s)'
            . ($jaExistiam > 0 ? ' · ' . $jaExistiam . ' já existiam na base' : '')
            . ($barrados > 0 ? ' · ' . $barrados . ' barrado(s) (endereço inválido ou pedido recente)' : '')
            . ($descadastrados > 0 ? ' · ' . $descadastrados . ' descadastrado(s), sem novo pedido' : '')
            . '. O contato entra na base quando confirmar pelo e-mail.';
    }

    /** @return array{pedidos: int, jaExistiam: int, barrados: int, descadastrados: int, mensagem: string} */
    public function paraArray(): array
    {
        return ['pedidos' => $this->pedidos, 'jaExistiam' => $this->jaExistiam, 'barrados' => $this->barrados, 'descadastrados' => $this->descadastrados, 'mensagem' => $this->mensagem];
    }
}
