<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Entity\Auth\User;
use App\Produto\Enum\Produto;
use App\Produto\EventListener\GuardaDoProduto;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * A guarda do produto em bloqueio não age nas rotas públicas nem nas do Trabalho Externo liberadas sem escritório
 * (spec multiproduto, §6.1, e a decisão do PR 3 sobre o `_sem_escritorio`). A assinatura pública fica em
 * {@see GuardaDoProdutoNaAssinaturaPublicaTest}.
 */
#[CoversClass(GuardaDoProduto::class)]
final class GuardaDoProdutoNasPublicasTest extends JusPrimeWebTestCase
{
    use Factories;
    use ComEscritoriosPorProduto;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->modo('bloqueio');
    }

    #[TestDox('bloqueio: a cédula abre sem login e para quem está logado num escritório sem o Voto')]
    public function testCedulaPassa(): void
    {
        $link = $this->linkDaCedula();

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('anônimo');

        $soExpediente = $this->escritorio(Produto::Expediente);
        $this->logarComTenant($this->client, $this->primario($soExpediente), $soExpediente);
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('logado num escritório sem o Voto');
    }

    #[TestDox('bloqueio: o Convidado do Trabalho Externo, sem escritório na sessão, não leva o 404 da guarda (sai por "sem escritório")')]
    public function testConvidadoSemEscritorioNaoLeva404(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $convidado = (new User())->setEmail('convidado_' . uniqid() . '@test.com')->setFullName('Convidado')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $em->persist($convidado);
        $em->flush();
        $this->client->loginUser($convidado);
        $this->marcarTermosAceitos($this->client);

        $this->client->request('GET', '/trabalho-externo/api/painel');

        // A guarda só responde 404; o painel sem demanda responde pela regra do próprio Trabalho Externo.
        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
    }

    #[TestDox('bloqueio: com escritório só Voto na sessão, a rota _sem_escritorio do Trabalho Externo dá 404 (o Convidado, sem escritório na sessão, passa pela saída "sem escritório", como no TenantContextValidatorListener)')]
    public function testTrabalhoExternoComEscritorioSemExpediente(): void
    {
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/trabalho-externo/api/painel');

        self::assertResponseStatusCodeSame(404);
    }

    /** Assembleia publicada, com pauta aberta e uma unidade, num escritório só Voto com o Voto liberado. */
    private function linkDaCedula(): string
    {
        $soVoto = $this->escritorio(Produto::Voto);
        $this->liberarOVoto($soVoto);
        $assembleia = AssembleiaFactory::new()->publicada()->create(['tenant' => $soVoto, 'titulo' => 'AGO da guarda'])->_real();
        PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()->create(['assembleia' => $assembleia]);
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'identificacao' => 'A-101'])->_real();

        return '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);
    }
}
