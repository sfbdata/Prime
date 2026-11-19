<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\CarteiraDaPasta;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDestaque;
use App\Pasta\Entity\PrioridadePasta;

/**
 * A entrada da `atualizar_pasta`, conferida (`docs/specs/mcp-remoto-oauth.md`, 6.2, regra 10): a ação da lista fechada,
 * só os campos que a ação usa, os obrigatórios presentes, o tipo de cada campo e os valores de lista fechada. Qualquer
 * desvio é "pedido inválido", com a mensagem fixa; o valor digitado nunca volta.
 *
 * Valor, não serviço: nasce por {@see de()} a cada chamada.
 */
final class AtualizarPastaEntrada
{
    /** O valor de `destaque` que tira a cor da linha. */
    public const SEM_DESTAQUE = 'nenhum';

    /** No máximo 50 itens por chamada (6.2, regra 12). */
    public const LOTE = 50;

    public const ALVOS = ['pasta', 'marcador', 'reu'];

    /** Os campos de cada ação, na ordem da tabela de aba e chave ({@see TabelaDeAbaEChave}). */
    public const CAMPOS = [
        'criar' => ['nome_cliente', 'nome_acao'],
        'editar_dados' => ['pasta_id', 'nup', 'nome_cliente'],
        'situacao' => ['pasta_id', 'situacao'],
        'responsavel' => ['pasta_id', 'responsavel_id'],
        'prioridade' => ['pasta_id', 'prioridade'],
        'carteira' => ['pasta_id', 'carteira'],
        'duplicar' => ['pasta_id'],
        'restaurar' => ['pasta_id'],
        'favoritar' => ['pasta_id', 'ligado'],
        'acompanhar' => ['pasta_id', 'ligado'],
        'destacar' => ['pasta_id', 'destaque'],
        'marcadores_da_pasta' => ['pasta_id', 'marcadores'],
        'marcador' => ['operacao', 'marcador_id', 'nome', 'cor', 'descricao', 'pai_id', 'alvo_id', 'depois'],
        'nome_do_cliente' => ['pasta_id', 'nome'],
        'cadastro_da_pasta' => ['pasta_id', 'nome_do_reu', 'cpf', 'qualificacao', 'email', 'endereco', 'contrato', 'unidade', 'titular_extrato', 'prescricao'],
        'reu' => ['pasta_id', 'operacao', 'reu_id', 'nome', 'documento'],
        'pedir_acesso' => ['pasta_id', 'nivel', 'motivo'],
        'enviar_relatorio_sem_processo' => ['pasta_id'],
        'excluir' => ['alvo', 'pasta_id', 'marcador_id', 'reu_id'],
    ];

    private const OBRIGATORIOS = [
        'editar_dados' => ['pasta_id'],
        'situacao' => ['pasta_id', 'situacao'],
        'responsavel' => ['pasta_id', 'responsavel_id'],
        'prioridade' => ['pasta_id', 'prioridade'],
        'carteira' => ['pasta_id', 'carteira'],
        'duplicar' => ['pasta_id'],
        'restaurar' => ['pasta_id'],
        'favoritar' => ['pasta_id', 'ligado'],
        'acompanhar' => ['pasta_id', 'ligado'],
        'destacar' => ['pasta_id', 'destaque'],
        'marcadores_da_pasta' => ['pasta_id', 'marcadores'],
        'marcador' => ['operacao'],
        'nome_do_cliente' => ['pasta_id', 'nome'],
        'cadastro_da_pasta' => ['pasta_id'],
        'reu' => ['pasta_id', 'operacao'],
        'pedir_acesso' => ['pasta_id'],
        'enviar_relatorio_sem_processo' => ['pasta_id'],
        'excluir' => ['alvo'],
    ];

    private const OPERACOES = [
        'marcador' => ['criar', 'editar', 'reordenar'],
        'reu' => ['adicionar', 'subir'],
    ];

    private const INTEIROS = ['pasta_id', 'responsavel_id', 'marcador_id', 'pai_id', 'alvo_id', 'reu_id'];
    private const BOOLEANOS = ['ligado', 'depois'];

    /** O tamanho máximo de cada texto; o que não está aqui vai até 255. */
    public const TAMANHOS = ['nome' => 255, 'cor' => 7, 'descricao' => 255, 'motivo' => 500, 'qualificacao' => 500, 'endereco' => 500, 'prescricao' => 10, 'cpf' => 20, 'documento' => 20];

