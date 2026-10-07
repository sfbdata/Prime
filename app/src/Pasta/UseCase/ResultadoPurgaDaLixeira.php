<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

/**
 * O resumo de uma execução de `PurgarLixeiraUseCase` (`app:documentos:purgar-lixeira`).
 *
 *  - `documentosCandidatos`/`secoesCandidatas`: o que estava vencido no início (toda a fila, não
 *    só o que coube no `--limite`);
 *  - `documentosRemovidos`/`secoesRemovidas`: linhas que SAÍRAM do banco nesta execução — as
 *    seções contam a descendência que caiu junto; os documentos, os de dentro das seções também;
 *  - `arquivosRemovidos`: arquivos físicos apagados DEPOIS do COMMIT;
 *  - `arquivosNaoRemovidos`: chaves (texto) que ficaram no storage com o banco já confirmado —
 *    órfãos recuperáveis, registrados no log pela `RemocaoAposTransacao`;
 *  - `secoesPuladas`: seções vencidas da fila que NÃO saíram porque há documento na árvore com
 *    caminho que não vira chave (SEC-007);
 *  - `documentosPulados`: documentos vencidos da fila que NÃO saíram pelo mesmo motivo. Nenhum
 *    pulado ocupa vaga do `--limite` (SEC-007b);
 *  - `simulacao`: `--dry-run` — nada saiu do banco nem do disco.
 */
final readonly class ResultadoPurgaDaLixeira
{
    /** @param list<string> $arquivosNaoRemovidos */
    public function __construct(
        public bool $simulacao,
        public \DateTimeImmutable $corte,
        public int $documentosCandidatos,
        public int $secoesCandidatas,
        public int $documentosRemovidos,
        public int $secoesRemovidas,
        public int $arquivosRemovidos,
        public array $arquivosNaoRemovidos,
        public int $secoesPuladas = 0,
        public int $documentosPulados = 0,
    ) {
    }
}
