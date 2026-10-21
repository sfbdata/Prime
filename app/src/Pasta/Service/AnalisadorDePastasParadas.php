<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\PastaParaAnaliseDeParada;
use App\Pasta\DTO\PastaParada;

/**
 * Porte de `paradasDados` (desenho "02 - EXPEDIENTES - VS 18.10.26.dc.html", L4704-4737; spec
 * docs/specs/pastas-paradas.md): diz quais pastas estão paradas há mais de 30 dias, há quanto
 * tempo, em que nível, e monta a pré-análise e as sugestões com os textos do desenho.
 *
 * Serviço puro: não lê banco. Quem junta os dados é o {@see ColetorDePastasParadas}.
 *
 * - Última movimentação: a data mais recente entre as movimentações dos processos, o Push, os
 *   registros de pessoas e a entrada na fase atual do andamento. Sem nenhuma, conta da abertura.
 * - Sai da lista: andamento encerrado (fase atual = última da via) e, sem andamento gravado, a
 *   pasta cujo processo principal está arquivado, baixado, transitado ou extinto (`AndamentoDaPasta`).
 * - Os dias contam por data (meia-noite a meia-noite no fuso de `$hoje`), como o `setHours(0,0,0,0)`
 *   do desenho.
 * - O financeiro (a linha "Financeiro: …" e a sugestão dele) só entra para quem pode ver o
 *   financeiro da pasta: quem chama diz, por pasta, em `$podeVerFinanceiro`; sem ele, fica oculto.
 */
final class AnalisadorDePastasParadas
{
    public const LIMITE_DIAS   = 30;
    public const DIAS_ATENCAO  = 60;
    public const DIAS_CRITICO  = 90;

    public const NIVEL_CRITICO    = 'Crítico';
    public const NIVEL_ATENCAO    = 'Atenção';
    public const NIVEL_ACOMPANHAR = 'Acompanhar';

    public const COR_CRITICO    = '#a3232b';
    public const COR_ATENCAO    = '#b06a00';
    public const COR_ACOMPANHAR = '#0a6a96';

    /** Os dois documentos que toda pasta deve ter (L4717), na ordem do desenho. */
    public const PROCURACAO = 'Procuração';
    public const CONTRATO_DE_HONORARIOS = 'Contrato de honorários';

    public const SUGESTAO_CONTRATO   = 'Confirmar com o cliente a assinatura do contrato e juntar à pasta.';
    public const SUGESTAO_FINANCEIRO = 'Ver com o Financeiro se o pagamento já foi feito e dar a baixa no sistema.';
    public const SUGESTAO_DOCUMENTOS = 'Solicitar ao cliente os documentos faltantes.';
    public const SUGESTAO_SEM_PROCESSO = 'Verificar se a ação já foi distribuída e vincular o processo, ou decidir pelo arquivamento.';
    public const SUGESTAO_SUSPENSO   = 'Conferir o motivo e o prazo da suspensão no PJe e registrar no expediente.';
    public const SUGESTAO_PJE        = 'Consultar o andamento no PJe e registrar a providência.';

    /** Situação do processo que encerra a pasta sem andamento gravado (`AndamentoDaPasta::inferir`). */
    private const PROCESSO_ENCERRADO = '/arquiv|baix|transit|extint/iu';
    private const PROCESSO_SUSPENSO  = '/suspens/iu';

    /**
     * @param list<PastaParaAnaliseDeParada> $pastas
     * @param (\Closure(int): bool)|null     $podeVerFinanceiro pastaId => pode ver o financeiro; nulo = ninguém vê
     *
     * @return list<PastaParada> da mais parada para a menos
     */
    public function analisar(array $pastas, \DateTimeImmutable $hoje, ?\Closure $podeVerFinanceiro = null): array
    {
        $dia     = self::dia($hoje, $hoje->getTimezone());
        $paradas = [];
        foreach ($pastas as $pasta) {
            $parada = $this->analisarPasta($pasta, $dia, $podeVerFinanceiro);
            if ($parada !== null) {
                $paradas[] = $parada;
            }
        }
        usort($paradas, static fn (PastaParada $a, PastaParada $b): int => $b->dias <=> $a->dias ?: strnatcmp($a->numero, $b->numero));

        return $paradas;
    }

