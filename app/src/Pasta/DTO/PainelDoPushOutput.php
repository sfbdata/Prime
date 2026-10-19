<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Processo\DTO\SituacaoAtualOutput;
use App\Processo\Entity\Processo;

/**
 * O cartão da coluna direita da aba Push (desenho 1.2.3, dc L.2568-2626; spec
 * push-painel-monitoramento §1-3), já resolvido. Os textos são os de `pushVals` (dc L.5434-5446).
 *
 * O monitoramento é o do processo PRINCIPAL da pasta. `situacao` existe quando há movimentação;
 * com o monitoramento pausado a tela a esconde (dc L.5420), e o switch a mostra de novo sem
 * recarregar.
 */
final readonly class PainelDoPushOutput
{
    private function __construct(
        public bool $temProcesso,
        public bool $ativo,
        public string $statusTxt,
        public string $statusSub,
        public string $processo,
        public string $ultima,
        public ?SituacaoAtualOutput $situacao,
    ) {
    }

    public static function semProcesso(): self
    {
        [$titulo, $sub] = self::textosDoEstado(false, false);

        return new self(false, false, $titulo, $sub, 'Sem processo vinculado', 'Última verificação: ainda não verificado', null);
    }

    public static function doProcesso(Processo $principal, \DateTimeImmutable $agora, ?SituacaoAtualOutput $situacao): self
    {
        $ativo = $principal->monitoramentoAtivo();
        [$titulo, $sub] = self::textosDoEstado(true, $ativo);
        $tribunal = trim($principal->getSiglaTribunal());

        return new self(
            true,
            $ativo,
            $titulo,
            $sub,
            $principal->getNumeroProcesso() . ($tribunal !== '' ? ' · ' . $tribunal : ''),
            'Última verificação: ' . self::quando($principal->getUltimaVerificacaoEm(), $agora),
            $situacao,
        );
    }

    /**
     * Título e subtítulo do cartão (dc L.5436-5437).
     *
     * @return array{0: string, 1: string}
     */
    public static function textosDoEstado(bool $temProcesso, bool $ativo): array
    {
        if (!$temProcesso) {
            return ['Sem monitoramento', 'Vincule um processo'];
        }

        return $ativo
            ? ['Monitoramento ativo', 'Captura automática nos diários e no PJe']
            : ['Monitoramento pausado', 'Novas movimentações não serão capturadas'];
    }

    /** "hoje, 09:12" (dc L.5442), "ontem, 18:40", "05/10/2026, 08:00" ou "ainda não verificado". */
    private static function quando(?\DateTimeImmutable $em, \DateTimeImmutable $agora): string
    {
        if ($em === null) {
            return 'ainda não verificado';
        }

        $dia = $em->format('Y-m-d');

        return match ($dia) {
            $agora->format('Y-m-d') => 'hoje',
            $agora->modify('-1 day')->format('Y-m-d') => 'ontem',
            default => $em->format('d/m/Y'),
        } . ', ' . $em->format('H:i');
    }
}
