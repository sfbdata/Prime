<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Service\AvisoDoChecklist;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\MensagensDaCobrancaDoChecklist;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Liga ou desliga o checklist de documentação de uma pasta (DOC-73; interruptor "Ativo" do
 * cabeçalho do checklist, desenho 02 - EXPEDIENTES 1.2.3, dc L2093-2117 e `ckcVals` L4047-4079).
 *
 * Quem: quem pode EDITAR a pasta. O quê: dizer que o checklist não se aplica a esta pasta (um dos
 * quatro motivos do desenho), ou voltar a usá-lo. Por quê: pasta administrativa, encerrada ou com
 * documentos controlados em outro lugar não deve carregar a pendência "itens do checklist sem
 * anexo" para sempre — e o registro de quem desligou, quando e por quê fica na pasta.
 *
 * Recebe o ESTADO FINAL, não "alterna" (precedente: `DefinirPastaAdministrativaUseCase`): duas
 * abas abertas não se desfazem uma à outra. Estado igual ao atual não grava nada — nem flush, nem
 * linha de auditoria — e, em particular, desativar o que já está desativado NÃO troca o motivo
 * nem o autor da desativação que existe.
 *
 * Ao desativar, a controladoria é avisada (desenho 02; a D-DOC-S6 "adiar" foi superada em 19/10). O
 * lembrete de 30 dias e a cobrança ficam com a rotina diária (`CobrarChecklistDoEscritorioUseCase`).
 *
 * Guardas, nesta ordem:
 *   1. a pasta é do escritório da sessão — senão {@see PastaDeOutroEscritorioException} (404);
 *   2. o usuário pode editar a pasta — senão {@see SemPermissaoParaEditarPastaException} (403);
 *   3. para desativar, o motivo é um dos quatro — senão \InvalidArgumentException (422).
 * O motivo é validado DEPOIS da permissão: quem não pode editar recebe 403, não uma pista sobre
 * quais valores o campo aceita.
 *
 * Auditoria: `Pasta` implementa `Auditavel`; a troca dos três campos entra no `audit_log` pelo
 * subscriber, como qualquer outra edição da pasta.
 *
 * @return bool true quando o estado mudou
 */
final class AlterarEstadoDoChecklistUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
        private readonly ClockInterface $clock,
        private readonly ?ControladoriaDoEscritorioInterface $controladoria = null,
        private readonly ?AvisoDoChecklist $aviso = null,
        private readonly ?UrlGeneratorInterface $urls = null,
    ) {
    }

    public function executar(Pasta $pasta, bool $ativo, ?string $motivo, User $usuario, Tenant $tenant): bool
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
        }

        if ($ativo) {
            if ($pasta->isChecklistAtivo()) {
                return false;
            }

            $pasta->reativarChecklist();
            $this->em->flush();

            return true;
        }

        $motivoEscolhido = MotivoDesativacaoChecklist::tryFrom(trim((string) $motivo));
        if ($motivoEscolhido === null) {
            throw new \InvalidArgumentException('Escolha o motivo da desativação.');
        }

        if (!$pasta->isChecklistAtivo()) {
            return false;
        }

        $pasta->desativarChecklist($motivoEscolhido, $usuario, $this->clock->now());
        $this->avisarControladoria($pasta, $motivoEscolhido, $usuario, $tenant);
        $this->em->flush();

        return true;
    }

    /** Desenho 02 (dc 4247-4248): a controladoria fica sabendo, com o motivo e as pendências em aberto. */
    private function avisarControladoria(Pasta $pasta, MotivoDesativacaoChecklist $motivo, User $usuario, Tenant $tenant): void
    {
        if ($this->controladoria === null || $this->aviso === null || $this->urls === null) {
            return;
        }
        $pendentes = \count(array_filter($pasta->getChecklistItens()->toArray(), static fn ($item): bool => !$item->isConcluido()));
        $quem = (string) ($usuario->getFullName() ?: $usuario->getEmail());
        $url = $this->urls->generate('pasta_show', ['id' => $pasta->getId(), 'aba' => 'documentos']);
        $this->aviso->avisarVarias($this->controladoria->pessoas($tenant), $usuario, $tenant, AvisoDoChecklist::TIPO_PROVIDENCIA, MensagensDaCobrancaDoChecklist::desativado($pasta, $quem, $motivo->rotulo(), $pendentes), $url);
    }
}
