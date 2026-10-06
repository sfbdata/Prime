<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;

/**
 * Modo avançado do painel BlueJus Intelligence — porte de `analisarAvancado()` de
 * `bluejus-avancado.js` (pacote do desenho) sobre os dados REAIS do Dashboard: alertas da
 * Central de Inteligência (problema → evidência → causa → impacto → ação), cockpit,
 * qualidade dos dados, distribuição da equipe, perguntas guiadas e a "Visão estratégica
 * da equipe" do painel básico (dc L2688-2697). Regras fixas, sem modelo de linguagem.
 *
 * Entrada: o DashboardOutput (linhas visíveis + card Demandas urgentes) e a LeituraDoRitmo
 * (somas, ritmo por dia e tempo do período) já calculados pelo MotorDeRitmo — o protótipo
 * faz o mesmo: `_avIn` (dc L2703) leva o ritmo do motor básico para o avançado.
 *
 * Não portado, porque o dado não existe no sistema:
 *  - alerta 5 "ritmo do período" / "ritmo dentro do esperado" (L108-131) e o indicador
 *    "Produtividade" do cockpit (L209): dependem do ritmo necessário e do estado, que exigem
 *    o período anterior com conclusões (ver MotorDeRitmo);
 *  - causa do alerta de vencidas (L42): compara ritmo atual × necessário;
 *  - percentual de vencidas sobre a fila ativa (L35-38, L41, L46, L208): vencidas são
 *    relativas a HOJE (sem período) e a fila ativa é das metas CRIADAS no período — bases
 *    diferentes, a razão não significa nada (dava "1500%"). O nível do alerta e o cockpit
 *    usam só a contagem (≥ 10 é crítico, L38);
 *  - alerta 4 "entrada acima da conclusão" (L89-105): nas metas criadas no período,
 *    novas − concluídas é o que segue aberto — não mede tendência da fila nem permite
 *    projeção (ver MotorDeRitmo);
 *  - alerta 7 "meta de pastas" (L151-164): não existe meta mensal de pastas;
 *  - evidência por pessoa do alerta de urgentes (L140): a tabela não traz urgentes por
 *    colaborador — o gatilho usa o card Demandas urgentes;
 *  - pergunta "Se nada mudar…" (L223): a resposta vinha do alerta de entrada ou do estado
 *    do ritmo — nenhum dos dois existe;
 *  - "Como está a equipe?", "Quem precisa de atenção" e "Ritmo para o objetivo" da visão
 *    estratégica (dc L2689, L2692, L2694): estado, condição por pessoa e ritmo necessário.
 *  - Toda a "Inteligência de Desempenho" (`bluejus-equipe.js`): condição por pessoa com
 *    rótulos de RH — decisão do dono, fora por instrução.
 */
final class MotorAvancado
{
    public const ID_VENCIDAS     = 'vencidas';
    public const ID_CONCENTRACAO = 'concentracao';
    public const ID_PRAZOS       = 'prazos';
    public const ID_URGENTES     = 'urgentes';
    public const ID_CAPACIDADE   = 'capacidade';
    public const ID_SEM_VENCIDAS = 'sem-vencidas';

    // Alerta 1 — vencidas: só a contagem (L38, `venc >= 10`); o percentual sobre a fila
    // ativa misturava universos (vencidas de hoje × metas criadas no período) e saiu
    public const VENCIDAS_CRITICO_QTD = 10;

    // Alerta 2 — concentração de carga: ≥ 1,5× a média dispara; ≥ 2× é atenção (L53, L56)
    public const CONCENTRACAO_FATOR         = 1.5;
    public const CONCENTRACAO_ATENCAO_FATOR = 2.0;

    // Alerta 3 — prazos próximos (L74) e cockpit (L207)
    public const PRAZOS_ATENCAO = 15;

    /** Janela de "prazo próximo" do sistema (TarefaRepository::countPrazosProximosPorResponsavel). */
    public const PRAZO_PROXIMO_DIAS = 7;

    // Alerta 6 — pastas urgentes (L137)
    public const URGENTES_ATENCAO = 8;

