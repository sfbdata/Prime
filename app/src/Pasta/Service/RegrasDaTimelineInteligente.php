<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\EventoDaTimelineOutput;
use App\Pasta\DTO\FiltroDaTimelineInput;

/**
 * As regras FIXAS da Timeline inteligente — sem modelo de linguagem (decisão D-IA da Trilha B:
 * nada de IA, nada de ✦; o rótulo da tela diz "por regras").
 *
 * Portadas do protótipo (`bluejus-central.js`, `tlEventos`/`tlFiltrar`/`tlResumo`/`tlPend`), só no
 * que tem lastro no sistema:
 *  - prioridade do prazo da meta: vencida há mais de 30 dias = crítico; vencida = importante;
 *  - publicação do Push: "importante" quando o tipo fala em intimação, citação ou prazo;
 *  - "Precisa de atenção": pendente (publicação não lida, pagamento vencido) ou crítico;
 *  - marcos: criação da pasta, primeira publicação do Push (só com a lista completa), todas as
 *    metas concluídas, financeiro quitado;
 *  - filtros por categoria, período, pessoa e busca com sinônimos;
 *  - resumo da atividade por contagem.
 *
 * Classe pura (sem banco, sem relógio próprio): o "agora" vem de fora, para o teste fixá-lo.
 */
final class RegrasDaTimelineInteligente
{
    /** Agrupamentos do desenho (`TLC`) que têm fonte no sistema: [rótulo, ícone, cor]. */
    public const CATEGORIAS = [
        'registro'   => ['Registros', 'bi-journal-text', '#455c6b'],
        'documento'  => ['Documentos', 'bi-file-earmark-text', '#6b5bb0'],
        'meta'       => ['Metas', 'bi-list-check', '#b4460a'],
        'processo'   => ['Processos', 'bi-bank', '#34505f'],
        'financeiro' => ['Financeiro', 'bi-cash-coin', '#1e7b45'],
        'cadastro'   => ['Cadastro', 'bi-person-vcard', '#0b5f86'],
    ];

    /** `TLP` do desenho. */
    public const PRIORIDADES = [
        'critico'    => ['Crítico', '#cf3f36'],
        'importante' => ['Importante', '#c76a12'],
        'info'       => ['Informativo', '#0a7aad'],
        'ok'         => ['Concluído', '#1e7b45'],
    ];

    public const MARCO_CRIACAO          = 'Criação da pasta';
    public const MARCO_PRIMEIRA_PUB     = 'Primeira publicação no Push';
    public const MARCO_METAS_CONCLUIDAS = 'Todas as metas concluídas';
    public const MARCO_FINANCEIRO       = 'Financeiro quitado';

    private const LIMITE_ATENCAO = 8;

    /** Sinônimos da busca (`TL_SIN` do desenho). */
    private const SINONIMOS = [
        'contrato'  => ['contrat', 'honorar'],
        'telefone'  => ['telefone', 'fone', 'celular', 'whatsapp'],
        'documento' => ['document', 'procurac', 'anex', 'pdf'],
        'prazo'     => ['prazo', 'vence', 'intimac'],
        'pagamento' => ['pagament', 'parcela', 'pix', 'boleto'],
    ];

    /** Palavras de pergunta que não são assunto ("quando falaram sobre o contrato"). */
    private const PALAVRAS_VAZIAS = [
        'quando', 'quero', 'encontrar', 'falaram', 'sobre', 'mostre', 'tudo', 'que', 'aconteceu',
        'com', 'relacionado', 'foi', 'cliente', 'onde', 'para', 'dos', 'das',
    ];

    public function prioridadeDoPrazoDaMeta(int $diasDeAtraso): string
    {
        return match (true) {
            $diasDeAtraso > 30 => 'critico',
            $diasDeAtraso > 0  => 'importante',
            default            => 'info',
        };
    }

