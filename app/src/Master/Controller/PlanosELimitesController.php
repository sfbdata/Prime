<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Contribua\DTO\AlterarPlanoInput;
use App\Contribua\UseCase\AlterarPlanoUseCase;
use App\Contribua\UseCase\ConfigurarFaixasDoEspacoUseCase;
use App\Entity\Auth\User;
use App\Master\DTO\EspacoDaContaOutput;
use App\Master\DTO\LimiteDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\UseCase\DefinirLimiteDaContaUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Escritas de "Planos e limites" do Painel do Master (spec docs/specs/perfis-1.2-planos-e-limites.md P2, P4
 * e P6; desenho 05 - Perfis 1.2): limite de armazenamento e de usuários de uma conta, edição de um plano
 * do catálogo e faixas de alerta com a chave do bloqueio. Exclusivo do Master (ROLE_SUPER_ADMIN).
 *
 * As regras moram na Contribua (contrato da spec perfis-1.2-contas §7); as recusas chegam como
 * \DomainException, com o texto do desenho, e voltam em JSON 422 para o modal mostrar. A auditoria é
 * deste lado: limites no escopo da conta alvo ({@see DefinirLimiteDaContaUseCase}), plano e faixas no
 * escopo de plataforma (sem escritório).
 *
 * CSRF: o limite usa o token da conta (`master_conta_<id>`, o mesmo de suspender e reativar, que vem em
 * /master/dados/contas); o plano, `master_plano_<código>`; as faixas, `master_faixas` (os dois vêm em
 * /master/dados/planos).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PlanosELimitesController extends AbstractController
{
    public const PREFIXO_CSRF_PLANO = 'master_plano_';
    public const CSRF_FAIXAS = 'master_faixas';

    private const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';

    public function __construct(
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    #[Route('/master/contas/{id}/limite-armazenamento', name: 'master_conta_limite_armazenamento', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function limiteDeArmazenamento(int $id, Request $request, DefinirLimiteDaContaUseCase $definir): JsonResponse
    {
        return $this->definirLimite($id, $request, LimiteDaContaInput::ARMAZENAMENTO, $definir);
    }

    #[Route('/master/contas/{id}/limite-usuarios', name: 'master_conta_limite_usuarios', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function limiteDeUsuarios(int $id, Request $request, DefinirLimiteDaContaUseCase $definir): JsonResponse
    {
        return $this->definirLimite($id, $request, LimiteDaContaInput::USUARIOS, $definir);
    }

    #[Route('/master/planos/{codigo}', name: 'master_plano_alterar', requirements: ['codigo' => '[a-z0-9_-]+'], methods: ['POST'])]
    public function alterarPlano(string $codigo, Request $request, AlterarPlanoUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::PREFIXO_CSRF_PLANO . $codigo, $request->request->getString('_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }
        $cotaGb = self::inteiro($request->request->getString('cotaGb'));
        $precoCentavos = self::inteiro($request->request->getString('precoCentavos'));
        if ($cotaGb === null || $precoCentavos === null) {
            return $this->json(['erro' => 'Informe o espaço em GB e o preço em reais.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $input = new AlterarPlanoInput(
            $codigo,
            $request->request->getString('nome'),
            $cotaGb,
            $precoCentavos,
            self::inteiro($request->request->getString('limiteUsuarios')),
            $request->request->getString('descricao'),
            $request->request->getBoolean('ativo'),
        );

        return $this->naPlataforma(static fn () => $alterar->executar($input), sprintf('Plano "%s" atualizado.', trim($input->nome)));
    }

    #[Route('/master/faixas', name: 'master_faixas_configurar', methods: ['POST'])]
    public function configurarFaixas(Request $request, ConfigurarFaixasDoEspacoUseCase $configurar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_FAIXAS, $request->request->getString('_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }
        // Valor que não é inteiro vira 0, e a Contribua recusa com "As faixas precisam crescer em ordem, de 1% a 100%."
        [$atencao, $alerta, $critico, $bloqueio] = array_map(
            static fn (string $campo): int => self::inteiro($request->request->getString($campo)) ?? 0,
            ['atencao', 'alerta', 'critico', 'bloqueio'],
        );
        $ligado = $request->request->getBoolean('bloqueioAtivo');

        return $this->naPlataforma(static fn () => $configurar->executar($atencao, $alerta, $critico, $bloqueio, $ligado), 'Faixas de alerta atualizadas.');
    }

    private function definirLimite(int $id, Request $request, string $tipo, DefinirLimiteDaContaUseCase $definir): JsonResponse
    {
        if (!$this->isCsrfTokenValid(ContasDoSistemaController::PREFIXO_CSRF . $id, $request->request->getString('_token'))) {
            return $this->json(['erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
        }

        try {
            $retrato = $definir->executar(new LimiteDaContaInput(
                $this->ator(),
                $id,
                $tipo,
                self::inteiro($request->request->getString('valor')),
                $tipo === LimiteDaContaInput::ARMAZENAMENTO && $request->request->getBoolean('semLimite'),
                $request->request->getString('justificativa'),
            ));
        } catch (ContaNaoEncontrada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mensagem = $tipo === LimiteDaContaInput::ARMAZENAMENTO ? 'Limite de armazenamento atualizado.' : 'Limite de usuários atualizado.';

        return $this->json(['mensagem' => $mensagem, 'conta' => ['id' => $id, 'espaco' => EspacoDaContaOutput::doRetrato($retrato)->paraArray()]]);
    }

    /** Plano e faixas valem para todos os escritórios: a linha da auditoria fica sem escritório. */
    private function naPlataforma(callable $acao, string $mensagem): JsonResponse
    {
        try {
            $this->escopoDePlataforma->executar($acao);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['mensagem' => $mensagem]);
    }

    /** "25" vira 25; vazio, decimal ou texto viram nulo (quem chama decide a mensagem). */
    private static function inteiro(string $valor): ?int
    {
        $valor = trim($valor);

        return preg_match('/^-?\d{1,9}$/', $valor) === 1 ? (int) $valor : null;
    }

    private function ator(): User
    {
        $ator = $this->getUser();
        if (!$ator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $ator;
    }
}
