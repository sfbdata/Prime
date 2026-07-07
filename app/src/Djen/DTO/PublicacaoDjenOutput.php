<?php

declare(strict_types=1);

namespace App\Djen\DTO;

use App\Djen\Entity\PublicacaoDjen;

/**
 * DTO de saída de uma publicação para os templates (o template nunca recebe a entidade).
 * O teor (`texto`) do DJEN vem em HTML de fonte externa — aqui é convertido para TEXTO PLANO seguro
 * (sem tags), preservando quebras de parágrafo. No template usa-se `|nl2br` (que escapa) — sem `|raw`.
 */
final class PublicacaoDjenOutput
{
    /**
     * @param list<array{nome: string, polo: string}> $destinatarios
     */
    private function __construct(
        public readonly int $id,
        public readonly string $siglaTribunal,
        public readonly ?string $tipoComunicacao,
        public readonly ?string $numeroProcessoExibicao,
        public readonly ?string $dataDisponibilizacao,
        public readonly ?string $meioLabel,
        public readonly ?string $nomeOrgao,
        public readonly ?string $nomeClasse,
        public readonly ?string $textoHtml,
        public readonly ?string $link,
        public readonly bool $avulsa,
        public readonly ?int $processoId,
        public readonly ?string $oabRotulo,
        public readonly bool $lida,
        public readonly array $destinatarios,
    ) {
    }

    /**
     * @param ?string $textoHtml teor JÁ sanitizado (o controller passa via HtmlSanitizerInterface).
     *                           Nunca receber HTML cru aqui — o template renderiza com `|raw`.
     */
    public static function fromEntity(PublicacaoDjen $p, ?string $textoHtml = null): self
    {
        return new self(
            (int) $p->getId(),
            $p->getSiglaTribunal(),
            $p->getTipoComunicacao(),
            $p->getNumeroProcessoComMascara() ?? ($p->getNumeroProcesso() !== '' ? $p->getNumeroProcesso() : null),
            $p->getDataDisponibilizacao()?->format('d/m/Y'),
            self::meioLabel($p),
            $p->getNomeOrgao(),
            $p->getNomeClasse(),
            $textoHtml,
            $p->getLink(),
            $p->isAvulsa(),
            $p->getProcesso()?->getId(),
            $p->getOabMonitorada()?->getRotulo(),
            $p->isLida(),
            self::destinatarios($p),
        );
    }

    private static function meioLabel(PublicacaoDjen $p): ?string
    {
        if ($p->getMeioCompleto() !== null && $p->getMeioCompleto() !== '') {
            return $p->getMeioCompleto();
        }

        return match ($p->getMeio()) {
            'D' => 'Diário Eletrônico',
            'E' => 'Edital',
            default => $p->getMeio(),
        };
    }

    /**
     * @return list<array{nome: string, polo: string}>
     */
    private static function destinatarios(PublicacaoDjen $p): array
    {
        $saida = [];
        foreach ($p->getDestinatarios() as $dest) {
            $nome = is_array($dest) && is_scalar($dest['nome'] ?? null) ? (string) $dest['nome'] : '';
            if ($nome === '') {
                continue;
            }

            $poloCode = is_array($dest) && is_scalar($dest['polo'] ?? null) ? (string) $dest['polo'] : '';
            $saida[] = ['nome' => $nome, 'polo' => self::poloLabel($poloCode)];
        }

        return $saida;
    }

    private static function poloLabel(string $codigo): string
    {
        return match (strtoupper($codigo)) {
            'A' => 'Ativo',
            'P' => 'Passivo',
            'T' => 'Terceiro interessado',
            'D' => 'Outros',
            default => '—',
        };
    }
}
