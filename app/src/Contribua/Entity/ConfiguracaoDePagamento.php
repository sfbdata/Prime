<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Repository\ConfiguracaoDePagamentoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * O modal "Pagamento por cartão · só Master" do desenho 08: qual gateway, a chave pública, o link de
 * assinatura de cada pacote e a carência da inadimplência (D3, 7 dias por padrão).
 *
 * Linha única (id 1, criada pela migration). Sem gateway escolhido, o "Quero +N GB" continua sendo a
 * lista de espera da C2. A chave SECRETA nunca mora aqui: fica no ambiente (`CONTRIBUA_<GATEWAY>_*`).
 * A chave pública é pública por definição (vai para o navegador no checkout).
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDePagamentoRepository::class)]
#[ORM\Table(name: 'contribua_configuracao_pagamento')]
#[ORM\HasLifecycleCallbacks]
class ConfiguracaoDePagamento implements Auditavel
{
    public const ID_UNICO = 1;
    public const CARENCIA_PADRAO = 7;
    private const CARENCIA_MAXIMA = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $gateway = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chavePublica = null;

    /** @var array<string, string> código do plano → link de assinatura no gateway */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $linksDosPacotes = [];

    #[ORM\Column(type: 'smallint', options: ['default' => self::CARENCIA_PADRAO])]
    private int $diasDeCarencia = self::CARENCIA_PADRAO;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** @param array<string, string> $linksDosPacotes */
    public function configurar(?string $gateway, ?string $chavePublica, array $linksDosPacotes, int $diasDeCarencia): void
    {
        if ($diasDeCarencia < 0 || $diasDeCarencia > self::CARENCIA_MAXIMA) {
            throw new \DomainException(sprintf('A carência vai de 0 a %d dias.', self::CARENCIA_MAXIMA));
        }
        foreach ($linksDosPacotes as $link) {
            if (preg_match('#^https://\S+\.\S+#', $link) !== 1) {
                throw new \DomainException('O link do pacote precisa começar com https://.');
            }
        }

        $this->gateway = $gateway;
        $this->chavePublica = $chavePublica;
        $this->linksDosPacotes = $linksDosPacotes;
        $this->diasDeCarencia = $diasDeCarencia;
    }

    public function getGateway(): ?string
    {
        return $this->gateway;
    }

    public function getChavePublica(): ?string
    {
        return $this->chavePublica;
    }

    /** @return array<string, string> */
    public function getLinksDosPacotes(): array
    {
        return $this->linksDosPacotes;
    }

    public function linkDoPacote(string $codigoDoPlano): ?string
    {
        return $this->linksDosPacotes[$codigoDoPlano] ?? null;
    }

    public function getDiasDeCarencia(): int
    {
        return $this->diasDeCarencia;
    }
}
