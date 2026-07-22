<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma linha da tabela da aba Atividade (Central de Acompanhamento, Fatia 1). Volume e efetividade lado
 * a lado, SEM nota única e SEM ranking (decisão do dono): `contatos` é toda tentativa, `atendidos` é o
 * subconjunto que falou com o devedor. Não há valor em R$ aqui — "baixas registradas" conta o ATO, não
 * o mérito da negociação (spec §5, nota 4), e recuperado por carteira fica na aba Resultado.
 *
 * `usuarioId` nulo + `semResponsavel` verdadeiro é a linha dos eventos órfãos (`usuario_id` é
 * `onDelete: SET NULL`): eles nunca são atribuídos a alguém.
 */
final class AtividadePessoaOutput
{
    public const SEM_RESPONSAVEL = 'Sem responsável';

    public function __construct(
        public readonly ?int $usuarioId,
        public readonly string $nome,
        public readonly int $contatos,
        public readonly int $atendidos,
        public readonly int $acordos,
        public readonly int $baixas,
        public readonly ?\DateTimeImmutable $ultimaAcao,
        public readonly bool $semResponsavel = false,
    ) {
    }

    public static function zerada(int $usuarioId, string $nome): self
    {
        return new self($usuarioId, $nome, 0, 0, 0, 0, null);
    }

    /**
     * Identificador da linha para a rota de detalhe — `sem-responsavel` no lugar de um id que não existe.
     */
    public function chaveDetalhe(): string
    {
        return $this->semResponsavel ? 'sem-responsavel' : (string) $this->usuarioId;
    }

    public function temAlgumaAtividade(): bool
    {
        return $this->contatos > 0 || $this->acordos > 0 || $this->baixas > 0 || $this->ultimaAcao !== null;
    }
}