    public function prioridadeDaPublicacao(?string $tipo): string
    {
        return preg_match('/intima|cita|prazo/', $this->normalizar((string) $tipo)) === 1 ? 'importante' : 'info';
    }

    /**
     * Marca, na lista COMPLETA (antes de qualquer filtro), os eventos que são marco por regra.
     *
     * A primeira publicação só é marco quando a lista do Push veio inteira: com o teto atingido, a
     * mais antiga carregada não é necessariamente a primeira — e a tela não afirma o que não sabe.
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<EventoDaTimelineOutput>
     */
    public function aplicarMarcos(array $eventos, bool $pushCompleto, bool $financeiroQuitado): array
    {
        $marcar = [];

        $criacao = $this->maisAntigo($eventos, 'pasta_criada');
        if ($criacao !== null) {
            $marcar[$criacao] = self::MARCO_CRIACAO;
        }

        if ($pushCompleto) {
            $primeira = $this->maisAntigo($eventos, 'publicacao');
            if ($primeira !== null) {
                $marcar[$primeira] = self::MARCO_PRIMEIRA_PUB;
            }
        }

        if ($financeiroQuitado) {
            $ultima = $this->maisRecente($eventos, 'pagamento_quitado');
            if ($ultima !== null) {
                $marcar[$ultima] = self::MARCO_FINANCEIRO;
            }
        }

        return array_map(
            static fn (EventoDaTimelineOutput $e) => isset($marcar[$e->id]) ? $e->comMarco($marcar[$e->id]) : $e,
            $eventos,
        );
    }

    /**
     * A data em que a última meta foi concluída, quando TODAS as metas da pasta estão concluídas
     * e todas têm a data gravada. Pasta sem meta não tem esse marco; meta concluída sem data
     * (legado) também não — não se inventa o dia.
     *
     * @param list<array{concluida: bool, concluidaEm: ?\DateTimeImmutable}> $metas
     */
    public function dataDeTodasAsMetasConcluidas(array $metas): ?\DateTimeImmutable
    {
        if ($metas === []) {
            return null;
        }

        $ultima = null;
        foreach ($metas as $meta) {
            if (!$meta['concluida'] || $meta['concluidaEm'] === null) {
                return null;
            }
            if ($ultima === null || $meta['concluidaEm'] > $ultima) {
                $ultima = $meta['concluidaEm'];
            }
        }

        return $ultima;
    }

    /**
     * "Precisa de atenção" (`tlPend`): pendente ou crítico, os mais recentes primeiro, até 8.
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<EventoDaTimelineOutput>
     */
    public function atencao(array $eventos): array
    {
        $lista = array_values(array_filter(
            $eventos,
            static fn (EventoDaTimelineOutput $e) => $e->pendente || $e->prioridade === 'critico',
        ));

        return array_slice($this->ordenar($lista), 0, self::LIMITE_ATENCAO);
    }

    /**
     * Do mais novo para o mais antigo; empate pelo id, para a ordem não variar entre duas cargas.
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<EventoDaTimelineOutput>
     */
    public function ordenar(array $eventos): array
    {
        usort(
            $eventos,
            static fn (EventoDaTimelineOutput $a, EventoDaTimelineOutput $b) => [$b->quando, $b->id] <=> [$a->quando, $a->id],
        );

        return $eventos;
    }

    /**
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<EventoDaTimelineOutput>
     */
    public function filtrar(array $eventos, FiltroDaTimelineInput $filtro, \DateTimeImmutable $agora): array
    {
        [$inicio, $fim] = $this->janelaDoPeriodo($filtro->periodo, $agora);
        $tokens         = $this->tokensDaBusca($filtro->busca);

        return array_values(array_filter($eventos, function (EventoDaTimelineOutput $e) use ($filtro, $inicio, $fim, $tokens): bool {
            $casaCategoria = match ($filtro->categoria) {
                'tudo'     => true,
                'pendente' => $e->pendente,
                'marco'    => $e->marco !== null,
                default    => $e->categoria === $filtro->categoria,
            };
            if (!$casaCategoria) {
                return false;
            }
            if ($inicio !== null && $e->quando < $inicio) {
                return false;
            }
            if ($fim !== null && $e->quando >= $fim) {
                return false;
            }
            if ($filtro->pessoa !== null && $e->autor !== $filtro->pessoa) {
                return false;
            }

            return $this->casaBusca($e, $tokens);
        }));
    }

