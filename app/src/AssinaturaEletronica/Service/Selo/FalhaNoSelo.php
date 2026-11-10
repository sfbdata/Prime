<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Selo;

/**
 * O selo não saiu (spec assinatura-documentos-etapa-8, E8-4). Leva o código (o `erro` do validador, ou um dos códigos
 * locais do {@see HttpSeladorDePdf}), a classe da tabela 2.1 da etapa 6 e a situação HTTP, quando houve resposta.
 *
 * A mensagem é para o log e para a fila do worker: só código, classe e situação. Nunca o PDF, o token nem a exceção do
 * cliente HTTP (ela não é encadeada: a mensagem dela pode trazer o cabeçalho, e o Messenger serializa o `previous`).
 */
final class FalhaNoSelo extends \RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        public readonly ClasseDaFalhaNoSelo $classe,
        public readonly ?int $situacao = null,
    ) {
        parent::__construct(sprintf(
            'Selo do PDF final não aplicado: %s (%s%s).',
            $codigo,
            $classe->value,
            $situacao === null ? '' : ', HTTP ' . $situacao,
        ));
    }

    /** Falha que pode passar sozinha: a mensagem tenta de novo; esgotadas as tentativas, guarda sem o selo. */
    public function tentaDeNovo(): bool
    {
        return $this->classe !== ClasseDaFalhaNoSelo::Definitiva;
    }
}