    // Alerta 8 — capacidade disponível: fila ≤ 0,6× a média (L167)
    public const CAPACIDADE_FATOR = 0.6;

    // Qualidade dos dados (L192-200)
    public const QUALIDADE_SEM_CARGO     = 6;
    public const QUALIDADE_INICIO        = 15;
    public const QUALIDADE_INICIO_FRACAO = 0.25;
    public const QUALIDADE_ANDAMENTO     = 6;
    public const QUALIDADE_POUCOS        = 20;
    public const QUALIDADE_POUCOS_MIN    = 3;
    public const QUALIDADE_AJUSTE        = 4;
    public const QUALIDADE_MIN           = 40;

    // Cockpit — uso da capacidade (L210); o indicador de fila vencida usa a contagem (L38)
    public const CAPACIDADE_USO_CRITICO    = 120;
    public const CAPACIDADE_USO_ATENCAO    = 95;
    public const CAPACIDADE_USO_MONITORAR  = 80;

    public function analisar(DashboardOutput $dashboard, LeituraDoRitmo $ritmo): LeituraAvancada
    {
        $linhas = $dashboard->porAdvogado;
        $n      = count($linhas);
        $tempo  = $ritmo->tempo;
        $restam = $tempo?->restantes ?? 0;

        $ativas = $ritmo->ativas;
        $venc   = $ritmo->vencidas;
        $prazos = $ritmo->prazos;
        $totalM = $ritmo->novas;
        $urg    = $dashboard->demandasUrgentes;
        $media  = $n > 0 ? $ativas / $n : 0.0;

        $porAtivas = $this->ordenarPor($linhas, 'metasAtivas');
        $maior     = $porAtivas[0] ?? null;
        $menor     = $porAtivas === [] ? null : $porAtivas[$n - 1];
        $porVenc   = $this->ordenarPor($linhas, 'metasVencidas');
        $porPrazo  = $this->ordenarPor($linhas, 'prazosProximos');

        // Base de "dados analisados" de todo alerta (L31)
        $base = [
            sprintf('%d %s', $n, $this->plural($n, 'colaborador', 'colaboradores')),
            sprintf('%d %s no período', $totalM, $this->plural($totalM, 'meta', 'metas')),
        ];
        if ($tempo !== null) {
            $base[] = sprintf('dia %d de %d', $tempo->passados, $tempo->total);
        }

        $alertas = [];

        // 1. Vencidas (L34-50) — só a contagem: o "% das ativas" (L35, L41) dividia vencidas
        //    de HOJE por metas criadas no PERÍODO, bases diferentes
        if ($venc > 0) {
            $top = array_slice(array_values(array_filter(
                $porVenc,
                static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->metasVencidas > 0,
            )), 0, 2);
            $nomes = array_map(fn (LinhaAdvogadoDashboardOutput $l): string => $this->primeiro($l->nomeAdvogado), $top);

            $alertas[] = new Alerta(
                id:        self::ID_VENCIDAS,
                nivel:     $venc >= self::VENCIDAS_CRITICO_QTD ? NivelDeAlerta::Critico : NivelDeAlerta::Monitorar,
                titulo:    sprintf('%d %s', $venc, $this->plural($venc, 'meta vencida', 'metas vencidas')),
                problema:  $venc === 1
                    ? '1 meta passou do prazo e continua aberta.'
                    : sprintf('%d metas passaram do prazo e continuam abertas.', $venc),
                evidencia: implode(' e ', array_map(
                    fn (LinhaAdvogadoDashboardOutput $l): string => sprintf('%s tem %d', $this->primeiro($l->nomeAdvogado), $l->metasVencidas),
                    $top,
                )) . '.',
                // causa (L42) de fora: compara o ritmo atual com o necessário, que não existe
                impacto:   'Metas vencidas aumentam o risco de prazo processual perdido.',
                acao:      sprintf(
                    'Priorizar %s antes de novas tarefas, começando por %s.',
                    $venc === 1 ? 'a vencida' : sprintf('as %d vencidas', $venc),
                    implode(' e ', $nomes),
                ),
                responsavel:    'Coordenação operacional',
                prazo:          'Hoje',
                acompanhamento: 'Conferir diariamente até zerar as vencidas.',
                fatores:   ['Prazo: já vencido', 'Volume: ' . $venc],
                dados:     [...$base, 'metas vencidas por colaborador (contadas em relação a hoje, não ao período)'],
            );
        }

        // 2. Concentração de carga (L53-68)
        if ($maior !== null && $menor !== null && $media > 0 && $maior->metasAtivas >= $media * self::CONCENTRACAO_FATOR) {
            $excesso = (int) round($maior->metasAtivas - $media);
            $nome    = $this->primeiro($maior->nomeAdvogado);
            $nomeMenor = $this->primeiro($menor->nomeAdvogado);

            $alertas[] = new Alerta(
                id:        self::ID_CONCENTRACAO,
                nivel:     $maior->metasAtivas >= $media * self::CONCENTRACAO_ATENCAO_FATOR ? NivelDeAlerta::Atencao : NivelDeAlerta::Monitorar,
                titulo:    'Carga concentrada em ' . $nome,
                problema:  sprintf('%s tem %d metas ativas.', $nome, $maior->metasAtivas),
                evidencia: sprintf(
                    'São %d acima da média da equipe (%s). Volume não é sinônimo de baixa produtividade: %s concluiu %d no período.',
                    $excesso,
                    $this->f1($media),
                    $nome,
                    $maior->totalMetas - $maior->metasAtivas,
                ),
                causa:     'Distribuição desigual de novas metas entre os responsáveis.',
                // L61 dizia "a fila cresce mais rápido": tendência que ninguém mediu — fica o fato
                impacto:   sprintf(
                    'A fila desse responsável é a maior da equipe: %d ativas contra a média de %s.',
                    $maior->metasAtivas,
                    $this->f1($media),
                ),
                acao:      sprintf(
                    'Avaliar redistribuir cerca de %d metas para %s (%d ativas), respeitando processos estratégicos.',
                    (int) ceil($excesso / 2),
                    $nomeMenor,
                    $menor->metasAtivas,
                ),
                responsavel:    'Coordenação',
                prazo:          'Esta semana',
                acompanhamento: sprintf('Comparar a fila de %s com a média em 7 dias.', $nome),
                // "Tendência: fila crescendo" (L64) de fora: o JS afirma sem medir
                fatores:   [
                    sprintf('Carga: %d%% da média', (int) round($maior->metasAtivas / $media * 100)),
                    sprintf('Menor fila: %s, %d %s', $nomeMenor, $menor->metasAtivas, $this->plural($menor->metasAtivas, 'ativa', 'ativas')),
                ],
                dados:     [...$base, 'metas ativas por colaborador', 'média da equipe'],
            );
        }

        // 3. Prazos próximos (L71-86)
        if ($prazos > 0) {
            $t    = $porPrazo[0];
            $nome = $this->primeiro($t->nomeAdvogado);
            $pt   = $this->pct($t->prazosProximos, $prazos);

            $alertas[] = new Alerta(
                id:        self::ID_PRAZOS,
                nivel:     $prazos >= self::PRAZOS_ATENCAO ? NivelDeAlerta::Atencao : NivelDeAlerta::Monitorar,
                titulo:    sprintf('%d %s', $prazos, $this->plural($prazos, 'prazo próximo', 'prazos próximos')),
                problema:  sprintf('%d %s nos próximos dias.', $prazos, $this->plural($prazos, 'meta vence', 'metas vencem')),
                evidencia: sprintf('%s concentra %d (%d%%).', $nome, $t->prazosProximos, $pt),
                causa:     'Prazos agrupados no mesmo intervalo.',
                impacto:   'Sem priorização, parte delas pode virar vencida no fim do período.',
                // "em até 3 dias" (L80) virou a janela real do sistema (7 dias)
                acao:      sprintf(
                    'Concluir primeiro as metas com prazo nos próximos %d dias e checar a agenda de %s.',
                    self::PRAZO_PROXIMO_DIAS,
                    $nome,
                ),
                responsavel:    $nome . ' e coordenação',
                prazo:          'Próximas 48 horas',
                acompanhamento: 'Reavaliar em 24 horas.',
                fatores:   ['Prazo: até ' . self::PRAZO_PROXIMO_DIAS . ' dias', 'Concentração: ' . $pt . '% em uma pessoa'],
                dados:     [...$base, 'prazos próximos por colaborador'],
            );
        }

        // 4. "Entrada acima da conclusão" (L89-105) NÃO entra: novas − concluídas das metas
        //    criadas no período é o que segue aberto, não uma tendência (ver docblock).
        $atual = $ritmo->ritmoAtual;

        // 6. Urgentes (L134-148) — gatilho pelo card; sem evidência por pessoa (a tabela não traz)
        if ($urg > 0) {
            $alertas[] = new Alerta(
                id:        self::ID_URGENTES,
                nivel:     $urg >= self::URGENTES_ATENCAO ? NivelDeAlerta::Atencao : NivelDeAlerta::Monitorar,
                titulo:    sprintf('%d %s', $urg, $this->plural($urg, 'pasta urgente', 'pastas urgentes')),
                problema:  sprintf('%d %s com prioridade Urgente.', $urg, $this->plural($urg, 'pasta está', 'pastas estão')),
                causa:     'Pastas marcadas com prioridade Urgente no período.',
                impacto:   'Urgências competem com as metas do período.',
                acao:      'Confirmar responsável e próximo passo de cada pasta urgente.',
                responsavel:    'Coordenação',
                prazo:          'Hoje',
                acompanhamento: 'Revisar a lista de urgentes diariamente.',
                fatores:   ['Prioridade: Urgente', 'Volume: ' . $urg],
                dados:     [...$base, 'card Demandas urgentes (pastas por prioridade)'],
            );
        }

        // 8. Oportunidade: capacidade disponível (L167-177)
        if ($menor !== null && $maior !== $menor && $media > 0 && $menor->metasAtivas <= $media * self::CAPACIDADE_FATOR) {
            $nome = $this->primeiro($menor->nomeAdvogado);

            $alertas[] = new Alerta(
                id:        self::ID_CAPACIDADE,
                nivel:     NivelDeAlerta::Oportunidade,
                titulo:    'Capacidade disponível',
                problema:  sprintf('%s tem %d metas ativas, abaixo da média (%s).', $nome, $menor->metasAtivas, $this->f1($media)),
                // JS diz "Sem vencidas acumuladas: N" mesmo com N > 0; aqui o número manda
                evidencia: sprintf('Metas vencidas: %d.', $menor->metasVencidas),
                causa:     'Menor volume atribuído no período.',
                impacto:   'Pode absorver parte da fila sem prejudicar o próprio ritmo.',
                acao:      sprintf('Direcionar as próximas metas novas para %s.', $nome),
                responsavel:    'Coordenação',
                prazo:          'Próximas distribuições',
                acompanhamento: 'Verificar equilíbrio em 7 dias.',
                fatores:   [sprintf('Carga: %d%% da média', (int) round($menor->metasAtivas / $media * 100))],
                dados:     [...$base, 'metas ativas por colaborador'],
            );
        }

        // 9. Melhoria: colaboradores sem vencidas (L180-186)
        $limpos = array_values(array_filter(
            $linhas,
            static fn (LinhaAdvogadoDashboardOutput $l): bool => $l->metasVencidas === 0 && $l->totalMetas > 0,
        ));
        if ($limpos !== []) {
            $k = count($limpos);

            $alertas[] = new Alerta(
                id:        self::ID_SEM_VENCIDAS,
                nivel:     NivelDeAlerta::Normal,
                titulo:    sprintf('%d sem metas vencidas', $k),
                problema:  sprintf(
                    '%s %s sem vencidas.',
                    implode(', ', array_map(fn (LinhaAdvogadoDashboardOutput $l): string => $this->primeiro($l->nomeAdvogado), $limpos)),
                    $k === 1 ? 'está' : 'estão',
                ),
                evidencia: 'Nenhuma meta vencida hoje.',
                causa:     'Prazos cumpridos.',
                impacto:   'Referência de boa prática para a equipe.',
                acao:      'Manter.',
                acompanhamento: 'Semanal.',
                fatores:   ['Vencidas: 0'],
                dados:     $base,
            );
        }

        // L188: ordem por nível (crítico primeiro); usort é estável, empate mantém a ordem das regras
        usort($alertas, static fn (Alerta $a, Alerta $b): int => $a->nivel->ordem() <=> $b->nivel->ordem());

        $contagem = [];
        foreach (NivelDeAlerta::cases() as $nivel) {
            $contagem[$nivel->value] = count(array_filter($alertas, static fn (Alerta $a): bool => $a->nivel === $nivel));
        }

        $porId = [];
        foreach ($alertas as $a) {
            $porId[$a->id] = $a;
        }

        $qualidade = $this->qualidade($linhas, $tempo, $n);

        return new LeituraAvancada(
            alertas:          $alertas,
            contagem:         $contagem,
            cockpit:          $this->cockpit($contagem, $prazos, $venc, $ativas, $atual, $restam),
            qualidadeNota:    $qualidade['nota'],
            qualidadeNotas:   $qualidade['notas'],
            distribuicao:     $this->distribuicao($porAtivas),
            mediaPct:         (int) round($media / $this->escalaDasBarras($linhas) * 100),
            mediaValor:       $media,
            perguntas:        $this->perguntas($porId, $media),
            visaoEstrategica: $this->visaoEstrategica($ritmo, $porAtivas),
            pior:             $alertas[0]->nivel ?? NivelDeAlerta::Normal,
        );
    }

