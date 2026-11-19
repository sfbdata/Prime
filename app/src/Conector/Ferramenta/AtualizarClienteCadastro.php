<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\DTO\AtualizarContatoDoClienteInput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Exception\DocumentoJaCadastradoException;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Shared\Service\DigitoVerificador;

/**
 * `completar` e `contato` da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6), com o recurso `cliente` de
 * editar e sem pasta:
 *
 *  - `completar` (`cliente_id`, `campos` com chaves de {@see CamposDoCadastro::ROTULOS} do tipo do cliente): grava
 *    pelo {@see SalvarCadastroDoClienteUseCase}. Valor igual ao que já está (sem olhar maiúsculas, espaços nem a
 *    pontuação de documento e telefone) não muda nada;
 *  - `contato` (`campo` celular ou email, `valor`): pelo {@see AtualizarContatoDoClienteUseCase}, com a mesma regra.
 *
 * Preencher campo vazio grava direto. Trocar ou apagar um valor já preenchido é gravar por cima (abertura da 6.6) e
 * vai por proposta ({@see proporTroca()}): a chamada que troca qualquer campo vira UMA proposta com todos os campos que
 * mudam, os vazios junto (item "Lote"), e o resumo diz o que é novo e o que é troca, com os valores que o assistente
 * mandou e nunca o valor antigo. Os valores são conferidos antes de gravar direto e ao propor (num cadastro em branco
 * que não vai ao banco, {@see AtualizarClienteAcesso::conferirCampos()}), então a recusa nunca deixa o cliente
 * gerenciado sujo, e de novo na confirmação ({@see reconferirTroca()}), com a
 * permissão, o CPF que virou repetido e o envio automático. O e-mail de cliente com o envio automático ligado recusa
 * sempre, antes de propor. A resposta traz o id, o nome e os NOMES dos campos que mudaram, nunca os valores (6.2,
 * regra 4).
 */
final class AtualizarClienteCadastro
{
    public const CAMPO_DE_OUTRO_TIPO = 'Um dos campos não é do tipo deste cliente (pessoa física ou jurídica). Confira os campos com a ler_clientes. Nada foi gravado.';
    public const CADASTRO_RECUSADO = 'O BlueJus recusou o cadastro. Confira os valores (CPF ou CNPJ válidos, e-mail, nascimento AAAA-MM-DD, UF de duas letras, estado civil da lista, tamanho de cada campo) e tente de novo. Nada foi gravado.';
    public const CONTATO_RECUSADO = 'O BlueJus recusou o contato. Mande o celular com DDD (10 ou 11 dígitos) ou um e-mail válido. Nada foi gravado.';

    /** Campos comparados só pelos dígitos: a pontuação não faz deles outro valor. */
    private const SO_DIGITOS = ['cpf', 'cnpj', 'representante_cpf', 'cep', 'telefone', 'whatsapp'];

    public function __construct(
        private readonly AtualizarClienteAcesso $acesso,
        private readonly CamposDoCadastro $campos,
        private readonly SalvarCadastroDoClienteUseCase $salvarCadastro,
        private readonly AtualizarContatoDoClienteUseCase $atualizarContato,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly AtualizarClienteProposta $proposta,
    ) {
    }

    /**
     * @param array<string, string> $campos chaves de {@see CamposDoCadastro::ROTULOS}, já conferidas pela ferramenta
     *
     * @throws ErroDaFerramenta
     */
    public function completar(int $clienteId, array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $cliente = $this->clienteEditavel($clienteId, $chamada);
        $atuais = $this->exigirCamposDoTipo($cliente, $campos, $chamada);
        [$mudam, $trocas] = self::mudancas($campos, $atuais);
        if ($mudam === []) {
            return $this->resposta('completar', $cliente, [], $chamada);
        }
        if ($trocas !== []) {
            return $this->proporCompletar($cliente, $mudam, $trocas, $chamada);
        }
        // Os valores passam primeiro no rascunho: o UseCase aplica campo por campo no cliente gerenciado e, se recusa um
        // depois de aplicar outro, o cliente fica sujo e um flush posterior da requisição gravaria a metade.
        $this->exigirValores($cliente, $mudam, $chamada);

        return $this->resposta('completar', $cliente, $this->gravarCampos($cliente, $mudam, $chamada), $chamada);
    }

