<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Recusa do Ponto com chave no catálogo (spec idioma-do-sistema §30, idioma-ponto-php PR B), no padrão da
 * `RecusaDaSeccional` do Master: a constante RECUSA_* de cada exceção é o português (a fonte, e o que os testes
 * comparam), com %d ou %s onde entra um valor; a mensagem da exceção continua sendo esse português, já preenchido.
 * Quem leva a recusa à tela (o controller) traduz pela chave(), com os parametros(). O
 * RecusasDoPontoNoCatalogoTest garante que o português do catálogo é igual ao da constante.
 */
abstract class RecusaDoPonto extends \DomainException
{
    /** @var array<string, string> recusa (o texto da constante RECUSA_*) => chave no catálogo; cada exceção tem a sua */
    public const CHAVE_DA_RECUSA = [];

    private readonly string $chave;

    /** @var array<string, string> */
    private readonly array $parametros;

    /**
     * @param string                $recusa     uma das constantes RECUSA_* da exceção
     * @param array<string, scalar> $parametros o valor, pelo nome do parâmetro no catálogo ({limite}); vai como texto,
     *                                          sem separador de milhar, como o %d do português
     */
    public function __construct(string $recusa, array $parametros = [])
    {
        $chave = static::CHAVE_DA_RECUSA[$recusa] ?? null;
        if ($chave === null) {
            throw new \LogicException(sprintf('Recusa sem chave no catálogo: "%s".', $recusa));
        }

        parent::__construct(vsprintf($recusa, array_values($parametros)));
        $this->chave = $chave;
        $this->parametros = array_map('strval', $parametros);
    }

    public function chave(): string
    {
        return $this->chave;
    }

    /** @return array<string, string> */
    public function parametros(): array
    {
        return $this->parametros;
    }
}
