<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\Exception\AssinaturaDaFolhaRecusadaException;
use App\Ponto\UseCase\AssinarFolhaDoPontoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Assinar folha" da /ponto (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 15 e 19; desenho 16 L290 e L809):
 * o envio da janela do aceite mensal. Exige login, o escritório da sessão e o módulo Ponto; quem assina é sempre a
 * própria pessoa, no escritório da sessão (nada no pedido escolhe outra pessoa ou outro escritório).
 *
 * Volta para a /ponto na competência, como as janelas da E4: "Folha assinada." no aviso flutuante verde do desenho
 * (`avisar` L809; `PontoController::FLASH_AVISO_FLUTUANTE`, decisão 15) ou a frase da recusa no aviso do topo. Depois
 * de uma recusa só há o aviso e a volta: a corrida de dois envios fecha o EntityManager.
 */
final class AceiteDaFolhaController extends AbstractController
{
    public const FOLHA_ASSINADA = 'Folha assinada.';
    public const TOKEN_INVALIDO = 'Token de segurança inválido.';

    public function __construct(
        private readonly AssinarFolhaDoPontoUseCase $assinarFolha,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/ponto/folha/assinar', name: 'ponto_folha_assinar', methods: ['POST'])]
    public function assinar(Request $request): RedirectResponse
    {
        [$pessoa, $escritorio] = $this->pessoaComOPonto();
        $competencia = (string) $request->request->get('competencia', '');
        if (!$this->isCsrfTokenValid('ponto_folha_assinar', (string) $request->request->get('_token', ''))) {
            return $this->voltar($competencia, 'danger', self::TOKEN_INVALIDO);
        }

        $pedido = $this->pedido($request, $competencia);
        $violacoes = $this->validator->validate($pedido);
        if (count($violacoes) > 0) {
            return $this->voltar($competencia, 'warning', (string) $violacoes->get(0)->getMessage());
        }

        try {
            $this->assinarFolha->executar($pedido, $pessoa, $escritorio);
        } catch (AssinaturaDaFolhaRecusadaException $recusa) {
            return $this->voltar($competencia, 'warning', $recusa->getMessage());
        }

        return $this->voltar($competencia, PontoController::FLASH_AVISO_FLUTUANTE, self::FOLHA_ASSINADA);
    }

    /** @return array{User, Tenant} */
    private function pessoaComOPonto(): array
    {
        $pessoa = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$pessoa instanceof User || $escritorio === null || !$this->permissionChecker->canAccessModule($pessoa, $escritorio, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        return [$pessoa, $escritorio];
    }

    /** A competência no formato da lista da folha (AAAA-MM); fora dele, ano e mês zerados, que o DTO recusa. */
    private function pedido(Request $request, string $competencia): AssinarFolhaInput
    {
        [$ano, $mes] = preg_match('/^(\d{4})-(\d{2})$/D', $competencia, $partes) === 1 ? [(int) $partes[1], (int) $partes[2]] : [0, 0];

        return new AssinarFolhaInput(
            $ano,
            $mes,
            (string) $request->request->get('situacao', ''),
            (string) $request->request->get('ressalva', ''),
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );
    }

    /** Volta para a /ponto no mês que a pessoa via (só um mês bem formado vai no endereço). */
    private function voltar(string $competencia, string $tipo, string $mensagem): RedirectResponse
    {
        $this->addFlash($tipo, $mensagem);

        return $this->redirectToRoute('ponto_index', preg_match('/^\d{4}-\d{2}$/D', $competencia) === 1 ? ['competencia' => $competencia] : []);
    }
}
