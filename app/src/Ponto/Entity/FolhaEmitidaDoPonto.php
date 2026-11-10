<?php

declare(strict_types=1);

namespace App\Ponto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma folha do mês emitida em PDF com código `BJF-XXXX-XXXX-XXXX` (spec ponto-desenho-0811, E16, P34, D17; decisões 2,
 * 3 e 18). Cada PDF gerado é uma emissão nova, com código próprio: nada é reaproveitado (decisão 2).
 *
 * Guarda o que a validação pública confere: os dados assinados (JSON canônico, {@see \App\Ponto\DTO\DadosDaFolhaEmitida})
 * e o HMAC deles, com a versão da chave que selou. A `impressao_registros` é o sha256 do resumo dia a dia e dos totais
 * impressos (decisão 3): é ela que diz se a folha foi "alterada depois do aceite", comparando a emissão vista no aceite
 * com a folha de agora.
 *
 * A pessoa da folha e o escritório ficam sem cascata, como em `ponto_comprovante` e `registro_ponto` (a purga do
 * escritório apaga a linha explicitamente; a conta da pessoa nunca é apagada). Quem emitiu pode ser a própria pessoa
 * ou o gestor (P89); a ligação fica nula se esse usuário sair (SET NULL), e o nome dele continua nos dados assinados.
 * Sem o segredo do HMAC, nenhuma linha é gravada.
 */
#[ORM\Entity(repositoryClass: FolhaEmitidaDoPontoRepository::class)]
#[ORM\Table(name: 'ponto_folha_emitida')]
#[ORM\Index(name: 'idx_ponto_folha_emitida_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ponto_folha_emitida_competencia', columns: ['tenant_id', 'user_id', 'ano', 'mes'])]
#[ORM\Index(name: 'idx_ponto_folha_emitida_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_ponto_folha_emitida_emitida_por', columns: ['emitida_por_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ponto_folha_emitida_codigo', columns: ['codigo'])]
class FolhaEmitidaDoPonto implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** @throws \InvalidArgumentException competência fora do calendário ou impressão que não é um sha256 */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        /** A pessoa da folha (sem cascata, como `registro_ponto.user_id`). */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private User $user,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'emitida_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $emitidaPor,
        #[ORM\Column(type: 'smallint')]
        private int $ano,
        #[ORM\Column(type: 'smallint')]
        private int $mes,
        #[ORM\Column(length: 18)]
        private string $codigo,
        #[ORM\Column(length: 64)]
        private string $hmac,
        #[ORM\Column(name: 'versao_chave', type: 'integer')]
        private int $versaoChave,
        #[ORM\Column(name: 'emitida_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $emitidaEm,
        #[ORM\Column(name: 'impressao_registros', length: 64)]
        private string $impressaoRegistros,
        #[ORM\Column(name: 'dados_assinados', type: 'text')]
        private string $dadosAssinados,
    ) {
        self::exigirCompetencia($ano, $mes);
        if (preg_match('/^[0-9a-f]{64}$/', $impressaoRegistros) !== 1) {
            throw new \InvalidArgumentException('A impressão dos registros da folha é um sha256 em hexadecimal.');
        }
    }

    /** @throws \InvalidArgumentException mês fora de 1 a 12 ou ano sem quatro dígitos */
    public static function exigirCompetencia(int $ano, int $mes): void
    {
        if ($mes < 1 || $mes > 12 || $ano < 1000 || $ano > 9999) {
            throw new \InvalidArgumentException(sprintf('Competência inválida: %d/%d.', $mes, $ano));
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

    /** Null se o usuário que emitiu sair (o nome dele continua nos dados assinados). */
    public function getEmitidaPor(): ?User
    {
        return $this->emitidaPor;
    }

    public function getAno(): int
    {
        return $this->ano;
    }

    public function getMes(): int
    {
        return $this->mes;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getHmac(): string
    {
        return $this->hmac;
    }

    public function getVersaoChave(): int
    {
        return $this->versaoChave;
    }

    public function getEmitidaEm(): \DateTimeImmutable
    {
        return $this->emitidaEm;
    }

    public function getImpressaoRegistros(): string
    {
        return $this->impressaoRegistros;
    }

    public function getDadosAssinados(): string
    {
        return $this->dadosAssinados;
    }
}
