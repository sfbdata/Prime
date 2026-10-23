<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O nome que cada etapa e cada caixa da esteira têm NESTE escritório: o que o "Configurar caixa" gravou, ou o do
 * catálogo. Spec `minhas-metas-esteira-2210.md` 2.2: a etapa renomeada vale em toda a tela (chip, modais, trilho e
 * avisos), não só na esteira. Uma consulta por escritório, guardada para a requisição.
 */
final class NomesDasEtapas implements ResetInterface
{
    /** @var array<int, array<string, CaixaDaEsteira>> */
    private array $configuradas = [];

    public function __construct(
        private readonly CaixaDaEsteiraRepository $caixas,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function nome(Tenant $escritorio, EtapaDaPasta $etapa): string
    {
        return $this->caixa($escritorio, $etapa)?->getNomeDaEtapa() ?? $this->tradutor->trans($etapa->rotulo());
    }

    /** O nome da caixa, para o aviso "Nova demanda na caixa X" (04 L181: sem nome próprio, o da etapa). */
    public function nomeDaCaixa(Tenant $escritorio, EtapaDaPasta $etapa): string
    {
        return $this->caixa($escritorio, $etapa)?->getNomeDaCaixa() ?? $this->nome($escritorio, $etapa);
    }

    public function setor(EtapaDaPasta $etapa): string
    {
        return $this->tradutor->trans($etapa->setor());
    }

    public function descricao(EtapaDaPasta $etapa): string
    {
        return $this->tradutor->trans($etapa->descricao());
    }

    /** @return list<User> quem a caixa avisa quando chega demanda */
    public function avisados(Tenant $escritorio, EtapaDaPasta $etapa): array
    {
        $caixa = $this->caixa($escritorio, $etapa);

        return $caixa === null ? [] : array_values($caixa->getAvisados()->toArray());
    }

    public function reset(): void
    {
        $this->configuradas = [];
    }

    private function caixa(Tenant $escritorio, EtapaDaPasta $etapa): ?CaixaDaEsteira
    {
        $id = (int) $escritorio->getId();
        $this->configuradas[$id] ??= $this->caixas->configuradasDoEscritorio($escritorio);

        return $this->configuradas[$id][$etapa->value] ?? null;
    }
}