    private function analisarPasta(PastaParaAnaliseDeParada $p, \DateTimeImmutable $hoje, ?\Closure $podeVerFinanceiro): ?PastaParada
    {
        if (self::encerrada($p)) {
            return null;
        }
        $ultima = self::ultimaMovimentacao($p, $hoje->getTimezone());
        $desde  = $ultima ?? ($p->abertura !== null ? self::dia($p->abertura, $hoje->getTimezone()) : null);
        $dias   = $desde !== null ? self::diasEntre($desde, $hoje) : null;
        if ($dias === null || $dias <= self::LIMITE_DIAS) {
            return null;
        }

        $verFinanceiro = $podeVerFinanceiro !== null && $podeVerFinanceiro($p->id) === true;

        return new PastaParada(
            pastaId: $p->id,
            numero: $p->numero,
            cliente: $p->cliente,
            responsavel: $p->responsavel,
            responsavelId: $p->responsavelId,
            processoNumero: $p->processoNumero,
            processoTribunal: $p->processoTribunal,
            dias: $dias,
            ultima: $ultima,
            pontos: self::pontos($p, $hoje, $ultima, $dias, $verFinanceiro),
            sugestoes: self::sugestoes($p, $verFinanceiro),
            nivel: self::nivel($dias)[0],
            cor: self::nivel($dias)[1],
            semContrato: !self::temContrato($p),
        );
    }

    private static function encerrada(PastaParaAnaliseDeParada $p): bool
    {
        if ($p->faseAtual !== null) {
            return $p->andamentoEncerrado;
        }

        return $p->processoNumero !== null && preg_match(self::PROCESSO_ENCERRADO, $p->processoSituacao) === 1;
    }

    /** O máximo das quatro fontes (L4713-4715), já na data (sem a hora). */
    private static function ultimaMovimentacao(PastaParaAnaliseDeParada $p, \DateTimeZone $fuso): ?\DateTimeImmutable
    {
        $candidatas = $p->movimentacoes;
        if ($p->faseDesde !== null) {
            $candidatas[] = $p->faseDesde;
        }
        if ($candidatas === []) {
            return null;
        }

        return self::dia(max($candidatas), $fuso);
    }

    /**
     * L4719-4726: abertura, última movimentação, andamento, processo, contrato, documentos e financeiro.
     *
     * @return list<string>
     */
    private static function pontos(PastaParaAnaliseDeParada $p, \DateTimeImmutable $hoje, ?\DateTimeImmutable $ultima, int $dias, bool $verFinanceiro): array
    {
        $abertura = $p->abertura !== null ? self::dia($p->abertura, $hoje->getTimezone()) : null;
        $pontos   = [
            $abertura !== null
                ? 'Pasta aberta em ' . $abertura->format('d/m/Y') . ', hoje faz ' . self::diasEntre($abertura, $hoje) . ' dias.'
                : 'Data de abertura não informada.',
            $ultima !== null
                ? 'Última movimentação em ' . $ultima->format('d/m/Y') . ' (' . $dias . ' dias parada).'
                : 'Nenhuma movimentação registrada desde a abertura.',
        ];
        $pontos[] = self::pontoDoAndamento($p, $hoje);
        $pontos[] = self::pontoDoProcesso($p);
        $pontos[] = $p->proBono ? 'Contrato: pró-bono.' : (self::temContrato($p) ? 'Contrato assinado.' : 'Contrato não juntado.');
        $pontos[] = self::pontoDosDocumentos($p);
        $pontos[] = $verFinanceiro ? 'Financeiro: ' . self::resumoFinanceiro($p) . '.' : null;

        return array_values(array_filter($pontos, static fn (?string $t): bool => $t !== null));
    }

    private static function pontoDoAndamento(PastaParaAnaliseDeParada $p, \DateTimeImmutable $hoje): ?string
    {
        if ($p->faseAtual === null || $p->faseDesde === null) {
            return null;
        }
        $desde = self::dia($p->faseDesde, $hoje->getTimezone());

        return 'Andamento: na fase "' . $p->faseAtual . '" há ' . self::diasEntre($desde, $hoje) . ' dias (desde ' . $desde->format('d/m/Y') . ').';
    }

