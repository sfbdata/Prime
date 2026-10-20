<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\DTO\DefinirLimiteDeArmazenamentoInput;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\UseCase\DefinirLimiteDeArmazenamentoUseCase;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O furo da revisão da C1: com o TenantFilter da sessão no escritório X, o Master lia o espaço do Y
 * como Free e, se o Y já tinha linha, a gravação violava a unicidade. Aqui o filtro é ligado como o
 * `TenantFilterListener` faz numa requisição do Master com o escritório X ativo.
 */
#[CoversClass(EspacoDoEscritorioRepository::class)]
#[CoversClass(DefinirLimiteDeArmazenamentoUseCase::class)]
final class LimiteDeOutroEscritorioPeloMasterTest extends KernelTestCase
{
    #[TestDox('Master com o escritório X na sessão muda o limite de Y, que já tem linha: atualiza a linha de Y')]
    public function testMasterNoEscritorioXAlteraOY(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $x = $this->criarTenant($em, 'X');
        $y = $this->criarTenant($em, 'Y');
        $espacoDeY = new EspacoDoEscritorio($y, $c->get(PlanoRepository::class)->free());
        $espacoDeY->definirLimite(5, false, 'limite inicial', null);
        $em->persist($espacoDeY);
        $em->flush();
        $em->clear();

        $em->getFilters()->enable('tenant')->setParameter('tenant', $x->getId(), Types::INTEGER);
        $situacao = $c->get(SituacaoDoEspaco::class);
        $useCase = new DefinirLimiteDeArmazenamentoUseCase(
            $c->get(EspacoDoEscritorioRepository::class),
            $c->get(PlanoRepository::class),
            $c->get(ConsumoDeArmazenamentoRepository::class),
            $situacao,
        );

        $retrato = $useCase->executar(new DefinirLimiteDeArmazenamentoInput((int) $y->getId(), 30, false, 'contratou ampliação'), null);

        self::assertSame(OrigemDoLimite::Master, $retrato->origemDoLimite);
        self::assertSame(30 * RetratoDoEspaco::BYTES_POR_GB, $retrato->bytesLimite);
        self::assertSame(30 * RetratoDoEspaco::BYTES_POR_GB, $situacao->doEscritorio((int) $y->getId())->bytesLimite);
        $linhas = $c->get(Connection::class)->fetchAllAssociative(
            'SELECT cota_gb FROM contribua_espaco_do_escritorio WHERE tenant_id = :t',
            ['t' => $y->getId()],
        );
        self::assertSame([['cota_gb' => 30]], $linhas, 'uma linha só, a do Y, atualizada');
        self::assertSame(0, (int) $c->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM contribua_espaco_do_escritorio WHERE tenant_id = :t',
            ['t' => $x->getId()],
        ), 'nada gravado para o escritório da sessão');
    }

    private function criarTenant(EntityManagerInterface $em, string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant CONTRIBUA ' . $nome . ' ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}
