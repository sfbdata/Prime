<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Contrato de `PessoaRepository::opcoesDoTenant` contra o BANCO REAL — o mapa que popula o ChoiceType de
 * "Trocar responsável" e "Vincular pessoa".
 *
 * O defeito que estes testes travam: a chave do mapa era o NOME puro, então homônimo sobrescrevia
 * homônimo (medido no dev: 125 pessoas → 110 opções). Quem sumia ficava inalcançável, e escolher um nome
 * repetido podia mandar o id do outro registro — e isso decide QUEM é cobrado.
 *
 * Invariantes cobertos aqui: uma opção por pessoa; o VALOR é sempre o id; o nome é só rótulo; homônimos
 * ganham desempate visível; nada de outro tenant entra.
 */
#[CoversClass(PessoaRepository::class)]
final class PessoaRepositoryOpcoesTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private PessoaRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var PessoaRepository $repo */
        $repo = $this->em->getRepository(Pessoa::class);
        $this->repo = $repo;
    }

    #[Test]
    #[TestDox('Dois homônimos do mesmo tenant viram DUAS opções distintas, cada uma com o próprio id')]
    public function homonimosViramOpcoesDistintas(): void
    {
        $tenant = $this->tenant();
        $primeira = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '111.111.111-11'])->_real();
        $segunda = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '222.222.222-22'])->_real();

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertCount(2, $opcoes, 'homônimo não pode apagar homônimo');
        // O que importa para a submissão: os DOIS ids estão presentes, e cada rótulo aponta para um só.
        self::assertContains((int) $primeira->getId(), $opcoes);
        self::assertContains((int) $segunda->getId(), $opcoes);
        // Sentinela de ordenação grosseira. NÃO prova o `addOrderBy('p.id')` do repositório: com duas
        // linhas recém-inseridas o Postgres devolve na ordem de inserção mesmo sem o desempate. O
        // desempate existe para o dado real (onde a ordem sem ele é indefinida) e não tem teste barato
        // que o trave — está registrado no handoff.
        self::assertSame(
            [(int) $primeira->getId(), (int) $segunda->getId()],
            array_values($opcoes),
            'as duas opções saem na ordem esperada',
        );

        // O nome é só rótulo — e, repetido, precisa carregar o desempate para o humano escolher certo.
        foreach (array_keys($opcoes) as $rotulo) {
            self::assertStringContainsString('JOSE DA SILVA', (string) $rotulo);
        }
        self::assertArrayHasKey('JOSE DA SILVA (CPF 111.111.111-11)', $opcoes);
        self::assertArrayHasKey('JOSE DA SILVA (CPF 222.222.222-22)', $opcoes);
        self::assertSame((int) $primeira->getId(), $opcoes['JOSE DA SILVA (CPF 111.111.111-11)'], 'o rótulo resolve o id exato');
        self::assertSame((int) $segunda->getId(), $opcoes['JOSE DA SILVA (CPF 222.222.222-22)'], 'o rótulo resolve o id exato');
    }

    #[Test]
    #[TestDox('Nome único continua exibido puro — o desempate não expõe documento a mais')]
    public function nomeUnicoNaoGanhaDesempate(): void
    {
        $tenant = $this->tenant();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'MARIA UNICA', 'cpf' => '333.333.333-33'])->_real();

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertSame(['MARIA UNICA' => (int) $pessoa->getId()], $opcoes);
    }

    #[Test]
    #[TestDox('Desempate cai para CNPJ, depois e-mail e por fim o id — nunca deixa opção colidir')]
    public function desempateUsaODadoDisponivel(): void
    {
        $tenant = $this->tenant();
        $comCnpj = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'HOMONIMO', 'cnpj' => '11.222.333/0001-44'])->_real();
        $comEmail = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'HOMONIMO', 'email' => 'contato@exemplo.test'])->_real();
        $semNada = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'HOMONIMO'])->_real();

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertCount(3, $opcoes);
        self::assertSame((int) $comCnpj->getId(), $opcoes['HOMONIMO (CNPJ 11.222.333/0001-44)']);
        self::assertSame((int) $comEmail->getId(), $opcoes['HOMONIMO (contato@exemplo.test)']);
        self::assertSame((int) $semNada->getId(), $opcoes['HOMONIMO (#' . $semNada->getId() . ')']);
    }

    #[Test]
    #[TestDox('Homônimos com o MESMO documento ainda assim não se apagam (caso patológico)')]
    public function homonimosComMesmoDocumentoNaoColidem(): void
    {
        $tenant = $this->tenant();
        PessoaFactory::createMany(2, ['tenant' => $tenant, 'nome' => 'CLONE', 'cpf' => '444.444.444-44']);

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertCount(2, $opcoes, 'nem o cadastro duplicado pode fazer uma opção sumir');
        self::assertCount(2, array_unique($opcoes), 'cada opção aponta para um id diferente');
    }

    #[Test]
    #[TestDox('Nenhuma pessoa desaparece: N cadastradas => N opções, mesmo com homônimos em massa')]
    public function nenhumaPessoaDesaparece(): void
    {
        $tenant = $this->tenant();

        // Reproduz a forma do dev que gerou o bug (125 pessoas → 110 opções): muita gente, nomes
        // repetidos e sem documento. Aqui em escala menor, mas com a mesma proporção de colisão.
        $ids = [];
        for ($i = 0; $i < 40; ++$i) {
            $ids[] = (int) PessoaFactory::createOne([
                'tenant' => $tenant,
                'nome' => 'REPETIDO ' . ($i % 5),
            ])->_real()->getId();
        }

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertCount(40, $opcoes, '40 pessoas têm de produzir 40 opções');
        sort($ids);
        $valores = array_values($opcoes);
        sort($valores);
        self::assertSame($ids, $valores, 'todo id cadastrado tem de estar alcançável');
    }

    #[Test]
    #[TestDox('Homônimo de OUTRO tenant não aparece nas opções')]
    public function homonimoDeOutroTenantFicaDeFora(): void
    {
        $tenant = $this->tenant();
        $outro = $this->tenant();

        $minha = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA'])->_real();
        $alheia = PessoaFactory::createOne(['tenant' => $outro, 'nome' => 'JOSE DA SILVA'])->_real();

        $opcoes = $this->repo->opcoesDoTenant($tenant);

        self::assertSame(['JOSE DA SILVA' => (int) $minha->getId()], $opcoes, 'só a pessoa do tenant');
        self::assertNotContains((int) $alheia->getId(), $opcoes, 'id de outro escritório não pode ser oferecido');

        // E o inverso vale: cada tenant vê só o seu.
        self::assertSame(['JOSE DA SILVA' => (int) $alheia->getId()], $this->repo->opcoesDoTenant($outro));
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Opcoes ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