    /**
     * Qualidade dos dados (L192-200): nota com penalidades declaradas e as notas que as
     * explicam. Não é medida de nada externo — é o quanto a própria leitura está apoiada.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     *
     * @return array{nota: int, notas: string[]}
     */
    private function qualidade(array $linhas, ?TempoDoPeriodo $tempo, int $n): array
    {
        $semCargo = count(array_filter(
            $linhas,
            static fn (LinhaAdvogadoDashboardOutput $l): bool => trim((string) $l->cargoNome) === '',
        ));
        $q     = 100;
        $notas = [];

        if ($semCargo > 0) {
            $q -= self::QUALIDADE_SEM_CARGO * $semCargo;
            $notas[] = sprintf('%d %s sem cargo informado.', $semCargo, $this->plural($semCargo, 'colaborador', 'colaboradores'));
        }

        if ($tempo !== null && !$tempo->encerrado) {
            if ($tempo->passados < $tempo->total * self::QUALIDADE_INICIO_FRACAO) {
                $q -= self::QUALIDADE_INICIO;
                $notas[] = 'Início do período: tendências ainda pouco confiáveis.';
            } else {
                $q -= self::QUALIDADE_ANDAMENTO;
                $notas[] = 'Período em andamento: os números ainda vão mudar.';
            }
        }

        if ($n < self::QUALIDADE_POUCOS_MIN) {
            $q -= self::QUALIDADE_POUCOS;
            $notas[] = 'Poucos colaboradores no filtro para comparar.';
        }

        $notas[] = 'Complexidade das metas não é registrada; volume não mede esforço.';

        return ['nota' => max(self::QUALIDADE_MIN, $q - self::QUALIDADE_AJUSTE), 'notas' => $notas];
    }