    /**
     * @param string $campo um de {@see AtualizarClienteFerramenta::CAMPOS_DO_CONTATO}
     *
     * @throws ErroDaFerramenta
     */
    public function contato(int $clienteId, string $campo, string $valor, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $cliente = $this->clienteDoContato($clienteId, $campo, $chamada);
        $atual = self::contatoAtual($cliente, $campo);
        if (self::mesmoValor($campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL ? 'email' : 'telefone', $atual, $valor)) {
            return $this->resposta('contato', $cliente, [], $chamada);
        }
        if ($atual !== '') {
            return $this->proporContato($cliente, $campo, $valor, $chamada);
        }

        $this->gravarContato($cliente, $campo, $valor, $chamada);

        return $this->resposta('contato', $cliente, [$campo], $chamada);
    }

    /**
     * Reconfere a troca proposta (6.7, item 4), sem gravar: o cliente que a pessoa vê e edita, os campos do tipo, o
     * envio automático (a chave pode ter sido ligada depois da proposta) e os valores, com o CPF que virou repetido.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferirTroca(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->conferirTroca($acao, $dados, $chamada);
    }

    /**
     * Grava a troca confirmada, conferida de novo na mesma transação.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarTroca(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        [$cliente, $campos] = $this->conferirTroca($acao, $dados, $chamada);
        if ($acao === 'completar') {
            $mudados = $this->gravarCampos($cliente, $campos, $chamada);
        } else {
            $campo = (string) array_key_first($campos);
            $this->gravarContato($cliente, $campo, $campos[$campo], $chamada);
            $mudados = [$campo];
        }
        $resposta = $this->resposta($acao, $cliente, $mudados, $chamada);
        $id = (int) $cliente->getId();

        return new ResultadoDaConfirmacao(array_diff_key($resposta->dados, ['estado' => true]), [$id], $resposta->contagens, $resposta->ids);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{0: Cliente, 1: array<string, string>} o cliente e os campos a gravar (no contato, campo => valor)
     *
     * @throws ErroDaFerramenta
     */
    private function conferirTroca(string $acao, array $dados, ChamadaDoConector $chamada): array
    {
        $clienteId = AtualizarClienteProposta::inteiroDe($dados, 'cliente_id');
        if ($acao === 'completar') {
            $campos = AtualizarClienteProposta::camposDe($dados, 'campos');
            $cliente = $this->clienteEditavel($clienteId, $chamada);
            $this->exigirCamposDoTipo($cliente, $campos, $chamada);
            $this->exigirValores($cliente, $campos, $chamada);

            return [$cliente, $campos];
        }
        $campo = AtualizarClienteProposta::textoDe($dados, 'campo');
        $valor = AtualizarClienteProposta::textoDe($dados, 'valor');
        if (!\in_array($campo, AtualizarClienteFerramenta::CAMPOS_DO_CONTATO, true)) {
            throw new \LogicException('Proposta de contato com campo fora da lista.');
        }
        $cliente = $this->clienteDoContato($clienteId, $campo, $chamada);
        self::exigirContatoValido($campo, $valor);

        return [$cliente, [$campo => $valor]];
    }

