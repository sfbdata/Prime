<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\Entity\Cliente;
use App\Cliente\UseCase\VincularDocumentoDaPastaAoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\DefinirClientePrincipalUseCase;
use App\Pasta\UseCase\DesvincularClienteDaPastaUseCase;
use App\Pasta\UseCase\VincularClienteAPastaUseCase;

/**
 * `vincular_a_pasta`, `principal` e `ligar_documento` da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6).
 * Acrescentar e escolher numa lista gravam direto (abertura da 6.6):
 *
 *  - `vincular_a_pasta` (`cliente_id`, `pasta_id`): {@see VincularClienteAPastaUseCase}, com o recurso `pasta` de
 *    editar, como a tela; pasta sem cliente faz dele o principal;
 *  - `principal` (`cliente_id`, `pasta_id`): {@see DefinirClientePrincipalUseCase}, com o recurso `pasta` de editar;
 *    o cliente precisa já estar na pasta;
 *  - `ligar_documento` (`cliente_id`, `documento_id`): {@see VincularDocumentoDaPastaAoClienteUseCase}, com o recurso
 *    `cliente` de editar e ver a pasta do documento, como a tela; a pasta passa pelas mesmas recusas da tabela, da
 *    lixeira, do segredo e da aba Documentos ({@see AtualizarClienteAcesso::pastaDoDocumento()}).
 *
 * Desvincular (`vincular_a_pasta` com `operacao: desvincular`) é exclusão e vai sempre por proposta
 * ({@see proporDesvinculo()}); a confirmação grava pelo {@see DesvincularClienteDaPastaUseCase}. O cadastro do cliente
 * continua existindo; se ele era o principal, {@see \App\Pasta\Entity\Pasta::removeCliente()} promove o de cadastro mais
 * antigo entre os que sobram.
 *
 * Cliente com o envio automático do Push ligado recusa o `vincular_a_pasta` e o `principal` sempre, sem gravar nada; no
 * desvincular, recusa quando o cliente que seria PROMOVIDO a principal tem o envio ligado, ao propor e de novo na
 * confirmação (abertura da 6.6). A resposta traz ids, o nome do cliente e o número da pasta, nunca dado de cadastro
 * (6.2, regra 4).
 */
final class AtualizarClienteVinculos
{
    public const JA_VINCULADO = 'Este cliente já está vinculado à pasta. Nada foi gravado.';
    public const NAO_VINCULADO = 'Este cliente não está vinculado à pasta: vincule antes, com a ação vincular_a_pasta. Nada foi gravado.';
    public const DOCUMENTO_RECUSADO = 'O BlueJus recusou ligar este documento ao cadastro. Só documento de identificação ou comprovante de residência achado numa pasta do cliente (veja ler_clientes, ação documentos_de_identidade) pode ser ligado. Nada foi gravado.';

    public function __construct(
        private readonly AtualizarClienteAcesso $acesso,
        private readonly VincularClienteAPastaUseCase $vincular,
        private readonly DefinirClientePrincipalUseCase $definirPrincipal,
        private readonly VincularDocumentoDaPastaAoClienteUseCase $ligarDocumento,
        private readonly PastaDocumentoRepository $documentos,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly DesvincularClienteDaPastaUseCase $desvincular,
        private readonly AtualizarClienteProposta $proposta,
    ) {
    }

    /**
     * Desvincular é exclusão: só propõe; nada muda até a confirmação.
     *
     * @throws ErroDaFerramenta
     */
    public function proporDesvinculo(int $clienteId, int $pastaId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        [$pasta, $cliente, $promovido] = $this->conferirDesvinculo($clienteId, $pastaId, $chamada);
        $resumo = sprintf(
            'Desvincular o cliente %s (id %d) da pasta %s. O cadastro do cliente continua existindo.',
            $this->dadosDoCliente($cliente, $chamada)['nome'],
            (int) $cliente->getId(),
            $this->numero($pasta, $chamada),
        );
        if ($promovido !== null) {
            $resumo .= sprintf(' Ele é o cliente principal da pasta: %s (id %d) passa a ser o principal.', $this->dadosDoCliente($promovido, $chamada)['nome'], (int) $promovido->getId());
        }
        $dados = ['operacao' => 'desvincular', 'cliente_id' => (int) $cliente->getId(), 'pasta_id' => (int) $pasta->getId()];

        return $this->proposta->propor($chamada, 'vincular_a_pasta', $dados, $resumo);
    }

