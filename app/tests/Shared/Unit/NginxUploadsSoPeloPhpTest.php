<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Auditoria 3.13: tudo o que mora em public/uploads (documentos de pasta, clientes, atestados, chamados, cobrança e o
 * resto) só sai pelo PHP, que confere login, escritório e posse. Duas barreiras, uma independente da outra:
 *
 * 1. O nginx (dev e produção) manda todo /uploads/ ao front controller com `location ^~ /uploads/ { rewrite ^
 *    /index.php last; }`. O `^~` vence os regex de imagem; o rewrite não olha o disco. Esta regra já falhou uma vez:
 *    era `try_files $uri ...`, que entregava o arquivo quando ele existia.
 * 2. O nginx de produção não monta o volume uploads_prod. Se a regra regredir, o nginx não acha arquivo nenhum para
 *    entregar. O volume continua nos serviços que gravam e leem pelo PHP (php, worker, scheduler), e o static_prod
 *    que o nginx monta em public/ nunca recebe uploads (o entrypoint pula a pasta na cópia).
 *
 * Os testes funcionais de /uploads/pastas (PecaImagemControllerTest) só exercitam o Symfony; o nginx não roda na
 * suíte, por isso a guarda é sobre o texto das configurações.
 */
#[CoversNothing]
final class NginxUploadsSoPeloPhpTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../../../..';

    /** @return iterable<string, array{string}> */
    public static function configsDoNginx(): iterable
    {
        yield 'produção (server 443 do bluejus.com.br)' => ['prod'];
        yield 'dev (nginx.conf)' => ['dev'];
    }

    #[DataProvider('configsDoNginx')]
    #[TestDox('O nginx de $_dataName manda todo /uploads/ ao PHP com location ^~ e rewrite, sem root, alias nem try_files')]
    public function testUploadsVaoAoPhp(string $qual): void
    {
        // O bloco inteiro é só o rewrite: qualquer root, alias ou try_files dentro dele quebra o casamento.
        self::assertMatchesRegularExpression(
            '#location \^~ /uploads/ \{\s*rewrite \^ /index\.php last;\s*\}#',
            $this->config($qual),
            'falta o location ^~ /uploads/ { rewrite ^ /index.php last; } (sem ele, try_files ou o regex de imagens entregaria o arquivo do disco)',
        );
    }

    #[DataProvider('configsDoNginx')]
    #[TestDox('O nginx de $_dataName não tem outro location, root ou alias que cite uploads')]
    public function testNenhumOutroCaminhoParaUploads(string $qual): void
    {
        $config = $this->config($qual);

        preg_match_all('#^\s*location\b[^{]*uploads[^{]*\{#m', $config, $locations);
        self::assertSame(['location ^~ /uploads/ {'], array_map('trim', $locations[0]), 'só pode existir um location que cite uploads, o que manda ao PHP');
        self::assertDoesNotMatchRegularExpression('#^\s*(root|alias)\b[^;]*uploads#m', $config, 'root ou alias apontando para uploads serviria o arquivo direto do disco');
    }

    #[TestDox('O nginx de produção não monta o volume uploads_prod (sem o volume, uma regressão da regra não acha arquivo)')]
    public function testNginxDeProducaoSemOVolumeDeUploads(): void
    {
        self::assertNotContains(
            'uploads_prod',
            $this->origensDosVolumes('nginx'),
            'o serviço nginx de docker-compose.prod.yml voltou a montar uploads_prod: tudo em /uploads/ vai ao PHP, o nginx não precisa do volume',
        );
    }

    #[TestDox('O volume uploads_prod continua nos serviços php, worker e scheduler, em public/uploads')]
    public function testVolumeContinuaNosServicosDoPhp(): void
    {
        foreach (['php', 'worker', 'scheduler'] as $servico) {
            self::assertContains(
                'uploads_prod:/var/www/app/public/uploads',
                $this->volumes($servico),
                \sprintf('o serviço %s perdeu a montagem de uploads_prod (o app não acharia os arquivos)', $servico),
            );
        }
    }

    #[TestDox('O entrypoint de produção copia public/ para o static_prod sem a pasta uploads')]
    public function testEntrypointNaoCopiaUploadsParaOStatic(): void
    {
        $entrypoint = (string) file_get_contents(self::RAIZ . '/app/bin/entrypoint.prod.sh');

        self::assertStringContainsString(
            'find public -mindepth 1 -maxdepth 1 ! -name uploads -exec cp -rf {} /var/www/static/ \;',
            $entrypoint,
            'o static_prod é montado no nginx em public/: copiar uploads para lá deixaria o arquivo ao alcance do nginx',
        );
    }

    private function config(string $qual): string
    {
        if ($qual === 'dev') {
            return (string) file_get_contents(self::RAIZ . '/nginx.conf');
        }

        // Só o server 443 do bluejus.com.br (os outros servers redirecionam ou servem o sistema co-hospedado).
        $conf = (string) file_get_contents(self::RAIZ . '/nginx/conf.d/nginx.prod.conf');
        $inicio = strpos($conf, "listen 443 ssl;\n    server_name bluejus.com.br;");
        self::assertNotFalse($inicio, 'falta o server 443 do bluejus.com.br');
        $fim = strpos($conf, 'server_name grupojusprime.tech', $inicio);

        return substr($conf, $inicio, ($fim === false ? \strlen($conf) : $fim) - $inicio);
    }

    /**
     * Os volumes do serviço em docker-compose.prod.yml, na sintaxe curta (`origem:destino[:modo]`) ou longa (mapa).
     *
     * @return list<string>
     */
    private function volumes(string $servico): array
    {
        $compose = Yaml::parseFile(self::RAIZ . '/docker-compose.prod.yml');
        self::assertIsArray($compose);
        self::assertArrayHasKey($servico, $compose['services'] ?? [], \sprintf('falta o serviço %s em docker-compose.prod.yml', $servico));

        $volumes = [];
        foreach ($compose['services'][$servico]['volumes'] ?? [] as $volume) {
            $volumes[] = \is_array($volume)
                ? \sprintf('%s:%s', (string) ($volume['source'] ?? ''), (string) ($volume['target'] ?? ''))
                : (string) $volume;
        }

        return $volumes;
    }

    /** @return list<string> a origem (volume nomeado ou caminho do host) de cada montagem do serviço */
    private function origensDosVolumes(string $servico): array
    {
        return array_map(
            static fn (string $volume): string => explode(':', $volume, 2)[0],
            $this->volumes($servico),
        );
    }
}
