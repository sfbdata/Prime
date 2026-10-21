<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Auth\Enum\StatusOab;
use App\Comercial\Campanha\AtributosDosDestinatarios;
use App\Comercial\Repository\CadastradosRepository;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Lotacao;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Tests\Comercial\Support\CriaCampanhas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Os atributos do filtro da campanha contra o banco (spec comercial-e-emails.md §9, decisões do coordenador): perfil
 * (gênero, atuação e nascimento), papel de algum escritório, UF pela regra do mapa do Master, OAB e o plano de cada
 * escritório pelo Contribua (sem espaço gravado = Free). Quem não tem conta fica de fora do mapa.
 */
#[CoversClass(AtributosDosDestinatarios::class)]
#[CoversClass(CadastradosRepository::class)]
final class AtributosDosDestinatariosTest extends KernelTestCase
{
    use CriaCampanhas;

    #[TestDox('Primária num escritório e secundária noutro: papel prim, UF da lotação, OAB confirmada, perfil e plano Free')]
    public function testAtributosDeQuemTemConta(): void
    {
        self::bootKernel();
        $ana = $this->usuario('ana', nome: 'Ana Atributos');
        $ana->setOabNumero('12345')->setOabUf('GO')->setOabStatus(StatusOab::Confirmada);
        $escritorio = $this->vincular($ana, true);
        $this->vincular($ana, false);
        $this->lotar($ana->getId(), $escritorio->getId(), 'Brasília/DF');
        $this->perfil($ana, Genero::Feminino, Atuacao::Advogado, '1990-10-05');

        $a = static::getContainer()->get(AtributosDosDestinatarios::class)->de([(string) $ana->getEmail(), 'sem.conta@x.com']);

        self::assertArrayNotHasKey('sem.conta@x.com', $a, 'quem não tem conta fica sem nada');
        $da = $a[(string) $ana->getEmail()];
        self::assertSame(['F', '1990-10-05', 'DF', 'prim', 'adv', 'conf', ['free']], [$da->genero, $da->nascimento?->format('Y-m-d'), $da->uf, $da->papel, $da->cargo, $da->oab, $da->planos]);
    }

    #[TestDox('Secundário sem lotação nem OAB UF: UF do escritório; OAB não verificada é pendente; sem perfil fica sem gênero nem cargo')]
    public function testUfDoEscritorioEOabPendente(): void
    {
        self::bootKernel();
        $beto = $this->usuario('beto');
        $beto->setOabNumero('999')->setOabStatus(StatusOab::NaoVerificada);
        $escritorio = $this->vincular($beto, false);
        $escritorio->setEstado('SP');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $b = static::getContainer()->get(AtributosDosDestinatarios::class)->de([(string) $beto->getEmail()])[(string) $beto->getEmail()];

        self::assertSame(['SP', 'sec', 'pend', null, null, null], [$b->uf, $b->papel, $b->oab, $b->genero, $b->cargo, $b->nascimento]);
    }

    private function lotar(?int $userId, ?int $tenantId, string $nome): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $lotacao = (new Lotacao())->setNome($nome)->setTenant($em->getReference(\App\Entity\Tenant\Tenant::class, $tenantId));
        $em->persist($lotacao);
        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $userId, 'tenant' => $tenantId]);
        \assert($vinculo instanceof UserTenant);
        $vinculo->setLotacao($lotacao);
        $em->flush();
    }
}
