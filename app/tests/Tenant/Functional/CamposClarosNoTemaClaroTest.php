<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Campos escuros em tela clara: com o `<meta name="color-scheme" content="light dark">` o navegador pintava os controles
 * sem `.form-control` com o visual escuro do sistema operacional. A página declara só `light`; o tema escuro do
 * sistema continua pelo `color-scheme: dark` que o Bootstrap aplica em `[data-bs-theme=dark]`.
 */
#[CoversClass(TenantController::class)]
final class CamposClarosNoTemaClaroTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->escritorio = $this->escritorio('Escritório A');
        $this->pessoa = $this->pessoa('Pessoa do escritório');
        $this->vincular($this->pessoa, $this->escritorio);
        $admin = $this->pessoa('Admin');
        $this->vincular($admin, $this->escritorio, [], true);
        $this->logarComTenant($this->client, $admin, $this->escritorio);
    }

    #[TestDox('A página declara color-scheme só claro; o tema escuro fica com o data-bs-theme')]
    public function testColorSchemeSoClaro(): void
    {
        $crawler = $this->abrirDadosPessoais();

        self::assertSame('light', $crawler->filter('meta[name="color-scheme"]')->attr('content'));
    }

    #[TestDox('Os campos da aba Dados Pessoais têm o estilo do sistema (form-control)')]
    public function testCamposDaAbaDadosPessoaisComFormControl(): void
    {
        $crawler = $this->abrirDadosPessoais();

        foreach (['nomeCompleto', 'cpf', 'dataNascimento', 'ctps', 'serie'] as $campo) {
            $input = $crawler->filter(sprintf('input[name$="[%s]"]', $campo));
            self::assertCount(1, $input, $campo);
            self::assertStringContainsString('form-control', (string) $input->attr('class'), $campo);
        }
    }

    private function abrirDadosPessoais(): Crawler
    {
        $crawler = $this->client->request('GET', sprintf(
            '/tenant/%d/user/%d/edit-role?tab=dados-pessoais',
            $this->escritorio->getId(),
            $this->pessoa->getId(),
        ));
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
