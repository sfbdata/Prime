<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistCobranca;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Auditoria "sem anexo" do desenho 02 (`ckAuditar`, dc 4314-4337): item marcado como entregue sem nenhum
 * arquivo correspondente na pasta (regra da `ConferenciaDeAnexosDoChecklist`) avisa quem marcou e a
 * controladoria, uma vez por item enquanto continuar assim. Anexar o documento apaga o registro; desmarcar
 * limpa a auditoria.
 *
 * Com o checklist desativado nada roda (desenho: "auditoria pausada"). Não grava: quem chama dá o flush.
 */
final class AuditoriaDoChecklist
{
    public function __construct(
        private readonly PastaChecklistCobrancaRepository $cobrancas,
        private readonly ControladoriaDoEscritorioInterface $controladoria,
        private readonly AvisoDoChecklist $aviso,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Ao marcar ou desmarcar um item: quem marcou é quem recebe o aviso. */
    public function aoAlternar(PastaChecklistItem $item, User $quem, \DateTimeImmutable $hoje): void
    {
        $pasta = $item->getPasta();
        if ($pasta === null || !$pasta->isChecklistAtivo()) {
            return;
        }

        $conferencia = ConferenciaDeAnexosDoChecklist::daPasta([$item], $pasta->getDocumentos(), $item->getTenant());
        $this->conferirItem($pasta, $item, $conferencia, $quem, $hoje);
    }

    /** Varredura diária: itens marcados antes, atribuídos ao responsável da pasta. */
    public function varrer(Pasta $pasta, \DateTimeImmutable $hoje): void
    {
        if (!$pasta->isChecklistAtivo()) {
            return;
        }

        $itens = $pasta->getChecklistItens()->toArray();
        $conferencia = ConferenciaDeAnexosDoChecklist::daPasta($itens, $pasta->getDocumentos(), $pasta->getTenant());
        foreach ($itens as $item) {
            $this->conferirItem($pasta, $item, $conferencia, $pasta->getResponsavel(), $hoje);
        }
    }

    private function conferirItem(Pasta $pasta, PastaChecklistItem $item, ConferenciaDeAnexosDoChecklist $conferencia, ?User $quem, \DateTimeImmutable $hoje): void
    {
        $registro = $this->cobrancas->doItem($item);
        if (!$item->isConcluido()) {
            $registro?->limparAuditoria();

            return;
        }
        if ($conferencia->temAnexo((int) $item->getId())) {
            if ($registro !== null) {
                $this->cobrancas->remover($registro);
            }

            return;
        }
        if ($registro?->getAuditoriaEm() !== null) {
            return;
        }

        $registro ??= new PastaChecklistCobranca($item->getTenant() ?? $pasta->getTenant(), $item, $hoje);
        $registro->registrarAuditoria($quem, $hoje);
        $this->cobrancas->salvar($registro);
        $this->avisar($pasta, $item, $quem);
    }

    private function avisar(Pasta $pasta, PastaChecklistItem $item, ?User $quem): void
    {
        $url = $this->urls->generate('pasta_show', ['id' => $pasta->getId(), 'aba' => 'documentos']);
        $tenant = $pasta->getTenant();
        if ($quem !== null) {
            $this->aviso->avisar($quem, $tenant, AvisoDoChecklist::TIPO_COBRANCA, MensagensDaCobrancaDoChecklist::semAnexoParaQuemMarcou($item->getTitulo(), $pasta), $url);
        }
        $nome = $quem?->getFullName() ?: ($quem?->getEmail() ?? 'Alguém');
        $this->aviso->avisarVarias($this->controladoria->pessoas($tenant), $quem, $tenant, AvisoDoChecklist::TIPO_COBRANCA, MensagensDaCobrancaDoChecklist::semAnexoParaControladoria($item->getTitulo(), $pasta, (string) $nome), $url);
    }
}
