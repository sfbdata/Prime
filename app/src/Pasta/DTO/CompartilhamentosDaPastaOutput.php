<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\PastaCompartilhamento;

/**
 * O que a janela "Compartilhar pasta" (desenho L3 e L4) e a seção COMPARTILHAR do menu (L6) mostram. O texto das
 * permissões e do estado é montado na tela, pelo catálogo, a partir das caixas e do `estado`.
 */
final readonly class CompartilhamentosDaPastaOutput
{
    /**
     * @param list<array{id: int, nome: string}> $equipe
     * @param list<array<string, mixed>>         $colaboradores
     * @param list<array{id: int, nome: string}> $recentes
     */
    public function __construct(
        public int $pastaId,
        public bool $podeCompartilhar,
        public array $equipe,
        public array $colaboradores,
        public array $recentes,
    ) {
    }

    /** @return array<string, mixed> */
    public static function colaborador(PastaCompartilhamento $c, \DateTimeImmutable $hoje, bool $podeEncerrar, bool $meu): array
    {
        $permissoes = $c->permissoes();

        return [
            'id'               => (int) $c->getId(),
            'destinoId'        => (int) $c->getDestino()->getId(),
            'nome'             => self::nome($c->getDestino()),
            'interno'          => $c->isInterno(),
            'movimentar'       => $permissoes->movimentar,
            'enviarDocumentos' => $permissoes->enviarDocumentos,
            'baixarDocumentos' => $permissoes->baixarDocumentos,
            'desde'            => $c->getCriadoEm()->format('Y-m-d'),
            'ate'              => $c->getValidoAte()?->format('Y-m-d'),
            'estado'           => self::estado($c, $hoje),
            'ativo'            => $c->estaAtivo($hoje),
            'podeEncerrar'     => $podeEncerrar && $c->estaAtivo($hoje),
            'meu'              => $meu,
        ];
    }

    /** @return array{id: int, nome: string} */
    public static function pessoa(User $u): array
    {
        return ['id' => (int) $u->getId(), 'nome' => self::nome($u)];
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'pastaId'          => $this->pastaId,
            'podeCompartilhar' => $this->podeCompartilhar,
            'equipe'           => $this->equipe,
            'colaboradores'    => $this->colaboradores,
            'recentes'         => $this->recentes,
        ];
    }

    /** `colaborando`, `aguardando`, `expirada` ou `encerrada` (os quatro estados do desenho). */
    private static function estado(PastaCompartilhamento $c, \DateTimeImmutable $hoje): string
    {
        return match (true) {
            $c->getStatus() === EstadoDoCompartilhamento::Encerrada => 'encerrada',
            $c->getStatus() === EstadoDoCompartilhamento::Aceita && !$c->estaAtivo($hoje) => 'expirada',
            $c->getStatus() === EstadoDoCompartilhamento::Pendente => 'aguardando',
            default => 'colaborando',
        };
    }

    private static function nome(User $u): string
    {
        return (string) ($u->getFullName() ?: $u->getEmail());
    }
}
