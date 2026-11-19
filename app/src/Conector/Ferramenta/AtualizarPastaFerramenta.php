<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 10): o que se muda na pasta fora do andamento, dos
 * registros, dos prazos, dos documentos e do financeiro. A entrada é plana (6.2, regra 10): `acao` numa lista fechada e
 * só os campos da ação ({@see AtualizarPastaEntrada}). Cada grupo de ações mora numa classe `AtualizarPasta*`:
 *
 *  - {@see AtualizarPastaCiclo}: `criar`, `duplicar`, `restaurar`;
 *  - {@see AtualizarPastaDados}: `editar_dados`;
 *  - {@see AtualizarPastaCabecalho}: `situacao`, `responsavel`, `prioridade`, `carteira`;
 *  - {@see AtualizarPastaPreferencias}: `favoritar`, `acompanhar`, `destacar`;
 *  - {@see AtualizarPastaMarcadores}: `marcadores_da_pasta`, `marcador`;
 *  - {@see AtualizarPastaCadastro}: `nome_do_cliente`, `cadastro_da_pasta`, `reu`;
 *  - {@see AtualizarPastaAvisos}: `pedir_acesso`, `enviar_relatorio_sem_processo`;
 *  - {@see AtualizarPastaExclusao}: `excluir` (pasta, marcador ou réu), sempre por proposta.
 *
 * Regras de todas (abertura da 6.6 e 6.7): preencher, acrescentar, marcar e escolher numa lista fechada gravam direto;
 * excluir e gravar por cima devolvem proposta, e só a `confirmar_acao` grava. Pasta de outro escritório, inexistente, na
 * lixeira (menos no `restaurar`) ou que a pessoa não vê é "não encontrado"; pasta em segredo de justiça recusa. Toda
 * recusa tem mensagem fixa. Pessoa e escritório vêm só da {@see ChamadaDoConector}.
 */
// Não implementa o ExecutorDeProposta: as duas interfaces declaram `TAG` e o PHP recusa a classe. O
// AtualizarPastaExecutor é o executor e repassa para o reconferir() e o executarConfirmada() daqui.
final class AtualizarPastaFerramenta implements FerramentaDoConector
{
    public const NOME = 'atualizar_pasta';

    public const NADA_A_MUDAR = 'Nada a mudar: a pasta já está assim.';
    public const MUDOU = 'A pasta mudou desde a proposta. Peça de novo.';

    /** O que cada campo é, para o modelo; um campo só, com o sentido de cada ação que o usa. */
    private const DESCRICOES = [
        'acao' => 'O que fazer.',
        'pasta_id' => 'O id da pasta (veja buscar_pastas). Em todas as ações menos criar e marcador; no excluir, com alvo pasta ou reu.',
        'nome_cliente' => 'Em criar e editar_dados: o identificador da pasta (o nome do cliente, em maiúsculas).',
        'nome_acao' => 'Em criar: o nome da ação da pasta.',
        'nup' => 'Em editar_dados: o número da pasta.',
        'situacao' => 'Em situacao: a situação nova da pasta.',
        'responsavel_id' => 'Em responsavel: o id do colega ativo do escritório (veja quem_sou_eu); null tira o responsável.',
        'prioridade' => 'Em prioridade: a prioridade nova.',
        'carteira' => 'Em carteira: a carteira nova.',
        'ligado' => 'Em favoritar e acompanhar: verdadeiro liga, falso desliga.',
        'destaque' => 'Em destacar: a cor da linha da pasta na Lista; "nenhum" tira a cor.',
        'marcadores' => 'Em marcadores_da_pasta: os ids dos marcadores que a pasta deve ter (a lista inteira, até 50).',
        'operacao' => 'Em marcador: criar, editar ou reordenar. Em reu: adicionar ou subir (uma posição na ordem; o primeiro é o principal).',
        'marcador_id' => 'Em marcador (editar, reordenar) e no excluir com alvo marcador: o id do marcador do escritório.',
        'nome' => 'Em marcador (criar, editar): o nome do marcador, até 100 letras. Em nome_do_cliente: o nome novo. Em reu adicionar: o nome do réu.',
        'cor' => 'Em marcador (criar, editar): a cor, no formato #rrggbb, uma das cores da tela.',
        'descricao' => 'Em marcador (criar, editar): a descrição; vazia apaga.',
        'pai_id' => 'Em marcador criar: o id do marcador principal, para criar um submarcador.',
        'alvo_id' => 'Em marcador reordenar: o id do marcador ao lado do qual o marcador vai.',
        'depois' => 'Em marcador reordenar: verdadeiro põe depois do alvo; falso, antes.',
        'nome_do_reu' => 'Em cadastro_da_pasta: o nome do réu principal.',
        'cpf' => 'Em cadastro_da_pasta: o CPF ou CNPJ do réu principal.',
        'qualificacao' => 'Em cadastro_da_pasta: a qualificação do réu principal.',
        'email' => 'Em cadastro_da_pasta: o e-mail do réu principal.',
        'endereco' => 'Em cadastro_da_pasta: o endereço do réu principal.',
        'contrato' => 'Em cadastro_da_pasta: o contrato.',
        'unidade' => 'Em cadastro_da_pasta: a unidade.',
        'titular_extrato' => 'Em cadastro_da_pasta: o titular do extrato.',
        'prescricao' => 'Em cadastro_da_pasta: a data-limite de prescrição, AAAA-MM-DD; vazia apaga.',
        'reu_id' => 'Em reu subir e no excluir com alvo reu: o id do réu da pasta (veja ler_pasta).',
        'documento' => 'Em reu adicionar: o CPF ou CNPJ do réu (opcional).',
        'nivel' => 'Em pedir_acesso: visualizar ou editar (padrão editar).',
        'motivo' => 'Em pedir_acesso: o motivo do pedido, que vai aos primários.',
        'alvo' => 'Em excluir: o que excluir. pasta (vai para a lixeira, pasta_id), marcador (sai de todas as pastas, marcador_id) ou reu (pasta_id e reu_id).',
    ];

