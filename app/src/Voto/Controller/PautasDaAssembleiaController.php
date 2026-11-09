<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\PautaInput;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AdicionarPautaUseCase;
use App\Voto\UseCase\EditarPautaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\UseCase\RemoverPautaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pautas da assembleia (spec voto-assembleia-condominio, seção 1.3; insumo do desenho §2, etapa 4). Em rascunho, a
 * tela inclui, altera e remove pautas (opções numeradas, padrão 1 Sim e 2 Não; a abstenção é automática). Depois de
 * publicada, a mesma tela é a mesa: cada pauta com as ações de abrir, prazo e encerrar (MesaDaAssembleiaController).
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
        $this->executar($request, $id, static function () use ($adicionar, $id, $input, $tenant): void {
            $adicionar->executar($tenant, $id, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_incluida');

        return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]);
    }

    #[Route('/{pauta}/editar', name: 'voto_assembleia_pauta_editar', methods: ['POST'])]
    public function editar(int $id, int $pauta, Request $request, EditarPautaUseCase $editar): Response
    {
        $tenant = $this->guarda->exigir();
        $input = self::input($request);
        $this->executar($request, $id, static function () use ($editar, $id, $input, $pauta, $tenant): void {
            $editar->executar($tenant, $id, $pauta, $input);
        }, 'voto.assembleia.gestor.aviso.pauta_salva');

        return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
    }

    #[Route('/{pauta}/remover', name: 'voto_assembleia_pauta_remover', methods: ['POST'])]
    public function remover(int $id, int $pauta, Request $request, RemoverPautaUseCase $remover): Response
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, static function () use ($id, $pauta, $remover, $tenant): void {
            $remover->executar($tenant, $id, $pauta);
        }, 'voto.assembleia.gestor.aviso.pauta_removida');

        return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id]);
    }

    private function executar(Request $request, int $id, callable $acao, string $avisoOk): void
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return;
        }
        try {
            $acao();
            $this->avisos->ok($this->tradutor->trans($avisoOk));
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
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

        return new PautaInput((string) $request->request->get('titulo', ''), (string) $request->request->get('descricao', ''), $opcoes);
    }
}
