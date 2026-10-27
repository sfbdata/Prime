<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Entity\Auth\User;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\PastaDaRota;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Barreira de rota do cadeado da pasta (spec pasta-cadeado §3), em `kernel.controller_arguments`.
 *
 * O teto do `canAccessResource` cobre as rotas que perguntam ao `PermissionChecker`; esta barreira cobre
 * TODA rota de pasta, pergunte ou não, pelo mesmo resolvedor da lápide e da aba oculta (`PastaDaRota`):
 *  - `Oculto`: recusa toda rota da pasta, menos `pasta_show` (que desenha a tela "Pasta trancada") e o
 *    pedido de acesso (`ROTAS_DO_OCULTO`);
 *  - `Visualizar`: recusa os métodos não seguros, menos as preferências pessoais e as leituras por POST
 *    (`ROTAS_DO_VISUALIZAR`);
 *  - `Editar`: passa.
 *
 * Pasta de outro escritório passa reto: a própria action responde o 404 dela, sem a barreira revelar que
 * a pasta existe. O alcance é travado pelo `BarreiraDoCadeadoRotasArquiteturaTest`.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class BarreiraDoCadeadoListener
{
    /**
     * O que quem está `Oculto` ainda alcança: a tela trancada e o pedido de acesso (da tela, da Lista e da busca).
     *
     * @var list<string>
     */
    public const ROTAS_DO_OCULTO = ['pasta_show', 'pasta_acesso_pedir'];

    /**
     * Escritas que quem está em `Visualizar` ainda faz, cada uma pelo seu motivo (K4):
     *  - preferências pessoais, só da pessoa e de mais ninguém: `pasta_favorito_alternar` (fixar nos
     *    favoritos), `pasta_acompanhamento_alternar` (acompanhar alterações), `pasta_documentos_favorito`
     *    (estrela do documento), `pasta_timeline_visita` (a última visita à Timeline inteligente) e
     *    `expediente_pasta_destaque` (destaque de linha na Lista);
     *  - leituras por POST: `pasta_documentos_zip` (baixar o que se vê; a seleção vai no corpo) e
     *    `inteligencia_analise_registro` (grava só a linha de auditoria de baixar ou imprimir a análise);
     *  - o pedido: `pasta_acesso_pedir` ("Pedir para editar"; a mesma rota serve à Lista e à busca).
     *
     * Ficam de FORA (recusadas): `pasta_push_lida` e `inteligencia_analise_lida`/`_nao_lida`, que marcam
     * para o escritório inteiro (lida por quem, quando), não para a pessoa.
     *
     * @var list<string>
     */
    public const ROTAS_DO_VISUALIZAR = [
        'pasta_favorito_alternar',
        'pasta_acompanhamento_alternar',
        'pasta_documentos_favorito',
        'pasta_timeline_visita',
        'expediente_pasta_destaque',
        'pasta_documentos_zip',
        'inteligencia_analise_registro',
        'pasta_acesso_pedir',
    ];

    /**
     * Rotas `pasta_*` (ou de path `/pasta/`) que não operam sobre uma pasta existente: não há cadeado para
     * olhar. Estão aqui para o teste de arquitetura distinguir "não precisa" de "esqueceram":
     *  - `pasta_index` e `pasta_new`: a lista e a criação;
     *  - `pasta_imagem_editor`: a imagem embutida na peça é servida pelo nome do arquivo; quem confere o
     *    cadeado da pasta dona é o próprio `PecaImagemController` (D5);
     *  - `central_ia_pasta`: o rótulo da barra da IA. Quem confere o cadeado (`AcessoAPasta::podeVer`) é o
     *    próprio use case, que devolve o mesmo 404 para pasta inexistente, de outro escritório, excluída ou trancada.
     *
     * @var list<string>
     */
    public const ROTAS_SEM_PASTA = ['pasta_index', 'pasta_new', 'pasta_imagem_editor', 'central_ia_pasta'];

    public const MENSAGEM_OCULTO = 'O usuário primário restringiu o acesso a esta pasta.';

    public const MENSAGEM_VISUALIZAR = 'Somente visualização: o usuário primário trancou esta pasta.';

    private const METODOS_DE_LEITURA = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly PastaDaRota $pastaDaRota,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $pasta   = $this->pastaDoEscritorio($event);
        $user    = $this->security->getUser();
        if ($pasta === null || !$user instanceof User) {
            return;
        }

        $nivel = $this->acessoAPasta->nivel($user, $pasta);
        if (!$this->recusa($nivel, $request)) {
            return;
        }

        // Curto-circuito: troca o controller por quem só devolve a recusa, com os argumentos zerados.
        $resposta = $this->resposta($request, $pasta, $nivel);
        $event->setController(static fn (): Response => $resposta);
        $event->setArguments([]);
    }

    private function pastaDoEscritorio(ControllerArgumentsEvent $event): ?Pasta
    {
        $pasta = $this->pastaDaRota->resolver($event)?->pasta;

        return $pasta !== null && $this->pastaDaRota->ehDoEscritorioDaSessao($pasta) ? $pasta : null;
    }

    private function recusa(NivelDeAcesso $nivel, Request $request): bool
    {
        return self::recusaria($nivel, $request->getMethod(), (string) $request->attributes->get('_route'));
    }

    /**
     * A regra da barreira, sem a requisição: o desfazer da auditoria (`GuardaDaTelaNoDesfazer`) pergunta aqui se a
     * escrita na meta ou na pasta passaria pela barreira, para seguir qualquer mudança dela.
     */
    public static function recusaria(NivelDeAcesso $nivel, string $metodo, string $rota): bool
    {
        return match ($nivel) {
            NivelDeAcesso::Editar     => false,
            NivelDeAcesso::Oculto     => !in_array($rota, self::ROTAS_DO_OCULTO, true),
            NivelDeAcesso::Visualizar => !in_array($metodo, self::METODOS_DE_LEITURA, true)
                && !in_array($rota, self::ROTAS_DO_VISUALIZAR, true),
        };
    }

    /**
     * JSON no formato dos endpoints AJAX da pasta (`status`, `erro`, `mensagem`) para as chamadas da tela;
     * 403 para a navegação por GET (documento em aba nova, Peticionar); e, num formulário comum, volta para
     * a pasta com o aviso (a tela trancada, para quem está `Oculto`).
     */
    private function resposta(Request $request, Pasta $pasta, NivelDeAcesso $nivel): Response
    {
        $mensagem = $nivel === NivelDeAcesso::Oculto ? self::MENSAGEM_OCULTO : self::MENSAGEM_VISUALIZAR;
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'json')) {
            return new JsonResponse(['status' => 'erro', 'erro' => $mensagem, 'mensagem' => $mensagem], Response::HTTP_FORBIDDEN);
        }
        if (in_array($request->getMethod(), self::METODOS_DE_LEITURA, true)) {
            throw new AccessDeniedHttpException($mensagem);
        }

        $sessao = $this->requestStack->getSession();
        if ($sessao instanceof Session) {
            $sessao->getFlashBag()->add('warning', $mensagem);
        }

        return new RedirectResponse($this->router->generate('pasta_show', ['id' => $pasta->getId()]));
    }
}
