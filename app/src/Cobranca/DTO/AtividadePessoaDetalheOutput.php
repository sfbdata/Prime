<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Detalhe de uma pessoa na aba Atividade, carregado SOB DEMANDA (rota própria) — a tabela não embute o
 * detalhe de todo mundo, senão a primeira consulta cresce com o tamanho da equipe (spec §8).
 *
 * A lista principal traz só **trabalho de cobrança** (spec §5.1); os lançamentos de cadastro/importação
 * ficam resumidos em `totalCadastro` e só são carregados quando o usuário expande. `truncado` e
 * `totalCadastro` existem para a tela nunca mentir por omissão: ela para no limite e avisa, e diz
 * quantos eventos deixou de fora por tipo.
 */
final class AtividadePessoaDetalheOutput
{
    /**
     * @param list<DesfechoContatoOutput> $desfechos
     * @param list<DesfechoContatoOutput> $canais
     * @param list<EventoAtividadeOutput> $eventos
     * @param list<EventoAtividadeOutput> $eventosCadastro vazio enquanto não expandido
     */
    public function __construct(
        public readonly ?int $usuarioId,
        public readonly string $nome,
        public readonly array $desfechos,
        public readonly array $canais,
        public readonly array $eventos,
        public readonly bool $truncado,
        public readonly int $limite,
        public readonly int $totalCadastro,
        public readonly array $eventosCadastro = [],
        public readonly bool $cadastroExpandido = false,
        public readonly bool $truncadoCadastro = false,
    ) {
    }

    public function temLancamentosDeCadastro(): bool
    {
        return $this->totalCadastro > 0;
    }
}
