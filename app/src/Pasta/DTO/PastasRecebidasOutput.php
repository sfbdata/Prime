<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\PastaCompartilhamento;

/**
 * A janela "Pastas recebidas de outras contas" e o botão "Recebidas" (desenho L9 e L10): o código da pessoa, quantos
 * convites a aguardam e um cartão por convite. O texto das permissões e do estado é montado na tela, pelo catálogo.
 */
final readonly class PastasRecebidasOutput
{
    /** @param list<array<string, mixed>> $itens */
    public function __construct(
        public string $meuCodigo,
        public int $pendentes,
        public array $itens,
    ) {
    }

    /** @return array<string, mixed> */
    public static function item(PastaCompartilhamento $c, \DateTimeImmutable $hoje): array
    {
        $permissoes = $c->permissoes();
        $pasta = $c->getPasta();

        return [
            'id'               => (int) $c->getId(),
            'nup'              => (string) $pasta->getNup(),
            'cliente'          => (string) $pasta->getNomeCliente(),
            'de'               => self::nome($c->getOrigem()),
            'conta'            => (string) $c->getTenant()->getName(),
            'movimentar'       => $permissoes->movimentar,
            'enviarDocumentos' => $permissoes->enviarDocumentos,
            'baixarDocumentos' => $permissoes->baixarDocumentos,
            'mensagem'         => $c->getMensagem(),
            'em'               => $c->getCriadoEm()->format('Y-m-d\TH:i'),
            'ate'              => $c->getValidoAte()?->format('Y-m-d'),
            'estado'           => self::estado($c, $hoje),
        ];
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return ['meuCodigo' => $this->meuCodigo, 'pendentes' => $this->pendentes, 'itens' => $this->itens];
    }

    /** `aguardando`, `colaborando` ou `expirada` (os três estados do cartão no desenho, L10; a caixa só traz pendente e aceito). */
    private static function estado(PastaCompartilhamento $c, \DateTimeImmutable $hoje): string
    {
        return match (true) {
            $c->venceuEm($hoje) => 'expirada',
            $c->getStatus() === EstadoDoCompartilhamento::Pendente => 'aguardando',
            default => 'colaborando',
        };
    }

    private static function nome(User $u): string
    {
        return (string) ($u->getFullName() ?: $u->getEmail());
    }
}
