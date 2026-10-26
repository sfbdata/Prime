<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Convênio OAB de um estado quando ele NÃO é gratuito (desenho 05 Perfis 1.2 VS 25.10.26, `bj-convenio-uf`; spec
 * docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P4b). Sem linha para a UF, o convênio está ativo e grátis:
 * "Convênio ativo, grátis" apaga a linha.
 *
 * Dois modos: `dif` (convênio ativo com valor diferenciado por mês) e `sem` (convênio encerrado ou não renovado, vale a
 * mensalidade informada). GLOBAL, sem tenant, de propósito: é configuração da PLATAFORMA, mexida só pelo Master, como o
 * {@see TemaConfig}. A chave é a própria sigla da UF. Auditável: o AuditLogSubscriber grava cada mudança no escopo de
 * plataforma. Este registro ainda não muda cobrança nem envia e-mail (frentes próprias).
 */
#[ORM\Entity(repositoryClass: ConvenioDaUfRepository::class)]
#[ORM\Table(name: 'convenio_uf')]
#[ORM\Index(name: 'idx_convenio_uf_alterado_por', columns: ['alterado_por_id'])]
class ConvenioDaUf implements Auditavel
{
    public const MODO_DIFERENCIADO = 'dif';
    public const MODO_SEM = 'sem';

    public const VALOR_MAXIMO = 999999.99;
    public const TAMANHO_MAXIMO_DO_MOTIVO = 200;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 2)]
    private string $uf;

    #[ORM\Column(length: 3)]
    private string $modo;

    /** Reais por mês, com centavos. */
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $valor;

    /** A partir de quando vale. */
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $desde;

    #[ORM\Column(length: self::TAMANHO_MAXIMO_DO_MOTIVO, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    public function __construct(string $uf, string $modo, float $valor, \DateTimeImmutable $desde, ?string $motivo, ?User $por, \DateTimeImmutable $em)
    {
        $this->uf = self::exigirUf($uf);
        $this->definir($modo, $valor, $desde, $motivo, $por, $em);
    }

    /** Muda o modo, o valor, a data e o motivo de um convênio que já tem linha. */
    public function definir(string $modo, float $valor, \DateTimeImmutable $desde, ?string $motivo, ?User $por, \DateTimeImmutable $em): void
    {
        if (!in_array($modo, [self::MODO_DIFERENCIADO, self::MODO_SEM], true)) {
            throw new ConvenioRecusado('Modo de convênio inválido.');
        }
        if ($valor < 0 || $valor > self::VALOR_MAXIMO) {
            throw new ConvenioRecusado('Valor inválido.');
        }

        $motivo = $motivo === null ? '' : trim($motivo);
        $this->modo = $modo;
        $this->valor = number_format($valor, 2, '.', '');
        $this->desde = $desde;
        $this->motivo = $modo === self::MODO_SEM && $motivo !== '' ? mb_substr($motivo, 0, self::TAMANHO_MAXIMO_DO_MOTIVO) : null;
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    public static function exigirUf(string $uf): string
    {
        $uf = strtoupper(trim($uf));
        if (LocalizacaoDaPessoa::uf($uf) === null) {
            throw new ConvenioRecusado('Estado desconhecido.');
        }

        return $uf;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    public function getValor(): float
    {
        return (float) $this->valor;
    }

    public function getDesde(): \DateTimeImmutable
    {
        return $this->desde;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getId(): string
    {
        return $this->uf;
    }
}