    private static function pontoDoProcesso(PastaParaAnaliseDeParada $p): ?string
    {
        if ($p->processoNumero === null) {
            return 'Sem processo vinculado: não há número de processo cadastrado.';
        }

        return self::suspenso($p) ? 'Processo ' . $p->processoNumero . ' consta como suspenso.' : null;
    }

    private static function pontoDosDocumentos(PastaParaAnaliseDeParada $p): ?string
    {
        $faltam = self::documentosQueFaltam($p);
        if ($faltam === []) {
            return null;
        }

        return ($p->totalDocumentos > 0 ? 'Falta juntar: ' : 'Nenhum documento juntado. Falta: ') . implode(' e ', $faltam) . '.';
    }

    /** "nenhum lançamento", "N pagamento(s) vencido(s)" ou "em dia" (spec, "Pré-análise"). */
    private static function resumoFinanceiro(PastaParaAnaliseDeParada $p): string
    {
        if ($p->pagamentos['total'] === 0) {
            return 'nenhum lançamento';
        }

        return $p->pagamentos['vencidos'] > 0 ? $p->pagamentos['vencidos'] . ' pagamento(s) vencido(s)' : 'em dia';
    }

    /**
     * L4727-4733, nesta ordem, quando valem.
     *
     * @return list<string>
     */
    private static function sugestoes(PastaParaAnaliseDeParada $p, bool $verFinanceiro): array
    {
        $sugestoes = [];
        if (!self::temContrato($p)) {
            $sugestoes[] = self::SUGESTAO_CONTRATO;
        }
        if ($verFinanceiro && ($p->pagamentos['total'] === 0 || $p->pagamentos['vencidos'] > 0)) {
            $sugestoes[] = self::SUGESTAO_FINANCEIRO;
        }
        if (self::documentosQueFaltam($p) !== []) {
            $sugestoes[] = self::SUGESTAO_DOCUMENTOS;
        }
        $sugestoes[] = match (true) {
            $p->processoNumero === null => self::SUGESTAO_SEM_PROCESSO,
            self::suspenso($p)          => self::SUGESTAO_SUSPENSO,
            default                     => self::SUGESTAO_PJE,
        };

        return $sugestoes;
    }

    /** @return list<string> */
    private static function documentosQueFaltam(PastaParaAnaliseDeParada $p): array
    {
        $faltam = [];
        if (!$p->temProcuracao) {
            $faltam[] = self::PROCURACAO;
        }
        if (!$p->temContratoDeHonorarios) {
            $faltam[] = self::CONTRATO_DE_HONORARIOS;
        }

        return $faltam;
    }

    private static function temContrato(PastaParaAnaliseDeParada $p): bool
    {
        return $p->proBono || $p->contratoAssinado;
    }

    private static function suspenso(PastaParaAnaliseDeParada $p): bool
    {
        return preg_match(self::PROCESSO_SUSPENSO, $p->processoSituacao) === 1;
    }

    /** @return array{0: string, 1: string} [nível, cor] (L4734) */
    private static function nivel(int $dias): array
    {
        return match (true) {
            $dias > self::DIAS_CRITICO => [self::NIVEL_CRITICO, self::COR_CRITICO],
            $dias > self::DIAS_ATENCAO => [self::NIVEL_ATENCAO, self::COR_ATENCAO],
            default                    => [self::NIVEL_ACOMPANHAR, self::COR_ACOMPANHAR],
        };
    }

    private static function dia(\DateTimeImmutable $quando, \DateTimeZone $fuso): \DateTimeImmutable
    {
        return $quando->setTimezone($fuso)->setTime(0, 0);
    }

    /** Dias de `$de` até `$ate` (ambos à meia-noite); negativo se `$de` for depois. */
    private static function diasEntre(\DateTimeImmutable $de, \DateTimeImmutable $ate): int
    {
        return (int) $de->diff($ate)->format('%r%a');
    }
}