    /** @param array<string, mixed> $campos */
    private function __construct(public readonly string $acao, private readonly array $campos)
    {
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    public static function de(array $argumentos): self
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        if (array_diff(array_map('strval', array_keys($argumentos)), self::CAMPOS[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach (self::OBRIGATORIOS[$acao] ?? [] as $campo) {
            if (!\array_key_exists($campo, $argumentos)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        foreach ($argumentos as $campo => $valor) {
            self::conferir($acao, (string) $campo, $valor);
        }

        return new self($acao, $argumentos);
    }

    /**
     * Os valores aceitos de um campo de lista fechada; null quando o campo é livre.
     *
     * @return list<string>|null
     */
    public static function opcoes(string $acao, string $campo): ?array
    {
        return match ($campo) {
            'situacao' => Pasta::SITUACOES,
            'prioridade' => array_map(static fn (PrioridadePasta $p): string => $p->value, PrioridadePasta::cases()),
            'carteira' => array_map(static fn (CarteiraDaPasta $c): string => $c->value, CarteiraDaPasta::cases()),
            'destaque' => [...PastaDestaque::CORES, self::SEM_DESTAQUE],
            'nivel' => [NivelDeAcesso::Visualizar->value, NivelDeAcesso::Editar->value],
            'alvo' => self::ALVOS,
            'operacao' => self::OPERACOES[$acao] ?? [],
            default => null,
        };
    }

    /** @return list<string> todas as operações, para o esquema */
    public static function todasAsOperacoes(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::OPERACOES))));
    }

    public function tem(string $campo): bool
    {
        return \array_key_exists($campo, $this->campos);
    }

    /** @throws ErroDaFerramenta */
    public function pastaId(): int
    {
        return $this->inteiroObrigatorio('pasta_id');
    }

    /** @throws ErroDaFerramenta */
    public function inteiroObrigatorio(string $campo): int
    {
        return $this->inteiro($campo) ?? throw ErroDaFerramenta::entradaInvalida();
    }

    public function inteiro(string $campo): ?int
    {
        $valor = $this->campos[$campo] ?? null;

        return \is_int($valor) ? $valor : null;
    }

    public function texto(string $campo): ?string
    {
        $valor = $this->campos[$campo] ?? null;

        return \is_string($valor) ? $valor : null;
    }

    /** @throws ErroDaFerramenta */
    public function textoObrigatorio(string $campo): string
    {
        return $this->texto($campo) ?? throw ErroDaFerramenta::entradaInvalida();
    }

    public function booleano(string $campo, bool $padrao = false): bool
    {
        $valor = $this->campos[$campo] ?? null;

        return \is_bool($valor) ? $valor : $padrao;
    }

    /** @return list<int> */
    public function lista(string $campo): array
    {
        $valor = $this->campos[$campo] ?? [];

        return \is_array($valor) ? array_values(array_unique(array_map('intval', $valor))) : [];
    }

    /** @throws ErroDaFerramenta */
    private static function conferir(string $acao, string $campo, mixed $valor): void
    {
        $valido = match (true) {
            \in_array($campo, self::INTEIROS, true) => ($valor === null && $campo === 'responsavel_id') || (\is_int($valor) && $valor >= 1),
            \in_array($campo, self::BOOLEANOS, true) => \is_bool($valor),
            $campo === 'marcadores' => self::listaValida($valor),
            default => self::textoValido($acao, $campo, $valor),
        };
        if (!$valido) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    private static function listaValida(mixed $valor): bool
    {
        if (!\is_array($valor) || !array_is_list($valor) || \count($valor) > self::LOTE) {
            return false;
        }
        foreach ($valor as $id) {
            if (!\is_int($id) || $id < 1) {
                return false;
            }
        }

        return true;
    }

    private static function textoValido(string $acao, string $campo, mixed $valor): bool
    {
        if (!\is_string($valor) || mb_strlen($valor) > (self::TAMANHOS[$campo] ?? 255)) {
            return false;
        }
        $opcoes = self::opcoes($acao, $campo);

        return $opcoes === null || \in_array($valor, $opcoes, true);
    }
}
