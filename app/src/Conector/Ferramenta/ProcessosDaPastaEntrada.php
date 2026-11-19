<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\TipoDeApenso;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;

/**
 * A entrada da `processos_da_pasta`, já conferida (6.2, regra 10: esquema fechado, tipos e tamanhos iguais aos do
 * esquema). Nasce dos argumentos da chamada ({@see dosArgumentos()}) ou dos dados guardados na proposta
 * ({@see dosDados()}). O campo de segredo do cartão não existe aqui: o conector nunca liga nem desliga segredo (6.2.3).
 */
final class ProcessosDaPastaEntrada
{
    public const ACOES = ['vincular', 'principal', 'dados_do_cartao', 'acao_da_pasta', 'via_administrativa', 'nota_tecnica', 'excluir_processo'];

    public const OPERACOES = [
        'vincular' => ['novo', 'cadastrado', 'apensado', 'desvincular'],
        'nota_tecnica' => ['criar', 'editar', 'excluir'],
    ];

    /** Os campos do cartão do processo que o conector grava (sem `segredo`). */
    public const CARTAO_TEXTOS = ['classe', 'assuntos', 'jurisdicao', 'orgao', 'distribuicao', 'valor_causa'];
    public const CARTAO_FLAGS = ['digital', 'gratuita', 'tutela', 'prioridade'];

    /** O `maxLength` de cada campo de texto do esquema. */
    public const TAMANHOS = [
        'numero_processo' => 30, 'orgao_julgador' => 255, 'classe_processual' => 255, 'assunto_processual' => 255,
        'classe' => 255, 'assuntos' => 5200, 'jurisdicao' => 255, 'orgao' => 255, 'valor_causa' => 25,
        'nome_da_acao' => 255, 'conteudo' => 20000,
    ];

    private const OBRIGATORIOS = [
        'vincular.novo' => ['numero_processo', 'orgao_julgador', 'classe_processual', 'assunto_processual'],
        'vincular.cadastrado' => ['processo_id'],
        'vincular.apensado' => ['processo_id', 'tipo_apenso'],
        'vincular.desvincular' => ['processo_id'],
        'principal' => ['processo_id'],
        'dados_do_cartao' => ['processo_id'],
        'acao_da_pasta' => ['nome_da_acao'],
        'via_administrativa' => ['administrativa'],
        'nota_tecnica.criar' => ['processo_id', 'conteudo'],
        'nota_tecnica.editar' => ['processo_id', 'nota_id', 'conteudo'],
        'nota_tecnica.excluir' => ['processo_id', 'nota_id'],
        'excluir_processo' => ['processo_id'],
    ];

    private const OPCIONAIS = [
        'vincular.novo' => ['situacao_processo', 'instancia', 'data_distribuicao'],
        'dados_do_cartao' => [...self::CARTAO_TEXTOS, ...self::CARTAO_FLAGS],
    ];

    /** @param array<string, mixed> $campos */
    private function __construct(
        public readonly string $acao,
        public readonly ?string $operacao,
        public readonly int $pastaId,
        public readonly array $campos,
    ) {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function dosArgumentos(array $argumentos): self
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !\in_array($acao, self::ACOES, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = self::inteiro($argumentos['pasta_id'] ?? null) ?? throw ErroDaFerramenta::entradaInvalida();
        $operacao = self::operacao($acao, $argumentos['operacao'] ?? null);
        unset($argumentos['acao'], $argumentos['pasta_id'], $argumentos['operacao']);

        $chave = $operacao === null ? $acao : $acao . '.' . $operacao;
        $obrigatorios = self::OBRIGATORIOS[$chave];
        if (array_diff(array_keys($argumentos), $obrigatorios, self::OPCIONAIS[$chave] ?? []) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($obrigatorios as $campo) {
            if (!\array_key_exists($campo, $argumentos)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        if ($acao === 'dados_do_cartao' && \count($argumentos) < 2) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[$campo] = self::campo($campo, $valor);
        }

        return new self($acao, $operacao, $pastaId, $campos);
    }

    /**
     * Os dados de uma proposta: os mesmos campos mais a operação e a pasta; `anterior` (o que a proposta guardou do
     * valor de antes) não é campo de entrada.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public static function dosDados(string $acao, array $dados): self
    {
        unset($dados['anterior']);

        return self::dosArgumentos(['acao' => $acao] + $dados);
    }

    public function chave(): string
    {
        return $this->operacao === null ? $this->acao : $this->acao . '.' . $this->operacao;
    }

    /** O id de um campo que a ação exige (conferido na entrada). */
    public function id(string $campo): int
    {
        return (int) ($this->campos[$campo] ?? 0);
    }

    public function texto(string $campo): string
    {
        return (string) ($this->campos[$campo] ?? '');
    }

    /**
     * Os dados que ficam na proposta: tudo o que será feito, exatamente.
     *
     * @param array<string, mixed> $anterior o valor de antes, para a confirmação conferir que não mudou
     *
     * @return array<string, mixed>
     */
    public function dados(array $anterior = []): array
    {
        $dados = ['pasta_id' => $this->pastaId] + ($this->operacao === null ? [] : ['operacao' => $this->operacao]) + $this->campos;

        return $anterior === [] ? $dados : $dados + ['anterior' => $anterior];
    }

    /** @throws ErroDaFerramenta */
    private static function operacao(string $acao, mixed $operacao): ?string
    {
        $operacoes = self::OPERACOES[$acao] ?? [];
        if ($operacoes === []) {
            return $operacao === null ? null : throw ErroDaFerramenta::entradaInvalida();
        }

        return \is_string($operacao) && \in_array($operacao, $operacoes, true) ? $operacao : throw ErroDaFerramenta::entradaInvalida();
    }

    /** @throws ErroDaFerramenta */
    private static function campo(string $campo, mixed $valor): string|int|bool
    {
        return match (true) {
            $campo === 'processo_id' || $campo === 'nota_id' => self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida(),
            $campo === 'administrativa' || \in_array($campo, self::CARTAO_FLAGS, true) => \is_bool($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            $campo === 'tipo_apenso' => self::daLista($valor, array_map(static fn (TipoDeApenso $t): string => $t->value, TipoDeApenso::cases())),
            $campo === 'situacao_processo' => self::daLista($valor, CadastrarProcessoPelaPastaUseCase::SITUACOES),
            $campo === 'instancia' => self::daLista($valor, CadastrarProcessoPelaPastaUseCase::INSTANCIAS),
            $campo === 'data_distribuicao' || $campo === 'distribuicao' => self::data($valor),
            default => self::escalar($campo, $valor),
        };
    }

    /** @param list<string> $lista */
    private static function daLista(mixed $valor, array $lista): string
    {
        return \is_string($valor) && \in_array($valor, $lista, true) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /** AAAA-MM-DD de verdade, ou vazio (limpa o campo). */
    private static function data(mixed $valor): string
    {
        if (!\is_string($valor) || ($valor !== '' && !self::dataValida($valor))) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $valor;
    }

    private static function dataValida(string $valor): bool
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data !== false && $data->format('Y-m-d') === $valor;
    }

    private static function escalar(string $campo, mixed $valor): string
    {
        if (\is_string($valor) && isset(self::TAMANHOS[$campo]) && mb_strlen(trim($valor)) <= self::TAMANHOS[$campo]) {
            return trim($valor);
        }

        throw ErroDaFerramenta::entradaInvalida();
    }

    /** Só número inteiro, como o esquema diz (`integer`): "1" não vale. */
    private static function inteiro(mixed $valor): ?int
    {
        return \is_int($valor) && $valor >= 1 ? $valor : null;
    }
}
