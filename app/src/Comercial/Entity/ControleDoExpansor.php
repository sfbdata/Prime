<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * O controle da cadeia do expansor de campanhas (spec `comercial-e-emails.md` §8.5): uma linha só (id 1, criada pela
 * migration), no molde de {@see VarreduraComercial}. O handler reivindica com `UPDATE ... WHERE proxima_em <= agora`;
 * zero linhas, outra cadeia já passou e esta morre (despachos extras não criam cadeia paralela).
 */
#[ORM\Entity]
#[ORM\Table(name: 'comercial_expansor')]
class ControleDoExpansor
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
