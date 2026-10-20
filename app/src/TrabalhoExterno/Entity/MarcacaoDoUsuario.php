<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que UM usuário marcou num item (demanda, vaga, currículo, candidatura): estrela, importância
 * manual, "não lida", lixeira e exclusão da lista dele. Nada disso muda o item para os outros
 * (spec VS 18.10.26 §2). Tabela única para os quatro tipos: as operações são as mesmas; o preço é
 * `item_id` sem FK, e quem remove o item remove as marcações dele.
 *
 * Não é `TenantAware`: a marcação é do usuário, e o Master marca demandas de todos os escritórios.
 * Toda leitura filtra pelo usuário logado.
 */
#[ORM\Entity(repositoryClass: MarcacaoDoUsuarioRepository::class)]
#[ORM\Table(name: 'trabalho_externo_marcacao')]
#[ORM\UniqueConstraint(name: 'uniq_te_marcacao_usuario_item', columns: ['usuario_id', 'tipo', 'item_id'])]
class MarcacaoDoUsuario
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $estrela = false;

    /** `null` = automático (calculado); `true`/`false` = escolha do usuário, que vence o cálculo. */
    #[ORM\Column(nullable: true)]
    private ?bool $importante = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $naoLida = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lixeiraEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidaEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(enumType: TipoItemMarcado::class, length: 20)]
        private TipoItemMarcado $tipo,
        #[ORM\Column]
        private int $itemId,
    ) {
    }

    /** `Lida` aqui só desliga a marca manual; o ponteiro de leitura do chat é do UseCase. */
    public function aplicar(OperacaoDeMarcacao $op, \DateTimeImmutable $agora): void
    {
        match ($op) {
            OperacaoDeMarcacao::Lixeira => $this->lixeiraEm = $agora,
            OperacaoDeMarcacao::Restaurar => $this->lixeiraEm = null,
            OperacaoDeMarcacao::Definitivo => $this->excluirDaLista($agora),
            OperacaoDeMarcacao::Lida => $this->naoLida = false,
            OperacaoDeMarcacao::NaoLida => $this->naoLida = true,
            OperacaoDeMarcacao::Estrela => $this->estrela = true,
            OperacaoDeMarcacao::SemEstrela => $this->estrela = false,
            OperacaoDeMarcacao::Importante => $this->importante = true,
            OperacaoDeMarcacao::NaoImportante => $this->importante = false,
        };
    }

    private function excluirDaLista(\DateTimeImmutable $agora): void
    {
        $this->lixeiraEm = null;
        $this->excluidaEm = $agora;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function temEstrela(): bool
    {
        return $this->estrela;
    }

    public function getImportante(): ?bool
    {
        return $this->importante;
    }

    public function isNaoLida(): bool
    {
        return $this->naoLida;
    }

    public function naLixeira(): bool
    {
        return $this->lixeiraEm !== null && $this->excluidaEm === null;
    }

    public function excluida(): bool
    {
        return $this->excluidaEm !== null;
    }
}
