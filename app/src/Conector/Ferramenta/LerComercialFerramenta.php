<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\CampanhaDoRelatorio;
use App\Comercial\DTO\DataComemorativaOutput;
use App\Comercial\DTO\DisparoOutput;
use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\DTO\LinhaDoRelatorio;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\MetricasDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\PedidoDePublico;
use App\Comercial\DTO\PedidoDoRelatorio;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use App\Comercial\UseCase\AbrirCampanhaDoRelatorioUseCase;
use App\Comercial\UseCase\ConsultarEnvioDeCampanhasUseCase;
use App\Comercial\UseCase\ContarPublicosUseCase;
use App\Comercial\UseCase\ListarAutomacoesUseCase;
use App\Comercial\UseCase\ListarDatasComemorativasUseCase;
use App\Comercial\UseCase\ListarModelosDeCampanhaUseCase;
use App\Comercial\UseCase\ListarRelatorioDeCampanhasUseCase;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Comercial\UseCase\ResumirComercialUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\HtmlParaTextoDoConector;
use App\Conector\Service\MascaraDeEmailDoConector;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `ler_comercial` (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisões 34 a 41): o Comercial do Painel do Master, só leitura,
 * só para o Master da plataforma. Escritório do token não entra: o Comercial é da plataforma.
 *
 * A guarda é a primeira coisa do {@see executar}: o handler do `tools/call` já respondeu "Tool not found" a quem não é
 * Master, e esta é a segunda porta (como o `#[IsGranted]` do controller), com a recusa neutra {@see NAO_DISPONIVEL}.
 *
 * Ações (campo `acao`): `resumo`, `modelos`, `automacoes`, `campanhas`, `campanha`, `contar_publico` e `previa`.
 * Dado pessoal (6.9, "Dado pessoal (LGPD)"): nenhuma saída traz a lista de destinatários nem nome de pessoa. Saem
 * contagens e, só onde a 6.9 permite, e-mail pela {@see MascaraDeEmailDoConector}: o exemplo do público e, na campanha,
 * até {@see MAXIMO_DE_REJEITADOS} endereços rejeitados. Público `csv` e `indiv` fica fora. O HTML do modelo e da
 * campanha nunca sai; a prévia vai em texto. Leitura não grava nada.
 */
final class LerComercialFerramenta implements FerramentaDoConector
{
    public const NOME = 'ler_comercial';

    public const RESUMO = 'resumo';
    public const MODELOS = 'modelos';
    public const AUTOMACOES = 'automacoes';
    public const CAMPANHAS = 'campanhas';
    public const CAMPANHA = 'campanha';
    public const CONTAR_PUBLICO = 'contar_publico';
    public const PREVIA = 'previa';
    public const ACOES = [self::RESUMO, self::MODELOS, self::AUTOMACOES, self::CAMPANHAS, self::CAMPANHA, self::CONTAR_PUBLICO, self::PREVIA];

    /** Pelo conector só os públicos que não levam lista de e-mails (6.9). */
    public const PUBLICOS = ['leads', 'prim', 'sec', 'todos'];

    public const MOTIVO_SO_DO_MASTER = 'so_do_master';
    public const NAO_DISPONIVEL = 'Ferramenta não disponível.';

    /** Na campanha, quantos e-mails mascarados da situação de rejeitado saem (6.9). */
    public const MAXIMO_DE_REJEITADOS = 10;
    /** A situação do destinatário que a 6.9 chama de erro ou devolvido ({@see AbrirCampanhaDoRelatorioUseCase::ESTADOS}). */
    public const SITUACAO_REJEITADO = 'rejeitado';

    /** O texto da prévia é cortado aqui, abaixo dos 50 mil caracteres do despacho (6.2, regra 7). */
    public const MAXIMO_DA_PREVIA = 30_000;
    public const CORTADO = ' (texto cortado)';

    public const PREVIA_INVALIDA = 'Este modelo não monta uma prévia.';
    public const PUBLICO_RECUSADO = 'Não foi possível contar este público.';

