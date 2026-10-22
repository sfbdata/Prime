<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\PrazosDoPush;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Depois de cada captação do DJEN (docs/specs/prazo-do-push.md §3): as pastas vivas do escritório cujo processo
 * recebeu publicação nova têm os prazos do Push acertados ({@see PrazosDoPush}), num flush só.
 *
 * Quem dispara: a captação (botão do módulo, "Buscar agora" da pasta e o comando diário), pelo
 * {@see \App\Pasta\Service\AvisoDoPushNaCaptacao}. Tenant explícito: roda no CLI, sem o filtro de tenant. Só entram
 * as publicações vinculadas a um processo DO escritório e as pastas DELE, fora da lixeira.
 */
final class SincronizarPrazosDoPushUseCase
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaRepository $pastas,
        private readonly PrazosDoPush $prazos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $djenIdsNovos as publicações que a captação acabou de criar
     *
     * @return int quantas pastas tiveram prazo criado ou apagado
     */
    public function executar(Tenant $tenant, array $djenIdsNovos): int
    {
        $mudaram = 0;
        foreach ($this->pastasDasNovas($tenant, $djenIdsNovos) as $pasta) {
            $r = $this->prazos->sincronizar($pasta);
            $mudaram += $r['criados'] + $r['apagados'] > 0 ? 1 : 0;
        }
        if ($mudaram > 0) {
            $this->em->flush();
        }

        return $mudaram;
    }

    /**
     * Cada pasta uma vez: dois processos da mesma pasta com publicação nova não podem sincronizá-la duas vezes antes
     * do flush (a segunda não veria as linhas da primeira e duplicaria).
     *
     * @param list<string> $djenIdsNovos
     *
     * @return array<int, Pasta>
     */
    private function pastasDasNovas(Tenant $tenant, array $djenIdsNovos): array
    {
        $publicacoes = $this->publicacoes->listarVinculadasPorDjenIdsDoTenant($tenant, $djenIdsNovos);
        if ($publicacoes === []) {
            return [];
        }

        $processoIds = array_map(static fn (PublicacaoDjen $p): int => (int) $p->getProcesso()?->getId(), $publicacoes);
        $pastas = [];
        foreach ($this->pastas->listarVinculosDePastasVivasDosProcessos($tenant, $processoIds) as $vinculo) {
            $pasta = $vinculo->getPasta();
            $pastas[(int) $pasta->getId()] = $pasta;
        }

        return $pastas;
    }
}
