<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Entity\NotificacaoRecebida;
use App\Contribua\Repository\NotificacaoRecebidaRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(NotificacaoRecebidaRepository::class)]
final class NotificacaoRecebidaRepositoryTest extends KernelTestCase
{
    #[TestDox('O mesmo evento do mesmo gateway entra uma vez só; outro gateway com o mesmo id entra')]
    public function testIdempotencia(): void
    {
        self::bootKernel();
        $repositorio = static::getContainer()->get(NotificacaoRecebidaRepository::class);
        $id = 'evt_' . uniqid();

        self::assertTrue($repositorio->registrarSeNova(new NotificacaoRecebida('asaas', $id, 'PAYMENT_CONFIRMED', 'sub_1')));
        self::assertFalse($repositorio->registrarSeNova(new NotificacaoRecebida('asaas', $id, 'PAYMENT_CONFIRMED', 'sub_1')));
        self::assertTrue($repositorio->registrarSeNova(new NotificacaoRecebida('cora', $id, 'invoice.paid', 'inv_1')));

        $repositorio->marcarProcessada('asaas', $id);
        $linha = static::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT recebida_em, processada_em FROM contribua_notificacao_recebida WHERE origem = :o AND id_evento = :e',
            ['o' => 'asaas', 'e' => $id],
        );
        self::assertNotFalse($linha);
        self::assertNotNull($linha['processada_em']);
        // Hora do PHP (America/Sao_Paulo), não o NOW() do Postgres, que está em UTC (3 h adiantado).
        foreach (['recebida_em', 'processada_em'] as $coluna) {
            self::assertLessThan(120, abs((new \DateTimeImmutable((string) $linha[$coluna]))->getTimestamp() - time()), $coluna);
        }
    }
}
