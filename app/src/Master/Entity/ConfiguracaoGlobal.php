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
 *
 * Com o prefixo `convenio.` mora a chave geral do Convênio OAB (pedido do Farlei M2.1, 24/11/2026): o Master a liga e
 * desliga em Planos e limites; desligada, a faixa, a tag do Free e os selos do Contribua somem. A cobrança não muda.
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
    private const PREFIXO_CONVENIO = 'convenio.';
    /** A chave geral do Convênio OAB (M2.1). O nome é o ponto de partida da spec docs/specs/convenios.md: não renomear. */
    private const CONVENIO_ATIVO = self::PREFIXO_CONVENIO . 'ativo';
    /** Quando a primeira sincronização completa dos vínculos terminou: daí em diante o convênio OAB não vale mais implícito. */
    private const CONVENIO_VINCULOS_SINCRONIZADOS_EM = self::PREFIXO_CONVENIO . 'vinculosSincronizadosEm';
    /** O teto mensal (centavos de dólar) que a I.A. ligada pelo dono recebe (spec multiproduto, §9, regra 3, e §10). */
    private const TETO_PADRAO_DA_IA = self::PREFIXO_IA . 'tetoPadraoCentavos';
    /** Chaves com estes prefixos não são campos do catálogo: a tela de configurações não as mostra nem as apaga. */
    private const PREFIXOS_FORA_DO_CATALOGO = [self::PREFIXO_REVISAO_OAB, self::PREFIXO_COMERCIAL, self::PREFIXO_IA, self::PREFIXO_CONVENIO];

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

    /**
     * Liga ou desliga o Convênio OAB na plataforma inteira. Registra quem e quando, como a chave das campanhas: a troca
     * entra na Trilha de Auditoria pelo AuditLogSubscriber.
     */
    public function definirConvenioAtivo(bool $ativo, ?User $por, \DateTimeImmutable $em): void
    {
        $this->valores[self::CONVENIO_ATIVO] = $ativo ? '1' : '0';
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    /** Null: ninguém mexeu na chave ainda (vale o padrão, ligado, que é como o sistema sempre foi). */
    public function getConvenioAtivo(): ?bool
    {
        $valor = $this->valores[self::CONVENIO_ATIVO] ?? null;

        return $valor === null ? null : $valor === '1';
    }

    /**
     * A sincronização diária dos vínculos de convênio terminou (spec docs/specs/convenios.md, §3.4, decisão de 24/11). Ato
     * do sistema: não muda quem alterou por último.
     */
    public function marcarVinculosDeConvenioSincronizados(\DateTimeImmutable $em): void
    {
        $this->valores[self::CONVENIO_VINCULOS_SINCRONIZADOS_EM] = $em->format(\DateTimeInterface::ATOM);
    }

    /** Null: a primeira sincronização completa ainda não rodou (o convênio OAB vale implícito). */
    public function getVinculosDeConvenioSincronizadosEm(): ?\DateTimeImmutable
    {
        $valor = $this->valores[self::CONVENIO_VINCULOS_SINCRONIZADOS_EM] ?? null;

        return $valor === null ? null : new \DateTimeImmutable($valor);
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
     * Grava o teto mensal padrão (centavos de dólar) da I.A. ligada pelo dono (spec multiproduto, §10). Registra quem e
     * quando, como a chave das campanhas. A faixa é conferida por quem chama; não mexe nos tetos já gravados por escritório.
     */
    public function definirTetoPadraoDaIa(int $centavos, ?User $por, \DateTimeImmutable $em): void
    {
        $this->valores[self::TETO_PADRAO_DA_IA] = (string) $centavos;
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    /** Null: o Master ainda não mexeu (vale a reserva do PR 8). */
    public function getTetoPadraoDaIa(): ?int
    {
        $valor = $this->valores[self::TETO_PADRAO_DA_IA] ?? null;

        return \is_string($valor) && ctype_digit($valor) ? (int) $valor : null;
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
