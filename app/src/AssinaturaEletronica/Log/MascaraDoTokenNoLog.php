<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Log;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * O token do link do signatário fora do log (contrato da etapa 2, seção 3: o token nunca é logado).
 *
 * Em produção o handler `fingers_crossed` despeja, num erro, os registros guardados da requisição; entre eles o
 * "Matched route" do RouterListener, que traz `request_uri` (a URL com o token) e `route_parameters` (o token cru).
 * Este processador roda em todo canal, antes dos handlers, e troca na mensagem, no contexto e no extra (em qualquer
 * profundidade):
 *
 * - todo `/assinar/<64 hexadecimais>` por `/assinar/****`;
 * - dentro de `route_parameters`, o valor da chave `token` por `****` (de qualquer rota) e qualquer sequência de 64
 *   hexadecimais por `****` (a rota de reserva recebe o token no parâmetro `resto`).
 *
 * O objeto de uma exceção posta no contexto não é reescrito (a mensagem dela é dela); a mensagem do registro, sim.
 */
#[AsMonologProcessor]
final class MascaraDoTokenNoLog implements ProcessorInterface
{
    public const MASCARA = '****';
    private const LINK = '#/assinar/[a-f0-9]{64}#i';
    private const SEQUENCIA_DO_TOKEN = '#[a-f0-9]{64}#i';
    private const PARAMETROS_DA_ROTA = 'route_parameters';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::semLink($record->message),
            context: self::limpar($record->context, false),
            extra: self::limpar($record->extra, false),
        );
    }

    /**
     * @param array<mixed> $dados
     *
     * @return array<mixed>
     */
    private static function limpar(array $dados, bool $daRota): array
    {
        foreach ($dados as $chave => $valor) {
            if ($daRota && $chave === 'token') {
                $dados[$chave] = self::MASCARA;
            } elseif (\is_array($valor)) {
                $dados[$chave] = self::limpar($valor, $daRota || $chave === self::PARAMETROS_DA_ROTA);
            } elseif (\is_string($valor)) {
                $dados[$chave] = $daRota ? self::semSequenciaDoToken($valor) : self::semLink($valor);
            }
        }

        return $dados;
    }

    private static function semLink(string $texto): string
    {
        return str_contains(strtolower($texto), '/assinar/')
            ? (string) preg_replace(self::LINK, '/assinar/' . self::MASCARA, $texto)
            : $texto;
    }

    private static function semSequenciaDoToken(string $texto): string
    {
        return (string) preg_replace(self::SEQUENCIA_DO_TOKEN, self::MASCARA, self::semLink($texto));
    }
}
