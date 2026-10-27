<?php

declare(strict_types=1);

namespace App\ServiceDesk\Controller;

use App\Contribua\Service\CodigoPixEstatico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\ServiceDesk\DTO\AbrirChamadoInput;
use App\ServiceDesk\DTO\SolicitarRecargaInput;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use App\ServiceDesk\Enum\FormaDeRecarga;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;
use App\ServiceDesk\Service\ReferenciaAssinadaDeRecarga;
use App\ServiceDesk\UseCase\AbrirChamadoDeSuporteUseCase;
use App\ServiceDesk\UseCase\ConsultarSaldoDoEscritorioUseCase;
use App\ServiceDesk\UseCase\ListarChamadosDoEscritorioUseCase;
use App\ServiceDesk\UseCase\SolicitarRecargaDeSaldoUseCase;
use App\ServiceDesk\UseCase\VerChamadoDoEscritorioUseCase;
use App\TrabalhoExterno\Service\QrCodeEmSvg;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Meus chamados" (desenho 12): saldo, extrato, chamados e as ações da tela. Módulo `servicedesk`.
 * As ações são JSON, chamadas pelo JS da página com o `_token` do formulário da página.
 */
#[IsGranted('ROLE_USER')]
#[Route('/service-desk')]
final class ServiceDeskController extends AbstractController
{
    private const CSRF = 'service_desk';
    private const VALORES_DA_RECARGA = [20, 50, 100, 200];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('', name: 'service_desk_index', methods: ['GET'])]
    public function index(
        ConsultarSaldoDoEscritorioUseCase $saldo,
        ListarChamadosDoEscritorioUseCase $listar,
        PrecoDoAtendimentoRepository $precos,
    ): Response {
        $tenant = $this->escritorio();

        return $this->render('service_desk/index.html.twig', [
            'saldo' => $saldo->executar($tenant),
            'lista' => $listar->executar($tenant),
            'precos' => $precos->valoresPorTipo(),
            'tipos' => TipoDeChamado::cases(),
            'prioridades' => PrioridadeDoChamado::cases(),
            'valoresDaRecarga' => self::VALORES_DA_RECARGA,
            'minimoCentavos' => SaldoDoEscritorio::MINIMO_PARA_ABRIR_CENTAVOS,
        ]);
    }

    #[Route('/chamado/{id}', name: 'service_desk_chamado', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function chamado(int $id, VerChamadoDoEscritorioUseCase $ver): JsonResponse
    {
        try {
            $detalhe = $ver->executar($this->escritorio(), $id);
        } catch (\DomainException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'ok' => true,
            'titulo' => 'Chamado ' . $detalhe->resumo->numeroFormatado(),
            'html' => $this->renderView('service_desk/_chamado.html.twig', ['detalhe' => $detalhe]),
        ]);
    }

    #[Route('/chamado', name: 'service_desk_abrir', methods: ['POST'])]
    public function abrir(Request $request, AbrirChamadoDeSuporteUseCase $abrir): JsonResponse
    {
        return $this->acao($request, function () use ($request, $abrir): array {
            $tipo = TipoDeChamado::tryFrom((string) $request->request->get('tipo')) ?? throw new \DomainException('Escolha o tipo do chamado.');
            $prioridade = PrioridadeDoChamado::tryFrom((string) $request->request->get('prioridade')) ?? PrioridadeDoChamado::Media;
            $assunto = trim((string) $request->request->get('assunto'));
            if ($assunto === '') {
                throw new \DomainException('Informe o assunto.');
            }

            $data = null;
            $hora = null;
            if ($tipo->exigeAgendamento()) {
                $data = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('data')) ?: null;
                $hora = trim((string) $request->request->get('hora'));
                if ($data === null || $hora === '') {
                    throw new \DomainException('Escolha a data e o horário do agendamento.');
                }
            }

            $aberto = $abrir->executar(new AbrirChamadoInput(
                $this->escritorio(),
                $this->usuario(),
                $tipo,
                $prioridade,
                $assunto,
                trim((string) $request->request->get('descricao')),
                $data,
                $hora,
            ));

            return ['mensagem' => 'Chamado ' . $aberto->numeroFormatado() . ' aberto. A equipe técnica foi avisada.'];
        });
    }

    /** Mostra o PIX da recarga (copia e cola + QR) sem gravar nada: o pendente só nasce em "Já paguei". */
    #[Route('/pix', name: 'service_desk_pix', methods: ['POST'])]
    public function pix(Request $request, ReferenciaAssinadaDeRecarga $referencias, QrCodeEmSvg $qr): JsonResponse
    {
        return $this->acao($request, function () use ($request, $referencias, $qr): array {
            $tenant = $this->escritorio();
            $centavos = $this->valorDaRecarga($request) * 100;
            $emitida = $referencias->emitir((int) $tenant->getId(), $centavos);
            $copiaECola = CodigoPixEstatico::gerar($emitida['referencia'], $centavos);

            return $emitida + ['copiaECola' => $copiaECola, 'qrSvg' => $qr->gerar($copiaECola)];
        });
    }

    /** "Já paguei": grava a recarga pendente com a referência que o PIX mostrado já levava. */
    #[Route('/recarga', name: 'service_desk_recarga', methods: ['POST'])]
    public function recarga(Request $request, SolicitarRecargaDeSaldoUseCase $solicitar, ReferenciaAssinadaDeRecarga $referencias): JsonResponse
    {
        return $this->acao($request, function () use ($request, $solicitar, $referencias): array {
            $tenant = $this->escritorio();
            $centavos = $this->valorDaRecarga($request) * 100;
            $referencia = (string) $request->request->get('referencia');
            if (!$referencias->confere((int) $tenant->getId(), $centavos, $referencia, (string) $request->request->get('assinatura'))) {
                throw new \DomainException('O PIX expirou. Abra "Adicionar saldo" de novo.');
            }

            $solicitar->executar(new SolicitarRecargaInput($tenant, $this->usuario(), $centavos, FormaDeRecarga::Pix, $referencia));

            return [];
        });
    }

    private function valorDaRecarga(Request $request): int
    {
        $reais = (int) $request->request->get('valor');
        if (!\in_array($reais, self::VALORES_DA_RECARGA, true)) {
            throw new \DomainException('Escolha um dos valores de recarga.');
        }

        return $reais;
    }

    /** @param callable(): array<string, mixed> $acao */
    private function acao(Request $request, callable $acao): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json(['ok' => true] + $acao());
        } catch (\DomainException|\InvalidArgumentException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function escritorio(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $usuario = $this->getUser();
        if ($tenant === null || !$usuario instanceof User || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'servicedesk')) {
            throw $this->createAccessDeniedException('Sem acesso ao Service Desk.');
        }

        return $tenant;
    }

    private function usuario(): ?User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : null;
    }
}
