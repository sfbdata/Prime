<?php

declare(strict_types=1);

namespace App\Backup\Coletor;

use App\Backup\Service\ValoresDoBackup;
use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Armazenamento\ChavesDePonto;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Ponto no backup (RISCO ALTO, identidade): SÓ o ponto da PRÓPRIA pessoa que pediu o backup, nunca o de outra, nem para
 * gestor ou primário. `dados/ponto.json` (batidas, justificativas, escala, horas pagas e comprovantes) e
 * `ponto/justificativas/<arquivo>` (atestados anexados por ela).
 *
 * Só roda para quem tem o módulo `ponto`. Toda consulta leva o `user` da pessoa E o escritório (`tenant_id`) escritos
 * na mão, como a tela dela (`PontoController::index` e `downloadAnexo`, que recusa justificativa de outra pessoa
 * em `PontoController.php:838`). As batidas vêm de {@see RegistroPontoRepository::findByUserAndCompetencia()}, as
 * EFETIVAS que a folha dela conta (com os ajustes e sem as desconsideradas), mês a mês desde a primeira batida.
 *
 * Fora: latitude, longitude e precisão do GPS, o IP e o aparelho (a folha da pessoa não os mostra) e o `hmac` dos
 * comprovantes. O banco de horas é derivado das batidas e da escala (recalculável), e não entra como número.
 */
#[AsTaggedItem(priority: 3)]
final class ColetorDoPonto implements ColetorDoBackup
{
    public const MODULO = 'ponto';

    public const MESES_POR_LOTE = 6;

    public function __construct(
        private readonly PermissionChecker $permissoes,
        private readonly RegistroPontoRepository $registros,
        private readonly EntityManagerInterface $em,
        private readonly int $mesesPorLote = self::MESES_POR_LOTE,
    ) {
    }

