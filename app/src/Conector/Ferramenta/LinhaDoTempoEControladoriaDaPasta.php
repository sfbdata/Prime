<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\DTO\EventoDaTimelineOutput;
use App\Pasta\DTO\FiltroDaTimelineInput;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\UseCase\LerPontosDoRelatorioInicialUseCase;
use App\Pasta\UseCase\ListarAlertasDaPastaUseCase;

/**
 * As partes `timeline` e `controladoria` da `ler_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.4, PR 14), com a mesma
 * visibilidade da tela:
 *
 *  - `timeline`: a Timeline inteligente (`pasta_timeline`), pelo {@see MontadorDaTimelineInteligente}, que já tira o que vem
 *    das abas ocultas e só mostra o financeiro a quem o vê. Só leitura: a visita ({@see \App\Pasta\UseCase\RegistrarVisitaDaTimelineUseCase})
 *    nunca é gravada. Saem os {@see EVENTOS} mais recentes, sem o link e sem o texto de antes da edição; o texto livre passa
 *    pelo {@see ProtecaoDoTextoDoChat::comoDado()} e encolhe, e depois some evento, até a parte caber no espaço da resposta;
 *  - `controladoria`: os alertas ({@see ListarAlertasDaPastaUseCase}) e os pontos do relatório inicial
 *    ({@see LerPontosDoRelatorioInicialUseCase}). O alerta de metas e prazos some com a aba Metas oculta; os do contrato e
 *    da unidade, que citam outras pastas e o valor do contrato, só saem a quem vê o financeiro, e nunca com o detalhe, nem
 *    o do titular do extrato (nome do réu): o Cadastro sai só como preenchido ou falta (6.2, regra 4).
 */
final class LinhaDoTempoEControladoriaDaPasta
{
    public const EVENTOS = 30;
    public const ATENCAO = 5;

    private const TEXTO_MAXIMO = 300;
    private const TEXTO_MINIMO = 60;
    private const TAMANHO_DO_TITULO = 160;
    private const TAMANHO_DO_ALERTA = 300;

    /** Alertas cujo detalhe cita outras pastas, o valor do contrato ou o nome do réu: saem sem ele. */
    private const SEM_DETALHE = ['contrato', 'unidade', 'titular'];

    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly MontadorDaTimelineInteligente $montador,
        private readonly ListarAlertasDaPastaUseCase $alertas,
        private readonly LerPontosDoRelatorioInicialUseCase $pontos,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /**
     * @param int $espaco o que sobrou do teto da resposta, medido como o despacho mede
     *
     * @return array<string, mixed>
     */
    public function linhaDoTempo(Pasta $pasta, ChamadaDoConector $chamada, int $espaco): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $saida = $this->montador->montar($pasta, $escritorio, $chamada->contexto->pessoa, new FiltroDaTimelineInput(limite: self::EVENTOS));
        $protegidos = array_map(fn (EventoDaTimelineOutput $e): array => $this->evento($e, $escritorio), $saida->eventos);

