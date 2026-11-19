<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\Processo;
use App\Processo\Exception\ProcessoForaDoEscopoException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Exclui o processo de vez (`processo_delete`, tela, e `processos_da_pasta` excluir_processo, conector). Sem volta.
 *
 * O que sai junto vem do mapeamento e das chaves do banco, sem código extra aqui: partes, movimentações, assuntos,
 * documentos, notas técnicas, previsões, links externos do Push e os vínculos com todas as pastas (ficam as pastas);
 * tarefas, publicações do Diário e processos filhos só perdem a ligação. O processo não guarda arquivo no disco
 * (`DocumentoProcesso` só aponta o caminho de origem), então não há remoção de arquivo para depois do COMMIT.
 *
 * Permissão (recurso `processo`, ação `delete`), CSRF e o redirecionamento são de quem chama: aqui só a posse do
 * escritório e a gravação, num único `flush`. Dá `clear()` no EntityManager ao fim: o processo removido ainda é
 * apontado por vínculos já carregados na memória (de uma pasta aberta por quem chama), e um `flush` depois deles
 * falharia. Quem chama relê o que ainda usa; as entidades recebidas ficam desanexadas.
 */
final class ExcluirProcessoUseCase
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @throws ProcessoForaDoEscopoException */
    public function executar(Processo $processo, Tenant $tenant): void
    {
        if ($processo->getTenant()?->getId() !== $tenant->getId()) {
            throw new ProcessoForaDoEscopoException('O processo não é deste escritório.');
        }

        $this->em->remove($processo);
        $this->em->flush();
        $this->em->clear();
    }
}
