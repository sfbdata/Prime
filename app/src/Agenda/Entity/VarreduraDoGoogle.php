<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Marca da cadeia que sincroniza o Google Agenda a cada 5 min (uma linha só, id 1, criada pela migration).
 * Mapeada para o Doctrine não propor apagá-la; quem lê e grava é o VarreduraDoGoogleRepository, por SQL atômico.
 */
#[ORM\Entity]
#[ORM\Table(name: 'agenda_google_varredura')]
class VarreduraDoGoogle
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\Column(name: 'ultima_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimaEm = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getUltimaEm(): ?\DateTimeImmutable
    {
        return $this->ultimaEm;
    }
}
