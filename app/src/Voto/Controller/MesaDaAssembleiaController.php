<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AbrirPautaUseCase;
use App\Voto\UseCase\DefinirPrazoDaPautaUseCase;
use App\Voto\UseCase\EncerrarPautaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A mesa, por pauta (spec voto-assembleia-condominio, seção 1.7; insumo do desenho §5): abrir por minutos ou sem
 * prazo (1ª ou 2ª convocação), definir ou retirar o prazo e encerrar com motivo. Volta para onde a mesa estava (o
 * painel da Gestão eleitoral ou a tela de pautas da assembleia).
 */
#[Route('/voto/assembleias/{id}/pautas/{pauta}', requirements: ['id' => '\d+', 'pauta' => '\d+'])]
final class MesaDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/abrir', name: 'voto_assembleia_pauta_abrir', methods: ['POST'])]
    public function abrir(int $id, int $pauta, Request $request, AbrirPautaUseCase $abrir): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $nome = $this->guarda->nomeDeQuemOpera();
        $convocacao = $request->request->getInt('convocacao', 1);
        $minutos = self::minutos($request);
        $this->executar($request, $id, static function () use ($abrir, $convocacao, $id, $minutos, $nome, $pauta, $tenant): void {
            $abrir->executar($tenant, $id, $pauta, $convocacao, $minutos, $nome);
        }, 'voto.assembleia.gestor.aviso.aberta');

        return $this->voltar($request, $id, $pauta);
    }

    #[Route('/prazo', name: 'voto_assembleia_pauta_prazo', methods: ['POST'])]
    public function prazo(int $id, int $pauta, Request $request, DefinirPrazoDaPautaUseCase $definir): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $minutos = self::minutos($request);
        $aviso = null === $minutos ? 'voto.assembleia.gestor.aviso.prazo_retirado' : 'voto.assembleia.gestor.aviso.prazo_definido';
        $this->executar($request, $id, static function () use ($definir, $id, $minutos, $pauta, $tenant): void {
            $definir->executar($tenant, $id, $pauta, $minutos);
        }, $aviso);

        return $this->voltar($request, $id, $pauta);
    }

    #[Route('/encerrar', name: 'voto_assembleia_pauta_encerrar', methods: ['POST'])]
    public function encerrar(int $id, int $pauta, Request $request, EncerrarPautaUseCase $encerrar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $nome = $this->guarda->nomeDeQuemOpera();
        $motivo = (string) $request->request->get('motivo', '');
        $this->executar($request, $id, static function () use ($encerrar, $id, $motivo, $nome, $pauta, $tenant): void {
            $encerrar->executar($tenant, $id, $pauta, $nome, $motivo);
        }, 'voto.assembleia.gestor.aviso.encerrada');

        return $this->voltar($request, $id, $pauta);
    }

    /** "Abrir sem prazo" e "Sem prazo" mandam modo=sem; os outros botões mandam os minutos digitados. */
    private static function minutos(Request $request): ?int
    {
        if ('sem' === $request->request->get('modo')) {
            return null;
        }
        $minutos = trim((string) $request->request->get('minutos', ''));

        return 1 === preg_match('/^\d{1,5}$/', $minutos) ? (int) $minutos : 0;
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

    private function voltar(Request $request, int $id, int $pauta): RedirectResponse
    {
        if ('pautas' === $request->request->get('voltar')) {
            return $this->redirectToRoute('voto_assembleia_pautas', ['id' => $id, '_fragment' => 'pauta-' . $pauta]);
        }

        return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
    }
}
