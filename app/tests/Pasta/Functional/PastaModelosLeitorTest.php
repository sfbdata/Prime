<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Controller\PastaModelosController;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Quem só VÊ a pasta consulta o banco de modelos e marca as próprias estrelas (preferência pessoal),
 * mas não cria, edita nem exclui modelo do escritório.
 */
#[CoversClass(PastaModelosController::class)]
#[Group('pasta')]
final class PastaModelosLeitorTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('Leitor da pasta: consulta, favorita modelo e matéria; criar, editar e excluir dão 403 e nada muda')]
    public function testLeitorFavoritaMasNaoEscreve(): void
    {
        $client          = static::createClient();
        [, $tenant]      = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $leitor          = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $modelo          = new ModeloDePeca($tenant);
        $modelo->atualizar('Do escritório', MateriaDeModelo::Civel, TipoDePeca::Parecer, '<p>x</p>');
        $this->em()->persist($modelo);
        $this->em()->flush();

        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
        $this->logarComTenant($client, $leitor, $tenant);
        $base  = '/pasta/' . $pasta->getId() . '/modelos';
        $token = ['_token' => 'TOKEN_modelos_pasta_' . $pasta->getId()];

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertNotSame('', (string) $crawler->filter('#mdlRaiz')->attr('data-token'), 'O leitor recebe o token das estrelas');
        self::assertCount(0, $crawler->filter('#mdlNovo, #mdlMais'));

        $client->request('GET', $base);
        self::assertResponseIsSuccessful();

        $client->request('POST', $base . '/' . $modelo->getId() . '/favorito', $token);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['favorito']);
        $client->request('POST', $base . '/materias/saude/favorita', $token);
        self::assertResponseIsSuccessful();

        $client->request('POST', $base, $token + ['titulo' => 'X', 'materia' => 'civel', 'tipo' => 'parecer', 'corpo' => '<p>x</p>']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $base . '/' . $modelo->getId(), $token + ['titulo' => 'Y', 'materia' => 'civel', 'tipo' => 'parecer', 'corpo' => '<p>y</p>']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', $base . '/' . $modelo->getId() . '/excluir', $token);
        self::assertResponseStatusCodeSame(403);

        $titulo = $this->em()->getConnection()->fetchOne('SELECT titulo FROM modelo_de_peca WHERE id = ?', [$modelo->getId()]);
        self::assertSame('Do escritório', $titulo);
    }
}
