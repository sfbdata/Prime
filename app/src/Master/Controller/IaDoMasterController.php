<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\AlterarIaDoEscritorioInput;
use App\Inteligencia\DTO\AlterarRetencaoDaIaInput;
use App\Inteligencia\DTO\EscritorioNaIaDoMasterOutput;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Exception\EscritorioNaoEncontradoException;
use App\Inteligencia\UseCase\AlterarChaveGeralDaIaUseCase;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Inteligencia\UseCase\AlterarRetencaoDaIaUseCase;
use App\Inteligencia\UseCase\EscolherModeloDaIaUseCase;
use App\Inteligencia\UseCase\ObterPainelDaIaDoMasterUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Configurações da I.A." do Painel do Master (spec ia-anthropic-ligar §10; nenhum desenho tem esta tela, então
 * vale o molde das Indicações). Mora no domínio Master, como o IndicacoesDoMasterController: a rota e a guarda são do
 * painel; as regras moram nos UseCases do domínio Inteligencia, que conferem o Master de novo.
 *
 * JSON. Os POST levam o token CSRF `master_ia` no header `X-CSRF-Token` e respondem o mesmo corpo do GET, já com o
 * estado novo; recusa em 422 com `{erro}` (403 no CSRF, 404 no escritório que não existe ou não está ativo).
 * Esta tela não mostra nem recebe a chave da API: só diz se ela está gravada no servidor.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class IaDoMasterController extends AbstractController
{
    public const TOKEN = 'master_ia';
    private const CSRF_INVALIDO = 'master.ia.recusa.token_invalido';

    public function __construct(
        private readonly ObterPainelDaIaDoMasterUseCase $painel,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/ia', name: 'master_ia_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** A chave geral. Corpo `{ligada: bool}`. */
    #[Route('/master/ia/chave-geral', name: 'master_ia_chave_geral', methods: ['POST'])]
    public function chaveGeral(Request $request, AlterarChaveGeralDaIaUseCase $alterar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $ligada = self::corpo($request)['ligada'] ?? null;
        if (!is_bool($ligada)) {
            return $this->recusa('master.ia.recusa.informe_ligada', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $alterar->executar($ligada, $this->master());

        return $this->json($this->estado());
    }

    /** O modelo. Corpo `{modelo: "claude-haiku-5-5"}`; fora do enum é 422. */
    #[Route('/master/ia/modelo', name: 'master_ia_modelo', methods: ['POST'])]
    public function modelo(Request $request, EscolherModeloDaIaUseCase $escolher): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $valor = self::corpo($request)['modelo'] ?? null;
        $modelo = is_string($valor) ? ModeloDeIa::tryFrom($valor) : null;
        if ($modelo === null) {
            return $this->recusa('master.ia.recusa.modelo_invalido', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $escolher->executar($modelo, $this->master());

        return $this->json($this->estado());
    }

    /** Por quantos meses as respostas da IA ficam guardadas. Corpo `{meses: 12}` (inteiro de 1 a 120, ou texto do campo). */
    #[Route('/master/ia/retencao', name: 'master_ia_retencao', methods: ['POST'])]
    public function retencao(Request $request, AlterarRetencaoDaIaUseCase $alterar, ValidatorInterface $validador): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $meses = self::inteiro(self::corpo($request)['meses'] ?? null);
        if ($meses === null) {
            return $this->recusa('master.ia.recusa.retencao_invalida', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $input = new AlterarRetencaoDaIaInput($meses);
        $violacoes = $validador->validate($input);
        if (count($violacoes) > 0) {
            return $this->json(['erro' => (string) $violacoes->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $alterar->executar($input, $this->master());

        return $this->json($this->estado());
    }

    /** Uma linha da tabela. Corpo `{liberada: bool, modoDeCobranca: string, orcamento: "12.50" | null}` (US$). */
    #[Route('/master/ia/escritorios/{tenantId}', name: 'master_ia_escritorio', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function escritorio(int $tenantId, Request $request, AlterarIaDoEscritorioUseCase $alterar, ValidatorInterface $validador): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->recusa(self::CSRF_INVALIDO, Response::HTTP_FORBIDDEN);
        }
        $input = $this->inputDoEscritorio($tenantId, self::corpo($request));
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $violacoes = $validador->validate($input);
        if (count($violacoes) > 0) {
            return $this->json(['erro' => (string) $violacoes->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($input, $this->master());
        } catch (EscritorioNaoEncontradoException) {
            return $this->recusa('master.ia.recusa.escritorio_nao_encontrado', Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->estado());
    }

    /** @param array<mixed> $corpo */
    private function inputDoEscritorio(int $tenantId, array $corpo): AlterarIaDoEscritorioInput|JsonResponse
    {
        if (!is_bool($corpo['liberada'] ?? null)) {
            return $this->recusa('master.ia.recusa.informe_liberada', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_string($corpo['modoDeCobranca'] ?? null)) {
            return $this->recusa('master.ia.recusa.modo_invalido', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $centavos = self::centavos($corpo['orcamento'] ?? null);
        if ($centavos === false) {
            return $this->recusa('master.ia.recusa.orcamento_invalido', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new AlterarIaDoEscritorioInput($tenantId, $corpo['liberada'], $corpo['modoDeCobranca'], $centavos);
    }

    /** @return array<string, mixed> */
    private function estado(): array
    {
        $saida = $this->painel->executar();

        return [
            'instalacao' => ['chaveDaApiGravada' => $saida->chaveDaApiGravada, 'travaDoServidorLigada' => $saida->travaDoServidorLigada],
            'ligadaParaTodos' => $saida->ligadaParaTodos,
            'modelo' => $saida->modelo?->value,
            'mesesDeRetencao' => $saida->mesesDeRetencao,
            'modelos' => array_map(static fn (ModeloDeIa $m): array => ['valor' => $m->value, 'rotulo' => $m->rotulo()] + $m->precoPorMilhao(), ModeloDeIa::cases()),
            'modos' => array_map(static fn (ModoDeCobrancaDaIa $m): array => ['valor' => $m->value, 'rotulo' => $m->rotulo()], ModoDeCobrancaDaIa::cases()),
            'escritorios' => array_map(self::escritorioEmJson(...), $saida->escritorios),
        ];
    }

    /** @return array<string, mixed> */
    private static function escritorioEmJson(EscritorioNaIaDoMasterOutput $e): array
    {
        return [
            'tenantId' => $e->tenantId,
            'nome' => $e->nome,
            'liberada' => $e->liberada,
            'aceiteEm' => $e->aceiteEm?->format('Y-m-d'),
            'modoDeCobranca' => $e->modoDeCobranca->value,
            'orcamento' => $e->orcamentoMensalCentavos === null ? null : self::dolares($e->orcamentoMensalCentavos),
            'analisesNoMes' => $e->analisesNoMes,
            'gastoNoMes' => self::dolares($e->gastoNoMesCentavos),
            'orcamentoAtingido' => $e->orcamentoAtingido(),
            'acimaDoGratis' => $e->acimaDoGratis(),
        ];
    }

    /** Centavos em dólares com duas casas e ponto ("12.50"), o formato do campo do orçamento. */
    private static function dolares(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''));
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

    /** Inteiro do JSON, ou texto só com dígitos (o valor do campo numérico); o resto é recusado. */
    private static function inteiro(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_string($valor) && preg_match('/^-?\d{1,9}$/', trim($valor)) === 1) {
            return (int) trim($valor);
        }

        return null;
    }

    /**
     * Dólares do campo (texto "12.50" ou "12,50", ou número do JSON) em centavos. Vazio ou nulo = sem orçamento
     * (null); formato que não é valor com até duas casas = false. O sinal passa: negativo é recusado pelo validador.
     */
    private static function centavos(mixed $valor): int|false|null
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            $valor = is_int($valor) ? (string) $valor : sprintf('%.2F', $valor);
        }
        if (!is_string($valor) || preg_match('/^(-?)(\d{1,9})(?:[.,](\d{1,2}))?$/', trim($valor), $m) !== 1) {
            return false;
        }
        $centavos = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$centavos : $centavos;
    }
}
