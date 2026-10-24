<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

use App\Comercial\DTO\SituacaoDaCota;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use Psr\Clock\ClockInterface;

/**
 * A cota de envio de e-mail (spec comercial-e-emails.md §3.6 e §8.5), por contagem em `comercial_envio` numa janela
 * CORRIDA de 24 h (a da Hostinger), nunca no dia do calendário. Conta TODOS os e-mails que saíram, inclusive os
 * síncronos (o {@see \App\Comercial\EventListener\RegistroDeEmailSincronoListener} os registra como `sistema`).
 *
 *   - teto: o total em 24 h (padrão 2.400, abaixo dos 3.000 da caixa);
 *   - teto das campanhas (padrão 1.600); a reserva dos demais é a diferença (800), e a campanha nunca a toma: se os
 *     demais passam da reserva, a campanha encolhe;
 *   - por minuto: os enviados nos últimos 60 s.
 *
 * As origens isentas só respeitam o por minuto, mas contam no total (a margem até a Hostinger as cobre). Sem vaga, a
 * volta é quando o envio mais antigo da janela sai dela (+ 60 s de folga); ao reavaliar sem vaga, adia de novo.
 */
final class CotaDeEnvio
{
    /**
     * Respeitam só o limite por minuto, nunca o teto de 24 h: os transacionais do ciclo da conta (N6, as boas-vindas
     * nunca esperam por causa de campanha) e o "Enviar teste" da campanha, que vai só ao Master logado (§8.4, com teto
     * próprio de 10 por hora).
     */
    public const ORIGENS_ISENTAS = [
        'automacao:cad', 'automacao:lemb', 'automacao:val', 'automacao:conv',
        'automacao:e90', 'automacao:e100', 'automacao:ped',
        'teste_campanha',
        // Resumo diário do Meu Perfil (decisão do coordenador, 21/10/2026): não disputa o teto com as campanhas.
        'resumo_diario',
    ];

    public const JANELA = '-24 hours';
    private const VOLTA = '+24 hours 60 seconds';

    public function __construct(
        private readonly EnvioDeEmailRepository $envios,
        private readonly DestinoDaCampanhaRepository $destinos,
        private readonly ClockInterface $relogio,
        private readonly int $tetoDiario,
        private readonly int $tetoDiarioDeCampanhas,
        private readonly int $enviosPorMinuto,
        private readonly int $avisoDaCotaPercentual,
    ) {
    }

    /** O que fica para os e-mails que não são de campanha: derivada, nunca negativa. */
    public function reserva(): int
    {
        return max(0, $this->tetoDiario - $this->tetoDiarioDeCampanhas);
    }

    /** A guarda do handler, na hora de mandar: quando há vaga de novo, ou null se há vaga agora. */
    public function disponivelEm(string $origem, \DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        if ($this->envios->contarEnviadosDesde($agora->modify('-60 seconds')) >= $this->enviosPorMinuto) {
            return $agora->modify('+60 seconds');
        }
        if (\in_array($origem, self::ORIGENS_ISENTAS, true)) {
            return null;
        }

        return str_starts_with($origem, 'campanha:') ? $this->campanhaBloqueadaAte($agora) : $this->geralBloqueadoAte($agora);
    }

    /** O expansor: quantos envios de campanha ainda cabem na janela, contando os já registrados na fila. */
    public function vagasDeCampanha(\DateTimeImmutable $agora): int
    {
        return self::vagas($this->folgasDoExpansor($agora));
    }

    /** O aviso do Master, calculado na hora (sem tabela). */
    public function situacao(): SituacaoDaCota
    {
        $agora = $this->relogio->now();
        $enviados = $this->envios->contarEnviadosDesde($agora->modify(self::JANELA));
        $liberaEm = $this->campanhasPausadasAte($agora);

        return new SituacaoDaCota(
            $enviados,
            $this->tetoDiario,
            $this->avisoDaCotaPercentual,
            $enviados * 100 >= $this->tetoDiario * $this->avisoDaCotaPercentual,
            $liberaEm !== null,
            $liberaEm,
        );
    }

