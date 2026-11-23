<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Agenda\Entity\Evento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\EventoRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Agenda no backup: `dados/agenda.json` (compromissos e prazos).
 *
 * Só roda para quem tem o módulo `agenda`. Compromissos = os do escritório (`tenant_id` escrito na mão) que a pessoa vê:
 * a regra única da tela, {@see EventoRepository::restringirAoVisivelPara()} (só ela; criou, participa ou "Para todos"
 * sem participantes), que recebe o `User` e não lê a sessão. Sem filtro de período: vão todos, com a recorrência.
 * Prazos e audiências das pastas só com a permissão "Ver prazos na agenda" ({@see PermissoesDaAgenda::verPrazos()}),
 * como a grade (`ListarCompromissosUseCase::dosPrazos`), de pasta fora da lixeira e NÃO oculta para a pessoa
 * ({@see AcessoAPasta::idsDasPastasOcultas()}, cadeado e escopo do acesso restrito). O NUP da pasta de um compromisso
 * só vai com o módulo Pastas e se a pasta não está oculta.
 *
 * Nunca entram: token, conexão ou id externo do Google (`ConexaoGoogleAgenda`, `LigacaoComGoogle`), inscrições de push
 * nem os aniversários da equipe (dado de perfil dos colegas, não da agenda da pessoa).
 */
#[AsTaggedItem(priority: 6)]
final class ColetorDaAgenda implements ColetorDoBackup
{
    public const MODULO = 'agenda';

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly PermissoesDaAgenda $daAgenda,
        private readonly AcessoAPasta $acesso,
        private readonly EventoRepository $eventos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $ocultas = $this->acesso->idsDasPastasOcultas($usuario, $tenant);
        $verNup  = $this->daAgenda->verPastas($usuario, $tenant);

        $compromissos = [];
        foreach ($this->compromissos($usuario, $tenant) as $evento) {
            $compromissos[] = $this->dadosDoCompromisso($evento, $verNup, $ocultas);
            $zip->contar('Compromissos da agenda');
        }

        $prazos = [];
        if ($this->daAgenda->verPrazos($usuario, $tenant)) {
            foreach ($this->prazos($tenant, $ocultas) as $prazo) {
                $prazos[] = $this->dadosDoPrazo($prazo);
                $zip->contar('Prazos da agenda');
            }
        }

        $dir = $zip->diretorioDeDados();
        $zip->adicionarJson($dir . '/agenda.json', ['compromissos' => $compromissos, 'prazos' => $prazos]);
    }

    /** @return list<Evento> */
    private function compromissos(User $usuario, Tenant $tenant): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('e')->from(Evento::class, 'e')
            ->andWhere('e.tenant = :tenant')->setParameter('tenant', $tenant)
            ->orderBy('e.dataInicio')->addOrderBy('e.id');
        $this->eventos->restringirAoVisivelPara($qb, $usuario);

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * @param list<int> $pastasOcultas
     *
     * @return list<PastaPrazo>
     */
    private function prazos(Tenant $tenant, array $pastasOcultas): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('z', 'p')->from(PastaPrazo::class, 'z')
            ->join('z.pasta', 'p')
            ->andWhere('z.tenant = :tenant')->andWhere('p.tenant = :tenant')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('z.data')->addOrderBy('z.hora')->addOrderBy('z.id');
        if ($pastasOcultas !== []) {
            $qb->andWhere('p.id NOT IN (:ocultas)')->setParameter('ocultas', $pastasOcultas);
        }

        return array_values($qb->getQuery()->getResult());
    }

    /**
     * @param list<int> $pastasOcultas
     *
     * @return array<string, mixed>
     */
    private function dadosDoCompromisso(Evento $evento, bool $verNup, array $pastasOcultas): array
    {
        $pasta = $evento->getPasta();

        return [
            'titulo'             => $evento->getTitulo(),
            'descricao'          => $evento->getDescricao(),
            'local'              => $evento->getLocal(),
            'inicio'             => ValoresDoBackup::data($evento->getDataInicio()),
            'fim'                => ValoresDoBackup::data($evento->getDataFim()),
            'dia_inteiro'        => $evento->isDiaInteiro(),
            'status'             => $evento->getStatus(),
            'tipo'               => $evento->getTipo()?->value,
            'cor'                => $evento->getCor(),
            'visibilidade'       => $evento->getVisibilidade(),
            'recorrente'         => $evento->isRecorrente(),
            'tipo_recorrencia'   => $evento->getTipoRecorrencia(),
            'fim_recorrencia'    => ValoresDoBackup::data($evento->getFimRecorrencia()),
            'lembrete_minutos'   => $evento->getLembreteMinutos(),
            'criador'            => $evento->getCriador()?->getFullName(),
            'participantes'      => array_values(array_map(static fn (User $u): ?string => $u->getFullName(), $evento->getParticipantes()->toArray())),
            'pasta'              => $this->nupDaPasta($pasta, $verNup, $pastasOcultas),
            'criado_em'          => ValoresDoBackup::data($evento->getCriadoEm()),
        ];
    }

    /** @param list<int> $pastasOcultas */
    private function nupDaPasta(?Pasta $pasta, bool $verNup, array $pastasOcultas): ?string
    {
        if ($pasta === null || !$verNup || in_array((int) $pasta->getId(), $pastasOcultas, true) || $pasta->getExcluidaEm() !== null) {
            return null;
        }

        return $pasta->getNup();
    }

    /** @return array<string, mixed> */
    private function dadosDoPrazo(PastaPrazo $prazo): array
    {
        return [
            'tipo'        => $prazo->getTipo(),
            'titulo'      => $prazo->getTitulo(),
            'data'        => ValoresDoBackup::dia($prazo->getData()),
            'hora'        => $prazo->getHora(),
            'responsavel' => $prazo->getResponsavel(),
            'cumprido_em' => ValoresDoBackup::data($prazo->getCumpridoEm()),
            'pasta'       => $prazo->getPasta()->getNup(),
        ];
    }
}
