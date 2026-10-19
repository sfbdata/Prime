<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\AjusteEntradaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um ajuste da tela de entrada feito pelo Master nas ferramentas SÓ MASTER do desenho 10: o endereço e a
 * visibilidade de um link do rodapé (`link.termos`, `link.priv`, `link.linkedin`, `link.instagram`) ou quem recebe
 * as mensagens de suporte (`suporte.destinatario`, valor = id do User). Sem linha = o padrão do desenho; "Restaurar"
 * apaga a linha.
 *
 * NÃO é multi-tenant: a tela de entrada é uma só, anterior a qualquer escritório.
 */
#[ORM\Entity(repositoryClass: AjusteEntradaRepository::class)]
#[ORM\Table(name: 'ajuste_entrada')]
class AjusteEntrada
{
    public const SUPORTE_DESTINATARIO = 'suporte.destinatario';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $valor = null;

    #[ORM\Column(type: 'boolean')]
    private bool $oculto = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        private string $chave,
    ) {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    public static function chaveDoLink(string $link): string
    {
        return 'link.' . $link;
    }

    public function getChave(): string { return $this->chave; }

    public function getValor(): ?string { return $this->valor; }

    public function isOculto(): bool { return $this->oculto; }

    public function getAlteradoEm(): \DateTimeImmutable { return $this->alteradoEm; }

    public function getAlteradoPor(): ?User { return $this->alteradoPor; }

    public function definirValor(?string $valor, User $por, \DateTimeImmutable $agora): void
    {
        $this->valor = $valor;
        $this->registrarAlteracao($por, $agora);
    }

    public function definirOculto(bool $oculto, User $por, \DateTimeImmutable $agora): void
    {
        $this->oculto = $oculto;
        $this->registrarAlteracao($por, $agora);
    }

    private function registrarAlteracao(User $por, \DateTimeImmutable $agora): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $agora;
    }
}
