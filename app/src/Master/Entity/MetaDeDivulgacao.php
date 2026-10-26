<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Entity\Auth\User;
use App\Master\Exception\MetaRecusada;
use App\Master\Fontes\ClienteIbge;
use App\Master\Repository\MetaDeDivulgacaoRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Metas de divulgação do BlueJus (bloco da Visão geral do /master; desenho 05 Perfis 1.2 VS 25.10.26, `bj-metas-div`;
 * spec docs/specs/perfis-1.2-vs-25-10-metas-mapa-config.md, P3). Linha ÚNICA (id 1), escrita só pelo Master.
 *
 * GLOBAL, sem tenant, de propósito: é configuração da PLATAFORMA, como o {@see TemaConfig}. Guarda o mercado (advogados
 * regulares na OAB) e o alvo nacional, a atualização automática, a meta fixa por estado e por cidade e a cor de
 * destaque por estado. O que vale sem meta fixa (meta proporcional, sugestão por cidade) é cálculo da tela.
 * Sem a linha valem os padrões das constantes. Auditável: o AuditLogSubscriber grava cada mudança, no escopo de
 * plataforma.
 */
#[ORM\Entity(repositoryClass: MetaDeDivulgacaoRepository::class)]
#[ORM\Table(name: 'meta_de_divulgacao')]
#[ORM\Index(name: 'idx_meta_de_divulgacao_alterado_por', columns: ['alterado_por_id'])]
class MetaDeDivulgacao implements Auditavel
{
    public const ID_UNICO = 1;

    public const MERCADO_PADRAO = 1510000;
    public const ALVO_PADRAO = 1000;
    public const FONTE_PADRAO = 'Conselho Federal da OAB · Quadro da Advocacia';
    public const REFERENCIA_PADRAO = '2026-10';
    public const LIMITE_NUMERO = 100000000;
    /** Abaixo disto o total do Quadro da Advocacia é lido como tabela incompleta e não vira mercado. */
    public const MERCADO_MINIMO_AUTOMATICO = 100000;

    /** Frequência => dias entre duas atualizações automáticas. */
    public const FREQUENCIAS = ['semanal' => 7, 'mensal' => 30, 'trimestral' => 90];