    /**
     * Cockpit (L203-212), sem "Produtividade" (rótulo do estado do ritmo, não classificado).
     *
     * @param array<string, int> $contagem
     *
     * @return IndicadorDoCockpit[]
     */
    private function cockpit(array $contagem, int $prazos, int $venc, int $ativas, ?float $atual, int $restam): array
    {
        $criticos = $contagem[NivelDeAlerta::Critico->value] ?? 0;
        $atencao  = $contagem[NivelDeAlerta::Atencao->value] ?? 0;
        // mesmo universo (metas criadas no período): fila ativa ÷ (concluídas/dia × dias restantes)
        $capUso   = $atual !== null && $atual > 0 && $restam > 0
            ? (int) round($ativas / ($atual * $restam) * 100)
            : null;

        return [
            new IndicadorDoCockpit(
                'Saúde operacional',
                $criticos > 0 ? 'Em risco' : ($atencao > 0 ? 'Atenção' : 'Estável'),
                $criticos > 0 ? NivelDeAlerta::Critico : ($atencao > 0 ? NivelDeAlerta::Atencao : NivelDeAlerta::Normal),
            ),
            new IndicadorDoCockpit(
                'Prazos',
                $prazos . ' ' . $this->plural($prazos, 'próximo', 'próximos'),
                $prazos >= self::PRAZOS_ATENCAO ? NivelDeAlerta::Atencao : ($prazos > 0 ? NivelDeAlerta::Monitorar : NivelDeAlerta::Normal),
            ),
            // L208 mostrava "% vencida" da fila ativa: bases diferentes (hoje × período) — fica a contagem
            new IndicadorDoCockpit(
                'Vencidas',
                (string) $venc,
                $venc >= self::VENCIDAS_CRITICO_QTD ? NivelDeAlerta::Critico : ($venc > 0 ? NivelDeAlerta::Monitorar : NivelDeAlerta::Normal),
                'Metas com prazo anterior a hoje ainda abertas (não depende do período)',
            ),
            new IndicadorDoCockpit(
                'Capacidade',
                $capUso === null ? 'Sem base' : $capUso . '%',
                match (true) {
                    $capUso === null                           => NivelDeAlerta::Monitorar,
                    $capUso > self::CAPACIDADE_USO_CRITICO     => NivelDeAlerta::Critico,
                    $capUso > self::CAPACIDADE_USO_ATENCAO     => NivelDeAlerta::Atencao,
                    $capUso > self::CAPACIDADE_USO_MONITORAR   => NivelDeAlerta::Monitorar,
                    default                                    => NivelDeAlerta::Normal,
                },
                'Fila ativa ÷ (conclusão diária × dias restantes)',
            ),
            new IndicadorDoCockpit(
                'Riscos',
                $criticos . ' ' . $this->plural($criticos, 'crítico', 'críticos'),
                $criticos > 0 ? NivelDeAlerta::Critico : NivelDeAlerta::Normal,
            ),
        ];
    }

