<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * O aceite mensal da folha (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 14 e 19): a pessoa concorda com os
 * registros do mês fechado ou assina com ressalva, sobre uma folha emitida com código (a "folha emitida vista").
 *
 * Histórico numerado (decisão 1): cada nova assinatura do mesmo mês ganha o número seguinte (1, 2, 3...) por pessoa,
 * escritório e mês, nada é apagado, e vale o último. O índice único sobre os cinco campos segura dois cliques ao mesmo
 * tempo.
 *
 * O escritório, a pessoa e a competência saem da folha vista: o aceite nunca fica preso a uma folha de outra pessoa,
 * de outro escritório ou de outro mês. O próprio aceite é selado (decisão 14, chave `ponto-folha`) sobre a situação, a
 * ressalva, a data, o IP, o navegador e o código da folha vista; a trilha não guarda o texto da ressalva. A entidade
 * guarda os valores como chegam (sem cortar nem aparar), para que o selo feito antes confira com o que foi gravado:
 * quem grava corta o navegador e apara a ressalva antes de selar.
 *
 * Escritório e pessoa sem cascata, como em `ponto_comprovante`; a folha vista também (a purga apaga o aceite antes da
 * folha emitida). IP e navegador são dado pessoal guardado como prova do aceite e saem com a purga do escritório.
 */
#[ORM\Entity(repositoryClass: AceiteDaFolhaDoPontoRepository::class)]
#[ORM\Table(name: 'ponto_folha_aceite')]
#[ORM\Index(name: 'idx_ponto_folha_aceite_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ponto_folha_aceite_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_ponto_folha_aceite_folha', columns: ['folha_emitida_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ponto_folha_aceite_numero', columns: ['tenant_id', 'user_id', 'ano', 'mes', 'numero'])]
class AceiteDaFolhaDoPonto implements TenantAware
{
    /** Ressalva com até 1.000 caracteres (decisão 19). */
    public const LIMITE_DA_RESSALVA = 1000;
    public const LIMITE_DO_IP = 45;
    public const LIMITE_DO_NAVEGADOR = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private Tenant $tenant;

    /** Quem assinou: a pessoa da folha vista (sem cascata, como `registro_ponto.user_id`). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\Column(type: 'smallint')]
    private int $ano;

    #[ORM\Column(type: 'smallint')]
    private int $mes;

    /**
     * @throws \DomainException          ressalva sem texto, ressalva longa demais ou texto de ressalva em quem concorda
     * @throws \InvalidArgumentException número menor que 1, folha vista sem escritório, IP ou navegador longos demais
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: FolhaEmitidaDoPonto::class)]
        #[ORM\JoinColumn(name: 'folha_emitida_id', nullable: false)]
        private FolhaEmitidaDoPonto $folhaVista,
        #[ORM\Column(type: 'integer')]
        private int $numero,
        #[ORM\Column(length: 10, enumType: SituacaoDoAceiteDaFolha::class)]
        private SituacaoDoAceiteDaFolha $situacao,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $ressalva,
        #[ORM\Column(name: 'aceito_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $aceitoEm,
        #[ORM\Column(length: self::LIMITE_DO_IP, nullable: true)]
        private ?string $ip,
        #[ORM\Column(length: self::LIMITE_DO_NAVEGADOR, nullable: true)]
        private ?string $navegador,
        #[ORM\Column(length: 64)]
        private string $hmac,
        #[ORM\Column(name: 'versao_chave', type: 'integer')]
        private int $versaoChave,
    ) {
        if ($numero < 1) {
            throw new \InvalidArgumentException('O número do aceite começa em 1.');
        }
        self::exigirRessalvaCoerente($situacao, $ressalva);
        self::exigirTamanho($ip, self::LIMITE_DO_IP, 'O IP');
        self::exigirTamanho($navegador, self::LIMITE_DO_NAVEGADOR, 'O navegador');

        $this->tenant = $folhaVista->getTenant() ?? throw new \InvalidArgumentException('A folha vista não tem escritório.');
        $this->user = $folhaVista->getUser();
        $this->ano = $folhaVista->getAno();
        $this->mes = $folhaVista->getMes();
    }

    /**
     * Ressalva obrigatória (não vazia depois de aparar) e com até 1.000 caracteres quando a pessoa assina com ressalva;
     * nula quando concorda (decisão 19).
     *
     * @throws \DomainException
     */
    public static function exigirRessalvaCoerente(SituacaoDoAceiteDaFolha $situacao, ?string $ressalva): void
    {
        if ($situacao === SituacaoDoAceiteDaFolha::Concordo) {
            if ($ressalva !== null) {
                throw new \DomainException('Quem concorda com a folha não registra ressalva.');
            }

            return;
        }
        if ($ressalva === null || trim($ressalva) === '') {
            throw new \DomainException('Descreva a ressalva.');
        }
        if (mb_strlen($ressalva) > self::LIMITE_DA_RESSALVA) {
            throw new \DomainException('A ressalva pode ter até 1.000 caracteres.');
        }
    }

    private static function exigirTamanho(?string $valor, int $limite, string $campo): void
    {
        if ($valor !== null && mb_strlen($valor) > $limite) {
            throw new \InvalidArgumentException(sprintf('%s do aceite passa de %d caracteres.', $campo, $limite));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAno(): int
    {
        return $this->ano;
    }

    public function getMes(): int
    {
        return $this->mes;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getSituacao(): SituacaoDoAceiteDaFolha
    {
        return $this->situacao;
    }

    /** O texto da ressalva; null quando a pessoa concordou. */
    public function getRessalva(): ?string
    {
        return $this->ressalva;
    }

    public function getAceitoEm(): \DateTimeImmutable
    {
        return $this->aceitoEm;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function getNavegador(): ?string
    {
        return $this->navegador;
    }

    public function getFolhaVista(): FolhaEmitidaDoPonto
    {
        return $this->folhaVista;
    }

    public function getHmac(): string
    {
        return $this->hmac;
    }

    public function getVersaoChave(): int
    {
        return $this->versaoChave;
    }
}