    /**
     * A troca do `completar` vira UMA proposta com todos os campos que mudam, os vazios junto (abertura da 6.6, "Lote").
     *
     * @param array<string, string> $mudam
     * @param list<string>          $trocas as chaves cujo valor atual não está vazio
     *
     * @throws ErroDaFerramenta
     */
    private function proporCompletar(Cliente $cliente, array $mudam, array $trocas, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirValores($cliente, $mudam, $chamada);
        $linhas = [sprintf('Alterar o cadastro do cliente %s (id %d):', $this->nome($cliente, $chamada), (int) $cliente->getId())];
        foreach ($mudam as $chave => $valor) {
            $linhas[] = '- ' . AtualizarClienteProposta::campo($chave, $valor, \in_array($chave, $trocas, true) ? 'troca o valor atual' : 'campo vazio');
        }
        $dados = ['operacao' => 'trocar', 'cliente_id' => (int) $cliente->getId(), 'campos' => $mudam];
        $novos = array_values(array_diff(array_keys($mudam), $trocas));

        return $this->proposta->propor($chamada, 'completar', $dados, implode("\n", $linhas), ['campos_que_trocam' => $trocas, 'campos_vazios' => $novos]);
    }

    /** @throws ErroDaFerramenta */
    private function proporContato(Cliente $cliente, string $campo, string $valor, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        self::exigirContatoValido($campo, $valor);
        $rotulo = $campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL ? 'e-mail' : 'celular';
        $quem = sprintf('do cliente %s (id %d)', $this->nome($cliente, $chamada), (int) $cliente->getId());
        $resumo = trim($valor) === ''
            ? sprintf('Apagar o %s %s.', $rotulo, $quem)
            : sprintf('Trocar o %s %s por: %s', $rotulo, $quem, trim($valor));
        $dados = ['operacao' => 'trocar', 'cliente_id' => (int) $cliente->getId(), 'campo' => $campo, 'valor' => $valor];

        return $this->proposta->propor($chamada, 'contato', $dados, $resumo, ['campos_que_trocam' => [$campo]]);
    }

    /**
     * Os campos atuais do tipo do cliente; campo de outro tipo recusa, e o e-mail de cliente com envio ligado também.
     *
     * @param array<string, string> $campos
     *
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private function exigirCamposDoTipo(Cliente $cliente, array $campos, ChamadaDoConector $chamada): array
    {
        $atuais = array_intersect_key($this->campos->fotografar($cliente), CamposDoCadastro::ROTULOS);
        if (array_diff_key($campos, $atuais) !== []) {
            throw ErroDaFerramenta::recusada(self::CAMPO_DE_OUTRO_TIPO);
        }
        if (\array_key_exists('email', $campos)) {
            $this->acesso->exigirSemEnvioAutomatico($chamada, $cliente);
        }

        return $atuais;
    }

    /**
     * Os valores passam pelas regras da janela de Cadastro sem tocar no cliente: CPF ou CNPJ de outro cadastro recusa
     * (o do próprio cliente, não).
     *
     * @param array<string, string> $campos
     *
     * @throws ErroDaFerramenta
     */
    private function exigirValores(Cliente $cliente, array $campos, ChamadaDoConector $chamada): void
    {
        AtualizarClienteAcesso::gravando(function () use ($cliente, $campos, $chamada): void {
            try {
                $this->acesso->conferirCampos(AtualizarClienteAcesso::tipoDo($cliente), $campos, $chamada->contexto->escritorio);
            } catch (DocumentoJaCadastradoException $erro) {
                if ($erro->existente->getId() !== $cliente->getId()) {
                    throw $erro;
                }
            }
        }, self::CADASTRO_RECUSADO);
    }

    /**
     * @param array<string, string> $campos
     *
     * @return list<string> as chaves dos campos que mudaram, nunca os valores
     *
     * @throws ErroDaFerramenta
     */
    private function gravarCampos(Cliente $cliente, array $campos, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $rotulos = AtualizarClienteAcesso::gravando(
            fn (): array => $this->salvarCadastro->executar($cliente, $campos, $contexto->pessoa, $contexto->escritorio),
            self::CADASTRO_RECUSADO,
        );

        return array_values(array_filter(array_keys($campos), static fn (string $chave): bool => \in_array(CamposDoCadastro::ROTULOS[$chave], $rotulos, true)));
    }

