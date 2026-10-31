<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaAndamentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\RotuloDeEtapaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regra do andamento da pasta em fases, portada do desenho 1.2.3 (`FASES`, `faseInferir`, `faseMarcar`,
 * `contratoFase`, `faseSugestao`; spec E12b).
 *
 * - Duas trilhas de 10 fases: a judicial e a administrativa (pasta "Administrativo sem processo").
 * - Sem andamento gravado, a fase é ESTIMADA: Abertura sem registros; Documentação com registros; Em andamento
 *   com processo vinculado; Finalizada com processo arquivado, baixado, transitado ou extinto.
 * - Mudar de fase grava a data de cada fase alcançada; saltar à frente deixa as do meio "puladas"; voltar apaga
 *   as datas das fases à frente. Finalizada clicada de novo reativa: entram Reativada, Em andamento e um novo
 *   Finalizada, e o histórico anterior fica.
 * - Contrato: alcançar a fase Contrato marca o contrato da pasta como regular (assinado); voltar para antes dela
 *   o reabre como pendente. E o contrato marcado no Financeiro move o andamento até Contrato.
 * - Cada mudança vira um cartão "Andamento: …" no Registro. Quem chama dá o flush.
 */
final class AndamentoDaPasta
{
    public const FASES = [
        ['Abertura', 'bi-folder-plus'], ['Documentação', 'bi-file-earmark-text'], ['Contrato', 'bi-pen'],
        ['Distribuído', 'bi-diagram-3'], ['Protocolo', 'bi-send'], ['Em andamento', 'bi-hourglass-split'],
        ['Decisão', 'gavel'], ['Sentença', 'gavel'], ['Cumprimento', 'bi-cash-coin'], ['Finalizada', 'bi-flag'],
    ];

    public const FASES_ADM = [
        ['Abertura', 'bi-folder-plus'], ['Documentação', 'bi-file-earmark-text'], ['Contrato', 'bi-pen'],
        ['Requerimento', 'bi-file-earmark-richtext'], ['Protocolo', 'bi-send'], ['Em análise', 'bi-hourglass-split'],
        ['Exigência', 'bi-exclamation-diamond'], ['Parecer', 'bi-clipboard-check'], ['Cumprimento', 'bi-cash-coin'],
        ['Finalizada', 'bi-flag'],
    ];

    /** Índice de "Protocolo" nas duas trilhas: é por ele que a Lista conta o que falta protocolar. */
    public const IDX_PROTOCOLO = 4;
    private const IDX_CONTRATO = 2;
    private const CONTRATO_ASSINADO = 'REGULAR';
    private const CONTRATO_PENDENTE = 'PENDENTE';

    public function __construct(
        private readonly PastaAndamentoRepository $repository,
        private readonly PastaRepository $pastaRepository,
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PastaDocumentoRepository $documentos,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly EntityManagerInterface $em,
        private readonly RotuloDeEtapaRepository $rotulos,
    ) {
    }

