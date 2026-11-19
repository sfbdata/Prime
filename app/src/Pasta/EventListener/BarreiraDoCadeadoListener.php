<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Pasta\Service\EscopoDePastas;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\Service\TrilhaDoAcessoRestrito;
use App\Entity\Tenant\Tenant;
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
     *  - o pedido: `pasta_acesso_pedir` ("Pedir para editar"; a mesma rota serve à Lista e à busca);
     *  - `pasta_compartilhamento_encerrar`: "Deixar de colaborar" de quem recebeu a pasta só para ver (quem pode
     *    encerrar o use case decide).
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
        'pasta_compartilhamento_encerrar',
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

    /**
     * Pasta compartilhada com quem tem acesso restrito (spec acesso-restrito-e-compartilhar §4.4): "Baixar documentos"
     * e "Enviar documentos" valem por rota. "Abrir e movimentar" é o teto `Visualizar` do `NivelNoCadeado`; o envio
     * passa por cima dele só quando o cadeado deixaria editar.
     *
     * @var list<string>
     */
    public const ROTAS_DE_BAIXAR = ['pasta_documento_download', 'pasta_documentos_zip', 'pasta_financeiro_documento_download'];

    /** @var list<string> */
    public const ROTAS_DE_ENVIAR = [
        'pasta_peticionar_upload',
        'pasta_peticionar_upload_drive',
        'pasta_peticionar_upload_imagem',
        'pasta_peticionar_texto',
        'pasta_financeiro_upload',
    ];

    public const MENSAGEM_COMPARTILHAMENTO = 'O compartilhamento desta pasta não permite esta ação.';

    private const METODOS_DE_LEITURA = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly PastaDaRota $pastaDaRota,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly ?EscopoDePastas $escopoDePastas = null,
        private readonly ?NivelNoCadeado $nivelNoCadeado = null,
        private readonly ?TrilhaDoAcessoRestrito $trilha = null,
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
        $tenant = $this->tenantDoRestrito($user, $pasta);
        try {
            $resposta = $this->recusaPara($user, $pasta, $request);
        } catch (NotFoundHttpException $foraDoEscopo) {
            $this->anotarNaTrilha($tenant, $user, $request, $pasta, negado: true);

            throw $foraDoEscopo;
        }
        $this->anotarNaTrilha($tenant, $user, $request, $pasta, negado: $resposta !== null);
        if ($resposta === null) {
            return;
        }

        // Curto-circuito: troca o controller por quem só devolve a recusa, com os argumentos zerados.
        $event->setController(static fn (): Response => $resposta);
        $event->setArguments([]);
    }

    /**
     * A recusa da barreira para esta rota, ou `null` quando ela passa. Pasta fora do escopo do restrito é 404 em toda
     * rota (spec acesso-restrito-e-compartilhar §5.1), inclusive a tela e o pedido de acesso: não confirma que a pasta
     * existe nem oferece pedir.
     */
    private function recusaPara(User $user, Pasta $pasta, Request $request): ?Response
    {
        if ($this->escopoDePastas?->pastaForaDoEscopo($user, $pasta) === true) {
            throw new NotFoundHttpException('Pasta não encontrada.');
        }
        $peloCompartilhamento = $this->decisaoDoCompartilhamento($user, $pasta, (string) $request->attributes->get('_route'));
        if ($peloCompartilhamento === true) {
            return null;
        }
        $nivel = $this->acessoAPasta->nivel($user, $pasta);
        if ($peloCompartilhamento === null && !$this->recusa($nivel, $request)) {
            return null;
        }
        $limitaPeloCompartilhamento = $peloCompartilhamento === false || $this->soOCompartilhamentoLimita($user, $pasta, $nivel);

        return $this->resposta($request, $pasta, $nivel, $limitaPeloCompartilhamento ? self::MENSAGEM_COMPARTILHAMENTO : null);
    }

    /** Regra 7: a recusa sempre; a passagem só quando abre a pasta (a tela, o download ou o ZIP). */
    private function anotarNaTrilha(?Tenant $tenant, User $user, Request $request, Pasta $pasta, bool $negado): void
    {
        if ($tenant === null || $this->trilha === null) {
            return;
        }
        if ($negado) {
            $this->trilha->negado($user, $tenant, $request, Pasta::class, (int) $pasta->getId());

            return;
        }
        if (TrilhaDoAcessoRestrito::contaComoLeitura($request)) {
            $this->trilha->leitura($user, $tenant, $request, Pasta::class, (int) $pasta->getId());
        }
    }

    /** O escritório da pasta quando quem pede tem o perfil restrito nele (a trilha da regra 7); senão, `null`. */
    private function tenantDoRestrito(User $user, Pasta $pasta): ?Tenant
    {
        $tenant = $pasta->getTenant();
        if ($this->trilha === null || $tenant === null || $this->escopoDePastas?->ehRestritoNoEscritorio($user, $tenant) !== true) {
            return null;
        }

        return $tenant;
    }

    /**
     * `false` recusa pelo compartilhamento; `true` libera o envio (caixa marcada e o cadeado deixaria editar); `null`
     * segue a regra do cadeado. Só para o restrito que vê a pasta pelo compartilhamento.
     */
    private function decisaoDoCompartilhamento(User $user, Pasta $pasta, string $rota): ?bool
    {
        $baixar = in_array($rota, self::ROTAS_DE_BAIXAR, true);
        $enviar = in_array($rota, self::ROTAS_DE_ENVIAR, true);
        $compartilhamento = $baixar || $enviar ? $this->escopoDePastas?->compartilhamentoQueDaAcesso($user, $pasta) : null;
        if ($compartilhamento === null) {
            return null;
        }
        $permissoes = $compartilhamento->permissoes();
        if ($baixar) {
            return $permissoes->baixarDocumentos ? null : false;
        }
        if (!$permissoes->enviarDocumentos) {
            return false;
        }

        return $this->nivelNoCadeado?->nivelSoDoCadeado($user, $pasta) === NivelDeAcesso::Editar ? true : null;
    }

    /** O `Visualizar` veio do compartilhamento sem "Abrir e movimentar", não do cadeado: a recusa diz isso. */
    private function soOCompartilhamentoLimita(User $user, Pasta $pasta, NivelDeAcesso $nivel): bool
    {
        return $nivel === NivelDeAcesso::Visualizar
            && $this->nivelNoCadeado?->nivelSoDoCadeado($user, $pasta) === NivelDeAcesso::Editar
            && $this->escopoDePastas?->compartilhamentoQueDaAcesso($user, $pasta) !== null;
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
    private function resposta(Request $request, Pasta $pasta, NivelDeAcesso $nivel, ?string $mensagem = null): Response
    {
        $mensagem ??= $nivel === NivelDeAcesso::Oculto ? self::MENSAGEM_OCULTO : self::MENSAGEM_VISUALIZAR;
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