    /**
     * Barras por colaborador (L215-216), da maior fila para a menor.
     *
     * @param LinhaAdvogadoDashboardOutput[] $porAtivas
     *
     * @return DistribuicaoDaPessoa[]
     */
    private function distribuicao(array $porAtivas): array
    {
        $maxA = $this->escalaDasBarras($porAtivas);

        return array_map(fn (LinhaAdvogadoDashboardOutput $l): DistribuicaoDaPessoa => new DistribuicaoDaPessoa(
            nome:        $this->primeiro($l->nomeAdvogado),
            ativas:      $l->metasAtivas,
            vencidas:    $l->metasVencidas,
            pctAtivas:   (int) round($l->metasAtivas / $maxA * 100),
            pctVencidas: (int) round($l->metasVencidas / $maxA * 100),
        ), $porAtivas);
    }

    /**
     * Perguntas guiadas (L220-226), respondidas só a partir dos achados.
     *
     * @param array<string, Alerta> $porId
     *
     * @return PerguntaGuiada[]
     */
    private function perguntas(array $porId, float $media): array
    {
        $achar = static fn (string $id): ?Alerta => $porId[$id] ?? null;
        $p     = [];

        $gargalo = $achar(self::ID_CONCENTRACAO) ?? $achar(self::ID_VENCIDAS);
        $p[] = new PerguntaGuiada('Onde está nosso maior gargalo?', $gargalo?->problema ?? 'Nenhum gargalo relevante nos dados atuais.');

        $conc = $achar(self::ID_CONCENTRACAO);
        $p[] = new PerguntaGuiada(
            'Qual equipe ou pessoa está sobrecarregada?',
            $conc !== null
                ? $conc->problema . ' ' . $conc->evidencia
                : sprintf('A carga está distribuída perto da média (%s por pessoa).', $this->f1($media)),
        );

        // L223 "Se nada mudar…" não entra: dependia do alerta de entrada ou do estado do ritmo

        $prioridades = array_filter([
            $achar(self::ID_VENCIDAS)?->acao,
            $achar(self::ID_PRAZOS)?->acao,
            $achar(self::ID_URGENTES)?->acao,
        ]);
        $p[] = new PerguntaGuiada(
            'Quais tarefas deveriam ser priorizadas?',
            $prioridades !== [] ? implode(' ', $prioridades) : 'Não há vencidas, prazos próximos ou urgentes em aberto.',
        );

        $riscos = array_map(
            static fn (Alerta $a): string => $a->titulo,
            array_filter($porId, static fn (Alerta $a): bool => $a->nivel === NivelDeAlerta::Critico || $a->nivel === NivelDeAlerta::Atencao),
        );
        $p[] = new PerguntaGuiada(
            'Mostre os principais riscos operacionais.',
            $riscos !== [] ? implode(' · ', $riscos) : 'Sem riscos críticos ou de atenção.',
        );

        return $p;
    }

