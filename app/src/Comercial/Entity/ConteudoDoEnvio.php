<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * O corpo e os cabeçalhos de um envio enquanto ele não chega a um estado final (spec §3.1, N1). Apagado depois do
 * envio, da falha definitiva ou do bloqueio: o corpo pode levar credencial (o link de confirmação do `lemb`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_envio_conteudo')]
class ConteudoDoEnvio
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: EnvioDeEmail::class)]
    #[ORM\JoinColumn(name: 'envio_id', nullable: false, onDelete: 'CASCADE')]
    private EnvioDeEmail $envio;

    #[ORM\Column(name: 'corpo_html', type: 'text')]
    private string $corpoHtml = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $selo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $titulo = null;

    #[ORM\Column(name: 'reply_to', length: 180, nullable: true)]
    private ?string $replyTo = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $cc = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $rastrear = false;

    #[ORM\Column(name: 'nome_do_remetente', length: 120, nullable: true)]
    private ?string $nomeDoRemetente = null;

    public function __construct(EnvioDeEmail $envio)
    {
        $this->envio = $envio;
    }
}
