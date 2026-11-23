<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configurações globais do sistema (seção "Configurações globais" do /master; spec
 * docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P2). Linha ÚNICA (id 1), escrita só pelo Master.
 *
 * GLOBAL, sem tenant, de propósito: vale para a plataforma inteira, como o {@see TemaConfig}. Guarda só os campos
 * que não têm outra fonte: o espaço do Plano Free e o aviso de espaço moram em Plano e ConfiguracaoDoEspaco.
 * Sem a linha, valem os padrões do {@see \App\Master\Service\CatalogoDeConfiguracoes}.
 * Auditável: o AuditLogSubscriber grava cada mudança, no escopo de plataforma.
 *
 * Na mesma linha, com prefixo próprio, mora a validação automática da Revisão de OAB (desenho 05 - Perfis 1.2 de
 * 31/10). Ela não é campo do catálogo: {@see getValores()} não a devolve e {@see definirValores()} a preserva, para
 * que salvar a tela de configurações não a apague nem a mostre.
 *
 * Do mesmo jeito, com o prefixo `comercial.`, mora a chave do envio de campanhas (pedido do Farlei M8.1, 23/11/2026): a
 * Central de envios a liga e desliga, e cada troca entra na Trilha de Auditoria pelo AuditLogSubscriber.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoGlobalRepository::class)]
#[ORM\Table(name: 'configuracao_global')]
#[ORM\Index(name: 'idx_configuracao_global_alterado_por', columns: ['alterado_por_id'])]
class ConfiguracaoGlobal implements Auditavel
{
    public const ID_UNICO = 1;
    private const PREFIXO_REVISAO_OAB = 'revisaoOab.';
    private const PREFIXO_COMERCIAL = 'comercial.';
    private const ENVIO_DE_CAMPANHAS = self::PREFIXO_COMERCIAL . 'campanhasLigadas';
    private const PREFIXO_IA = 'ia.';
    /** O teto mensal (centavos de dólar) que a I.A. ligada pelo dono recebe (spec multiproduto, §9, regra 3, e §10). */
    private const TETO_PADRAO_DA_IA = self::PREFIXO_IA . 'tetoPadraoCentavos';
    /** Chaves com estes prefixos não são campos do catálogo: a tela de configurações não as mostra nem as apaga. */
    private const PREFIXOS_FORA_DO_CATALOGO = [self::PREFIXO_REVISAO_OAB, self::PREFIXO_COMERCIAL, self::PREFIXO_IA];

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    /** @var array<string, string> chave do catálogo => valor já normalizado */
    #[ORM\Column(type: 'json')]
    private array $valores = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct()
    {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    /** @param array<string, string> $valores já validados pelo catálogo */
    public function definirValores(array $valores, ?User $por, \DateTimeImmutable $em): void
    {
        $this->valores = $valores + $this->foraDoCatalogo();
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    /** @return array<string, string> */
    public function getValores(): array
    {
        return array_diff_key($this->valores, $this->foraDoCatalogo());
    }

    /**
     * Liga ou desliga o envio de campanhas. Registra quem e quando, como a tela de configurações: o estado mostrado na
     * Central de envios e a trilha dizem quem mexeu por último.
     */
    public function definirEnvioDeCampanhas(bool $ligado, ?User $por, \DateTimeImmutable $em): void
    {
        $this->valores[self::ENVIO_DE_CAMPANHAS] = $ligado ? '1' : '0';
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    /** Null: ninguém mexeu na chave pelo painel ainda (vale o padrão do ambiente). */
    public function getEnvioDeCampanhas(): ?bool
    {
        $valor = $this->valores[self::ENVIO_DE_CAMPANHAS] ?? null;

        return $valor === null ? null : $valor === '1';
    }

    /** O nome da chave dentro do JSON `valores`, para a leitura direta do banco (o worker não guarda a entidade). */
    public static function chaveDoEnvioDeCampanhas(): string
    {
        return self::ENVIO_DE_CAMPANHAS;
    }

    /** O nome da chave do teto padrão da I.A. dentro do JSON `valores`, para a leitura direta do banco. */
    public static function chaveDoTetoPadraoDaIa(): string
    {
        return self::TETO_PADRAO_DA_IA;
    }

    /**
     * Grava a configuração da Revisão de OAB sem mexer nos campos do catálogo nem em alteradoPor/alteradoEm (que são
     * da tela de configurações).
     *
     * @param array<string, string> $valores chave curta (sem prefixo) => valor
     */
    public function definirRevisaoOab(array $valores): void
    {
        foreach ($valores as $chave => $valor) {
            $this->valores[self::PREFIXO_REVISAO_OAB . $chave] = $valor;
        }
    }

    /** @return array<string, string> chave curta (ou com prefixo, para preservar) => valor */
    public function getRevisaoOab(bool $comPrefixo = false): array
    {
        $saida = [];
        foreach ($this->valores as $chave => $valor) {
            if (str_starts_with($chave, self::PREFIXO_REVISAO_OAB)) {
                $saida[$comPrefixo ? $chave : substr($chave, \strlen(self::PREFIXO_REVISAO_OAB))] = $valor;
            }
        }

        return $saida;
    }

    /** @return array<string, string> as chaves fora do catálogo, com o prefixo, para preservar ao salvar o catálogo */
    private function foraDoCatalogo(): array
    {
        return array_filter($this->valores, static function (string $chave): bool {
            foreach (self::PREFIXOS_FORA_DO_CATALOGO as $prefixo) {
                if (str_starts_with($chave, $prefixo)) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
