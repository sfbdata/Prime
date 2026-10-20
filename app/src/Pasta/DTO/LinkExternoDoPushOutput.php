<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\EstadoDoLinkExterno;
use App\Pasta\Entity\LinkExternoDoPush;

/**
 * Um link da lista "Links desta pasta" (modal do link externo, C.6) e do painel de acessos (C.7),
 * já resolvido para a tela: estado, datas formatadas, acessos agregados e o que cada botão pode.
 *
 * O código vai junto porque o desenho manda copiá-lo de novo da lista; esta saída só sai pelas
 * rotas internas, atrás do guarda da pasta.
 */
final readonly class LinkExternoDoPushOutput
{
    /**
     * @param array<string, mixed>|null                          $renovacao
     * @param array{total: int, ultimo: ?string, porDia: list<array{dia: string, total: int}>, ultimos: list<string>} $acessos
     */
    private function __construct(
        public int $id,
        public string $estado,
        public string $estadoRotulo,
        public string $endereco,
        public string $url,
        public ?string $codigo,
        public bool $mostrarCliente,
        public bool $incluirAnalises,
        public string $criadoPor,
        public string $criadoEm,
        public string $expiraEm,
        public ?string $revogadoEm,
        public ?array $renovacao,
        public array $acessos,
        public bool $podeRevogar,
        public bool $podeRenovar,
        public bool $podeRecusar,
        public ?\DateTimeImmutable $ultimoAcessoEm,
    ) {
    }

    /**
     * @param array{total: int, ultimo: ?\DateTimeImmutable, porDia: array<string, int>, ultimos: list<\DateTimeImmutable>} $estatisticas
     */
    public static function fromEntity(LinkExternoDoPush $link, string $url, array $estatisticas, \DateTimeImmutable $agora, bool $visivel = true): self
    {
        $estado = $link->estado($agora);
        // Link que a página pública não abre mais não pode aparecer como "Ativo" nem ser renovado.
        if (!$visivel && $estado !== EstadoDoLinkExterno::Revogado) {
            $estado = EstadoDoLinkExterno::Indisponivel;
        }

        return new self(
            id: (int) $link->getId(),
            estado: $estado->value,
            estadoRotulo: $estado->rotulo(),
            endereco: $link->getEndereco(),
            url: $url,
            codigo: $link->getCodigo(),
            mostrarCliente: $link->mostraCliente(),
            incluirAnalises: $link->incluiAnalises(),
            criadoPor: (string) $link->getCriadoPor()->getFullName(),
            criadoEm: self::dataHora($link->getCriadoEm()),
            expiraEm: self::dataHora($link->getExpiraEm()),
            revogadoEm: $link->getRevogadoEm() !== null ? self::dataHora($link->getRevogadoEm()) : null,
            renovacao: self::renovacao($link),
            acessos: self::acessos($estatisticas),
            podeRevogar: $estado !== EstadoDoLinkExterno::Revogado,
            podeRenovar: $estado === EstadoDoLinkExterno::Expirado || $estado === EstadoDoLinkExterno::RenovacaoPedida,
            podeRecusar: $estado === EstadoDoLinkExterno::RenovacaoPedida,
            ultimoAcessoEm: $estatisticas['ultimo'],
        );
    }

    /** O que "Copiar" põe na área de transferência ([EXP] 5196): a URL e, se houver, o código. */
    public function textoParaCopiar(): string
    {
        return $this->url . ($this->codigo !== null ? "\nCódigo de acesso: " . $this->codigo : '');
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'estado' => $this->estado,
            'estadoRotulo' => $this->estadoRotulo,
            'endereco' => $this->endereco,
            'url' => $this->url,
            'codigo' => $this->codigo,
            'comCodigo' => $this->codigo !== null,
            'mostrarCliente' => $this->mostrarCliente,
            'incluirAnalises' => $this->incluirAnalises,
            'criadoPor' => $this->criadoPor,
            'criadoEm' => $this->criadoEm,
            'expiraEm' => $this->expiraEm,
            'revogadoEm' => $this->revogadoEm,
            'renovacao' => $this->renovacao,
            'acessos' => $this->acessos,
            'podeRevogar' => $this->podeRevogar,
            'podeRenovar' => $this->podeRenovar,
            'podeRecusar' => $this->podeRecusar,
            'textoParaCopiar' => $this->textoParaCopiar(),
        ];
    }

    /** @return array{pedidaEm: string, nome: ?string, mensagem: ?string, resposta: ?string, pendente: bool}|null */
    private static function renovacao(LinkExternoDoPush $link): ?array
    {
        $pedidaEm = $link->getRenovacaoPedidaEm();
        if ($pedidaEm === null) {
            return null;
        }

        return [
            'pedidaEm' => self::dataHora($pedidaEm),
            'nome' => $link->getRenovacaoNome(),
            'mensagem' => $link->getRenovacaoMensagem(),
            'resposta' => $link->getRenovacaoResposta(),
            'pendente' => $link->temPedidoPendente(),
        ];
    }

    /**
     * @param array{total: int, ultimo: ?\DateTimeImmutable, porDia: array<string, int>, ultimos: list<\DateTimeImmutable>} $estatisticas
     *
     * @return array{total: int, ultimo: ?string, porDia: list<array{dia: string, total: int}>, ultimos: list<string>}
     */
    private static function acessos(array $estatisticas): array
    {
        $porDia = [];
        foreach ($estatisticas['porDia'] as $dia => $total) {
            $porDia[] = ['dia' => (new \DateTimeImmutable($dia))->format('d/m/Y'), 'total' => $total];
        }

        return [
            'total' => $estatisticas['total'],
            'ultimo' => $estatisticas['ultimo'] !== null ? self::dataHora($estatisticas['ultimo']) : null,
            'porDia' => $porDia,
            'ultimos' => array_map(self::dataHora(...), $estatisticas['ultimos']),
        ];
    }

    private static function dataHora(\DateTimeImmutable $quando): string
    {
        return $quando->format('d/m/Y H:i');
    }
}
