<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\LinkExternoDoPush;

/**
 * A página pública do link externo, já decidida ([PC]): o template só desenha o `estado`.
 *
 * Estados: `nao_encontrado` · `revogado` · `expirado` (com o estado do pedido de renovação) ·
 * `pede_codigo` (com erro opcional) · `bloqueado` (5 códigos errados) · `ok`.
 *
 * Fora do `ok`, nenhum dado do processo sai: só a barra de quem criou o link (para o terceiro saber
 * com quem falar) e a validade, no expirado. No `nao_encontrado`, nem isso.
 */
final readonly class PaginaDoPushCompartilhadoOutput
{
    public const NAO_ENCONTRADO = 'nao_encontrado';
    public const REVOGADO = 'revogado';
    public const EXPIRADO = 'expirado';
    public const PEDE_CODIGO = 'pede_codigo';
    public const BLOQUEADO = 'bloqueado';
    public const OK = 'ok';

    /**
     * @param array{nome: string, oab: ?string, email: ?string, escritorio: string}|null $advogado
     * @param AnaliseCompartilhadaOutput[]                                              $analises
     * @param MovimentacaoCompartilhadaOutput[]                                         $movimentacoes
     */
    private function __construct(
        public string $estado,
        public string $endereco,
        public ?array $advogado = null,
        public ?string $expiraEm = null,
        public bool $podePedirRenovacao = false,
        public bool $renovacaoPendente = false,
        public bool $renovacaoRecusada = false,
        public ?string $renovacaoPedidaEm = null,
        public ?string $erroCodigo = null,
        public ?string $processo = null,
        public ?string $cliente = null,
        public ?string $acao = null,
        public array $analises = [],
        public array $movimentacoes = [],
    ) {
    }

    public static function naoEncontrado(string $endereco): self
    {
        return new self(self::NAO_ENCONTRADO, $endereco);
    }

    public static function revogado(LinkExternoDoPush $link): self
    {
        return new self(self::REVOGADO, $link->getEndereco(), self::advogado($link));
    }

    public static function expirado(LinkExternoDoPush $link, \DateTimeImmutable $agora): self
    {
        return new self(
            estado: self::EXPIRADO,
            endereco: $link->getEndereco(),
            advogado: self::advogado($link),
            expiraEm: self::dataHora($link->getExpiraEm()),
            podePedirRenovacao: $link->aceitaPedidoDeRenovacao($agora),
            renovacaoPendente: $link->temPedidoPendente(),
            renovacaoRecusada: $link->renovacaoFoiRecusada(),
            renovacaoPedidaEm: $link->getRenovacaoPedidaEm() !== null ? self::dataHora($link->getRenovacaoPedidaEm()) : null,
        );
    }

    public static function pedeCodigo(LinkExternoDoPush $link, ?string $erro = null): self
    {
        return new self(self::PEDE_CODIGO, $link->getEndereco(), self::advogado($link), erroCodigo: $erro);
    }

    public static function bloqueado(LinkExternoDoPush $link): self
    {
        return new self(self::BLOQUEADO, $link->getEndereco(), self::advogado($link));
    }

    /**
     * @param AnaliseCompartilhadaOutput[]      $analises
     * @param MovimentacaoCompartilhadaOutput[] $movimentacoes
     */
    public static function ok(LinkExternoDoPush $link, ?string $cliente, array $analises, array $movimentacoes): self
    {
        return new self(
            estado: self::OK,
            endereco: $link->getEndereco(),
            advogado: self::advogado($link),
            expiraEm: self::dataHora($link->getExpiraEm()),
            processo: $link->getProcesso()->getNumeroProcesso(),
            cliente: $link->mostraCliente() ? $cliente : null,
            acao: $link->getPasta()->getNomeAcao(),
            analises: $link->incluiAnalises() ? $analises : [],
            movimentacoes: $movimentacoes,
        );
    }

    /** Os 8 caracteres aleatórios do fim do endereço: a assinatura da marca d'água ([PC] 154). */
    public function assinatura(): string
    {
        return substr($this->endereco, -8);
    }

    /** @return array{nome: string, oab: ?string, email: ?string, escritorio: string} */
    private static function advogado(LinkExternoDoPush $link): array
    {
        $usuario = $link->getCriadoPor();
        $numero = trim((string) $usuario->getOabNumero());
        $uf = trim((string) $usuario->getOabUf());

        return [
            'nome' => (string) $usuario->getFullName(),
            'oab' => $numero !== '' ? $numero . ($uf !== '' ? '/' . mb_strtoupper($uf) : '') : null,
            'email' => $usuario->getEmail(),
            'escritorio' => (string) $link->getTenant()->getName(),
        ];
    }

    private static function dataHora(\DateTimeImmutable $quando): string
    {
        return $quando->format('d/m/Y H:i');
    }
}
