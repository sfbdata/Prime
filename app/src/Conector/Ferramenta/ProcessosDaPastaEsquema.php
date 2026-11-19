<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Pasta\Entity\TipoDeApenso;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;

/** O esquema de entrada e a descrição da `processos_da_pasta` (os mesmos campos e tamanhos de {@see ProcessosDaPastaEntrada}). */
final class ProcessosDaPastaEsquema
{
    private function __construct()
    {
    }

    public static function descricao(): string
    {
        return 'Grava na aba Processo de uma pasta do escritório (pasta_id é obrigatório). Ações: vincular (operacao: novo, cadastrado, apensado ou desvincular), '
            . 'principal (processo_id), dados_do_cartao (classe, assuntos, jurisdicao, orgao, distribuicao, valor_causa e os sim ou não digital, gratuita, tutela e prioridade; '
            . 'o segredo de justiça nunca muda pelo conector), acao_da_pasta (nome_da_acao), via_administrativa (administrativa verdadeiro ou falso) e '
            . 'nota_tecnica (operacao: criar, editar ou excluir). Trocar um valor que já existe, editar ou excluir nota técnica e desvincular processo não gravam na hora: '
            . 'voltam como proposta, que a pessoa precisa aprovar com confirmar_acao. Vincular não vale para pasta cujo cliente principal recebe o envio automático do Push. '
            . 'Excluir processo ainda não está disponível. Pasta ou processo de outro escritório, na lixeira ou com a aba Processo oculta responde "não encontrado"; '
            . 'pasta ou processo em segredo de justiça não sai pelo conector.';
    }

    /** @return array<string, mixed> */
    public static function esquema(): array
    {
        $texto = static fn (string $campo, string $descricao): array => ['type' => 'string', 'maxLength' => ProcessosDaPastaEntrada::TAMANHOS[$campo], 'description' => $descricao];

        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => ProcessosDaPastaEntrada::ACOES, 'description' => 'O que fazer na aba Processo.'],
                'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id da pasta (veja buscar_pastas).'],
                'operacao' => ['type' => 'string', 'enum' => ['novo', 'cadastrado', 'apensado', 'desvincular', 'criar', 'editar', 'excluir'], 'description' => 'Em vincular: novo, cadastrado, apensado ou desvincular. Em nota_tecnica: criar, editar ou excluir.'],
                'processo_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id do processo (veja ler_processos).'],
                'nota_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id da nota técnica (veja ler_processos, detalhar).'],
                'tipo_apenso' => ['type' => 'string', 'enum' => array_map(static fn (TipoDeApenso $t): string => $t->value, TipoDeApenso::cases()), 'description' => 'Em vincular apensado: o tipo do apenso.'],
                'numero_processo' => $texto('numero_processo', 'Em vincular novo: o número do processo (20 dígitos, padrão CNJ).'),
                'orgao_julgador' => $texto('orgao_julgador', 'Em vincular novo: o órgão julgador.'),
                'classe_processual' => $texto('classe_processual', 'Em vincular novo: a classe processual.'),
                'assunto_processual' => $texto('assunto_processual', 'Em vincular novo: o assunto processual.'),
                'situacao_processo' => ['type' => 'string', 'enum' => CadastrarProcessoPelaPastaUseCase::SITUACOES, 'description' => 'Em vincular novo: a situação (padrão EM_ANDAMENTO).'],
                'instancia' => ['type' => 'string', 'enum' => CadastrarProcessoPelaPastaUseCase::INSTANCIAS, 'description' => 'Em vincular novo: a instância (padrão G1).'],
                'data_distribuicao' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Em vincular novo: a data de distribuição (AAAA-MM-DD).'],
                'classe' => $texto('classe', 'No dados_do_cartao: a classe.'),
                'assuntos' => $texto('assuntos', 'No dados_do_cartao: os assuntos, um por linha.'),
                'jurisdicao' => $texto('jurisdicao', 'No dados_do_cartao: a jurisdição.'),
                'orgao' => $texto('orgao', 'No dados_do_cartao: o órgão julgador.'),
                'distribuicao' => ['type' => 'string', 'maxLength' => 10, 'description' => 'No dados_do_cartao: a distribuição (AAAA-MM-DD). Vazio limpa o campo.'],
                'valor_causa' => $texto('valor_causa', 'No dados_do_cartao: o valor da causa em reais, com vírgula nos centavos (12.860,00).'),
                'digital' => ['type' => 'boolean', 'description' => 'No dados_do_cartao: processo digital.'],
                'gratuita' => ['type' => 'boolean', 'description' => 'No dados_do_cartao: justiça gratuita.'],
                'tutela' => ['type' => 'boolean', 'description' => 'No dados_do_cartao: tutela de urgência.'],
                'prioridade' => ['type' => 'boolean', 'description' => 'No dados_do_cartao: prioridade de tramitação.'],
                'nome_da_acao' => $texto('nome_da_acao', 'Na acao_da_pasta: o nome da ação da pasta. Vazio limpa.'),
                'administrativa' => ['type' => 'boolean', 'description' => 'Na via_administrativa: verdadeiro marca a pasta como administrativa (sem processo judicial).'],
                'conteudo' => $texto('conteudo', 'Na nota_tecnica criar ou editar: o texto da nota, até 5000 caracteres visíveis. Pode mencionar colegas como @[Nome](user:ID).'),
            ],
            'required' => ['acao', 'pasta_id'],
            'additionalProperties' => false,
        ];
    }
}