    /** As seis cores do menu "Destacar" (amarelo, azul, verde, laranja, vermelho, roxo). */
    public const CORES = ['#f1c40f', '#2e86de', '#27ae60', '#e67e22', '#c0392b', '#8e44ad'];

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(type: 'integer', options: ['default' => self::MERCADO_PADRAO])]
    private int $mercado = self::MERCADO_PADRAO;

    #[ORM\Column(type: 'integer', options: ['default' => self::ALVO_PADRAO])]
    private int $alvo = self::ALVO_PADRAO;

    #[ORM\Column(length: 160)]
    private string $fonte = self::FONTE_PADRAO;

    /** Mês da fonte do mercado, "AAAA-MM". */
    #[ORM\Column(length: 7)]
    private string $referencia = self::REFERENCIA_PADRAO;

    #[ORM\Column(options: ['default' => true])]
    private bool $automatica = true;

    #[ORM\Column(length: 12, options: ['default' => 'mensal'])]
    private string $frequencia = 'mensal';

    #[ORM\Column(options: ['default' => true])]
    private bool $avancarDeFase = true;

    /** O mercado foi digitado pelo Master: a atualização automática não o troca. */
    #[ORM\Column(options: ['default' => false])]
    private bool $mercadoManual = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimaAutomatica = null;

    /** @var array<string, string> UF => cor de destaque */
    #[ORM\Column(type: 'json')]
    private array $destaques = [];

    /** @var array<string, int> UF => meta fixa de advogados */
    #[ORM\Column(type: 'json')]
    private array $metasPorUf = [];

    /** @var array<string, array<string, int>> UF => cidade => meta fixa de advogados */
    #[ORM\Column(type: 'json')]
    private array $metasPorCidade = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct()
    {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    /** "Ajustar": o Master digita o mercado e o alvo; o mercado passa a valer como manual. */
    public function ajustar(int $mercado, int $alvo, \DateTimeImmutable $em): void
    {
        $this->mercado = self::numero($mercado, 'mercado');
        $this->alvo = self::numero($alvo, 'meta');
        $this->referencia = $em->format('Y-m');
        $this->mercadoManual = true;
    }

    public function definirAlvo(int $alvo): void
    {
        $this->alvo = self::numero($alvo, 'meta');
    }

    public function ligarAutomatica(bool $ligada): void
    {
        $this->automatica = $ligada;
    }

    public function definirFrequencia(string $frequencia): void
    {
        if (!isset(self::FREQUENCIAS[$frequencia])) {
            throw new MetaRecusada('Frequência inválida.');
        }

        $this->frequencia = $frequencia;
    }

    public function ligarAvancoDeFase(bool $ligado): void
    {
        $this->avancarDeFase = $ligado;
    }

    /** "Voltar a automático": solta o mercado digitado e pede atualização já. */
    public function voltarAoAutomatico(): void
    {
        $this->mercadoManual = false;
        $this->ultimaAutomatica = null;
    }

    /** "Atualizar agora": a próxima leitura atualiza, sem esperar a frequência. */
    public function pedirAtualizacao(): void
    {
        $this->ultimaAutomatica = null;
    }

    /**
     * A atualização está devida? Nunca executada (ou pedida por "Atualizar agora"/"voltar a automático") vale mesmo com
     * a chave desligada; depois, só com a chave ligada e a frequência vencida.
     */
    public function atualizacaoDevida(\DateTimeImmutable $agora): bool
    {
        return $this->ultimaAutomatica === null || ($this->automatica && $agora >= $this->proximaAtualizacao());
    }

    public function proximaAtualizacao(): \DateTimeImmutable
    {
        $base = $this->ultimaAutomatica ?? new \DateTimeImmutable('@0');

        return $base->modify('+' . self::FREQUENCIAS[$this->frequencia] . ' days');
    }

    /**
     * Registra uma atualização automática. `$totalDaOab` é a soma de advogados das 27 UFs na tabela da OAB; só vira
     * mercado se passar do mínimo e se o Master não tiver fixado o valor à mão.
     */
    public function registrarAtualizacaoAutomatica(int $totalDaOab, \DateTimeImmutable $agora): void
    {
        $this->ultimaAutomatica = $agora;
        if ($totalDaOab > self::MERCADO_MINIMO_AUTOMATICO && !$this->mercadoManual) {
            $this->mercado = $totalDaOab;
            $this->referencia = $agora->format('Y-m');
        }
    }

    /** Meta fixa do estado; nulo volta à proporcional. */
    public function definirMetaDaUf(string $uf, ?int $meta): void
    {
        self::exigirUf($uf);
        $metas = $this->metasPorUf;
        if ($meta === null) {
            unset($metas[$uf]);
        } else {
            $metas[$uf] = self::numero($meta, 'meta');
        }
        $this->metasPorUf = $metas;
    }

    /** Meta fixa da cidade; nulo volta à sugerida. */
    public function definirMetaDaCidade(string $uf, string $cidade, ?int $meta): void
    {
        self::exigirUf($uf);
        $cidade = trim($cidade);
        if ($cidade === '' || mb_strlen($cidade) > 120) {
            throw new MetaRecusada('Informe o nome da cidade (até 120 caracteres).');
        }

        $metas = $this->metasPorCidade;
        if ($meta === null) {
            unset($metas[$uf][$cidade]);
            if (($metas[$uf] ?? []) === []) {
                unset($metas[$uf]);
            }
        } else {
            $metas[$uf][$cidade] = self::numero($meta, 'meta');
        }
        $this->metasPorCidade = $metas;
    }

    /** Cor de destaque do estado; nulo remove. */
    public function definirDestaque(string $uf, ?string $cor): void
    {
        self::exigirUf($uf);
        $destaques = $this->destaques;
        if ($cor === null) {
            unset($destaques[$uf]);
        } elseif (in_array($cor, self::CORES, true)) {
            $destaques[$uf] = $cor;
        } else {
            throw new MetaRecusada('Cor de destaque inválida.');
        }
        $this->destaques = $destaques;
    }

    public function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    private static function numero(int $valor, string $rotulo): int
    {
        if ($valor < 1 || $valor > self::LIMITE_NUMERO) {
            throw new MetaRecusada(sprintf('Informe um %s de 1 a %d.', $rotulo, self::LIMITE_NUMERO));
        }

        return $valor;
    }

    private static function exigirUf(string $uf): void
    {
        if (!isset(ClienteIbge::CODIGO_UF[$uf])) {
            throw new MetaRecusada('Estado desconhecido.');
        }
    }

    public function getMercado(): int
    {
        return $this->mercado;
    }

    public function getAlvo(): int
    {
        return $this->alvo;
    }

    public function getFonte(): string
    {
        return $this->fonte;
    }

    public function getReferencia(): string
    {
        return $this->referencia;
    }

    public function isAutomatica(): bool
    {
        return $this->automatica;
    }

    public function getFrequencia(): string
    {
        return $this->frequencia;
    }

    public function isAvancarDeFase(): bool
    {
        return $this->avancarDeFase;
    }

    public function isMercadoManual(): bool
    {
        return $this->mercadoManual;
    }

    public function getUltimaAutomatica(): ?\DateTimeImmutable
    {
        return $this->ultimaAutomatica;
    }

    /** @return array<string, string> */
    public function getDestaques(): array
    {
        return $this->destaques;
    }

    /** @return array<string, int> */
    public function getMetasPorUf(): array
    {
        return $this->metasPorUf;
    }

    /** @return array<string, array<string, int>> */
    public function getMetasPorCidade(): array
    {
        return $this->metasPorCidade;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getId(): int
    {
        return $this->id;
    }
}