    /**
     * A pasta viva que a pessoa edita, o cliente que ela vê e que está na pasta e, se ele é o principal, o envio
     * automático de quem seria promovido. Só lê: roda ao propor e de novo na confirmação (6.7, item 4).
     *
     * @param array<string, mixed> $dados
     *
     * @return array{0: Pasta, 1: Cliente, 2: Cliente|null} a pasta, o cliente e quem seria promovido
     *
     * @throws ErroDaFerramenta
     */
    public function reconferirDesvinculo(array $dados, ChamadaDoConector $chamada): array
    {
        return $this->conferirDesvinculo(AtualizarClienteProposta::inteiroDe($dados, 'cliente_id'), AtualizarClienteProposta::inteiroDe($dados, 'pasta_id'), $chamada);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarDesvinculo(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        [$pasta, $cliente] = $this->reconferirDesvinculo($dados, $chamada);
        $contexto = $chamada->contexto;
        $promovido = AtualizarClienteAcesso::gravando(
            fn (): ?Cliente => $this->desvincular->executar($pasta, $cliente, $contexto->pessoa, $contexto->escritorio),
            self::NAO_VINCULADO,
        );

        return new ResultadoDaConfirmacao(
            [
                'operacao' => 'desvincular',
                'cliente' => $this->dadosDoCliente($cliente, $chamada),
                'pasta' => ['id' => (int) $pasta->getId(), 'numero' => $this->numero($pasta, $chamada)],
                'novo_principal' => $promovido === null ? null : $this->dadosDoCliente($promovido, $chamada),
            ],
            [(int) $cliente->getId()],
            ['clientes' => 1],
            ['cliente' => [(int) $cliente->getId()], 'pasta' => [(int) $pasta->getId()]],
        );
    }

    /** @throws ErroDaFerramenta */
    public function vincularAPasta(int $clienteId, int $pastaId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        [$pasta, $cliente] = $this->pastaECliente('vincular_a_pasta', $clienteId, $pastaId, $chamada);
        if ($pasta->getClientes()->contains($cliente)) {
            throw ErroDaFerramenta::recusada(self::JA_VINCULADO);
        }

        $contexto = $chamada->contexto;
        AtualizarClienteAcesso::gravando(
            fn (): Cliente => $this->vincular->executar($pasta, (int) $cliente->getId(), $contexto->pessoa, $contexto->escritorio),
            self::JA_VINCULADO,
        );

        return $this->respostaDaPasta('vincular_a_pasta', $pasta, $cliente, true, $chamada);
    }

    /** @throws ErroDaFerramenta */
    public function principal(int $clienteId, int $pastaId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        [$pasta, $cliente] = $this->pastaECliente('principal', $clienteId, $pastaId, $chamada);
        if (!$pasta->getClientes()->contains($cliente)) {
            throw ErroDaFerramenta::recusada(self::NAO_VINCULADO);
        }
        if ($pasta->getClientePrincipal()?->getId() === $cliente->getId()) {
            return $this->respostaDaPasta('principal', $pasta, $cliente, false, $chamada);
        }

        AtualizarClienteAcesso::gravando(fn () => $this->definirPrincipal->executar($pasta, $cliente), self::NAO_VINCULADO);

        return $this->respostaDaPasta('principal', $pasta, $cliente, true, $chamada);
    }

    /** @throws ErroDaFerramenta */
    public function ligarDocumento(int $clienteId, int $documentoId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        $contexto = $chamada->contexto;
        $pasta = $this->documentos->findByIdAndTenant($documentoId, $contexto->escritorio)?->getPasta();
        if (!$pasta instanceof Pasta) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->acesso->pastaDoDocumento($chamada, $pasta);
        $this->acesso->exigirEdicaoDoCliente($chamada, $cliente);

        $criado = AtualizarClienteAcesso::gravando(
            fn (): bool => $this->ligarDocumento->executar($cliente, $documentoId, $contexto->pessoa, $contexto->escritorio),
            self::DOCUMENTO_RECUSADO,
        );

        return new RespostaDaFerramenta(
            [
                'estado' => 'concluida',
                'acao' => 'ligar_documento',
                'cliente' => $this->dadosDoCliente($cliente, $chamada),
                'documento_id' => $documentoId,
                'pasta_id' => (int) $pasta->getId(),
                'ja_estava_ligado' => !$criado,
            ],
            contagens: ['documentos' => $criado ? 1 : 0],
            ids: ['cliente' => [(int) $cliente->getId()], 'pasta' => [(int) $pasta->getId()], 'documento' => [$documentoId]],
        );
    }

    /**
     * A pasta viva que a pessoa edita, o cliente que ela vê e a recusa do envio automático, nesta ordem.
     *
     * @return array{0: Pasta, 1: Cliente}
     *
     * @throws ErroDaFerramenta
     */
    private function pastaECliente(string $acao, int $clienteId, int $pastaId, ChamadaDoConector $chamada): array
    {
        $pasta = $this->acesso->pastaViva($chamada, $acao, $pastaId, true);
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        $this->acesso->exigirSemEnvioAutomatico($chamada, $cliente);

        return [$pasta, $cliente];
    }

    /**
     * @return array{0: Pasta, 1: Cliente, 2: Cliente|null}
     *
     * @throws ErroDaFerramenta
     */
    private function conferirDesvinculo(int $clienteId, int $pastaId, ChamadaDoConector $chamada): array
    {
        $pasta = $this->acesso->pastaViva($chamada, 'vincular_a_pasta', $pastaId, true);
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        if (!$pasta->getClientes()->contains($cliente)) {
            throw ErroDaFerramenta::recusada(self::NAO_VINCULADO);
        }
        $promovido = self::quemSeriaPromovido($pasta, $cliente);
        if ($promovido !== null) {
            $this->acesso->exigirSemEnvioAutomatico($chamada, $promovido);
        }

        return [$pasta, $cliente, $promovido];
    }

    /**
     * Quem {@see Pasta::removeCliente()} promoveria a principal, sem mexer na pasta: o de menor id entre os que sobram,
     * só quando o desvinculado é o principal.
     */
    private static function quemSeriaPromovido(Pasta $pasta, Cliente $cliente): ?Cliente
    {
        if ($pasta->getClientePrincipal()?->getId() !== $cliente->getId()) {
            return null;
        }
        $restantes = array_values(array_filter($pasta->getClientes()->toArray(), static fn (Cliente $c): bool => $c->getId() !== $cliente->getId()));
        usort($restantes, static fn (Cliente $a, Cliente $b): int => $a->getId() <=> $b->getId());

        return $restantes[0] ?? null;
    }

    private function numero(Pasta $pasta, ChamadaDoConector $chamada): string
    {
        return $this->protecao->rotulo($chamada->contexto->escritorio, (string) $pasta->getNup(), 'sem número');
    }

    private function respostaDaPasta(string $acao, Pasta $pasta, Cliente $cliente, bool $mudou, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $principal = $pasta->getClientePrincipal();

        return new RespostaDaFerramenta(
            [
                'estado' => 'concluida',
                'acao' => $acao,
                'cliente' => $this->dadosDoCliente($cliente, $chamada),
                'pasta' => ['id' => (int) $pasta->getId(), 'numero' => $this->numero($pasta, $chamada)],
                'e_o_principal_da_pasta' => $principal !== null && $principal->getId() === $cliente->getId(),
                'nada_mudou' => !$mudou,
            ],
            contagens: ['clientes' => $mudou ? 1 : 0],
            ids: ['cliente' => [(int) $cliente->getId()], 'pasta' => [(int) $pasta->getId()]],
        );
    }

    /** @return array{id: int, nome: string} */
    private function dadosDoCliente(Cliente $cliente, ChamadaDoConector $chamada): array
    {
        return ['id' => (int) $cliente->getId(), 'nome' => $this->protecao->rotulo($chamada->contexto->escritorio, $cliente->getNomeExibicao(), 'sem nome')];
    }
}