        $tamanho = self::TEXTO_MAXIMO;
        $quantos = \count($protegidos);
        while (true) {
            $dados = $this->dados($saida->totalFiltrado, $saida->truncado, $quantos, $protegidos, $tamanho, $saida->contagens, $escritorio, $saida->resumo, $saida->atencao);
            if ($quantos <= 1 || mb_strlen((string) json_encode($dados, self::JSON)) <= $espaco) {
                return $dados;
            }
            if ($tamanho > self::TEXTO_MINIMO) {
                $tamanho = max(self::TEXTO_MINIMO, intdiv($tamanho * 3, 4));
            } else {
                $quantos = intdiv($quantos, 2);
            }
        }
    }

    /**
     * @param list<array<string, mixed>>   $protegidos
     * @param array<string, int>           $contagens
     * @param list<EventoDaTimelineOutput> $atencao
     *
     * @return array<string, mixed>
     */
    private function dados(int $total, bool $truncado, int $quantos, array $protegidos, int $tamanho, array $contagens, Tenant $escritorio, string $resumo, array $atencao): array
    {
        $eventos = [];
        foreach (\array_slice($protegidos, 0, $quantos) as $evento) {
            $texto = (string) $evento['texto'];
            $cortado = mb_strlen($texto) > $tamanho;
            $eventos[] = array_replace($evento, ['texto' => $cortado ? mb_substr($texto, 0, $tamanho) . '…' : $texto, 'cortado' => $cortado]);
        }

        return [
            'eventos' => $eventos,
            'total' => $total,
            'truncado' => $truncado || $quantos < \count($protegidos),
            'contagens_por_categoria' => $contagens,
            'resumo' => self::cortar($this->protecao->comoDado($escritorio, $resumo), self::TEXTO_MAXIMO),
            'precisa_de_atencao' => array_map(
                fn (EventoDaTimelineOutput $e): string => self::cortar($this->protecao->rotulo($escritorio, $e->titulo, ''), self::TAMANHO_DO_TITULO),
                \array_slice($atencao, 0, self::ATENCAO),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function evento(EventoDaTimelineOutput $e, Tenant $escritorio): array
    {
        $rotulo = fn (?string $t): ?string => $t !== null ? $this->protecao->rotulo($escritorio, $t, '') : null;

        return [
            'id' => $e->id,
            'tipo' => $e->tipo,
            'categoria' => $e->categoria,
            'quando' => $e->quando->format($e->soData ? 'Y-m-d' : \DateTimeInterface::ATOM),
            'titulo' => self::cortar((string) $rotulo($e->titulo), self::TAMANHO_DO_TITULO),
            'texto' => $e->texto !== null ? $this->protecao->comoDado($escritorio, $e->texto) : '',
            'autor' => $rotulo($e->autor),
            'fonte' => $rotulo($e->fonte),
            'prioridade' => $e->prioridade,
            'pendente' => $e->pendente,
            'editado' => $e->editado,
        ];
    }

    /**
     * @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>} $decisao
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    public function controladoria(Pasta $pasta, ChamadaDoConector $chamada, array $decisao): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $pessoa = $chamada->contexto->pessoa;
        try {
            $saida = $this->alertas->executar($pasta, $pessoa, $escritorio);
            $pontos = $this->pontos->executar($pasta, $pessoa, $escritorio);
        } catch (PastaDeOutroEscritorioException|SemPermissaoParaVerPastaException) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $visiveis = array_values(array_filter($saida->alertas, fn (AlertaDaPasta $a): bool => self::alertaVisivel($a, $decisao)));

        return [
            'alertas' => array_map(fn (AlertaDaPasta $a): array => $this->alerta($a, $escritorio), $visiveis),
            'urgentes' => \count(array_filter($visiveis, static fn (AlertaDaPasta $a): bool => $a->nivel !== AlertaDaPasta::BAIXA)),
            'pontos_do_relatorio_inicial' => [
                'itens' => array_map(static fn (array $i): array => ['ponto' => $i['k'], 'rotulo' => $i['rot'], 'registrado' => $i['ok'], 'dica' => $i['dica']], $pontos->itens),
                'completo' => $pontos->completo(),
                'selo' => $pontos->selo(),
            ],
        ];
    }

    /** @param array{pastaId: int, financeiro: bool, abasOcultas: list<string>} $decisao */
    private static function alertaVisivel(AlertaDaPasta $a, array $decisao): bool
    {
        return match (true) {
            \in_array($a->chave, ['metas', 'prazos'], true) => !\in_array(AbaDaPasta::Tarefas->value, $decisao['abasOcultas'], true),
            \in_array($a->chave, ['contrato', 'unidade'], true) => $decisao['financeiro'],
            default => true,
        };
    }

    /** @return array<string, string> */
    private function alerta(AlertaDaPasta $a, Tenant $escritorio): array
    {
        $texto = fn (string $t): string => self::cortar($this->protecao->rotulo($escritorio, $t, ''), self::TAMANHO_DO_ALERTA);

        return [
            'tipo' => $a->chave,
            'nivel' => $a->nivel,
            'titulo' => $texto($a->titulo),
            'detalhe' => \in_array($a->chave, self::SEM_DETALHE, true) ? '' : $texto($a->detalhe),
            'sugestao' => $texto($a->sugestao),
        ];
    }

    private static function cortar(string $texto, int $tamanho): string
    {
        return mb_strlen($texto) > $tamanho ? mb_substr($texto, 0, $tamanho) . '…' : $texto;
    }
}
