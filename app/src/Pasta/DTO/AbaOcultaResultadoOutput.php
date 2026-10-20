<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\AbaDaPasta;

/**
 * Resposta das três escritas do "ocultar aba" (spec pasta-ocultar-aba §3): o estado que ficou e a
 * mensagem do aviso do desenho (`gmAviso`, [EXP] L.4415, L.4417 e L.4426).
 */
final readonly class AbaOcultaResultadoOutput
{
    public function __construct(
        public AbaDaPasta $aba,
        public bool $paraTodos,
        public int $quantidade,
        public string $mensagem,
    ) {
    }

    public static function ocultaParaTodos(AbaDaPasta $aba): self
    {
        return new self($aba, true, 0, $aba->rotulo() . ' oculta para toda a equipe');
    }

    /** `$paraTodos` = todos os elegíveis foram marcados; a mensagem do desenho segue contando pessoas. */
    public static function ocultaPara(AbaDaPasta $aba, int $quantidade, bool $paraTodos): self
    {
        return new self($aba, $paraTodos, $quantidade, $aba->rotulo() . ' oculta para ' . $quantidade . ' pessoa(s)');
    }

    public static function visivelParaTodos(AbaDaPasta $aba): self
    {
        return new self($aba, false, 0, $aba->rotulo() . ' visível para todos');
    }

    /** @return array{sucesso: true, aba: string, rotulo: string, paraTodos: bool, quantidade: int, mensagem: string} */
    public function paraJson(): array
    {
        return [
            'sucesso'    => true,
            'aba'        => $this->aba->value,
            'rotulo'     => $this->aba->rotulo(),
            'paraTodos'  => $this->paraTodos,
            'quantidade' => $this->quantidade,
            'mensagem'   => $this->mensagem,
        ];
    }
}
