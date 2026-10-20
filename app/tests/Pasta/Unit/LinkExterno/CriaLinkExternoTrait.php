<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\CadeadoDaPastaRepository;
use App\Processo\Entity\Processo;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Peças em memória (sem banco) para os testes unitários do link externo do Push.
 */
trait CriaLinkExternoTrait
{
    private const AGORA = '2026-10-19 10:00:00';
    private const NUMERO = '07011345720258070007';

    /** @var list<Pasta> pastas que o dublê do repositório de cadeados diz terem cadeado */
    private array $pastasComCadeado = [];

    /** A pasta passa a ter cadeado (qualquer modo): é o que desliga o link externo (spec pasta-cadeado D3). */
    private function comCadeado(Pasta $pasta): Pasta
    {
        $this->pastasComCadeado[] = $pasta;

        return $pasta;
    }

    private function cadeados(): CadeadoDaPastaRepository
    {
        $cadeados = $this->createStub(CadeadoDaPastaRepository::class);
        $cadeados->method('existeNaPasta')->willReturnCallback(fn (Pasta $p): bool => in_array($p, $this->pastasComCadeado, true));

        return $cadeados;
    }

    private function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::AGORA);
    }

    private function tenant(string $nome = 'Escritório Teste'): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);

        return $tenant;
    }

    private function usuario(string $nome = 'Ana Advogada'): User
    {
        $usuario = new User();
        $usuario->setFullName($nome);
        $usuario->setEmail('ana@escritorio.test');

        return $usuario;
    }

    private function processo(Tenant $tenant, string $numero = self::NUMERO): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);

        return $processo;
    }

    private function pastaComProcesso(Tenant $tenant, ?Processo $processo = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('2026-001');
        $pasta->setTenant($tenant);
        $pasta->vincularProcesso($processo ?? $this->processo($tenant));
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($pasta, 42);

        return $pasta;
    }

    private function link(?string $codigo = null, ?\DateTimeImmutable $criadoEm = null, bool $mostrarCliente = true, bool $incluirAnalises = false): LinkExternoDoPush
    {
        $tenant = $this->tenant();
        $processo = $this->processo($tenant);

        return new LinkExternoDoPush(
            tenant: $tenant,
            pasta: $this->pastaComProcesso($tenant, $processo),
            processo: $processo,
            endereco: 'bluejus-maria-silva-abcdefgh',
            codigo: $codigo,
            mostrarCliente: $mostrarCliente,
            incluirAnalises: $incluirAnalises,
            criadoPor: $this->usuario(),
            criadoEm: $criadoEm ?? $this->agora(),
        );
    }

    /** Link criado há 40 dias: expirou há 10. */
    private function linkExpirado(?string $codigo = null): LinkExternoDoPush
    {
        return $this->link($codigo, $this->agora()->modify('-40 days'));
    }

    private function item(int $id, string $tipo = 'Intimação', ?string $texto = '<p>Fica a parte intimada do despacho.</p>'): PublicacaoDjenListaItem
    {
        return PublicacaoDjenListaItem::fromRow([
            'id' => $id,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => $tipo,
            'numeroProcesso' => self::NUMERO,
            'numeroProcessoComMascara' => '0701134-57.2025.8.07.0007',
            'dataDisponibilizacao' => new \DateTimeImmutable('2026-10-10'),
            'nomeOrgao' => '1ª Vara Cível de Brasília',
            'lida' => false,
            'processoId' => 1,
            'textoInicio' => $texto,
        ]);
    }

    private function analise(Tenant $tenant, bool $interna, bool $concluida = true): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 0, 'v1', str_repeat('a', 64), []);
        if ($concluida) {
            $analise->iniciarProcessamento();
            $analise->concluir('Resumo da análise', [['tipo' => 'prazo', 'texto' => 'Prazo de 15 dias']], null, '{}', 'falso', 'falso', null, null, null);
        }
        if (!$interna) {
            $analise->alternarInterna();
        }

        return $analise;
    }

    private function urlGenerator(): UrlGenerator
    {
        $rotas = new RouteCollection();
        $rotas->add('push_compartilhado', new Route('/p/{endereco}'));
        $rotas->add('pasta_show', new Route('/pasta/{id}'));

        return new UrlGenerator($rotas, new RequestContext('', 'GET', 'jusprime.test', 'https'));
    }
}
