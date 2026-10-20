<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que a tela da pasta mostra do cadeado para quem está olhando (desenho 02, `cdVals`):
 *  - para quem configura: o item "Cadeado" do ⋮ com o selo, o selo grande no andamento, o modal com a
 *    grade da equipe e os pedidos pendentes;
 *  - para quem está em `Visualizar`: a faixa de somente visualização e o "Pedir para editar";
 *  - para quem está `Oculto`: a tela "Pasta trancada" e o estado do pedido.
 */
final class CadeadoNaTelaOutput
{
    /**
     * @param list<array{id: int, nome: string, funcao: string, nivel: string}>               $equipe
     * @param list<array{id: int, nome: string, nivel: string, motivo: ?string, hora: string}> $pedidosPendentes
     */
    public function __construct(
        public readonly string $nivel,
        public readonly bool $podeConfigurar,
        public readonly bool $temCadeado,
        public readonly ?string $modo,
        public readonly string $selo,
        public readonly bool $aguardando,
        public readonly string $horaDoPedido,
        public readonly bool $recusado,
        /** Recusado há menos de 24 h: "dd/mm às HH:MM", a partir de quando pode pedir de novo. */
        public readonly string $pedirDeNovoEm = '',
        public readonly array $equipe = [],
        public readonly array $pedidosPendentes = [],
    ) {
    }

    public function oculto(): bool
    {
        return $this->temCadeado && $this->nivel === 'oculto';
    }

    public function soVer(): bool
    {
        return $this->temCadeado && $this->nivel === 'visualizar';
    }

    /** "Pedir acesso ao primário" / "Pedir para editar": só sem pedido aguardando (desenho `!pend`). */
    public function podePedir(): bool
    {
        return ($this->oculto() || $this->soVer()) && !$this->aguardando && $this->pedirDeNovoEm === '';
    }

    /** O texto do recusado (desenho L6094), com a data do antispam enquanto ele vale (desvio registrado na spec). */
    public function recusadoTexto(): string
    {
        return $this->pedirDeNovoEm === ''
            ? 'O usuário primário não liberou o pedido anterior. Você pode pedir de novo.'
            : 'O usuário primário não liberou o pedido anterior. Você pode pedir de novo a partir de ' . $this->pedirDeNovoEm . '.';
    }

    public function menuIcone(): string
    {
        return $this->temCadeado ? 'bi bi-lock-fill' : 'bi bi-unlock';
    }

    /** O estilo inline do selo do ⋮ (desenho L6099, `menuTagSt`). */
    public function menuSeloEstilo(): string
    {
        return 'font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:4px;'
            . ($this->temCadeado ? 'background:#ece8fb;color:#4a3d80' : 'background:#eef2f5;color:#6b8494');
    }

    /** O title do selo grande (desenho L6098). */
    public function seloTitulo(): string
    {
        return $this->modo === 'todos' ? 'Trancada: oculta para toda a equipe' : 'Trancada: acesso restrito por pessoa';
    }
}
