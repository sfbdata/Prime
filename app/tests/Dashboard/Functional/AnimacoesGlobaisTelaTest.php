<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\Twig\AnimacoesDoUsuarioExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A opção "Animações" do Guia visual vale para o sistema inteiro: com "reduzidas", o `<html>` de
 * QUALQUER tela do layout base leva `jp-sem-anim` (e o app.css desliga o movimento por ela).
 */
#[CoversClass(AnimacoesDoUsuarioExtension::class)]
final class AnimacoesGlobaisTelaTest extends DashboardWebTestCase
{
    private function repo(): PreferenciaDoUsuarioRepository
    {
        return static::getContainer()->get(PreferenciaDoUsuarioRepository::class);
    }

    #[TestDox('Sem preferência gravada o <html> não leva jp-sem-anim')]
    public function testPadraoSemClasse(): void
    {
        $client = static::createClient();
        $this->criarGestorLogado($client);

        $crawler = $client->request('GET', '/notificacoes');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('jp-sem-anim', (string) $crawler->filter('html')->attr('class'));
    }

    #[TestDox('Com "reduzidas", o <html> de uma tela fora do Dashboard leva jp-sem-anim')]
    public function testReduzidasValeForaDoDashboard(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $this->repo()->gravar($tenant, $user, 'dashboard.animacoes', 'reduzidas');

        foreach (['/notificacoes', '/dashboard'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame('jp-sem-anim', $crawler->filter('html')->attr('class'), $url);
        }
    }

    #[TestDox('A escolha de outro usuário do mesmo escritório não vale para mim')]
    public function testPreferenciaEhDoUsuario(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarGestorLogado($client);
        $colega          = $this->criarColaborador($tenant, 'Colega Reduzido');
        $this->repo()->gravar($tenant, $colega, 'dashboard.animacoes', 'reduzidas');
        $this->repo()->gravar($tenant, $user, 'dashboard.animacoes', 'ligadas');

        $crawler = $client->request('GET', '/notificacoes');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('jp-sem-anim', (string) $crawler->filter('html')->attr('class'));
    }
}
