<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

use App\Pasta\Entity\AbaDaPasta;

/**
 * As seções de dados da pasta que um {@see Agente} pode ler — cada uma vira um bloco delimitado do
 * prompt (`<clientes>`, `<metas>`, …) e uma contagem em `contexto_resumo`. O `nivel()` é a posição
 * na hierarquia de fontes do Designer (`IA_REGRAS`: menor N vale mais): movimentação oficial 5-7,
 * cadastro 7, documento juntado 8, registro interno 9, anotação/atendimento 10.
 *
 * `Financeiro` é a única seção condicionada a permissão (spec fatia 2 §2): o montador só a monta se
 * a solicitação disse que a pessoa pode ver o financeiro da pasta.
 */
enum SecaoDoContexto: string
{
    case Clientes = 'clientes';
    case Movimentacoes = 'movimentacoes';
    case Metas = 'metas';
    case Anotacoes = 'anotacoes';
    case Observacoes = 'observacoes';
    case Documentos = 'documentos';
    case Checklist = 'checklist';
    case Financeiro = 'financeiro';

    /** Título da seção como vai no prompt (antes do bloco). */
    public function titulo(): string
    {
        return match ($this) {
            self::Clientes => 'CLIENTES DA PASTA (cadastro; documentos e contatos não são enviados)',
            self::Movimentacoes => 'MOVIMENTAÇÕES OFICIAIS (push processual, mais recente primeiro)',
            self::Metas => 'METAS (tarefas da pasta; abertas primeiro)',
            self::Anotacoes => 'ANOTAÇÕES INTERNAS (registros da aba Dados, mais recente primeiro)',
            self::Observacoes => 'OBSERVAÇÕES (aba Detalhes, mais recente primeiro)',
            self::Documentos => 'DOCUMENTOS JUNTADOS (só metadados; o conteúdo dos arquivos NÃO foi lido)',
            self::Checklist => 'CHECKLIST DE DOCUMENTAÇÃO',
            self::Financeiro => 'FINANCEIRO (contrato, valor da causa, pagamentos e observações financeiras)',
        };
    }

    /** Nome da tag delimitadora do bloco no prompt. */
    public function tag(): string
    {
        return $this->value;
    }

    /** Como a tela diz ao usuário o que o agente lê (transparência sobre o que sai do escritório). */
    public function rotuloCurto(): string
    {
        return match ($this) {
            self::Clientes => 'clientes (sem documentos nem contatos)',
            self::Movimentacoes => 'movimentações do Push',
            self::Metas => 'metas',
            self::Anotacoes => 'anotações',
            self::Observacoes => 'observações',
            self::Documentos => 'documentos (só nomes e datas)',
            self::Checklist => 'checklist',
            self::Financeiro => 'financeiro',
        };
    }

    /** Nível na hierarquia de fontes do Designer. Movimentações variam por item (5 publicação, 7 Datajud). */
    public function nivel(): int
    {
        return match ($this) {
            self::Movimentacoes => 7,
            self::Clientes => 7,
            self::Documentos => 8,
            self::Metas, self::Checklist, self::Financeiro => 9,
            self::Anotacoes => 9,
            self::Observacoes => 10,
        };
    }

    /**
     * A aba da pasta de onde vem a seção (spec pasta-ocultar-aba §5, desenho L.4218): com ela oculta
     * para quem pediu a análise, a seção não entra. Clientes é do cadastro (cabeçalho), de aba nenhuma.
     */
    public function abaDaPasta(): ?AbaDaPasta
    {
        return match ($this) {
            self::Clientes => null,
            self::Movimentacoes => AbaDaPasta::Push,
            self::Metas => AbaDaPasta::Tarefas,
            self::Anotacoes => AbaDaPasta::Dados,
            self::Observacoes => AbaDaPasta::Detalhes,
            self::Documentos, self::Checklist => AbaDaPasta::Documentos,
            self::Financeiro => AbaDaPasta::Financeiro,
        };
    }

    public function exigeVisibilidadeDoFinanceiro(): bool
    {
        return $this === self::Financeiro;
    }
}
