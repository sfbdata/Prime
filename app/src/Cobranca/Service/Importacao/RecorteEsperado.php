<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

/**
 * O recorte que cada fonte da contábil L.G PRECISA ter para poder ser importada — spec
 * `docs/specs/cobranca-validador-rodape-filtros.md` §3.6.
 *
 * Os textos são os IMPRESSOS no rodapé, não os enums enviados à API: manda-se `BAIXADA` e o arquivo
 * escreve `Baixadas` (plural), manda-se `EM_ANDAMENTO` e sai `Em andamento`. O mapa é tabelado à mão
 * de propósito (§2.2.4) — e a comparação é exata, nunca `contains`, porque
 * `str_contains('Baixadas', 'Baixada')` é verdadeiro e deixaria passar o recorte errado.
 *
 * Campo que o arquivo traz e que não está declarado aqui é IGNORADO: a lista de campos do rodapé varia
 * entre emissões (`Conta (bancária, caixinha...)` aparece em umas e não em outras, §2.2.3). Este
 * objeto afirma "o que me importa está certo", não "o arquivo é exatamente isto".
 */
final class RecorteEsperado
{
    public const TIPO_EXATO = 'exato';
    public const TIPO_QUALQUER_UM_DE = 'qualquerUmDe';
    public const TIPO_TODOS_OU_ORFAO = 'todosOuOrfao';

    /** Valores que contam como "sem recorte" num campo de período (o sistema deles varia gênero). */
    public const VALORES_TODOS = ['Todos', 'Todas'];

    /**
     * @param list<array{tipo: string, chave: string, valores: list<string>}> $expectativas
     */
    private function __construct(
        public readonly string $fonte,
        public readonly array $expectativas,
    ) {
    }

    /**
     * Inadimplência detalhada. `Inadimplência até:<data>` fica de FORA de propósito: a data de corte
     * muda a cada emissão, e fixá-la recusaria toda emissão a partir do dia seguinte (spec §3.6).
     */
    public static function inadimplencia(): self
    {
        return new self('Inadimplências detalhadas', [
            self::exato('Competência', 'Todas'),
            self::exato('Período de vencimento', 'Todos'),
            self::exato('Unidade', 'Todas'),
            self::exato('Sacado', 'Todos'),
        ]);
    }

    /**
     * Acordos detalhados — emitidos UM ARQUIVO POR SITUAÇÃO, então as duas situações importáveis são
     * aceitas separadamente.
     *
     * 🔒 `Cancelado` é recusado por decisão do dono (handoff §5: "cancelados ficam de fora"). Até aqui
     * isso era só uma frase num documento e uma instrução repetida a cada sessão; esta linha é o que
     * transforma a decisão em trava técnica — apontar o comando para o `*_CANCELADO.xlsx` passa a ser
     * barrado, não avisado.
     */
    public static function acordos(): self
    {
        return new self('Acordos detalhados', [
            self::qualquerUmDe('Situação do acordo', ['Em andamento', 'Liquidado']),
            self::exato('Período de criação do acordo', 'Todos'),
            self::exato('Unidade/Cliente', 'Todos'),
            self::exato('Sacado', 'Todos'),
        ]);
    }

    /**
     * Receitas detalhadas por unidade/cliente. `Situação das contas: Baixadas` é decisão do dono de
     * 04/08 (só pago — handoff §5): linha em aberto obrigaria o importador a adivinhar se é dívida ou
     * pagamento e quebraria a idempotência da sincronização.
     *
     * `Período de recebimento` é o ÚNICO `todosOuOrfao` — o rodapé perde o rótulo desse campo quando
     * ele vale "todos" e deixa um `Todos;` solto (§2.2.2). O `Período de vencimento`, vizinho dele,
     * mantém o rótulo: por isso um é `exato` e o outro não. Foi justamente a janela de recebimento que
     * cortou 5 anos de histórico para 7 meses.
     */
    public static function receitas(): self
    {
        return new self('Receitas detalhadas por unidade/cliente', [
            self::exato('Situação das contas', 'Baixadas'),
            self::exato('Competência', 'Todas'),
            self::exato('Período de vencimento', 'Todos'),
            self::todosOuOrfao('Período de recebimento'),
            self::exato('Unidade', 'Todos'),
            self::exato('Classe de conta', 'Todas'),
            self::exato('Sacado', 'Todos'),
        ]);
    }

    public static function cadastro(): self
    {
        return new self('Dados cadastrais dos condôminos', [
            self::exato('Unidades', 'Todas'),
        ]);
    }

    /** @return array{tipo: string, chave: string, valores: list<string>} */
    private static function exato(string $chave, string $valor): array
    {
        return ['tipo' => self::TIPO_EXATO, 'chave' => $chave, 'valores' => [$valor]];
    }

    /**
     * @param list<string> $valores
     *
     * @return array{tipo: string, chave: string, valores: list<string>}
     */
    private static function qualquerUmDe(string $chave, array $valores): array
    {
        return ['tipo' => self::TIPO_QUALQUER_UM_DE, 'chave' => $chave, 'valores' => $valores];
    }

    /** @return array{tipo: string, chave: string, valores: list<string>} */
    private static function todosOuOrfao(string $chave): array
    {
        return ['tipo' => self::TIPO_TODOS_OU_ORFAO, 'chave' => $chave, 'valores' => self::VALORES_TODOS];
    }
}