    public function coletar(User $usuario, Tenant $tenant, ZipDoBackup $zip): void
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO)) {
            return;
        }

        $usuarioId = (int) $usuario->getId();
        $tenantId  = (int) $tenant->getId();
        $dados     = [
            'batidas'         => $this->batidas($usuario, $tenant, $usuarioId, $tenantId, $zip),
            'justificativas'  => $this->justificativas($usuarioId, $tenantId, $zip),
            'escala'          => $this->escala($usuarioId, $tenantId),
            'horas_pagas'     => $this->horasPagas($usuarioId, $tenantId),
            'comprovantes'    => $this->comprovantes($usuarioId, $tenantId),
        ];

        $dir = $zip->reservarDiretorio('', 'dados');
        $zip->adicionarJson($dir . '/ponto.json', $dados);
    }

    /** @return list<array<string, mixed>> */
    private function batidas(User $usuario, Tenant $tenant, int $usuarioId, int $tenantId, ZipDoBackup $zip): array
    {
        $primeira = $this->registros->findDataPrimeiraBatida($usuario, $tenant);
        if ($primeira === null) {
            return [];
        }

        $linhas = [];
        $mes    = $primeira->modify('first day of this month')->setTime(0, 0);
        $fim    = new \DateTimeImmutable('first day of next month 00:00');
        $lidos  = 0;
        for (; $mes < $fim; $mes = $mes->modify('+1 month')) {
            foreach ($this->registros->findByUserAndCompetencia($usuario, $tenant, (int) $mes->format('Y'), (int) $mes->format('n')) as $batida) {
                $linhas[] = $this->linhaDaBatida($batida);
                $zip->contar('Batidas de ponto');
            }
            if (++$lidos % $this->mesesPorLote === 0) {
                // Memória do worker: solta o lote e recarrega só a pessoa e o escritório.
                $this->em->clear();
                $usuario = $this->em->find(User::class, $usuarioId) ?? throw new \LogicException('Usuário do backup sumiu.');
                $tenant  = $this->em->find(Tenant::class, $tenantId) ?? throw new \LogicException('Escritório do backup sumiu.');
            }
        }

        return $linhas;
    }

    /** @return array<string, mixed> */
    private function linhaDaBatida(MarcacaoDoPonto $b): array
    {
        return [
            'data_hora'          => ValoresDoBackup::data($b->getDataHora()),
            'tipo'               => $b->getTipo(),
            'observacao'         => $b->getObservacao(),
            'home_office'        => $b->isHomeOffice(),
            'trabalho_externo'   => $b->isTrabalhoExterno(),
            'enviada_sem_internet' => $b->isEnviadaSemInternet(),
            'feita_pelo_colaborador' => $b->feitaPeloColaborador(),
            'ajustada'           => $b->ajuste() !== null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function justificativas(int $usuarioId, int $tenantId, ZipDoBackup $zip): array
    {
        /** @var list<JustificativaPonto> $itens */
        $itens = $this->em->createQueryBuilder()
            ->select('j')->from(JustificativaPonto::class, 'j')
            ->andWhere('IDENTITY(j.user) = :usuario')->andWhere('IDENTITY(j.tenant) = :tenant')
            ->setParameter('usuario', $usuarioId)->setParameter('tenant', $tenantId)
            ->orderBy('j.data')->addOrderBy('j.id')->getQuery()->getResult();

        $dir    = null;
        $linhas = [];
        foreach ($itens as $j) {
            $anexo = $j->getAnexoPath();
            if ($anexo !== null && $anexo !== '') {
                $dir ??= $zip->reservarDiretorio($zip->reservarDiretorio('', 'ponto'), 'justificativas');
                $zip->adicionarArquivo($dir, $anexo, static fn () => ChavesDePonto::anexoDeJustificativa($j));
                $zip->contar('Atestados de ponto');
            }
            $linhas[] = [
                'data'             => ValoresDoBackup::dia($j->getData()),
                'tipo'             => $j->getTipo(),
                'situacao'         => $j->getStatus(),
                'motivo'           => $j->getMotivo(),
                'abono_parcial'    => $j->isAbonoParcial(),
                'analisada_em'     => ValoresDoBackup::data($j->getDataAnalise()),
                'observacao_da_analise' => $j->getObservacaoAnalise(),
                'anexo'            => $anexo,
            ];
            $zip->contar('Justificativas de ponto');
        }

        return $linhas;
    }

    /** @return list<array<string, mixed>> */
    private function escala(int $usuarioId, int $tenantId): array
    {
        /** @var list<JornadaColaborador> $itens */
        $itens = $this->em->createQueryBuilder()
            ->select('j')->from(JornadaColaborador::class, 'j')
            ->andWhere('IDENTITY(j.user) = :usuario')->andWhere('IDENTITY(j.tenant) = :tenant')
            ->setParameter('usuario', $usuarioId)->setParameter('tenant', $tenantId)
            ->orderBy('j.valeAPartirDe')->addOrderBy('j.id')->getQuery()->getResult();

        return array_map(static fn (JornadaColaborador $j): array => [
            'vale_a_partir_de'      => ValoresDoBackup::data($j->getValeAPartirDe()),
            'entrada_1'             => $j->getEntrada1(),
            'saida_1'               => $j->getSaida1(),
            'entrada_2'             => $j->getEntrada2(),
            'saida_2'               => $j->getSaida2(),
            'carga_horaria_diaria_minutos' => $j->getCargaHorariaDiaria(),
            'carga_horaria_semanal_minutos' => $j->getCargaHorariaSemanal(),
            'dias_da_semana'        => $j->getDiasSemana(),
        ], $itens);
    }

    /** @return list<array<string, mixed>> */
    private function horasPagas(int $usuarioId, int $tenantId): array
    {
        /** @var list<LancamentoHorasPagas> $itens */
        $itens = $this->em->createQueryBuilder()
            ->select('l')->from(LancamentoHorasPagas::class, 'l')
            ->andWhere('IDENTITY(l.user) = :usuario')->andWhere('IDENTITY(l.tenant) = :tenant')
            ->setParameter('usuario', $usuarioId)->setParameter('tenant', $tenantId)
            ->orderBy('l.ano')->addOrderBy('l.mes')->addOrderBy('l.id')->getQuery()->getResult();

        return array_map(static fn (LancamentoHorasPagas $l): array => ['ano' => $l->getAno(), 'mes' => $l->getMes(), 'minutos' => $l->getMinutos(), 'motivo' => $l->getMotivo()], $itens);
    }

    /** @return list<array<string, mixed>> */
    private function comprovantes(int $usuarioId, int $tenantId): array
    {
        /** @var list<ComprovanteDoPonto> $itens */
        $itens = $this->em->createQueryBuilder()
            ->select('c')->from(ComprovanteDoPonto::class, 'c')
            ->andWhere('IDENTITY(c.user) = :usuario')->andWhere('IDENTITY(c.tenant) = :tenant')
            ->setParameter('usuario', $usuarioId)->setParameter('tenant', $tenantId)
            ->orderBy('c.nsr')->getQuery()->getResult();

        return array_map(static fn (ComprovanteDoPonto $c): array => ['nsr' => $c->getNsr(), 'codigo' => $c->getCodigo(), 'emitido_em' => ValoresDoBackup::data($c->getEmitidoEm())], $itens);
    }
}
