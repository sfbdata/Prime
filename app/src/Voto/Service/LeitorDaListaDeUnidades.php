<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\LinhaDeUnidade;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;

use function Symfony\Component\String\u;

/**
 * Lê a lista de unidades colada pelo gestor (spec voto-assembleia-condominio, seção 1.4): uma por linha,
 * `unidade; bloco; situação; fração; responsável; e-mail` (ponto e vírgula, ou tabulação quando vem colada de uma
 * planilha). Uma primeira linha de cabeçalho (unidade, identificacao...) é pulada.
 *
 * Regras do desenho (importação, N97): linha sem unidade ou com fração menor ou igual a zero é inválida; a mesma
 * unidade no mesmo bloco repetida é duplicada; fração com vírgula ou ponto decimal. Na assembleia por fração ideal
 * a fração é obrigatória. E-mail, se houver, precisa ser válido (é para onde vai o link).
 */
final class LeitorDaListaDeUnidades
{
    public const LIMITE_DE_LINHAS = 3000;

    private const CABECALHOS = ['unidade', 'identificacao', 'identificador', 'numero'];

    /**
     * @return array{validas: list<LinhaDeUnidade>, invalidas: list<array{linha: int, motivo: string}>, duplicadas: list<int>}
     */
    public function ler(string $texto, PesoDoVoto $peso): array
    {
        $linhas = preg_split('/\R/u', $texto) ?: [];
        if (\count($linhas) > self::LIMITE_DE_LINHAS) {
            throw new OperacaoInvalidaNaAssembleiaException(sprintf('Cole no máximo %d linhas por vez.', self::LIMITE_DE_LINHAS));
        }
        $lidas = ['validas' => [], 'invalidas' => [], 'duplicadas' => []];
        $vistas = [];
        foreach ($linhas as $indice => $bruta) {
            $colunas = self::colunas($bruta);
            if (null === $colunas || (0 === \count($lidas['validas']) + \count($lidas['invalidas']) && self::ehCabecalho($colunas))) {
                continue;
            }
            $this->classificar($indice + 1, $colunas, $peso, $lidas, $vistas);
        }

        return $lidas;
    }

    /**
     * @param list<string>                                                                                                  $colunas
     * @param array{validas: list<LinhaDeUnidade>, invalidas: list<array{linha: int, motivo: string}>, duplicadas: list<int>} $lidas
     * @param array<string, true>                                                                                           $vistas
     */
    private function classificar(int $numero, array $colunas, PesoDoVoto $peso, array &$lidas, array &$vistas): void
    {
        try {
            $linha = self::linha($numero, $colunas, $peso);
        } catch (OperacaoInvalidaNaAssembleiaException $motivo) {
            $lidas['invalidas'][] = ['linha' => $numero, 'motivo' => $motivo->getMessage()];

            return;
        }
        if (isset($vistas[$linha->chave()])) {
            $lidas['duplicadas'][] = $numero;

            return;
        }
        $vistas[$linha->chave()] = true;
        $lidas['validas'][] = $linha;
    }

    /** @return list<string>|null null para linha vazia */
    private static function colunas(string $linha): ?array
    {
        if ('' === trim($linha)) {
            return null;
        }
        $separador = str_contains($linha, ';') ? ';' : "\t";

        return array_map(static fn (string $c): string => trim($c), array_pad(explode($separador, $linha), 6, ''));
    }

    /** @param list<string> $colunas */
    private static function ehCabecalho(array $colunas): bool
    {
        return \in_array(u($colunas[0])->ascii()->lower()->toString(), self::CABECALHOS, true);
    }

    /** @param list<string> $colunas */
    private static function linha(int $numero, array $colunas, PesoDoVoto $peso): LinhaDeUnidade
    {
        [$identificacao, $bloco, $situacao, $fracao, $responsavel, $email] = $colunas;
        if ('' === $identificacao) {
            throw new OperacaoInvalidaNaAssembleiaException('Linha sem a unidade.');
        }

        return new LinhaDeUnidade(
            linha: $numero,
            identificacao: mb_substr($identificacao, 0, 80),
            bloco: mb_substr($bloco, 0, 40),
            situacao: '' === $situacao ? null : mb_substr($situacao, 0, 40),
            fracao: self::fracao($fracao, $peso),
            responsavel: '' === $responsavel ? null : mb_substr($responsavel, 0, 160),
            email: self::email($email),
        );
    }

    private static function fracao(string $fracao, PesoDoVoto $peso): ?string
    {
        if ('' === $fracao) {
            if (PesoDoVoto::Fracao === $peso) {
                throw new OperacaoInvalidaNaAssembleiaException('Informe a fração ideal.');
            }

            return null;
        }
        if (1 !== preg_match('/^\d{1,6}([.,]\d{1,6})?$/', $fracao)) {
            throw new OperacaoInvalidaNaAssembleiaException('Fração inválida.');
        }
        $valor = bcadd(str_replace(',', '.', $fracao), '0', 6);
        if (bccomp($valor, '0', 6) <= 0) {
            throw new OperacaoInvalidaNaAssembleiaException('A fração precisa ser maior que zero.');
        }

        return $valor;
    }

    private static function email(string $email): ?string
    {
        if ('' === $email) {
            return null;
        }
        if (mb_strlen($email) > 180 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new OperacaoInvalidaNaAssembleiaException('E-mail inválido.');
        }

        return $email;
    }
}
