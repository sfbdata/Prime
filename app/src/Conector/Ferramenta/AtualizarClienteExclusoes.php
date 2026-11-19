<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Service\DocumentosVisiveisDoCadastro;
use App\Cliente\UseCase\ExcluirClienteUseCase;
use App\Cliente\UseCase\ExcluirDocumentoDoClienteUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Repository\ClienteDocumentoRepository;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;

/**
 * `excluir_documento` e `excluir_cliente` da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 14b).
 * Excluir é sempre por proposta (6.7); a confirmação refaz todas as conferências.
 *
 *  - `excluir_documento` (`cliente_id`, `documento_id`): {@see ExcluirDocumentoDoClienteUseCase}, com o recurso
 *    `cliente` de editar, como a tela (`cliente_documento_delete`). O documento é do escritório E do cliente
 *    informado; o vinculado de pasta só vale se a pessoa o vê na tela, a pasta não está em segredo e a linha da tabela
 *    abre a pasta ({@see DocumentosDoClienteNoRecorte}). Só sai o vínculo: o arquivo é da pasta;
 *  - `excluir_cliente` (`cliente_id`): {@see ExcluirClienteUseCase}, com o recurso `cliente` de excluir, como a tela
 *    (`cliente_delete`). Cliente com carteira de cobrança é recusado ao propor e na confirmação, com mensagem fixa: a
 *    FK `cobranca_carteira.cliente_id` não pode estourar dentro da transação, ela fecha o EntityManager.
 *
 * Os arquivos próprios saem DEPOIS do COMMIT da confirmação (INV-6): o UseCase devolve só as chaves e o resultado leva a
 * remoção em {@see ResultadoDaConfirmacao::$depoisDoCommit}. A resposta traz ids e o nome do cliente, nunca dado de
 * cadastro (6.2, regra 4).
 */
final class AtualizarClienteExclusoes
{
    public const COM_CARTEIRA = 'Este cliente tem carteira de cobrança e não pode ser excluído. Exclua a carteira antes, pela tela. Nada foi gravado.';
    public const RECUSADO = 'O BlueJus recusou esta exclusão. Nada foi gravado.';

    public function __construct(
        private readonly AtualizarClienteAcesso $acesso,
        private readonly AtualizarClienteProposta $proposta,
        private readonly ClienteDocumentoRepository $documentos,
        private readonly DocumentosVisiveisDoCadastro $visiveis,
        private readonly DocumentosDoClienteNoRecorte $recorte,
        private readonly ExcluirDocumentoDoClienteUseCase $excluirDocumento,
        private readonly ExcluirClienteUseCase $excluirCliente,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ProtecaoDoTextoDoChat $protecao,
    ) {
    }

    /** @throws ErroDaFerramenta */
    public function proporDocumento(int $clienteId, int $documentoId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        [$cliente, $documento] = $this->conferirDocumento($clienteId, $documentoId, $chamada);
        $resumo = sprintf(
            'Excluir o documento "%s" (id %d) do cadastro do cliente %s (id %d). %s',
            $this->protecao->rotulo($chamada->contexto->escritorio, $documento->getNomeOriginal(), 'sem nome'),
            $documentoId,
            $this->nome($cliente, $chamada),
            $clienteId,
            $documento->isVinculado()
                ? 'Sai só o vínculo com o cadastro: o arquivo continua na pasta. Não há como desfazer.'
                : 'O arquivo é apagado do sistema. Não há como desfazer.',
        );

        return $this->proposta->propor($chamada, 'excluir_documento', ['operacao' => 'excluir_documento', 'cliente_id' => $clienteId, 'documento_id' => $documentoId], $resumo);
    }

    /** @throws ErroDaFerramenta */
    public function proporCliente(int $clienteId, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $cliente = $this->conferirCliente($clienteId, $chamada);
        $resumo = sprintf(
            'Excluir DE VEZ o cliente %s (id %d) do escritório. Não há como desfazer. Os documentos próprios do cadastro saem junto e os arquivos são apagados. '
            . 'O cliente sai de todas as pastas em que está vinculado (as pastas e os documentos delas ficam; onde ele era o principal, a pasta fica sem cliente principal).',
            $this->nome($cliente, $chamada),
            $clienteId,
        );

        return $this->proposta->propor($chamada, 'excluir_cliente', ['operacao' => 'excluir_cliente', 'cliente_id' => $clienteId], $resumo);
    }

