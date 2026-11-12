<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\PautaInput;
use App\Voto\DTO\RegrasDaPautaInput;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\EditarPautaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\UseCase\RemoverPautaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pautas da assembleia (spec voto-assembleia-condominio, seção 1.3; insumo do desenho §2, etapa 4). Em rascunho, a
 * tela inclui, altera e remove pautas (opções numeradas, padrão 1 Sim e 2 Não; a abstenção é automática). Depois de
 * publicada, a mesma tela é a mesa: cada pauta com as ações de abrir, prazo e encerrar (MesaDaAssembleiaController).
 *
 * No desenho, as pautas abertas na etapa 4 ficam em edição juntas e nada se perde entre elas. Por isso a tela grava por
 * fetch (public/js/voto-assembleia.js): o "Salvar" de uma pauta não recarrega a página, e as pautas alteradas são
 * gravadas antes de qualquer ação que recarrega. O pedido por fetch (X-Requested-With) recebe JSON com `ok` e
 * `mensagem` (o texto do toast): 200, 422 na recusa de validação e 404 na pauta que não existe mais (a tela deixa
 * seguir sem ela) ou 403 na sessão expirada (a tela tenta de novo); o envio comum segue com o toast na sessão e o redirecionamento.
 */
#[Route('/voto/assembleias/{id}/pautas', requirements: ['id' => '\d+', 'pauta' => '\d+'])]
final class PautasDaAssembleiaController extends AbstractController
{
    /** "Cargos e pautas frequentes" do desenho: cada um cria a pauta padrão com esse nome. */
    public const ATALHOS = ['Síndico(a)', 'Subsíndico(a)', 'Conselho fiscal', 'Aprovação de contas', 'Previsão orçamentária', 'Obra ou benfeitoria', 'Alteração da convenção'];

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_pautas', methods: ['GET'])]
    public function pautas(int $id, ObterAssembleiaUseCase $obter): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $assembleia = $obter->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/pautas.html.twig', [
            'assembleia' => $assembleia,
            'atalhos' => self::ATALHOS,
            'opcoes_padrao' => PautaInput::OPCOES_PADRAO,
            'agora' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('', name: 'voto_assembleia_pauta_criar', methods: ['POST'])]
    public function criar(int $id, Request $request, AdicionarPautaUseCase $adicionar): Response
    {
        $tenant = $this->guarda->exigir();
        $atalho = (string) $request->request->get('atalho', '');
        $input = \in_array($atalho, self::ATALHOS, true) ? PautaInput::padrao($atalho) : self::input($request);

        return $this->responder($request, $id, static function () use ($adicionar, $id, $input, $tenant): void {
            $adicionar->executar($tenant, $id, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_incluida', ['id' => $id]);
    }

    #[Route('/{pauta}/editar', name: 'voto_assembleia_pauta_editar', methods: ['POST'])]
    public function editar(int $id, int $pauta, Request $request, EditarPautaUseCase $editar): Response
    {
        $tenant = $this->guarda->exigir();
        $input = self::input($request);

        return $this->responder($request, $id, static function () use ($editar, $id, $input, $pauta, $tenant): void {
            $editar->executar($tenant, $id, $pauta, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_salva', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
    }

    #[Route('/{pauta}/remover', name: 'voto_assembleia_pauta_remover', methods: ['POST'])]
    public function remover(int $id, int $pauta, Request $request, RemoverPautaUseCase $remover): Response
    {
        $tenant = $this->guarda->exigir();

        return $this->responder($request, $id, static function () use ($id, $pauta, $remover, $tenant): void {
            $remover->executar($tenant, $id, $pauta);
        }, 'voto.assembleia.gestor.aviso.pauta_removida', ['id' => $id]);
    }

    /**
     * Executa a ação e responde: JSON `{ok, mensagem}` ao fetch da tela (200, 422, 404 ou 403, sem aviso na sessão) ou o
     * toast na sessão e o redirecionamento para as pautas.
     *
     * @param array<string, int|string> $voltar parâmetros da rota das pautas no envio comum
     */
    private function responder(Request $request, int $id, callable $acao, string $avisoOk, array $voltar): Response
    {
        [$status, $mensagem] = $this->executar($request, $id, $acao, $avisoOk);
        $ok = Response::HTTP_OK === $status;
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => $ok, 'mensagem' => $mensagem], $status);
        }
        if ($ok) {
            $this->avisos->ok($mensagem);
        } else {
            $this->avisos->erro($mensagem);
        }

        return $this->redirectToRoute('voto_assembleia_pautas', $voltar);
    }

    /**
     * @return array{int, string} o status HTTP (200; 422 na recusa; 404 na pauta ou assembleia que não existe, só no
     *                            fetch; 403 na sessão expirada) e o texto do toast
     */
    private function executar(Request $request, int $id, callable $acao, string $avisoOk): array
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            return [Response::HTTP_FORBIDDEN, $this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada')];
        }
        try {
            $acao();

            return [Response::HTTP_OK, $this->tradutor->trans($avisoOk)];
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            return [Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage()];
        } catch (AssembleiaNaoEncontradaException) {
            if (!$request->isXmlHttpRequest()) {
                throw $this->createNotFoundException();
            }

            return [Response::HTTP_NOT_FOUND, $this->tradutor->trans('voto.assembleia.gestor.aviso.pauta_nao_encontrada')];
        }
    }

    private static function input(Request $request): PautaInput
    {
        $numeros = $request->request->all('opcao_numero');
        $rotulos = $request->request->all('opcao_rotulo');
        $opcoes = [];
        foreach (array_values($numeros) as $i => $numero) {
            $opcoes[] = ['numero' => \is_string($numero) ? $numero : '', 'rotulo' => \is_string($rotulos[$i] ?? null) ? $rotulos[$i] : ''];
        }

        return new PautaInput((string) $request->request->get('titulo', ''), (string) $request->request->get('descricao', ''), $opcoes, self::regras($request));
    }

    /** As regras só vêm quando a tela manda o critério; sem ele, a pauta mantém as que tem. */
    private static function regras(Request $request): ?RegrasDaPautaInput
    {
        $criterio = RegraDeDecisao::tryFrom((string) $request->request->get('criterio', ''));
        if (null === $criterio) {
            return null;
        }
        $segunda = trim((string) $request->request->get('quorum_segunda', ''));

        return new RegrasDaPautaInput(
            $criterio,
            BaseDaDecisao::tryFrom((string) $request->request->get('base', '')) ?? BaseDaDecisao::Validos,
            self::inteiro((string) $request->request->get('digitos', '1')),
            self::inteiro((string) $request->request->get('vagas', '1')),
            self::inteiro((string) $request->request->get('quorum_primeira', '50')),
            '' === $segunda ? null : self::inteiro($segunda),
        );
    }

    /** Só algarismos valem; o resto vira -1, que a pauta recusa como fora do limite (nada de lixo virando 0). */
    private static function inteiro(string $valor): int
    {
        return 1 === preg_match('/^\d{1,6}$/', trim($valor)) ? (int) $valor : -1;
    }
}
