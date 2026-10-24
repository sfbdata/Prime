<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * A BlueJus IA da PLATAFORMA, editada pelo Master na aba "Configurações da I.A." (spec ia-anthropic-ligar §10.1):
 * a chave geral, o modelo e por quantos meses as respostas da IA ficam guardadas (a tarefa de expiração lê daqui). Linha ÚNICA (id 1, criada pela migration), como o {@see \App\Master\Entity\TemaConfig}.
 *
 * GLOBAL, sem tenant, de propósito: não há dado de escritório aqui. A chave geral é o interruptor do dia a dia; o
 * `IA_HABILITADA` do ambiente continua como trava de instalação (desligado no ambiente, nada liga por aqui). A chave
 * da API nunca mora no banco: fica só no `.env.prod`.
 *
 * Auditável: o AuditLogSubscriber grava cada mudança, no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: ConfiguracaoDaPlataformaDeIaRepository::class)]
#[ORM\Table(name: 'inteligencia_plataforma')]
#[ORM\Index(name: 'idx_inteligencia_plataforma_alterada_por', columns: ['alterada_por_id'])]
class ConfiguracaoDaPlataformaDeIa implements Auditavel
{
    public const ID_UNICO = 1;
    public const MESES_DE_RETENCAO_PADRAO = 12;
    public const MESES_DE_RETENCAO_MINIMO = 1;
    public const MESES_DE_RETENCAO_MAXIMO = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ligadaParaTodos = false;

    #[ORM\Column(length: 40, options: ['default' => 'claude-haiku-5-5'])]
    private string $modelo = ModeloDeIa::PADRAO->value;

    /** Respostas e perguntas da IA mais antigas que isto são apagadas pela tarefa agendada (ficam só os custos). */
    #[ORM\Column(type: 'integer', options: ['default' => self::MESES_DE_RETENCAO_PADRAO])]
    private int $mesesDeRetencao = self::MESES_DE_RETENCAO_PADRAO;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradaPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradaEm;

    public function __construct()
    {
        $this->alteradaEm = new \DateTimeImmutable();
    }

    public function ligarParaTodos(User $por, \DateTimeImmutable $agora): void
    {
        $this->ligadaParaTodos = true;
        $this->registrarAlteracao($por, $agora);
    }

    public function desligarParaTodos(User $por, \DateTimeImmutable $agora): void
    {
        $this->ligadaParaTodos = false;
        $this->registrarAlteracao($por, $agora);
    }

    public function escolherModelo(ModeloDeIa $modelo, User $por, \DateTimeImmutable $agora): void
    {
        $this->modelo = $modelo->value;
        $this->registrarAlteracao($por, $agora);
    }

    public function definirRetencao(int $meses, User $por, \DateTimeImmutable $agora): void
    {
        if ($meses < self::MESES_DE_RETENCAO_MINIMO || $meses > self::MESES_DE_RETENCAO_MAXIMO) {
            throw new \InvalidArgumentException('Guarde as respostas da IA por 1 a 120 meses.');
        }

        $this->mesesDeRetencao = $meses;
        $this->registrarAlteracao($por, $agora);
    }

    public function getMesesDeRetencao(): int
    {
        return $this->mesesDeRetencao;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isLigadaParaTodos(): bool
    {
        return $this->ligadaParaTodos;
    }

    /** O valor gravado, como está (a coluna é texto: quem usa confere contra o enum). */
    public function getModelo(): string
    {
        return $this->modelo;
    }

    /** O modelo gravado, se for um dos que o painel oferece; senão nulo. */
    public function modeloEscolhido(): ?ModeloDeIa
    {
        return ModeloDeIa::tryFrom($this->modelo);
    }

    public function getAlteradaPor(): ?User
    {
        return $this->alteradaPor;
    }

    public function getAlteradaEm(): \DateTimeImmutable
    {
        return $this->alteradaEm;
    }

    private function registrarAlteracao(User $por, \DateTimeImmutable $agora): void
    {
        $this->alteradaPor = $por;
        $this->alteradaEm = $agora;
    }
}
