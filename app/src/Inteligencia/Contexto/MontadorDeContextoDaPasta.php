<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\ClientePJ;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\SecaoDeContexto;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\Comando;
use App\Inteligencia\Enum\SecaoDoContexto;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\NeutralizadorDeConteudo;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Processo\Entity\Processo;
use Psr\Clock\ClockInterface;

/**
 * Monta o contexto de UM agente para UMA pasta (spec `inteligencia-agentes-da-pasta.md` §2):
 * cabeçalho da pasta + processos vinculados + as seções que o agente lê, cada uma com limite de
 * itens e de caracteres, PII mascarada (se o escritório não desligou), tudo neutralizado, e um
 * orçamento total — o corte é DECLARADO (contagem de omitidos por seção), nunca silencioso.
 *
 * REGRA (D4): processo em segredo (`nivelSigilo > 0` ou "Sim" manual; `Processo::emSegredoDeJustica`) → {@see ContextoBloqueadoException} antes de ler
 * qualquer outra coisa. Nunca sai.
 *
 * `<financeiro>` só entra se a solicitação disse que a pessoa pode ver o financeiro da pasta
 * (`$incluirFinanceiro`); o worker repassa a decisão gravada, não decide.
 *
 * As movimentações vêm do {@see MontadorDeContextoDoPush} — mesma leitura, mesma máscara, mesmo
 * tenant explícito —, só que limitadas a {@see LIMITE_MOVIMENTACOES} linhas aqui.
 *
 * Cada linha do prompt tem uma ASSINATURA estável (id, data absoluta, estado, conteúdo) que é o
 * que entra no `contexto_hash`; o texto relativo ("vence em N dia(s)", calculado pelo relógio
 * injetado) fica só no prompt. Assim "nada novo desde a última análise" vale enquanto o dado não
 * muda, não só no mesmo dia.
 *
 * O cadastro do financeiro (contrato, pró-bono, valor da causa) só vira linha quando há valor da
 * causa, pró-bono ou algum pagamento/observação: a pasta recém-criada tem contrato PENDENTE por
 * padrão, e esse padrão sozinho não é dado para análise (spec §2.1: só cabeçalho não é análise).
 *
 * Tenant explícito em tudo: roda no worker.
 */
final class MontadorDeContextoDaPasta
{
    public const LIMITE_CLIENTES = 10;
    public const LIMITE_MOVIMENTACOES = 25;
    public const LIMITE_METAS = 30;
    public const LIMITE_ANOTACOES = 20;
    public const LIMITE_OBSERVACOES = 15;
    public const LIMITE_DOCUMENTOS = 40;
    public const LIMITE_CHECKLIST = 40;
    public const LIMITE_PAGAMENTOS = 30;
    public const LIMITE_OBSERVACOES_FINANCEIRAS = 10;

    /** Caracteres por campo de texto livre (descrição de meta, anotação, observação). */
    public const TAMANHO_MAXIMO_DA_DESCRICAO = 300;
    public const TAMANHO_MAXIMO_DA_ANOTACAO = 600;
    public const TAMANHO_MAXIMO_DA_OBSERVACAO = 800;

    /** Campos curtos (títulos, nomes, categorias): acima disto é lixo ou ataque. */
    public const TAMANHO_MAXIMO_DO_ROTULO = 200;

    /** Orçamento total em caracteres de todas as seções somadas (o cabeçalho não conta). */
    public const ORCAMENTO_TOTAL = 60000;

    /**
     * Chat da Central cruzando as pastas dos clientes (PR 4c): a pasta aberta e cada pasta adicional usam um orçamento menor
     * que o total, para o pedido inteiro (até 5 pastas) ficar em ~90 mil caracteres.
     */
    public const ORCAMENTO_PASTA_ABERTA_NO_CRUZAMENTO = 30000;
    public const ORCAMENTO_PASTA_ADICIONAL_NO_CRUZAMENTO = 15000;

