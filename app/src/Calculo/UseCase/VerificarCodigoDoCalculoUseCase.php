<?php

declare(strict_types=1);

namespace App\Calculo\UseCase;

use App\Calculo\DTO\ResultadoDaVerificacao;
use App\Calculo\Repository\CalculoEmitidoRepository;

/**
 * Página pública de verificação: confirma que um código `BJC-XXXXXXXX` foi emitido e, se vier o hash SHA-256 do
 * arquivo apresentado, se ele é o PDF original. Sem login, sem tenant: devolve só o que o próprio PDF já mostra.
 */
final class VerificarCodigoDoCalculoUseCase
{
    private const FORMATO = '/^BJC-[A-Z0-9]{8}$/';

    public function __construct(private readonly CalculoEmitidoRepository $emitidos)
    {
    }

    public function executar(string $codigoDigitado, ?string $hashDoArquivo = null): ResultadoDaVerificacao
    {
        $codigo = strtoupper(trim($codigoDigitado));
        if (preg_match(self::FORMATO, $codigo) !== 1) {
            return new ResultadoDaVerificacao(ResultadoDaVerificacao::FORMATO_INVALIDO, mb_substr($codigo, 0, 20));
        }

        $emitido = $this->emitidos->porCodigo($codigo);
        if ($emitido === null) {
            return new ResultadoDaVerificacao(ResultadoDaVerificacao::NAO_ENCONTRADO, $codigo);
        }

        return new ResultadoDaVerificacao(
            ResultadoDaVerificacao::ENCONTRADO,
            $codigo,
            $emitido->getTenant()?->getName(),
            $emitido->getEmitidoEm(),
            $emitido->getAtualizadoAte(),
            $emitido->getTotal(),
            $hashDoArquivo === null ? null : $emitido->confereComOHash($hashDoArquivo),
        );
    }
}
