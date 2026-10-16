<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escreve um cartão do SISTEMA no Registro da pasta, como o `autoReg` do desenho 1.2.3: "Andamento: Fase da
 * pasta alterada de X para Y", "Prazo: Audiência agendada: …". Autor = quem fez a mudança. Só persiste; quem
 * chama dá o flush junto da própria mudança.
 */
final class RegistroDoSistemaNaPasta
{
    /** Rótulos do desenho (`Component.SYS`); `prazo` não está no SYS do protótipo e ganhou o rótulo óbvio. */
    public const ROTULOS = [
        'fase'     => 'Andamento',
        'prazo'    => 'Prazo',
        'contrato' => 'Contrato',
        'situacao' => 'Situação da pasta',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function registrar(Pasta $pasta, User $autor, string $tipo, string $texto): PastaMensagem
    {
        $rotulo = self::ROTULOS[$tipo] ?? 'Alteração';
        $mensagem = new PastaMensagem();
        $mensagem->setPasta($pasta);
        $mensagem->setTenant($pasta->getTenant());
        $mensagem->setAutor($autor);
        $mensagem->setSistema($tipo);
        $mensagem->setConteudo('<p><strong>' . htmlspecialchars($rotulo, ENT_QUOTES) . ':</strong> '
            . htmlspecialchars($texto, ENT_QUOTES) . '</p>');
        $this->em->persist($mensagem);

        return $mensagem;
    }
}
