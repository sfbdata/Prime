<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Entity\Auth\User;
use App\Pasta\Service\EscopoDePastas;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Barreira de rotas do ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE (spec `docs/specs/acesso-restrito-e-compartilhar.md`,
 * §5.1 item 2). Segunda camada: o módulo sem permissão já recusa (`PermissoesDoAcessoRestrito`), mas tela sem módulo
 * (Painel, Central, relatórios gerais do Expediente) não.
 *
 * Lista de liberação, e não de bloqueio: o restrito só alcança a Lista e a pasta (Expediente, pasta e filhas, processo,
 * cliente), Meu Perfil, Contribua, sair e a troca de escritório, mais o que não mostra dado do escritório (sino, termos,
 * páginas públicas). Rota nova fica fechada até alguém a pôr aqui. O resto recebe a tela "Fora do seu acesso" (403,
 * textos do `bj-escopo.js` do desenho); a página inicial manda para a Lista.
 *
 * Prioridade 2: depois do roteamento (32), do firewall (8), da barreira da sessão (6), do TenantFilter (5) e da
 * barreira do escritório inativo (3).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
final class BarreiraDoAcessoRestritoListener
{
    public const TITULO = 'Fora do seu acesso';
    public const MENSAGEM = 'Seu perfil tem acesso restrito às pastas sob sua responsabilidade. Esta área do escritório não está disponível para você.';

    /** Para onde vai a página inicial (e o botão "Ir para minhas pastas"). */
    public const ROTA_DA_LISTA = 'expediente_index';

    /**
     * Prefixos de nome de rota liberados.
     *
     * @var list<string>
     */
    public const PREFIXOS_LIBERADOS = [
        // A Lista e a pasta (a pasta alheia ainda cai no 404 da barreira do cadeado e some das listas).
        'expediente_', 'pasta_', 'processo_', 'cliente_',
        // Meu Perfil (dados, senha, foto, exclusão da própria conta) e Contribua.
        'app_profile', 'conta_', 'contribua_',
        // Sem dado do escritório: o sino do cabeçalho, a conta (convites recebidos, senha), os termos e a política.
        'notificacao_', 'auth_', 'termo_', 'legal_',
        // Públicas (link de e-mail, rastreio, comprovante).
        'comercial_', 'push_compartilhado', 'validacao_',
        // Barra de depuração e páginas de erro (só em dev).
        '_',
    ];

    /** @var list<string> rotas liberadas uma a uma */
    public const ROTAS_LIBERADAS = [
        'app_logout',
        'tenant_selecionar',
        'escritorio_sair',
        'conector_perfil_revogar',
        'api_datajud_search',
        'monitor_evento',
        'novidades_index',
        'ajuda_index',
        'email_descadastro',
        'register_confirm',
        'indicacao_link_curto',
        'vagas_publicas_cta',
    ];

    /**
     * Rotas dentro dos prefixos liberados que mostram ou mexem no escritório inteiro: relatórios gerais, lixeira,
     * importação em lote, cadastro de marcadores e os convites da equipe.
     *
     * @var list<string>
     */
    public const ROTAS_FECHADAS = [
        'expediente_acervo_geral',
        'expediente_lixeira',
        'expediente_pastas_paradas',
        'expediente_pastas_paradas_pdf',
        'expediente_relatorio_processos',
        'expediente_relatorio_processos_pdf',
        'expediente_relatorio_processos_enviar',
        'expediente_importar_lote_drive_arvore',
        'expediente_importar_lote_pasta',
        'expediente_importar_lote_sugerir',
        'expediente_marcador_criar',
        'expediente_marcador_editar',
        'expediente_marcador_excluir',
        'expediente_marcador_reordenar',
        'auth_gerenciar_convites',
        'auth_gerenciar_convites_criar',
        'auth_gerenciar_convites_reenviar',
        'auth_gerenciar_convites_revogar',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly EscopoDePastas $escopo,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $rota = (string) $event->getRequest()->attributes->get('_route', '');
        // Rota sem sessão (o conector MCP, por token) não tem escritório da sessão para olhar; o escopo dela vem da
        // lista de liberação e do `NivelNoCadeado` com o escritório do token (fatia 3).
        $semSessao = $event->getRequest()->attributes->getBoolean('_stateless');
        if (!$event->isMainRequest() || $rota === '' || $semSessao || !$this->ehRestrito()) {
            return;
        }

        if ($rota === 'homepage') {
            $event->setResponse(new RedirectResponse($this->urls->generate(self::ROTA_DA_LISTA)));

            return;
        }
        if (!self::liberada($rota)) {
            $event->setResponse($this->recusa($event->getRequest()));
        }
    }

    public static function liberada(string $rota): bool
    {
        if (in_array($rota, self::ROTAS_FECHADAS, true)) {
            return false;
        }
        if (in_array($rota, self::ROTAS_LIBERADAS, true)) {
            return true;
        }
        foreach (self::PREFIXOS_LIBERADOS as $prefixo) {
            if (str_starts_with($rota, $prefixo)) {
                return true;
            }
        }

        return false;
    }

    private function ehRestrito(): bool
    {
        $usuario = $this->security->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        return $usuario instanceof User && $tenant !== null && $this->escopo->ehRestritoNoEscritorio($usuario, $tenant);
    }

    private function recusa(Request $request): Response
    {
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept', ''), 'application/json')) {
            return new JsonResponse(['erro' => self::MENSAGEM], Response::HTTP_FORBIDDEN);
        }

        return new Response($this->twig->render('pasta/fora_do_seu_acesso.html.twig', [
            'titulo'   => self::TITULO,
            'urlDaLista' => $this->urls->generate(self::ROTA_DA_LISTA),
        ]), Response::HTTP_FORBIDDEN);
    }
}
