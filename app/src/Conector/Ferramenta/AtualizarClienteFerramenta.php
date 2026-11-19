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
 * `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 11, pedido C), rodada 1: só as ações DIRETAS.
 *
 *  - {@see AtualizarClienteCadastro}: `completar` (campos vazios do cadastro) e `contato` (celular ou e-mail vazio);
 *  - {@see AtualizarClienteVinculos}: `vincular_a_pasta`, `principal` e `ligar_documento`.
 *
 * Fora do esquema, como as ações não entregues da `ler_clientes` (pedidas, dão "pedido inválido"): `cadastrar` (sempre
 * por proposta), a troca de valor preenchido (por proposta: nesta rodada recusa sem gravar, em
 * {@see AtualizarClienteCadastro}) e `desvincular` (exclusão, por proposta; o UseCase já existe,
 * `DesvincularClienteDaPastaUseCase`). A rodada 2 traz a proposta e o executor.
 *
 * Regras de todas: escopo `mcp:escrita` (a `ScopePolicy` barra antes; aqui, a segunda camada); a linha da
 * {@see TabelaDeAbaEChave} (módulo `clientes`; com pasta, a chave `expedientes`) pela {@see GuardaDaAcaoDoConector};
 * cliente ou pasta de outro escritório, inexistente ou que a pessoa não vê, e pasta na lixeira: o mesmo "não
 * encontrado"; pasta em segredo de justiça recusa; envio automático ligado recusa a chamada inteira. Toda recusa tem
 * mensagem fixa, sem nenhum valor recebido; a resposta traz ids, nomes e os NOMES dos campos, nunca valores (6.2, regra
 * 4). Pessoa e escritório vêm só da {@see ChamadaDoConector}.
 */
final class AtualizarClienteFerramenta implements FerramentaDoConector
{
    public const NOME = 'atualizar_cliente';

    /** Os contatos desta ferramenta (6.6: celular ou e-mail); o telefone fixo fica na tela. */
    public const CAMPOS_DO_CONTATO = [AtualizarContatoDoClienteUseCase::CAMPO_CELULAR, AtualizarContatoDoClienteUseCase::CAMPO_EMAIL];

    /** As ações desta rodada e os campos de cada uma, além de `acao`; todos obrigatórios (6.2, regra 10). */
    private const CAMPOS_DA_ACAO = [
        'completar' => ['cliente_id', 'campos'],
        'contato' => ['cliente_id', 'campo', 'valor'],
        'vincular_a_pasta' => ['cliente_id', 'pasta_id'],
        'principal' => ['cliente_id', 'pasta_id'],
        'ligar_documento' => ['cliente_id', 'documento_id'],
    ];

    private const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly AtualizarClienteCadastro $cadastro,
        private readonly AtualizarClienteVinculos $vinculos,
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
        return 'Muda o cadastro de um cliente do escritório. Ações: completar (preenche campos vazios do cadastro: mande em campos só as chaves que faltam, '
            . 'que a ação ler da ler_clientes lista); contato (o celular ou o e-mail, quando vazio); vincular_a_pasta (liga o cliente a uma pasta; '
            . 'pasta sem cliente faz dele o principal); principal (marca, entre os clientes da pasta, o que a representa) e ligar_documento '
            . '(liga ao cadastro um documento de identificação ou comprovante achado numa pasta do cliente, veja ler_clientes, ação documentos_de_identidade). '
            . 'Trocar ou apagar um valor já preenchido não sai pelo conector por enquanto: a chamada inteira é recusada e nada é gravado. '
            . 'Cliente que recebe o envio automático do Push não tem o e-mail gravado, não é vinculado nem vira principal pelo conector. '
            . 'Cliente ou pasta de outro escritório, que a pessoa não vê ou na lixeira respondem "não encontrado"; pasta em segredo de justiça não sai pelo conector. '
            . 'A resposta diz quais campos mudaram, nunca os valores.';
    }

    public function esquemaDeEntrada(): array
    {
        $campos = [];
        foreach (array_keys(CamposDoCadastro::ROTULOS) as $chave) {
            $campos[$chave] = ['type' => 'string', 'maxLength' => self::TAMANHO_MAXIMO];
        }

        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => array_keys(self::CAMPOS_DA_ACAO), 'description' => 'O que fazer.'],
                'cliente_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em todas as ações: o id do cliente (veja ler_clientes).'],
                'campos' => [
                    'type' => 'object',
                    'properties' => $campos,
                    'additionalProperties' => false,
                    'minProperties' => 1,
                    'description' => 'Em completar: os campos vazios a preencher, pela chave do cadastro. Nascimento em AAAA-MM-DD; estado civil um de '
                        . implode(', ', CamposDoCadastro::ESTADOS_CIVIS) . '; UF com duas letras.',
                ],
                'campo' => ['type' => 'string', 'enum' => self::CAMPOS_DO_CONTATO, 'description' => 'Em contato: qual contato preencher.'],
                'valor' => ['type' => 'string', 'maxLength' => self::TAMANHO_MAXIMO, 'description' => 'Em contato: o celular com DDD ou o e-mail.'],
                'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em vincular_a_pasta e principal: o id da pasta (veja buscar_pastas).'],
                'documento_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Em ligar_documento: o id do documento da pasta (veja ler_clientes, ação documentos_de_identidade).'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
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
        $pastaId = self::inteiro($argumentos, 'pasta_id');

        return $acao === 'principal'
            ? fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->principal($clienteId, $pastaId, $c)
            : fn (ChamadaDoConector $c): RespostaDaFerramenta => $this->vinculos->vincularAPasta($clienteId, $pastaId, $c);
    }

    /**
     * A ação da lista desta rodada, com exatamente os campos dela (falta ou sobra: pedido inválido).
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
        $recebidos = array_keys($argumentos);
        sort($esperados);
        sort($recebidos);
        if ($recebidos !== $esperados) {
            throw ErroDaFerramenta::entradaInvalida();
        }

        return $acao;
    }

    /**
     * Os campos do `completar`: objeto não vazio, só chaves de {@see CamposDoCadastro::ROTULOS}, cada valor um texto de
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
