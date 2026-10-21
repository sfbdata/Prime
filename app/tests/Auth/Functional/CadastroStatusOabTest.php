<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Enum\StatusOab;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cadastros pendentes do fluxo antigo (com OAB) ainda enviados valem 24 h depois que o "Iniciar" saiu de cena
 * (o cadastro do desenho 10 cria a conta direto, sem OAB). Este teste prova que a confirmação deles continua
 * copiando o status de OAB gravado no pendente para o User.
 */
final class CadastroStatusOabTest extends KernelTestCase
{
    #[TestDox('O status da OAB gravado no pendente é copiado para o User na confirmação')]
    public function testStatusPercorreAConfirmacao(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);

        $pendente = new CadastroPendente(
            'ana' . uniqid() . '@adv.com',
            bin2hex(random_bytes(32)),
            'Dra. Ana Souza',
            'Banca Souza ' . uniqid(),
            '12345',
            'SP',
            'HASH_QUALQUER',
            '127.0.0.1',
            new \DateTimeImmutable('+1 hour'),
            'ua-teste',
        );
        $pendente->setOabStatus(StatusOab::NaoVerificada);
        $em->persist($pendente);
        $em->flush();

        $user = $c->get(ConfirmarCadastroUseCase::class)->executar($pendente->getToken());

        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus(), 'Confirmar copia o status para o User');
        self::assertSame('12345', $user->getOabNumero());
    }
}
