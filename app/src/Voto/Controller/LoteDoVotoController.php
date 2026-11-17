<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\ResultadoDoLote;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\ArquivarAssembleiasEmLoteUseCase;
use App\Voto\UseCase\ExcluirRascunhosEmLoteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seleção em lote da Gestão eleitoral (desenho 18, A6): arquivar as marcadas com um motivo único e excluir os rascunhos
 * nunca publicados. Os ids vêm em `ids[]`; as que não se encaixam ficam como estão e são contadas no aviso. CSRF
 * `voto_assembleia_lote`. Uma assembleia de outro escritório dá 404 para o lote inteiro, sem mexer em nenhuma. As que a equipe própria deixa só
 * para leitura (ou esconde) da pessoa saem do lote e entram na contagem das mantidas (teto da equipe, seção 1.2 da spec
 * voto-equipe-e-master-1611).
 */
#[Route('/voto/assembleias/lote')]
final class LoteDoVotoController extends AbstractController
{
    /** Teto de itens por pedido (o painel lista no máximo 100 assembleias). */
    private const MAXIMO = 100;

    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AcessoAAssembleia $acesso,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/arquivar', name: 'voto_assembleia_lote_arquivar', methods: ['POST'])]
    public function arquivar(Request $request, ArquivarAssembleiasEmLoteUseCase $arquivar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $por = $this->guarda->nomeDeQuemOpera();
        $motivo = (string) $request->request->get('motivo', '');

        return $this->executar($request, $tenant, static fn (array $ids): ResultadoDoLote => $arquivar->executar($tenant, $ids, $motivo, $por), 'arquivadas', 'nenhuma_arquivar');
    }

    #[Route('/excluir', name: 'voto_assembleia_lote_excluir', methods: ['POST'])]
    public function excluir(Request $request, ExcluirRascunhosEmLoteUseCase $excluir): RedirectResponse
    {
        $tenant = $this->guarda->exigir();

        return $this->executar($request, $tenant, static fn (array $ids): ResultadoDoLote => $excluir->executar($tenant, $ids), 'excluidas', 'nenhuma_excluir');
    }

    /** @param callable(list<int>): ResultadoDoLote $acao */
    private function executar(Request $request, Tenant $tenant, callable $acao, string $avisoOk, string $avisoNenhuma): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_lote', (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return $this->voltar();
        }
        $ids = self::ids($request);
        if ([] === $ids) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.lote.nenhuma'));

            return $this->voltar();
        }
        // Teto da equipe: o que a pessoa só lê (ou não vê) não entra no lote; conta como "mantida".
        $permitidos = $this->acesso->idsQueEdita($this->usuario(), $tenant, $ids);
        try {
            $resultado = $acao($permitidos);
            $resultado = new ResultadoDoLote($resultado->feitas, $resultado->mantidas + \count($ids) - \count($permitidos));
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());

            return $this->voltar();
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
        $this->avisar($resultado, $avisoOk, $avisoNenhuma);

        return $this->voltar();
    }

    private function usuario(): User
    {
        $usuario = $this->getUser();

        return $usuario instanceof User ? $usuario : throw $this->createAccessDeniedException();
    }

    private function avisar(ResultadoDoLote $resultado, string $avisoOk, string $avisoNenhuma): void
    {
        if (0 === $resultado->feitas) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.lote.' . $avisoNenhuma));

            return;
        }
        $texto = $this->tradutor->trans('voto.assembleia.gestor.lote.' . $avisoOk, ['n' => $resultado->feitas]);
        if ($resultado->mantidas > 0) {
            $texto .= ' ' . $this->tradutor->trans('voto.assembleia.gestor.lote.fora_feito', ['n' => $resultado->mantidas]);
        }
        $this->avisos->ok($texto);
    }

    /** @return list<int> ids inteiros positivos, sem repetição, no máximo {@see self::MAXIMO} */
    private static function ids(Request $request): array
    {
        $ids = [];
        foreach ($request->request->all('ids') as $bruto) {
            if (is_scalar($bruto) && ctype_digit((string) $bruto) && (int) $bruto > 0) {
                $ids[(int) $bruto] = (int) $bruto;
            }
        }

        return \array_slice(array_values($ids), 0, self::MAXIMO);
    }

    private function voltar(): RedirectResponse
    {
        return $this->redirectToRoute('voto_index', ['aba' => 'ges']);
    }
}
