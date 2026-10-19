<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;
use App\Processo\DTO\SituacaoAtualOutput;
use App\Processo\Entity\Processo;

/**
 * O cartão da coluna direita da aba Push (desenho 1.2.3, dc L.2568-2626; spec
 * push-painel-monitoramento §1-3), já resolvido. Os textos são os de `pushVals` (dc L.5434-5446).
 *
 * O cabeçalho é o do processo PRINCIPAL da pasta (o switch dele é o do cabeçalho). `processos`
 * lista o principal primeiro e depois os demais da pasta, cada um com o seu switch; com mais de um,
 * o subtítulo ganha " · N processos". `situacao` existe quando há movimentação; com o
 * monitoramento pausado a tela a esconde (dc L.5420), e o switch a mostra de novo sem recarregar.
 */
final readonly class PainelDoPushOutput
{
    /** @param list<ProcessoDoPainelOutput> $processos */
    private function __construct(
        public bool $temProcesso,
        public bool $ativo,
        public string $statusTxt,
        public string $statusSub,
        public string $ultima,
        public array $processos,
        public ?SituacaoAtualOutput $situacao,
    ) {
    }

    public static function semProcesso(): self
    {
        [$titulo, $sub] = self::textosDoEstado(false, false, 0);

        return new self(false, false, $titulo, $sub, 'Última verificação: ainda não verificado', [], null);
    }

    public static function daPasta(Pasta $pasta, Processo $principal, \DateTimeImmutable $agora, ?SituacaoAtualOutput $situacao): self
    {
        $processos = [ProcessoDoPainelOutput::de($principal, true)];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            if ($vinculo->getProcesso() !== $principal) {
                $processos[] = ProcessoDoPainelOutput::de($vinculo->getProcesso(), false);
            }
        }

        $ativo = $principal->monitoramentoAtivo();
        // Desenho 18/10 (statusSub, N:5696): N = 1 (o principal) + apensados NÃO pausados, e só com o principal ativo.
        $monitorados = 1;
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            if ($vinculo->getProcesso() !== $principal && $vinculo->getProcesso()->monitoramentoAtivo()) {
                ++$monitorados;
            }
        }
        [$titulo, $sub] = self::textosDoEstado(true, $ativo, $ativo ? $monitorados : 0);

        return new self(
            true,
            $ativo,
            $titulo,
            $sub,
            'Última verificação: ' . self::quando($principal->getUltimaVerificacaoEm(), $agora),
            $processos,
            $situacao,
        );
    }

    /** O processo de id `$id` no cartão, ou null. */
    public function processo(int $id): ?ProcessoDoPainelOutput
    {
        foreach ($this->processos as $processo) {
            if ($processo->id === $id) {
                return $processo;
            }
        }

        return null;
    }

    /**
     * Título e subtítulo do cartão (dc L.5436-5437), com " · N processos" quando há mais de um.
     *
     * @return array{0: string, 1: string}
     */
    private static function textosDoEstado(bool $temProcesso, bool $ativo, int $quantos): array
    {
        if (!$temProcesso) {
            return ['Sem monitoramento', 'Vincule um processo'];
        }

        // Pausado: desvio de texto do desenho (statusSub, L5437) aprovado pelo dono (19/10): a pausa só para a
        // busca pelo número; intimações pela OAB continuam sendo captadas, para nenhum prazo se perder.
        [$titulo, $sub] = $ativo
            ? ['Monitoramento ativo', 'Captura automática nos diários e no PJe']
            : ['Monitoramento pausado', 'A busca pelo número do processo fica parada; intimações pela OAB continuam chegando.'];

        return [$titulo, $sub . ($quantos > 1 ? ' · ' . $quantos . ' processos' : '')];
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
