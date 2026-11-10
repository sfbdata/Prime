<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\DTO\DadosDoSignatario;
use App\AssinaturaEletronica\Exception\AcaoRecusada;

/**
 * Confere a estrutura de um modelo do escritório (contrato da etapa 4, seção 6), na linha do {@see ValidadorDeBlocos}:
 * os campos na forma do catálogo `BJAssinaCampos`, os papéis de quem assina e os blocos que o editor produz. Nenhum
 * HTML passa por aqui: o navegador converte o editor nesta estrutura e a reabre a partir dela. Cada recusa diz qual
 * regra falhou; nada é cortado em silêncio.
 */
final class ValidadorDoModelo
{
    public const CAMPOS_MAXIMO = 100;
    public const CHAVE_MAXIMO = 60;
    public const ROTULO_MAXIMO = 120;
    public const EXEMPLO_MAXIMO = 120;
    public const OPCOES_MAXIMO = 20;
    public const OPCAO_MAXIMO = 60;
    public const SIGNATARIOS_MAXIMO = 50;
    public const PAPEL_MAXIMO = DadosDoSignatario::PAPEL_MAXIMO;
    public const BLOCOS_MAXIMO = ValidadorDeBlocos::BLOCOS_MAXIMO;
    public const TEXTO_MAXIMO = ValidadorDeBlocos::TEXTO_MAXIMO;
    public const TEXTO_TOTAL_MAXIMO = 300000;
    public const PAPEL_DO_BLOCO_MAXIMO = ValidadorDeBlocos::PAPEL_DO_BLOCO_MAXIMO;
    /** Título, subtítulo, seção, número, data e parágrafo; o lugar de assinatura é o `a`. */
    public const TIPOS_DE_TEXTO = ['t', 's', 'h', 'n', 'd', 'p'];
    private const ERRO = 'assinatura_eletronica.modelo.erro.';

    private function __construct()
    {
    }

    /**
     * @return list<list<int|string|list<string>>> cada campo como `[chave, rótulo, largo]`, com o exemplo e as opções
     *                                              quando vieram
     *
     * @throws AcaoRecusada
     */
    public static function campos(mixed $campos): array
    {
        $lista = self::lista($campos);
        if (\count($lista) > self::CAMPOS_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'campos_demais');
        }

        $vistos = [];
        $normalizados = [];
        foreach ($lista as $indice => $campo) {
            $normalizado = self::campo($campo, $indice + 1);
            $chave = (string) $normalizado[0];
            if (isset($vistos[$chave])) {
                throw new AcaoRecusada(self::ERRO . 'campo_repetido', ['nome' => $chave]);
            }

            $vistos[$chave] = true;
            $normalizados[] = $normalizado;
        }

