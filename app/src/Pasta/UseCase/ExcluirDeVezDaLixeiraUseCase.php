<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Excluir de vez" na lixeira da aba Documentos (decisão do dono, 03/11/2026).
 *
 * Quem dispara é quem pode excluir e restaurar documento da pasta (permissão de EDITAR a pasta,
 * S-10); nenhuma regra nova. O que ele quer: tirar do sistema AGORA o que está na lixeira, sem
 * esperar os 30 dias da purga agendada.
 *
 * Regras:
 *  - cada item precisa estar NA LIXEIRA desta pasta e deste escritório (a rota prova por consulta;
 *    aqui é a segunda barreira, e o laço da purga confere de novo na hora do `remove()`);
 *  - a subpasta leva a subárvore inteira, como na purga; o arquivo só sai do disco DEPOIS do
 *    COMMIT e só se nenhuma outra linha o referenciar (INV-6, fatia C). Banco que recusa → nada
 *    do lote que falhou sai do disco e a exceção sobe (lote anterior, já confirmado, fica apagado);
 *  - documento com caminho legado que não vira chave é PULADO (fica na lixeira), como na purga.
 *
 * Não duplica a lógica: delega a {@see PurgarLixeiraUseCase::purgarSelecao()}, o mesmo laço da
 * purga agendada. Com o espelho R2 ligado o arquivo sai de lá pelo decorador do armazenamento.
 */
final class ExcluirDeVezDaLixeiraUseCase
{
    public function __construct(
        private readonly PurgarLixeiraUseCase $purga,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     *
     * @throws AccessDeniedException     item de outro escritório
     * @throws \InvalidArgumentException item de outra pasta, fora da lixeira, ou seleção vazia
     * @throws \Doctrine\DBAL\Exception  banco que recusa a exclusão (nada saiu do disco)
     */
    public function executar(Pasta $pasta, array $documentos, array $secoes, Tenant $tenant): ResultadoPurgaDaLixeira
    {
        if ($documentos === [] && $secoes === []) {
            throw new \InvalidArgumentException('Nenhum item selecionado.');
        }

        foreach ([...$secoes, ...$documentos] as $item) {
            $this->conferir($item, $pasta, $tenant);
        }

        return $this->purga->purgarSelecao(
            array_map(static fn (PastaSecao $s): int => (int) $s->getId(), $secoes),
            array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $documentos),
            (int) $pasta->getId(),
            (int) $tenant->getId(),
        );
    }

    private function conferir(PastaSecao|PastaDocumento $item, Pasta $pasta, Tenant $tenant): void
    {
        if ($item->getTenant() !== $tenant) {
            throw new AccessDeniedException('Item de outro escritório.');
        }
        if ($item->getPasta() !== $pasta) {
            throw new \InvalidArgumentException('Item de outra pasta.');
        }
        if ($item->getExcluidoEm() === null) {
            throw new \InvalidArgumentException('Item fora da lixeira.');
        }
    }
}