    /**
     * "Visão estratégica da equipe" (dc L2688-2697), só as linhas com lastro.
     *
     * @param LinhaAdvogadoDashboardOutput[] $porAtivas
     *
     * @return PerguntaGuiada[]
     */
    private function visaoEstrategica(LeituraDoRitmo $ritmo, array $porAtivas): array
    {
        $v = [];

        // L2690
        $v[] = new PerguntaGuiada('Principal gargalo', $ritmo->riscos[0]->texto ?? 'Nenhum gargalo relevante');

        // L2691: com sobrecarga, quem concentra; senão, quem tem mais fila "(dentro do normal)"
        $conc = $ritmo->equipe->sobrecarga;
        $top  = $porAtivas[0] ?? null;
        if ($conc !== null) {
            $v[] = new PerguntaGuiada('Maior concentração', sprintf('%s, %d metas ativas', $conc->nomeAdvogado, $conc->metasAtivas));
        } elseif ($top !== null) {
            $v[] = new PerguntaGuiada('Maior concentração', sprintf('%s, %d metas ativas (dentro do normal)', $top->nomeAdvogado, $top->metasAtivas));
        }

        // L2693
        $v[] = new PerguntaGuiada(
            'Metas perto do vencimento',
            sprintf('%d %s com prazo próximo', $ritmo->prazos, $this->plural($ritmo->prazos, 'meta', 'metas')),
        );

        // L2695
        $acao = $ritmo->oQueFazer();
        if ($acao !== null) {
            $v[] = new PerguntaGuiada('Ação de hoje', $acao->texto);
        }

        return $v;
    }

