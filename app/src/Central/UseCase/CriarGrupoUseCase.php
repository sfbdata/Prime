<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\GrupoOutput;
use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\ConversaRepository;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisoDeGrupoNaCentral;
use App\Central\Service\MensagensDeSistemaDoGrupo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Criar grupo (`docs/specs/central-comunicacao-b.md` §2): quem tem vínculo ativo dá nome e escolhe de 1 a 99 colegas
 * com vínculo ativo NO MESMO escritório; vira o administrador do grupo. Cada escolhido ganha o aviso "adicionou você"
 * e a conversa abre com a linha de sistema "Ana criou o grupo" — tudo numa transação.
 */
final class CriarGrupoUseCase
{
    public const MAXIMO_DE_OUTROS = 99;

    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly ConversaRepository $conversas,
        private readonly ParticipanteRepository $participantes,
        private readonly MensagensDeSistemaDoGrupo $sistema,
        private readonly AvisoDeGrupoNaCentral $aviso,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<int> $participanteIds
     *
     * @throws SemAcessoACentralException
     * @throws PedidoInvalidoNaCentralException
     */
    public function executar(User $eu, ?Tenant $tenant, string $nome, array $participanteIds): GrupoOutput
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $ids = array_values(array_unique(array_filter($participanteIds, static fn (int $id): bool => $id > 0 && $id !== $eu->getId())));
        if ($ids === []) {
            throw new PedidoInvalidoNaCentralException('Escolha ao menos um participante.');
        }
        if (count($ids) > self::MAXIMO_DE_OUTROS) {
            throw new PedidoInvalidoNaCentralException(sprintf('Um grupo tem no máximo %d participantes além de você.', self::MAXIMO_DE_OUTROS));
        }

        $colegas = $this->acesso->colaboradoresAtivos($tenant);
        $escolhidos = [];
        foreach ($ids as $id) {
            $escolhidos[] = $colegas[$id] ?? throw new PedidoInvalidoNaCentralException('Participante não encontrado neste escritório.');
        }

        $agora = $this->clock->now();
        try {
            $grupo = Conversa::grupo($tenant, $eu, $nome, $agora);
        } catch (\DomainException $e) {
            throw new PedidoInvalidoNaCentralException($e->getMessage());
        }

        $this->entityManager->wrapInTransaction(function () use ($grupo, $tenant, $eu, $escolhidos, $agora): void {
            $this->conversas->salvar($grupo);
            $this->participantes->salvar(new Participante($tenant, $grupo, $eu, $agora));
            foreach ($escolhidos as $pessoa) {
                $this->participantes->salvar(new Participante($tenant, $grupo, $pessoa, $agora));
            }
            $this->sistema->criou($grupo, $eu, $tenant, $agora);
            $this->entityManager->flush();   // o id do grupo é o link do aviso
            foreach ($escolhidos as $pessoa) {
                $this->aviso->avisarAdicionado($grupo, $eu, $tenant, $pessoa);
            }
            $this->entityManager->flush();
        });

        return new GrupoOutput((int) $grupo->getId(), (string) $grupo->getNome());
    }
}
