<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\LiberacaoDasOpcoesDeEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\Service\VisaoDoMaster;
use App\Contribua\UseCase\RegistrarLiberacaoAutomaticaUseCase;
use App\Service\Tenant\TenantContext;
use App\Twig\TenantContextExtension;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Os dados dos avisos de espaço de toda tela logada (desenho Contribua 14 e 15/11, `bj-plano.js`):
 *
 *  - `aviso_de_espaco()`: a barra do topo. Só o primário, a partir da faixa Alerta do Master (padrão 90%), nunca na tela
 *    Contribua (que já mostra o mesmo conteúdo). Null quando não há barra.
 *  - `espaco_para_solicitar()`: o que os modais "Solicitar mais espaço" e "Pedido em análise" e o pop-up dos 97% leem.
 *    Para o primário e para o Master, em qualquer tela, inclusive a Contribua. A marca `auto` fica anotada aqui e só é
 *    gravada na resposta (`aoResponder`), depois do render e só se a página deu certo: o Twig não grava no banco.
 *  - `aviso_de_pedidos_de_espaco()`: quantos pedidos pendentes o Master tem a analisar (barra N9), fora da Contribua.
 *
 * D6: "novos envios pausados" só aparece com o bloqueio ligado; com ele desligado o texto diz só que o espaço está cheio.
 *
 * Custo: quem não é primário nem Master sai antes de qualquer consulta (o retrato soma o uso de várias tabelas).
 */
final class AvisoDeEspacoExtension extends AbstractExtension implements ResetInterface
{
    public const ROTA_DA_TELA = 'contribua_index';

    /** @var array{tenant: int, dados: ?array<string, mixed>}|null */
    private ?array $daRequisicao = null;

    private ?int $pedidosDoMaster = null;

