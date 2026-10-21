<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * O controle da cadeia de varredura (spec §3.4, M3): uma linha só (id 1, criada pela migration). O handler reivindica
 * com UPDATE ... WHERE proxima_em <= agora; zero linhas, outra cadeia já passou e esta morre.
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_varredura')]
class VarreduraComercial
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\Column(name: 'proxima_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $proximaEm;

    #[ORM\Column(name: 'executada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $executadaEm = null;

    public function __construct()
    {
        $this->proximaEm = new \DateTimeImmutable();
    }
}