    /**
     * Só lê: roda ao propor e de novo na confirmação.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferirDocumento(array $dados, ChamadaDoConector $chamada): void
    {
        $this->conferirDocumento(AtualizarClienteProposta::inteiroDe($dados, 'cliente_id'), AtualizarClienteProposta::inteiroDe($dados, 'documento_id'), $chamada);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferirCliente(array $dados, ChamadaDoConector $chamada): void
    {
        $this->conferirCliente(AtualizarClienteProposta::inteiroDe($dados, 'cliente_id'), $chamada);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarDocumento(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        [$cliente, $documento] = $this->conferirDocumento(AtualizarClienteProposta::inteiroDe($dados, 'cliente_id'), AtualizarClienteProposta::inteiroDe($dados, 'documento_id'), $chamada);
        $clienteId = (int) $cliente->getId();
        $documentoId = (int) $documento->getId();
        $nome = $this->nome($cliente, $chamada);
        $contexto = $chamada->contexto;
        $chaves = AtualizarClienteAcesso::gravando(fn (): array => $this->excluirDocumento->executar($documento, $contexto->pessoa, $contexto->escritorio), self::RECUSADO);

        return new ResultadoDaConfirmacao(
            ['operacao' => 'excluir_documento', 'cliente' => ['id' => $clienteId, 'nome' => $nome], 'documento_id' => $documentoId],
            [$documentoId],
            ['documentos' => 1],
            ['cliente' => [$clienteId], 'documento' => [$documentoId]],
            $this->remocaoDepoisDoCommit($chaves),
        );
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarCliente(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $cliente = $this->conferirCliente(AtualizarClienteProposta::inteiroDe($dados, 'cliente_id'), $chamada);
        $clienteId = (int) $cliente->getId();
        $nome = $this->nome($cliente, $chamada);
        $escritorio = $chamada->contexto->escritorio;
        // O ids e o nome antes: o UseCase dá `clear()` e as entidades ficam desanexadas.
        $chaves = AtualizarClienteAcesso::gravando(fn (): array => $this->excluirCliente->executar($clienteId, $escritorio), self::RECUSADO);

        return new ResultadoDaConfirmacao(
            ['operacao' => 'excluir_cliente', 'cliente' => ['id' => $clienteId, 'nome' => $nome]],
            [$clienteId],
            ['clientes' => 1],
            ['cliente' => [$clienteId]],
            $this->remocaoDepoisDoCommit($chaves),
        );
    }

    /**
     * O cliente que a pessoa vê e pode editar e o documento dele, do escritório; o vinculado de pasta só se a pessoa o
     * vê na tela e a pasta abre para o conector.
     *
     * @return array{0: Cliente, 1: ClienteDocumento}
     *
     * @throws ErroDaFerramenta
     */
    private function conferirDocumento(int $clienteId, int $documentoId, ChamadaDoConector $chamada): array
    {
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        $this->acesso->exigirEdicaoDoCliente($chamada, $cliente);
        $contexto = $chamada->contexto;
        $documento = $this->documentos->findOneBy(['id' => $documentoId, 'tenant' => $contexto->escritorio]);
        if (!$documento instanceof ClienteDocumento || $documento->getCliente()?->getId() !== $cliente->getId()
            || !\in_array($documento, $this->visiveis->de($cliente, $contexto->pessoa, $contexto->escritorio), true)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $recorte = $this->recorte->recortar([$documento], $chamada, AtualizarClienteFerramenta::NOME, 'excluir_documento');
        if ($recorte['em_segredo'] > 0) {
            throw ErroDaFerramenta::emSegredo();
        }
        if ($recorte['documentos'] === []) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return [$cliente, $documento];
    }

    /** @throws ErroDaFerramenta */
    private function conferirCliente(int $clienteId, ChamadaDoConector $chamada): Cliente
    {
        $cliente = $this->acesso->cliente($chamada, $clienteId);
        $this->acesso->exigirExclusaoDoCliente($chamada, $cliente);
        if ($this->excluirCliente->temCarteiraDeCobranca($cliente, $chamada->contexto->escritorio)) {
            throw ErroDaFerramenta::recusada(self::COM_CARTEIRA);
        }

        return $cliente;
    }

    /**
     * A remoção dos arquivos, para depois do COMMIT da confirmação. Só chaves, nenhuma entidade.
     *
     * @param list<ChaveDeArquivo> $chaves
     *
     * @return (\Closure(): void)|null
     */
    private function remocaoDepoisDoCommit(array $chaves): ?\Closure
    {
        if ($chaves === []) {
            return null;
        }
        $remocao = $this->remocao;

        return static function () use ($remocao, $chaves): void {
            $remocao->remover($chaves, 'AtualizarClienteExclusoes: arquivos do cadastro excluído');
        };
    }

    private function nome(Cliente $cliente, ChamadaDoConector $chamada): string
    {
        return $this->protecao->rotulo($chamada->contexto->escritorio, $cliente->getNomeExibicao(), 'sem nome');
    }
}
