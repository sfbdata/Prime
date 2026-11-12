<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;

/**
 * Confere as opções numeradas de uma pauta (validação do desenho, etapa 4: "Inclua candidaturas em {pauta}.",
 * "Número {n} deve ter {dig} dígito(s).", "Número repetido em {pauta}.", "Candidatura sem nome em {pauta}.").
 *
 * O número tem exatamente os dígitos da pauta (1 a 5; padrão 1, de 1 a 9), com zeros à esquerda se precisar ("01"). O 0
 * é da abstenção, que o sistema cria sozinho.
 */
final class OpcoesDaPauta
{
    /**
     * @param list<array{numero: string, rotulo: string}> $linhas como a tela enviou
     *
     * @return list<array{numero: int, rotulo: string}> pelo número
     */
    public function validar(string $pauta, array $linhas, int $digitos = 1): array
    {
        $opcoes = [];
        foreach ($linhas as $linha) {
            $numero = trim($linha['numero']);
            $rotulo = trim($linha['rotulo']);
            if ('' === $numero && '' === $rotulo) {
                continue;
            }
            $valor = self::numero($numero, $digitos);
            self::exigir('' !== $rotulo, sprintf('Candidatura sem nome em %s.', $pauta));
            self::exigir(!isset($opcoes[$valor]), sprintf('Número repetido em %s.', $pauta));
            $opcoes[$valor] = ['numero' => $valor, 'rotulo' => mb_substr($rotulo, 0, 120)];
        }
        self::exigir([] !== $opcoes, sprintf('Inclua candidaturas em %s.', $pauta));
        ksort($opcoes);

        return array_values($opcoes);
    }

    private static function numero(string $numero, int $digitos): int
    {
        $soAlgarismos = 1 === preg_match('/^\d+$/', $numero);
        self::exigir(!$soAlgarismos || (int) $numero > 0, 'O número 0 é da abstenção.');
        $mostrado = '' === $numero ? '' : ' ' . mb_substr($numero, 0, 12);
        self::exigir(1 === preg_match('/^\d{' . $digitos . '}$/', $numero), sprintf('Número%s deve ter %d dígito(s).', $mostrado, $digitos));

        return (int) $numero;
    }

    private static function exigir(bool $condicao, string $mensagem): void
    {
        if (!$condicao) {
            throw new OperacaoInvalidaNaAssembleiaException($mensagem);
        }
    }
}