    /**
     * As campanhas estão pausadas quando há campanha esperando e sem vaga: envio de campanha na fila que o handler não
     * pode mandar, ou destinatário pendente que o expansor não pode liberar. Devolve quando a vaga volta, ou null.
     */
    private function campanhasPausadasAte(\DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        $pendentes = $this->destinos->haPendentes();
        $doHandler = $this->campanhaBloqueadaAte($agora);
        if ($doHandler !== null && ($pendentes || $this->envios->contarDeCampanhaNaFila() > 0)) {
            return $doHandler;
        }

        if (!$pendentes) {
            return null;
        }
        $folgas = $this->folgasDoExpansor($agora);

        return self::vagas($folgas) === 0 ? $this->expansorLiberaEm($agora, $folgas[0]) : null;
    }

    /** Não-campanha: pode enquanto o total da janela está abaixo do teto. */
    private function geralBloqueadoAte(\DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        if ($this->envios->contarEnviadosDesde($agora->modify(self::JANELA)) < $this->tetoDiario) {
            return null;
        }

        return $this->volta($this->envios->enviadoMaisAntigoDesde($agora->modify(self::JANELA), false), $agora);
    }

    /** Campanha: abaixo do próprio teto E sem tomar a reserva dos demais (nem o que eles já passaram dela). */
    private function campanhaBloqueadaAte(\DateTimeImmutable $agora): ?\DateTimeImmutable
    {
        $desde = $agora->modify(self::JANELA);
        $deCampanha = $this->envios->contarEnviadosDeCampanhaDesde($desde);
        if ($deCampanha >= $this->tetoDiarioDeCampanhas) {
            return $this->volta($this->envios->enviadoMaisAntigoDesde($desde, true), $agora);
        }
        $demais = $this->envios->contarEnviadosDesde($desde) - $deCampanha;
        if ($deCampanha + max($demais, $this->reserva()) >= $this->tetoDiario) {
            return $this->volta($this->envios->enviadoMaisAntigoDesde($desde, false), $agora);
        }

        return null;
    }

    /**
     * As duas folgas do expansor: a do teto das campanhas e a do teto total sem a reserva (ou sem o que os demais já
     * passaram dela), ambas descontando os envios de campanha já registrados na janela (inclusive os ainda na fila).
     *
     * @return array{int, int}
     */
    private function folgasDoExpansor(\DateTimeImmutable $agora): array
    {
        $desde = $agora->modify(self::JANELA);
        $registrados = $this->envios->contarDeCampanhaDesde($desde);
        $demais = $this->envios->contarEnviadosDesde($desde) - $this->envios->contarEnviadosDeCampanhaDesde($desde);

        return [
            $this->tetoDiarioDeCampanhas - $registrados,
            $this->tetoDiario - max($demais, $this->reserva()) - $registrados,
        ];
    }

    /** @param array{int, int} $folgas */
    private static function vagas(array $folgas): int
    {
        return max(0, min($folgas[0], $folgas[1]));
    }

    /** Quando o expansor volta a ter vaga: pelo enviado mais antigo do conjunto que trava; sem ele, o registro mais antigo. */
    private function expansorLiberaEm(\DateTimeImmutable $agora, int $doTetoDasCampanhas): \DateTimeImmutable
    {
        $desde = $agora->modify(self::JANELA);
        $maisAntigo = $this->envios->enviadoMaisAntigoDesde($desde, $doTetoDasCampanhas <= 0)
            ?? $this->envios->registradoDeCampanhaMaisAntigoDesde($desde);

        return $this->volta($maisAntigo, $agora);
    }

    /** O mais antigo da janela sai dela 24 h depois; +60 s de folga. Sem ele (teto zero), tenta de novo em 60 s. */
    private function volta(?\DateTimeImmutable $maisAntigo, \DateTimeImmutable $agora): \DateTimeImmutable
    {
        $volta = $maisAntigo?->modify(self::VOLTA);

        return $volta !== null && $volta > $agora ? $volta : $agora->modify('+60 seconds');
    }
}