    /**
     * Contagem por chip, sobre a lista inteira (o número do chip não muda ao trocar de chip).
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return array<string, int>
     */
    public function contagens(array $eventos): array
    {
        $contagem = ['tudo' => count($eventos), 'pendente' => 0, 'marco' => 0];
        foreach (array_keys(self::CATEGORIAS) as $categoria) {
            $contagem[$categoria] = 0;
        }
        foreach ($eventos as $e) {
            $contagem[$e->categoria] = ($contagem[$e->categoria] ?? 0) + 1;
            if ($e->pendente) {
                ++$contagem['pendente'];
            }
            if ($e->marco !== null) {
                ++$contagem['marco'];
            }
        }

        return $contagem;
    }

    /**
     * Pessoas que aparecem como autoras, em ordem alfabética (filtro "Todas as pessoas").
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<string>
     */
    public function pessoas(array $eventos): array
    {
        $nomes = [];
        foreach ($eventos as $e) {
            if ($e->autor !== null && $e->autor !== '') {
                $nomes[$e->autor] = true;
            }
        }
        $lista = array_keys($nomes);
        sort($lista, SORT_STRING | SORT_FLAG_CASE);

        return $lista;
    }

    /**
     * Acontecimentos depois da última visita, até agora, que não foram feitos por quem olha
     * ("Enquanto você estava fora").
     *
     * @param list<EventoDaTimelineOutput> $eventos
     * @return list<EventoDaTimelineOutput>
     */
    public function novosDesde(array $eventos, \DateTimeImmutable $desde, \DateTimeImmutable $agora, ?string $meuNome): array
    {
        return array_values(array_filter(
            $eventos,
            static fn (EventoDaTimelineOutput $e) => $e->quando > $desde
                && $e->quando <= $agora
                && ($meuNome === null || $e->autor !== $meuNome),
        ));
    }

    /**
     * Resumo da atividade por CONTAGEM (`tlResumo`) — frases fixas, nenhuma inferência.
     *
     * @param list<EventoDaTimelineOutput> $eventos
     */
    public function resumir(array $eventos): string
    {
        $conta = static fn (callable $f): int => count(array_filter($eventos, $f));

        $frases = [];
        $docs   = $conta(static fn (EventoDaTimelineOutput $e) => $e->categoria === 'documento');
        if ($docs > 0) {
            $frases[] = sprintf('%d evento(s) de documentos.', $docs);
        }
        $regs = $conta(static fn (EventoDaTimelineOutput $e) => $e->categoria === 'registro');
        if ($regs > 0) {
            $frases[] = sprintf('%d registro(s) na pasta.', $regs);
        }
        $pubs = $conta(static fn (EventoDaTimelineOutput $e) => $e->tipo === 'publicacao');
        if ($pubs > 0) {
            $frases[] = sprintf('%d movimentação(ões) no processo.', $pubs);
        }
        $concluidas = $conta(static fn (EventoDaTimelineOutput $e) => $e->tipo === 'meta_concluida');
        if ($concluidas > 0) {
            $frases[] = sprintf('%d meta(s) concluída(s).', $concluidas);
        }
        $vencidas = $conta(static fn (EventoDaTimelineOutput $e) => $e->tipo === 'prazo_meta' && $e->prioridade === 'critico');
        if ($vencidas > 0) {
            $frases[] = sprintf('%d meta(s) com prazo vencido há mais de 30 dias.', $vencidas);
        }
        $quitados = $conta(static fn (EventoDaTimelineOutput $e) => $e->tipo === 'pagamento_quitado');
        if ($quitados > 0) {
            $frases[] = sprintf('%d pagamento(s) quitado(s).', $quitados);
        }
        $cadastro = $conta(static fn (EventoDaTimelineOutput $e) => $e->categoria === 'cadastro');
        if ($cadastro > 0) {
            $frases[] = sprintf('%d alteração(ões) no cadastro da pasta.', $cadastro);
        }

        return $frases !== [] ? implode(' ', $frases) : 'Nenhuma atividade no período.';
    }