    /** @throws ErroDaFerramenta */
    private function gravarContato(Cliente $cliente, string $campo, string $valor, ChamadaDoConector $chamada): void
    {
        $contexto = $chamada->contexto;
        AtualizarClienteAcesso::gravando(
            fn () => $this->atualizarContato->executar(new AtualizarContatoDoClienteInput((int) $cliente->getId(), $campo, $valor), $contexto->escritorio, $contexto->pessoa),
            self::CONTATO_RECUSADO,
        );
    }

    /**
     * Os campos que mudam (o que veio igual ao que já está sai sem mudar nada) e, entre eles, os que trocam ou apagam
     * um valor preenchido.
     *
     * @param array<string, string> $campos
     * @param array<string, string> $atuais
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function mudancas(array $campos, array $atuais): array
    {
        $mudam = [];
        $trocas = [];
        foreach ($campos as $chave => $valor) {
            $atual = trim($atuais[$chave]);
            if (self::mesmoValor($chave, $atual, $valor)) {
                continue;
            }
            if ($atual !== '') {
                $trocas[] = $chave;
            }
            $mudam[$chave] = $valor;
        }

        return [$mudam, $trocas];
    }

    /**
     * O formato que o {@see AtualizarContatoDoClienteUseCase} aceita, conferido antes de propor: celular com DDD (ou
     * vazio, que apaga) e e-mail (que não se apaga).
     *
     * @throws ErroDaFerramenta
     */
    private static function exigirContatoValido(string $campo, string $valor): void
    {
        $valor = trim($valor);
        $valido = $campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL
            ? preg_match('/^\S+@\S+\.\S+$/', $valor) === 1
            : $valor === '' || \in_array(\strlen(DigitoVerificador::digitos($valor)), [10, 11], true);
        if (!$valido) {
            throw ErroDaFerramenta::recusada(self::CONTATO_RECUSADO);
        }
    }

    private static function contatoAtual(Cliente $cliente, string $campo): string
    {
        return trim($campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL ? $cliente->getEmail() : (string) $cliente->getTelefoneCelular());
    }

    private static function mesmoValor(string $chave, string $atual, string $novo): bool
    {
        if (\in_array($chave, self::SO_DIGITOS, true)) {
            return DigitoVerificador::digitos($atual) === DigitoVerificador::digitos($novo);
        }

        return self::comparavel($atual) === self::comparavel($novo);
    }

    private static function comparavel(string $valor): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($valor)));
    }

    /** @throws ErroDaFerramenta */
    private function clienteEditavel(int $clienteId, ChamadaDoConector $chamada): Cliente
    {
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        $this->acesso->exigirEdicaoDoCliente($chamada, $cliente);

        return $cliente;
    }

    /**
     * O cliente editável; para o e-mail, sem o envio automático ligado.
     *
     * @throws ErroDaFerramenta
     */
    private function clienteDoContato(int $clienteId, string $campo, ChamadaDoConector $chamada): Cliente
    {
        $cliente = $this->clienteEditavel($clienteId, $chamada);
        if ($campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL) {
            $this->acesso->exigirSemEnvioAutomatico($chamada, $cliente);
        }

        return $cliente;
    }

    private function nome(Cliente $cliente, ChamadaDoConector $chamada): string
    {
        return $this->protecao->rotulo($chamada->contexto->escritorio, $cliente->getNomeExibicao(), 'sem nome');
    }

    /** @param list<string> $mudados os nomes dos campos (chaves da entrada), nunca os valores */
    private function resposta(string $acao, Cliente $cliente, array $mudados, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $id = (int) $cliente->getId();

        return new RespostaDaFerramenta(
            [
                'estado' => 'concluida',
                'acao' => $acao,
                'cliente' => ['id' => $id, 'nome' => $this->nome($cliente, $chamada)],
                'campos_alterados' => $mudados,
                'nada_mudou' => $mudados === [],
            ],
            contagens: ['campos' => \count($mudados)],
            ids: ['cliente' => [$id]],
        );
    }
}
