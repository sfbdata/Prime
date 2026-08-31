<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma pasta em que o processo está vinculado, do jeito que a tela do processo precisa dela.
 *
 * Projetada por DQL (só as colunas exibidas) para não hidratar a Pasta inteira — com ela
 * viriam clientes, documentos, tarefas, marcadores e seções, nenhum deles usado aqui.
 *
 * O `principal` é do VÍNCULO, não da pasta: diz se este processo é o que representa aquela
 * pasta nos resumos. É a mesma informação que a aba "Processos vinculados" da pasta mostra,
 * vista do outro lado.
 */
final class PastaVinculadaOutput
{
    private function __construct(
        public readonly int $id,
        public readonly string $rotulo,
        public readonly ?string $nomeCliente,
        public readonly ?string $nomeAcao,
        public readonly string $situacao,
        public readonly bool $principal,
        public readonly bool $excluida,
        public readonly ?\DateTimeImmutable $excluidaEm,
        public readonly ?\DateTimeImmutable $vinculadoEm,
    ) {
    }

    /**
     * @param array<string, mixed> $row linha projetada (aliases de PastaRepository::listarVinculadasAoProcesso)
     */
    public static function fromRow(array $row): self
    {
        $id        = (int) $row['id'];
        $excluidaEm = self::data($row['excluidaEm'] ?? null);

        return new self(
            id: $id,
            rotulo: self::identificar($id, $row['nup'] ?? null),
            nomeCliente: self::texto($row['nomeCliente'] ?? null),
            nomeAcao: self::texto($row['nomeAcao'] ?? null),
            situacao: (string) ($row['situacao'] ?? ''),
            principal: (bool) ($row['principal'] ?? false),
            excluida: $excluidaEm !== null,
            excluidaEm: $excluidaEm,
            vinculadoEm: self::data($row['vinculadoEm'] ?? null),
        );
    }

    /**
     * Como a pasta se chama na tela. Sem número (pasta importada antes da numeração
     * automática) sobra o id — mesma convenção de `PastaRepository::opcoesDoTenant` e do
     * `PastaVizinhasOutput`; nunca um rótulo vazio.
     */
    private static function identificar(int $id, mixed $nup): string
    {
        $nup = is_string($nup) ? trim($nup) : '';

        return $nup !== '' ? $nup : '#' . $id;
    }

    private static function texto(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor !== '' ? $valor : null;
    }

    /**
     * A hidratação em array devolve `DateTimeImmutable` para colunas `datetime_immutable`,
     * mas o driver pode entregar string em algumas plataformas — aceitamos os dois.
     */
    private static function data(mixed $valor): ?\DateTimeImmutable
    {
        if ($valor instanceof \DateTimeImmutable) {
            return $valor;
        }

        if ($valor instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($valor);
        }

        if (is_string($valor) && trim($valor) !== '') {
            try {
                return new \DateTimeImmutable($valor);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
