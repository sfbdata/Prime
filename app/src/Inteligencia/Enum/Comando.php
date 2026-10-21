<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Os comandos e relatórios do drawer BlueJus IA da pasta (desenho `02 - EXPEDIENTES - VS 18.10.26`,
 * `CMDS` L5894-5905 e `MOD` L5906-5913; spec `docs/specs/inteligencia-drawer-da-pasta.md`, F1 e F2).
 * Cada um é UM pedido ao modelo, feito pelo agente que o desenho indica, com o contexto montado no
 * servidor ({@see \App\Inteligencia\Contexto\MontadorDeContextoDaPasta}, seções do agente).
 *
 * Rótulos, ícones e pedidos são os do desenho, com dois ajustes pelas regras do domínio
 * (`app/src/Inteligencia/CLAUDE.md`, regras 5 e 7): o conteúdo dos documentos não é lido, então
 * nenhum pedido afirma lê-lo (o Raio-X e a Análise jurídica dizem que não foi lido, e a Jurídica
 * separa "FATO ENCONTRADO NOS DADOS", não "NO DOCUMENTO"); e nenhum pedido carrega dado da pasta
 * (os dados vão nos blocos delimitados do prompt, nunca no pedido).
 *
 * Fora daqui: o "Relatório do cliente" do desenho (todas as pastas do cliente) espera a decisão D12.
 *
 * O valor do case é o segmento da rota (`/pasta/{id}/ia/comandos/{comando}/analises`) e o conteúdo
 * da coluna `comando` (até 30 caracteres).
 */
enum Comando: string
{
    // Comandos para esta pasta (aba Análise)
    case AnalisarPasta = 'analisar_pasta';
    case IdentificarRiscos = 'identificar_riscos';
    case VerificarPrazos = 'verificar_prazos';
    case ProcessoParado = 'processo_parado';
    case ProximasProvidencias = 'proximas_providencias';
    case Briefing = 'briefing';
    case LinhaDoTempo = 'linha_do_tempo';
    case AnalisarHistorico = 'analisar_historico';
    case RaioX = 'raio_x';
    case AnaliseJuridica = 'analise_juridica';

    // Relatórios (aba Relatórios)
    case RelatorioPasta = 'relatorio_pasta';
    case ResumoCliente = 'resumo_cliente';
    case RelatorioPrazos = 'relatorio_prazos';
    case RelatorioRisco = 'relatorio_risco';
    case RelatorioLinhaDoTempo = 'relatorio_linha_do_tempo';

    /** Frase que a Jurídica e o Raio-X acrescentam: o que está nos arquivos não chega ao modelo. */
    private const SEM_CONTEUDO_DE_ARQUIVO = 'O conteúdo dos arquivos não foi lido: use só os metadados dos documentos e não descreva o que está dentro deles.';

    public static function deRota(string $valor): ?self
    {
        return self::tryFrom(mb_strtolower(trim($valor)));
    }