    public function __construct(
        private readonly FonteDeDadosDaPasta $dados,
        private readonly FonteDeMovimentacoesDoPush $fonteDeMovimentacoes,
        private readonly MontadorDeContextoDoPush $movimentacoes,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly MascaradorDeDadosPessoais $mascarador,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * `$abasOcultas`: as abas ocultas para quem pediu (spec pasta-ocultar-aba §5), decididas na
     * solicitação e gravadas em `contexto_resumo.abasOcultas`, como o financeiro; aqui só se obedece.
     * `$comando`: o comando do drawer que pediu (spec inteligencia-drawer-da-pasta, F1); as seções são
     * as do agente dele, e ele entra no hash e no resumo.
     * `$documentosSelecionados`: os documentos marcados na aba Documentos (F4), já validados na
     * solicitação contra {@see documentosMostrados()} e gravados em `contexto_resumo.documentosSelecionados`;
     * a seção `<documentos>` leva só esses. Nulo = sem recorte (todos os comandos menos o Índice).
     * `$orcamento`: o teto em caracteres das seções somadas; nulo = {@see ORCAMENTO_TOTAL} (os chamadores antigos não mudam).
     *
     * @param list<string>   $abasOcultas
     * @param list<int>|null $documentosSelecionados
     *
     * @throws ContextoBloqueadoException
     */
    public function para(Tenant $tenant, Pasta $pasta, Agente $agente, bool $incluirFinanceiro, array $abasOcultas = [], ?Comando $comando = null, ?array $documentosSelecionados = null, ?int $orcamento = null): ContextoDaPasta
    {
        if ($comando !== null && $comando->agente() !== $agente) {
            throw new \LogicException(sprintf('O comando "%s" é do agente "%s".', $comando->value, $comando->agente()->value));
        }

        // Sigilo primeiro: o montador do Push lança antes de ler qualquer publicação; a lista de
        // processos daqui repete a conferência para o caso de o agente não ler movimentações.
        $processos = $this->processosDaPasta($tenant, $pasta);
        $mascarar = $this->configuracoes->findDoTenant($tenant)?->isMascararDadosPessoais() ?? true;

        $cabecalho = $this->cabecalho($tenant, $pasta, $processos, $mascarar);
        $linhasDeProcessos = array_map(fn (Processo $p): string => $this->linhaDoProcesso($p), $processos);

        $secoes = [];
        $gasto = 0;
        foreach ($agente->secoes() as $secao) {
            if (($secao->exigeVisibilidadeDoFinanceiro() && !$incluirFinanceiro) || \in_array($secao->abaDaPasta()?->value, $abasOcultas, true)) {
                continue; // a decisão foi tomada na solicitação; aqui só se obedece
            }
            $secoes[] = $this->caber($this->montarSecao($secao, $tenant, $pasta, $mascarar, $abasOcultas, $documentosSelecionados, $incluirFinanceiro, $comando?->leConteudoDosArquivos() === true), $gasto, $orcamento ?? self::ORCAMENTO_TOTAL);
        }

        $numeros = array_map(static fn (Processo $p): string => $p->getNumeroProcesso(), $processos);

        return new ContextoDaPasta(
            agente: $agente,
            cabecalho: $cabecalho,
            processos: $linhasDeProcessos,
            secoes: $secoes,
            hash: ContextoDaPasta::hashDe($agente, $cabecalho, $linhasDeProcessos, $secoes, $comando, $documentosSelecionados),
            incluiFinanceiro: $incluirFinanceiro && $agente->leFinanceiro(),
            numerosDosProcessos: $numeros,
            abasOcultas: $abasOcultas,
            comando: $comando,
            documentosSelecionados: $documentosSelecionados,
        );
    }

    /**
     * Os documentos que a aba Documentos do drawer mostra e que podem ser marcados (F4): os
     * {@see LIMITE_DOCUMENTOS} mais recentes da pasta visíveis para quem vê, na MESMA ordem e com o
     * MESMO corte da seção `<documentos>` do contexto. Só metadados; tenant explícito. Com a aba
     * Documentos oculta para quem vê, nenhum (a seção também não entra no contexto).
     *
     * @param list<string> $abasOcultas
     *
     * @return list<PastaDocumento>
     */
    public function documentosMostrados(Tenant $tenant, Pasta $pasta, array $abasOcultas): array
    {
        if (\in_array(SecaoDoContexto::Documentos->abaDaPasta()?->value, $abasOcultas, true)) {
            return [];
        }

        return \array_slice($this->documentosVisiveis($tenant, $pasta, $abasOcultas), 0, self::LIMITE_DOCUMENTOS);
    }

    /**
     * A análise anterior do agente como pode voltar ao provedor (`<analise_anterior>`): mesma regra
     * do Push — neutralizada e mascarada pela preferência atual do escritório (IA-033).
     */
    public function analiseAnterior(Tenant $tenant, ?string $resumo): ?string
    {
        return $this->movimentacoes->analiseAnterior($tenant, $resumo);
    }

    // ---------------------------------------------------------------------------------------
    // Seções
    // ---------------------------------------------------------------------------------------

    /**
     * @param list<string>   $abasOcultas
     * @param list<int>|null $documentosSelecionados
     */
    private function montarSecao(SecaoDoContexto $secao, Tenant $tenant, Pasta $pasta, bool $mascarar, array $abasOcultas, ?array $documentosSelecionados, bool $incluirFinanceiro, bool $conteudoAnexado = false): SecaoDeContexto
    {
        return match ($secao) {
            SecaoDoContexto::Clientes => $this->clientes($tenant, $pasta, $mascarar),
            SecaoDoContexto::Movimentacoes => $this->movimentacoes($tenant, $pasta),
            SecaoDoContexto::Metas => $this->metas($tenant, $pasta, $mascarar),
            SecaoDoContexto::Anotacoes => $this->anotacoes($tenant, $pasta, $mascarar, $incluirFinanceiro),
            SecaoDoContexto::Observacoes => $this->observacoes($tenant, $pasta, $mascarar),
            SecaoDoContexto::Documentos => $this->documentos($tenant, $pasta, $mascarar, $abasOcultas, $documentosSelecionados, $conteudoAnexado),
            SecaoDoContexto::Checklist => $this->checklist($tenant, $pasta, $mascarar),
            SecaoDoContexto::Financeiro => $this->financeiro($tenant, $pasta, $mascarar),
        };
    }

    private function clientes(Tenant $tenant, Pasta $pasta, bool $mascarar): SecaoDeContexto
    {
        $principal = $pasta->getClientePrincipal();
        $linhas = [];
        $assinaturas = [];
        $todos = $this->dados->clientesDaPasta($tenant, $pasta);
        foreach (array_slice($todos, 0, self::LIMITE_CLIENTES) as $cliente) {
            // Só nome e natureza: CPF/CNPJ, e-mail, telefone e endereço NÃO saem (spec §2.1).
            $tipo = match (true) {
                $cliente instanceof ClientePF => 'pessoa física',
                $cliente instanceof ClientePJ => 'pessoa jurídica',
                default => 'cliente',
            };
            $nome = $this->rotulo($cliente->getNomeExibicao(), 'sem nome', $mascarar);
            if ($cliente instanceof ClientePJ && $cliente->getNomeFantasia() !== null && trim($cliente->getNomeFantasia()) !== '') {
                $nome .= ' (' . $this->rotulo($cliente->getNomeFantasia(), '', $mascarar) . ')';
            }
            $ehPrincipal = $principal !== null && $principal === $cliente;
            $linhas[] = sprintf(
                '%s · %s%s [nível %d, cadastro]',
                $nome,
                $tipo,
                $ehPrincipal ? ' · cliente principal' : '',
                SecaoDoContexto::Clientes->nivel(),
            );
            $assinaturas[] = sprintf('cliente:%d:%s:%s:%d', (int) $cliente->getId(), $nome, $tipo, $ehPrincipal ? 1 : 0);
        }

        return new SecaoDeContexto(SecaoDoContexto::Clientes, $linhas, max(0, count($todos) - self::LIMITE_CLIENTES), $assinaturas);
    }

    private function movimentacoes(Tenant $tenant, Pasta $pasta): SecaoDeContexto
    {
        // Já neutralizadas, mascaradas e truncadas pelo montador do Push (mesmo tenant explícito).
        $contexto = $this->movimentacoes->para($tenant, $pasta);
        $linhas = [];
        $assinaturas = [];
        foreach (array_slice($contexto->itens, 0, self::LIMITE_MOVIMENTACOES) as $item) {
            $linhas[] = sprintf('%s [nível %d, %s]', $item->linha(), $item->ehPublicacao() ? 5 : 7, $item->ehPublicacao() ? 'publicação oficial' : 'movimentação oficial');
            $assinaturas[] = $item->chave . ':' . $item->texto; // a mesma base do hash do Push
        }

        return new SecaoDeContexto(SecaoDoContexto::Movimentacoes, $linhas, max(0, count($contexto->itens) - self::LIMITE_MOVIMENTACOES), $assinaturas);
    }

    private function metas(Tenant $tenant, Pasta $pasta, bool $mascarar): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $hoje = $this->relogio->now()->setTime(0, 0);
        $todas = $this->dados->tarefasDaPasta($tenant, $pasta, self::LIMITE_METAS + 1);
        foreach (array_slice($todas, 0, self::LIMITE_METAS) as $tarefa) {
            $responsaveis = [];
            foreach ($tarefa->getResponsaveis() as $responsavel) {
                $responsaveis[] = $this->rotulo($responsavel->getFullName(), 'sem nome', $mascarar);
            }
            $prazo = $tarefa->getPrazo();
            $situacao = Tarefa::STATUS_LABELS[$tarefa->getStatus()] ?? $tarefa->getStatus();
            // Texto relativo (muda todo dia) só no prompt; a assinatura leva a data absoluta.
            if ($prazo !== null && $tarefa->getStatus() !== Tarefa::STATUS_CONCLUIDA) {
                $dias = (int) $hoje->diff($prazo->setTime(0, 0))->format('%r%a');
                $situacao .= $dias < 0
                    ? sprintf(', ATRASADA %d dia(s)', abs($dias))
                    : ($dias === 0 ? ', vence HOJE' : sprintf(', vence em %d dia(s)', $dias));
            }
            $titulo = $this->rotulo($tarefa->getTitulo(), 'sem título', $mascarar);
            $descricao = $this->texto($tarefa->getDescricao(), self::TAMANHO_MAXIMO_DA_DESCRICAO, $mascarar);
            $linhas[] = sprintf(
                'prazo %s · "%s" · responsáveis: %s · %s%s [nível %d, registro interno]',
                $prazo?->format('d/m/Y') ?? 'sem prazo',
                $titulo,
                $responsaveis === [] ? 'nenhum' : implode(', ', $responsaveis),
                $situacao,
                $descricao === '' ? '' : ' · ' . $descricao,
                SecaoDoContexto::Metas->nivel(),
            );
            $assinaturas[] = sprintf(
                'meta:%d:%s:%s:%s:%s:%s',
                (int) $tarefa->getId(),
                $prazo?->format('Y-m-d') ?? '-',
                $tarefa->getStatus(),
                $titulo,
                implode(',', $responsaveis),
                $descricao,
            );
        }

        return new SecaoDeContexto(SecaoDoContexto::Metas, $linhas, $this->excedente($todas, self::LIMITE_METAS), $assinaturas);
    }

