<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Voto\DTO\EscritorioNaLiberacaoDoVotoOutput;
use App\Voto\DTO\EstatisticaDaVotacao;
use App\Voto\DTO\EstatisticasDoVotoOutput;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\Service\CsvDasEstatisticasDoVoto;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use App\Voto\UseCase\ObterEstatisticasDoVotoUseCase;
use App\Voto\UseCase\ObterLiberacoesDoVotoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Voto" do Painel do Master (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b; nenhum desenho tem esta tela,
 * então vale o molde da aba da I.A.). Mora no domínio Master, como o IaDoMasterController: a rota e a guarda são do
 * painel; as regras moram nos UseCases do domínio Voto, que conferem o Master de novo.
 *
 * JSON. O POST leva o token CSRF `master_voto` no header `X-CSRF-Token` e responde o mesmo corpo do GET, já com o
 * estado novo; recusa em 422 com `{erro}` (403 no CSRF, 404 no escritório que não existe ou não está ativo).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class VotoDoMasterController extends AbstractController
{
    public const TOKEN = 'master_voto';

    public function __construct(
        private readonly ObterLiberacoesDoVotoUseCase $liberacoes,
        private readonly ObterEstatisticasDoVotoUseCase $estatisticas,
        private readonly CsvDasEstatisticasDoVoto $csv,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/voto', name: 'master_voto_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** Uma linha da grade. Corpo `{liberado: bool}`; outro tipo é 422. */
    #[Route('/master/voto/escritorios/{tenantId}', name: 'master_voto_escritorio', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function escritorio(int $tenantId, Request $request, AlterarLiberacaoDoVotoUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->recusa('master.voto.recusa.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $liberado = self::corpo($request)['liberado'] ?? null;
        if (!is_bool($liberado)) {
            return $this->recusa('master.voto.recusa.informe_liberado', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($tenantId, $liberado, $this->master());
        } catch (EscritorioNaoEncontradoException) {
            return $this->recusa('master.voto.recusa.escritorio_nao_encontrado', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->estado());
    }

    /**
     * "Estatísticas de todas as votações" (W2-7, bloco C): só leitura, só números agregados, de todos os escritórios.
     * `?categoria=` filtra por cenário (vazio = todas; valor que não existe é 422).
     */
    #[Route('/master/voto/estatisticas', name: 'master_voto_estatisticas', methods: ['GET'])]
    public function estatisticasDados(Request $request): JsonResponse
    {
        $categoria = self::categoria($request);
        if (false === $categoria) {
            return $this->recusa('master.voto.recusa.categoria_invalida', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->estatisticasEmJson($this->estatisticas->executar($this->master(), $categoria), $categoria));
    }

    #[Route('/master/voto/estatisticas.csv', name: 'master_voto_estatisticas_csv', methods: ['GET'])]
    public function estatisticasCsv(Request $request): Response
    {
        $categoria = self::categoria($request);
        if (false === $categoria) {
            return $this->recusa('master.voto.recusa.categoria_invalida', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new Response($this->csv->gerar($this->estatisticas->executar($this->master(), $categoria)), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="estatisticas-votacoes.csv"',
        ]);
    }

    /** @return CenarioDaAssembleia|null|false null = todas; false = valor inválido */
    private static function categoria(Request $request): CenarioDaAssembleia|null|false
    {
        $valor = (string) $request->query->get('categoria', '');

        return '' === $valor ? null : (CenarioDaAssembleia::tryFrom($valor) ?? false);
    }

    /** @return array<string, mixed> */
    private function estatisticasEmJson(EstatisticasDoVotoOutput $e, ?CenarioDaAssembleia $categoria): array
    {
        return [
            'categoria' => $categoria->value ?? '',
            'categorias' => array_map(static fn (CenarioDaAssembleia $c): array => ['id' => $c->value, 'rotulo' => $c->rotulo()], CenarioDaAssembleia::cases()),
            'kpis' => [
                'votacoes' => $e->votacoes(),
                'realizadas' => $e->realizadas(),
                'emAndamento' => $e->emAndamento(),
                'aptos' => $e->aptosNasRealizadas(),
                'votantes' => $e->votantesNasRealizadas(),
                'participacao' => $e->participacaoNasRealizadas(),
                'quorumAtingido' => $e->quorumAtingido(),
                'quorumExigido' => $e->comQuorumExigido(),
            ],
            'linhas' => array_map(fn (EstatisticaDaVotacao $l): array => [
                'escritorio' => $l->escritorio,
                'entidade' => $l->entidade,
                'assembleia' => $l->assembleia,
                'pauta' => $l->pauta,
                'categoria' => $l->categoria,
                'situacao' => $this->tradutor->trans('master.voto.estatisticas.situacao.' . $l->situacao),
                'cor' => $l->cor(),
                'ate' => $l->fim(),
                'aptos' => $l->aptos,
                'votantes' => $l->votantes,
                'participacao' => $l->participacao(),
                'quorum' => null === $l->quorum ? null : $this->tradutor->trans('master.voto.estatisticas.quorum.' . $l->quorum),
            ], $e->linhas),
        ];
    }

    /** @return array{escritorios: list<array{tenantId: int, nome: string, liberado: bool}>} */
    private function estado(): array
    {
        return [
            'escritorios' => array_map(static fn (EscritorioNaLiberacaoDoVotoOutput $e): array => [
                'tenantId' => $e->tenantId,
                'nome' => $e->nome,
                'liberado' => $e->liberado,
            ], $this->liberacoes->executar($this->master())),
        ];
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function master(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** @return array<mixed> */
    private static function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
