<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuração global do espaço: as quatro faixas do desenho 05 e a chave do bloqueio.
 *
 * Linha única (id fixo 1, criada pela migration). Faixas em % de uso:
 * Atenção 70 · Alerta 90 · Crítico 95 · Bloqueio 100. O aviso do desenho 08 (`bj-plano.js`,
 * "a partir de 90%") é a faixa Alerta; o bloqueio de envio começa na faixa Bloqueio (100 = no
 * próprio limite; o Master pode antecipá-lo, como o desenho 05 permite).
 *
 * `bloqueioAtivo` é convenção, sem desenho: nasce DESLIGADO para que o deploy não pause os envios
 * de quem já passa da franquia hoje. O Master liga quando quiser.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDoEspacoRepository::class)]
#[ORM\Table(name: 'contribua_configuracao')]
#[ORM\HasLifecycleCallbacks]
class ConfiguracaoDoEspaco implements Auditavel
{
    public const ID_UNICO = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(type: 'smallint', options: ['default' => 70])]
    private int $faixaAtencao = 70;

    #[ORM\Column(type: 'smallint', options: ['default' => 90])]
    private int $faixaAlerta = 90;

    #[ORM\Column(type: 'smallint', options: ['default' => 95])]
    private int $faixaCritico = 95;

    #[ORM\Column(type: 'smallint', options: ['default' => 100])]
    private int $faixaBloqueio = 100;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $bloqueioAtivo = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** Faixas em ordem estrita e dentro de 1..100: senão uma faixa engoliria a seguinte. */
    public function definirFaixas(int $atencao, int $alerta, int $critico, int $bloqueio): void
    {
        if (!(0 < $atencao && $atencao < $alerta && $alerta < $critico && $critico < $bloqueio && $bloqueio <= 100)) {
            throw new \DomainException('As faixas precisam crescer em ordem, de 1% a 100%.');
        }

        [$this->faixaAtencao, $this->faixaAlerta, $this->faixaCritico, $this->faixaBloqueio]
            = [$atencao, $alerta, $critico, $bloqueio];
    }

    public function ligarBloqueio(bool $ligado): void
    {
        $this->bloqueioAtivo = $ligado;
    }

    public function getFaixaAtencao(): int
    {
        return $this->faixaAtencao;
    }

    public function getFaixaAlerta(): int
    {
        return $this->faixaAlerta;
    }

    public function getFaixaCritico(): int
    {
        return $this->faixaCritico;
    }

    public function getFaixaBloqueio(): int
    {
        return $this->faixaBloqueio;
    }

    public function isBloqueioAtivo(): bool
    {
        return $this->bloqueioAtivo;
    }
}
