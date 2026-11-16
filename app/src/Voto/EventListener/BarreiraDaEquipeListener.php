<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use App\Entity\Auth\User;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\AssembleiaDaRota;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Barreira de rota da equipe por assembleia (spec docs/specs/voto-equipe-e-master-1611.md, seção 1.2), em
 * `kernel.controller_arguments`, no molde da `BarreiraDoCadeadoListener` da pasta. Vale para TODA rota `voto_assembleia_*`
 * que resolve uma assembleia (argumento `Assembleia` ou o `id` da rota; ver {@see AssembleiaDaRota}):
 *  - `Oculto`: 403 em tudo, com a frase do desenho "Seu perfil não atua nesta votação.";
 *  - `Visualizar`: 403 nos métodos não seguros, menos as rotas de `ROTAS_DO_VISUALIZAR` (as leituras por POST e a decisão
 *    do conselho, a única escrita, que o UseCase confere por papel);
 *  - `Editar`: passa.
 *
 * É TETO: a barreira só recusa, nunca libera. A guarda do módulo (`GuardaDaEquipeDoVoto::exigir()`, nas actions) continua
 * valendo. Assembleia de outro escritório passa reto: a própria action responde o 404 dela. O alcance é travado pelo
 * `BarreiraDaEquipeRotasArquiteturaTest`.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class BarreiraDaEquipeListener
{
    public const PREFIXO_DAS_ROTAS = 'voto_assembleia_';

    /**
     * Rotas `voto_assembleia_*` que não levam uma assembleia do escritório pela rota, cada uma pelo seu motivo. Estão
     * aqui para o teste de arquitetura distinguir "não precisa" de "esqueceram".
     *
     * @var array<string, string>
     */
    public const ROTAS_SEM_ASSEMBLEIA = [
        'voto_assembleia_nova' => 'cria uma assembleia: ainda não existe equipe, e quem cria é a gestão do módulo.',
        'voto_assembleia_lote_arquivar' => 'os ids vão no corpo; o LoteDoVotoController tira os que a pessoa não edita (AcessoAAssembleia::idsQueEdita).',
        'voto_assembleia_lote_excluir' => 'os ids vão no corpo; o LoteDoVotoController tira os que a pessoa não edita (AcessoAAssembleia::idsQueEdita).',
        'voto_assembleia_cedula' => 'tela pública do eleitor, sem login: chega pelo token da unidade, que não é a equipe.',
        'voto_assembleia_cedula_votar' => 'tela pública do eleitor, sem login: chega pelo token da unidade, que não é a equipe.',
        'voto_assembleia_cedula_comprovante_email' => 'tela pública do eleitor, sem login: chega pelo token da unidade, que não é a equipe.',
        'voto_assembleia_cedula_foto' => 'tela pública do eleitor, sem login: chega pelo token da unidade, que não é a equipe.',
        'voto_assembleia_portal' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_localizar' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_solicitar' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_confirmar' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_comprovante' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_reiniciar' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
        'voto_assembleia_portal_sair_terminal' => 'portal público do evento, sem login: chega pelo código público, que não é a equipe.',
    ];

    /**
     * POSTs que quem está em `Visualizar` ainda faz. As duas minutas de ata só GERAM o arquivo para baixar (POST por causa
     * do modelo .docx enviado a cada geração e do token), sem gravar nada. A decisão do conselho sobre uma avaliação é a
     * única que GRAVA, por isso carrega o motivo e a conferência de papel no UseCase.
     *
     * @var array<string, string>
     */
    public const ROTAS_DO_VISUALIZAR = [
        'voto_assembleia_minuta' => 'gera a minuta da ata para baixar; o POST leva o modelo .docx e não grava nada.',
        'voto_assembleia_pauta_minuta' => 'gera a minuta da ata de uma votação para baixar; o POST leva o modelo .docx e não grava nada.',
        'voto_assembleia_avaliacao_decidir' => 'A ÚNICA escrita do nível Visualizar (spec voto-avaliacoes-do-conselho-1611): o conselho decide a avaliação de aptidão. A barreira só deixa passar; o papel (conselho ou Administração) é conferido no DecidirAvaliacaoUseCase, que recusa auditoria e fiscal com 403.',
    ];

    private const METODOS_DE_LEITURA = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly AssembleiaDaRota $assembleiaDaRota,
        private readonly AcessoAAssembleia $acesso,
        private readonly Security $security,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        $rota = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || !str_starts_with($rota, self::PREFIXO_DAS_ROTAS) || isset(self::ROTAS_SEM_ASSEMBLEIA[$rota])) {
            return;
        }

        $user = $this->security->getUser();
        $assembleia = $user instanceof User ? $this->assembleiaDaRota->resolver($event) : null;
        if (null === $assembleia) {
            return;
        }

        $nivel = $this->acesso->nivel($user, $assembleia);
        if (!self::recusaria($nivel, $request->getMethod(), $rota)) {
            return;
        }

        $mensagem = $this->tradutor->trans(NivelNaAssembleia::Oculto === $nivel ? 'voto.equipe.recusa.oculto' : 'voto.equipe.recusa.somente_leitura');
        if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'json')) {
            $resposta = new JsonResponse(['status' => 'erro', 'erro' => $mensagem, 'mensagem' => $mensagem], Response::HTTP_FORBIDDEN);
            $event->setController(static fn (): Response => $resposta);
            $event->setArguments([]);

            return;
        }

        throw new AccessDeniedHttpException($mensagem);
    }

    /** A regra da barreira, sem a requisição. */
    public static function recusaria(NivelNaAssembleia $nivel, string $metodo, string $rota): bool
    {
        return match ($nivel) {
            NivelNaAssembleia::Editar => false,
            NivelNaAssembleia::Oculto => true,
            NivelNaAssembleia::Visualizar => !\in_array($metodo, self::METODOS_DE_LEITURA, true) && !isset(self::ROTAS_DO_VISUALIZAR[$rota]),
        };
    }
}
