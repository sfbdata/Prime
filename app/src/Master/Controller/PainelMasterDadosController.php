<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\Service\FaixaEtaria;
use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Master\UseCase\ConsultarPedidosDeExclusaoUseCase;
use App\Master\UseCase\ConsultarPlanosUseCase;
use App\Master\UseCase\ConsultarUsuariosOabUseCase;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dados das seções do Painel do Master, em JSON, só leitura. Metadados (contagens, tamanhos, datas,
 * UF/cidade); a lista de contas leva o nome do escritório e do titular, como o desenho pede.
 *
 * Cada conta da lista leva o token CSRF dela (`token`), que o painel envia ao suspender ou reativar
 * ({@see ContasDoSistemaController}): token por conta, inútil para outra conta.
 *
 * A lista de contas leva também os pedidos de exclusão pendentes (`pedidosExclusao`, de todas as contas),
 * para o bloco do desenho acima da tabela; cada pedido leva o token da conta dele, o mesmo da lista. E cada
 * conta leva `pedidoPendente`, para a situação "Suspensa a pedido" da tabela.
 *
 * Idioma (spec idioma-do-sistema, P2b): o rótulo das faixas etárias da Visão geral sai do catálogo no idioma da
 * requisição ({@see FaixaEtaria::chaveDoRotulo()}). O resto é dado (nomes, situação gravada, ação da trilha).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/dados')]
final class PainelMasterDadosController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    #[Route('/visao-geral', name: 'master_dados_visao_geral', methods: ['GET'])]
    public function visaoGeral(ConsultarVisaoGeralUseCase $useCase): JsonResponse
    {
        $dados = $useCase->executar();
        if (is_array($dados['idades'] ?? null) && is_array($dados['idades']['faixas'] ?? null)) {
            $dados['idades']['faixas'] = array_map($this->traduzirFaixa(...), $dados['idades']['faixas']);
        }

        return $this->json($dados);
    }

    private function traduzirFaixa(mixed $faixa): mixed
    {
        if (!is_array($faixa) || !is_string($faixa['rot'] ?? null)) {
            return $faixa;
        }
        $chave = FaixaEtaria::chaveDoRotulo($faixa['rot']);
        if ($chave !== null) {
            $faixa['rot'] = $this->tradutor->trans($chave[0], $chave[1]);
        }

        return $faixa;
    }

    #[Route('/mapa', name: 'master_dados_mapa', methods: ['GET'])]
    public function mapa(ConsultarVisaoGeralUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->mapa());
    }

    #[Route('/contas', name: 'master_dados_contas', methods: ['GET'])]
    public function contas(
        ConsultarContasUseCase $useCase,
        ConsultarPedidosDeExclusaoUseCase $pedidos,
        CsrfTokenManagerInterface $csrf,
        #[Autowire('%tenant_carencia_purga_dias%')]
        int $carenciaPurgaDias,
    ): JsonResponse {
        $pedidosExclusao = array_map(
            static fn (array $p): array => $p + ['token' => $csrf->getToken(ContasDoSistemaController::PREFIXO_CSRF . $p['contaId'])->getValue()],
            $pedidos->executar(),
        );
        // Marca por conta: a tabela mostra "Suspensa a pedido" no lugar de "Ativa" (o Tenant segue ativo durante o pedido).
        $comPedido = array_flip(array_column($pedidosExclusao, 'contaId'));

        $contas = array_map(
            static fn (array $c): array => $c + [
                'token'          => $csrf->getToken(ContasDoSistemaController::PREFIXO_CSRF . $c['id'])->getValue(),
                'pedidoPendente' => isset($comPedido[$c['id']]),
            ],
            $useCase->executar(),
        );

        // A carência vai junto para o modal de "Excluir conta" dizer o prazo configurado, não um número fixo.
        return $this->json(['contas' => $contas, 'carenciaPurgaDias' => $carenciaPurgaDias, 'pedidosExclusao' => $pedidosExclusao]);
    }

    /**
     * Planos do catálogo e faixas de alerta (spec perfis-1.2-planos-e-limites P1 e P6), com o token CSRF de
     * cada plano e o das faixas ({@see PlanosELimitesController}).
     */
    #[Route('/planos', name: 'master_dados_planos', methods: ['GET'])]
    public function planos(ConsultarPlanosUseCase $useCase, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $dados = $useCase->executar();
        $dados['planos'] = array_map(
            static fn (array $p): array => $p + ['token' => $csrf->getToken(PlanosELimitesController::PREFIXO_CSRF_PLANO . $p['codigo'])->getValue()],
            $dados['planos'],
        );

        return $this->json($dados + ['tokenFaixas' => $csrf->getToken(PlanosELimitesController::CSRF_FAIXAS)->getValue()]);
    }

    #[Route('/usuarios-oab', name: 'master_dados_usuarios_oab', methods: ['GET'])]
    public function usuariosOab(ConsultarUsuariosOabUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar());
    }

    #[Route('/auditoria', name: 'master_dados_auditoria', methods: ['GET'])]
    public function auditoria(ConsultarAuditoriaUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar());
    }
}