    public function __construct(
        private readonly ResumirComercialUseCase $resumo,
        private readonly ConsultarEnvioDeCampanhasUseCase $envio,
        private readonly CotaDeEnvio $cota,
        private readonly ListarModelosDeCampanhaUseCase $modelos,
        private readonly ListarAutomacoesUseCase $automacoes,
        private readonly ListarDatasComemorativasUseCase $datas,
        private readonly ListarRelatorioDeCampanhasUseCase $relatorio,
        private readonly AbrirCampanhaDoRelatorioUseCase $campanha,
        private readonly ContarPublicosUseCase $publicos,
        private readonly PreviaDaCampanhaUseCase $previa,
        private readonly ModelosDeCampanha $fixos,
        private readonly ModeloDeCampanhaRepository $proprios,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Ler o Comercial do Painel do Master';
    }

    public function descricao(): string
    {
        return 'Lê o Comercial do Painel do Master (só leitura, só para o Master da plataforma). Ação "resumo": contagens de campanhas, fila, base e descadastrados, se o envio de campanhas está ligado e a cota de envio. "modelos": os modelos de e-mail (nome, chave, modo, se é próprio ou fixo), sem o HTML. "automacoes": as automações e as datas comemorativas, com o estado de cada uma. "campanhas": o relatório de campanhas por aba (env, ag, rec, est, arq), com "busca" e "pagina". "campanha" (com campanha_id): estado, indicadores e quantos destinatários há em cada situação; nunca a lista de e-mails. "contar_publico" (com publico: leads, prim, sec ou todos): quantos destinatários o público tem. "previa" (com modelo: id ou chave de um modelo salvo): assunto e corpo do e-mail em texto. Endereços de e-mail saem sempre mascarados e nenhum nome de destinatário sai.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => [
                    'type' => 'string',
                    'enum' => self::ACOES,
                    'description' => 'resumo, modelos, automacoes, campanhas, campanha (exige campanha_id), contar_publico (exige publico) ou previa (exige modelo).',
                ],
                'aba' => [
                    'type' => 'string',
                    'enum' => RelatorioDeCampanhasRepository::ABAS,
                    'description' => 'Só em campanhas. env (padrão), ag, rec, est ou arq.',
                ],
                'busca' => ['type' => 'string', 'maxLength' => PedidoDoRelatorio::BUSCA_MAXIMA, 'description' => 'Só em campanhas. Texto para buscar no nome da campanha.'],
                'pagina' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Só em campanhas. A página; use o proxima_pagina da resposta anterior.'],
                'campanha_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Obrigatório em campanha. O id que veio na ação campanhas.'],
                'publico' => ['type' => 'string', 'enum' => self::PUBLICOS, 'description' => 'Obrigatório em contar_publico. leads, prim, sec ou todos.'],
                'modelo' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Obrigatório em previa. O id ou a chave de um modelo salvo, como veio na ação modelos.'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirMaster($chamada);
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !\in_array($acao, self::ACOES, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return match ($acao) {
            self::RESUMO => $this->resumo(),
            self::MODELOS => $this->modelos(),
            self::AUTOMACOES => $this->automacoes(),
            self::CAMPANHAS => $this->campanhas($argumentos, $chamada),
            self::CAMPANHA => $this->campanha($argumentos),
            self::CONTAR_PUBLICO => $this->contarPublico($argumentos),
            default => $this->previa($argumentos),
        };
    }

    /** @throws ErroDaFerramenta */
    private function exigirMaster(ChamadaDoConector $chamada): void
    {
        try {
            GuardaDoMasterDoConector::exigir($chamada->contexto->pessoa);
        } catch (AccessDeniedException) {
            throw new ErroDaFerramenta(self::MOTIVO_SO_DO_MASTER, self::NAO_DISPONIVEL);
        }
    }

    private function resumo(): RespostaDaFerramenta
    {
        $r = $this->resumo->executar();
        $envio = $this->envio->executar();
        $cota = $this->cota->situacao();

        return new RespostaDaFerramenta([
            'acao' => self::RESUMO,
            'campanhas' => $r->campanhas,
            'na_fila' => $r->naFila,
            'base' => $r->base,
            'descadastrados' => $r->descadastrados,
            'testes' => $r->testes,
            'envio_de_campanhas' => ['ligado' => $envio->ligada, 'origem' => $envio->origem, 'esperando_o_envio' => $envio->esperando],
            'cota_de_envio' => [
                'enviados_24h' => $cota->enviados24h,
                'teto' => $cota->teto,
                'aviso_a_partir_de_percentual' => $cota->percentual,
                'perto_do_teto' => $cota->perto,
                'campanhas_pausadas' => $cota->campanhasPausadas,
                'libera_em' => $cota->liberaEm?->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    private function modelos(): RespostaDaFerramenta
    {
        $lista = array_map(static fn (array $m): array => [
            'chave' => (string) $m['chave'],
            'id' => $m['id'] === null ? null : (int) $m['id'],
            'nome' => (string) ($m['nome'] ?? ''),
            'modo' => (string) ($m['modo'] ?? ''),
            'assunto' => (string) ($m['assunto'] ?? ''),
            'proprio' => (bool) $m['proprio'],
        ], $this->modelos->executar());

        return new RespostaDaFerramenta(
            ['acao' => self::MODELOS, 'total' => \count($lista), 'modelos' => $lista],
            contagens: ['modelos' => \count($lista)],
        );
    }

    private function automacoes(): RespostaDaFerramenta
    {
        $dados = $this->automacoes->executar();
        $datas = $this->datas->executar();

        $automacoes = array_map(static fn (EstadoDaAutomacao $e): array => [
            'chave' => $e->automacao->value,
            'titulo' => $e->automacao->titulo(),
            'descricao' => $e->automacao->descricao(),
            'ligada' => $e->ligada,
            'atraso' => $e->atraso,
            'ligada_em' => $e->ligadaEm?->format(\DateTimeInterface::ATOM),
        ], $dados['automacoes']);
        $ultimos = self::ultimosDisparos($dados['ultimos']);
        $datasComemorativas = array_map(static fn (DataComemorativaOutput $d): array => [
            'chave' => $d->chave,
            'nome' => $d->nome,
            'ligada' => $d->ligada,
            'antecedencia_em_dias' => $d->antecedencia,
            'publico' => $d->publico,
            'proxima_data' => $d->data,
            'dias_que_faltam' => $d->diasQueFaltam,
            'envio_em' => $d->envio,
        ], $datas->datas);

        return new RespostaDaFerramenta(
            [
                'acao' => self::AUTOMACOES,
                'automacoes' => $automacoes,
                'ultimos_disparos' => $ultimos,
                'datas_comemorativas' => $datasComemorativas,
                'datas_ligadas' => $datas->ativas,
            ],
            contagens: ['automacoes' => \count($automacoes), 'datas' => \count($datasComemorativas)],
        );
    }

    /**
     * O UseCase já mascara com a máscara da tela (domínio inteiro); aqui o domínio também some, e o assunto do envio não
     * sai, porque pode levar o nome de quem recebeu (6.9, LGPD).
     *
     * @param list<DisparoOutput> $disparos
     *
     * @return list<array<string, mixed>>
     */
    private static function ultimosDisparos(array $disparos): array
    {
        return array_map(static fn (DisparoOutput $d): array => [
            'gatilho' => $d->gatilho,
            'destinatario' => MascaraDeEmailDoConector::mascarar($d->destinatario),
            'em' => $d->em,
            'simulado' => $d->simulado,
        ], $disparos);
    }

    /** @param array<string, mixed> $argumentos */
    private function campanhas(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $relatorio = $this->relatorio->executar(self::pedidoDoRelatorio($argumentos), (int) $chamada->contexto->pessoa->getId());
        $linhas = array_map(static fn (LinhaDoRelatorio $l): array => [
            'id' => $l->id,
            'nome' => $l->nome,
            'modelo' => $l->sub,
            'publico' => $l->publico,
            'total' => $l->total,
            'estado' => $l->estado,
            'rotulo' => $l->rotulo,
            'data' => $l->data,
            'metricas' => self::metricas($l->metricas),
        ], $relatorio->linhas);

        return new RespostaDaFerramenta(
            [
                'acao' => self::CAMPANHAS,
                'aba' => $relatorio->aba,
                'campanhas_por_aba' => $relatorio->abas,
                'total' => $relatorio->total,
                'pagina' => $relatorio->pagina,
                'paginas' => $relatorio->paginas(),
                'campanhas' => $linhas,
            ],
            $relatorio->pagina < $relatorio->paginas() ? $relatorio->pagina + 1 : null,
            ['campanhas' => \count($linhas)],
            $linhas === [] ? [] : ['campanha' => array_column($linhas, 'id')],
        );
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function pedidoDoRelatorio(array $argumentos): PedidoDoRelatorio
    {
        $aba = $argumentos['aba'] ?? 'env';
        $busca = $argumentos['busca'] ?? '';
        $pagina = $argumentos['pagina'] ?? 1;
        if (!\is_string($aba) || !\in_array($aba, RelatorioDeCampanhasRepository::ABAS, true)) {
            throw ErroDaFerramenta::filtroInvalido('aba');
        }
        if (!\is_string($busca)) {
            throw ErroDaFerramenta::filtroInvalido('busca');
        }
        if (!\is_int($pagina) || $pagina < 1) {
            throw ErroDaFerramenta::filtroInvalido('pagina');
        }

        return new PedidoDoRelatorio($aba, mb_substr(trim($busca), 0, PedidoDoRelatorio::BUSCA_MAXIMA), $pagina);
    }

    /** @param array<string, mixed> $argumentos */
    private function campanha(array $argumentos): RespostaDaFerramenta
    {
        $id = $argumentos['campanha_id'] ?? null;
        if (!\is_int($id) || $id < 1) {
            throw ErroDaFerramenta::filtroInvalido('campanha_id');
        }
        $c = $this->campanha->executar($id);
        if ($c === null) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return new RespostaDaFerramenta(
            [
                'acao' => self::CAMPANHA,
                'id' => $c->id,
                'assunto' => $c->assunto,
                'titulo' => $c->titulo,
                'estado' => $c->estado,
                'situacao' => $c->situacao,
                'publico' => $c->publico,
                'modelo' => $c->modelo,
                'quando' => $c->quando,
                'cancelavel' => $c->cancelavel,
                'iniciada' => $c->iniciada,
                'kpis' => $c->kpis(),
                'metricas' => self::metricas($c->metricas),
            ] + $this->destinatarios($c),
            contagens: ['destinatarios' => $c->metricas->destinatarios],
            ids: ['campanha' => [$c->id]],
        );
    }

    /**
     * A contagem por situação dos destinatários que o UseCase listou, e os e-mails mascarados só dos rejeitados (6.9).
     * O endereço anonimizado pela retenção não é e-mail: conta, mas não aparece.
     *
     * @return array{destinatarios_por_situacao: array<string, int>, rejeitados_mascarados: list<string>, sem_detalhe: int}
     */
    private function destinatarios(CampanhaDoRelatorio $c): array
    {
        $porSituacao = [];
        $rejeitados = [];
        foreach ($c->destinatarios as $d) {
            $porSituacao[$d->estado] = ($porSituacao[$d->estado] ?? 0) + 1;
            if ($d->estado === self::SITUACAO_REJEITADO && \count($rejeitados) < self::MAXIMO_DE_REJEITADOS && str_contains($d->email, '@')) {
                $rejeitados[] = MascaraDeEmailDoConector::mascarar($d->email);
            }
        }
        ksort($porSituacao);

        return ['destinatarios_por_situacao' => $porSituacao, 'rejeitados_mascarados' => $rejeitados, 'sem_detalhe' => $c->mais];
    }

    /** @param array<string, mixed> $argumentos */
    private function contarPublico(array $argumentos): RespostaDaFerramenta
    {
        $publico = $argumentos['publico'] ?? null;
        if (!\is_string($publico) || !\in_array($publico, self::PUBLICOS, true)) {
            throw ErroDaFerramenta::filtroInvalido('publico');
        }
        try {
            $contagem = $this->publicos->executar(new PedidoDePublico($publico));
        } catch (CampanhaRecusada) {
            throw ErroDaFerramenta::recusada(self::PUBLICO_RECUSADO);
        }

        return new RespostaDaFerramenta(
            [
                'acao' => self::CONTAR_PUBLICO,
                'publico' => $publico,
                'tamanho_dos_publicos' => array_intersect_key($contagem->publicos, array_flip(self::PUBLICOS)),
                'destinatarios' => $contagem->destinatarios,
                'descadastrados' => $contagem->descadastrados,
                'sem_autorizacao' => $contagem->semAutorizacao,
                'exemplo_mascarado' => $contagem->exemplo === null ? null : MascaraDeEmailDoConector::mascarar($contagem->exemplo->email),
            ],
            contagens: ['destinatarios' => $contagem->destinatarios],
        );
    }

    /** @param array<string, mixed> $argumentos */
    private function previa(array $argumentos): RespostaDaFerramenta
    {
        $modelo = $argumentos['modelo'] ?? null;
        if (!\is_string($modelo) || trim($modelo) === '') {
            throw ErroDaFerramenta::filtroInvalido('modelo');
        }
        $dados = $this->dadosDoModelo(trim($modelo));
        try {
            $mensagem = MensagemDaCampanha::deArray($dados);
            $html = $this->previa->executar($mensagem);
        } catch (CampanhaRecusada|\InvalidArgumentException) {
            throw ErroDaFerramenta::recusada(self::PREVIA_INVALIDA);
        }

        // Sem destinatário, o rodapé leva o e-mail de exemplo: todo e-mail do texto sai mascarado, por garantia (6.9).
        $texto = (string) preg_replace_callback(
            '/[^\s@<>()]+@[^\s@<>()]+\.[^\s@<>()]+/u',
            static fn (array $m): string => MascaraDeEmailDoConector::mascarar($m[0]),
            HtmlParaTextoDoConector::converter($html),
        );
        $cortado = mb_strlen($texto) > self::MAXIMO_DA_PREVIA;
        if ($cortado) {
            $texto = mb_substr($texto, 0, self::MAXIMO_DA_PREVIA) . self::CORTADO;
        }

        return new RespostaDaFerramenta([
            'acao' => self::PREVIA,
            'modelo' => ['chave' => $mensagem->chave, 'nome' => (string) ($dados['nome'] ?? ''), 'modo' => $mensagem->modo],
            'assunto' => $mensagem->assunto,
            'corpo_em_texto' => $texto,
            'cortado' => $cortado,
        ]);
    }

    /**
     * O modelo salvo pelo id (`12`), pela chave do próprio (`cu12`) ou pela chave de um fixo ou de uma data
     * comemorativa. Nada que o pedido traga vira mensagem: só o que está salvo (6.9).
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function dadosDoModelo(string $modelo): array
    {
        if (preg_match('/^(?:cu)?(\d{1,9})$/', $modelo, $partes) === 1) {
            $entidade = $this->proprios->porId((int) $partes[1]);

            return $entidade === null ? throw ErroDaFerramenta::naoEncontrado() : ModeloDaGrade::deEntidade($entidade);
        }
        if (preg_match('/^[a-z0-9-]{1,40}$/', $modelo) !== 1) {
            throw ErroDaFerramenta::filtroInvalido('modelo');
        }
        $fixo = $this->fixos->fixo($modelo);

        return $fixo === null ? throw ErroDaFerramenta::naoEncontrado() : ModeloDaGrade::deFixo($fixo);
    }

    /** @return array<string, int|bool|string> */
    private static function metricas(MetricasDaCampanha $m): array
    {
        return [
            'destinatarios' => $m->destinatarios,
            'na_fila' => $m->naFila,
            'enviados' => $m->enviados,
            'entregues' => $m->entregues,
            'lidos' => $m->lidosNoKpi() === MetricasDaCampanha::NAO_RASTREADA ? MetricasDaCampanha::NAO_RASTREADA : $m->lidos,
            'nao_lidos' => $m->naoLidos(),
            'rejeitados' => $m->rejeitados,
            'clicaram' => $m->clicaram,
            'taxa_de_leitura' => $m->taxaDeLeitura(),
            'rastreia_leitura' => $m->rastreiaLeitura,
        ];
    }
}
