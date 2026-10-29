<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Enum\TipoDaProposta;
use App\Inteligencia\DTO\ChamadaDeFerramenta;

/**
 * Lê a chamada de ferramenta do modelo e a transforma em `dados` de uma proposta, ou a DESCARTA (nulo). O que o modelo
 * devolve é dado não confiável (pode vir de uma CNH ou PDF que tenta instruir): só passa a forma esperada.
 *
 * - nome de ferramenta desconhecido, chave fora de `CamposDoCadastro::ROTULOS` (ou de outro tipo de pessoa), chave de
 *   topo que a ferramenta não declara, valor que não é texto ou número, valor passando de {@see self::TETO_DO_VALOR}
 *   caracteres, proposta sem nenhum valor: descarte;
 * - texto: sem caracteres de controle e invisíveis, aparado; vazio some (campo ausente = em branco).
 *
 * A validação de CONTEÚDO (CPF com dígito, e-mail, limites das colunas) é do {@see ValidadorDaProposta}, que mostra o
 * erro no cartão em vez de descartar.
 */
final class InterpretadorDaChamadaDeCadastro
{
    /** Teto de qualquer valor: bem acima das colunas (255), só para a proposta não carregar um texto gigante. */
    public const TETO_DO_VALOR = 1000;

    /** @return array{tipo: TipoDaProposta, dados: array<string, mixed>}|null */
    public function interpretar(ChamadaDeFerramenta $chamada): ?array
    {
        $tipo = TipoDaProposta::daFerramenta($chamada->nome);
        $dados = match ($tipo) {
            TipoDaProposta::Cliente => self::dadosDoCliente($chamada->entrada),
            TipoDaProposta::Pasta => self::dadosDaPasta($chamada->entrada),
            null => null,
        };

        return $tipo === null || $dados === null ? null : ['tipo' => $tipo, 'dados' => $dados];
    }

    /**
     * Os campos de um cliente: só chaves do tipo de pessoa, valores em texto. Nulo = inválido (chave fora da lista, valor
     * que não é texto). Vazios saem. Serve também para os valores editados no cartão.
     *
     * @return array<string, string>|null
     */
    public static function campos(mixed $campos, string $tipoPessoa): ?array
    {
        if (!\is_array($campos) || ($campos !== [] && array_is_list($campos))) {
            return null;
        }
        $aceitas = CamposDaProposta::chaves($tipoPessoa);
        $limpos = [];
        foreach ($campos as $chave => $valor) {
            if (!\in_array($chave, $aceitas, true)) {
                return null;
            }
            if ($valor === null) {
                continue;
            }
            $texto = self::texto($valor, self::TETO_DO_VALOR);
            if ($texto === null) {
                return null;
            }
            if ($texto !== '') {
                $limpos[$chave] = $texto;
            }
        }

        return $limpos;
    }

    /** Texto (ou número) sem controle nem invisíveis, aparado; nulo se não é texto ou passa do teto. */
    public static function texto(mixed $valor, int $teto): ?string
    {
        if (\is_int($valor) || \is_float($valor)) {
            $valor = (string) $valor;
        }
        if (!\is_string($valor)) {
            return null;
        }
        $limpo = trim((string) preg_replace('/[\s\p{Cc}\p{Cf}]+/u', ' ', $valor));

        return mb_strlen($limpo) > $teto ? null : $limpo;
    }

    /**
     * @param array<string, mixed> $entrada
     *
     * @return array<string, mixed>|null
     */
    private static function dadosDoCliente(array $entrada): ?array
    {
        if (array_diff(array_keys($entrada), ['tipo', 'campos']) !== [] || !CamposDaProposta::tipoValido($entrada['tipo'] ?? null)) {
            return null;
        }
        $campos = self::campos($entrada['campos'] ?? null, (string) $entrada['tipo']);

        return $campos === null || $campos === [] ? null : ['tipoPessoa' => $entrada['tipo'], 'campos' => $campos];
    }

    /**
     * @param array<string, mixed> $entrada
     *
     * @return array<string, mixed>|null
     */
    private static function dadosDaPasta(array $entrada): ?array
    {
        if (array_diff(array_keys($entrada), ['nome_cliente', 'nome_acao', 'nup', 'cliente']) !== []) {
            return null;
        }
        $nomeCliente = self::texto($entrada['nome_cliente'] ?? null, 255);
        $nomeAcao = self::texto($entrada['nome_acao'] ?? null, 255);
        $nup = ''; // o número da pasta é gerado pelo sistema (R1): se o modelo mandar `nup`, é descartado
        if ($nomeCliente === null || $nomeCliente === '' || $nomeAcao === null || $nomeAcao === '') {
            return null;
        }
        $dados = ['nomeCliente' => $nomeCliente, 'nomeAcao' => $nomeAcao, 'nup' => $nup, 'cliente' => null];
        if (isset($entrada['cliente'])) {
            $cliente = \is_array($entrada['cliente']) ? self::dadosDoCliente($entrada['cliente']) : null;
            if ($cliente === null) {
                return null;
            }
            $dados['cliente'] = $cliente;
        }

        return $dados;
    }
}
