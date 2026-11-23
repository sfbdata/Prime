<?php
declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\JornadaTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\TextoDoPonto;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/tenant')]
final class JornadaTenantController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Dois salvamentos da mesma jornada ao mesmo tempo: o segundo bate no índice único (spec seguranca-b-jornada). */
    public const JORNADA_ALTERADA_AO_MESMO_TEMPO = 'Esta jornada acabou de ser alterada; recarregue e tente de novo.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
        private readonly TranslatorInterface $tradutor,
    ) {}

    #[Route('/{id}/jornada', name: 'app_jornada_tenant_get', methods: ['GET'])]
    public function get(
        Tenant $tenant,
        PermissionChecker $permissionChecker,
        JornadaTenantRepository $jornadaTenantRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $tenantCtx    = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenantCtx?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenantCtx, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para configurar jornada.');
        }
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        $jornada = $jornadaTenantRepository->findOneBy(['tenant' => $tenant]);

        if ($jornada === null) {
            return $this->json([
                'cargaHorariaSemanal'             => 2400,
                'alertaHabilitado'                => true,
                'validacaoRepousoHabilitada'      => true,
                'minimoMinutosRepouso'            => 60,
                'validacaoInterjornadaHabilitada' => true,
                'minimoMinutosInterjornada'       => 660,
                'blocos'                          => [],
            ]);
        }

        return $this->json([
            'cargaHorariaSemanal'             => $jornada->getCargaHorariaSemanal(),
            'alertaHabilitado'                => $jornada->isAlertaHabilitado(),
            'validacaoRepousoHabilitada'      => $jornada->isValidacaoRepousoHabilitada(),
            'minimoMinutosRepouso'            => $jornada->getMinimoMinutosRepouso(),
            'validacaoInterjornadaHabilitada' => $jornada->isValidacaoInterjornadaHabilitada(),
            'minimoMinutosInterjornada'       => $jornada->getMinimoMinutosInterjornada(),
            'blocos'                          => array_map(
                fn(BlocoJornada $b) => [
                    'diasSemana'   => $b->getDiasSemana(),
                    'entrada'      => $b->getEntrada(),
                    'repouso'      => $b->getRepouso(),
                    'retorno'      => $b->getRetorno(),
                    'saida'        => $b->getSaida(),
                    'minutosBloco' => $b->getMinutosBloco(),
                ],
                $jornada->getBlocos()->toArray()
            ),
        ]);
    }

    #[Route('/{id}/jornada', name: 'app_jornada_tenant_save', methods: ['POST'])]
    public function save(
        Tenant $tenant,
        Request $request,
        PermissionChecker $permissionChecker,
        JornadaTenantRepository $jornadaTenantRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
        $tenantCtx    = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenantCtx?->getId() === $tenant->getId();

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($user, $tenantCtx, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para configurar jornada.');
        }
        $this->escoparFiltroNoTenant($entityManager, $tenant);

        // CSRF após os guards de autorização (a falha de autz/tenant tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.comum.payload_invalido')], Response::HTTP_BAD_REQUEST);
        }

        $carga              = (int) ($data['cargaHorariaSemanal'] ?? 0);
        $alerta             = (bool) ($data['alertaHabilitado'] ?? true);
        $blocos             = $data['blocos'] ?? [];
        $validaRepouso      = (bool) ($data['validacaoRepousoHabilitada'] ?? true);
        $minimoRepouso      = (int) ($data['minimoMinutosRepouso'] ?? 60);
        $validaInterjornada = (bool) ($data['validacaoInterjornadaHabilitada'] ?? true);
        $minimoInterjornada = (int) ($data['minimoMinutosInterjornada'] ?? 660);

        if ($carga < 60 || $carga > 2640) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.jornada.carga_semanal')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($minimoRepouso < 1 || $minimoRepouso > 480) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.jornada.repouso_minimo')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($minimoInterjornada < 60 || $minimoInterjornada > 1440) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.jornada.interjornada_minima')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_array($blocos) || count($blocos) > 7) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.jornada.max_blocos')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $erros = $this->validarBlocos($blocos, $carga);
        if ($erros !== null) {
            return $this->json(['erro' => $erros], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $afetadas = $this->pessoasEmChoque($tenant, $jornadaTenantRepository->doEscritorio($tenant), $blocos);
        if ($afetadas !== []) {
            $traduzidas = array_map(fn (array $p): array => ['nome' => $p['nome'], 'mensagem' => $p['texto']->traduzir($this->tradutor)], $afetadas);

            return $this->json(['erro' => $this->recusaDasPessoas($traduzidas), 'pessoas' => $traduzidas], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $jornada = $jornadaTenantRepository->findOneBy(['tenant' => $tenant]);

        if ($jornada === null) {
            $jornada = new JornadaTenant();
            $jornada->setTenant($tenant);
            $entityManager->persist($jornada);
        }

        $jornada->setCargaHorariaSemanal($carga);
        $jornada->setAlertaHabilitado($alerta);
        $jornada->setValidacaoRepousoHabilitada($validaRepouso);
        $jornada->setMinimoMinutosRepouso($minimoRepouso);
        $jornada->setValidacaoInterjornadaHabilitada($validaInterjornada);
        $jornada->setMinimoMinutosInterjornada($minimoInterjornada);

        // orphanRemoval cuida da deleção dos blocos antigos
        foreach ($jornada->getBlocos()->toArray() as $blocoAntigo) {
            $jornada->removeBloco($blocoAntigo);
        }

        foreach ($blocos as $blocoData) {
            $jornada->addBloco($this->blocoDaRequisicao($blocoData));
        }

        return $this->gravarJornada($entityManager);   // dois salvamentos ao mesmo tempo: 409, não 500
    }

    /**
     * As pessoas do escritório com o ponto ligado para quem a padrão proposta bate com o horário de outro escritório
     * (spec seguranca-b-jornada §3.3). Quem tem jornada pessoal em blocos não usa a padrão.
     *
     * @param array<int, array<string, mixed>> $blocos
     *
     * @return list<array{nome: string, texto: TextoDoPonto}>
     */
    private function pessoasEmChoque(Tenant $tenant, ?JornadaTenant $atual, array $blocos): array
    {
        $proposta = ($atual ?? (new JornadaTenant())->setTenant($tenant))
            ->propostaComBlocos(array_map($this->blocoDaRequisicao(...), $blocos));

        return $this->choque->pessoasEmChoqueComPadraoComChave($tenant, $proposta);
    }

    /** @param list<array{nome: string, mensagem: string}> $afetadas */
    private function recusaDasPessoas(array $afetadas): string
    {
        $linhas = array_map(static fn (array $p): string => $p['nome'] . ': ' . $p['mensagem'], $afetadas);

        return ChoqueDeJornadaEntreEscritorios::texto(ChoqueDeJornadaEntreEscritorios::MENSAGEM_PADRAO)->traduzir($this->tradutor) . "\n" . implode("\n", $linhas);
    }

    /** @param array<string, mixed> $blocoData */
    private function blocoDaRequisicao(array $blocoData): BlocoJornada
    {
        $bloco = new BlocoJornada();
        $bloco->setDiasSemana(array_map('intval', (array) ($blocoData['diasSemana'] ?? [])));
        $bloco->setEntrada((string) ($blocoData['entrada'] ?? '09:00'));
        $bloco->setRepouso(($blocoData['repouso'] ?? '') !== '' ? (string) $blocoData['repouso'] : null);
        $bloco->setRetorno(($blocoData['retorno'] ?? '') !== '' ? (string) $blocoData['retorno'] : null);
        $bloco->setSaida((string) ($blocoData['saida'] ?? '18:00'));
        $bloco->setMinutosBloco($this->calcularMinutosBloco($bloco));

        return $bloco;
    }

    /**
     * Fixa o TenantFilter no escritório da URL, como `HorasPagasController::escoparFiltroNoTenant`. A rota é `/{id}`,
     * não `{tenantId}`, e o `TenantUrlScopeListener` não a alcança: o super admin com a sessão em outro escritório lia
     * a padrão do escritório da URL como inexistente (a tela vinha vazia) e, ao salvar, tentava criar uma segunda,
     * que batia no índice único. Chamar depois do guard de acesso e antes de tocar em entidade TenantAware.
     */
    private function escoparFiltroNoTenant(EntityManagerInterface $entityManager, Tenant $tenant): void
    {
        $entityManager->getFilters()->enable('tenant')->setParameter('tenant', (int) $tenant->getId(), Types::INTEGER);
    }

    private function gravarJornada(EntityManagerInterface $entityManager): JsonResponse
    {
        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->json(['erro' => $this->tradutor->trans('ponto_servidor.comum.jornada_alterada')], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    private function validarBlocos(array $blocos, int $cargaSemanal): ?string
    {
        $diasUsados = [];
        $totalMinutos = 0;

        foreach ($blocos as $i => $b) {
            $dias = array_map('intval', (array) ($b['diasSemana'] ?? []));

            if (empty($dias)) {
                return $this->tradutor->trans('ponto_servidor.jornada.bloco_sem_dias', ['n' => $i + 1]);
            }

            foreach ($dias as $dia) {
                if ($dia < 1 || $dia > 7) {
                    return $this->tradutor->trans('ponto_servidor.jornada.dia_invalido', ['n' => $i + 1, 'dia' => $dia]);
                }
                if (in_array($dia, $diasUsados, true)) {
                    return $this->tradutor->trans('ponto_servidor.jornada.dia_repetido', ['dia' => $dia]);
                }
                $diasUsados[] = $dia;
            }

            $bloco = new BlocoJornada();
            $bloco->setEntrada((string) ($b['entrada'] ?? '09:00'));
            $bloco->setRepouso($b['repouso'] !== '' ? (string) ($b['repouso'] ?? null) : null);
            $bloco->setRetorno($b['retorno'] !== '' ? (string) ($b['retorno'] ?? null) : null);
            $bloco->setSaida((string) ($b['saida'] ?? '18:00'));

            $minutos = $this->calcularMinutosBloco($bloco);

            if ($minutos <= 0) {
                return $this->tradutor->trans('ponto_servidor.jornada.bloco_carga_invalida', ['n' => $i + 1]);
            }

            $totalMinutos += $minutos * count($dias);
        }

        if (!empty($blocos) && $totalMinutos !== $cargaSemanal) {
            return $this->tradutor->trans('ponto_servidor.jornada.soma_blocos', [
                'soma' => sprintf('%dh%02dm', intdiv($totalMinutos, 60), $totalMinutos % 60),
                'carga' => sprintf('%dh%02dm', intdiv($cargaSemanal, 60), $cargaSemanal % 60),
            ]);
        }

        return null;
    }

    private function calcularMinutosBloco(BlocoJornada $bloco): int
    {
        [$hE, $mE] = array_map('intval', explode(':', $bloco->getEntrada()));
        [$hS, $mS] = array_map('intval', explode(':', $bloco->getSaida()));

        $total = ($hS * 60 + $mS) - ($hE * 60 + $mE);

        if ($bloco->getRepouso() !== null && $bloco->getRetorno() !== null) {
            [$hR, $mR] = array_map('intval', explode(':', $bloco->getRepouso()));
            [$hRt, $mRt] = array_map('intval', explode(':', $bloco->getRetorno()));
            $total -= ($hRt * 60 + $mRt) - ($hR * 60 + $mR);
        }

        return max(0, $total);
    }
}