        return $normalizados;
    }

    /**
     * @return list<array{papel: string}> só o papel: nome e e-mail do signatário são do documento, não do modelo
     *
     * @throws AcaoRecusada
     */
    public static function signatarios(mixed $signatarios): array
    {
        $lista = self::lista($signatarios);
        if (\count($lista) > self::SIGNATARIOS_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'signatarios_demais');
        }

        $normalizados = [];
        foreach ($lista as $indice => $signatario) {
            $normalizados[] = ['papel' => self::papel($signatario, $indice + 1)];
        }

        return $normalizados;
    }

    /**
     * @param int $signatarios quantos signatários o modelo tem: o `a` de cada lugar de assinatura aponta para um deles
     *
     * @return list<array<string, int|string>>
     *
     * @throws AcaoRecusada
     */
    public static function corpo(mixed $corpo, int $signatarios): array
    {
        $lista = self::lista($corpo);
        if (\count($lista) > self::BLOCOS_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'blocos_demais');
        }

        $normalizados = [];
        $total = 0;
        foreach ($lista as $indice => $bloco) {
            $normalizado = self::bloco($bloco, $indice + 1, $signatarios);
            $total += self::tamanho($normalizado);
            $normalizados[] = $normalizado;
        }

        if ($total > self::TEXTO_TOTAL_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'corpo_longo');
        }

        return $normalizados;
    }

    /**
     * As conferências do "Salvar modelo" do desenho (dc:454), com os mesmos textos e na mesma ordem.
     *
     * @param list<array<string, int|string>> $corpo já normalizado
     *
     * @throws AcaoRecusada
     */
    public static function exigirOEssencial(array $corpo, int $signatarios, string $layout): void
    {
        $tipos = array_map(static fn (array $bloco): string => (string) array_key_first($bloco), $corpo);

        if (!\in_array('p', $tipos, true)) {
            throw new AcaoRecusada(self::ERRO . 'sem_texto');
        }

        if ($signatarios === 0 || !\in_array('a', $tipos, true)) {
            throw new AcaoRecusada(self::ERRO . 'sem_assinatura');
        }

        if ($layout === '2col' && !\in_array('t', $tipos, true)) {
            throw new AcaoRecusada(self::ERRO . 'sem_titulo');
        }
    }

    /**
     * @return list<mixed>
     *
     * @throws AcaoRecusada
     */
    private static function lista(mixed $valor): array
    {
        if (!\is_array($valor) || !array_is_list($valor)) {
            throw new AcaoRecusada(self::ERRO . 'dados_invalidos');
        }

        return $valor;
    }

    /**
     * @return list<int|string|list<string>>
     *
     * @throws AcaoRecusada
     */
    private static function campo(mixed $campo, int $posicao): array
    {
        if (!\is_array($campo) || !array_is_list($campo) || \count($campo) < 2 || \count($campo) > 5) {
            throw new AcaoRecusada(self::ERRO . 'campo_invalido', ['posicao' => $posicao]);
        }

        $normalizado = [
            self::chave($campo[0], $posicao),
            self::textoDoCampo($campo[1], true, self::ROTULO_MAXIMO, $posicao),
            self::largo($campo[2] ?? 0, $posicao),
        ];
        if (\count($campo) >= 4) {
            $normalizado[] = self::textoDoCampo($campo[3], false, self::EXEMPLO_MAXIMO, $posicao);
        }

        if (\count($campo) === 5) {
            $normalizado[] = self::opcoes($campo[4], $posicao);
        }

        return $normalizado;
    }

    /**
     * A chave vai dentro de `[[...]]` no texto: colchete quebraria a marcação, e `^` no começo faria o `[[^chave]]`
     * ser lido como a versão em maiúsculas de outro campo (o valor nunca apareceria).
     *
     * @throws AcaoRecusada
     */
    private static function chave(mixed $chave, int $posicao): string
    {
        if (!\is_string($chave)) {
            throw new AcaoRecusada(self::ERRO . 'campo_invalido', ['posicao' => $posicao]);
        }

        if (trim($chave) === '' || mb_strlen($chave) > self::CHAVE_MAXIMO || strpbrk($chave, '[]') !== false || str_starts_with($chave, '^')) {
            throw new AcaoRecusada(self::ERRO . 'campo_chave', ['posicao' => $posicao]);
        }

        return $chave;
    }

    /** @throws AcaoRecusada */
    private static function textoDoCampo(mixed $texto, bool $obrigatorio, int $maximo, int $posicao): string
    {
        if (!\is_string($texto) || ($obrigatorio && trim($texto) === '') || mb_strlen($texto) > $maximo) {
            throw new AcaoRecusada(self::ERRO . 'campo_invalido', ['posicao' => $posicao]);
        }

        return $texto;
    }

    /** @throws AcaoRecusada */
    private static function largo(mixed $largo, int $posicao): int
    {
        return match ($largo) {
            0, false => 0,
            1, true => 1,
            default => throw new AcaoRecusada(self::ERRO . 'campo_invalido', ['posicao' => $posicao]),
        };
    }

    /**
     * @return list<string>
     *
     * @throws AcaoRecusada
     */
    private static function opcoes(mixed $opcoes, int $posicao): array
    {
        if (!\is_array($opcoes) || !array_is_list($opcoes) || $opcoes === [] || \count($opcoes) > self::OPCOES_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'campo_invalido', ['posicao' => $posicao]);
        }

        $lidas = [];
        foreach ($opcoes as $opcao) {
            $lidas[] = self::textoDoCampo($opcao, true, self::OPCAO_MAXIMO, $posicao);
        }

        return $lidas;
    }

    /** @throws AcaoRecusada */
    private static function papel(mixed $signatario, int $numero): string
    {
        $papel = \is_array($signatario) && !array_is_list($signatario) ? ($signatario['papel'] ?? null) : null;
        if (!\is_string($papel) || trim($papel) === '') {
            throw new AcaoRecusada(self::ERRO . 'signatario_invalido', ['numero' => $numero]);
        }

        $papel = trim($papel);
        if (mb_strlen($papel) > self::PAPEL_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'signatario_papel_longo', ['numero' => $numero]);
        }

        return $papel;
    }

    /**
     * Exatamente uma chave de tipo; o resto do bloco é conferido pelo tipo.
     *
     * @return array<string, int|string>
     *
     * @throws AcaoRecusada
     */
    private static function bloco(mixed $bloco, int $posicao, int $signatarios): array
    {
        if (!\is_array($bloco) || array_is_list($bloco)) {
            throw new AcaoRecusada(self::ERRO . 'bloco_invalido', ['posicao' => $posicao]);
        }

        $tipos = array_values(array_filter(array_keys($bloco), static fn (int|string $chave): bool => \in_array($chave, [...self::TIPOS_DE_TEXTO, 'a'], true)));
        if (\count($tipos) !== 1) {
            throw new AcaoRecusada(self::ERRO . 'bloco_invalido', ['posicao' => $posicao]);
        }

        return $tipos[0] === 'a' ? self::assinatura($bloco, $posicao, $signatarios) : self::texto($bloco, (string) $tipos[0], $posicao);
    }

    /**
     * @param array<mixed> $bloco
     *
     * @return array<string, int|string>
     *
     * @throws AcaoRecusada
     */
    private static function texto(array $bloco, string $tipo, int $posicao): array
    {
        self::exigirChaves($bloco, $tipo === 'p' ? ['p', 'full'] : [$tipo], $posicao);
        $texto = $bloco[$tipo];
        if (!\is_string($texto) || trim($texto) === '') {
            throw new AcaoRecusada(self::ERRO . 'bloco_invalido', ['posicao' => $posicao]);
        }

        if (mb_strlen($texto) > self::TEXTO_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'bloco_texto_longo', ['posicao' => $posicao]);
        }

        $normalizado = [$tipo => $texto];
        if ($tipo === 'p' && self::marcado($bloco['full'] ?? null, $posicao)) {
            $normalizado['full'] = 1;
        }

        return $normalizado;
    }

    /**
     * @param array<mixed> $bloco
     *
     * @return array<string, int|string>
     *
     * @throws AcaoRecusada
     */
    private static function assinatura(array $bloco, int $posicao, int $signatarios): array
    {
        self::exigirChaves($bloco, ['a', 'r'], $posicao);
        $indice = $bloco['a'];
        $papel = $bloco['r'] ?? '';
        if (!\is_int($indice) || $indice < 0 || $indice >= $signatarios || !\is_string($papel) || mb_strlen($papel) > self::PAPEL_DO_BLOCO_MAXIMO) {
            throw new AcaoRecusada(self::ERRO . 'assinatura_invalida', ['posicao' => $posicao]);
        }

        return trim($papel) === '' ? ['a' => $indice] : ['a' => $indice, 'r' => $papel];
    }

    /**
     * @param array<mixed> $bloco
     * @param list<string> $permitidas
     *
     * @throws AcaoRecusada
     */
    private static function exigirChaves(array $bloco, array $permitidas, int $posicao): void
    {
        foreach (array_keys($bloco) as $chave) {
            if (!\in_array($chave, $permitidas, true)) {
                throw new AcaoRecusada(self::ERRO . 'bloco_invalido', ['posicao' => $posicao]);
            }
        }
    }

    /** @throws AcaoRecusada */
    private static function marcado(mixed $valor, int $posicao): bool
    {
        return match ($valor) {
            null, 0, false => false,
            1, true => true,
            default => throw new AcaoRecusada(self::ERRO . 'bloco_invalido', ['posicao' => $posicao]),
        };
    }

    /** @param array<string, int|string> $bloco */
    private static function tamanho(array $bloco): int
    {
        $total = 0;
        foreach ($bloco as $valor) {
            $total += \is_string($valor) ? mb_strlen($valor) : 0;
        }

        return $total;
    }
}
