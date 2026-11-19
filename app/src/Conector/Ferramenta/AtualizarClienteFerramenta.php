<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\ServidorDeAutorizacao;

/**
 * `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 11, pedido C):
 *
 *  - {@see AtualizarClienteLote}: `cadastrar` (lista de 1 a 50 clientes; sempre por proposta);
 *  - {@see AtualizarClienteCadastro}: `completar` (campos do cadastro) e `contato` (celular ou e-mail). Campo vazio grava
 *    direto; trocar ou apagar valor preenchido vai por proposta, com os vazios do mesmo pedido na mesma proposta;
 *  - {@see AtualizarClienteVinculos}: `vincular_a_pasta` (com `operacao`: `vincular`, o padrão e direto, ou
 *    `desvincular`, exclusão e por proposta), `principal` e `ligar_documento`.
 *
 * O que vai por proposta só é gravado depois do "sim" da pessoa, pela `confirmar_acao`, no
 * {@see AtualizarClienteConfirmacao}, que reconfere tudo.
 *
 * Regras de todas: escopo `mcp:escrita` (a `ScopePolicy` barra antes; aqui, a segunda camada); a linha da
 * {@see TabelaDeAbaEChave} (módulo `clientes`; com pasta, a chave `expedientes`) pela {@see GuardaDaAcaoDoConector};
 * cliente ou pasta de outro escritório, inexistente ou que a pessoa não vê, e pasta na lixeira: o mesmo "não
 * encontrado"; pasta em segredo de justiça recusa; envio automático ligado recusa a chamada inteira. Toda recusa tem
 * mensagem fixa, sem nenhum valor recebido; a resposta traz ids, nomes e os NOMES dos campos, nunca valores (6.2, regra
 * 4); o resumo da proposta mostra os valores que o assistente mandou, nunca o valor antigo. Pessoa e escritório vêm só
 * da {@see ChamadaDoConector}.
 */
final class AtualizarClienteFerramenta implements FerramentaDoConector
{
    public const NOME = 'atualizar_cliente';

    /** Os contatos desta ferramenta (6.6: celular ou e-mail); o telefone fixo fica na tela. */
    public const CAMPOS_DO_CONTATO = [AtualizarContatoDoClienteUseCase::CAMPO_CELULAR, AtualizarContatoDoClienteUseCase::CAMPO_EMAIL];

    /** As ações e os campos obrigatórios de cada uma, além de `acao` (6.2, regra 10). */
    private const CAMPOS_DA_ACAO = [
        'cadastrar' => ['clientes'],
        'completar' => ['cliente_id', 'campos'],
        'contato' => ['cliente_id', 'campo', 'valor'],
        'vincular_a_pasta' => ['cliente_id', 'pasta_id'],
        'principal' => ['cliente_id', 'pasta_id'],
        'ligar_documento' => ['cliente_id', 'documento_id'],
    ];

    /** Os campos que a ação aceita sem exigir. */
    private const CAMPOS_OPCIONAIS = ['vincular_a_pasta' => ['operacao']];

    /** O que o `vincular_a_pasta` faz: acrescentar (direto) ou tirar (por proposta). */
    private const OPERACOES_DO_VINCULO = ['vincular', 'desvincular'];

    /** As chaves de cada cliente do `cadastrar`. */
    private const CAMPOS_DO_ITEM = ['tipo', 'campos', 'pasta_id'];

