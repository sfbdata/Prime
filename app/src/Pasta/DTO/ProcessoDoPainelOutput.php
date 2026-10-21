<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Processo\Entity\Processo;

/**
 * Uma linha de processo no cartão de monitoramento do Push: o principal (selo "PRINCIPAL") ou um
 * dos outros processos da pasta, recuado, com o seu próprio switch (pedido do coordenador sobre o
 * desenho de 18/10). O número vai cru: a tela aplica a máscara CNJ (`numero_cnj`), como o resto da
 * pasta.
 */
final readonly class ProcessoDoPainelOutput
{
    private function __construct(
        public int $id,
        public string $numero,
        public string $tribunal,
        public bool $principal,
        public bool $ativo,
        public string $sub,
        public ?string $dicaPausa,
    ) {
    }

    public static function de(Processo $processo, bool $principal): self
    {
        $ativo = $processo->monitoramentoAtivo();
        $classe = trim($processo->getClasseProcessual());

        return new self(
            (int) $processo->getId(),
            $processo->getNumeroProcesso(),
            trim($processo->getSiglaTribunal()),
            $principal,
            $ativo,
            ($classe !== '' ? 'Apensado: ' . $classe : 'Apensado') . ' · ' . ($ativo ? 'monitorado' : 'pausado'),
            $ativo ? null : self::dicaPausa($processo),
        );
    }

    /** "Pausado por Fulano em 18/10" (o title do switch), ou sem o nome se o usuário não existe mais. */
    private static function dicaPausa(Processo $processo): string
    {
        $quem = trim((string) $processo->getMonitoramentoAlteradoPor()?->getFullName());
        $quando = $processo->getMonitoramentoAlteradoEm() ?? $processo->getMonitoramentoPausadoEm();

        return 'Pausado' . ($quem !== '' ? ' por ' . $quem : '') . ($quando !== null ? ' em ' . $quando->format('d/m') : '');
    }
}
