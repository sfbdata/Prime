<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\DTO\AlterarRegistroDePontoInput;
use App\Ponto\DTO\VersaoDaJornadaInput;
use App\Ponto\Exception\ChoqueDeJornadaException;
use App\Ponto\Exception\VersaoDaJornadaNaoEncontradaException;
use App\Ponto\Exception\VersaoDaJornadaRecusadaException;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\UseCase\AlterarRegistroDePontoUseCase;
use App\Ponto\UseCase\ExcluirVersaoDaJornadaUseCase;
use App\Ponto\UseCase\LimparJornadaPessoalUseCase;
use App\Ponto\UseCase\SalvarVersaoDaJornadaUseCase;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/tenant/{tenantId}/user/{id}')]
final class JornadaColaboradorController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Dois salvamentos da mesma jornada ao mesmo tempo: o segundo bate no índice único (spec seguranca-b-jornada). */
    public const JORNADA_ALTERADA_AO_MESMO_TEMPO = 'Esta jornada acabou de ser alterada; recarregue e tente de novo.';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly JornadaColaboradorRepository $jornadas,
        private readonly JornadaTenantRepository $padroes,
        private readonly IntervalosDeTrabalho $intervalos,
        private readonly ChoqueDeJornadaEntreEscritorios $choque,
        private readonly ClockInterface $relogio,
    ) {}

    #[Route('/jornada-colaborador', name: 'app_jornada_colaborador_get', methods: ['GET'])]
    public function get(
        int $tenantId,
        User $user,
        PermissionChecker $permissionChecker
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $tenant       = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenant?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar jornadas.');
        }

        // O usuário-alvo precisa pertencer ao tenant da URL — vale também para super-admin
        // (escopo pela URL, consistente com as rotas admin de ponto).
        $urlTenant = $this->tenantRepository->find($tenantId);
        if ($urlTenant === null || !$this->userTenantRepository->existeVinculoAtivo($user, $urlTenant)) {
            throw $this->createNotFoundException('Usuário não pertence a este tenant.');
        }

        $hoje          = $this->relogio->now();
        $historico     = $this->jornadas->historicoDe($user, $urlTenant);
        $jornada       = $historico?->vigenteEm($hoje);
        $jornadaTenant = $urlTenant->getJornadaTenant();

        $blocosTenant = $jornadaTenant !== null ? $this->blocosEmLista($jornadaTenant->getBlocos()) : [];

        $cargaUsuario = $jornada?->getCargaHorariaSemanal();
        $cargaEfetiva = $cargaUsuario ?? $jornadaTenant?->getCargaHorariaSemanal() ?? 2400;

        $temBlocosUsuario = $jornada !== null && !$jornada->getBlocos()->isEmpty();
        $temCamposLegados = $jornada !== null && !$temBlocosUsuario && (
            $jornada->getEntrada1() !== null || $jornada->getSaida1() !== null
        );

        $blocosUsuario = $temBlocosUsuario ? $this->blocosEmLista($jornada->getBlocos()) : [];

        return $this->json([
            'hoje'                 => $hoje->format('Y-m-d'),
            'versaoVigenteId'      => $jornada?->getId(),
            'versoes'              => $this->versoesEmLista($historico, $jornada, $hoje),
            'cargaHorariaSemanal'  => $cargaEfetiva,
            'alertaHabilitado'     => $jornada?->isAlertaHabilitado() ?? true,
            'blocos'               => $blocosUsuario,
            'temBlocosUsuario'     => $temBlocosUsuario,
            'temCamposLegados'     => $temCamposLegados,
            'jornadaTenant'        => [
                'cargaHorariaSemanal' => $jornadaTenant?->getCargaHorariaSemanal(),
                'alertaHabilitado'    => $jornadaTenant?->isAlertaHabilitado() ?? true,
                'blocos'              => $blocosTenant,
            ],
        ]);
    }

    #[Route('/jornada-colaborador', name: 'app_jornada_colaborador_save', methods: ['POST'])]
    public function save(
        int $tenantId,
        User $user,
        Request $request,
        PermissionChecker $permissionChecker,
        SalvarVersaoDaJornadaUseCase $salvarVersao,
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $tenant       = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenant?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar jornadas.');
        }

        // O usuário-alvo precisa pertencer ao tenant da URL — vale também para super-admin
        // (escopo pela URL, consistente com as rotas admin de ponto).
        $urlTenant = $this->tenantRepository->find($tenantId);
        if ($urlTenant === null || !$this->userTenantRepository->existeVinculoAtivo($user, $urlTenant)) {
            throw $this->createNotFoundException('Usuário não pertence a este tenant.');
        }

        // CSRF após os guards de autorização (a falha de autz/tenant tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $carga  = (int) ($data['cargaHorariaSemanal'] ?? 0);
        $alerta = (bool) ($data['alertaHabilitado'] ?? true);
        $blocos = $data['blocos'] ?? [];

        if ($carga < 60 || $carga > 2640) {
            return $this->json(['erro' => 'Carga horária semanal deve estar entre 1h e 44h.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_array($blocos) || count($blocos) > 7) {
            return $this->json(['erro' => 'Máximo de 7 blocos de jornada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $erros = $this->validarBlocos($blocos, $carga);
        if ($erros !== null) {
            return $this->json(['erro' => $erros], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Spec ponto-jornada-vigencia §10.2: sem a data, a nova versão vale a partir de hoje; editar exige a data.
        $versaoId      = $data['versaoId'] ?? null;
        $valeAPartirDe = $this->dataDaRequisicao($data['valeAPartirDe'] ?? null, $versaoId === null);
        if ($valeAPartirDe === null) {
            return $this->json(['erro' => 'Informe a data em que a jornada passa a valer (dd/mm/aaaa).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($versaoId !== null && (!is_int($versaoId) || $versaoId < 1)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $blocosPropostos = array_map($this->blocoDaRequisicao(...), $blocos);
        $historico       = $this->jornadas->historicoDe($user, $urlTenant);
        if (($choque = $this->choqueDaProposta($user, $urlTenant, $historico?->vigenteEm($valeAPartirDe), $blocosPropostos)) !== null) {
            return $this->json(['erro' => $choque], Response::HTTP_UNPROCESSABLE_ENTITY);   // regra 3: quem marcou primeiro fica
        }
        // Levar a versão de hoje para uma data futura deixa valendo hoje a anterior: ela também passa pela conferência.
        $hoje        = $this->relogio->now();
        $vigenteHoje = $historico?->vigenteEm($hoje);
        if ($historico !== null && $vigenteHoje !== null && $vigenteHoje->getId() === $versaoId
            && $valeAPartirDe->format('Y-m-d') > $hoje->format('Y-m-d')
            && ($choque = $this->choqueDoQuePassaAValerHoje($user, $urlTenant, $historico, $vigenteHoje)) !== null) {
            return $this->json(['erro' => $choque], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $salvarVersao->executar($user, $urlTenant, new VersaoDaJornadaInput($valeAPartirDe, $carga, $alerta, $blocosPropostos, $versaoId));
        } catch (VersaoDaJornadaNaoEncontradaException $naoEncontrada) {
            return $this->json(['erro' => $naoEncontrada->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (VersaoDaJornadaRecusadaException $recusada) {
            return $this->json(['erro' => $recusada->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Dois salvamentos ao mesmo tempo na mesma data: 409, não 500.
            return $this->json(['erro' => self::JORNADA_ALTERADA_AO_MESMO_TEMPO], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/jornada-colaborador', name: 'app_jornada_colaborador_delete', methods: ['DELETE'])]
    public function delete(
        int $tenantId,
        User $user,
        Request $request,
        PermissionChecker $permissionChecker,
        LimparJornadaPessoalUseCase $limparJornada,
    ): JsonResponse {
        $currentUser = $this->getUser();

        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $tenant       = $this->tenantContext->getCurrentTenant();
        $isOwnTenant  = $tenant?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar jornadas.');
        }

        // O usuário-alvo precisa pertencer ao tenant da URL — vale também para super-admin
        // (escopo pela URL, consistente com as rotas admin de ponto).
        $urlTenant = $this->tenantRepository->find($tenantId);
        if ($urlTenant === null || !$this->userTenantRepository->existeVinculoAtivo($user, $urlTenant)) {
            throw $this->createNotFoundException('Usuário não pertence a este tenant.');
        }

        // CSRF após os guards de autorização (a falha de autz/tenant tem prioridade e fica testável).
        $this->validarCsrfAjax($request);

        // Spec ponto-jornada-vigencia §10.2.5: apaga todas as versões; a folha volta a usar a padrão.
        $vigenteHoje = $this->jornadas->findByUserETenant($user, $urlTenant);
        if ($vigenteHoje !== null && ($choque = $this->choqueDaProposta($user, $urlTenant, $vigenteHoje, [])) !== null) {
            return $this->json(['erro' => $choque], Response::HTTP_UNPROCESSABLE_ENTITY);   // sem a pessoal vale a padrão
        }

        $limparJornada->executar($user, $urlTenant);

        return $this->json(['ok' => true]);
    }

    /**
     * Exclui uma versão do histórico (spec ponto-jornada-vigencia §10.2.4): os dias dela passam para a versão vizinha.
     * Quando a excluída é a que vale hoje, a que passa a valer hoje passa pela conferência de choque entre escritórios.
     */
    #[Route('/jornada-colaborador/versao/{versaoId}', name: 'app_jornada_colaborador_versao_delete', requirements: ['versaoId' => '\d+'], methods: ['DELETE'])]
    public function excluirVersao(
        int $tenantId,
        User $user,
        int $versaoId,
        Request $request,
        PermissionChecker $permissionChecker,
        ExcluirVersaoDaJornadaUseCase $excluirVersao,
    ): JsonResponse {
        $urlTenant = $this->exigirAdminDoEscritorioDaPessoa($tenantId, $user, $permissionChecker);
        $this->validarCsrfAjax($request);

        $historico   = $this->jornadas->historicoDe($user, $urlTenant);
        $vigenteHoje = $historico?->vigenteEm($this->relogio->now());
        if ($historico !== null && $vigenteHoje !== null && $vigenteHoje->getId() === $versaoId
            && ($choque = $this->choqueDoQuePassaAValerHoje($user, $urlTenant, $historico, $vigenteHoje)) !== null) {
            return $this->json(['erro' => $choque], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $excluirVersao->executar($user, $urlTenant, $versaoId);
        } catch (VersaoDaJornadaNaoEncontradaException $naoEncontrada) {
            return $this->json(['erro' => $naoEncontrada->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (VersaoDaJornadaRecusadaException $recusada) {
            return $this->json(['erro' => $recusada->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true]);
    }

    /**
     * Liga ou desliga a chave "Registra ponto eletrônico" da pessoa NESTE escritório (spec seguranca-b-jornada, regras 2
     * e 3). Mesma guarda da jornada: `admin.users.manage` no escritório da sessão (ou Master) e vínculo ativo da pessoa
     * no escritório da URL. Ligar recusa com 422 quando a jornada daqui bate com outro escritório.
     */
    #[Route('/registra-ponto', name: 'app_jornada_colaborador_registra_ponto', methods: ['POST'])]
    public function registraPonto(
        int $tenantId,
        User $user,
        Request $request,
        PermissionChecker $permissionChecker,
        AlterarRegistroDePontoUseCase $alterarRegistroDePonto,
    ): JsonResponse {
        $urlTenant = $this->exigirAdminDoEscritorioDaPessoa($tenantId, $user, $permissionChecker);
        $this->validarCsrfAjax($request);

        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data) || !is_bool($data['registraPonto'] ?? null)) {
            return $this->json(['erro' => 'Payload inválido.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $alterarRegistroDePonto->executar(new AlterarRegistroDePontoInput($user, $urlTenant, $data['registraPonto']));
        } catch (ChoqueDeJornadaException $choque) {
            return $this->json(['erro' => $choque->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'registraPonto' => $data['registraPonto']]);
    }

    /** A guarda das rotas da página do colaborador: devolve o escritório da URL, onde a pessoa tem vínculo ativo. */
    private function exigirAdminDoEscritorioDaPessoa(int $tenantId, User $user, PermissionChecker $permissionChecker): Tenant
    {
        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $tenant = $this->tenantContext->getCurrentTenant();
        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant = $tenant?->getId() === $tenantId;
        if (!$isSuperAdmin && !($isOwnTenant && $permissionChecker->canAdminister($currentUser, $tenant, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Sem permissão para gerenciar o ponto.');
        }
        $urlTenant = $this->tenantRepository->find($tenantId);
        if ($urlTenant === null || !$this->userTenantRepository->existeVinculoAtivo($user, $urlTenant)) {
            throw $this->createNotFoundException('Usuário não pertence a este tenant.');
        }

        return $urlTenant;
    }

    /**
     * O choque da jornada proposta com outro escritório onde a pessoa registra ponto (spec seguranca-b-jornada §3.3).
     * Só conta quando a chave do ponto dela está ligada neste escritório: desligada, a jornada daqui não marca horário.
     *
     * @param list<BlocoJornadaColaborador> $blocos os blocos propostos; vazio é "sem jornada pessoal em blocos"
     */
    private function choqueDaProposta(User $user, Tenant $tenant, ?JornadaColaborador $atual, array $blocos): ?string
    {
        if ($this->userTenantRepository->findAtivoPorUserETenant($user, $tenant)?->isRegistraPonto() !== true) {
            return null;
        }
        $proposta = ($atual ?? new JornadaColaborador())->propostaComBlocos($blocos);

        return $this->choque->mensagem($user, $tenant, $this->intervalos->daJornada($proposta, $this->padroes->doEscritorio($tenant)));
    }

    /** Sem a versão de hoje (excluída ou levada para frente), vale hoje a vizinha, ou a padrão: ela não pode bater com outro escritório. */
    private function choqueDoQuePassaAValerHoje(User $user, Tenant $tenant, HistoricoDeJornadas $historico, JornadaColaborador $sai): ?string
    {
        $passaAValer = $historico->semA($sai)?->vigenteEm($this->relogio->now());

        return $this->choqueDaProposta($user, $tenant, $passaAValer, $passaAValer?->getBlocos()->toArray() ?? []);
    }

    /** @param array<string, mixed> $blocoData */
    private function blocoDaRequisicao(array $blocoData): BlocoJornadaColaborador
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana(array_map('intval', (array) ($blocoData['diasSemana'] ?? [])));
        $bloco->setEntrada((string) ($blocoData['entrada'] ?? '09:00'));
        $bloco->setRepouso(($blocoData['repouso'] ?? '') !== '' ? (string) $blocoData['repouso'] : null);
        $bloco->setRetorno(($blocoData['retorno'] ?? '') !== '' ? (string) $blocoData['retorno'] : null);
        $bloco->setSaida((string) ($blocoData['saida'] ?? '18:00'));
        $bloco->setMinutosBloco($this->calcularMinutosBloco($bloco));

        return $bloco;
    }

    /** `Y-m-d` válido entre 2000 e 2099 vira a data; ausente vira hoje quando `$hojeSeAusente`; o resto, `null`. */
    private function dataDaRequisicao(mixed $valor, bool $hojeSeAusente): ?\DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return $hojeSeAusente ? $this->relogio->now()->setTime(0, 0) : null;
        }
        if (!is_string($valor)) {
            return null;
        }
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $valor >= '2000-01-01' && $valor <= '2099-12-31' ? $data : null;
    }

    /**
     * As versões para o histórico da aba, da mais antiga para a mais nova. `ate` é a véspera da seguinte (`null` na
     * última); `valeParaTras` marca a mais antiga quando ela cobre os dias antes da data dela (§10.1).
     *
     * @return list<array<string, mixed>>
     */
    private function versoesEmLista(?HistoricoDeJornadas $historico, ?JornadaColaborador $vigente, \DateTimeImmutable $hoje): array
    {
        if ($historico === null) {
            return [];
        }
        $versoes = $historico->versoes();
        $lista   = [];
        foreach ($versoes as $i => $versao) {
            $inicio  = $versao->getValeAPartirDe();
            $seguinte = $versoes[$i + 1] ?? null;
            $lista[] = [
                'id'                  => $versao->getId(),
                'valeAPartirDe'       => $inicio->format('Y-m-d'),
                'ate'                 => $seguinte?->getValeAPartirDe()->modify('-1 day')->format('Y-m-d'),
                'valeParaTras'        => $i === 0 && $historico->vigenteEm($inicio->modify('-1 day')) === $versao,
                'vigente'             => $versao === $vigente,
                'agendada'            => $inicio->format('Y-m-d') > $hoje->format('Y-m-d'),
                'cargaHorariaSemanal' => $versao->getCargaHorariaSemanal(),
                'blocos'              => $this->blocosEmLista($versao->getBlocos()),
            ];
        }

        return $lista;
    }

    /**
     * @param iterable<BlocoJornada|BlocoJornadaColaborador> $blocos blocos da jornada pessoal ou da padrão
     *
     * @return list<array<string, mixed>>
     */
    private function blocosEmLista(iterable $blocos): array
    {
        $lista = [];
        foreach ($blocos as $b) {
            $lista[] = [
                'diasSemana'   => $b->getDiasSemana(),
                'entrada'      => $b->getEntrada(),
                'repouso'      => $b->getRepouso(),
                'retorno'      => $b->getRetorno(),
                'saida'        => $b->getSaida(),
                'minutosBloco' => $b->getMinutosBloco(),
            ];
        }

        return $lista;
    }

    private function validarBlocos(array $blocos, int $cargaSemanal): ?string
    {
        $diasUsados   = [];
        $totalMinutos = 0;

        foreach ($blocos as $i => $b) {
            $dias = array_map('intval', (array) ($b['diasSemana'] ?? []));

            if (empty($dias)) {
                return sprintf('Bloco %d não tem dias da semana definidos.', $i + 1);
            }

            foreach ($dias as $dia) {
                if ($dia < 1 || $dia > 7) {
                    return sprintf('Dia inválido no bloco %d: %d.', $i + 1, $dia);
                }
                if (in_array($dia, $diasUsados, true)) {
                    return sprintf('Dia %d aparece em mais de um bloco.', $dia);
                }
                $diasUsados[] = $dia;
            }

            $bloco = new BlocoJornadaColaborador();
            $bloco->setEntrada((string) ($b['entrada'] ?? '09:00'));
            $bloco->setRepouso($b['repouso'] !== '' ? (string) ($b['repouso'] ?? null) : null);
            $bloco->setRetorno($b['retorno'] !== '' ? (string) ($b['retorno'] ?? null) : null);
            $bloco->setSaida((string) ($b['saida'] ?? '18:00'));

            $minutos = $this->calcularMinutosBloco($bloco);

            if ($minutos <= 0) {
                return sprintf('Bloco %d tem carga horária inválida (deve ser positiva).', $i + 1);
            }

            $totalMinutos += $minutos * count($dias);
        }

        if (!empty($blocos) && $totalMinutos !== $cargaSemanal) {
            return sprintf(
                'Soma dos blocos (%dh%02dm) não bate com a carga semanal (%dh%02dm).',
                intdiv($totalMinutos, 60), $totalMinutos % 60,
                intdiv($cargaSemanal, 60), $cargaSemanal % 60
            );
        }

        return null;
    }

    private function calcularMinutosBloco(BlocoJornadaColaborador $bloco): int
    {
        [$hE, $mE] = array_map('intval', explode(':', $bloco->getEntrada()));
        [$hS, $mS] = array_map('intval', explode(':', $bloco->getSaida()));

        $total = ($hS * 60 + $mS) - ($hE * 60 + $mE);

        if ($bloco->getRepouso() !== null && $bloco->getRetorno() !== null) {
            [$hR, $mR]   = array_map('intval', explode(':', $bloco->getRepouso()));
            [$hRt, $mRt] = array_map('intval', explode(':', $bloco->getRetorno()));
            $total -= ($hRt * 60 + $mRt) - ($hR * 60 + $mR);
        }

        return max(0, $total);
    }
}