    /** Sem o financeiro, os cartões de pró-bono e contrato do Registro não entram (regra da aba Financeiro). */
    private function anotacoes(Tenant $tenant, Pasta $pasta, bool $mascarar, bool $incluirFinanceiro): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $todas = $this->dados->anotacoesDaPasta($tenant, $pasta, self::LIMITE_ANOTACOES + 1, $incluirFinanceiro);
        foreach (array_slice($todas, 0, self::LIMITE_ANOTACOES) as $mensagem) {
            $conteudo = $this->texto($mensagem->getConteudo(), self::TAMANHO_MAXIMO_DA_ANOTACAO, $mascarar);
            if ($conteudo === '') {
                continue;
            }
            $autor = $this->rotulo($mensagem->getAutor()?->getFullName(), 'autor não informado', $mascarar);
            $linhas[] = sprintf(
                '%s · %s: %s [nível %d, registro interno]',
                $mensagem->getCriadaEm()->format('d/m/Y H:i'),
                $autor,
                $conteudo,
                SecaoDoContexto::Anotacoes->nivel(),
            );
            $assinaturas[] = sprintf('anotacao:%d:%s:%s:%s', (int) $mensagem->getId(), $mensagem->getCriadaEm()->format('Y-m-d H:i'), $autor, $conteudo);
        }

