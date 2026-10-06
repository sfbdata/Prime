<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Os sete agentes da BlueJus IA na pasta — nomes e papéis do Designer (`02 - EXPEDIENTES 1.2.3`,
 * `AGENTES`, L.5453-5461). Cada agente tem UM pedido canônico (o comando do desenho que melhor
 * corresponde ao papel; `CMDS`/`MOD`, L.5636-5654) e lê um subconjunto das seções da pasta
 * (spec `docs/specs/inteligencia-agentes-da-pasta.md` §2).
 *
 * O valor do case é o segmento da rota (`/pasta/{id}/ia/agentes/{agente}/analises`) e o conteúdo
 * da coluna `agente`.
 */
enum Agente: string
{
    case Gestor = 'gestor';
    case Processual = 'processual';
    case Documental = 'documental';
    case Prazos = 'prazos';
    case Relatorios = 'relatorios';
    case Cliente = 'cliente';
    case Juridico = 'juridico';

    public static function deRota(string $valor): ?self
    {
        return self::tryFrom(mb_strtolower(trim($valor)));
    }

    public function nome(): string
    {
        return match ($this) {
            self::Gestor => 'Agente Gestor',
            self::Processual => 'Agente Processual',
            self::Documental => 'Agente Documental',
            self::Prazos => 'Agente Prazos',
            self::Relatorios => 'Agente Relatórios',
            self::Cliente => 'Agente Cliente',
            self::Juridico => 'Agente Jurídico',
        };
    }

    /** Papel do agente, como o Designer o descreve e como entra no prompt de sistema. */
    public function papel(): string
    {
        return match ($this) {
            self::Gestor => 'Cruza todas as informações da pasta e entrega visão executiva.',
            self::Processual => 'Analisa processo, fase, movimentações e inatividade.',
            self::Documental => 'Lê e interpreta documentos: tipo, data, partes, valores, prazos e providências.',
            self::Prazos => 'Controla prazos, intimações e riscos de perda de prazo.',
            self::Relatorios => 'Produz relatórios executivos e para o cliente.',
            self::Cliente => 'Analisa histórico e relacionamento do cliente com o escritório.',
            self::Juridico => 'Faz análise jurídica técnica para o advogado: questiona, compara, aponta inconsistências, sugere teses e estrutura argumentos.',
        };
    }

    /** Ícone Bootstrap do comando correspondente no desenho. */
    public function icone(): string
    {
        return match ($this) {
            self::Gestor => 'bi-clipboard-data',
            self::Processual => 'bi-hourglass-split',
            self::Documental => 'bi-file-earmark-text',
            self::Prazos => 'bi-alarm',
            self::Relatorios => 'bi-bar-chart-line',
            self::Cliente => 'bi-person-vcard',
            self::Juridico => 'bi-bank',
        };
    }

    /** O pedido que vai na mensagem do usuário — texto do comando do desenho. */
    public function pedido(): string
    {
        return match ($this) {
            self::Gestor => 'Analise esta pasta por completo: resumo executivo, cliente, responsável, processo, fase, última movimentação, prazos, tarefas pendentes, riscos, pendências documentais e prioridades.',
            self::Processual => 'Avalie se o processo está parado considerando a fase, os prazos e as providências pendentes, sem usar apenas o número de dias. Monte a linha do tempo do caso com as datas disponíveis e diga o que está vigente, a última e a próxima providência.',
            self::Documental => 'Monte um índice inteligente dos documentos da pasta com: documento, data, tipo, relevância, processo relacionado, providência. Indique documentos faltantes ou duplicados, se houver base no checklist e nos metadados. O conteúdo dos arquivos não foi lido: não descreva o que está dentro deles.',
            self::Prazos => 'Verifique os prazos desta pasta, prazos vencidos e risco de perda de prazo. Separe prazo processual (intimação, publicação) de prazo interno (meta) e diga o que precisa de providência hoje.',
            self::Relatorios => 'Gere o "Relatório da pasta": situação, processo, prazos e providências, em linguagem executiva para o advogado responsável.',
            self::Cliente => 'Analise o histórico de registros e atendimento desta pasta: o que foi combinado, o que ficou pendente, a frequência de contato e o que o escritório deve ao cliente ou cobrar dele.',
            self::Juridico => 'Faça a análise jurídica profunda: 1 contexto, 2 fatos relevantes, 3 questão jurídica, 4 documentos relevantes, 5 fase processual, 6 fundamentos jurídicos identificados, 7 argumentos favoráveis, 8 argumentos contrários, 9 pontos de atenção, 10 riscos, 11 providências possíveis, 12 documentos necessários, 13 próximas tarefas. Separe FATO ENCONTRADO NOS DADOS, INTERPRETAÇÃO DA IA, ARGUMENTO JURÍDICO e SUGESTÃO. Não cite jurisprudência ou artigo que não esteja nos dados; quando precisar, diga quais informações faltam.',
        };
    }

    /**
     * Seções da pasta que este agente lê, na ordem em que entram no prompt. `<pasta>` e
     * `<processos_vinculados>` vão sempre, para todos.
     *
     * @return list<SecaoDoContexto>
     */
    public function secoes(): array
    {
        return match ($this) {
            self::Gestor => [
                SecaoDoContexto::Clientes,
                SecaoDoContexto::Movimentacoes,
                SecaoDoContexto::Metas,
                SecaoDoContexto::Anotacoes,
                SecaoDoContexto::Observacoes,
                SecaoDoContexto::Documentos,
                SecaoDoContexto::Checklist,
                SecaoDoContexto::Financeiro,
            ],
            self::Processual => [SecaoDoContexto::Movimentacoes, SecaoDoContexto::Metas],
            self::Documental => [SecaoDoContexto::Documentos, SecaoDoContexto::Checklist],
            self::Prazos => [SecaoDoContexto::Movimentacoes, SecaoDoContexto::Metas],
            self::Relatorios => [
                SecaoDoContexto::Clientes,
                SecaoDoContexto::Movimentacoes,
                SecaoDoContexto::Metas,
                SecaoDoContexto::Observacoes,
                SecaoDoContexto::Financeiro,
            ],
            self::Cliente => [
                SecaoDoContexto::Clientes,
                SecaoDoContexto::Anotacoes,
                SecaoDoContexto::Observacoes,
                SecaoDoContexto::Metas,
                SecaoDoContexto::Financeiro,
            ],
            self::Juridico => [
                SecaoDoContexto::Movimentacoes,
                SecaoDoContexto::Documentos,
                SecaoDoContexto::Metas,
                SecaoDoContexto::Observacoes,
            ],
        };
    }

    public function leFinanceiro(): bool
    {
        return in_array(SecaoDoContexto::Financeiro, $this->secoes(), true);
    }
}
