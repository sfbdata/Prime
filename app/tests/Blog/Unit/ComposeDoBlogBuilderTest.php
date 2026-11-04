<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * O contêiner `blog_builder` e a pasta dos pedidos só existem no compose de produção (spec painel-artigos-blogs,
 * seção 6.1): o dev não tem nada disso, então a suíte passaria sem esta guarda. Trava o que o contrato exige do
 * compose: o builder como 33:33, sem porta, com teto de CPU e memória, os volumes certos e a chave só leitura; o
 * worker e o agendador com o volume dos pedidos e a variável que o parâmetro `publicacao_blogs_dir` lê.
 */
#[CoversNothing]
final class ComposeDoBlogBuilderTest extends TestCase
{
    #[TestDox('O blog_builder roda como 33:33, sem porta, com cpus 1, mem_limit 1g e teto de memória do node')]
    public function testServicoDoBuilder(): void
    {
        $builder = $this->compose()['services']['blog_builder'] ?? null;
        self::assertIsArray($builder, 'falta o serviço blog_builder');

        self::assertSame('./builder-blog', $builder['build']);
        self::assertSame('33:33', $builder['user']);
        self::assertArrayNotHasKey('ports', $builder, 'o builder não abre porta');
        self::assertArrayNotHasKey('expose', $builder);
        self::assertSame(1, $builder['cpus']);
        self::assertSame('1g', $builder['mem_limit']);
        self::assertSame('unless-stopped', $builder['restart']);
        self::assertArrayNotHasKey('profiles', $builder, 'serviço em profile não sobe no `up -d` do deploy');
        self::assertSame(['saas_net'], $builder['networks']);
        self::assertSame('/cache', $builder['environment']['HOME']);
        self::assertMatchesRegularExpression('/^--max-old-space-size=\d+$/', $builder['environment']['NODE_OPTIONS']);
    }

    #[TestDox('O blog_builder monta os pedidos, sites-publicados (escrita), o cache e a deploy key só leitura')]
    public function testVolumesDoBuilder(): void
    {
        self::assertSame([
            'publicacao_blogs:/pedidos',
            './sites-publicados:/sites-publicados',
            'blog_builder_cache:/cache',
            '/opt/jusprime/secrets/sites_deploy_key:/chave:ro',
        ], $this->compose()['services']['blog_builder']['volumes']);
    }

    /** @return iterable<string, array{string}> */
    public static function servicosDoBlueJus(): iterable
    {
        yield 'worker' => ['worker'];
        yield 'scheduler' => ['scheduler'];
    }

    #[DataProvider('servicosDoBlueJus')]
    #[TestDox('O $_dataName monta publicacao_blogs em /var/publicacao-blogs e aponta PUBLICACAO_BLOGS_DIR para lá')]
    public function testWorkerEAgendadorVeemOsPedidos(string $servico): void
    {
        $definicao = $this->compose()['services'][$servico];

        self::assertContains('publicacao_blogs:/var/publicacao-blogs', $definicao['volumes']);
        self::assertSame('/var/publicacao-blogs', $definicao['environment']['PUBLICACAO_BLOGS_DIR'] ?? null);
    }

    #[TestDox('Só o worker, o agendador e o builder montam os pedidos (o php e o nginx não precisam)')]
    public function testQuemMontaOsPedidos(): void
    {
        $quem = [];
        foreach ($this->compose()['services'] as $nome => $servico) {
            foreach ((array) ($servico['volumes'] ?? []) as $volume) {
                if (\is_string($volume) && str_starts_with($volume, 'publicacao_blogs:')) {
                    $quem[] = $nome;
                }
            }
        }

        self::assertSame(['worker', 'scheduler', 'blog_builder'], $quem);
    }

    #[TestDox('Os volumes nomeados publicacao_blogs e blog_builder_cache estão declarados')]
    public function testVolumesNomeados(): void
    {
        $volumes = $this->compose()['volumes'];

        self::assertArrayHasKey('publicacao_blogs', $volumes);
        self::assertArrayHasKey('blog_builder_cache', $volumes);
    }

    #[TestDox('A imagem do builder fixa a versão do node, cria /pedidos e /cache com 33:33 e roda o laco.sh como 33:33')]
    public function testDockerfileDoBuilder(): void
    {
        $dockerfile = (string) file_get_contents(\dirname(__DIR__, 4) . '/builder-blog/Dockerfile');

        self::assertMatchesRegularExpression('#^FROM node:22\.\d+\.\d+-alpine[\d.]*$#m', $dockerfile, 'versão do node fixada pela tag, sem latest');
        self::assertMatchesRegularExpression('#^RUN apk add --no-cache git rsync openssh-client$#m', $dockerfile);
        self::assertMatchesRegularExpression('#mkdir -p /pedidos /cache\s*\\\\\s*&& chown 33:33 /pedidos /cache#', $dockerfile);
        self::assertMatchesRegularExpression('#^USER 33:33$#m', $dockerfile);
        self::assertMatchesRegularExpression('#^CMD \["/usr/local/bin/laco\.sh"\]$#m', $dockerfile);
    }

    /** @return array<string, mixed> */
    private function compose(): array
    {
        $compose = Yaml::parseFile(\dirname(__DIR__, 4) . '/docker-compose.prod.yml');
        self::assertIsArray($compose);

        return $compose;
    }
}
