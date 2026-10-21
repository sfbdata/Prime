<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Enum\PrioridadeDoDespacho;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\PedidoInvalidoNaCentralException;

/**
 * O pedido de despacho como chega da tela: QUAL item (pasta, tipo, id), para QUEM (um colega OU uma conversa, nunca os
 * dois) e como (instrução opcional, prioridade). O texto do item não vem daqui: o servidor relê.
 */
final readonly class EncaminharItemInput
{
    public const INSTRUCAO_MAXIMA = 1000;

    /** Maior valor de uma coluna `int` do PostgreSQL: id acima disso nem chega ao banco. */
    private const MAIOR_ID = 2_147_483_647;

    public ?string $instrucao;

    /** @throws PedidoInvalidoNaCentralException */
    public function __construct(
        public int $pastaId,
        public TipoDeItemEncaminhado $tipo,
        public ?int $itemId,
        public ?int $usuarioId,
        public ?int $conversaId,
        ?string $instrucao,
        public PrioridadeDoDespacho $prioridade,
    ) {
        if (($usuarioId === null) === ($conversaId === null)) {
            throw new PedidoInvalidoNaCentralException('Escolha um destino: um colega ou uma conversa.');
        }

        $instrucao = $instrucao === null ? null : trim($instrucao);
        if ($instrucao !== null && mb_strlen($instrucao) > self::INSTRUCAO_MAXIMA) {
            throw new PedidoInvalidoNaCentralException(sprintf('A instrução passa de %d caracteres.', self::INSTRUCAO_MAXIMA));
        }
        $this->instrucao = $instrucao === '' ? null : $instrucao;
    }

    /**
     * @param array<string, mixed> $dados o corpo JSON
     *
     * @throws PedidoInvalidoNaCentralException tipo errado, enum desconhecido, id torto
     */
    public static function fromArray(array $dados): self
    {
        $instrucao = $dados['instrucao'] ?? null;
        if ($instrucao !== null && !is_string($instrucao)) {
            throw new PedidoInvalidoNaCentralException('A instrução precisa ser texto.');
        }

        return new self(
            pastaId: self::id($dados, 'pastaId') ?? throw new PedidoInvalidoNaCentralException('Pasta não informada.'),
            tipo: self::enum($dados, 'tipo', TipoDeItemEncaminhado::class, null, 'Tipo de item desconhecido.'),
            itemId: self::id($dados, 'itemId'),
            usuarioId: self::id($dados, 'usuarioId'),
            conversaId: self::id($dados, 'conversaId'),
            instrucao: $instrucao,
            prioridade: self::enum($dados, 'prioridade', PrioridadeDoDespacho::class, PrioridadeDoDespacho::Normal, 'Prioridade desconhecida.'),
        );
    }

    /** @param array<string, mixed> $dados */
    private static function id(array $dados, string $chave): ?int
    {
        $valor = $dados[$chave] ?? null;
        if ($valor === null) {
            return null;
        }
        if (!is_int($valor) || $valor <= 0 || $valor > self::MAIOR_ID) {
            throw new PedidoInvalidoNaCentralException(sprintf('O campo %s é inválido.', $chave));
        }

        return $valor;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<string, mixed> $dados
     * @param class-string<T>      $enum
     * @param T|null               $padrao
     *
     * @return T
     */
    private static function enum(array $dados, string $chave, string $enum, ?\BackedEnum $padrao, string $erro): \BackedEnum
    {
        $valor = $dados[$chave] ?? null;
        if ($valor === null && $padrao !== null) {
            return $padrao;
        }

        $caso = is_string($valor) ? $enum::tryFrom($valor) : null;

        return $caso ?? throw new PedidoInvalidoNaCentralException($erro);
    }
}