    private const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly AtualizarClienteCadastro $cadastro,
        private readonly AtualizarClienteVinculos $vinculos,
        private readonly AtualizarClienteLote $lote,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Atualizar cliente';
    }

    public function descricao(): string
    {
        return 'Muda o cadastro de clientes do escritório. Ações: cadastrar (cria de 1 a 50 clientes de uma vez, cada um com tipo pf ou pj, campos e, se quiser, a pasta '
            . 'a que fica ligado; sempre por proposta); completar (preenche campos do cadastro: mande em campos só as chaves que mudam, a ação ler da ler_clientes '
            . 'diz quais faltam); contato (o celular ou o e-mail); vincular_a_pasta (liga o cliente a uma pasta, e pasta sem cliente faz dele o principal; com '
            . 'operacao desvincular, tira o cliente da pasta, sem apagar o cadastro); principal (marca, entre os clientes da pasta, o que a representa) e '
            . 'ligar_documento (liga ao cadastro um documento de identificação ou comprovante achado numa pasta do cliente, veja ler_clientes, ação documentos_de_identidade). '
            . 'Preencher campo vazio grava na hora. Cadastrar, desvincular e trocar ou apagar um valor já preenchido devolvem uma proposta com o resumo: '
            . 'mostre o resumo à pessoa e só chame confirmar_acao se ela disser sim; nada é gravado antes. No cadastrar, o cliente com CPF ou CNPJ que já é de '
            . 'um cadastro do escritório, o repetido na própria lista e o que a pessoa não pode cadastrar saem da proposta, com o motivo. '
            . 'Cliente que recebe o envio automático do Push não tem o e-mail gravado, não é vinculado nem vira principal pelo conector. '
            . 'Cliente ou pasta de outro escritório, que a pessoa não vê ou na lixeira respondem "não encontrado"; pasta em segredo de justiça não sai pelo conector. '
            . 'A resposta diz quais campos mudaram, nunca os valores.';
    }

    public function esquemaDeEntrada(): array
    {
        $campos = self::esquemaDosCampos();

        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => array_keys(self::CAMPOS_DA_ACAO), 'description' => 'O que fazer.'],
                'clientes' => self::esquemaDoLote($campos),
                'cliente_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em todas as ações, menos cadastrar: o id do cliente (veja ler_clientes).'],
                'campos' => $campos + [
                    'description' => 'Em completar: os campos a preencher ou trocar, pela chave do cadastro. Nascimento em AAAA-MM-DD; estado civil um de '
                        . implode(', ', CamposDoCadastro::ESTADOS_CIVIS) . '; UF com duas letras.',
                ],
                'campo' => ['type' => 'string', 'enum' => self::CAMPOS_DO_CONTATO, 'description' => 'Em contato: qual contato gravar.'],
                'valor' => ['type' => 'string', 'maxLength' => self::TAMANHO_MAXIMO, 'description' => 'Em contato: o celular com DDD ou o e-mail.'],
                'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em vincular_a_pasta e principal: o id da pasta (veja buscar_pastas).'],
                'operacao' => ['type' => 'string', 'enum' => self::OPERACOES_DO_VINCULO, 'description' => 'Em vincular_a_pasta: vincular (o padrão) ou desvincular (por proposta).'],
                'documento_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em ligar_documento: o id do documento da pasta (veja ler_clientes, ação documentos_de_identidade).'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> o objeto `campos`, com as chaves de {@see CamposDoCadastro::ROTULOS} */
    private static function esquemaDosCampos(): array
    {
        $campos = [];
        foreach (array_keys(CamposDoCadastro::ROTULOS) as $chave) {
            $campos[$chave] = ['type' => 'string', 'maxLength' => self::TAMANHO_MAXIMO];
        }

        return ['type' => 'object', 'properties' => $campos, 'additionalProperties' => false, 'minProperties' => 1];
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private static function esquemaDoLote(array $campos): array
    {
        return [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => AtualizarClienteLote::MAXIMO,
            'description' => 'Em cadastrar: os clientes a criar (até ' . AtualizarClienteLote::MAXIMO . ').',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => AtualizarClienteLote::TIPOS, 'description' => 'pf (pessoa física) ou pj (pessoa jurídica).'],
                    'campos' => $campos + ['description' => 'Os campos do cadastro do tipo, pela chave (os mesmos do completar).'],
                    'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'A pasta a que o cliente fica ligado; sem ela, o cadastro nasce sem pasta.'],
                ],
                'required' => ['tipo', 'campos'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        // Segunda camada: a `ScopePolicy` já deu 403 antes do SDK a quem não tem `mcp:escrita` (5.1).
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            throw ErroDaFerramenta::semPermissao();
        }
        $acao = self::acao($argumentos);
        // A entrada inteira é conferida antes da guarda: pedido inválido não depende de permissão.
        $executar = $this->preparar($acao, $argumentos);
        $this->guarda->exigir($chamada, self::NOME, $acao);

        return $executar($chamada);
    }

    /**
     * Confere os campos da ação e devolve o que a executa, ainda sem olhar permissão nem banco.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return \Closure(ChamadaDoConector): RespostaDaFerramenta
     *
     * @throws ErroDaFerramenta
     */
    private function preparar(string $acao, array $argumentos): \Closure
    {
        if ($acao === 'cadastrar') {
            $clientes = self::clientes($argumentos);

            return fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->lote->propor($clientes, $c);
        }
        $clienteId = self::inteiro($argumentos, 'cliente_id');
        if ($acao === 'completar') {
            $campos = self::camposDoCadastro($argumentos);

            return fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->cadastro->completar($clienteId, $campos, $c);
        }
        if ($acao === 'contato') {
            [$campo, $valor] = self::contato($argumentos);

            return fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->cadastro->contato($clienteId, $campo, $valor, $c);
        }
        if ($acao === 'ligar_documento') {
            $documentoId = self::inteiro($argumentos, 'documento_id');

            return fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->ligarDocumento($clienteId, $documentoId, $c);
        }

        return $this->prepararPelaPasta($acao, $clienteId, self::inteiro($argumentos, 'pasta_id'), $argumentos['operacao'] ?? 'vincular');
    }

    /**
     * `principal` e `vincular_a_pasta` (com a `operacao`: vincular ou desvincular).
     *
     * @return \Closure(ChamadaDoConector): RespostaDaFerramenta
     *
     * @throws ErroDaFerramenta
     */
    private function prepararPelaPasta(string $acao, int $clienteId, int $pastaId, mixed $operacao): \Closure
    {
        if ($acao === 'principal') {
            return fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->principal($clienteId, $pastaId, $c);
        }
        if (!\in_array($operacao, self::OPERACOES_DO_VINCULO, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $operacao === 'desvincular'
            ? fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->proporDesvinculo($clienteId, $pastaId, $c)
            : fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->vincularAPasta($clienteId, $pastaId, $c);
    }

    /**
     * A lista do `cadastrar`: de 1 a 50 itens, cada um com `tipo`, `campos` e `pasta_id` opcional.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return list<array{tipo: string, campos: array<string, string>, pasta_id: int|null}>
     *
     * @throws ErroDaFerramenta
     */
    private static function clientes(array $argumentos): array
    {
        $lista = $argumentos['clientes'] ?? null;
        if (!\is_array($lista) || !array_is_list($lista)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if ($lista === [] || \count($lista) > AtualizarClienteLote::MAXIMO) {
            throw ErroDaFerramenta::recusada(AtualizarClienteLote::LISTA_FORA_DO_TAMANHO);
        }

        return array_map(self::clienteDoLote(...), $lista);
    }

    /**
     * @return array{tipo: string, campos: array<string, string>, pasta_id: int|null}
     *
     * @throws ErroDaFerramenta
     */
    private static function clienteDoLote(mixed $item): array
    {
        if (!\is_array($item) || array_diff(array_keys($item), self::CAMPOS_DO_ITEM) !== [] || !\in_array($item['tipo'] ?? null, AtualizarClienteLote::TIPOS, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return [
            'tipo' => (string) $item['tipo'],
            'campos' => self::camposDoCadastro($item),
            'pasta_id' => isset($item['pasta_id']) ? self::inteiro($item, 'pasta_id') : null,
        ];
    }

    /**
     * A ação da lista, com os campos obrigatórios dela e só os opcionais que ela aceita (falta ou sobra: pedido
     * inválido).
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function acao(array $argumentos): string
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao']);
        $esperados = self::CAMPOS_DA_ACAO[$acao];
        $recebidos = array_values(array_diff(array_keys($argumentos), self::CAMPOS_OPCIONAIS[$acao] ?? []));
        sort($esperados);
        sort($recebidos);
        if ($recebidos !== $esperados) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $acao;
    }

    /**
     * Os campos do `completar` e de cada cliente do `cadastrar`: objeto não vazio, só chaves de {@see CamposDoCadastro::ROTULOS}, cada valor um texto de
     * uma linha até o tamanho máximo. O valor nunca entra na mensagem.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private static function camposDoCadastro(array $argumentos): array
    {
        $campos = $argumentos['campos'] ?? null;
        if (!\is_array($campos) || $campos === [] || array_is_list($campos) || array_diff_key($campos, CamposDoCadastro::ROTULOS) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($campos as $valor) {
            if (!self::textoDeUmaLinha($valor)) {
                throw ErroDaFerramenta::filtroInvalido('campos');
            }
        }

        /** @var array<string, string> $campos */
        return $campos;
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{0: string, 1: string} o contato e o valor
     *
     * @throws ErroDaFerramenta
     */
    private static function contato(array $argumentos): array
    {
        $campo = $argumentos['campo'] ?? null;
        if (!\in_array($campo, self::CAMPOS_DO_CONTATO, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $valor = $argumentos['valor'] ?? null;
        if (!self::textoDeUmaLinha($valor)) {
            throw ErroDaFerramenta::filtroInvalido('valor');
        }

        return [(string) $campo, (string) $valor];
    }

    private static function textoDeUmaLinha(mixed $valor): bool
    {
        return \is_string($valor) && mb_check_encoding($valor, 'UTF-8') && mb_strlen($valor) <= self::TAMANHO_MAXIMO
            && preg_match('/[\x00-\x1F\x7F]/', $valor) !== 1;
    }

    /**
     * O inteiro positivo obrigatório do campo (número ou texto só de dígitos); qualquer outra coisa, pedido inválido.
     *
     * @param array<string, mixed> $argumentos
     *
     * @throws ErroDaFerramenta
     */
    private static function inteiro(array $argumentos, string $campo): int
    {
        $valor = $argumentos[$campo] ?? null;
        if (\is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9) {
            $valor = (int) $valor;
        }
        if (!\is_int($valor) || $valor < 1) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $valor;
    }
}
