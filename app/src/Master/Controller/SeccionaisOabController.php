<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\AdicionarSubsecaoNaSeccionalInput;
use App\Master\DTO\EditarSedeDaSeccionalInput;
use App\Master\DTO\RegistrarDivulgacaoNaSeccionalInput;
use App\Master\DTO\RemoverSubsecaoDaSeccionalInput;
use App\Master\DTO\SalvarNotaDaSeccionalInput;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Exception\RecusaDaSeccional;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Exception\SubsecaoNaoEncontrada;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Master\UseCase\AdicionarSubsecaoNaSeccionalUseCase;
use App\Master\UseCase\EditarSedeDaSeccionalUseCase;
use App\Master\UseCase\ListarSeccionaisUseCase;
use App\Master\UseCase\RegistrarDivulgacaoNaSeccionalUseCase;
use App\Master\UseCase\RemoverSubsecaoDaSeccionalUseCase;
use App\Master\UseCase\SalvarNotaDaSeccionalUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seccionais da OAB no mapa "Onde estamos atuando" do Painel do Master (spec docs/specs/master-seccionais-oab.md;
 * desenho 05, Perfis 1.2, VS 21.10.26, L116-127). Só o Master: ROLE_SUPER_ADMIN na classe.
 *
 * GET devolve as 27 seccionais, o token CSRF `master_seccionais` (o painel o manda de volta, como o
 * `tokenFaixas` dos planos) e o molde da URL da sede. Os POST levam corpo JSON e o token no header `X-CSRF-Token`; respondem
 * `{mensagem}` no sucesso e `{erro}` na recusa (403 token, 404 UF fora das 27, 422 regra).
 *
 * Janela da OAB dos estados que faltam (spec docs/specs/master-oab-estados-que-faltam.md; desenho L481-495): o GET
 * traz também as subseções e a nota de cada UF e os moldes das URLs de subseção e de nota; os três POST (adicionar
 * subseção, remover subseção, salvar nota) seguem o mesmo contrato dos de cima.
 *
 * Idioma (spec idioma-do-sistema, P2a da b5): a resposta e a recusa do próprio controller saem do catálogo
 * (master.seccionais.*) no idioma da requisição. P2b: a recusa dos UseCases (SeccionalRecusada, SeccionalNaoEncontrada,
 * SubsecaoNaoEncontrada) também, pela chave e pelos parâmetros que a exceção leva ({@see RecusaDaSeccional}); e o rótulo
 * do tipo de divulgação (lista e resposta), pela chave do TipoDeDivulgacao.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class SeccionaisOabController extends AbstractController
{
    public const CSRF = 'master_seccionais';

    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    #[Route('/master/dados/seccionais', name: 'master_dados_seccionais', methods: ['GET'])]
    public function listar(ListarSeccionaisUseCase $listar, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        return $this->json([
            'seccionais' => array_map($this->traduzirDivulgacoes(...), $listar->executar()),
            'token'      => $csrf->getToken(self::CSRF)->getValue(),
            // Molde da URL de editar a sede (o painel troca XX pela UF): o bloco `urls` do painel leva só o GET.
            'urlSede'    => $this->generateUrl('master_seccional_sede', ['uf' => 'XX']),
            // Moldes da janela da OAB: XX é a UF; 0 é o id da subseção.
            'urlSubsecoes'       => $this->generateUrl('master_seccional_subsecao_adicionar', ['uf' => 'XX']),
            'urlSubsecaoRemover' => $this->generateUrl('master_seccional_subsecao_remover', ['uf' => 'XX', 'id' => 0]),
            'urlNota'            => $this->generateUrl('master_seccional_nota', ['uf' => 'XX']),
        ]);
    }

    #[Route('/master/seccionais/{uf}/subsecoes', name: 'master_seccional_subsecao_adicionar', requirements: ['uf' => '[A-Z]{2}'], methods: ['POST'])]
    public function adicionarSubsecao(string $uf, Request $request, AdicionarSubsecaoNaSeccionalUseCase $adicionar): JsonResponse
    {
        return $this->gravar($uf, $request, function (array $corpo) use ($uf, $adicionar): string {
            $subsecao = $adicionar->executar(AdicionarSubsecaoNaSeccionalInput::doJson($this->ator(), $uf, $corpo));

            return $this->tradutor->trans('master.seccionais.subsecao_adicionada', ['seccional' => $subsecao->getSeccional()->getNome()]);
        });
    }

    #[Route('/master/seccionais/{uf}/subsecoes/{id}/remover', name: 'master_seccional_subsecao_remover', requirements: ['uf' => '[A-Z]{2}', 'id' => '\d+'], methods: ['POST'])]
    public function removerSubsecao(string $uf, int $id, Request $request, RemoverSubsecaoDaSeccionalUseCase $remover): JsonResponse
    {
        return $this->gravar($uf, $request, function () use ($uf, $id, $remover): string {
            $subsecao = $remover->executar(new RemoverSubsecaoDaSeccionalInput($this->ator(), $uf, $id));

            return $this->tradutor->trans('master.seccionais.subsecao_removida', ['seccional' => $subsecao->getSeccional()->getNome()]);
        });
    }

    #[Route('/master/seccionais/{uf}/nota', name: 'master_seccional_nota', requirements: ['uf' => '[A-Z]{2}'], methods: ['POST'])]
    public function salvarNota(string $uf, Request $request, SalvarNotaDaSeccionalUseCase $salvar): JsonResponse
    {
        return $this->gravar($uf, $request, function (array $corpo) use ($uf, $salvar): string {
            $input = SalvarNotaDaSeccionalInput::doJson($this->ator(), $uf, $corpo);
            $seccional = $salvar->executar($input);

            return $this->tradutor->trans($input->texto === null ? 'master.seccionais.nota_apagada' : 'master.seccionais.nota_salva', ['seccional' => $seccional->getNome()]);
        });
    }

    #[Route('/master/seccionais/{uf}/sede', name: 'master_seccional_sede', requirements: ['uf' => '[A-Z]{2}'], methods: ['POST'])]
    public function editarSede(string $uf, Request $request, EditarSedeDaSeccionalUseCase $editar): JsonResponse
    {
        return $this->gravar($uf, $request, function (array $corpo) use ($uf, $editar): string {
            $seccional = $editar->executar(EditarSedeDaSeccionalInput::doJson($this->ator(), $uf, $corpo));

            return $this->tradutor->trans('master.seccionais.sede_atualizada', ['seccional' => $seccional->getNome()]);
        });
    }

    #[Route('/master/seccionais/{uf}/divulgacoes', name: 'master_seccional_divulgar', requirements: ['uf' => '[A-Z]{2}'], methods: ['POST'])]
    public function divulgar(string $uf, Request $request, RegistrarDivulgacaoNaSeccionalUseCase $registrar): JsonResponse
    {
        return $this->gravar($uf, $request, function (array $corpo) use ($uf, $registrar): string {
            $divulgacao = $registrar->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->ator(), $uf, $corpo));

            return $this->tradutor->trans('master.seccionais.divulgacao_registrada', ['seccional' => $divulgacao->getSeccional()->getNome(), 'tipo' => $this->tradutor->trans($divulgacao->getTipo()->chave())]);
        });
    }

    /** @param callable(array<mixed>): string $acao */
    private function gravar(string $uf, Request $request, callable $acao): JsonResponse
    {
        if (!in_array($uf, LocalizacaoDaPessoa::UFS, true)) {
            return $this->json(['erro' => $this->tradutor->trans('master.seccionais.nao_encontrada')], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => $this->tradutor->trans('master.seccionais.token_invalido')], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json(['mensagem' => $acao($this->corpo($request))]);
        } catch (SeccionalNaoEncontrada|SubsecaoNaoEncontrada $e) {
            return $this->json(['erro' => $this->recusa($e)], Response::HTTP_NOT_FOUND);
        } catch (SeccionalRecusada $e) {
            return $this->json(['erro' => $this->recusa($e)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function recusa(RecusaDaSeccional $e): string
    {
        return $this->tradutor->trans($e->chave(), $e->parametros());
    }

    /**
     * O rótulo do tipo da última divulgação e das últimas cinco, no idioma da requisição (o UseCase manda o português).
     *
     * @template T of array{ultima: ?array{tipo: string, rotulo: string, data: string}, ultimas: list<array{tipo: string, rotulo: string, data: string, autor: ?string, nota: ?string}>}
     *
     * @param T $seccional
     *
     * @return T
     */
    private function traduzirDivulgacoes(array $seccional): array
    {
        if ($seccional['ultima'] !== null) {
            $seccional['ultima']['rotulo'] = $this->rotuloDoTipo($seccional['ultima']['tipo'], $seccional['ultima']['rotulo']);
        }
        foreach ($seccional['ultimas'] as $i => $divulgacao) {
            $seccional['ultimas'][$i]['rotulo'] = $this->rotuloDoTipo($divulgacao['tipo'], $divulgacao['rotulo']);
        }

        return $seccional;
    }

    /** Tipo desconhecido (dado antigo) fica com o rótulo que veio. */
    private function rotuloDoTipo(string $tipo, string $rotulo): string
    {
        $conhecido = TipoDeDivulgacao::tryFrom($tipo);

        return $conhecido === null ? $rotulo : $this->tradutor->trans($conhecido->chave());
    }

    private function ator(): ?User
    {
        $ator = $this->getUser();

        return $ator instanceof User ? $ator : null;
    }

    /** @return array<mixed> */
    private function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
