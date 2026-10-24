<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Entity\Auth\User;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liga a chave geral do Master no banco de teste (a migration cria a linha desligada). Teste funcional que liga a IA
 * num escritório precisa da plataforma ligada: sem isto a disponibilidade para em "não configurada na plataforma".
 */
trait ChaveGeralDaIaNoBancoTrait
{
    private function ligarChaveGeralDaIa(User $por): void
    {
        $plataforma = static::getContainer()->get(ConfiguracaoDaPlataformaDeIaRepository::class)->obterParaAlterar();
        $plataforma->ligarParaTodos($por, new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