    /** @return list<self> os 10 comandos da aba Análise, na ordem do desenho */
    public static function comandos(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => !$c->ehRelatorio()));
    }

    /** @return list<self> os 5 relatórios da aba Relatórios, na ordem do desenho */
    public static function relatorios(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->ehRelatorio()));
    }

    /**
     * Onde a tela mostra uma análise antiga, de antes dos comandos (`comando` nulo): no comando que
     * herdou o pedido canônico do agente. O Documental (índice dos documentos pelos metadados) não
     * tem comando próprio na aba Análise; o mais próximo é o Raio-X, que verifica os documentos.
     */
    public static function doAgenteLegado(Agente $agente): self
    {
        return match ($agente) {
            Agente::Gestor => self::AnalisarPasta,
            Agente::Processual => self::ProcessoParado,
            Agente::Documental => self::RaioX,
            Agente::Prazos => self::VerificarPrazos,
            Agente::Relatorios => self::RelatorioPasta,
            Agente::Cliente => self::AnalisarHistorico,
            Agente::Juridico => self::AnaliseJuridica,
        };
    }

    /** @return list<Agente> os agentes cujas análises antigas aparecem neste comando */
    public function agentesLegados(): array
    {
        return array_values(array_filter(Agente::cases(), fn (Agente $a): bool => self::doAgenteLegado($a) === $this));
    }

    public function ehRelatorio(): bool
    {
        return match ($this) {
            self::RelatorioPasta, self::ResumoCliente, self::RelatorioPrazos, self::RelatorioRisco, self::RelatorioLinhaDoTempo => true,
            default => false,
        };
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::AnalisarPasta => 'Analisar esta pasta',
            self::IdentificarRiscos => 'Identificar riscos',
            self::VerificarPrazos => 'Verificar prazos',
            self::ProcessoParado => 'Processo parado?',
            self::ProximasProvidencias => 'Próximas providências',
            self::Briefing => 'Briefing para o advogado',
            self::LinhaDoTempo => 'Linha do tempo',
            self::AnalisarHistorico => 'Analisar histórico',
            self::RaioX => 'Raio-X jurídico',
            self::AnaliseJuridica => 'Análise jurídica profunda',
            self::RelatorioPasta => 'Relatório da pasta',
            self::ResumoCliente => 'Resumo para o cliente',
            self::RelatorioPrazos => 'Relatório de prazos',
            self::RelatorioRisco => 'Relatório de risco',
            self::RelatorioLinhaDoTempo => 'Linha do tempo',
        };
    }

    /** Descrição do relatório no cartão do desenho (`MOD`); os comandos não têm. */
    public function descricao(): ?string
    {
        return match ($this) {
            self::RelatorioPasta => 'Situação, processo, prazos e providências',
            self::ResumoCliente => 'Linguagem simples, pronto para enviar',
            self::RelatorioPrazos => 'O que vence e quem é responsável',
            self::RelatorioRisco => 'Pontos críticos e prioridades',
            self::RelatorioLinhaDoTempo => 'Fatos e registros em ordem',
            default => null,
        };
    }

    /** Ícone Bootstrap do desenho (sem o prefixo `bi `, como `Agente::icone`). */
    public function icone(): string
    {
        return match ($this) {
            self::AnalisarPasta => 'bi-clipboard-data',
            self::IdentificarRiscos => 'bi-shield-exclamation',
            self::VerificarPrazos => 'bi-alarm',
            self::ProcessoParado => 'bi-hourglass-split',
            self::ProximasProvidencias => 'bi-signpost-2',
            self::Briefing => 'bi-person-video3',
            self::LinhaDoTempo => 'bi-clock-history',
            self::AnalisarHistorico => 'bi-journal-text',
            self::RaioX => 'bi-upc-scan',
            self::AnaliseJuridica => 'bi-bank',
            self::RelatorioPasta => 'bi-journal-text',
            self::ResumoCliente => 'bi-chat-square-text',
            self::RelatorioPrazos => 'bi-alarm',
            self::RelatorioRisco => 'bi-shield-exclamation',
            self::RelatorioLinhaDoTempo => 'bi-clock-history',
        };
    }

    /** O agente que o desenho põe neste comando: define o papel no prompt e as seções lidas. */
    public function agente(): Agente
    {
        return match ($this) {
            self::AnalisarPasta, self::IdentificarRiscos, self::ProximasProvidencias, self::RelatorioRisco => Agente::Gestor,
            self::VerificarPrazos, self::RelatorioPrazos => Agente::Prazos,
            self::ProcessoParado, self::LinhaDoTempo, self::RaioX, self::RelatorioLinhaDoTempo => Agente::Processual,
            self::Briefing, self::RelatorioPasta, self::ResumoCliente => Agente::Relatorios,
            self::AnalisarHistorico => Agente::Cliente,
            self::AnaliseJuridica => Agente::Juridico,
        };
    }

    /** O pedido que vai na mensagem do usuário: o texto do desenho, com os ajustes do cabeçalho. */
    public function pedido(): string
    {
        if ($this->ehRelatorio()) {
            $pedido = sprintf('Gere o "%s" (%s).', $this->rotulo(), (string) $this->descricao());

            return $this === self::ResumoCliente
                ? $pedido . ' Escreva para o cliente leigo, cordial, em até 8 frases, sem termos técnicos.'
                : $pedido;
        }

        return match ($this) {
            self::AnalisarPasta => 'Analise esta pasta por completo: resumo executivo, cliente, responsável, processo, fase, última movimentação, prazos, tarefas pendentes, riscos, pendências documentais e prioridades.',
            self::IdentificarRiscos => 'Identifique os riscos operacionais e jurídicos desta pasta e explique por que cada um foi destacado.',
            self::VerificarPrazos => 'Verifique os prazos desta pasta, prazos vencidos e risco de perda de prazo.',
            self::ProcessoParado => 'Avalie se o processo está parado considerando a fase, os prazos e as providências pendentes, sem usar apenas o número de dias.',
            self::ProximasProvidencias => 'Liste as próximas providências em ordem de prioridade, explicando o motivo de cada prioridade.',
            self::Briefing => 'Prepare um briefing curto para o advogado responsável conversar com o cliente.',
            self::LinhaDoTempo => 'Monte a linha do tempo do caso com as datas disponíveis.',
            self::AnalisarHistorico => 'Analise o histórico de registros e atendimento desta pasta.',
            self::RaioX => 'Faça o raio-X jurídico: verifique documentos, prazos, decisões, manifestações, movimentações, tarefas, cadastro e histórico, classificando cada item como OK, ATENÇÃO, PENDÊNCIA, INCONSISTÊNCIA ou URGENTE. ' . self::SEM_CONTEUDO_DE_ARQUIVO,
            self::AnaliseJuridica => 'Faça a análise jurídica profunda: 1 contexto, 2 fatos relevantes, 3 questão jurídica, 4 documentos relevantes, 5 fase processual, 6 fundamentos jurídicos identificados, 7 argumentos favoráveis, 8 argumentos contrários, 9 pontos de atenção, 10 riscos, 11 providências possíveis, 12 documentos necessários, 13 próximas tarefas. Separe FATO ENCONTRADO NOS DADOS, INTERPRETAÇÃO DA IA, ARGUMENTO JURÍDICO e SUGESTÃO. Não cite jurisprudência ou artigo que não esteja nos dados; quando precisar, diga quais informações faltam. ' . self::SEM_CONTEUDO_DE_ARQUIVO,
            default => throw new \LogicException('Relatório sem pedido.'),
        };
    }

    /** Texto do botão do relatório no desenho: "Gerar " + nome em minúsculas. */
    public function rotuloDoBotao(): string
    {
        return 'Gerar ' . mb_strtolower($this->rotulo());
    }
}
