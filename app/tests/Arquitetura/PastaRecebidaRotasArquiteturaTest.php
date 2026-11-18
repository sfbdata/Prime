<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Pasta\Controller\PastaRecebidaController;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Pasta recebida de outra conta (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9a): as rotas `expediente_recebida*`
 * leem a pasta de OUTRO escritório, com o filtro do escritório da sessão desligado. A única porta é
 * `PastaRecebidaDoUsuario::localizar()`, que confere as cinco condições; por isso toda ação dessas rotas COMEÇA por ela
 * (a primeira chamada `$this->...(` da ação chega ao `localizar`, direto ou por um método da própria classe).
 *
 * Exceções com nome: `expediente_recebidas` (a caixa da pessoa, lê só os convites dela) e
 * `expediente_recebida_responder` (aceitar, recusar e deixar o próprio convite; não lê a pasta).
 *
 * O teste percorre o router inteiro: rota nova `expediente_recebida*` que não comece pela porta quebra aqui.
 */
#[CoversClass(PastaRecebidaController::class)]
final class PastaRecebidaRotasArquiteturaTest extends KernelTestCase
{
    private const EXCECOES = ['expediente_recebidas', 'expediente_recebida_responder'];

    private const ROTAS_DA_FATIA_9A = ['expediente_recebida_ver', 'expediente_recebida_pasta', 'expediente_recebida_documento'];

    #[TestDox('As rotas da pasta recebida (janela, página e documento) existem')]
    public function testAsRotasExistem(): void
    {
        $rotas = self::rotas();

        foreach (self::ROTAS_DA_FATIA_9A as $nome) {
            self::assertArrayHasKey($nome, $rotas, $nome);
        }
    }

    #[TestDox('Toda rota expediente_recebida* (fora as duas exceções) começa por PastaRecebidaDoUsuario::localizar()')]
    public function testTodaRotaComecaPeloLocalizador(): void
    {
        $semPorta = [];
        $conferidas = 0;
        foreach (self::rotas() as $nome => $rota) {
            if (!str_starts_with($nome, 'expediente_recebida') || in_array($nome, self::EXCECOES, true)) {
                continue;
            }
            ++$conferidas;
            [$classe, $metodo] = explode('::', (string) $rota->getDefault('_controller')) + [1 => '__invoke'];
            if (!class_exists($classe) || !method_exists($classe, $metodo) || !self::comecaPeloLocalizador($classe, $metodo)) {
                $semPorta[] = $nome . ' (' . $classe . '::' . $metodo . ')';
            }
        }

        self::assertGreaterThanOrEqual(count(self::ROTAS_DA_FATIA_9A), $conferidas);
        self::assertSame([], $semPorta, 'rota da pasta recebida que não começa por PastaRecebidaDoUsuario::localizar()');
    }

    /** @param class-string $classe */
    private static function comecaPeloLocalizador(string $classe, string $metodo): bool
    {
        $porta = self::metodosQueLocalizam($classe);
        if ($porta === []) {
            return false;
        }
        $corpo = self::corpo(new \ReflectionMethod($classe, $metodo));
        // A primeira chamada pelo `$this` da ação: um método da classe ou uma dependência (`$this->dep->x(`).
        if (preg_match('/\$this->(\w+)(?:->(\w+))?\(/', $corpo, $m) !== 1) {
            return false;
        }
        if (isset($m[2]) && $m[2] !== '') {
            return in_array($m[1], self::propriedadesDoLocalizador($classe), true) && $m[2] === 'localizar';
        }

        return in_array($m[1], $porta, true);
    }

    /**
     * Métodos da classe que chamam `$this-><localizador>->localizar(`, direto ou por outro método dela: fecho
     * transitivo sobre as chamadas `$this->x(` (o molde do `TrabalhoExternoSemEscritorioArquiteturaTest`).
     *
     * @param class-string $classe
     *
     * @return list<string>
     */
    private static function metodosQueLocalizam(string $classe): array
    {
        $propriedades = self::propriedadesDoLocalizador($classe);
        if ($propriedades === []) {
            return [];
        }
        $corpos = [];
        foreach ((new \ReflectionClass($classe))->getMethods() as $metodo) {
            $corpos[$metodo->getName()] = self::corpo($metodo);
        }

        $localizam = [];
        foreach ($corpos as $nome => $corpo) {
            foreach ($propriedades as $p) {
                if (str_contains($corpo, '$this->' . $p . '->localizar(')) {
                    $localizam[$nome] = true;
                }
            }
        }
        do {
            $antes = count($localizam);
            foreach ($corpos as $nome => $corpo) {
                if (isset($localizam[$nome])) {
                    continue;
                }
                preg_match_all('/\$this->(\w+)\(/', $corpo, $chamadas);
                if (array_intersect($chamadas[1], array_keys($localizam)) !== []) {
                    $localizam[$nome] = true;
                }
            }
        } while (count($localizam) > $antes);

        return array_keys($localizam);
    }

    /**
     * @param class-string $classe
     *
     * @return list<string>
     */
    private static function propriedadesDoLocalizador(string $classe): array
    {
        $nomes = [];
        foreach ((new \ReflectionClass($classe))->getProperties() as $p) {
            $tipo = $p->getType();
            if ($tipo instanceof \ReflectionNamedType && $tipo->getName() === PastaRecebidaDoUsuario::class) {
                $nomes[] = $p->getName();
            }
        }

        return $nomes;
    }

    /** @return array<string, Route> */
    private static function rotas(): array
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->getRouteCollection()->all();
    }

    private static function corpo(\ReflectionMethod $metodo): string
    {
        $arquivo = $metodo->getFileName();
        if ($arquivo === false) {
            return '';
        }
        $linhas = file($arquivo) ?: [];
        // Só o corpo: a assinatura não conta como chamada.
        $corpo = implode('', array_slice($linhas, (int) $metodo->getStartLine() - 1, (int) $metodo->getEndLine() - (int) $metodo->getStartLine() + 1));
        $abre = strpos($corpo, '{');

        return $abre === false ? '' : substr($corpo, $abre);
    }
}