    /** Escritório que chegou a 97% sem a marca `auto`: gravada em `aoResponder`. */
    private ?int $marcarAuto = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SituacaoDoEspaco $situacao,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
        private readonly SolicitanteDaSessao $solicitantes,
        private readonly TenantContextExtension $vinculos,
        private readonly PedidoDeEspacoRepository $pedidos,
        private readonly EspacoDoEscritorioRepository $espacos,
        private readonly RegistrarLiberacaoAutomaticaUseCase $liberacaoAutomatica,
        private readonly VisaoDoMaster $visaoDoMaster,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('aviso_de_espaco', $this->avisoDeEspaco(...)),
            new TwigFunction('espaco_para_solicitar', $this->espacoParaSolicitar(...)),
            new TwigFunction('aviso_de_pedidos_de_espaco', $this->avisoDePedidosDeEspaco(...)),
        ];
    }

    /** @return array<string, mixed>|null */
    public function avisoDeEspaco(): ?array
    {
        $dados = $this->dados();
        if ($dados === null || !$dados['primario'] || $this->naTelaContribua()) {
            return null;
        }
        if ($dados['percentual'] < $this->configuracoes->atual()->getFaixaAlerta()) {
            return null;
        }

        return $dados;
    }

    /** @return array<string, mixed>|null */
    public function espacoParaSolicitar(): ?array
    {
        $dados = $this->dados();

        return $dados !== null && ($dados['primario'] || $dados['master']) ? $dados : null;
    }

    /** Pedidos pendentes de todos os escritórios, só para o Master e fora da tela Contribua; null sem pendência. */
    public function avisoDePedidosDeEspaco(): ?int
    {
        if ($this->naTelaContribua() || !$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return null;
        }
        if ($this->pedidosDoMaster === null) {
            $this->pedidosDoMaster = $this->contarPendentesSemDerrubar();
        }

        return $this->pedidosDoMaster > 0 ? $this->pedidosDoMaster : null;
    }

    public function reset(): void
    {
        $this->daRequisicao = null;
        $this->pedidosDoMaster = null;
        $this->marcarAuto = null;
    }

    /**
     * N8: grava a marca `auto` depois do render, antes dos eventos adiados do espaço (prioridade maior que a do
     * `EventosDoEspacoAposConfirmar`, -1024), para que o evento da liberação saia nesta mesma resposta. Página de erro
     * não grava. Nunca derruba a resposta: a falha vai ao log e a próxima tela tenta de novo (o UseCase é idempotente).
     */
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
    public function aoResponder(ResponseEvent $evento): void
    {
        $tenantId = $this->marcarAuto;
        $this->marcarAuto = null;
        if ($tenantId === null || !$evento->isMainRequest() || $evento->getResponse()->getStatusCode() >= 400) {
            return;
        }
        try {
            $this->liberacaoAutomatica->executar($tenantId);
        } catch (\Throwable $falha) {
            $this->logger->error('Contribua: marca automática de liberação não gravada', ['tenant' => $tenantId, 'erro' => $falha->getMessage()]);
        }
    }

    private function contarPendentesSemDerrubar(): int
    {
        try {
            return $this->visaoDoMaster->deTodosOsEscritorios(fn (): int => $this->pedidos->contarParaOMaster()['pendentes']);
        } catch (\Throwable $falha) {
            $this->logger->error('Contribua: pedidos de espaço não contados para a barra do Master', ['erro' => $falha->getMessage()]);

            return 0;
        }
    }

    private function naTelaContribua(): bool
    {
        return $this->requestStack->getMainRequest()?->attributes->get('_route') === self::ROTA_DA_TELA;
    }

    /** @return array<string, mixed>|null */
    private function dados(): ?array
    {
        $tenantId = $this->tenantContext->getCurrentTenant()?->getId();
        if ($tenantId === null) {
            return null;
        }
        if ($this->daRequisicao === null || $this->daRequisicao['tenant'] !== $tenantId) {
            $this->daRequisicao = ['tenant' => $tenantId, 'dados' => $this->montarSemDerrubar($tenantId)];
        }

        return $this->daRequisicao['dados'];
    }

    /** Roda em toda página: uma falha aqui some com o aviso, nunca com a página. */
    private function montarSemDerrubar(int $tenantId): ?array
    {
        try {
            $quem = $this->quemPodePedir();
            if ($quem === null) {
                return null;
            }
            $retrato = $this->situacao->doEscritorio($tenantId);
            if ($retrato->percentual === null || $retrato->bytesLimite === null) {
                return null;
            }
            if ($quem->ehPrimario) {
                $this->anotarMarcaAutomatica($retrato);
            }

            return $this->precisaDoPedido($retrato) ? $this->montar($retrato, $quem) : null;
        } catch (\Throwable $falha) {
            $this->logger->error('Contribua: aviso de espaço não montado', ['tenant' => $tenantId, 'erro' => $falha->getMessage()]);

            return null;
        }
    }

    /**
     * Só o primário e o Master. O papel vem dos vínculos que a moldura já carregou (`eh_usuario_primario`, memória por
     * requisição): o colaborador sai sem nenhuma consulta a mais.
     */
    private function quemPodePedir(): ?Solicitante
    {
        $escritorio = $this->tenantContext->getCurrentTenant();
        $ehMaster = $this->security->isGranted('ROLE_SUPER_ADMIN');
        $ehPrimario = $this->vinculos->ehUsuarioPrimario();
        if ($escritorio === null || (!$ehPrimario && !$ehMaster)) {
            return null;
        }

        return new Solicitante($this->solicitantes->usuario(), $escritorio, $ehMaster, $ehPrimario);
    }

    /**
     * O pedido pendente só é procurado onde algo o usa: a tela Contribua, a barra (a partir da faixa Alerta) e o pop-up
     * dos 97%. Abaixo disso, fora da Contribua, nenhum botão abre o modal e a página não paga a consulta.
     */
    private function precisaDoPedido(RetratoDoEspaco $r): bool
    {
        return $this->naTelaContribua()
            || (int) $r->percentual >= $this->configuracoes->atual()->getFaixaAlerta()
            || LiberacaoDasOpcoesDeEspaco::usoNoLimiar($r->bytesUsados, (int) $r->bytesLimite);
    }

    /**
     * N8: a marca `auto` é gravada na primeira tela aberta com uso a partir de 97% (na resposta, ver `aoResponder`). Só
     * consulta a marca quando o uso está no limiar e a liberação não é a do Master; o UseCase é idempotente.
     */
    private function anotarMarcaAutomatica(RetratoDoEspaco $r): void
    {
        if (!LiberacaoDasOpcoesDeEspaco::usoNoLimiar($r->bytesUsados, $r->bytesLimite) || $r->liberadaPor === OrigemDaLiberacao::Master) {
            return;
        }
        if ($this->espacos->doTenant($r->tenantId)?->getOpcoesLiberadasPor() === null) {
            $this->marcarAuto = $r->tenantId;
        }
    }

    /** @return array<string, mixed> */
    private function montar(RetratoDoEspaco $r, Solicitante $quem): array
    {
        $pendente = $r->opcoesLiberadas ? null : $this->pedidos->pendenteDoEscritorio($r->tenantId);
        $faltam = max(0, (int) $r->bytesLimite - $r->bytesUsados);

        return [
            'primario' => $quem->ehPrimario,
            'master' => $quem->ehMaster,
            // `nenhum`, `pendente` ou `liberado`: a liberação (Master ou 97% de uso) vale mais que o pedido pendente.
            'estado' => $r->opcoesLiberadas ? 'liberado' : ($pendente !== null ? 'pendente' : 'nenhum'),
            'quando' => $pendente?->getCriadoEm()->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i'),
            'escritorio' => (string) $quem->escritorio->getName(),
            'percentual' => (int) $r->percentual,
            'largura' => min(100, (int) $r->percentual) . '%',
            'uso' => RetratoDoEspaco::formatarGb($r->bytesUsados),
            'limite' => RetratoDoEspaco::formatarGb((int) $r->bytesLimite),
            'faltam' => RetratoDoEspaco::formatarGb($faltam),
            // Pop-up dos 97% (N8): só o primário. Vermelho e "cheio" a partir de 100%.
            'popup' => $quem->ehPrimario && LiberacaoDasOpcoesDeEspaco::usoNoLimiar($r->bytesUsados, $r->bytesLimite),
            // Pelos bytes, não pelo percentual arredondado: a 99,5% ainda há espaço livre.
            'cheio' => $r->bytesUsados >= (int) $r->bytesLimite,
            // D6: só promete pausa com o bloqueio ligado ("estão pausados" só na faixa Bloqueio).
            'pausados' => $r->bloqueioAtivo && $r->faixa === FaixaDoEspaco::Bloqueio,
            'vaiPausar' => $r->bloqueioAtivo,
        ];
    }
}