    public static function via(Pasta $pasta): string
    {
        return $pasta->isAdministrativa() ? PastaAndamento::VIA_ADMINISTRATIVA : PastaAndamento::VIA_JUDICIAL;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function base(string $via): array
    {
        return $via === PastaAndamento::VIA_ADMINISTRATIVA ? self::FASES_ADM : self::FASES;
    }

    /**
     * Fases com as extras de reativação; uma "Reativada" sempre seguida de "Em andamento".
     *
     * @param list<array{0: string, 1: string}> $extra
     * @return list<array{0: string, 1: string}>
     */
    public static function fases(string $via, array $extra): array
    {
        for ($k = 0; $k < count($extra); ++$k) {
            if ($extra[$k][0] === 'Reativada' && ($extra[$k + 1][0] ?? '') !== 'Em andamento') {
                array_splice($extra, $k + 1, 0, [['Em andamento', 'bi-hourglass-split']]);
            }
        }

        return array_merge(self::base($via), $extra);
    }

    /**
     * Estado do andamento: gravado, ou estimado pelos dados da pasta.
     *
     * @return array{via: string, fases: list<array{0: string, 1: string}>, atual: int, em: array<int, string>, pul: list<int>, extra: list<array{0: string, 1: string}>, inferida: bool, base: string}
     */
    public function estado(Pasta $pasta, Tenant $tenant): array
    {
        $via = self::via($pasta);
        $gravado = $this->repository->daPasta($pasta, $via, $tenant);
        if ($gravado !== null) {
            $m = $gravado->getMarcos();

            return ['via' => $via, 'fases' => self::fases($via, $m['extra']), 'atual' => $gravado->getAtual(), 'em' => $m['em'],
                'pul' => $m['pul'], 'extra' => $m['extra'], 'inferida' => false, 'base' => ''];
        }

        return ['via' => $via, 'fases' => self::base($via), 'em' => [], 'pul' => [], 'extra' => [], 'inferida' => true] + $this->inferir($pasta, $via);
    }

    /** Nomes dados às etapas entre fases desta pasta na via atual (índice da fase de origem => texto). @return array<int, string> */
    public function rotulos(Pasta $pasta, Tenant $tenant): array
    {
        return $this->rotulos->daPasta($pasta, self::via($pasta), $tenant);
    }

    /** @return array{atual: int, base: string} */
    private function inferir(Pasta $pasta, string $via): array
    {
        $atual = 0;
        $base = 'Pasta aberta, sem registros';
        $regs = $pasta->getMensagens()->filter(static fn (PastaMensagem $m): bool => !$m->isDoSistema())->count();
        if ($regs > 0) {
            $atual = 1;
            $base = $regs . ' registro(s) na pasta';
        }
        $proc = $pasta->getProcessoPrincipal();
        if ($proc !== null && $proc->getNumeroProcesso() !== '') {
            $atual = 5;
            $base = 'processo ' . $proc->getNumeroProcesso() . ' vinculado';
            $sit = mb_strtolower($proc->getSituacaoProcesso());
            if (preg_match('/arquiv|baix|transit|extint/u', $sit) === 1) {
                $atual = count(self::base($via)) - 1;
                $base = 'processo ' . $sit;
            }
        }

        return ['atual' => $atual, 'base' => $base];
    }

    /**
     * Leva a pasta à fase `$alvo` (índice na trilha com extras). Fase atual clicada: nada. Finalizada clicada com
     * a pasta finalizada: reativa. Devolve o texto registrado, ou null quando não mudou nada.
     */
    public function marcar(Pasta $pasta, User $usuario, Tenant $tenant, int $alvo, bool $semPular = false, string $motivo = '', bool $sincronizarContrato = true): ?string
    {
        $e = $this->estado($pasta, $tenant);
        $fases = $e['fases'];
        $ult = count($fases) - 1;
        if ($alvo < 0 || $alvo > $ult) {
            throw new \DomainException('Fase inválida.');
        }
        $reativar = $alvo === $ult && $e['atual'] === $ult;
        if (!$reativar && $alvo === $e['atual']) {
            return null;
        }
        $andamento = $this->repository->daPasta($pasta, $e['via'], $tenant);
        if ($andamento === null) {
            $andamento = new PastaAndamento($tenant, $pasta, $e['via']);
            $this->em->persist($andamento);
        }

        if ($reativar) {
            $e['extra'] = array_merge(array_slice($fases, count(self::base($e['via']))), [['Reativada', 'bi-arrow-repeat'], ['Em andamento', 'bi-hourglass-split'], ['Finalizada', 'bi-flag']]);
            $texto = 'Pasta reativada: de "Finalizada" para "Em andamento".';
            $this->gravar($andamento, $e, $ult + 2, true);
        } else {
            $de = $fases[$e['atual']][0];
            $para = $fases[$alvo][0];
            $texto = ($alvo === $ult ? 'Pasta finalizada (de "' . $de . '" para "Finalizada").' : 'Fase da pasta alterada de "' . $de . '" para "' . $para . '".')
                . ($motivo !== '' ? ' Motivo: ' . $motivo . '.' : '');
            $this->gravar($andamento, $e, $alvo, $semPular);
        }

        $this->registro->registrar($pasta, $usuario, 'fase', $texto);
        if ($sincronizarContrato) {
            $this->sincronizarContrato($pasta, $usuario, $andamento, $e['atual']);
        }

        return $texto;
    }

    /**
     * Pasta arquivada (desenho `faseAuto('Finalizada', 'pasta arquivada')`): o andamento vai direto a Finalizada, sem
     * marcar as intermediárias como puladas. Já finalizada, nada (clicar em Finalizada reativaria); se a Finalizada é
     * só ESTIMADA (sem andamento gravado), a pasta conta como concluída na data do arquivamento (regra de "Pastas
     * concluídas" confirmada pelo dono, spec dashboard-parte-1).
     */
    public function finalizarPorArquivamento(Pasta $pasta, User $usuario, Tenant $tenant): ?string
    {
        $e = $this->estado($pasta, $tenant);
        $ult = count($e['fases']) - 1;
        if ($e['atual'] >= $ult) {
            if ($e['inferida'] && $pasta->getFinalizadaEm() === null) {
                $pasta->setFinalizadaEm(new \DateTimeImmutable());
            }

            return null;
        }

        return $this->marcar($pasta, $usuario, $tenant, $ult, true, 'pasta arquivada');
    }

    /**
     * O contrato marcado no Financeiro (desenho `contratoFase`): regular leva o andamento até Contrato (ou só
     * registra a data dela, se já passou); pendente volta de Contrato para a anterior, ou marca Contrato como
     * pulada se o andamento já foi além.
     */
    public function aoMudarContrato(Pasta $pasta, User $usuario, Tenant $tenant, string $situacao): void
    {
        $e = $this->estado($pasta, $tenant);
        $ic = self::IDX_CONTRATO;
        if ($situacao === self::CONTRATO_ASSINADO) {
            if ($e['atual'] < $ic) {
                $this->marcar($pasta, $usuario, $tenant, $ic, true, 'contrato assinado no Financeiro', false);

                return;
            }
            $e['em'][$ic] ??= (new \DateTimeImmutable())->format(\DATE_ATOM);
            $e['pul'] = array_values(array_diff($e['pul'], [$ic]));
        } else {
            if ($e['atual'] === $ic) {
                $this->marcar($pasta, $usuario, $tenant, $ic - 1, true, '', false);

                return;
            }
            if ($e['atual'] < $ic) {
                return;
            }
            $e['pul'][] = $ic;
            unset($e['em'][$ic]);
        }
        $andamento = $this->repository->daPasta($pasta, $e['via'], $tenant) ?? new PastaAndamento($tenant, $pasta, $e['via']);
        $this->em->persist($andamento);
        $andamento->gravar($e['atual'], ['em' => $e['em'], 'pul' => $e['pul'], 'extra' => $e['extra']]);
    }

    /**
     * Fases à frente que os dados da pasta sugerem (desenho `faseSugestao`), da mais próxima à mais distante: Push
     * (sentença, trânsito/cumprimento, decisão, distribuição), documentos anexados, processo vinculado e contrato
     * regular. No Push vale também o TIPO DO DOCUMENTO da publicação: a comunicação costuma vir como "Intimação" e
     * quem diz "Sentença" ou "Decisão" é o documento.
     *
     * @return list<array{nome: string, indice: int, motivo: string, icone: string}>
     */
    public function sugestoes(Pasta $pasta, Tenant $tenant): array
    {
        $e = $this->estado($pasta, $tenant);
        $out = [];
        $add = function (string $nome, string $motivo, string $icone) use ($e, &$out): void {
            foreach ($e['fases'] as $i => $f) {
                if ($f[0] === $nome && $i < count(self::base($e['via']))) {
                    if ($i > $e['atual'] && !isset($out[$nome])) {
                        $out[$nome] = ['nome' => $nome, 'indice' => $i, 'motivo' => $motivo, 'icone' => $icone];
                    }

                    return;
                }
            }
        };

        $numeros = $this->pastaRepository->numerosDosProcessosVinculados($pasta, $tenant);
        foreach ($this->publicacoes->listarItensPorNumerosDoTenant($tenant, $numeros, 50) as $pub) {
            $t = mb_strtolower(($pub->tipoComunicacao ?? '') . ' ' . ($pub->tipoDocumento ?? '') . ' ' . ($pub->textoResumo ?? ''));
            $tipo = mb_strtolower(($pub->tipoComunicacao ?? '') . ' ' . ($pub->tipoDocumento ?? ''));
            $dia = $pub->dataDisponibilizacao ?? '';
            if (preg_match('/senten[cç]a/u', $t) === 1 && preg_match('/cumprimento de senten/u', $t) !== 1) {
                $add('Sentença', 'Push: sentença em ' . $dia, 'bi-broadcast');
            } elseif (preg_match('/tr[aâ]nsito em julgado|cumprimento de senten/u', $t) === 1) {
                $add('Cumprimento', 'Push: ' . ($pub->tipoComunicacao ?: 'movimentação') . ' em ' . $dia, 'bi-broadcast');
            } elseif (str_contains($tipo, 'decis')) {
                $add('Decisão', 'Push: decisão em ' . $dia, 'bi-broadcast');
            } elseif (preg_match('/distribu/u', $t) === 1) {
                $add('Distribuído', 'Push: distribuição em ' . $dia, 'bi-broadcast');
            }
        }
        $docs = $this->documentos->contarVivosDaPasta($pasta, $tenant);
        if ($docs > 0) {
            $add('Documentação', $docs . ' documento(s) anexado(s) na aba Documentos', 'bi-paperclip');
        }
        $proc = $pasta->getProcessoPrincipal();
        if ($proc !== null && $proc->getNumeroProcesso() !== '') {
            $add('Em andamento', 'Processo ' . $proc->getNumeroProcesso() . ' vinculado na aba Processo', 'bi-link-45deg');
        }
        if ($pasta->getSituacaoContrato() === self::CONTRATO_ASSINADO) {
            $add('Contrato', 'Contrato assinado no Financeiro', 'bi-pen');
        }

        $lista = array_values($out);
        usort($lista, static fn (array $a, array $b): int => $a['indice'] <=> $b['indice']);

        return $lista;
    }

    /** @param array{via: string, fases: list<array{0: string, 1: string}>, atual: int, em: array<int, string>, pul: list<int>, extra: list<array{0: string, 1: string}>} $e */
    private function gravar(PastaAndamento $andamento, array $e, int $alvo, bool $naoPular): void
    {
        $agora = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $em = $e['em'];
        $pul = array_fill_keys($e['pul'], true);
        for ($k = 0; $k <= $alvo; ++$k) {
            if (isset($pul[$k]) && $k !== $alvo) {
                continue;
            }
            if (!isset($em[$k])) {
                if (!$naoPular && $k > $e['atual'] && $k < $alvo) {
                    $pul[$k] = true;
                } else {
                    $em[$k] = $agora;
                }
            }
        }
        $fases = self::fases($e['via'], $e['extra']);
        for ($k = $alvo; $k < count($fases); ++$k) {
            if ($k > $alvo) {
                unset($em[$k]);
            }
            unset($pul[$k]);
        }

        $extra = $e['extra'];
        if ($extra !== []) {
            $b = count(self::base($e['via']));
            $j = count($fases) - 1;
            foreach ($fases as $k => $f) {
                if ($k >= $alvo && $f[0] === 'Finalizada') {
                    $j = $k;
                    break;
                }
            }
            $extra = $j >= $b ? array_slice($fases, $b, $j - $b + 1) : [];
            foreach (array_keys($em) as $k) {
                if ($k > $j) {
                    unset($em[$k]);
                }
            }
            foreach (array_keys($pul) as $k) {
                if ($k > $j) {
                    unset($pul[$k]);
                }
            }
        }

        ksort($em);
        $andamento->gravar($alvo, ['em' => $em, 'pul' => array_map('intval', array_keys($pul)), 'extra' => $extra]);
    }

    /**
     * Contrato acompanha o andamento. Só REABRE quando o andamento recuou de Contrato (ou de depois dela) para antes:
     * pasta antiga com contrato já regular e fase estimada em Abertura não perde o contrato ao avançar para
     * Documentação. E com o contrato regular, saltar por cima de Contrato não a deixa "pulada": ela ganha a data.
     */
    private function sincronizarContrato(Pasta $pasta, User $usuario, PastaAndamento $andamento, int $antes): void
    {
        $ic = self::IDX_CONTRATO;
        $m = $andamento->getMarcos();
        if ($pasta->getSituacaoContrato() === self::CONTRATO_ASSINADO && $andamento->getAtual() > $ic && in_array($ic, $m['pul'], true)) {
            $m['em'][$ic] = (new \DateTimeImmutable())->format(\DATE_ATOM);
            $m['pul'] = array_values(array_diff($m['pul'], [$ic]));
            ksort($m['em']);
            $andamento->gravar($andamento->getAtual(), $m);
        }
        $alcancou = $andamento->getAtual() >= $ic && isset($m['em'][$ic]) && !in_array($ic, $m['pul'], true);
        $contrato = $pasta->getSituacaoContrato();
        if ($alcancou && $contrato !== self::CONTRATO_ASSINADO) {
            $pasta->setSituacaoContrato(self::CONTRATO_ASSINADO);
            $this->registro->registrar($pasta, $usuario, 'contrato', 'marcado como assinado (fase Contrato concluída no andamento).');
        } elseif ($andamento->getAtual() < $ic && $antes >= $ic && $contrato === self::CONTRATO_ASSINADO) {
            $pasta->setSituacaoContrato(self::CONTRATO_PENDENTE);
            $this->registro->registrar($pasta, $usuario, 'contrato', 'reaberto como pendente (andamento voltou para antes de Contrato).');
        }
    }
}