    private const INTEIROS = ['pasta_id', 'marcador_id', 'pai_id', 'alvo_id', 'reu_id'];
    private const BOOLEANOS = ['ligado', 'depois'];

    /** @var list<AtualizarPastaGrupo> */
    private readonly array $grupos;

    public function __construct(
        AtualizarPastaCiclo $ciclo,
        AtualizarPastaDados $dados,
        AtualizarPastaCabecalho $cabecalho,
        AtualizarPastaPreferencias $preferencias,
        AtualizarPastaMarcadores $marcadores,
        AtualizarPastaCadastro $cadastro,
        AtualizarPastaAvisos $avisos,
        AtualizarPastaExclusao $exclusao,
    ) {
        $this->grupos = [$ciclo, $dados, $cabecalho, $preferencias, $marcadores, $cadastro, $avisos, $exclusao];
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Atualizar a pasta';
    }

    public function descricao(): string
    {
        return 'Muda uma pasta do escritório. Ações: criar; editar_dados (número e identificador); situacao; responsavel; prioridade; carteira; '
            . 'duplicar; restaurar (da lixeira); favoritar, acompanhar e destacar (só para a pessoa); marcadores_da_pasta (a lista inteira de marcadores da pasta); '
            . 'marcador (criar, editar ou reordenar um marcador do escritório); nome_do_cliente; cadastro_da_pasta (o réu principal e os dados da Controladoria); '
            . 'reu (adicionar ou subir na ordem); pedir_acesso (pasta com cadeado); enviar_relatorio_sem_processo; excluir (pasta, marcador ou réu). '
            . 'Mande só os campos da ação. Preencher, acrescentar, marcar e escolher numa lista acontecem na hora. Excluir, tirar marcador da pasta, '
            . 'trocar um valor já preenchido e editar marcador ficam esperando o sim da pessoa: mostre o resumo e chame confirmar_acao só se ela disser sim. '
            . 'Pasta de outro escritório, na lixeira ou que a pessoa não vê responde "não encontrado"; pasta em segredo de justiça não sai pelo conector.';
    }

    public function esquemaDeEntrada(): array
    {
        $propriedades = ['acao' => ['type' => 'string', 'enum' => array_keys(AtualizarPastaEntrada::CAMPOS), 'description' => self::DESCRICOES['acao']]];
        foreach (self::camposDoEsquema() as $campo) {
            $propriedades[$campo] = self::propriedade($campo) + ['description' => self::DESCRICOES[$campo]];
        }

        return [
            'type' => 'object',
            'properties' => $propriedades,
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $entrada = AtualizarPastaEntrada::de($argumentos);

        return $this->grupo($entrada->acao)->executar($entrada, $chamada);
    }

    /**
     * O que o {@see AtualizarPastaExecutor} repassa: confere de novo, sem gravar.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->grupo($acao)->reconferir($acao, $dados, $chamada);
    }

    /**
     * O que o {@see AtualizarPastaExecutor} repassa: grava a proposta confirmada, dentro da transação da confirmação.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        return $this->grupo($acao)->executarConfirmada($acao, $dados, $chamada);
    }

    /** @throws ErroDaFerramenta */
    private function grupo(string $acao): AtualizarPastaGrupo
    {
        foreach ($this->grupos as $grupo) {
            if (\in_array($acao, $grupo->acoes(), true)) {
                return $grupo;
            }
        }

        throw ErroDaFerramenta::entradaInvalida();
    }

    /** @return list<string> todos os campos das ações, sem repetir, na ordem da tabela */
    private static function camposDoEsquema(): array
    {
        return array_values(array_unique(array_merge(...array_values(AtualizarPastaEntrada::CAMPOS))));
    }

    /** @return array<string, mixed> */
    private static function propriedade(string $campo): array
    {
        if (\in_array($campo, self::INTEIROS, true)) {
            return ['type' => 'integer', 'minimum' => 1];
        }
        if ($campo === 'responsavel_id') {
            return ['type' => ['integer', 'null'], 'minimum' => 1];
        }
        if (\in_array($campo, self::BOOLEANOS, true)) {
            return ['type' => 'boolean'];
        }
        if ($campo === 'marcadores') {
            return ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => AtualizarPastaEntrada::LOTE];
        }
        $opcoes = $campo === 'operacao' ? AtualizarPastaEntrada::todasAsOperacoes() : AtualizarPastaEntrada::opcoes('', $campo);
        if ($opcoes !== null) {
            return ['type' => 'string', 'enum' => $opcoes];
        }

        return ['type' => 'string', 'maxLength' => AtualizarPastaEntrada::TAMANHOS[$campo] ?? 255];
    }
}
