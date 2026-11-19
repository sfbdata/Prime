<?php

declare(strict_types=1);

namespace App\Contribua\Entity;

use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaRepository;
use App\Entity\Auth\User;
use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * A campanha "BlueJus Black" (Desafio de R$ 1,00): configuração do Master, uma só vigente por enquanto (a de maior id).
 * Os valores valem só para NOVAS inscrições: cada participação congela o que foi prometido (regra 3). A `versaoRegras`
 * sobe a cada alteração salva, para saber que regras cada pessoa aceitou.
 */
#[ORM\Entity(repositoryClass: CampanhaRepository::class)]
#[ORM\Table(name: 'contribua_campanha')]
#[SemIsolamentoDeTenant('Campanha comercial do BlueJus para todos os escritórios: a configuração é do Master e não pertence a nenhum escritório')]
class Campanha
{
    public const PRECO_MINIMO_CENTAVOS = 100;
    public const PRAZO_PEDIDO_MINIMO = 7;
    public const NOME_PADRAO = 'BlueJus Black';
    public const CHAMADA_PADRAO = 'Black Friday BlueJus';

    /** @var list<string> */
    public const BENEFICIOS_PADRAO = [
        'Implantação assistida: importamos suas pastas, clientes e prazos',
        'Suporte prioritário pelo WhatsApp durante a campanha',
        'Treinamento ao vivo para a sua equipe',
        'Selo "Fundador BlueJus Black" no seu perfil',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 60)]
    private string $nome = self::NOME_PADRAO;

    #[ORM\Column(length: 60)]
    private string $chamada = self::CHAMADA_PADRAO;

    #[ORM\Column(type: 'integer')]
    private int $precoCentavos = 100;

    #[ORM\Column(type: 'smallint')]
    private int $multiplicador = 2;

    #[ORM\Column(type: 'smallint')]
    private int $prazoPedidoDias = 30;

    #[ORM\Column(type: 'smallint')]
    private int $prazoPagamentoDiasUteis = 7;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $inicio;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $fim;

    /** 0 = sem limite. */
    #[ORM\Column(type: 'integer')]
    private int $vagas = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $mostrarContador = false;

    #[ORM\Column(type: 'boolean')]
    private bool $abaNoTopo = true;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $beneficios = self::BENEFICIOS_PADRAO;

    #[ORM\Column(type: 'boolean')]
    private bool $ligada = true;

    #[ORM\Column(type: 'integer')]
    private int $versaoRegras = 1;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $atualizadaPor = null;

    public function __construct()
    {
        $this->inicio = new \DateTimeImmutable('2026-11-19');
        $this->fim = new \DateTimeImmutable('2026-11-30');
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    /** A campanha com os valores padrão do desenho (a mesma que a migration cria). */
    public static function padrao(): self
    {
        return new self();
    }

    /**
     * Aplica uma configuração inteira (validada) e sobe a versão das regras se algo mudou.
     * Devolve false quando nada mudou.
     *
     * @param list<string> $beneficios
     */
    public function aplicar(
        string $nome,
        string $chamada,
        int $precoCentavos,
        int $multiplicador,
        int $prazoPedidoDias,
        int $prazoPagamentoDiasUteis,
        \DateTimeImmutable $inicio,
        \DateTimeImmutable $fim,
        int $vagas,
        bool $mostrarContador,
        bool $abaNoTopo,
        array $beneficios,
        bool $ligada,
        ?User $por,
        \DateTimeImmutable $em,
    ): bool {
        $antes = $this->valores();
        $this->definirValores([
            'nome' => $nome,
            'chamada' => $chamada,
            'precoCentavos' => $precoCentavos,
            'multiplicador' => $multiplicador,
            'prazoPedidoDias' => $prazoPedidoDias,
            'prazoPagamentoDiasUteis' => $prazoPagamentoDiasUteis,
            'inicio' => $inicio,
            'fim' => $fim,
            'vagas' => $vagas,
            'mostrarContador' => $mostrarContador,
            'abaNoTopo' => $abaNoTopo,
            'beneficios' => $beneficios,
            'ligada' => $ligada,
        ]);

        return $this->registrarSeMudou($antes, $por, $em);
    }

    /** Sobe a versão das regras e carimba quem alterou, só se os valores mudaram desde `$antes`. */
    private function registrarSeMudou(array $antes, ?User $por, \DateTimeImmutable $em): bool
    {
        if ($this->valores() === $antes) {
            return false;
        }
        ++$this->versaoRegras;
        $this->atualizadaEm = $em;
        $this->atualizadaPor = $por;

        return true;
    }

    /**
     * Apara textos e benefícios, valida tudo antes de gravar e então atribui os campos.
     *
     * @param array<string, mixed> $v os 13 valores da configuração, com as chaves de `valores()`
     */
    private function definirValores(array $v): void
    {
        $v['nome'] = trim($v['nome']);
        $v['chamada'] = trim($v['chamada']);
        $v['beneficios'] = array_values(array_filter(array_map('trim', $v['beneficios']), static fn (string $b): bool => $b !== ''));
        self::validarConfiguracao($v);

        $this->nome = $v['nome'];
        $this->chamada = $v['chamada'];
        $this->precoCentavos = $v['precoCentavos'];
        $this->multiplicador = $v['multiplicador'];
        $this->prazoPedidoDias = $v['prazoPedidoDias'];
        $this->prazoPagamentoDiasUteis = $v['prazoPagamentoDiasUteis'];
        $this->inicio = $v['inicio'];
        $this->fim = $v['fim'];
        $this->vagas = $v['vagas'];
        $this->mostrarContador = $v['mostrarContador'];
        $this->abaNoTopo = $v['abaNoTopo'];
        $this->beneficios = $v['beneficios'];
        $this->ligada = $v['ligada'];
    }

    /** @param array<string, mixed> $v valores já aparados */
    private static function validarConfiguracao(array $v): void
    {
        if ($v['nome'] === '' || mb_strlen($v['nome']) > 60) {
            throw new \DomainException('O nome da campanha deve ter de 1 a 60 caracteres.');
        }
        if ($v['chamada'] === '' || mb_strlen($v['chamada']) > 60) {
            throw new \DomainException('A chamada da campanha deve ter de 1 a 60 caracteres.');
        }
        self::validarPrazosEValores($v);
        if ($v['fim'] < $v['inicio']) {
            throw new \DomainException('A data final não pode ser antes da inicial.');
        }
        if ($v['vagas'] < 0) {
            throw new \DomainException('O número de vagas não pode ser negativo.');
        }
        foreach ($v['beneficios'] as $beneficio) {
            if (mb_strlen($beneficio) > 200) {
                throw new \DomainException('Cada benefício pode ter até 200 caracteres.');
            }
        }
    }

    /** @param array<string, mixed> $v */
    private static function validarPrazosEValores(array $v): void
    {
        if ($v['precoCentavos'] < self::PRECO_MINIMO_CENTAVOS) {
            throw new \DomainException('O preço mínimo é R$ 1,00.');
        }
        if (!\in_array($v['multiplicador'], [2, 3], true)) {
            throw new \DomainException('A devolução é em dobro ou em triplo.');
        }
        if ($v['prazoPedidoDias'] < self::PRAZO_PEDIDO_MINIMO) {
            throw new \DomainException('O prazo para pedir a devolução é de no mínimo 7 dias.');
        }
        if ($v['prazoPagamentoDiasUteis'] < 1) {
            throw new \DomainException('O prazo para pagar a devolução é de no mínimo 1 dia útil.');
        }
    }

    /**
     * Como a campanha está hoje. `$hoje` é a data no fuso America/Sao_Paulo e `$inscricoes` conta todas as
     * inscrições (inclusive as aguardando pagamento: não se vende além do limite).
     */
    public function estado(\DateTimeImmutable $hoje, int $inscricoes): EstadoDaCampanha
    {
        if (!$this->ligada) {
            return EstadoDaCampanha::Desligada;
        }
        $dia = $hoje->format('Y-m-d');
        if ($dia < $this->inicio->format('Y-m-d')) {
            return EstadoDaCampanha::Agendada;
        }
        if ($dia > $this->fim->format('Y-m-d')) {
            return EstadoDaCampanha::Encerrada;
        }
        if ($this->vagas > 0 && $inscricoes >= $this->vagas) {
            return EstadoDaCampanha::Esgotada;
        }

        return EstadoDaCampanha::Ativa;
    }

    /**
     * O retrato da configuração (trilha de alteração e comparação).
     *
     * @return array<string, mixed>
     */
    public function valores(): array
    {
        return [
            'nome' => $this->nome,
            'chamada' => $this->chamada,
            'precoCentavos' => $this->precoCentavos,
            'multiplicador' => $this->multiplicador,
            'prazoPedidoDias' => $this->prazoPedidoDias,
            'prazoPagamentoDiasUteis' => $this->prazoPagamentoDiasUteis,
            'inicio' => $this->inicio->format('Y-m-d'),
            'fim' => $this->fim->format('Y-m-d'),
            'vagas' => $this->vagas,
            'mostrarContador' => $this->mostrarContador,
            'abaNoTopo' => $this->abaNoTopo,
            'beneficios' => $this->beneficios,
            'ligada' => $this->ligada,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getChamada(): string
    {
        return $this->chamada;
    }

    public function getPrecoCentavos(): int
    {
        return $this->precoCentavos;
    }

    public function getMultiplicador(): int
    {
        return $this->multiplicador;
    }

    public function getPrazoPedidoDias(): int
    {
        return $this->prazoPedidoDias;
    }

    public function getPrazoPagamentoDiasUteis(): int
    {
        return $this->prazoPagamentoDiasUteis;
    }

    public function getInicio(): \DateTimeImmutable
    {
        return $this->inicio;
    }

    public function getFim(): \DateTimeImmutable
    {
        return $this->fim;
    }

    public function getVagas(): int
    {
        return $this->vagas;
    }

    public function isMostrarContador(): bool
    {
        return $this->mostrarContador;
    }

    public function isAbaNoTopo(): bool
    {
        return $this->abaNoTopo;
    }

    /** @return list<string> */
    public function getBeneficios(): array
    {
        return $this->beneficios;
    }

    public function isLigada(): bool
    {
        return $this->ligada;
    }

    public function getVersaoRegras(): int
    {
        return $this->versaoRegras;
    }

    public function valorDaDevolucaoCentavos(): int
    {
        return $this->precoCentavos * $this->multiplicador;
    }
}
