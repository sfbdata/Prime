<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

/**
 * O que o {@see SeloDoPdfFinal::aplicar()} fez com o PDF no temporário, para o {@see SeloDoPdfFinal::registrar()}
 * levar à trilha dentro da transação de quem guarda: selou, ficou sem o selo por uma falha, ou nada (não configurado).
 */
final readonly class ResultadoDoSelo
{
    private function __construct(
        public bool $selou,
        public ?FalhaNoSelo $falha,
    ) {
    }

    /** O PDF no temporário foi trocado pelo selado: `pdf_selado` na trilha. */
    public static function selado(): self
    {
        return new self(true, null);
    }

    /** Guardado sem o selo por esta falha: `selo_nao_aplicado` com o código e `logger->error`. */
    public static function semSelo(FalhaNoSelo $falha): self
    {
        return new self(false, $falha);
    }

    /** Selo não configurado: o PDF como veio, nada na trilha nem no log. */
    public static function naoConfigurado(): self
    {
        return new self(false, null);
    }
}
