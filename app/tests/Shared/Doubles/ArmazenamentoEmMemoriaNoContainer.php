<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResultadoDaRemocao;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * O {@see ArmazenamentoEmMemoria} no lugar do `ArmazenamentoLocal`, para teste FUNCIONAL de rota
 * que grava (E2.4A, R1).
 *
 * Nas categorias planas o disco ignora o escopo da chave, então um funcional contra o disco real
 * passa verde com o tenant errado. Contra este dublê, a chave gravada guarda o escopo, e o teste
 * pode conferir que ela é a MESMA que a fábrica de leitura monta a partir do registro persistido.
 *
 * Por que substituir o serviço concreto, e não a interface: o container resolve o alias
 * `ArmazenamentoDeArquivos` para `ArmazenamentoLocal` na compilação, então trocar só o alias não
 * chega aos controllers. E o `ArmazenamentoLocal` também é o `MaterializadorDeArquivo` que a
 * `EntregaDeArquivo` recebe — por isso este dublê implementa as duas interfaces. A entrega não é
 * exercitada aqui: rota de download continua sendo testada contra o disco real. Desde a E2.5 o
 * backend concreto também é o `ArmazenamentoComPrefixo` da purga, e o dublê o implementa pelo
 * mesmo motivo.
 *
 * Instale ANTES da primeira requisição: serviço privado já usado não pode mais ser trocado.
 */
final class ArmazenamentoEmMemoriaNoContainer implements ArmazenamentoDeArquivos, MaterializadorDeArquivo, ArmazenamentoComPrefixo
{
    public readonly ArmazenamentoEmMemoria $memoria;

    public function __construct()
    {
        $this->memoria = new ArmazenamentoEmMemoria();
    }

    public static function instalarEm(ContainerInterface $container): self
    {
        $duble = new self();
        $container->set(ArmazenamentoLocal::class, $duble);

        return $duble;
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        return $this->memoria->gravar($destino, $fonte);
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        return $this->memoria->abrir($chave);
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        return $this->memoria->ler($chave);
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->memoria->existe($chave);
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        $this->memoria->excluir($chave);
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        return $this->memoria->metadados($chave);
    }

    public function listar(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): iterable
    {
        return $this->memoria->listar($escopo, $categoria);
    }

    public function excluirPrefixo(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): ResultadoDaRemocao
    {
        return $this->memoria->excluirPrefixo($escopo, $categoria);
    }

    public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
    {
        throw new \LogicException('Dublê de gravação: a entrega de arquivo é testada contra o disco real.');
    }
}
