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

    #[TestDox('Aviso pendente do recurso: só conta o da mesma origem e do mesmo recurso, ainda não processado e recebido dentro da janela')]
    public function testHaPendenteDoRecurso(): void
    {
        self::bootKernel();
        $repositorio = static::getContainer()->get(NotificacaoRecebidaRepository::class);
        $banco = static::getContainer()->get(Connection::class);
        $recurso = 'inv_' . uniqid();
        $desde = new \DateTimeImmutable('-60 seconds');

        self::assertFalse($repositorio->haPendenteDoRecurso('cora', $recurso, $desde), 'sem aviso nenhum');
        $repositorio->registrarSeNova(new NotificacaoRecebida('cora', 'evt_' . uniqid(), 'invoice.paid', $recurso));
        self::assertTrue($repositorio->haPendenteDoRecurso('cora', $recurso, $desde));
        self::assertFalse($repositorio->haPendenteDoRecurso('cora', $recurso . '_outro', $desde), 'outro recurso');
        self::assertFalse($repositorio->haPendenteDoRecurso('inter', $recurso, $desde), 'outra origem');

        // Recebido antes da janela (a reconsulta falhou e ficou pendente): não segura o aviso novo.
        $banco->executeStatement('UPDATE contribua_notificacao_recebida SET recebida_em = :antes WHERE id_recurso = :r', ['antes' => (new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s'), 'r' => $recurso]);
        self::assertFalse($repositorio->haPendenteDoRecurso('cora', $recurso, $desde), 'fora da janela');

        $evento = 'evt_' . uniqid();
        $repositorio->registrarSeNova(new NotificacaoRecebida('cora', $evento, 'invoice.paid', $recurso));
        $repositorio->marcarProcessada('cora', $evento);
        self::assertFalse($repositorio->haPendenteDoRecurso('cora', $recurso, $desde), 'já processado');
    }
}
