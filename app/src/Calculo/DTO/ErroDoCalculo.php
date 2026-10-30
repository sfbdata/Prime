<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/** Problema que impede o cálculo: o texto, o campo da tela a destacar e o rótulo do botão "Ir para...". */
final readonly class ErroDoCalculo
{
    public function __construct(
        public string $mensagem,
        public string $campo = '',
        public string $irTxt = '',
    ) {
    }

    /** @return array{campo: string, msg: string, irTxt: string} */
    public function paraTela(): array
    {
        return ['campo' => $this->campo, 'msg' => $this->mensagem, 'irTxt' => $this->irTxt];
    }
}