    /**
     * Texto livre para comparação: minúsculas, sem acento.
     */
    public function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
            'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * @return array{?\DateTimeImmutable, ?\DateTimeImmutable} [início inclusivo, fim exclusivo]
     */
    private function janelaDoPeriodo(string $periodo, \DateTimeImmutable $agora): array
    {
        $hoje = $agora->setTime(0, 0);

        return match ($periodo) {
            'hoje'   => [$hoje, null],
            'ontem'  => [$hoje->modify('-1 day'), $hoje],
            'semana' => [$hoje->modify('-6 days'), null],
            'mes'    => [$hoje->modify('-29 days'), null],
            default  => [null, null],
        };
    }

    /**
     * Cada palavra da busca vira um grupo de alternativas: as do sinônimo, ou o radical da
     * palavra (sem as duas últimas letras, mínimo 4). Todas as palavras precisam casar.
     *
     * @return list<list<string>>
     */
    private function tokensDaBusca(string $busca): array
    {
        $palavras = preg_split('/\s+/', $this->normalizar(trim($busca))) ?: [];
        $tokens   = [];
        foreach ($palavras as $palavra) {
            $palavra = trim($palavra, " \t\"'.,;:!?()");
            if (mb_strlen($palavra) <= 2 || in_array($palavra, self::PALAVRAS_VAZIAS, true)) {
                continue;
            }

            $grupo = null;
            foreach (self::SINONIMOS as $chave => $alternativas) {
                $casou = str_starts_with($palavra, mb_substr($chave, 0, 5));
                foreach ($alternativas as $alt) {
                    $casou = $casou || str_starts_with($palavra, $alt);
                }
                if ($casou) {
                    $grupo = array_merge([$chave], $alternativas);
                    break;
                }
            }

            $tokens[] = $grupo ?? [mb_substr($palavra, 0, max(4, mb_strlen($palavra) - 2))];
        }

        return $tokens;
    }

    /**
     * @param list<list<string>> $tokens
     */
    private function casaBusca(EventoDaTimelineOutput $e, array $tokens): bool
    {
        if ($tokens === []) {
            return true;
        }

        $palheiro = $this->normalizar(implode(' ', [$e->titulo, (string) $e->texto, (string) $e->fonte, (string) $e->autor]));
        foreach ($tokens as $alternativas) {
            $achou = false;
            foreach ($alternativas as $alt) {
                if (str_contains($palheiro, $alt)) {
                    $achou = true;
                    break;
                }
            }
            if (!$achou) {
                return false;
            }
        }

        return true;
    }

    /** @param list<EventoDaTimelineOutput> $eventos */
    private function maisAntigo(array $eventos, string $tipo): ?string
    {
        $achado = null;
        foreach ($eventos as $e) {
            if ($e->tipo === $tipo && ($achado === null || [$e->quando, $e->id] < [$achado->quando, $achado->id])) {
                $achado = $e;
            }
        }

        return $achado?->id;
    }

    /** @param list<EventoDaTimelineOutput> $eventos */
    private function maisRecente(array $eventos, string $tipo): ?string
    {
        $achado = null;
        foreach ($eventos as $e) {
            if ($e->tipo === $tipo && ($achado === null || [$e->quando, $e->id] > [$achado->quando, $achado->id])) {
                $achado = $e;
            }
        }

        return $achado?->id;
    }
}
