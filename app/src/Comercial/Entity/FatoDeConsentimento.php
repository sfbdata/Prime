<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Prova do consentimento, só de acréscimo (spec §3.3, B1): cada linha é um fato (`pedido` ou `confirmado`), com o
 * texto e a versão que a pessoa viu. Nunca se atualiza nem se apaga dentro do prazo (5 anos depois do término,
 * Política L379). `tenant_id` é um inteiro SEM FK: a prova sobrevive à purga do escritório (TABELAS_RETIDAS).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_consentimento')]
#[ORM\Index(name: 'idx_comercial_consentimento_endereco', columns: ['lista', 'email'])]
#[ORM\Index(name: 'idx_comercial_consentimento_tenant', columns: ['tenant_id'])]
class FatoDeConsentimento
{
    public const PEDIDO = 'pedido';
    public const CONFIRMADO = 'confirmado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'tenant_id', type: 'integer', nullable: true)]
    private ?int $tenantId = null;

    #[ORM\Column(length: 20)]
    private string $fato = '';

    #[ORM\Column(length: 40)]
    private string $lista = '';

    #[ORM\Column(length: 180)]
    private string $email = '';

    #[ORM\Column(length: 60)]
    private string $origem = '';

    #[ORM\Column(name: 'versao_consentimento', length: 40)]
    private string $versaoConsentimento = '';

    #[ORM\Column(name: 'texto_consentimento', type: 'text')]
    private string $textoConsentimento = '';

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ip = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agente = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $em;

    public function __construct()
    {
        $this->em = new \DateTimeImmutable();
    }
}
