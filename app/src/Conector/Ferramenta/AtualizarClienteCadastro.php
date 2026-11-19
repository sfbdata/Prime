<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\DTO\AtualizarContatoDoClienteInput;
use App\Cliente\Entity\Cliente;
use App\Cliente\Service\CamposDoCadastro;
use App\Cliente\UseCase\AtualizarContatoDoClienteUseCase;
use App\Cliente\UseCase\SalvarCadastroDoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Shared\Service\DigitoVerificador;

/**
 * `completar` e `contato` da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6), com o recurso `cliente` de
 * editar e sem pasta:
 *
 *  - `completar` (`cliente_id`, `campos` com chaves de {@see CamposDoCadastro::ROTULOS} do tipo do cliente): grava
 *    pelo {@see SalvarCadastroDoClienteUseCase} só o que preenche campo VAZIO. Valor igual ao que já está (sem olhar
 *    maiúsculas, espaços nem a pontuação de documento e telefone) não muda nada;
 *  - `contato` (`campo` celular ou email, `valor`): pelo {@see AtualizarContatoDoClienteUseCase}, com a mesma regra.
 *
 * Trocar ou apagar um valor já preenchido é gravar por cima (abertura da 6.6) e vai por proposta. Nesta rodada a
 * proposta ainda não existe: a chamada que troca qualquer campo recusa INTEIRA, sem gravar nada, em
 * {@see recusarTroca()} (o ponto de extensão da rodada 2). O e-mail de cliente com o envio automático ligado recusa
 * sempre. A resposta traz o id, o nome e os NOMES dos campos que mudaram, nunca os valores (6.2, regra 4).
 */
final class AtualizarClienteCadastro
{
    public const TROCA_POR_CONFIRMACAO = 'Este pedido troca ou apaga um valor que já está preenchido no cadastro do cliente. '
        . 'Trocar valor preenchido vai por confirmação da pessoa, que o conector ainda não oferece para clientes: faça a troca pela tela do BlueJus. '
        . 'Para completar, mande só os campos vazios (a ação ler da ler_clientes diz quais faltam). Nada foi gravado.';
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
        $atuais = array_intersect_key($this->campos->fotografar($cliente), CamposDoCadastro::ROTULOS);
        if (array_diff_key($campos, $atuais) !== []) {
            throw ErroDaFerramenta::recusada(self::CAMPO_DE_OUTRO_TIPO);
        }
        if (\array_key_exists('email', $campos)) {
            $this->acesso->exigirSemEnvioAutomatico($chamada, $cliente);
        }
        $vazios = $this->soOsVazios($campos, $atuais);
        if ($vazios === []) {
            return $this->resposta('completar', $cliente, [], $chamada);
        }

        $contexto = $chamada->contexto;
        $rotulos = AtualizarClienteAcesso::gravando(
            fn (): array => $this->salvarCadastro->executar($cliente, $vazios, $contexto->pessoa, $contexto->escritorio),
            self::CADASTRO_RECUSADO,
        );
        $mudados = array_values(array_filter(array_keys($vazios), static fn (string $chave): bool => \in_array(CamposDoCadastro::ROTULOS[$chave], $rotulos, true)));

        return $this->resposta('completar', $cliente, $mudados, $chamada);
    }

    /**
     * @param string $campo um de {@see AtualizarClienteFerramenta::CAMPOS_DO_CONTATO}
     *
     * @throws ErroDaFerramenta
     */
    public function contato(int $clienteId, string $campo, string $valor, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $cliente = $this->clienteEditavel($clienteId, $chamada);
        $email = $campo === AtualizarContatoDoClienteUseCase::CAMPO_EMAIL;
        if ($email) {
            $this->acesso->exigirSemEnvioAutomatico($chamada, $cliente);
        }
        $chave = $email ? 'email' : 'telefone';
        $atual = trim($email ? $cliente->getEmail() : (string) $cliente->getTelefoneCelular());
        if (self::mesmoValor($chave, $atual, $valor)) {
            return $this->resposta('contato', $cliente, [], $chamada);
        }
        if ($atual !== '') {
            $this->recusarTroca();
        }

        $contexto = $chamada->contexto;
        AtualizarClienteAcesso::gravando(
            fn () => $this->atualizarContato->executar(new AtualizarContatoDoClienteInput((int) $cliente->getId(), $campo, $valor), $contexto->escritorio, $contexto->pessoa),
            self::CONTATO_RECUSADO,
        );

        return $this->resposta('contato', $cliente, [$campo], $chamada);
    }

    /**
     * Os campos que preenchem o vazio; o que veio igual ao que já está sai sem mudar nada. Qualquer campo que trocaria
     * ou apagaria um valor preenchido recusa a chamada inteira, antes de gravar.
     *
     * @param array<string, string> $campos
     * @param array<string, string> $atuais
     *
     * @return array<string, string>
     *
     * @throws ErroDaFerramenta
     */
    private function soOsVazios(array $campos, array $atuais): array
    {
        $vazios = [];
        foreach ($campos as $chave => $valor) {
            $atual = trim($atuais[$chave]);
            if (self::mesmoValor($chave, $atual, $valor)) {
                continue;
            }
            if ($atual !== '') {
                $this->recusarTroca();
            }
            $vazios[$chave] = $valor;
        }

        return $vazios;
    }

    /**
     * Ponto de extensão da rodada 2: aqui a troca vira proposta (`PropostasDoConector::propor`, com o resumo dizendo o
     * que é novo e o que é troca, abertura da 6.6, item "Lote") e o executor grava tudo na confirmação. Nesta rodada,
     * recusa com a mensagem fixa e nada é gravado.
     *
     * @throws ErroDaFerramenta
     */
    private function recusarTroca(): never
    {
        throw ErroDaFerramenta::recusada(self::TROCA_POR_CONFIRMACAO);
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

    /** @param list<string> $mudados os nomes dos campos (chaves da entrada), nunca os valores */
    private function resposta(string $acao, Cliente $cliente, array $mudados, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $id = (int) $cliente->getId();

        return new RespostaDaFerramenta(
            [
                'estado' => 'concluida',
                'acao' => $acao,
                'cliente' => ['id' => $id, 'nome' => $this->protecao->rotulo($chamada->contexto->escritorio, $cliente->getNomeExibicao(), 'sem nome')],
                'campos_alterados' => $mudados,
                'nada_mudou' => $mudados === [],
            ],
            contagens: ['campos' => \count($mudados)],
            ids: ['cliente' => [$id]],
        );
    }
}