        return new SecaoDeContexto(SecaoDoContexto::Anotacoes, $linhas, $this->excedente($todas, self::LIMITE_ANOTACOES), $assinaturas);
    }

    private function observacoes(Tenant $tenant, Pasta $pasta, bool $mascarar): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $todas = $this->dados->observacoesDaPasta($tenant, $pasta, self::LIMITE_OBSERVACOES + 1);
        foreach (array_slice($todas, 0, self::LIMITE_OBSERVACOES) as $observacao) {
            $conteudo = $this->texto($observacao->getConteudo(), self::TAMANHO_MAXIMO_DA_OBSERVACAO, $mascarar);
            if ($conteudo === '') {
                continue;
            }
            $autor = $this->rotulo($observacao->getAutor()?->getFullName(), 'autor não informado', $mascarar);
            $linhas[] = sprintf(
                '%s · %s: %s [nível %d, anotação manual]',
                $observacao->getCriadaEm()->format('d/m/Y'),
                $autor,
                $conteudo,
                SecaoDoContexto::Observacoes->nivel(),
            );
            $assinaturas[] = sprintf('observacao:%d:%s:%s:%s', (int) $observacao->getId(), $observacao->getCriadaEm()->format('Y-m-d'), $autor, $conteudo);
        }

        return new SecaoDeContexto(SecaoDoContexto::Observacoes, $linhas, $this->excedente($todas, self::LIMITE_OBSERVACOES), $assinaturas);
    }

    /**
     * Com o Financeiro oculto para quem pediu, o contrato de honorários não entra (regra
     * `VisibilidadeDasAbasDaPasta::documentosVisiveis`, ocultar aba). Com seleção (Índice da aba
     * Documentos, F4), só os marcados entram, buscados pelos ids e sem o corte de recência (a validação
     * da solicitação já os limitou à lista mostrada; um documento novo depois do pedido não tira um
     * marcado), e os não marcados não contam como omitidos por limite.
     *
     * No "Ler N documentos" (PR F, `$conteudoAnexado`), a linha diz que o conteúdo vai anexado à mensagem em vez
     * de "conteúdo não lido"; quem lê e anexa é o {@see LeitorDeDocumentosDaPasta}, não este montador.
     *
     * @param list<string>   $abasOcultas
     * @param list<int>|null $selecionados
     */
    private function documentos(Tenant $tenant, Pasta $pasta, bool $mascarar, array $abasOcultas, ?array $selecionados, bool $conteudoAnexado = false): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $todos = $selecionados === null
            ? $this->documentosVisiveis($tenant, $pasta, $abasOcultas)
            : VisibilidadeDasAbasDaPasta::documentosVisiveis($this->dados->documentosDaPastaPorIds($tenant, $pasta, $selecionados), $abasOcultas);
        foreach (array_slice($todos, 0, self::LIMITE_DOCUMENTOS) as $documento) {
            // Só metadados aqui: o conteúdo do arquivo só vai no "Ler N documentos", como anexo (PR F).
            $categoria = $this->rotulo($documento->getCategoria(), 'sem categoria', $mascarar);
            $titulo = $this->rotulo($documento->getTitulo(), 'sem título', $mascarar);
            $nome = $this->rotulo($documento->getNomeOriginal(), 'sem nome', $mascarar);
            $linhas[] = sprintf(
                '%s · %s · "%s" (arquivo %s) · %s [nível %d, documento juntado]',
                $documento->getCarregadoEm()->format('d/m/Y'),
                $categoria,
                $titulo,
                $nome,
                $conteudoAnexado ? 'conteúdo anexado a esta mensagem' : 'conteúdo não lido',
                SecaoDoContexto::Documentos->nivel(),
            );
            $assinaturas[] = sprintf('documento:%d:%s:%s:%s:%s', (int) $documento->getId(), $documento->getCarregadoEm()->format('Y-m-d'), $categoria, $titulo, $nome);
        }

        $omitidos = $selecionados === null ? $this->excedente($todos, self::LIMITE_DOCUMENTOS) : 0;

        return new SecaoDeContexto(SecaoDoContexto::Documentos, $linhas, $omitidos, $assinaturas);
    }

    /**
     * Os documentos da pasta visíveis para quem pediu, mais recente primeiro, um a mais que o limite
     * (para declarar o corte). Regra única da seção e da lista da aba Documentos.
     *
     * @param list<string> $abasOcultas
     *
     * @return list<PastaDocumento>
     */
    private function documentosVisiveis(Tenant $tenant, Pasta $pasta, array $abasOcultas): array
    {
        return VisibilidadeDasAbasDaPasta::documentosVisiveis($this->dados->documentosDaPasta($tenant, $pasta, self::LIMITE_DOCUMENTOS + 1), $abasOcultas);
    }

    private function checklist(Tenant $tenant, Pasta $pasta, bool $mascarar): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $todos = $this->dados->checklistDaPasta($tenant, $pasta, self::LIMITE_CHECKLIST + 1);
        foreach (array_slice($todos, 0, self::LIMITE_CHECKLIST) as $item) {
            $titulo = $this->rotulo($item->getTitulo(), 'sem título', $mascarar);
            $linhas[] = sprintf(
                '%s %s [nível %d, registro interno]',
                $item->isConcluido() ? '[x] concluído:' : '[ ] pendente:',
                $titulo,
                SecaoDoContexto::Checklist->nivel(),
            );
            $assinaturas[] = sprintf('checklist:%d:%d:%s', (int) $item->getId(), $item->isConcluido() ? 1 : 0, $titulo);
        }

        return new SecaoDeContexto(SecaoDoContexto::Checklist, $linhas, $this->excedente($todos, self::LIMITE_CHECKLIST), $assinaturas);
    }

    /**
     * Êxito e sucumbência previstos: o valor é um % do total contratado, não um R$ fixo
     * (spec pasta-exito-sucumbencia).
     */
    private function valorDoPagamento(PastaPagamento $pagamento, string $valor, bool $mascarar): string
    {
        return $pagamento->getPercentual() !== null
            ? $this->rotulo($pagamento->getPercentual(), '0', $mascarar) . '% do total contratado'
            : 'R$ ' . $valor;
    }

    /** O previsto não está "em aberto": depende do resultado do processo. */
    private function situacaoDoPagamento(PastaPagamento $pagamento): string
    {
        return match (true) {
            $pagamento->getPagoEm() !== null => 'pago em ' . $pagamento->getPagoEm()->format('d/m/Y'),
            $pagamento->estaPrevisto()       => 'PREVISTO, condicionado ao resultado',
            default                          => 'EM ABERTO',
        };
    }

    private function financeiro(Tenant $tenant, Pasta $pasta, bool $mascarar): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $omitidas = 0;

        $pagamentos = $this->dados->pagamentosDaPasta($tenant, $pasta, self::LIMITE_PAGAMENTOS + 1);
        $observacoes = $this->dados->observacoesFinanceirasDaPasta($tenant, $pasta, self::LIMITE_OBSERVACOES_FINANCEIRAS + 1);
        $valorCausa = $pasta->getValorCausa() !== null && $pasta->getValorCausa() !== '' ? $this->rotulo($pasta->getValorCausa(), '', $mascarar) : '';

        // A linha de cadastro só existe quando há dado financeiro de verdade: o contrato nasce
        // PENDENTE por padrão e, sozinho, não é dado para análise (M1 da revisão; spec §2.1).
        if ($valorCausa !== '' || $pasta->isProBono() || $pagamentos !== [] || $observacoes !== []) {
            $contrato = $this->rotulo($pasta->getSituacaoContrato(), 'não informada', $mascarar);
            $linhas[] = sprintf(
                'situação do contrato: %s · pró-bono: %s · valor da causa: %s [nível %d, registro interno]',
                $contrato,
                $pasta->isProBono() ? 'sim' : 'não',
                $valorCausa !== '' ? 'R$ ' . $valorCausa : 'não informado',
                SecaoDoContexto::Financeiro->nivel(),
            );
            $assinaturas[] = sprintf('financeiro:%s:%d:%s', $contrato, $pasta->isProBono() ? 1 : 0, $valorCausa);
        }

        foreach (array_slice($pagamentos, 0, self::LIMITE_PAGAMENTOS) as $pagamento) {
            $descricao = $this->rotulo($pagamento->getDescricao(), 'sem descrição', $mascarar);
            $valor = $this->rotulo($pagamento->getValor(), '0', $mascarar);
            $linhas[] = sprintf(
                'pagamento · %s · %s · vencimento %s · %s [nível %d, registro interno]',
                $descricao,
                $this->valorDoPagamento($pagamento, $valor, $mascarar),
                $pagamento->getVencimento()?->format('d/m/Y') ?? 'nenhum',
                $this->situacaoDoPagamento($pagamento),
                SecaoDoContexto::Financeiro->nivel(),
            );
            $assinaturas[] = sprintf(
                'pagamento:%d:%s:%s:%s:%s',
                (int) $pagamento->getId(),
                $descricao,
                $valor,
                $pagamento->getVencimento()?->format('Y-m-d') ?? '-',
                $pagamento->getPagoEm()?->format('Y-m-d') ?? '-',
            );
        }
        $omitidas += $this->excedente($pagamentos, self::LIMITE_PAGAMENTOS);

        foreach (array_slice($observacoes, 0, self::LIMITE_OBSERVACOES_FINANCEIRAS) as $observacao) {
            $conteudo = $this->texto($observacao->getConteudo(), self::TAMANHO_MAXIMO_DA_OBSERVACAO, $mascarar);
            if ($conteudo === '') {
                continue;
            }
            $autor = $this->rotulo($observacao->getAutor()?->getFullName(), 'autor não informado', $mascarar);
            $linhas[] = sprintf(
                'observação financeira · %s · %s: %s [nível %d, anotação manual]',
                $observacao->getCriadaEm()->format('d/m/Y'),
                $autor,
                $conteudo,
                SecaoDoContexto::Observacoes->nivel(),
            );
            $assinaturas[] = sprintf('obsfin:%d:%s:%s:%s', (int) $observacao->getId(), $observacao->getCriadaEm()->format('Y-m-d'), $autor, $conteudo);
        }
        $omitidas += $this->excedente($observacoes, self::LIMITE_OBSERVACOES_FINANCEIRAS);

        return new SecaoDeContexto(SecaoDoContexto::Financeiro, $linhas, $omitidas, $assinaturas);
    }

    // ---------------------------------------------------------------------------------------
    // Cabeçalho e processos
    // ---------------------------------------------------------------------------------------

    /**
     * @return list<Processo>
     * @throws ContextoBloqueadoException
     */
    private function processosDaPasta(Tenant $tenant, Pasta $pasta): array
    {
        $processos = [];
        foreach ($pasta->getProcessos() as $processo) {
            $tenantDoProcesso = $processo->getTenant();
            $mesmoTenant = $tenantDoProcesso === $tenant
                || ($tenant->getId() !== null && $tenantDoProcesso?->getId() === $tenant->getId());
            if (!$mesmoTenant) {
                continue;
            }

            if ($processo->emSegredoDeJustica()) { // tribunal OU "Sim" manual do cartão (P5b)
                throw new ContextoBloqueadoException($processo->getNumeroProcesso());
            }

            $processos[] = $processo;
        }

        return $processos;
    }

    /**
     * @param list<Processo> $processos
     * @return array<string, string>
     */
    private function cabecalho(Tenant $tenant, Pasta $pasta, array $processos, bool $mascarar): array
    {
        // Mesma fonte da equipe do Push (colaboradores ativos do escritório), tenant explícito.
        $equipe = [];
        foreach ($this->fonteDeMovimentacoes->nomesDaEquipe($tenant) as $nome) {
            $equipe[] = $this->rotulo($nome, 'colaborador', $mascarar);
        }

        return [
            'pasta' => $this->rotulo($pasta->getNup(), 'sem número', $mascarar),
            'situacao' => ['arquivado' => 'arquivada', 'suspenso' => 'suspensa', 'cancelado' => 'cancelada'][$pasta->getSituacao()] ?? 'ativa',
            'prioridade' => $this->rotulo($pasta->getPrioridadeLabel(), 'normal', $mascarar),
            'acao' => $this->rotulo($pasta->getNomeAcao(), 'não informada', $mascarar),
            'abertura' => $pasta->getDataAbertura()->format('d/m/Y'),
            'responsavel' => $this->rotulo($pasta->getResponsavel()?->getFullName(), 'SEM RESPONSÁVEL', $mascarar),
            'equipe' => $equipe === [] ? 'não informada' : implode(', ', $equipe),
        ];
    }

    private function linhaDoProcesso(Processo $processo): string
    {
        return sprintf(
            'processo %s · classe %s · assunto %s · %s · %s · situação %s [nível 7, cadastro]',
            $this->rotulo($processo->getNumeroProcesso(), 'sem número', false),
            $this->rotulo($processo->getClasseProcessual(), 'não informada', false),
            $this->rotulo($processo->getAssuntoProcessual(), 'não informado', false),
            $this->rotulo($processo->getSiglaTribunal(), 'tribunal não informado', false),
            $this->rotulo($processo->getOrgaoJulgador(), 'órgão não informado', false),
            $this->rotulo($processo->getSituacaoProcesso(), 'não informada', false),
        );
    }

    // ---------------------------------------------------------------------------------------
    // Preparo de texto e orçamento
    // ---------------------------------------------------------------------------------------

    /** Campo curto: neutralizado, com teto; vazio vira o rótulo padrão. */
    private function rotulo(?string $valor, string $padrao, bool $mascarar): string
    {
        $valor = NeutralizadorDeConteudo::neutralizar((string) $valor);
        if ($valor === '') {
            return $padrao;
        }
        if (mb_strlen($valor) > self::TAMANHO_MAXIMO_DO_ROTULO) {
            $valor = mb_substr($valor, 0, self::TAMANHO_MAXIMO_DO_ROTULO) . '…';
        }

        return $mascarar ? $this->mascarador->mascarar($valor) : $valor;
    }

    /** Texto livre (pode ser HTML do editor): texto plano, neutralizado, truncado, mascarado. */
    private function texto(?string $valor, int $maximo, bool $mascarar): string
    {
        $texto = self::textoPlano($valor);
        $texto = NeutralizadorDeConteudo::neutralizar($texto);
        if ($texto === '') {
            return '';
        }
        if (mb_strlen($texto) > $maximo) {
            $texto = mb_substr($texto, 0, $maximo) . '…';
        }

        return $mascarar ? $this->mascarador->mascarar($texto) : $texto;
    }

    private static function textoPlano(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $texto = (string) preg_replace('/<\s*(br|p|div|li|tr|h[1-6])\b[^>]*>/i', ' ', $html);
        $texto = strip_tags($texto);

        return html_entity_decode($texto, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /** Quantos itens a fonte devolveu acima do limite (a fonte é consultada com limite + 1). */
    private function excedente(array $itens, int $limite): int
    {
        return max(0, count($itens) - $limite);
    }

    /**
     * Orçamento total: a seção entra inteira enquanto couber; quando estourar, fica só o que
     * cabe e o resto vira `omitidas` — o modelo é avisado do corte.
     */
    private function caber(SecaoDeContexto $secao, int &$gasto, int $orcamento): SecaoDeContexto
    {
        $linhas = [];
        $assinaturas = [];
        $omitidas = $secao->omitidas;
        foreach ($secao->linhas as $indice => $linha) {
            $tamanho = mb_strlen($linha);
            if ($linhas !== [] && $gasto + $tamanho > $orcamento) {
                ++$omitidas;
                continue;
            }
            $linhas[] = $linha;
            $assinaturas[] = $secao->assinaturas[$indice] ?? $linha;
            $gasto += $tamanho;
        }

        return new SecaoDeContexto($secao->secao, $linhas, $omitidas, $assinaturas);
    }
}
