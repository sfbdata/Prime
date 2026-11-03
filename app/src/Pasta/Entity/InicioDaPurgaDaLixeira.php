<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Shared\Attribute\SemIsolamentoDeTenant;
use Doctrine\ORM\Mapping as ORM;

/**
 * Quando a contagem dos 30 dias da lixeira começou (decisão do Farlei, 03/11/2026): ninguém foi
 * avisado do prazo antes do deploy da purga agendada, então o que já estava na lixeira ganha 30
 * dias contados do deploy. Uma linha só (id 1), gravada pela migration Version20261103170000 com a
 * hora em que ela rodou em produção.
 *
 * A regra fica na {@see \App\Pasta\UseCase\PurgarLixeiraUseCase}: um item vence quando
 * `max(excluido_em, inicia_em)` fica mais velho que o prazo. Sem a linha (banco de teste, dev), vale
 * a regra de sempre.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lixeira_purga_inicio')]
#[SemIsolamentoDeTenant('Política da instalação: a mesma data de início vale para todos os escritórios')]
class InicioDaPurgaDaLixeira
{
    public const ID_UNICO = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $iniciaEm;

    public function __construct(\DateTimeImmutable $iniciaEm)
    {
        $this->iniciaEm = $iniciaEm;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIniciaEm(): \DateTimeImmutable
    {
        return $this->iniciaEm;
    }
}
