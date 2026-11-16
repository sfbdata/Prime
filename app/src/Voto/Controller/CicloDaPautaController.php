<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\ArquivarAssembleiaUseCase;
use App\Voto\UseCase\CancelarPautaUseCase;
use App\Voto\UseCase\DesarquivarAssembleiaUseCase;
use App\Voto\UseCase\DevolverPautaUseCase;
use App\Voto\UseCase\ExcluirRascunhoDaAssembleiaUseCase;
use App\Voto\UseCase\RetirarPautaDaOrdemDoDiaUseCase;
use App\Voto\UseCase\RetomarPautaUseCase;
use App\Voto\UseCase\SuspenderPautaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ciclo de vida da votação (desenho de 14 e 15/11; decisão C6): suspender, retomar, cancelar e retirar de pauta valem na
 * PAUTA; arquivar, desarquivar e excluir rascunho valem na ASSEMBLEIA. O motivo vem no campo `motivo` do POST. Mesma
 * guarda, mesmo CSRF (`voto_assembleia_{id}`) e mesma volta (painel da Gestão eleitoral, na assembleia) da
 * MesaDaAssembleiaController; de outro escritório dá 404.
 */
final class CicloDaPautaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/voto/assembleias/{id}/pautas/{pauta}/suspender', name: 'voto_assembleia_pauta_suspender', requirements: ['id' => '\d+', 'pauta' => '\d+'], methods: ['POST'])]
    public function suspender(int $id, int $pauta, Request $request, SuspenderPautaUseCase $suspender): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();
        $motivo = self::motivo($request);

        return $this->executar($request, $id, static function () use ($suspender, $tenant, $id, $pauta, $motivo, $por): void {
            $suspender->executar($tenant, $id, $pauta, $motivo, $por);
        }, 'voto.assembleia.gestor.ciclo.suspensa');
    }

    #[Route('/voto/assembleias/{id}/pautas/{pauta}/retomar', name: 'voto_assembleia_pauta_retomar', requirements: ['id' => '\d+', 'pauta' => '\d+'], methods: ['POST'])]
    public function retomar(int $id, int $pauta, Request $request, RetomarPautaUseCase $retomar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();

        return $this->executar($request, $id, static function () use ($retomar, $tenant, $id, $pauta, $por): void {
            $retomar->executar($tenant, $id, $pauta, $por);
        }, 'voto.assembleia.gestor.ciclo.retomada');
    }

    #[Route('/voto/assembleias/{id}/pautas/{pauta}/cancelar', name: 'voto_assembleia_pauta_cancelar', requirements: ['id' => '\d+', 'pauta' => '\d+'], methods: ['POST'])]
    public function cancelar(int $id, int $pauta, Request $request, CancelarPautaUseCase $cancelar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();
        $motivo = self::motivo($request);

        return $this->executar($request, $id, static function () use ($cancelar, $tenant, $id, $pauta, $motivo, $por): void {
            $cancelar->executar($tenant, $id, $pauta, $motivo, $por);
        }, 'voto.assembleia.gestor.ciclo.cancelada');
    }

    #[Route('/voto/assembleias/{id}/pautas/{pauta}/retirar', name: 'voto_assembleia_pauta_retirar', requirements: ['id' => '\d+', 'pauta' => '\d+'], methods: ['POST'])]
    public function retirar(int $id, int $pauta, Request $request, RetirarPautaDaOrdemDoDiaUseCase $retirar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();
        $motivo = self::motivo($request);

        return $this->executar($request, $id, static function () use ($retirar, $tenant, $id, $pauta, $motivo, $por): void {
            $retirar->executar($tenant, $id, $pauta, $motivo, $por);
        }, 'voto.assembleia.gestor.ciclo.retirada');
    }

    #[Route('/voto/assembleias/{id}/pautas/{pauta}/devolver', name: 'voto_assembleia_pauta_devolver', requirements: ['id' => '\d+', 'pauta' => '\d+'], methods: ['POST'])]
    public function devolver(int $id, int $pauta, Request $request, DevolverPautaUseCase $devolver): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();

        return $this->executar($request, $id, static function () use ($devolver, $tenant, $id, $pauta, $por): void {
            $devolver->executar($tenant, $id, $pauta, $por);
        }, 'voto.assembleia.gestor.ciclo.desarquivada');
    }

    #[Route('/voto/assembleias/{id}/arquivar', name: 'voto_assembleia_arquivar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function arquivar(int $id, Request $request, ArquivarAssembleiaUseCase $arquivar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();
        $motivo = self::motivo($request);

        return $this->executar($request, $id, static function () use ($arquivar, $tenant, $id, $motivo, $por): void {
            $arquivar->executar($tenant, $id, $motivo, $por);
        }, 'voto.assembleia.gestor.ciclo.arquivada');
    }

    #[Route('/voto/assembleias/{id}/desarquivar', name: 'voto_assembleia_desarquivar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function desarquivar(int $id, Request $request, DesarquivarAssembleiaUseCase $desarquivar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();

        return $this->executar($request, $id, static function () use ($desarquivar, $tenant, $id, $por): void {
            $desarquivar->executar($tenant, $id, $por);
        }, 'voto.assembleia.gestor.ciclo.desarquivada');
    }

    #[Route('/voto/assembleias/{id}/excluir', name: 'voto_assembleia_excluir_rascunho', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluirRascunho(int $id, Request $request, ExcluirRascunhoDaAssembleiaUseCase $excluir): RedirectResponse
    {
        $tenant = $this->guarda->exigir();

        return $this->executar($request, $id, static function () use ($excluir, $tenant, $id): void {
            $excluir->executar($tenant, $id);
        }, 'voto.assembleia.gestor.ciclo.rascunho_excluido');
    }

    private static function motivo(Request $request): string
    {
        return (string) $request->request->get('motivo', '');
    }

    private function executar(Request $request, int $id, callable $acao, string $avisoOk): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->voltar($request, $id);
        }
        try {
            $acao();
            $this->avisos->ok($this->tradutor->trans($avisoOk));
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->voltar($request, $id);
    }

    /** Volta para a Gestão eleitoral; o menu da aba Votar manda `voltar=votar` e volta para a própria aba (valor fixo, nunca URL livre). */
    private function voltar(Request $request, int $id): RedirectResponse
    {
        if ('votar' === $request->request->get('voltar')) {
            return $this->redirectToRoute('voto_index', ['aba' => 'ele']);
        }

        return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
    }
}