    /**
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     *
     * @return LinhaAdvogadoDashboardOutput[] decrescente pelo campo; usort é estável (empate mantém a ordem da tabela)
     */
    private function ordenarPor(array $linhas, string $campo): array
    {
        $copia = array_values($linhas);
        usort($copia, static fn (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b): int => $b->$campo <=> $a->$campo);

        return $copia;
    }

    /**
     * Escala das barras da distribuição, no mínimo 1. O JS usa só a maior fila ativa (`maxA`,
     * L215), mas as vencidas são de hoje e podem passar da maior fila do período — a barra
     * vazaria. A escala é o maior número entre ativas e vencidas: as duas barras cabem e
     * continuam na mesma régua (contagens), sem razão entre as duas bases.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     */
    private function escalaDasBarras(array $linhas): int
    {
        return max([1, ...array_map(
            static fn (LinhaAdvogadoDashboardOutput $l): int => max($l->metasAtivas, $l->metasVencidas),
            $linhas,
        )]);
    }

    /** `pct` do JS (L15): percentual inteiro, 0 quando o denominador é zero. */
    private function pct(int $a, int $b): int
    {
        return $b > 0 ? (int) round($a / $b * 100) : 0;
    }

    /** `f1` do JS (L16): uma casa decimal em pt-BR ("1", "1,5"). */
    private function f1(float $v): string
    {
        return str_replace('.', ',', (string) round($v, 1));
    }

    private function primeiro(string $nome): string
    {
        return explode(' ', trim($nome))[0];
    }

    private function plural(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }
}
